<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace AkauntingMcp\Tests;

use AkauntingMcp\Api;
use AkauntingMcp\ApiBrowser;
use AkauntingMcp\NfseReadCatalog;
use AkauntingMcp\RouteCatalog;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NfseReadBehaviorTest extends TestCase
{
    public function testKnownModuleJsonRoutesAreDiscoverableWithoutFiscalWritesOrBinaryDownloads(): void
    {
        $catalog=new RouteCatalog();
        $routes=array_column($catalog->list(),null,'route');
        self::assertArrayHasKey('api.nfse.ledger.index',$routes);
        self::assertArrayHasKey('api.nfse.dps.readiness',$routes);
        self::assertArrayHasKey('api.nfse.dps.lookup',$routes);
        self::assertArrayHasKey('api.nfse.invoices.service-preview',$routes);
        self::assertArrayHasKey('api.nfse.adn.documents.preview',$routes);
        self::assertArrayHasKey('api.nfse.settings.company-profiles.index',$routes);
        self::assertCount(19, (new NfseReadCatalog())->list());
        self::assertSame(['read-sales-invoices'],
            $routes['api.nfse.invoices.service-preview']['required_permission_hints']);
        self::assertFalse($catalog->permitsPath('/nfse/invoices/3/emit'));
        self::assertTrue($catalog->permitsPath('/nfse/dps/readiness'));
        self::assertTrue($catalog->permitsPath('/nfse/dps/lookup'));
        self::assertFalse($catalog->permitsPath('/nfse/invoices/3/reemit'));
        self::assertFalse($catalog->permitsPath('/nfse/receipts/2/artifacts/xml'));
        self::assertFalse($catalog->permitsPath('/nfse/adn/distribution'));
        self::assertFalse($catalog->permitsPath('/nfse/settings/vault/diagnostics'));
        self::assertTrue($catalog->permitsPath('/nfse/invoices/3/post-emission-status'));
    }

    public function testRouteParametersAndFiltersAreTypedAndFailClosed(): void
    {
        $catalog=new NfseReadCatalog();
        self::assertSame('/nfse/invoices/7/service-preview',
            $catalog->resolve('api.nfse.invoices.service-preview',['invoice'=>7])['path']);
        self::assertSame(['lc116'=>'0107','limit'=>3],
            $catalog->query('api.nfse.national-services.index',['lc116'=>'0107','limit'=>3]));
        self::assertSame(['competence'=>'2026-11'],
            $catalog->query('api.nfse.closing.index',['competence'=>'2026-11']));
        self::assertSame(['number'=>'900000000000008'],
            $catalog->query('api.nfse.dps.lookup',['number'=>'900000000000008']));
        foreach ([
            fn()=> $catalog->resolve('api.nfse.invoices.service-preview',['invoice'=>'7']),
            fn()=> $catalog->resolve('api.nfse.invoices.service-preview',['invoice'=>0]),
            fn()=> $catalog->resolve('api.nfse.invoices.service-preview',['invoice'=>7,'extra'=>1]),
            fn()=> $catalog->resolve('api.nfse.invoices.emit',['invoice'=>7]),
            fn()=> $catalog->query('api.nfse.ledger.index',['limit'=>500]),
            fn()=> $catalog->query('api.nfse.ledger.index',['status'=>'cancelled&company_id=2']),
            fn()=> $catalog->query('api.nfse.adn.documents.index',['review_status'=>'all']),
            fn()=> $catalog->query('api.nfse.ledger.index',['other_company'=>2]),
            fn()=> $catalog->query('api.nfse.closing.index',['competence'=>'2026-13']),
            fn()=> $catalog->query('api.nfse.ledger.index',['from'=>'2026-02-31']),
            fn()=> $catalog->query('api.nfse.national-services.index',['lc116'=>107]),
            fn()=> $catalog->query('api.nfse.dps.lookup',['number'=>'0001']),
            fn()=> $catalog->query('api.nfse.dps.lookup',['number'=>'9000000000000001']),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('Invalid fiscal argument unexpectedly accepted');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function testJsonReadPreverifiesInvoiceCompanyAndFiscalType(): void
    {
        $api=$this->fakeApi();
        $response=(new ApiBrowser($api))->read($this->credentials(),1,[
            'company_id'=>1,'route'=>'api.nfse.invoices.service-preview',
            'route_params'=>['invoice'=>7],
        ]);
        self::assertSame(1,$response['company_id']);
        self::assertSame('api.nfse.invoices.service-preview',$response['route']);
        self::assertSame([], $response['response']['fiscal_groups']);
        self::assertSame(['/documents/7','/nfse/invoices/7/service-preview'],
            array_column($api->calls,'path'));
        self::assertSame(1,$api->calls[1]['query']['company_id']);
        self::assertSame('type:invoice',$api->calls[0]['query']['search']);

        foreach (['company_id'=>2,'type'=>'bill','id'=>9] as $field=>$invalid) {
            $api=$this->fakeApi();
            $api->invoice['data'][$field]=$invalid;
            $this->expectRejected(function () use ($api): void {
                (new ApiBrowser($api))->read($this->credentials(),1,[
                    'route'=>'api.nfse.invoices.service-preview',
                    'route_params'=>['invoice'=>7],
                ]);
            });
            self::assertCount(1,$api->calls,'Unsafe request reached fiscal child route');
        }
    }

    public function testOfficialDpsReadCannotClaimTheNumberIsSafeToEmit(): void
    {
        $api=$this->fakeApi();
        $api->fiscal=['data'=>['official_state'=>'not_found','number'=>'900000000000008',
            'safe_to_emit'=>false,'exclusive_series_attested'=>false]];
        $result=(new ApiBrowser($api))->read($this->credentials(),1,[
            'route'=>'api.nfse.dps.lookup',
            'nfse_filters'=>['number'=>'900000000000008'],
        ]);
        self::assertSame('not_found',$result['response']['data']['official_state']);
        self::assertFalse($result['response']['data']['safe_to_emit']);
        self::assertSame('/nfse/dps/lookup',$api->calls[0]['path']);
        self::assertSame('900000000000008',$api->calls[0]['query']['number']);
        self::assertSame(1,$api->calls[0]['query']['company_id']);

        $api=$this->fakeApi();
        $api->fiscal=['data'=>['official_state'=>'not_found','safe_to_emit'=>true,
            'exclusive_series_attested'=>false]];
        $this->expectRejected(fn()=> (new ApiBrowser($api))->read($this->credentials(),1,[
            'route'=>'api.nfse.dps.lookup','nfse_filters'=>['number'=>'900000000000008'],
        ]));
    }

    public function testFiscalReadDeniesNestedForeignDataAndEmbeddedXml(): void
    {
        $api=$this->fakeApi();
        $api->fiscal=['data'=>[['id'=>1,'company_id'=>2]]];
        $this->expectRejected(fn()=> (new ApiBrowser($api))->read($this->credentials(),1,[
            'route'=>'api.nfse.ledger.index',
        ]));

        $api=$this->fakeApi();
        $api->fiscal=['data'=>[['id'=>2,'authorized_xml'=>'<NFSe>secret</NFSe>']]];
        $this->expectRejected(fn()=> (new ApiBrowser($api))->read($this->credentials(),1,[
            'route'=>'api.nfse.ledger.index',
        ]));

        $api=$this->fakeApi();
        $api->fiscal=['data'=>[['id'=>3,'api_token'=>'never-return','status'=>'emitted']]];
        $result=(new ApiBrowser($api))->read($this->credentials(),1,[
            'route'=>'api.nfse.ledger.index',
        ]);
        self::assertArrayNotHasKey('api_token',$result['response']['data'][0]);
        self::assertSame('emitted',$result['response']['data'][0]['status']);
    }

    public function testFiscalReadsRejectCoreRecordProjectionAndUnreviewedQueries(): void
    {
        $api=$this->fakeApi();
        foreach ([
            ['route'=>'api.nfse.ledger.index','fields'=>['id']],
            ['route'=>'api.nfse.ledger.index','page'=>2],
            ['route'=>'api.nfse.ledger.index','record_type'=>'invoice'],
            ['route'=>'api.nfse.adn.distribution'],
            ['route'=>'api.nfse.ledger.index','nfse_filters'=>['status'=>'bad']],
        ] as $case) {
            $this->expectRejected(fn()=> (new ApiBrowser($api))->read(
                $this->credentials(),1,$case,
            ));
        }
        self::assertSame([], $api->calls);
    }

    /** @return array<string,string> */
    private function credentials(): array
    {
        return ['email'=>'fixture@example.invalid','password'=>'synthetic-only'];
    }

    private function expectRejected(callable $fn): void
    {
        try {
            $fn();
            self::fail('Expected a rejected unsafe request');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }
    }

    private function fakeApi(): Api
    {
        return new class extends Api {
            public function __construct() {}
            public array $calls=[];
            public array $invoice=['data'=>['id'=>7,'company_id'=>1,'type'=>'invoice']];
            public array $fiscal=['fiscal_groups'=>[],'fiscal_profile_validation'=>[]];
            public function get(string $email,string $password,string $path,array $query=[]): array
            {
                $this->calls[]=['path'=>$path,'query'=>$query];
                return str_starts_with($path,'/documents/') ? $this->invoice : $this->fiscal;
            }
        };
    }
}
