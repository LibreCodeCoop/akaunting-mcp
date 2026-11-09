<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace AkauntingMcp\Tests;

use AkauntingMcp\Api;
use AkauntingMcp\ApiBrowser;
use AkauntingMcp\NfseReadOnlyPostCatalog;
use AkauntingMcp\RouteCatalog;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NfseOperationalReadTest extends TestCase
{
    private function fakeApi(): Api
    {
        return new class extends Api {
            public function __construct() {}
            public array $calls=[];
            public bool $foreign=false;
            public bool $secret=false;
            public string $vaultReason='verified';
            public function postReadOnly(
                string $email,string $password,string $path,int $companyId,array $body
            ): array {
                $this->calls[]=compact('path','companyId','body');
                if ($path==='/nfse/settings/vault/diagnostics') {
                    return ['http_status'=>200,'data'=>[
                        'state'=>'operational','reason'=>$this->vaultReason,
                        'capabilities'=>['network'=>true,'authentication'=>true,'kv_v2'=>true,
                            'certificate_read'=>true,'certificate_write'=>true],
                    ]];
                }
                if ($path==='/nfse/bulk/plan') {
                    return ['http_status'=>200,'data'=>['data'=>[
                        'queued'=>[['invoice_id'=>7,'company_id'=>$this->foreign?2:1]],
                        'blocked'=>[],'already_issued'=>[],
                        'not_found_or_forbidden'=>[],
                        ...($this->secret?['certificate_password'=>'forbidden']:[]),
                    ]]];
                }
                throw new RuntimeException('Unexpected read-only POST route');
            }
        };
    }

    public function testRegisteredPostOperationsAreReadOnlyAndNeverBecomeGetOrMutations(): void
    {
        $routes=array_column((new RouteCatalog())->list(),null,'route');
        foreach ([
            'api.nfse.bulk.plan'=>'/nfse/bulk/plan',
            'api.nfse.settings.vault.diagnostics'=>'/nfse/settings/vault/diagnostics',
        ] as $name=>$path) {
            self::assertSame(['POST'],$routes[$name]['methods']);
            self::assertSame('reviewed_read_only_post',$routes[$name]['transport']);
            self::assertFalse((new RouteCatalog())->permitsPath($path));
        }
        $safe=new NfseReadOnlyPostCatalog();
        self::assertTrue($safe->permitsPath('/nfse/bulk/plan'));
        self::assertFalse($safe->permitsPath('/nfse/bulk/dispatch'));
        self::assertFalse($safe->permitsPath('/nfse/invoices/7/emit'));
        self::assertFalse($safe->permitsPath('/nfse/settings/vault'));
    }

    public function testBulkPreviewReturnsValidatedPlanWithoutApprovalOrFinancialWrite(): void
    {
        $api=$this->fakeApi();
        $result=(new ApiBrowser($api))->read([
            'email'=>'fixture@example.invalid','password'=>'synthetic-password',
        ],1,[
            'route'=>'api.nfse.bulk.plan',
            'post_body'=>['invoice_ids'=>[7]],
        ]);
        self::assertSame(7,$result['response']['data']['queued'][0]['invoice_id']);
        self::assertSame(['path'=>'/nfse/bulk/plan','companyId'=>1,
            'body'=>['invoice_ids'=>[7]]],$api->calls[0]);
        self::assertCount(1,$api->calls);
        $api->foreign=true;
        $this->reject(fn()=> (new ApiBrowser($api))->read([
            'email'=>'fixture@example.invalid','password'=>'synthetic-password',
        ],1,['route'=>'api.nfse.bulk.plan','post_body'=>['invoice_ids'=>[7]]]));
        $api->foreign=false;
        $api->secret=true;
        $this->reject(fn()=> (new ApiBrowser($api))->read([
            'email'=>'fixture@example.invalid','password'=>'synthetic-password',
        ],1,['route'=>'api.nfse.bulk.plan','post_body'=>['invoice_ids'=>[7]]]));
    }

    public function testVaultDiagnosticRunsWithoutSecretsAndRejectsBody(): void
    {
        $api=$this->fakeApi();
        $result=(new ApiBrowser($api))->read([
            'email'=>'fixture@example.invalid','password'=>'synthetic-password',
        ],1,['route'=>'api.nfse.settings.vault.diagnostics']);
        self::assertSame('operational',$result['response']['state']);
        self::assertTrue($result['response']['capabilities']['kv_v2']);
        self::assertTrue($result['response']['capabilities']['certificate_read']);
        self::assertTrue($result['response']['capabilities']['certificate_write']);
        self::assertSame([],$api->calls[0]['body']);
        $this->reject(fn()=> (new ApiBrowser($api))->read([
            'email'=>'fixture@example.invalid','password'=>'synthetic-password',
        ],1,['route'=>'api.nfse.settings.vault.diagnostics',
            'post_body'=>['bao_token'=>'secret']]));
        self::assertCount(1,$api->calls);
        $api->vaultReason='secret from unauthorized upstream response';
        $this->reject(fn()=> (new ApiBrowser($api))->read([
            'email'=>'fixture@example.invalid','password'=>'synthetic-password',
        ],1,['route'=>'api.nfse.settings.vault.diagnostics']));
    }

    public function testReadOnlyPostPayloadRejectsMutationFieldsAndUnexpectedShapes(): void
    {
        $api=$this->fakeApi();
        foreach ([
            ['route'=>'api.nfse.bulk.plan','post_body'=>['invoice_ids'=>[7,7]]],
            ['route'=>'api.nfse.bulk.plan','post_body'=>['invoice_ids'=>[0]]],
            ['route'=>'api.nfse.bulk.plan','post_body'=>['invoice_ids'=>['7']]],
            ['route'=>'api.nfse.bulk.plan','post_body'=>['invoice_ids'=>[]]],
            ['route'=>'api.nfse.bulk.plan','post_body'=>['invoice_ids'=>range(1,101)]],
            ['route'=>'api.nfse.bulk.plan','post_body'=>['invoice_ids'=>[7],'company_id'=>2]],
            ['route'=>'api.nfse.bulk.plan','post_body'=>['invoice_ids'=>[7],'dispatch'=>true]],
            ['route'=>'api.nfse.bulk.plan','post_body'=>['invoice_ids'=>[7]],'nfse_filters'=>['page'=>1]],
            ['route'=>'api.nfse.ledger.index','post_body'=>['invoice_ids'=>[7]]],
            ['route'=>'api.nfse.invoices.emit','post_body'=>['nfse_force_ajax'=>true]],
        ] as $request) {
            $this->reject(fn()=> (new ApiBrowser($api))->read([
                'email'=>'fixture@example.invalid','password'=>'synthetic-password',
            ],1,$request));
        }
        self::assertSame([],$api->calls);
    }

    private function reject(callable $call): void
    {
        try {
            $call();
            self::fail('Unsafe operational read unexpectedly reached API');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }
    }
}
