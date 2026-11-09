<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace AkauntingMcp\Tests;

use AkauntingMcp\Api;
use AkauntingMcp\ApprovalService;
use AkauntingMcp\Config;
use AkauntingMcp\NfseReadCatalog;
use AkauntingMcp\NfseReadService;
use AkauntingMcp\NfseWriteCatalog;
use AkauntingMcp\Store;
use AkauntingMcp\WriteRouteCatalog;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NfseWriteBehaviorTest extends TestCase
{
    private string $directory;
    private Store $store;
    private string $subject;
    private Api $api;
    private ApprovalService $approval;
    private string|false $previousFiscalFlag;

    protected function setUp(): void
    {
        $this->previousFiscalFlag=getenv('MCP_NFSE_FISCAL_WRITES_ENABLED');
        putenv('MCP_NFSE_FISCAL_WRITES_ENABLED=true');
        $this->directory=sys_get_temp_dir().'/nfse-write-synthetic-'.bin2hex(random_bytes(8));
        mkdir($this->directory,0700);
        $config=new Config(
            'https://accounting.example.invalid','https://accounting.example.invalid/mcp-auth',
            'https://api.openai.com/v1/mcp/tunnel_synthetic','test-client',
            'https://chatgpt.com/connector/oauth/synthetic',
            $this->directory,random_bytes(32),['localhost'],
        );
        $this->store=new Store($config);
        $this->subject=$this->store->saveLogin('member@example.invalid','correct-password',1);
        $this->api=new class($config) extends Api {
            public int $writes=0;
            public array $lastWrite=[];
            public bool $sandbox=true;
            public bool $ready=true;
            public int $dpsEnvironment=2;
            public int $dpsCompany=1;
            public string $dpsSeries='00001';
            public bool $profileReady=true;
            public int $receiptId=0;
            public string $receiptState='emitted';
            public string $receiptNumber='9900123';
            public int $invoiceCompany=1;
            public int $profileVersion=1;
            public string $groupKey='service:0107|tax:010701|mun:123|rate:5.00';
            public ?string $eventDate=null;

            public function verify(string $email,string $password,int $companyId): void
            {
                if ($email!=='member@example.invalid' || $password!=='correct-password'
                    || $companyId!==1) throw new RuntimeException('Synthetic identity not authorized');
            }

            public function get(string $email,string $password,string $path,array $query=[]): array
            {
                if ($path==='/nfse/settings/company-profiles') {
                    return ['profiles'=>[[
                        'company_id'=>1,'status'=>'active',
                        'fiscal_parameters'=>[
                            'sandbox_mode'=>$this->sandbox?'1':'0',
                            'dps_series'=>'00001',
                        ],
                        'version'=>$this->profileVersion,
                    ]], 'eligible_companies'=>[]];
                }
                if ($path==='/nfse/settings/tax-associations') {
                    return ['data'=>['taxes'=>[[
                        'id'=>3,'name'=>'Synthetic ISS','type'=>'inclusive','rate'=>'2',
                        'revisions'=>[['id'=>9,'fiscal_identity'=>'issqn','fiscal_role'=>'provider_due']],
                    ]],'identities'=>['issqn'],'roles'=>['provider_due']]];
                }
                if ($path==='/nfse/settings/approximate-taxes') {
                    return ['data'=>[]];
                }
                if ($path==='/documents/7') {
                    return ['data'=>['id'=>7,'company_id'=>$this->invoiceCompany,'type'=>'invoice']];
                }
                if ($path==='/nfse/invoices/7/post-emission-status') {
                    return ['data'=>[
                        'status'=>$this->receiptId>0?'complete':'idle',
                        'receipt_id'=>$this->receiptId>0?$this->receiptId:null,
                        'nfse_number'=>$this->receiptId>0?$this->receiptNumber:null,
                        'artifacts'=>['danfse'=>['ready'=>false,'download_url'=>null]],
                    ]];
                }
                if ($path==='/nfse/ledger') {
                    $rows=$this->receiptId>0
                        && in_array($this->receiptState,['cancelled','emitted'],true)
                        && ($query['status']??null)===$this->receiptState
                        && ($query['search']??null)===$this->receiptNumber
                        ? [[
                            'id'=>$this->receiptId,'invoice_id'=>7,
                            'nfse_number'=>$this->receiptNumber,'status'=>$this->receiptState,
                        ]] : [];
                    return ['data'=>$rows,'meta'=>['total'=>count($rows)]];
                }
                if ($path==='/nfse/invoices/7/fiscal-assessment') {
                    return [
                        'data'=>[[
                            'group_key'=>$this->groupKey,'service_code'=>'0107',
                            'amount'=>'100.00','taxable_event_date'=>$this->eventDate,
                            'evidence_kind'=>$this->eventDate===null?null:'operator_attestation',
                            'diagnostics'=>[],
                        ]],
                        'scope'=>'rtc_calendar_preview','final_dps_checked'=>false,
                    ];
                }
                if ($path==='/nfse/invoices/7/service-preview') {
                    return ['fiscal_groups'=>[],'fiscal_profile_validation'=>[
                        'isReady'=>$this->profileReady,
                    ]];
                }
                if ($path==='/nfse/dps/readiness') {
                    return ['data'=>[
                        'company_id'=>$this->dpsCompany,'environment'=>$this->dpsEnvironment,
                        'series'=>$this->dpsSeries,'mechanically_ready'=>$this->ready,
                        'reasons'=>$this->ready?[]:['series_inactive'],
                        'external_reconciliation'=>'not_proven_by_this_endpoint',
                    ]];
                }
                throw new RuntimeException('Unreviewed synthetic read: '.$path);
            }

            public function mutate(
                string $email,string $password,string $method,string $path,int $companyId,array $body,?string $recordType=null
            ): array {
                ++$this->writes;
                $this->lastWrite=compact('method','path','companyId','body','recordType');
                return ['http_status'=>$method==='DELETE'?204:200];
            }
        };
        $this->approval=new ApprovalService($this->store,$this->api,$config->issuer);
    }

    protected function tearDown(): void
    {
        putenv($this->previousFiscalFlag===false
            ? 'MCP_NFSE_FISCAL_WRITES_ENABLED'
            : 'MCP_NFSE_FISCAL_WRITES_ENABLED='.$this->previousFiscalFlag);
        $files=new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory,\FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    private function prepare(string $route,string $method,array $params,array $body): array
    {
        return $this->approval->prepare($this->subject,$this->store->load($this->subject),1,[
            'route'=>$route,'method'=>$method,'route_params'=>$params,'body'=>$body,
        ]);
    }

    private function approve(array $proposal,string $phrase=''): array
    {
        $view=$this->approval->review($proposal['request_id']);
        return $this->approval->decide(
            $view['request_id'],$view['csrf'],$view['cookie'],
            'approve','member@example.invalid','correct-password',$phrase,
        );
    }

    public function testFiscalRoutesAreExplicitAndCannotReachSecretsOrOtherCompanies(): void
    {
        $routes=new WriteRouteCatalog();
        $names=array_column($routes->list(),'route');
        self::assertContains('api.nfse.invoices.emit',$names);
        self::assertContains('api.nfse.invoices.reemit',$names);
        self::assertContains('api.nfse.invoices.substitute',$names);
        self::assertContains('api.nfse.settings.company-profiles.update',$names);
        self::assertNotContains('api.nfse.settings.vault.update',$names);
        self::assertSame(
            '/nfse/invoices/7/emit',
            $routes->resolve('api.nfse.invoices.emit','POST',['invoice'=>7])['path'],
        );
        self::assertTrue($routes->permits('POST','/nfse/invoices/7/emit'));
        self::assertTrue($routes->permits('POST','/nfse/invoices/7/reemit'));
        self::assertTrue($routes->permits('POST','/nfse/invoices/7/substitute'));
        self::assertFalse($routes->permits('POST','/nfse/invoices/7'));
        self::assertFalse($routes->permits('POST','/nfse/settings/vault'));
        self::assertFalse($routes->permits('POST','/nfse/invoices/7/emit/../../vault'));
        self::assertFalse($routes->permits('POST','/nfse/invoices/0007/emit'));
        $this->expectException(RuntimeException::class);
        $routes->resolve('api.nfse.invoices.emit','POST',['invoice'=>7,'companyId'=>2]);
    }

    public function testFiscalProfileUpdateRequiresIndependentApprovalWithoutInjectedCompanyId(): void
    {
        $proposal=$this->prepare(
            'api.nfse.settings.company-profiles.update','PATCH',['companyId'=>1],
            ['fiscal_parameters'=>['emission_policy'=>'manual','sandbox_mode'=>true]],
        );
        self::assertSame('awaiting_human_approval',$proposal['status']);
        self::assertSame(0,$this->api->writes);
        self::assertSame('completed',$this->approve($proposal)['status']);
        self::assertSame(1,$this->api->writes);
        self::assertSame('/nfse/settings/company-profiles/1',$this->api->lastWrite['path']);
        self::assertSame(1,$this->api->lastWrite['companyId']);
        self::assertArrayNotHasKey('company_id',$this->api->lastWrite['body']);
        self::assertSame('manual',$this->api->lastWrite['body']['fiscal_parameters']['emission_policy']);
    }

    public function testProductionAndAutomaticPolicyChangesRequireASeparateConfirmedProfileWrite(): void
    {
        $proposal=$this->prepare(
            'api.nfse.settings.company-profiles.update','PATCH',['companyId'=>1],
            ['fiscal_parameters'=>['sandbox_mode'=>false,'emission_policy'=>'emit_on_send']],
        );
        self::assertSame('awaiting_human_approval',$proposal['status']);
        self::assertSame(0,$this->api->writes);
        self::assertSame('completed',$this->approve($proposal)['status']);
        self::assertSame(1,$this->api->writes);
        self::assertFalse($this->api->lastWrite['body']['fiscal_parameters']['sandbox_mode']);
        self::assertSame('emit_on_send',
            $this->api->lastWrite['body']['fiscal_parameters']['emission_policy']);
    }

    public function testProductionIssueRequiresMatchingAuthorityAndNewApproval(): void
    {
        $this->api->sandbox=false;
        try {
            $this->prepare('api.nfse.invoices.emit','POST',['invoice'=>7],[
                'nfse_force_ajax'=>true,
            ]);
            self::fail('Restricted series must not authorize production');
        } catch (RuntimeException) {
            self::assertSame(0,$this->api->writes);
        }
        $this->api->dpsEnvironment=1;
        $proposal=$this->prepare('api.nfse.invoices.emit','POST',['invoice'=>7],[
            'nfse_force_ajax'=>true,
        ]);
        self::assertSame(0,$this->api->writes);
        self::assertSame('awaiting_human_approval',$proposal['status']);
        self::assertSame('completed',$this->approve($proposal)['status']);
        self::assertSame(1,$this->api->writes);
    }

    public function testProductionIssueRejectsForeignOrDifferentSeriesDpsAuthority(): void
    {
        $this->api->sandbox=false;
        $this->api->dpsEnvironment=1;
        foreach ([
            ['dpsCompany'=>2,'dpsSeries'=>'00001'],
            ['dpsCompany'=>1,'dpsSeries'=>'00002'],
        ] as $invalid) {
            $this->api->dpsCompany=$invalid['dpsCompany'];
            $this->api->dpsSeries=$invalid['dpsSeries'];
            try {
                $this->prepare('api.nfse.invoices.emit','POST',['invoice'=>7],[
                    'nfse_force_ajax'=>true,
                ]);
                self::fail('Unrelated DPS authority must not permit an issuance');
            } catch (RuntimeException) {
                self::assertSame(0,$this->api->writes);
            }
        }
    }

    public function testNativeHostConfirmedProductionEmissionIsSingleUse(): void
    {
        $this->api->sandbox=false;
        $this->api->dpsEnvironment=1;
        $proposal=$this->approval->prepare(
            $this->subject,$this->store->load($this->subject),1,[
                'route'=>'api.nfse.invoices.emit','method'=>'POST',
                'route_params'=>['invoice'=>7],
                'body'=>['nfse_force_ajax'=>true],
                'approval_transport'=>'chatgpt',
            ],
        );
        self::assertSame('awaiting_host_confirmation',$proposal['status']);
        self::assertSame(0,$this->api->writes);
        self::assertSame('completed',$this->approval->executeNative(
            $this->subject,1,$proposal['request_id'],'api.nfse.invoices.emit','POST','',
        )['status']);
        self::assertSame(1,$this->api->writes);
        $this->expectException(RuntimeException::class);
        $this->approval->executeNative(
            $this->subject,1,$proposal['request_id'],'api.nfse.invoices.emit','POST','',
        );
    }

    public function testProductionEnvironmentChangeRevokesPreparedFiscalWrite(): void
    {
        $this->api->sandbox=false;
        $this->api->dpsEnvironment=1;
        $proposal=$this->prepare('api.nfse.invoices.emit','POST',['invoice'=>7],[
            'nfse_force_ajax'=>true,
        ]);
        $this->api->sandbox=true;
        $this->api->dpsEnvironment=2;
        try {
            $this->approve($proposal);
            self::fail('A fiscal environment change must invalidate a proposed POST');
        } catch (RuntimeException) {
            self::assertSame(0,$this->api->writes);
            self::assertSame('outcome_unconfirmed',
                $this->approval->status($this->subject,$proposal['request_id'])['status']);
        }
    }

    public function testProfileChangeDuringApprovalIsNotExecuted(): void
    {
        $proposal=$this->prepare(
            'api.nfse.settings.company-profiles.update','PATCH',['companyId'=>1],
            ['fiscal_parameters'=>['emission_policy'=>'manual']],
        );
        $this->api->profileVersion++;
        $this->expectException(RuntimeException::class);
        try {
            $this->approve($proposal);
        } finally {
            self::assertSame(0,$this->api->writes);
            self::assertSame('outcome_unconfirmed',
                $this->approval->status($this->subject,$proposal['request_id'])['status']);
        }
    }

    public function testFiscalProfileRejectsSecretsAndForeignCompany(): void
    {
        foreach ([
            ['companyId'=>2],
            ['companyId'=>1],
        ] as $index=>$params) {
            try {
                $this->prepare('api.nfse.settings.company-profiles.update','PATCH',$params,
                    ['fiscal_parameters'=>$index===0?['sandbox_mode'=>true]:['pfx_password'=>'hidden']]);
                self::fail('Unsafe profile mutation accepted');
            } catch (RuntimeException) {
                self::assertSame(0,$this->api->writes);
            }
        }
    }

    public function testTaxAssociationUsesExistingCompanyTaxAndExplicitProvenance(): void
    {
        $body=[
            'tax_id'=>3,'fiscal_identity'=>'issqn','fiscal_role'=>'provider_due',
            'effective_from'=>'2026-11-01','source_reference'=>'Documented synthetic policy',
            'acknowledge'=>true,
        ];
        $proposal=$this->prepare('api.nfse.settings.tax-associations.store','POST',[],$body);
        self::assertSame('completed',$this->approve($proposal)['status']);
        self::assertArrayNotHasKey('company_id',$this->api->lastWrite['body']);
        try {
            $this->prepare('api.nfse.settings.tax-associations.store','POST',[],
                [...$body,'tax_id'=>8]);
            self::fail('Unknown tax accepted');
        } catch (RuntimeException) {
            self::assertSame(1,$this->api->writes);
        }
        try {
            $this->prepare('api.nfse.settings.tax-associations.store','POST',[],
                [...$body,'acknowledge'=>false]);
            self::fail('Unconfirmed fiscal association accepted');
        } catch (RuntimeException) {
            self::assertSame(1,$this->api->writes);
        }
    }

    public function testApproximateTaxesAcceptOnlyDocumentedDecimalEvidence(): void
    {
        $body=[
            'national_service_code'=>'010101','simples_option'=>'1',
            'competence_from'=>'2026-11-01','source_kind'=>'documented_zero',
            'source_name'=>'Synthetic source','source_version'=>'2026-11',
            'source_reference'=>'Synthetic source reference',
            'federal_percent'=>'0.00','state_percent'=>'0.00','municipal_percent'=>'0.00',
        ];
        $proposal=$this->prepare('api.nfse.settings.approximate-taxes.store','POST',[],$body);
        self::assertSame('completed',$this->approve($proposal)['status']);
        $this->expectException(RuntimeException::class);
        (new NfseWriteCatalog())->validate(
            'api.nfse.settings.approximate-taxes.store',
            [...$body,'federal_percent'=>0.00],1,[],
        );
    }

    public function testIssuanceIsBlockedUntilRestrictedDpsAndFiscalProfileAreReady(): void
    {
        $body=['nfse_force_ajax'=>true];
        $this->api->ready=false;
        try {
            $this->prepare('api.nfse.invoices.emit','POST',['invoice'=>7],$body);
            self::fail('Inactive DPS was accepted');
        } catch (RuntimeException) {
            self::assertSame(0,$this->api->writes);
        }
        $this->api->ready=true;
        $this->api->profileReady=false;
        try {
            $this->prepare('api.nfse.invoices.emit','POST',['invoice'=>7],$body);
            self::fail('Unready fiscal profile was accepted');
        } catch (RuntimeException) {
            self::assertSame(0,$this->api->writes);
        }
        $this->api->profileReady=true;
        $proposal=$this->prepare('api.nfse.invoices.emit','POST',['invoice'=>7],$body);
        self::assertSame('awaiting_human_approval',$proposal['status']);
        self::assertSame(0,$this->api->writes);
        self::assertSame('completed',$this->approve($proposal)['status']);
        self::assertSame('/nfse/invoices/7/emit',$this->api->lastWrite['path']);
    }

    public function testReceiptCreatedAfterApprovalPreparationBlocksEmissionReplay(): void
    {
        $proposal=$this->prepare(
            'api.nfse.invoices.emit','POST',['invoice'=>7],['nfse_force_ajax'=>true],
        );
        $this->api->receiptId=12;
        $this->expectException(RuntimeException::class);
        try {
            $this->approve($proposal);
        } finally {
            self::assertSame(0,$this->api->writes);
        }
    }

    public function testFiscalCancellationRequiresCurrentReceiptAndCancelPhrase(): void
    {
        $this->api->receiptId=12;
        $body=[
            'nfse_receipt_id'=>12,'cancel_reason'=>'9',
            'cancel_justification'=>'Synthetic cancellation with operator approval',
        ];
        $proposal=$this->prepare('api.nfse.invoices.cancel','DELETE',['invoice'=>7],$body);
        $view=$this->approval->review($proposal['request_id']);
        try {
            $this->approval->decide(
                $view['request_id'],$view['csrf'],$view['cookie'],
                'approve','member@example.invalid','correct-password','EXCLUIR',
            );
            self::fail('Generic delete phrase authorized a fiscal cancellation');
        } catch (RuntimeException) {
            self::assertSame(0,$this->api->writes);
        }
        self::assertSame('completed',$this->approval->decide(
            $view['request_id'],$view['csrf'],$view['cookie'],
            'approve','member@example.invalid','correct-password','CANCELAR',
        )['status']);
        self::assertSame('DELETE',$this->api->lastWrite['method']);
        self::assertSame('/nfse/invoices/7',$this->api->lastWrite['path']);
        self::assertSame(12,$this->api->lastWrite['body']['nfse_receipt_id']);
    }

    public function testReemissionRequiresCancelledCurrentReceiptAndBrowserApproval(): void
    {
        $this->api->receiptId=12;
        $this->api->receiptState='cancelled';
        $proposal=$this->prepare('api.nfse.invoices.reemit','POST',['invoice'=>7],[
            'nfse_receipt_id'=>12,'nfse_force_ajax'=>true,
        ]);
        self::assertSame('awaiting_human_approval',$proposal['status']);
        self::assertSame(0,$this->api->writes);
        self::assertSame('completed',$this->approve($proposal)['status']);
        self::assertSame(1,$this->api->writes);
        self::assertSame('POST',$this->api->lastWrite['method']);
        self::assertSame('/nfse/invoices/7/reemit',$this->api->lastWrite['path']);
        self::assertSame(12,$this->api->lastWrite['body']['nfse_receipt_id']);
    }

    public function testReemissionRejectsActiveReceiptAndUnreviewedFields(): void
    {
        $this->api->receiptId=12;
        $body=['nfse_receipt_id'=>12,'nfse_force_ajax'=>true];
        try {
            $this->prepare('api.nfse.invoices.reemit','POST',['invoice'=>7],$body);
            self::fail('Active fiscal receipt was allowed to reemit');
        } catch (RuntimeException) {
            self::assertSame(0,$this->api->writes);
        }
        $this->api->receiptState='cancelled';
        foreach ([
            ['nfse_receipt_id'=>11],
            ['nfse_receipt_id'=>0],
            ['nfse_force_ajax'=>false],
            ['pfx_password'=>'forbidden'],
        ] as $override) {
            try {
                $this->prepare('api.nfse.invoices.reemit','POST',['invoice'=>7],[
                    ...$body,...$override,
                ]);
                self::fail('Unsafe fiscal reemission proposal was allowed');
            } catch (RuntimeException) {
                self::assertSame(0,$this->api->writes);
            }
        }
    }

    public function testReemissionDetectsFiscalStateChangeAfterApprovalPreparation(): void
    {
        $this->api->receiptId=12;
        $this->api->receiptState='cancelled';
        $proposal=$this->prepare('api.nfse.invoices.reemit','POST',['invoice'=>7],[
            'nfse_receipt_id'=>12,'nfse_force_ajax'=>true,
        ]);
        $this->api->receiptState='emitted';
        $this->expectException(RuntimeException::class);
        try {
            $this->approve($proposal);
        } finally {
            self::assertSame(0,$this->api->writes);
            self::assertSame('outcome_unconfirmed',
                $this->approval->status($this->subject,$proposal['request_id'])['status']);
        }
    }

    public function testReemissionRejectsMismatchedEnvironmentOrUnreadyDps(): void
    {
        $this->api->receiptId=12;
        $this->api->receiptState='cancelled';
        $body=['nfse_receipt_id'=>12,'nfse_force_ajax'=>true];
        $this->api->sandbox=false;
        try {
            $this->prepare('api.nfse.invoices.reemit','POST',['invoice'=>7],$body);
            self::fail('Restricted authority must not authorize production reemission');
        } catch (RuntimeException) {
            self::assertSame(0,$this->api->writes);
        }
        $this->api->sandbox=true;
        $this->api->ready=false;
        $this->expectException(RuntimeException::class);
        $this->prepare('api.nfse.invoices.reemit','POST',['invoice'=>7],$body);
    }

    public function testSubstitutionRequiresCurrentEmittedReceiptAndIndependentApproval(): void
    {
        $this->api->receiptId=12;
        $proposal=$this->prepare('api.nfse.invoices.substitute','POST',['invoice'=>7],[
            'nfse_substitution_receipt_id'=>12,
            'nfse_substitution_reason'=>'05',
            'nfse_substitution_description'=>'Synthetic correction in restricted fiscal environment',
            'nfse_force_ajax'=>true,
        ]);
        self::assertSame('awaiting_human_approval',$proposal['status']);
        self::assertSame(0,$this->api->writes);
        self::assertSame('completed',$this->approve($proposal)['status']);
        self::assertSame(1,$this->api->writes);
        self::assertSame('POST',$this->api->lastWrite['method']);
        self::assertSame('/nfse/invoices/7/substitute',$this->api->lastWrite['path']);
        self::assertSame(12,$this->api->lastWrite['body']['nfse_substitution_receipt_id']);
    }

    public function testSubstitutionRejectsWrongReceiptStatusAndUnexpectedFields(): void
    {
        $this->api->receiptId=12;
        $base=[
            'nfse_substitution_receipt_id'=>12,
            'nfse_substitution_reason'=>'05',
            'nfse_substitution_description'=>'Synthetic correction for a fiscal test case',
            'nfse_force_ajax'=>true,
        ];
        foreach ([
            ['nfse_substitution_receipt_id'=>11],
            ['nfse_substitution_receipt_id'=>0],
            ['nfse_substitution_reason'=>'88'],
            ['nfse_substitution_reason'=>'1'],
            ['nfse_substitution_description'=>'too short'],
            ['nfse_substitution_description'=>str_repeat('a',256)],
            ['nfse_substitution_description'=>"Invalid\nnewline inside reason"],
            ['nfse_force_ajax'=>false],
            ['pfx_password'=>'forbidden'],
            ['company_id'=>2],
        ] as $changes) {
            try {
                $this->prepare('api.nfse.invoices.substitute','POST',['invoice'=>7],[
                    ...$base,...$changes,
                ]);
                self::fail('Unsafe substitution proposal was prepared');
            } catch (RuntimeException) {
                self::assertSame(0,$this->api->writes);
            }
        }
        foreach (['cancelled','substituted','processing'] as $state) {
            $this->api->receiptState=$state;
            try {
                $this->prepare('api.nfse.invoices.substitute','POST',['invoice'=>7],$base);
                self::fail('Non-emitted receipt was accepted for substitution');
            } catch (RuntimeException) {
                self::assertSame(0,$this->api->writes);
            }
        }
    }

    public function testSubstitutionIsBlockedWhenReceiptChangesBeforeBrowserApproval(): void
    {
        $this->api->receiptId=12;
        $proposal=$this->prepare('api.nfse.invoices.substitute','POST',['invoice'=>7],[
            'nfse_substitution_receipt_id'=>12,
            'nfse_substitution_reason'=>'05',
            'nfse_substitution_description'=>'Synthetic correction under independent approval',
            'nfse_force_ajax'=>true,
        ]);
        $this->api->receiptId=13;
        $this->expectException(RuntimeException::class);
        try {
            $this->approve($proposal);
        } finally {
            self::assertSame(0,$this->api->writes);
            self::assertSame('outcome_unconfirmed',
                $this->approval->status($this->subject,$proposal['request_id'])['status']);
        }
    }

    public function testSubstitutionRequiresMatchingEnvironmentAndReadyDps(): void
    {
        $this->api->receiptId=12;
        $body=[
            'nfse_substitution_receipt_id'=>12,
            'nfse_substitution_reason'=>'05',
            'nfse_substitution_description'=>'Synthetic correction for fiscal validation',
            'nfse_force_ajax'=>true,
        ];
        $this->api->sandbox=false;
        try {
            $this->prepare('api.nfse.invoices.substitute','POST',['invoice'=>7],$body);
            self::fail('Restricted authority must not authorize production substitution');
        } catch (RuntimeException) {
            self::assertSame(0,$this->api->writes);
        }
        $this->api->sandbox=true;
        $this->api->ready=false;
        try {
            $this->prepare('api.nfse.invoices.substitute','POST',['invoice'=>7],$body);
            self::fail('Unready DPS was allowed');
        } catch (RuntimeException) {
            self::assertSame(0,$this->api->writes);
        }
        $this->api->ready=true;
        $this->api->profileReady=false;
        $this->expectException(RuntimeException::class);
        $this->prepare('api.nfse.invoices.substitute','POST',['invoice'=>7],$body);
    }

    public function testFiscalWritesRejectMismatchedEnvironmentAndForeignInvoices(): void
    {
        $this->api->sandbox=false;
        try {
            $this->prepare('api.nfse.invoices.emit','POST',['invoice'=>7],[
                'nfse_force_ajax'=>true,
            ]);
            self::fail('Restricted authority must not authorize production emission');
        } catch (RuntimeException) {
            self::assertSame(0,$this->api->writes);
        }
        $this->api->sandbox=true;
        $foreignCompany=2;
        $this->api->invoiceCompany=$foreignCompany;
        $this->expectException(RuntimeException::class);
        $this->prepare('api.nfse.invoices.emit','POST',['invoice'=>7],[
            'nfse_force_ajax'=>true,
        ]);
    }

    public function testProductionCancellationPreservesCurrentReceiptAndConfirmationPhrase(): void
    {
        $this->api->sandbox=false;
        $this->api->receiptId=12;
        $proposal=$this->prepare('api.nfse.invoices.cancel','DELETE',['invoice'=>7],[
            'nfse_receipt_id'=>12,'cancel_reason'=>'9',
            'cancel_justification'=>'Synthetic cancellation with legal approval',
        ]);
        $view=$this->approval->review($proposal['request_id']);
        try {
            $this->approval->decide(
                $view['request_id'],$view['csrf'],$view['cookie'],
                'approve','member@example.invalid','correct-password','EXCLUIR',
            );
            self::fail('Generic destructive confirmation must not cancel NFS-e');
        } catch (RuntimeException) {
            self::assertSame(0,$this->api->writes);
        }
        self::assertSame('completed',$this->approval->decide(
            $view['request_id'],$view['csrf'],$view['cookie'],
            'approve','member@example.invalid','correct-password','CANCELAR',
        )['status']);
        self::assertSame(1,$this->api->writes);
    }

    public function testProductionReissueAndSubstitutionRequireCurrentReceiptAndDps(): void
    {
        $this->api->sandbox=false;
        $this->api->dpsEnvironment=1;
        $this->api->receiptId=12;
        $substitute=$this->prepare('api.nfse.invoices.substitute','POST',['invoice'=>7],[
            'nfse_substitution_receipt_id'=>12,
            'nfse_substitution_reason'=>'05',
            'nfse_substitution_description'=>'Synthetic correction authorized by operator',
            'nfse_force_ajax'=>true,
        ]);
        self::assertSame('completed',$this->approve($substitute)['status']);
        $this->api->receiptState='cancelled';
        $reissue=$this->prepare('api.nfse.invoices.reemit','POST',['invoice'=>7],[
            'nfse_receipt_id'=>12,'nfse_force_ajax'=>true,
        ]);
        self::assertSame('completed',$this->approve($reissue)['status']);
        self::assertSame(2,$this->api->writes);
    }

    public function testFiscalTransmissionRequiresExplicitDeploymentGate(): void
    {
        putenv('MCP_NFSE_FISCAL_WRITES_ENABLED');
        $routes=array_column((new NfseWriteCatalog())->list(),null,'route');
        self::assertFalse($routes['api.nfse.invoices.emit']['enabled']);
        self::assertFalse($routes['api.nfse.invoices.substitute']['enabled']);
        self::assertTrue($routes['api.nfse.settings.company-profiles.update']['enabled']);
        $this->expectException(RuntimeException::class);
        try {
            $this->prepare('api.nfse.invoices.emit','POST',['invoice'=>7],[
                'nfse_force_ajax'=>true,
            ]);
        } finally {
            self::assertSame(0,$this->api->writes);
        }
    }

    public function testFiscalEventReadIsExplicitlyOnlyCalendarPreview(): void
    {
        $routes=array_column((new NfseReadCatalog())->list(),'route');
        self::assertContains('api.nfse.invoices.fiscal-assessment',$routes);
        $response=(new NfseReadService($this->api))->read(
            $this->store->load($this->subject),1,[
                'route'=>'api.nfse.invoices.fiscal-assessment',
                'route_params'=>['invoice'=>7],
            ],
        )['response'];
        self::assertSame('rtc_calendar_preview',$response['scope']);
        self::assertFalse($response['final_dps_checked']);
        self::assertSame($this->api->groupKey,$response['data'][0]['group_key']);
    }

    public function testFiscalEventAttestationRequiresApprovalButNotTransmissionGate(): void
    {
        putenv('MCP_NFSE_FISCAL_WRITES_ENABLED');
        $routes=array_column((new NfseWriteCatalog())->list(),null,'route');
        self::assertTrue($routes['api.nfse.invoices.fiscal-event-evidence.store']['enabled']);
        self::assertFalse($routes['api.nfse.invoices.emit']['enabled']);
        $body=[
            'fiscal_group_key'=>$this->api->groupKey,
            'taxable_event_date'=>'2026-11-01',
            'source_reference'=>'Signed synthetic service acceptance document',
            'acknowledge'=>true,
        ];
        $proposal=$this->prepare(
            'api.nfse.invoices.fiscal-event-evidence.store','POST',['invoice'=>7],$body,
        );
        self::assertSame('awaiting_human_approval',$proposal['status']);
        self::assertSame(0,$this->api->writes);
        self::assertSame('completed',$this->approve($proposal)['status']);
        self::assertSame('/nfse/invoices/7/fiscal-event-evidence',$this->api->lastWrite['path']);
        self::assertSame('POST',$this->api->lastWrite['method']);
        self::assertSame($body,$this->api->lastWrite['body']);
        self::assertArrayNotHasKey('company_id',$this->api->lastWrite['body']);
    }

    public function testFiscalEventAttestationRejectsUnprovenOrCrossCompanyData(): void
    {
        $body=[
            'fiscal_group_key'=>$this->api->groupKey,
            'taxable_event_date'=>'2026-11-01',
            'source_reference'=>'Signed synthetic service acceptance document',
            'acknowledge'=>true,
        ];
        foreach ([
            ['fiscal_group_key'=>'service:missing|tax:000000|rate:5.00'],
            ['fiscal_group_key'=>'service:0107/../../vault'],
            ['taxable_event_date'=>'2026-02-30'],
            ['source_reference'=>'none'],
            ['acknowledge'=>false],
            ['pfx_password'=>'forbidden'],
        ] as $override) {
            try {
                $this->prepare('api.nfse.invoices.fiscal-event-evidence.store','POST',
                    ['invoice'=>7],[...$body,...$override]);
                self::fail('Unreviewed fiscal event attestation was accepted');
            } catch (RuntimeException) {
                self::assertSame(0,$this->api->writes);
            }
        }
        $foreignCompany=2;
        $this->api->invoiceCompany=$foreignCompany;
        $this->expectException(RuntimeException::class);
        $this->prepare('api.nfse.invoices.fiscal-event-evidence.store','POST',
            ['invoice'=>7],$body);
    }

    public function testFiscalEventChangeDuringApprovalPreventsStaleAttestation(): void
    {
        $proposal=$this->prepare(
            'api.nfse.invoices.fiscal-event-evidence.store','POST',['invoice'=>7],[
                'fiscal_group_key'=>$this->api->groupKey,
                'taxable_event_date'=>'2026-11-01',
                'source_reference'=>'Synthetic independently reviewed event date',
                'acknowledge'=>true,
            ],
        );
        $this->api->eventDate='2026-10-31';
        $this->expectException(RuntimeException::class);
        try {
            $this->approve($proposal);
        } finally {
            self::assertSame(0,$this->api->writes);
            self::assertSame('outcome_unconfirmed',
                $this->approval->status($this->subject,$proposal['request_id'])['status']);
        }
    }

    public function testPersistedFiscalGroupKeyAcceptsPipeAndDecimalRate(): void
    {
        (new NfseWriteCatalog())->validate(
            'api.nfse.invoices.emit',[
                'nfse_force_ajax'=>true,
                'nfse_fiscal_group_key'=>$this->api->groupKey,
            ],1,['invoice'=>7],
        );
        $this->expectException(RuntimeException::class);
        (new NfseWriteCatalog())->validate(
            'api.nfse.invoices.emit',[
                'nfse_force_ajax'=>true,
                'nfse_fiscal_group_key'=>'service:0107/../../vault',
            ],1,['invoice'=>7],
        );
    }

    public function testMcpRejectsInvalidEnvironmentAndAutomaticPolicyValues(): void
    {
        $catalog=new NfseWriteCatalog();
        foreach ([
            ['sandbox_mode'=>'false'],
            ['emission_policy'=>'automatic_without_review'],
            ['pfx_password'=>'hidden'],
        ] as $fields) {
            try {
                $catalog->validate('api.nfse.settings.company-profiles.update',
                    ['fiscal_parameters'=>$fields],1,['companyId'=>1]);
                self::fail('Unsupported fiscal configuration was accepted');
            } catch (RuntimeException) {
                self::assertSame(0,$this->api->writes);
            }
        }
    }
}
