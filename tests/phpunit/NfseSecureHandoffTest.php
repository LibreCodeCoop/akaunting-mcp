<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace AkauntingMcp\Tests;

use AkauntingMcp\Api;
use AkauntingMcp\NfseSecureHandoff;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NfseSecureHandoffTest extends TestCase
{
    private function api(): Api
    {
        return new class extends Api {
            public string $base='https://accounting.example.invalid';
            public int $company=1;
            public int $receipt=55;
            public bool $artifactReady=true;
            public array $calls=[];

            public function __construct() {}

            public function applicationBaseUrl(): string { return $this->base; }

            public function verify(string $email,string $password,int $companyId): void
            {
                if ($email!=='operator@example.invalid' || $password!=='synthetic-secret'
                    || $companyId!==$this->company) {
                    throw new RuntimeException('Cross-company or invalid identity');
                }
            }

            public function get(string $email,string $password,string $path,array $query=[]): array
            {
                $this->verify($email,$password,$query['company_id']??0);
                $this->calls[]=['path'=>$path,'query'=>$query];
                if ($path==='/nfse/settings/company-profiles') {
                    return ['profiles'=>[],'eligible_companies'=>[['company_id'=>1,'name'=>'Synthetic']]];
                }
                if ($path==='/nfse/closing') {
                    return ['data'=>['competence'=>$query['competence']??null,
                        'summary'=>['count'=>1]]];
                }
                if ($path==='/documents/7') {
                    return ['data'=>['id'=>7,'company_id'=>1,'type'=>'invoice']];
                }
                if ($path==='/nfse/invoices/7/post-emission-status') {
                    return ['data'=>['receipt_id'=>$this->receipt,'artifacts'=>[
                        'xml'=>['ready'=>$this->artifactReady],
                        'danfse'=>['ready'=>$this->artifactReady],
                    ]]];
                }
                throw new RuntimeException('Unreviewed synthetic read');
            }
        };
    }

    private function creds(): array
    {
        return ['email'=>'operator@example.invalid','password'=>'synthetic-secret'];
    }

    public function testSensitiveAdministrationUsesNativeBrowserWithoutHandlingSecrets(): void
    {
        foreach ([
            'api.nfse.certificates.inspect'=>'settings?tab=company-profiles',
            'api.nfse.settings.company-profiles.store'=>'settings?tab=company-profiles',
            'api.nfse.settings.vault.update'=>'settings?tab=vault',
        ] as $route=>$destination) {
            $api=$this->api();
            $result=(new NfseSecureHandoff($api))->link($this->creds(),1,$route,[]);
            self::assertSame('https://accounting.example.invalid/1/nfse/'.$destination,
                $result['url']);
            self::assertTrue($result['requires_separate_browser_authentication']);
            self::assertFalse($result['transfers_credentials_or_file_contents_to_mcp']);
            self::assertCount(1,$api->calls);
            self::assertSame('/nfse/settings/company-profiles',$api->calls[0]['path']);
            self::assertStringNotContainsString('synthetic-secret',$result['url']);
        }
    }

    public function testCsvExportsRequireExactCompetenceAndNativeReadPermission(): void
    {
        foreach ([
            'api.nfse.closing.export',
            'api.nfse.closing.ibs-cbs-evidence',
        ] as $route) {
            $api=$this->api();
            $result=(new NfseSecureHandoff($api))->link($this->creds(),1,
                $route,['competence'=>'2026-11']);
            self::assertSame(
                'https://accounting.example.invalid/1/nfse/closing?competence=2026-11',
                $result['url'],
            );
            self::assertSame('/nfse/closing',$api->calls[0]['path']);
            self::assertSame(1,$api->calls[0]['query']['company_id']);
        }
        foreach (['2026-13','2026-11&company_id=2','../2026-11'] as $bad) {
            $this->mustReject(fn()=> (new NfseSecureHandoff($this->api()))->link(
                $this->creds(),1,'api.nfse.closing.export',['competence'=>$bad],
            ));
        }
    }

    public function testArtifactHandoffRequiresCurrentCompanyOwnedReadyReceipt(): void
    {
        $route='api.nfse.receipts.artifacts.download';
        $params=['invoice'=>7,'receipt'=>55,'artifact'=>'xml'];
        $api=$this->api();
        $result=(new NfseSecureHandoff($api))->link($this->creds(),1,$route,$params);
        self::assertSame(
            'https://accounting.example.invalid/1/nfse/ledger/55/artifacts/xml',
            $result['url'],
        );
        self::assertSame(['/documents/7','/nfse/invoices/7/post-emission-status'],
            array_column($api->calls,'path'));

        $api->receipt=56;
        $this->mustReject(fn()=> (new NfseSecureHandoff($api))->link(
            $this->creds(),1,$route,$params,
        ));
        $api->receipt=55;$api->artifactReady=false;
        $this->mustReject(fn()=> (new NfseSecureHandoff($api))->link(
            $this->creds(),1,$route,$params,
        ));
        foreach ([
            [...$params,'artifact'=>'../../vault'],
            [...$params,'receipt'=>'55'],
            [...$params,'invoice'=>8],
            [...$params,'debug'=>true],
        ] as $bad) {
            $this->mustReject(fn()=> (new NfseSecureHandoff($this->api()))->link(
                $this->creds(),1,$route,$bad,
            ));
        }
    }

    public function testNoUnreviewedOperationOrUnsafeOriginIsAccepted(): void
    {
        $this->mustReject(fn()=> (new NfseSecureHandoff($this->api()))->link(
            $this->creds(),1,'api.nfse.invoices.emit',[],
        ));
        $this->mustReject(fn()=> (new NfseSecureHandoff($this->api()))->link(
            $this->creds(),2,'api.nfse.settings.vault.update',[],
        ));
        $this->mustReject(fn()=> (new NfseSecureHandoff($this->api()))->link(
            $this->creds(),1,'api.nfse.certificates.inspect',['pfx_password'=>'secret'],
        ));
        $api=$this->api();
        $api->base='http://untrusted.example.invalid';
        $this->mustReject(fn()=> (new NfseSecureHandoff($api))->link(
            $this->creds(),1,'api.nfse.settings.vault.update',[],
        ));
    }

    private function mustReject(callable $f): void
    {
        try {
            $f();
            self::fail('Unsafe fiscal browser handoff accepted');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }
    }
}
