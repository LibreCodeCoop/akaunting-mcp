<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace AkauntingMcp\Tests;

use AkauntingMcp\Api;
use AkauntingMcp\ApiBrowser;
use AkauntingMcp\FieldStudioCatalog;
use AkauntingMcp\RouteCatalog;
use AkauntingMcp\WriteRouteCatalog;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FieldStudioBehaviorTest extends TestCase
{
    private function api(): Api
    {
        return new class extends Api {
            /** @var list<array<string,mixed>> */
            public array $calls=[];
            public int $responseCompany=1;
            public array $ids=[7,8];
            public function __construct() {}
            public function get(string $email,string $password,string $path,array $query=[]): array
            {
                $this->calls[]=['path'=>$path,'query'=>$query];
                $company=$this->responseCompany;
                if ($path==='/v1/field-studio/definitions') {
                    return ['data'=>[['id'=>13,'company_id'=>$company,
                        'entity_type'=>'invoice','field_key'=>'cost_center',
                        'field_type'=>'text','version'=>2,'value'=>null]]];
                }
                if ($path==='/v1/field-studio/definitions/13') {
                    return ['data'=>['id'=>13,'company_id'=>$company,
                        'entity_type'=>'invoice','field_key'=>'cost_center',
                        'field_type'=>'text','version'=>2]];
                }
                if ($path==='/v1/field-studio/records/invoice/7/values') {
                    return ['data'=>[['id'=>13,'company_id'=>$company,
                        'entity_type'=>'invoice','field_key'=>'cost_center',
                        'value'=>'Operations','is_set'=>true,'value_version'=>2]]];
                }
                if ($path==='/v1/field-studio/records') {
                    return ['data'=>$this->ids];
                }
                if ($path==='/v1/field-studio/audit') {
                    return ['data'=>[['id'=>23,'company_id'=>$company,
                        'entity_type'=>'invoice','entity_id'=>7,'action'=>'value.updated']]];
                }
                throw new RuntimeException('Unknown synthetic route');
            }
        };
    }

    private function credentials(): array
    {
        return ['email'=>'synthetic@example.invalid','password'=>'synthetic-secret'];
    }

    public function testAllTwelveRoutesAreDiscoverableWithExactPathsAndMethods(): void
    {
        $catalog=new FieldStudioCatalog();
        self::assertCount(5,$catalog->listRead());
        self::assertCount(7,$catalog->listWrite());
        $reads=array_column((new RouteCatalog())->list(),null,'route');
        $writes=array_column((new WriteRouteCatalog())->list(),null,'route');
        foreach ($catalog->listRead() as $route) {
            self::assertArrayHasKey($route['route'],$reads);
        }
        foreach ($catalog->listWrite() as $route) {
            self::assertArrayHasKey($route['route'],$writes);
            self::assertSame('required_for_every_request',
                $writes[$route['route']]['human_approval']);
        }
        self::assertTrue((new RouteCatalog())->permitsPath(
            '/v1/field-studio/records/invoice/7/values'));
        self::assertFalse((new RouteCatalog())->permitsPath(
            '/v1/field-studio/records/invoice/7/values/cost_center'));
        self::assertTrue((new WriteRouteCatalog())->permits('PATCH',
            '/v1/field-studio/records/invoice/7/values'));
        self::assertTrue((new WriteRouteCatalog())->permits('DELETE',
            '/v1/field-studio/records/invoice/7/values/cost_center'));
        self::assertFalse((new WriteRouteCatalog())->permits('GET',
            '/v1/field-studio/records/invoice/7/values/cost_center'));
        self::assertFalse((new WriteRouteCatalog())->permits('POST',
            '/v1/field-studio/records/customer/7/values'));
    }

    public function testReadContractsAcceptTypedIdsAndRejectUnknownRoutesOrUnsafeFilters(): void
    {
        $c=new FieldStudioCatalog();
        self::assertSame('/v1/field-studio/records/invoice/7/values',
            $c->resolveRead('api.field-studio.values.show',[
                'entity_type'=>'invoice','entity_id'=>7,
            ])['path']);
        self::assertSame(['entity_type'=>'invoice','field_key'=>'cost_center',
            'value'=>'Operations','limit'=>20],
            $c->filters('api.field-studio.records.search',[
                'entity_type'=>'invoice','field_key'=>'cost_center',
                'value'=>'Operations','limit'=>20,
            ]));
        foreach ([
            fn()=> $c->resolveRead('api.field-studio.values.show',[
                'entity_type'=>'customer','entity_id'=>7]),
            fn()=> $c->resolveRead('api.field-studio.values.show',[
                'entity_type'=>'invoice','entity_id'=>0]),
            fn()=> $c->resolveRead('api.field-studio.values.show',[
                'entity_type'=>'invoice','entity_id'=>7,'company_id'=>2]),
            fn()=> $c->resolveRead('api.field-studio.values.delete',[
                'entity_type'=>'invoice','entity_id'=>7,'field_key'=>'cost_center']),
            fn()=> $c->filters('api.field-studio.records.search',[
                'entity_type'=>'invoice','field_key'=>'cost_center']),
            fn()=> $c->filters('api.field-studio.records.search',[
                'entity_type'=>'bill','field_key'=>'cost_center','value'=>'a',
                'company_id'=>2]),
            fn()=> $c->filters('api.field-studio.audit.index',['entity_type'=>'invoice']),
            fn()=> $c->filters('api.field-studio.definitions.index',['entity_id'=>7]),
            fn()=> $c->filters('api.field-studio.records.search',[
                'entity_type'=>'invoice','field_key'=>'cost_center',
                'value'=>'a','limit'=>201]),
        ] as $reject) {
            $this->mustReject($reject);
        }
    }

    public function testModuleJsonReadAppliesNativeCompanyBoundaryAndRejectsForeignData(): void
    {
        $api=$this->api();
        $result=(new ApiBrowser($api))->read($this->credentials(),1,[
            'route'=>'api.field-studio.definitions.index',
            'module_filters'=>['entity_type'=>'invoice'],
        ]);
        self::assertSame(1,$result['company_id']);
        self::assertSame('cost_center',$result['response']['data'][0]['field_key']);
        self::assertSame(['entity_type'=>'invoice','company_id'=>1],$api->calls[0]['query']);
        $result=(new ApiBrowser($api))->read($this->credentials(),1,[
            'route'=>'api.field-studio.values.show',
            'route_params'=>['entity_type'=>'invoice','entity_id'=>7],
        ]);
        self::assertSame('Operations',$result['response']['data'][0]['value']);
        $result=(new ApiBrowser($api))->read($this->credentials(),1,[
            'route'=>'api.field-studio.records.search',
            'module_filters'=>['entity_type'=>'invoice','field_key'=>'cost_center','value'=>'a'],
        ]);
        self::assertSame([7,8],$result['response']['data']);
        $result=(new ApiBrowser($api))->read($this->credentials(),1,[
            'route'=>'api.field-studio.audit.index',
            'module_filters'=>['entity_type'=>'invoice','entity_id'=>7],
        ]);
        self::assertSame('value.updated',$result['response']['data'][0]['action']);
        $api->responseCompany=2;
        $this->mustReject(fn()=> (new ApiBrowser($api))->read($this->credentials(),1,[
            'route'=>'api.field-studio.definitions.index',
        ]));
        $this->mustReject(fn()=> (new ApiBrowser($api))->read($this->credentials(),1,[
            'route'=>'api.field-studio.values.show',
            'route_params'=>['entity_type'=>'invoice','entity_id'=>7],
        ]));
    }

    public function testModuleJsonSearchRejectsNonIdResults(): void
    {
        $api=$this->api();
        $api->ids=[7,'foreign'];
        $this->mustReject(fn()=> (new ApiBrowser($api))->read($this->credentials(),1,[
            'route'=>'api.field-studio.records.search',
            'module_filters'=>['entity_type'=>'invoice','field_key'=>'cost_center','value'=>'a'],
        ]));
        $this->mustReject(fn()=> (new ApiBrowser($api))->read($this->credentials(),1,[
            'route'=>'api.field-studio.records.search',
            'nfse_filters'=>['page'=>1],
        ]));
    }

    public function testDefinitionAndValuesWritesRequireReviewedBodyAndServerSnapshot(): void
    {
        $catalog=new FieldStudioCatalog();
        $creds=$this->credentials();
        $api=$this->api();
        $body=['entity_type'=>'invoice','field_key'=>'cost_center',
            'label'=>'Cost center','field_type'=>'text'];
        $catalog->validateWrite('api.field-studio.definitions.store','POST',[],$body);
        $snapshot=$catalog->snapshot($api,$creds,1,
            'api.field-studio.definitions.store',[],$body);
        self::assertSame(64,strlen($snapshot));
        $catalog->validateWrite('api.field-studio.definitions.update','PATCH',
            ['definition'=>13],['label'=>'New label','expected_version'=>2]);
        $catalog->validateWrite('api.field-studio.values.patch','PATCH',
            ['entity_type'=>'invoice','entity_id'=>7],
            ['values'=>['cost_center'=>'Sales'],'versions'=>['cost_center'=>2]]);
        self::assertSame(64,strlen($catalog->snapshot($api,$creds,1,
            'api.field-studio.values.patch',
            ['entity_type'=>'invoice','entity_id'=>7],
            ['values'=>['cost_center'=>'Sales']])));
        $api->responseCompany=2;
        $this->mustReject(fn()=> $catalog->snapshot($api,$creds,1,
            'api.field-studio.definitions.store',[],$body));
    }

    public function testWriteValidationRejectsUnexpectedFieldsWrongMethodsAndTypes(): void
    {
        $c=new FieldStudioCatalog();
        $cases=[
            ['api.field-studio.definitions.store','POST',[],
                ['entity_type'=>'invoice','field_key'=>'cost_center','field_type'=>'text']],
            ['api.field-studio.definitions.store','POST',[],
                ['entity_type'=>'customer','field_key'=>'cost_center','label'=>'Cost center','field_type'=>'text']],
            ['api.field-studio.definitions.store','POST',[],
                ['entity_type'=>'invoice','field_key'=>'cost_center','label'=>'Cost center',
                 'field_type'=>'text','company_id'=>2]],
            ['api.field-studio.definitions.update','PATCH',['definition'=>13],
                ['label'=>'New label']],
            ['api.field-studio.definitions.update','PATCH',['definition'=>13],
                ['field_key'=>'new_key','expected_version'=>2]],
            ['api.field-studio.values.patch','PATCH',['entity_type'=>'invoice','entity_id'=>7],
                ['values'=>['cost_center'=>'Sales'],'versions'=>['other_field'=>1]]],
            ['api.field-studio.values.patch','PATCH',['entity_type'=>'invoice','entity_id'=>7],
                ['values'=>['cost_center'=>(object)['unsafe'=>true]]]],
            ['api.field-studio.values.delete','DELETE',
                ['entity_type'=>'invoice','entity_id'=>7,'field_key'=>'cost_center'],
                ['values'=>['cost_center'=>null]]],
            ['api.field-studio.values.delete','POST',
                ['entity_type'=>'invoice','entity_id'=>7,'field_key'=>'cost_center'],[]],
        ];
        foreach ($cases as [$route,$method,$params,$body]) {
            $this->mustReject(fn()=> $c->validateWrite($route,$method,$params,$body));
        }
        self::assertSame('/v1/field-studio/records/invoice/7/values/cost_center',
            $c->resolveWrite('api.field-studio.values.delete','DELETE',[
                'entity_type'=>'invoice','entity_id'=>7,'field_key'=>'cost_center',
            ])['path']);
    }

    private function mustReject(\Closure $action): void
    {
        try {
            $action();
            self::fail('Unreviewed or cross-company Field Studio operation was accepted');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }
    }
}
