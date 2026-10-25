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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class McpTransportBehaviorTest extends TestCase
{
    private string $sandbox;
    private Mcp $mcp;
    private string $subject;

    protected function setUp(): void
    {
        $this->sandbox=sys_get_temp_dir().'/mcp-transport-'.bin2hex(random_bytes(8));
        mkdir($this->sandbox,0700);
        $config=new Config(
            'https://accounting.example.invalid',
            'https://accounting.example.invalid/mcp-auth',
            'https://api.openai.com/v1/mcp/tunnel_synthetic',
            'synthetic-client',
            'https://chatgpt.com/connector/oauth/synthetic',
            $this->sandbox,random_bytes(32),['localhost'],
        );
        $store=new Store($config);
        $this->subject=$store->saveLogin('reader@example.invalid','synthetic-password',1);
        $api=new class($config) extends Api {
            public function get(string $email,string $password,string $path,array $query=[]): array
            {
                if ($path === '/companies/1/owner' && ($query['company_id']??null) === 1) {
                    return [];
                }
                if ($path === '/categories') {
                    return ['data'=>[[
                        'id'=>7,'company_id'=>1,'name'=>str_repeat('synthetic',230000),
                    ]]];
                }
                throw new \RuntimeException('Read not authorized');
            }
        };
        $this->mcp=new Mcp(new Toolset($store,$api),
            'https://api.openai.com/.well-known/oauth-protected-resource/v1/mcp/tunnel_synthetic');
    }

    protected function tearDown(): void
    {
        $files=new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->sandbox,\FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach($files as $file){
            $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());
        }
        rmdir($this->sandbox);
    }

    private function execute(array $request,?string $subject='authenticated'): ?array
    {
        $user=$subject === 'authenticated' ? $this->subject : $subject;
        return $this->mcp->execute($user,json_encode($request,JSON_THROW_ON_ERROR));
    }

    public static function invalidRequests(): array
    {
        return [
            'malformed JSON' => ['{', -32700],
            'oversized request' => [str_repeat('x',524289), -32600],
            'JSON array' => ['[]', -32600],
            'missing method' => ['{"jsonrpc":"2.0","id":1}', -32600],
            'unknown protocol' => ['{"jsonrpc":"1.0","method":"ping","id":1}', -32600],
            'invalid ID' => ['{"jsonrpc":"2.0","method":"ping","id":[1]}', -32600],
            'array parameters' => ['{"jsonrpc":"2.0","method":"ping","params":[1],"id":1}', -32602],
            'unknown method' => ['{"jsonrpc":"2.0","method":"tools/erase","id":1}', -32601],
            'unusable initialize' => ['{"jsonrpc":"2.0","method":"initialize","params":{},"id":1}', -32602],
        ];
    }

    #[DataProvider('invalidRequests')]
    public function testBadJsonRpcRequestsHaveSafePredictableErrors(string $body,int $expected): void
    {
        $result=$this->mcp->execute($this->subject,$body);
        self::assertSame($expected,$result['error']['code']??null);
        self::assertArrayNotHasKey('result',$result);
    }

    public function testNotificationsNeverProduceAccidentalResponses(): void
    {
        self::assertNull($this->execute(['jsonrpc'=>'2.0','method'=>'notifications/initialized']));
    }

    public static function supportedVersions(): array
    {
        return [
            'previous supported' => ['2025-03-26','2025-03-26'],
            'current supported' => ['2025-06-18','2025-06-18'],
            'future unsupported falls back safely' => ['2030-01-01','2025-06-18'],
        ];
    }

    #[DataProvider('supportedVersions')]
    public function testProtocolNegotiationPreservesAvailableReadTools(string $request,string $expected): void
    {
        $initialized=$this->execute([
            'jsonrpc'=>'2.0','id'=>7,'method'=>'initialize',
            'params'=>['protocolVersion'=>$request],
        ]);
        self::assertSame($expected,$initialized['result']['protocolVersion']);
        $listed=$this->execute(['jsonrpc'=>'2.0','id'=>8,'method'=>'tools/list']);
        self::assertCount(9,$listed['result']['tools']);
        foreach($listed['result']['tools'] as $tool) {
            self::assertTrue($tool['annotations']['readOnlyHint']);
        }
    }

    public function testUnauthenticatedFinanceToolReturnsOAuthLinkingChallenge(): void
    {
        $result=$this->execute([
            'jsonrpc'=>'2.0','id'=>9,'method'=>'tools/call',
            'params'=>['name'=>'akaunting_whoami','arguments'=>[]],
        ],null);
        self::assertTrue($result['result']['isError']);
        self::assertStringContainsString('invalid_token',
            $result['result']['_meta']['mcp/www_authenticate'][0]);
    }

    public function testUnknownToolArgumentsCannotChangeOperation(): void
    {
        $result=$this->execute([
            'jsonrpc'=>'2.0','id'=>10,'method'=>'tools/call',
            'params'=>['name'=>'akaunting_resources','arguments'=>['erase'=>true]],
        ]);
        self::assertTrue($result['result']['isError']);
        self::assertStringNotContainsString('erase',
            $result['result']['content'][0]['text']);
    }

    public function testOversizedFinancialResponseIsRefusedWithoutReturningContent(): void
    {
        $result=$this->execute([
            'jsonrpc'=>'2.0','id'=>11,'method'=>'tools/call',
            'params'=>['name'=>'akaunting_list_records',
                'arguments'=>['company_id'=>1,'resource'=>'categories']],
        ]);
        self::assertTrue($result['result']['isError']);
        self::assertStringContainsString('too large',$result['result']['content'][0]['text']);
        self::assertLessThan(250,strlen(json_encode($result,JSON_THROW_ON_ERROR)));
    }
}
