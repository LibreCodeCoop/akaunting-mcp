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
use RuntimeException;

final class SpecializedReadBehaviorTest extends TestCase
{
    private string $sandbox;
    private Toolset $tools;
    private Api $api;
    private string $subject;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir().'/mcp-specialized-'.bin2hex(random_bytes(8));
        mkdir($this->sandbox,0700);
        $config = new Config(
            'https://accounting.example.invalid',
            'https://accounting.example.invalid/mcp-auth',
            'https://api.openai.com/v1/mcp/tunnel_synthetic',
            'synthetic-client',
            'https://chatgpt.com/connector/oauth/synthetic',
            $this->sandbox,random_bytes(32),['localhost'],
        );
        $store=new Store($config);
        $this->subject=$store->saveLogin('reader@example.invalid','synthetic-password',1);
        $this->api=new class($config) extends Api {
            public array $responses=[];
            public array $requestedPaths=[];
            public bool $limitReached=false;
            public function get(string $email,string $password,string $path,array $query=[]): array
            {
                if ($email !== 'reader@example.invalid' || $password !== 'synthetic-password'
                    || ($query['company_id'] ?? null) !== 1) {
                    throw new RuntimeException('Denied by personal Akaunting permission');
                }
                if ($path === '/companies/1/owner') return [];
                $this->requestedPaths[]=$path;
                if ($this->limitReached) {
                    throw new RuntimeException('Akaunting request rejected (HTTP 429): private-server-details');
                }
                if (!array_key_exists($path,$this->responses)) {
                    throw new RuntimeException('Unexpected native API path');
                }
                return $this->responses[$path];
            }
        };
        $this->tools=new Toolset($store,$this->api);
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

    private function native(string $route,array $parameters=[],array $options=[]): array
    {
        return $this->tools->call($this->subject,'akaunting_api_read',
            array_merge(['company_id'=>1,'route'=>$route,'route_params'=>$parameters],$options));
    }

    public function testUserListsNeverLeakUnassignedAccountsOrUpstreamTotals(): void
    {
        $this->api->responses['/users']=[
            'data'=>[
                ['id'=>11,'name'=>'Team member','email'=>'member@example.invalid','enabled'=>true,
                    'companies'=>['data'=>[['id'=>1]]],
                    'roles'=>['data'=>[['id'=>8,'name'=>'Auditor','code'=>'auditor','secret'=>'hidden']]]],
                ['id'=>12,'name'=>'Foreign identity','email'=>'foreign@example.invalid',
                    'companies'=>['data'=>[['id'=>2]]]],
                ['id'=>13,'name'=>'Unknown identity','companies'=>[]],
            ],
            'meta'=>['total'=>300,'last_page'=>3,'current_page'=>1],
        ];
        $legacy=$this->tools->call($this->subject,'akaunting_list_users',['company_id'=>1,'limit'=>10]);
        $native=$this->native('api.users.index');
        foreach([$legacy,$native] as $result){
            self::assertCount(1,$result['data']);
            self::assertSame('member@example.invalid',$result['data'][0]['email']);
            $json=json_encode($result,JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('foreign@example.invalid',$json);
            self::assertStringNotContainsString('hidden',$json);
            self::assertStringNotContainsString('300',$json);
        }
    }

    public function testUserDetailCannotRevealAnUnassignedAccount(): void
    {
        $this->api->responses['/users/12']=['data'=>[
            'id'=>12,'email'=>'foreign@example.invalid',
            'companies'=>['data'=>[['id'=>2]]],
        ]];
        $this->expectException(RuntimeException::class);
        $this->native('api.users.show',['user'=>12]);
    }

    public function testSettingsExposeOnlyReviewedFieldsForSelectedCompany(): void
    {
        $this->api->responses['/settings']=[
            'data'=>[
                ['id'=>1,'company_id'=>1,'key'=>'company.name','value'=>'Authorized company'],
                ['id'=>2,'company_id'=>1,'key'=>'company.secret_token','value'=>'hidden-token'],
                ['id'=>3,'company_id'=>2,'key'=>'company.name','value'=>'Other company'],
                ['id'=>4,'company_id'=>1,'key'=>'default.currency','value'=>'BRL'],
            ],
            'meta'=>['total'=>400],
        ];
        $records=$this->native('api.settings.index');
        self::assertCount(2,$records['data']);
        self::assertSame(['company.name','default.currency'],array_column($records['data'],'key'));
        self::assertStringNotContainsString('hidden-token',json_encode($records,JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('Other company',json_encode($records,JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('meta',$records);
    }

    public function testUnsafeSettingDetailIsRejected(): void
    {
        $this->api->responses['/settings/2']=['data'=>[
            'id'=>2,'company_id'=>1,'key'=>'company.secret_token','value'=>'hidden-token',
        ]];
        $this->expectException(RuntimeException::class);
        $this->native('api.settings.show',['setting'=>2]);
    }

    public function testNestedTransactionsRequireTheParentInvoiceToBelongToTheCompany(): void
    {
        $this->api->responses['/documents/7']=['data'=>[
            'id'=>7,'company_id'=>2,'type'=>'invoice',
        ]];
        $this->api->responses['/documents/7/transactions']=['data'=>[
            ['id'=>88,'company_id'=>1,'amount'=>'100.00','access_token'=>'hidden'],
        ]];
        try {
            $this->native('api.documents.transactions.index',['document'=>7],['record_type'=>'invoice']);
            self::fail('Foreign parent invoice must block access to its transactions');
        } catch(RuntimeException) {
            self::assertNotContains('/documents/7/transactions',$this->api->requestedPaths);
        }

        $this->api->requestedPaths=[];
        $this->api->responses['/documents/7']['data']['company_id']=1;
        $result=$this->native('api.documents.transactions.index',['document'=>7],['record_type'=>'invoice']);
        self::assertSame('100.00',$result['data'][0]['amount']);
        self::assertArrayNotHasKey('access_token',$result['data'][0]);
    }

    public function testCompanyDetailsCannotBeUsedToAccessOtherCompanies(): void
    {
        $this->expectException(RuntimeException::class);
        $this->native('api.companies.show',['company'=>2]);
    }

    public function testTranslationReadsAreAuthenticatedAndRedacted(): void
    {
        $this->api->responses['/translations/pt-BR/all']=[
            'data'=>['welcome'=>'Bem-vindo','access_token'=>'hidden'],
        ];
        $result=$this->native('api.translations.all',['locale'=>'pt-BR']);
        $json=json_encode($result,JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Bem-vindo',$json);
        self::assertStringNotContainsString('hidden',$json);
    }

    public static function unsafeParameters(): array
    {
        return [
            'path traversal' => ['api.users.show',['user'=>'../../1'],[]],
            'unknown mutating route' => ['api.users.disable',['user'=>1],[]],
            'wrong URL placeholder' => ['api.users.show',['other'=>1],[]],
            'invalid negative page' => ['api.settings.index',[],['page'=>0]],
            'oversized result page' => ['api.settings.index',[],['limit'=>51]],
            'record type on unrelated route' => ['api.settings.index',[],['record_type'=>'invoice']],
            'field projection on translations' => ['api.translations.all',['locale'=>'pt-BR'],['fields'=>['welcome']]],
        ];
    }

    #[DataProvider('unsafeParameters')]
    public function testUnsafeRouteRequestsNeverReachTheBusinessApi(
        string $route,array $routeParams,array $extra,
    ): void {
        $this->expectException(RuntimeException::class);
        try {
            $this->native($route,$routeParams,$extra);
        } finally {
            self::assertSame([],$this->api->requestedPaths);
        }
    }

    public function testPermissionProbeDoesNotReturnFinancialContent(): void
    {
        $this->api->responses['/categories']=['data'=>[[
            'id'=>7,'company_id'=>1,'amount'=>'9999.99','private_notes'=>'sensitive',
        ]]];
        $result=$this->tools->call($this->subject,'akaunting_probe_resource',
            ['resource'=>'categories','company_id'=>1]);
        self::assertTrue($result['accessible']);
        self::assertStringNotContainsString('9999.99',json_encode($result,JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('sensitive',json_encode($result,JSON_THROW_ON_ERROR));
    }

    public function testRateLimitExposesOnlySafeErrorMessageToChatGPT(): void
    {
        $upstream = $this->api;
        $upstream->limitReached = true;
        $mcp=new Mcp($this->tools,'https://mcp.example.invalid/.well-known/oauth-protected-resource');
        $result=$mcp->execute($this->subject,json_encode([
            'jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call',
            'params'=>['name'=>'akaunting_list_records',
                'arguments'=>['company_id'=>1,'resource'=>'categories']],
        ],JSON_THROW_ON_ERROR));
        self::assertTrue($result['result']['isError']);
        $message=$result['result']['content'][0]['text'];
        self::assertStringContainsString('429',$message);
        self::assertStringNotContainsString('private-server-details',$message);
    }
}
