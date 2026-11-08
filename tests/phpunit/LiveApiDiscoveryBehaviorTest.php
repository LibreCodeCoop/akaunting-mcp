<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace AkauntingMcp\Tests;

use AkauntingMcp\Api;
use AkauntingMcp\ApiBrowser;
use AkauntingMcp\LiveApiDiscovery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LiveApiDiscoveryBehaviorTest extends TestCase
{
    private function manifest(): array
    {
        return [
            'version'=>1,
            'company_id'=>1,
            'routes'=>[[
                'name'=>'api.nfse.invoices.fiscal-decision.show',
                'uri'=>'{company_id}/api/nfse/invoices/{invoice}/fiscal-decision',
                'method'=>'GET',
                'scope'=>'invoice',
                'params'=>['invoice'],
                'query'=>[],
                'response_paths'=>['/data/current','/data/effective','/data/history'],
                'permissions'=>['read-sales-invoices'],
            ]],
        ];
    }

    private function api(): Api
    {
        return new class extends Api {
            public array $manifest = [];
            public array $invoice = ['data'=>['id'=>7,'company_id'=>1,'type'=>'invoice']];
            public array $response = ['data'=>[
                'current'=>['id'=>1,'company_id'=>1,'invoice_id'=>7,
                    'decision'=>'do_not_issue','justification'=>'Synthetic operational review.'],
                'effective'=>['id'=>1,'company_id'=>1,'invoice_id'=>7,
                    'decision'=>'do_not_issue','source'=>'operator'],
                'history'=>[['id'=>1,'company_id'=>1,'invoice_id'=>7,
                    'decision'=>'do_not_issue','justification'=>'Synthetic operational review.']],
            ]];
            public array $calls = [];

            public function __construct() {}

            public function get(string $email, string $password, string $path, array $query=[]): array
            {
                $this->calls[]=['method'=>'GET','path'=>$path,'query'=>$query];
                if ($path==='/nfse/discovery')return $this->manifest;
                if ($path==='/documents/7')return $this->invoice;
                throw new RuntimeException('Unexpected synthetic API access');
            }

            public function getDiscovered(
                string $email, string $password, array $approved, array $query
            ): array {
                $this->calls[]=['method'=>'GET','path'=>$approved['path'],'query'=>$query,
                    'tenant_prefix'=>$approved['tenant_prefix']];
                return $this->response;
            }
        };
    }

    private function creds(): array
    {
        return ['email'=>'synthetic@example.invalid','password'=>'synthetic-test-only'];
    }

    public function testLiveRouteAppearsAndReadsOnlyProjectedCompanyOwnedData(): void
    {
        $api=$this->api();
        $api->manifest=$this->manifest();
        $browser=new ApiBrowser($api);
        $listed=$browser->catalog($this->creds(),1);
        $routes=array_column($listed['registered_read_routes'],null,'route');
        self::assertArrayHasKey('api.nfse.invoices.fiscal-decision.show',$routes);
        self::assertSame('live-api',$routes['api.nfse.invoices.fiscal-decision.show']['source']);
        self::assertSame(['read-sales-invoices'],
            $routes['api.nfse.invoices.fiscal-decision.show']['required_permission_hints']);
        self::assertArrayNotHasKey('api.nfse.invoices.fiscal-decision.store',$routes);

        $value=$browser->read($this->creds(),1,[
            'route'=>'api.nfse.invoices.fiscal-decision.show',
            'route_params'=>['invoice'=>7],
        ]);
        self::assertSame('do_not_issue',$value['response']['data']['current']['decision']);
        self::assertSame('do_not_issue',$value['response']['data']['effective']['decision']);
        self::assertSame('do_not_issue',$value['response']['data']['history'][0]['decision']);
        self::assertSame(1,$value['company_id']);
        self::assertSame(['/nfse/discovery','/nfse/discovery','/documents/7',
            '/nfse/invoices/7/fiscal-decision'],array_column($api->calls,'path'));
        self::assertTrue($api->calls[3]['tenant_prefix']);
        self::assertSame(['company_id'=>1],$api->calls[3]['query']);
        self::assertSame('type:invoice',$api->calls[2]['query']['search']);
    }

    public function testEmptyDataIsReadOnlyAndDoesNotMakeAnIssuanceCall(): void
    {
        $api=$this->api();
        $api->manifest=$this->manifest();
        $api->response=['data'=>['current'=>null,'effective'=>null,'history'=>[]]];
        $value=(new ApiBrowser($api))->read($this->creds(),1,[
            'route'=>'api.nfse.invoices.fiscal-decision.show','route_params'=>['invoice'=>7],
        ]);
        self::assertSame(['data'=>['current'=>null,'effective'=>null,'history'=>[]]],$value['response']);
        self::assertCount(3,$api->calls);
        foreach ($api->calls as $call) self::assertSame('GET',$call['method']);
    }

    public function testInvoiceMustBelongToSelectedCompanyBeforeDiscoveredDataCall(): void
    {
        foreach ([['company_id'=>2],['type'=>'bill'],['id'=>8]] as $mutation) {
            $api=$this->api();
            $api->manifest=$this->manifest();
            $api->invoice['data']=array_replace($api->invoice['data'],$mutation);
            $this->mustReject(static fn()=> (new ApiBrowser($api))->read(
                ['email'=>'synthetic@example.invalid','password'=>'synthetic-test-only'],1,
                ['route'=>'api.nfse.invoices.fiscal-decision.show','route_params'=>['invoice'=>7]],
            ));
            self::assertSame(['/nfse/discovery','/documents/7'],array_column($api->calls,'path'));
        }
    }

    public function testForeignHistoryOrUndeclaredResponseDataFailsClosed(): void
    {
        foreach ([
            ['data'=>['current'=>['id'=>1,'company_id'=>2,'invoice_id'=>7],'history'=>[]]],
            ['data'=>['current'=>['id'=>1,'company_id'=>1,'invoice_id'=>8],'history'=>[]]],
            ['data'=>['current'=>['id'=>1,'company_id'=>1,'invoice_id'=>7,'raw_xml'=>'<NFSe/>'],'history'=>[]]],
            ['data'=>['current'=>'raw text','history'=>[]]],
            ['data'=>['current'=>null]],
        ] as $response) {
            $api=$this->api();$api->manifest=$this->manifest();$api->response=$response;
            $this->mustReject(static fn()=> (new ApiBrowser($api))->read(
                ['email'=>'synthetic@example.invalid','password'=>'synthetic-test-only'],1,
                ['route'=>'api.nfse.invoices.fiscal-decision.show','route_params'=>['invoice'=>7]],
            ));
        }
        $api=$this->api();$api->manifest=$this->manifest();
        $api->response['data']['additional_secret_data']='Must not leave the declared output boundary.';
        $value=(new ApiBrowser($api))->read($this->creds(),1,[
            'route'=>'api.nfse.invoices.fiscal-decision.show','route_params'=>['invoice'=>7],
        ]);
        self::assertArrayNotHasKey('additional_secret_data',$value['response']['data']);
    }

    public function testUnapprovedRoutePathsAndParameterInjectionNeverReachFinancialApi(): void
    {
        foreach ([
            ['route'=>'api.nfse.invoices.emit','route_params'=>['invoice'=>7]],
            ['route'=>'api.nfse.invoices.fiscal-decision.show','route_params'=>['invoice'=>'7']],
            ['route'=>'api.nfse.invoices.fiscal-decision.show','route_params'=>['invoice'=>7,'extra'=>1]],
            ['route'=>'api.nfse.invoices.fiscal-decision.show','route_params'=>['invoice'=>7],
                'nfse_filters'=>['company_id'=>2]],
            ['route'=>'api.nfse.invoices.fiscal-decision.show','route_params'=>['invoice'=>7],
                'module_filters'=>['search'=>'delete all']],
        ] as $args) {
            $api=$this->api();$api->manifest=$this->manifest();
            $this->mustReject(static fn()=> (new ApiBrowser($api))->read(
                ['email'=>'synthetic@example.invalid','password'=>'synthetic-test-only'],1,$args,
            ));
            self::assertNotContains('/nfse/invoices/7/fiscal-decision',array_column($api->calls,'path'));
        }
    }

    public function testMalformedDiscoveryAndRouteDriftAreNotSilentlyTrusted(): void
    {
        $valid=$this->manifest();
        $cases=[];
        $case=$valid;$case['company_id']=2;$cases[]=$case;
        $case=$valid;$case['routes'][0]['method']='POST';$cases[]=$case;
        $case=$valid;$case['routes'][0]['uri']='https://evil.example.invalid/data';$cases[]=$case;
        $case=$valid;$case['routes'][0]['scope']='global';$cases[]=$case;
        $case=$valid;$case['routes'][0]['permissions']=['update-sales-invoices'];$cases[]=$case;
        $case=$valid;$case['routes'][0]['response_paths']=['/data','/data/current'];$cases[]=$case;
        $case=$valid;$case['routes'][0]['name']='api.nfse.invoices.emit';$cases[]=$case;
        $case=$valid;$case['routes'][0]['name']='api.nfse.ledger.index';
        $case['routes'][0]['uri']='{company_id}/api/nfse/ledger-changed';$case['routes'][0]['params']=[];
        $case['routes'][0]['scope']='company';$cases[]=$case;
        $case=$valid;$case['routes'][]=$case['routes'][0];$cases[]=$case;
        foreach ($cases as $manifest) {
            $api=$this->api();$api->manifest=$manifest;
            $this->mustReject(static fn()=> (new LiveApiDiscovery($api))->routes(
                ['email'=>'synthetic@example.invalid','password'=>'synthetic-test-only'],1,
            ));
        }
    }

    public function testOlderServerCanFallBackButServerErrorsRemainFailures(): void
    {
        $api=new class extends Api {
            public string $error='Akaunting request rejected (HTTP 404)';
            public function __construct() {}
            public function get(string $email,string $password,string $path,array $query=[]): array
            {
                throw new RuntimeException($this->error);
            }
        };
        $listed=(new ApiBrowser($api))->catalog($this->creds(),1);
        $known=array_column($listed['registered_read_routes'],'route');
        self::assertContains('api.nfse.ledger.index',$known);
        self::assertNotContains('api.nfse.invoices.fiscal-decision.show',$known);
        $api->error='Akaunting request rejected (HTTP 500)';
        $this->mustReject(fn()=> (new ApiBrowser($api))->catalog($this->creds(),1));
    }

    public function testDeclaredQueryTypesNeedNoMcpCodeChanges(): void
    {
        $discovery=new LiveApiDiscovery($this->api());
        self::assertSame(['page'=>2,'q'=>'Invoice ABC'], $discovery->query(
            ['page'=>'positive_integer','q'=>'short_text'],['page'=>2,'q'=>'Invoice ABC'],
        ));
        foreach ([['page'=>0],['q'=>'anything&company_id=2'],['unexpected'=>'1']] as $query) {
            $this->mustReject(fn()=> $discovery->query(
                ['page'=>'positive_integer','q'=>'short_text'],$query,
            ));
        }
    }

    private function mustReject(callable $fn): void
    {
        try {
            $fn();
            self::fail('Expected fail-closed discovered API behavior');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }
    }
}
