<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace AkauntingMcp\Tests;

use AkauntingMcp\Api;
use AkauntingMcp\Config;
use AkauntingMcp\Mcp;
use AkauntingMcp\Store;
use AkauntingMcp\Toolset;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Synthetic MCP JSON-RPC integration: no external services or production data. */
final class NativeMutationMcpIntegrationTest extends TestCase
{
    private string $directory;
    private Mcp $mcp;
    private string $subject;
    private Api $api;

    protected function setUp(): void
    {
        $this->directory=sys_get_temp_dir().'/native-mcp-'.bin2hex(random_bytes(8));
        mkdir($this->directory,0700);
        $config=new Config(
            'https://accounting.example.invalid',
            'https://accounting.example.invalid/mcp-auth',
            'https://api.openai.com/v1/mcp/tunnel_synthetic',
            'test-client',
            'https://chatgpt.com/connector/oauth/synthetic',
            $this->directory,random_bytes(32),['localhost'],
        );
        $store=new Store($config);
        $this->subject=$store->saveLogin('member@example.invalid','synthetic-password',1);
        $this->api=new class($config) extends Api {
            public array $writes=[];
            public function verify(string $email,string $password,int $companyId): void
            {
                if ($email!=='member@example.invalid'
                    || $password!=='synthetic-password' || $companyId!==1) {
                    throw new RuntimeException('Synthetic identity or company denied');
                }
            }

            public function get(string $email,string $password,string $path,array $query=[]): array
            {
                if ($path==='/contacts/7') {
                    return ['data'=>[
                        'id'=>7,'company_id'=>1,'type'=>'vendor','name'=>'Before',
                    ]];
                }
                throw new RuntimeException('Synthetic record unavailable');
            }

            public function mutate(
                string $email,string $password,string $method,string $path,
                int $companyId,array $body,?string $recordType=null
            ): array {
                $this->verify($email,$password,$companyId);
                $this->writes[]=compact('method','path','companyId','body','recordType');
                return ['http_status'=>200];
            }
        };
        $this->mcp=new Mcp(
            new Toolset($store,$this->api),
            'https://api.openai.com/.well-known/oauth-protected-resource/v1/mcp/tunnel_synthetic',
        );
    }

    protected function tearDown(): void
    {
        $files=new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory,\FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    private function call(string $tool,array $arguments,?string $subject='current'): array
    {
        $user=$subject==='current'?$this->subject:$subject;
        $response=$this->mcp->execute($user,json_encode([
            'jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call',
            'params'=>['name'=>$tool,'arguments'=>$arguments],
        ],JSON_THROW_ON_ERROR));
        self::assertNotNull($response);
        $result=$response['result'];
        $body=json_decode($result['content'][0]['text'],true);
        return ['isError'=>$result['isError'],'body'=>$body];
    }

    public function testNativeWriteIsUserConfirmedByHostAndReusesOAuthIdentityWithoutPassword(): void
    {
        $proposal=$this->call('akaunting_prepare_mutation',[
            'company_id'=>1,
            'route'=>'api.contacts.update','method'=>'PATCH',
            'route_params'=>['contact'=>7],
            'record_type'=>'vendor','body'=>['name'=>'After'],
        ]);
        self::assertFalse($proposal['isError']);
        self::assertSame('awaiting_host_confirmation',$proposal['body']['status']);
        self::assertArrayNotHasKey('approval_url',$proposal['body']);
        self::assertSame('After',$proposal['body']['summary']['body']['name']);
        self::assertCount(0,$this->api->writes);
        $ticket=$proposal['body']['request_id'];

        $result=$this->call('akaunting_execute_prepared_mutation',[
            'company_id'=>1,'request_id'=>$ticket,
            'route'=>'api.contacts.update','method'=>'PATCH',
        ]);
        self::assertFalse($result['isError']);
        self::assertSame('completed',$result['body']['status']);
        self::assertCount(1,$this->api->writes);
        self::assertSame('After',$this->api->writes[0]['body']['name']);
        self::assertSame('vendor',$this->api->writes[0]['recordType']);
        self::assertSame('/contacts/7',$this->api->writes[0]['path']);

        $replay=$this->call('akaunting_execute_prepared_mutation',[
            'company_id'=>1,'request_id'=>$ticket,
            'route'=>'api.contacts.update','method'=>'PATCH',
        ]);
        self::assertTrue($replay['isError']);
        self::assertCount(1,$this->api->writes);
        $status=$this->call('akaunting_mutation_status',[
            'company_id'=>1,'request_id'=>$ticket,
        ]);
        self::assertSame('completed',$status['body']['status']);
    }

    public function testNativeWriteRejectsUnknownIdentityAndUnknownRequest(): void
    {
        $proposal=$this->call('akaunting_prepare_mutation',[
            'company_id'=>1,'route'=>'api.contacts.update',
            'method'=>'PATCH','route_params'=>['contact'=>7],
            'record_type'=>'vendor','body'=>['name'=>'Still before'],
        ]);
        $request=$proposal['body']['request_id'];
        $args=['company_id'=>1,'request_id'=>$request,
            'route'=>'api.contacts.update','method'=>'PATCH'];
        self::assertTrue($this->call('akaunting_execute_prepared_mutation',$args,null)['isError']);
        self::assertTrue($this->call('akaunting_execute_prepared_mutation',
            $args,str_repeat('a',64))['isError']);
        self::assertTrue($this->call('akaunting_execute_prepared_mutation',
            $args+['unregistered_field'=>'not allowed'])['isError']);
        self::assertCount(0,$this->api->writes);
    }
}
