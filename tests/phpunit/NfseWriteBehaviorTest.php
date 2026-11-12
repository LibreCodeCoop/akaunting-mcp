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
    private Api $client;
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
        $this->client=new class($config) extends Api {
            public int $writes=0;
            public array $lastWrite=[];
            public bool $sandbox=true;
            public bool $ready=true;
            public string $dpsCandidate='900000000000008';
            public int $dpsEnvironment=2;
            public int $dpsCompany=1;
            public string $dpsSeries='00001';
            public bool $profileReady=true;
            public int $receiptId=0;
            public string $receiptState='emitted';
            public string $receiptNumber='9900123';
            public int $invoiceCompany=1;
            public int $profileVersion=1;
            public bool $profileActive=true;
            public string $groupKey='service:0107|tax:010701|mun:123|rate:5.00';
            public ?string $eventDate=null;
            public int $categoryVersion=1;
            public bool $categoryCanIssue=true;
            public string $categoryMode='issue';
            public int $fiscalDecisionVersion=0;
            public bool $decisionDiscoveryAvailable=true;
            public string $adnReviewStatus='pending';
            public int $adnDocumentId=11;
            public int $adnCompany=1;
            public bool $adnCanImport=true;
            public int $adnVendorId=19;
            public int $adnCategoryId=31;
            public int $adnItemId=41;

            public function verify(string $email,string $password,int $companyId): void
            {
                if ($email!=='member@example.invalid' || $password!=='correct-password'
                    || $companyId!==1) throw new RuntimeException('Synthetic identity not authorized');
            }

            public function get(string $email,string $password,string $path,array $query=[]): array
            {
                if ($path==='/nfse/discovery') {
                    if (!$this->decisionDiscoveryAvailable) {
                        throw new RuntimeException('Akaunting request rejected (HTTP 404)');
                    }
                    return [
                        'version'=>1,'company_id'=>1,
                        'routes'=>[[
                            'name'=>'api.nfse.invoices.fiscal-decision.show',
                            'uri'=>'{company_id}/api/nfse/invoices/{invoice}/fiscal-decision',
                            'method'=>'GET','scope'=>'invoice','params'=>['invoice'],
                            'query'=>[],
                            'response_paths'=>[
                                '/data/current','/data/effective','/data/history',
                            ],
                            'permissions'=>['read-sales-invoices'],
                        ]],
                    ];
                }
                if ($path==='/nfse/settings/company-profiles') {
                    return ['profiles'=>[[
                        'company_id'=>1,'status'=>$this->profileActive?'active':'inactive',
                        'fiscal_parameters'=>[
                            'sandbox_mode'=>$this->sandbox?'1':'0',
                            'dps_series'=>'00001',
                        ],
                        'version'=>$this->profileVersion,
                    ]], 'eligible_companies'=>[]];
                }
                if ($path==='/nfse/settings/email-footer') {
                    return ['data'=>[
                        'footer_message'=>'Initial synthetic company footer',
                        'footer_url'=>'https://footer.example.test/previous',
                    ]];
                }
                if ($path==='/nfse/invoices/7/email-preferences') {
                    return ['data'=>[
                        'configured'=>false,'subject'=>'','body'=>'',
                    ]];
                }
                if ($path==='/nfse/settings/category-issuance') {
                    return ['data'=>[[
                        'id'=>9,'company_id'=>1,'name'=>'Synthetic income',
                        'type'=>'income','enabled'=>true,
                        'can_issue'=>$this->categoryCanIssue,
                        'policy'=>['id'=>$this->categoryVersion,'mode'=>$this->categoryMode],
                    ]]];
                }
                if ($path==='/nfse/invoices/7/fiscal-decision') {
                    $current=$this->fiscalDecisionVersion===0?null:[
                        'id'=>$this->fiscalDecisionVersion,'company_id'=>1,
                        'invoice_id'=>7,'decision'=>'do_not_issue',
                        'reason'=>'operational_review',
                    ];
                    return ['data'=>[
                        'current'=>$current,
                        'effective'=>$current,
                        'history'=>$current===null?[]:[$current],
                    ]];
                }
                if ($path==='/nfse/invoices/7/emission-attempts') {
                    return ['data'=>[]];
                }
                if ($path==='/nfse/adn/review-options') {
                    return ['data'=>[
                        'can_import'=>$this->adnCanImport,
                        'vendors'=>[['id'=>$this->adnVendorId,'name'=>'Synthetic vendor']],
                        'categories'=>[['id'=>$this->adnCategoryId,'name'=>'Synthetic expense']],
                        'items'=>[['id'=>$this->adnItemId,'name'=>'Synthetic service']],
                    ]];
                }
                if ($path==='/nfse/adn/documents/11/preview') {
                    return ['data'=>[
                        'document_id'=>$this->adnDocumentId,
                        'fiscal_role'=>'received',
                        'review_status'=>$this->adnReviewStatus,
                        'company_id'=>$this->adnCompany,
                        'accounting_preview'=>['gross_value'=>'100.00'],
                        'ibs_cbs_credit_candidate'=>null,
                    ]];
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
                if ($path==='/documents/7' || $path==='/documents/8') {
                    return ['data'=>[
                        'id'=>(int)basename($path),
                        'company_id'=>$this->invoiceCompany,'type'=>'invoice',
                        'contact_id'=>12,'category_id'=>9,
                    ]];
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
                        'profile_active'=>true,'authority_present'=>true,'local_present'=>true,
                        'last_number'=>'900000000000007',
                        'candidate_number'=>$this->dpsCandidate,
                        'reasons'=>$this->ready?[]:['series_inactive'],
                        'external_reconciliation'=>'not_proven_by_this_endpoint',
                    ]];
                }
                throw new RuntimeException('Unreviewed synthetic read: '.$path);
            }

            public function getDiscovered(
                string $email,string $password,array $approved,array $query
            ): array {
                if (($approved['path']??null)!=='/nfse/invoices/7/fiscal-decision'
                    || ($approved['company_id']??null)!==1
                    || ($query['company_id']??null)!==1) {
                    throw new RuntimeException('Unsafe synthetic discovered read');
                }
                return $this->get($email,$password,$approved['path'],$query);
            }

            public function mutate(
                string $email,string $password,string $method,string $path,int $companyId,array $body,?string $recordType=null
            ): array {
                ++$this->writes;
                $this->lastWrite=compact('method','path','companyId','body','recordType');
                return ['http_status'=>$method==='DELETE'?204:200];
            }
        };
        $this->approval=new ApprovalService($this->store,$this->client,$config->issuer);
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

    public function testAuxiliaryNfseMutationsAreTypedAndRequireHumanApproval(): void
    {
        $footer=[
            'footer_message'=>'Synthetic company footer',
            'footer_url'=>'https://footer.example.test/current',
        ];
        $proposal=$this->prepare('api.nfse.settings.email-footer.update','PATCH',[],$footer);
        self::assertSame(0,$this->client->writes);
        self::assertSame('completed',$this->approve($proposal)['status']);
        self::assertSame('/nfse/settings/email-footer',$this->client->lastWrite['path']);
        self::assertSame('PATCH',$this->client->lastWrite['method']);

        $template=['subject'=>'Synthetic email subject','body'=>'<p>Synthetic email text</p>'];
        $proposal=$this->prepare('api.nfse.invoices.email-preferences.store','POST',
            ['invoice'=>7],$template);
        self::assertSame(1,$this->client->writes);
        self::assertSame('completed',$this->approve($proposal)['status']);
        self::assertSame('/nfse/invoices/7/email-preferences',$this->client->lastWrite['path']);

        $proposal=$this->prepare('api.nfse.invoices.refresh-all','POST',
            [],['confirmed'=>true]);
        self::assertSame(2,$this->client->writes);
        self::assertSame('completed',$this->approve($proposal)['status']);
        self::assertSame('/nfse/invoices/refresh-all',$this->client->lastWrite['path']);
        self::assertSame(3,$this->client->writes);

        foreach ([
            ['api.nfse.settings.email-footer.update','PATCH',[],[
                'footer_message'=>'Synthetic','footer_url'=>'https://user:pass@footer.example.test',
            ]],
            ['api.nfse.settings.email-footer.update','PATCH',[],[
                'footer_message'=>'Synthetic','footer_url'=>'http://insecure.example.test',
            ]],
            ['api.nfse.invoices.email-preferences.store','POST',['invoice'=>7],[
                'subject'=>'','body'=>'<p>Empty subject</p>',
            ]],
            ['api.nfse.invoices.email-preferences.store','POST',['invoice'=>7],[
                'subject'=>'Synthetic','body'=>'<p>Hello</p>','company_id'=>2,
            ]],
            ['api.nfse.invoices.refresh-all','POST',[],['confirmed'=>false]],
            ['api.nfse.invoices.refresh-all','POST',[],['confirmed'=>true,'force_emit'=>true]],
        ] as [$route,$method,$params,$body]) {
            try {
                $this->prepare($route,$method,$params,$body);
                self::fail('Unsafe auxiliary NFS-e mutation was prepared');
            } catch (RuntimeException) {
                self::assertSame(3,$this->client->writes);
            }
        }
    }

    public function testCategoryFiscalPolicyRequiresReviewedCategoryAndFreshOneUseApproval(): void
    {
        $body=[
            'category_id'=>9,'mode'=>'issue',
            'justification'=>'Synthetic approval of invoice service category.',
        ];
        $proposal=$this->prepare('api.nfse.settings.category-issuance.store','POST',[],$body);
        self::assertSame(0,$this->client->writes);
        self::assertSame('completed',$this->approve($proposal)['status']);
        self::assertSame('/nfse/settings/category-issuance',$this->client->lastWrite['path']);
        self::assertSame($body,$this->client->lastWrite['body']);

        foreach ([
            [...$body,'category_id'=>99],
            [...$body,'justification'=>'too short'],
            [...$body,'mode'=>'automatic'],
            [...$body,'company_id'=>2],
        ] as $invalid) {
            try {
                $this->prepare('api.nfse.settings.category-issuance.store','POST',[],$invalid);
                self::fail('Unsafe fiscal category proposal accepted');
            } catch (RuntimeException) {
                self::assertSame(1,$this->client->writes);
            }
        }

        $this->client->categoryCanIssue=false;
        try {
            $this->prepare('api.nfse.settings.category-issuance.store','POST',[],$body);
            self::fail('Disabled category was allowed to authorize fiscal issuance');
        } catch (RuntimeException) {
            self::assertSame(1,$this->client->writes);
        }
        $this->client->categoryCanIssue=true;

        $proposal=$this->prepare('api.nfse.settings.category-issuance.store','POST',[],$body);
        $this->client->categoryVersion=2;
        try {
            $this->approve($proposal);
            self::fail('Changed fiscal category policy was approved with stale snapshot');
        } catch (RuntimeException) {
            self::assertSame(1,$this->client->writes);
        }
    }

    public function testInvoiceFiscalDecisionRequiresCurrentInvoiceEvidenceAndOneUseApproval(): void
    {
        $body=[
            'decision'=>'do_not_issue',
            'reason'=>'operational_review',
            'justification'=>'Synthetic invoice review with supporting evidence.',
            'evidence_reference'=>'Synthetic memorandum 2026-11',
        ];
        $proposal=$this->prepare('api.nfse.invoices.fiscal-decision.store','POST',
            ['invoice'=>7],$body);
        self::assertSame(0,$this->client->writes);
        self::assertSame('completed',$this->approve($proposal)['status']);
        self::assertSame('/nfse/invoices/7/fiscal-decision',$this->client->lastWrite['path']);

        foreach ([
            [...$body,'decision'=>'exempt'],
            [...$body,'decision'=>'not_applicable','evidence_reference'=>null],
            [...$body,'decision'=>'reversed','reason'=>'other'],
            [...$body,'justification'=>'too short'],
            [...$body,'pfx_password'=>'secret'],
        ] as $invalid) {
            try {
                $this->prepare('api.nfse.invoices.fiscal-decision.store','POST',
                    ['invoice'=>7],$invalid);
                self::fail('Unsafe invoice fiscal decision was prepared');
            } catch (RuntimeException) {
                self::assertSame(1,$this->client->writes);
            }
        }

        $this->client->invoiceCompany=2;
        try {
            $this->prepare('api.nfse.invoices.fiscal-decision.store','POST',
                ['invoice'=>7],$body);
            self::fail('Foreign invoice fiscal decision accepted');
        } catch (RuntimeException) {
            self::assertSame(1,$this->client->writes);
        }
        $this->client->invoiceCompany=1;
        $this->client->categoryCanIssue=false;
        try {
            $this->prepare('api.nfse.invoices.fiscal-decision.store','POST',
                ['invoice'=>7],[...$body,'decision'=>'issue']);
            self::fail('Ineligible invoice was authorized to issue');
        } catch (RuntimeException) {
            self::assertSame(1,$this->client->writes);
        }
        $this->client->categoryCanIssue=true;

        $proposal=$this->prepare('api.nfse.invoices.fiscal-decision.store','POST',
            ['invoice'=>7],$body);
        $this->client->fiscalDecisionVersion=2;
        try {
            $this->approve($proposal);
            self::fail('Changed fiscal decision was accepted after approval preparation');
        } catch (RuntimeException) {
            self::assertSame(1,$this->client->writes);
        }
    }

    public function testFiscalDecisionRefusesMissingNativeReadDiscoveryRatherThanBypassingAcl(): void
    {
        $this->client->decisionDiscoveryAvailable=false;
        try {
            $this->prepare('api.nfse.invoices.fiscal-decision.store','POST',
                ['invoice'=>7],[
                    'decision'=>'do_not_issue',
                    'reason'=>'operational_review',
                    'justification'=>'Synthetic accounting note for fiscal decision',
                ]);
            self::fail('Undiscovered fiscal decision was prepared for execution');
        } catch (RuntimeException) {
            self::assertSame(0,$this->client->writes);
        }
    }

    public function testAdnIgnoreRequiresCompanyOwnedPendingDocumentAndFreshApproval(): void
    {
        $route='api.nfse.adn.documents.ignore';
        $body=['confirmed'=>true];
        $proposal=$this->prepare($route,'POST',['document'=>11],$body);
        self::assertSame(0,$this->client->writes);
        self::assertSame('completed',$this->approve($proposal)['status']);
        self::assertSame('/nfse/adn/documents/11/ignore',$this->client->lastWrite['path']);

        foreach ([['confirmed'=>false],['confirmed'=>true,'force_import'=>true]] as $invalid) {
            try {
                $this->prepare($route,'POST',['document'=>11],$invalid);
                self::fail('Invalid ADN ignore request accepted');
            } catch (RuntimeException) {
                self::assertSame(1,$this->client->writes);
            }
        }

        $this->client->adnCompany=2;
        try {
            $this->prepare($route,'POST',['document'=>11],$body);
            self::fail('Foreign ADN record can be dismissed');
        } catch (RuntimeException) {
            self::assertSame(1,$this->client->writes);
        }
        $this->client->adnCompany=1;
        $proposal=$this->prepare($route,'POST',['document'=>11],$body);
        $this->client->adnReviewStatus='imported';
        try {
            $this->approve($proposal);
            self::fail('Imported ADN record can be dismissed');
        } catch (RuntimeException) {
            self::assertSame(1,$this->client->writes);
        }
    }

    public function testAdnImportCreatesOnlyReviewedCompanyDraftAfterHumanApproval(): void
    {
        $route='api.nfse.adn.documents.import';
        $body=[
            'contact_id'=>19,'category_id'=>31,'item_id'=>41,
            'issued_at'=>'2026-11-01','due_at'=>'2026-11-30',
            'confirmed'=>true,
        ];
        $proposal=$this->prepare($route,'POST',['document'=>11],$body);
        self::assertSame(0,$this->client->writes);
        self::assertSame('completed',$this->approve($proposal)['status']);
        self::assertSame('/nfse/adn/documents/11/import',$this->client->lastWrite['path']);
        self::assertSame($body,$this->client->lastWrite['body']);

        foreach ([
            [...$body,'contact_id'=>99],
            [...$body,'category_id'=>99],
            [...$body,'item_id'=>99],
            [...$body,'contact_id'=>'19'],
            [...$body,'issued_at'=>'2026-02-30'],
            [...$body,'due_at'=>'2026-10-01'],
            [...$body,'confirmed'=>false],
            [...$body,'create_vendor'=>true],
            [...$body,'status'=>'paid'],
        ] as $invalid) {
            try {
                $this->prepare($route,'POST',['document'=>11],$invalid);
                self::fail('Unreviewed or unsafe imported bill mapping was accepted');
            } catch (RuntimeException) {
                self::assertSame(1,$this->client->writes);
            }
        }

        $this->client->adnCanImport=false;
        try {
            $this->prepare($route,'POST',['document'=>11],$body);
            self::fail('Operator without invoice creation permission imported an ADN bill');
        } catch (RuntimeException) {
            self::assertSame(1,$this->client->writes);
        }
        $this->client->adnCanImport=true;

        $proposal=$this->prepare($route,'POST',['document'=>11],$body);
        $this->client->adnItemId=42;
        try {
            $this->approve($proposal);
            self::fail('Changed ADN accounting item was accepted after approval');
        } catch (RuntimeException) {
            self::assertSame(1,$this->client->writes);
        }

        $this->client->adnItemId=41;
        $proposal=$this->prepare($route,'POST',['document'=>11],$body);
        $this->client->adnReviewStatus='imported';
        try {
            $this->approve($proposal);
            self::fail('Already imported ADN document was imported a second time');
        } catch (RuntimeException) {
            self::assertSame(1,$this->client->writes);
        }
    }

    public function testNonTransmittingAdministrativeActionsWorkBeforeFiscalIssuerIsActivated(): void
    {
        $this->client->profileActive=false;

        $footer=[
            'footer_message'=>'Synthetic pre-activation footer',
            'footer_url'=>'https://footer.example.test/activation',
        ];
        $proposal=$this->prepare('api.nfse.settings.email-footer.update','PATCH',[],$footer);
        self::assertSame('completed',$this->approve($proposal)['status']);

        $policy=[
            'category_id'=>9,'mode'=>'do_not_issue',
            'justification'=>'Synthetic policy before fiscal registration',
        ];
        $proposal=$this->prepare('api.nfse.settings.category-issuance.store','POST',[],$policy);
        self::assertSame('completed',$this->approve($proposal)['status']);

        $proposal=$this->prepare('api.nfse.adn.documents.ignore','POST',
            ['document'=>11],['confirmed'=>true]);
        self::assertSame('completed',$this->approve($proposal)['status']);

        self::assertSame(3,$this->client->writes);

        try {
            $this->prepare('api.nfse.dps.advance','POST',[],[
                'expected_next_number'=>'900000000000008',
                'next_number'=>'900000000000100',
                'reason'=>'Synthetic correction cannot proceed without active issuer',
                'confirmed'=>true,
            ]);
            self::fail('Inactive issuer was allowed a fiscal numbering mutation');
        } catch (RuntimeException) {
            self::assertSame(3,$this->client->writes);
        }
    }

    public function testDpsAdvanceRequiresApprovedExactCounterAndReason(): void
    {
        $body=[
            'expected_next_number'=>'900000000000008',
            'next_number'=>'900000000000100',
            'reason'=>'Documented synthetic series correction for test',
            'confirmed'=>true,
        ];
        $plan=$this->prepare('api.nfse.dps.advance','POST',[],$body);
        self::assertSame('awaiting_human_approval',$plan['status']);
        self::assertSame(0,$this->client->writes);
        self::assertSame('completed',$this->approve($plan)['status']);
        self::assertSame(1,$this->client->writes);
        self::assertSame('/nfse/dps/advance',$this->client->lastWrite['path']);
        self::assertSame($body,$this->client->lastWrite['body']);
    }

    public function testDpsAdvanceRejectsStaleSeriesAndMalformedJustifications(): void
    {
        $body=[
            'expected_next_number'=>'900000000000008',
            'next_number'=>'900000000000100',
            'reason'=>'Documented synthetic sequence conflict',
            'confirmed'=>true,
        ];
        foreach ([
            ['expected_next_number'=>'900000000000007'],
            ['next_number'=>'900000000000008'],
            ['next_number'=>'9000000000000001'],
            ['reason'=>'too short'],
            ['reason'=>"unexpected\nnewline"],
            ['confirmed'=>false],
            ['company_id'=>2],
            ['bao_token'=>'secret'],
        ] as $change) {
            try {
                $this->prepare('api.nfse.dps.advance','POST',[],[...$body,...$change]);
                self::fail('Unsafe DPS advancement proposal was prepared');
            } catch (RuntimeException) {
                self::assertSame(0,$this->client->writes);
            }
        }
        $plan=$this->prepare('api.nfse.dps.advance','POST',[],$body);
        $this->client->dpsCandidate='900000000000009';
        try {
            $this->approve($plan);
            self::fail('DPS number drift was not detected');
        } catch (RuntimeException) {
            self::assertSame(0,$this->client->writes);
            self::assertSame('outcome_unconfirmed',
                $this->approval->status($this->subject,$plan['request_id'])['status']);
        }
    }

    public function testFiscalReceiptRefreshUsesCurrentReceiptWithApproval(): void
    {
        $this->client->receiptId=12;
        $this->client->sandbox=false;
        $body=['nfse_receipt_id'=>12,'nfse_force_ajax'=>true];
        $plan=$this->prepare('api.nfse.invoices.refresh','POST',['invoice'=>7],$body);
        self::assertSame(0,$this->client->writes);
        self::assertSame('completed',$this->approve($plan)['status']);
        self::assertSame('/nfse/invoices/7/refresh',$this->client->lastWrite['path']);
        self::assertSame($body,$this->client->lastWrite['body']);

        $this->client->receiptId=13;
        foreach ([
            ['nfse_receipt_id'=>12,'nfse_force_ajax'=>true],
            ['nfse_receipt_id'=>13,'nfse_force_ajax'=>false],
            ['nfse_receipt_id'=>13,'nfse_force_ajax'=>true,'pfx_password'=>'secret'],
        ] as $case) {
            try {
                $this->prepare('api.nfse.invoices.refresh','POST',['invoice'=>7],$case);
                self::fail('Invalid fiscal refresh was prepared');
            } catch (RuntimeException) {
                self::assertSame(1,$this->client->writes);
            }
        }
    }

    public function testFiscalRefreshRejectsStaleReceiptAndForeignCompany(): void
    {
        $this->client->receiptId=12;
        $body=['nfse_receipt_id'=>12,'nfse_force_ajax'=>true];
        $plan=$this->prepare('api.nfse.invoices.refresh','POST',['invoice'=>7],$body);
        $this->client->receiptId=13;
        try {
            $this->approve($plan);
            self::fail('Stale fiscal status was accepted');
        } catch (RuntimeException) {
            self::assertSame(0,$this->client->writes);
        }
        $this->client->receiptId=12;
        $this->client->invoiceCompany=2;
        try {
            $this->prepare('api.nfse.invoices.refresh','POST',['invoice'=>7],$body);
            self::fail('Foreign invoice refresh was prepared');
        } catch (RuntimeException) {
            self::assertSame(0,$this->client->writes);
        }
    }

    public function testBulkFiscalDispatchRequiresSandboxAndPersonalApproval(): void
    {
        $body=['invoice_ids'=>[7,8]];
        $plan=$this->prepare('api.nfse.bulk.dispatch','POST',[],$body);
        self::assertSame('awaiting_human_approval',$plan['status']);
        self::assertSame(0,$this->client->writes);
        self::assertSame('completed',$this->approve($plan)['status']);
        self::assertSame('/nfse/bulk/dispatch',$this->client->lastWrite['path']);
        self::assertSame($body,$this->client->lastWrite['body']);
    }

    public function testBulkDispatchRejectsProductionForeignAndChangedDps(): void
    {
        $body=['invoice_ids'=>[7,8]];
        $this->client->sandbox=false;
        try {
            $this->prepare('api.nfse.bulk.dispatch','POST',[],$body);
            self::fail('Production fiscal bulk dispatch was accepted');
        } catch (RuntimeException) {
            self::assertSame(0,$this->client->writes);
        }
        $this->client->sandbox=true;
        $this->client->invoiceCompany=2;
        try {
            $this->prepare('api.nfse.bulk.dispatch','POST',[],$body);
            self::fail('Foreign invoice was accepted');
        } catch (RuntimeException) {
            self::assertSame(0,$this->client->writes);
        }
        $this->client->invoiceCompany=1;
        $plan=$this->prepare('api.nfse.bulk.dispatch','POST',[],$body);
        $this->client->dpsCandidate='900000000000009';
        try {
            $this->approve($plan);
            self::fail('Changed DPS was not noticed before dispatch');
        } catch (RuntimeException) {
            self::assertSame(0,$this->client->writes);
        }
        foreach ([
            ['invoice_ids'=>[7,7]],
            ['invoice_ids'=>['7']],
            ['invoice_ids'=>[0]],
            ['invoice_ids'=>range(1,101)],
            ['invoice_ids'=>[7],'override_company_id'=>2],
        ] as $invalid) {
            try {
                $this->prepare('api.nfse.bulk.dispatch','POST',[],$invalid);
                self::fail('Invalid bulk dispatch accepted');
            } catch (RuntimeException) {
                self::assertSame(0,$this->client->writes);
            }
        }
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
        self::assertSame(0,$this->client->writes);
        self::assertSame('completed',$this->approve($proposal)['status']);
        self::assertSame(1,$this->client->writes);
        self::assertSame('/nfse/settings/company-profiles/1',$this->client->lastWrite['path']);
        self::assertSame(1,$this->client->lastWrite['companyId']);
        self::assertArrayNotHasKey('company_id',$this->client->lastWrite['body']);
        self::assertSame('manual',$this->client->lastWrite['body']['fiscal_parameters']['emission_policy']);
    }

    public function testProductionAndAutomaticPolicyChangesRequireASeparateConfirmedProfileWrite(): void
    {
        $proposal=$this->prepare(
            'api.nfse.settings.company-profiles.update','PATCH',['companyId'=>1],
            ['fiscal_parameters'=>['sandbox_mode'=>false,'emission_policy'=>'emit_on_send']],
        );
        self::assertSame('awaiting_human_approval',$proposal['status']);
        self::assertSame(0,$this->client->writes);
        self::assertSame('completed',$this->approve($proposal)['status']);
        self::assertSame(1,$this->client->writes);
        self::assertFalse($this->client->lastWrite['body']['fiscal_parameters']['sandbox_mode']);
        self::assertSame('emit_on_send',
            $this->client->lastWrite['body']['fiscal_parameters']['emission_policy']);
    }

    public function testProductionIssueRequiresMatchingAuthorityAndNewApproval(): void
    {
        $this->client->sandbox=false;
        try {
            $this->prepare('api.nfse.invoices.emit','POST',['invoice'=>7],[
                'nfse_force_ajax'=>true,
            ]);
            self::fail('Restricted series must not authorize production');
        } catch (RuntimeException) {
            self::assertSame(0,$this->client->writes);
        }
        $this->client->dpsEnvironment=1;
        $proposal=$this->prepare('api.nfse.invoices.emit','POST',['invoice'=>7],[
            'nfse_force_ajax'=>true,
        ]);
        self::assertSame(0,$this->client->writes);
        self::assertSame('awaiting_human_approval',$proposal['status']);
        self::assertSame('completed',$this->approve($proposal)['status']);
        self::assertSame(1,$this->client->writes);
    }

    public function testProductionIssueRejectsForeignOrDifferentSeriesDpsAuthority(): void
    {
        $this->client->sandbox=false;
        $this->client->dpsEnvironment=1;
        foreach ([
            ['dpsCompany'=>2,'dpsSeries'=>'00001'],
            ['dpsCompany'=>1,'dpsSeries'=>'00002'],
        ] as $invalid) {
            $this->client->dpsCompany=$invalid['dpsCompany'];
            $this->client->dpsSeries=$invalid['dpsSeries'];
            try {
                $this->prepare('api.nfse.invoices.emit','POST',['invoice'=>7],[
                    'nfse_force_ajax'=>true,
                ]);
                self::fail('Unrelated DPS authority must not permit an issuance');
            } catch (RuntimeException) {
                self::assertSame(0,$this->client->writes);
            }
        }
    }

    public function testNativeHostConfirmedProductionEmissionIsSingleUse(): void
    {
        $this->client->sandbox=false;
        $this->client->dpsEnvironment=1;
        $proposal=$this->approval->prepare(
            $this->subject,$this->store->load($this->subject),1,[
                'route'=>'api.nfse.invoices.emit','method'=>'POST',
                'route_params'=>['invoice'=>7],
                'body'=>['nfse_force_ajax'=>true],
                'approval_transport'=>'chatgpt',
            ],
        );
        self::assertSame('awaiting_host_confirmation',$proposal['status']);
        self::assertSame(0,$this->client->writes);
        self::assertSame('completed',$this->approval->executeNative(
            $this->subject,1,$proposal['request_id'],'api.nfse.invoices.emit','POST','',
        )['status']);
        self::assertSame(1,$this->client->writes);
        $this->expectException(RuntimeException::class);
        $this->approval->executeNative(
            $this->subject,1,$proposal['request_id'],'api.nfse.invoices.emit','POST','',
        );
    }

    public function testProductionEnvironmentChangeRevokesPreparedFiscalWrite(): void
    {
        $this->client->sandbox=false;
        $this->client->dpsEnvironment=1;
        $proposal=$this->prepare('api.nfse.invoices.emit','POST',['invoice'=>7],[
            'nfse_force_ajax'=>true,
        ]);
        $this->client->sandbox=true;
        $this->client->dpsEnvironment=2;
        try {
            $this->approve($proposal);
            self::fail('A fiscal environment change must invalidate a proposed POST');
        } catch (RuntimeException) {
            self::assertSame(0,$this->client->writes);
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
        $this->client->profileVersion++;
        $this->expectException(RuntimeException::class);
        try {
            $this->approve($proposal);
        } finally {
            self::assertSame(0,$this->client->writes);
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
                self::assertSame(0,$this->client->writes);
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
        self::assertArrayNotHasKey('company_id',$this->client->lastWrite['body']);
        try {
            $this->prepare('api.nfse.settings.tax-associations.store','POST',[],
                [...$body,'tax_id'=>8]);
            self::fail('Unknown tax accepted');
        } catch (RuntimeException) {
            self::assertSame(1,$this->client->writes);
        }
        try {
            $this->prepare('api.nfse.settings.tax-associations.store','POST',[],
                [...$body,'acknowledge'=>false]);
            self::fail('Unconfirmed fiscal association accepted');
        } catch (RuntimeException) {
            self::assertSame(1,$this->client->writes);
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
        $this->client->ready=false;
        try {
            $this->prepare('api.nfse.invoices.emit','POST',['invoice'=>7],$body);
            self::fail('Inactive DPS was accepted');
        } catch (RuntimeException) {
            self::assertSame(0,$this->client->writes);
        }
        $this->client->ready=true;
        $this->client->profileReady=false;
        try {
            $this->prepare('api.nfse.invoices.emit','POST',['invoice'=>7],$body);
            self::fail('Unready fiscal profile was accepted');
        } catch (RuntimeException) {
            self::assertSame(0,$this->client->writes);
        }
        $this->client->profileReady=true;
        $proposal=$this->prepare('api.nfse.invoices.emit','POST',['invoice'=>7],$body);
        self::assertSame('awaiting_human_approval',$proposal['status']);
        self::assertSame(0,$this->client->writes);
        self::assertSame('completed',$this->approve($proposal)['status']);
        self::assertSame('/nfse/invoices/7/emit',$this->client->lastWrite['path']);
    }

    public function testReceiptCreatedAfterApprovalPreparationBlocksEmissionReplay(): void
    {
        $proposal=$this->prepare(
            'api.nfse.invoices.emit','POST',['invoice'=>7],['nfse_force_ajax'=>true],
        );
        $this->client->receiptId=12;
        $this->expectException(RuntimeException::class);
        try {
            $this->approve($proposal);
        } finally {
            self::assertSame(0,$this->client->writes);
        }
    }

    public function testFiscalCancellationRequiresCurrentReceiptAndCancelPhrase(): void
    {
        $this->client->receiptId=12;
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
            self::assertSame(0,$this->client->writes);
        }
        self::assertSame('completed',$this->approval->decide(
            $view['request_id'],$view['csrf'],$view['cookie'],
            'approve','member@example.invalid','correct-password','CANCELAR',
        )['status']);
        self::assertSame('DELETE',$this->client->lastWrite['method']);
        self::assertSame('/nfse/invoices/7',$this->client->lastWrite['path']);
        self::assertSame(12,$this->client->lastWrite['body']['nfse_receipt_id']);
    }

    public function testReemissionRequiresCancelledCurrentReceiptAndBrowserApproval(): void
    {
        $this->client->receiptId=12;
        $this->client->receiptState='cancelled';
        $proposal=$this->prepare('api.nfse.invoices.reemit','POST',['invoice'=>7],[
            'nfse_receipt_id'=>12,'nfse_force_ajax'=>true,
        ]);
        self::assertSame('awaiting_human_approval',$proposal['status']);
        self::assertSame(0,$this->client->writes);
        self::assertSame('completed',$this->approve($proposal)['status']);
        self::assertSame(1,$this->client->writes);
        self::assertSame('POST',$this->client->lastWrite['method']);
        self::assertSame('/nfse/invoices/7/reemit',$this->client->lastWrite['path']);
        self::assertSame(12,$this->client->lastWrite['body']['nfse_receipt_id']);
    }

    public function testReemissionRejectsActiveReceiptAndUnreviewedFields(): void
    {
        $this->client->receiptId=12;
        $body=['nfse_receipt_id'=>12,'nfse_force_ajax'=>true];
        try {
            $this->prepare('api.nfse.invoices.reemit','POST',['invoice'=>7],$body);
            self::fail('Active fiscal receipt was allowed to reemit');
        } catch (RuntimeException) {
            self::assertSame(0,$this->client->writes);
        }
        $this->client->receiptState='cancelled';
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
                self::assertSame(0,$this->client->writes);
            }
        }
    }

    public function testReemissionDetectsFiscalStateChangeAfterApprovalPreparation(): void
    {
        $this->client->receiptId=12;
        $this->client->receiptState='cancelled';
        $proposal=$this->prepare('api.nfse.invoices.reemit','POST',['invoice'=>7],[
            'nfse_receipt_id'=>12,'nfse_force_ajax'=>true,
        ]);
        $this->client->receiptState='emitted';
        $this->expectException(RuntimeException::class);
        try {
            $this->approve($proposal);
        } finally {
            self::assertSame(0,$this->client->writes);
            self::assertSame('outcome_unconfirmed',
                $this->approval->status($this->subject,$proposal['request_id'])['status']);
        }
    }

    public function testReemissionRejectsMismatchedEnvironmentOrUnreadyDps(): void
    {
        $this->client->receiptId=12;
        $this->client->receiptState='cancelled';
        $body=['nfse_receipt_id'=>12,'nfse_force_ajax'=>true];
        $this->client->sandbox=false;
        try {
            $this->prepare('api.nfse.invoices.reemit','POST',['invoice'=>7],$body);
            self::fail('Restricted authority must not authorize production reemission');
        } catch (RuntimeException) {
            self::assertSame(0,$this->client->writes);
        }
        $this->client->sandbox=true;
        $this->client->ready=false;
        $this->expectException(RuntimeException::class);
        $this->prepare('api.nfse.invoices.reemit','POST',['invoice'=>7],$body);
    }

    public function testSubstitutionRequiresCurrentEmittedReceiptAndIndependentApproval(): void
    {
        $this->client->receiptId=12;
        $proposal=$this->prepare('api.nfse.invoices.substitute','POST',['invoice'=>7],[
            'nfse_substitution_receipt_id'=>12,
            'nfse_substitution_reason'=>'05',
            'nfse_substitution_description'=>'Synthetic correction in restricted fiscal environment',
            'nfse_force_ajax'=>true,
        ]);
        self::assertSame('awaiting_human_approval',$proposal['status']);
        self::assertSame(0,$this->client->writes);
        self::assertSame('completed',$this->approve($proposal)['status']);
        self::assertSame(1,$this->client->writes);
        self::assertSame('POST',$this->client->lastWrite['method']);
        self::assertSame('/nfse/invoices/7/substitute',$this->client->lastWrite['path']);
        self::assertSame(12,$this->client->lastWrite['body']['nfse_substitution_receipt_id']);
    }

    public function testSubstitutionRejectsWrongReceiptStatusAndUnexpectedFields(): void
    {
        $this->client->receiptId=12;
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
                self::assertSame(0,$this->client->writes);
            }
        }
        foreach (['cancelled','substituted','processing'] as $state) {
            $this->client->receiptState=$state;
            try {
                $this->prepare('api.nfse.invoices.substitute','POST',['invoice'=>7],$base);
                self::fail('Non-emitted receipt was accepted for substitution');
            } catch (RuntimeException) {
                self::assertSame(0,$this->client->writes);
            }
        }
    }

    public function testSubstitutionIsBlockedWhenReceiptChangesBeforeBrowserApproval(): void
    {
        $this->client->receiptId=12;
        $proposal=$this->prepare('api.nfse.invoices.substitute','POST',['invoice'=>7],[
            'nfse_substitution_receipt_id'=>12,
            'nfse_substitution_reason'=>'05',
            'nfse_substitution_description'=>'Synthetic correction under independent approval',
            'nfse_force_ajax'=>true,
        ]);
        $this->client->receiptId=13;
        $this->expectException(RuntimeException::class);
        try {
            $this->approve($proposal);
        } finally {
            self::assertSame(0,$this->client->writes);
            self::assertSame('outcome_unconfirmed',
                $this->approval->status($this->subject,$proposal['request_id'])['status']);
        }
    }

    public function testSubstitutionRequiresMatchingEnvironmentAndReadyDps(): void
    {
        $this->client->receiptId=12;
        $body=[
            'nfse_substitution_receipt_id'=>12,
            'nfse_substitution_reason'=>'05',
            'nfse_substitution_description'=>'Synthetic correction for fiscal validation',
            'nfse_force_ajax'=>true,
        ];
        $this->client->sandbox=false;
        try {
            $this->prepare('api.nfse.invoices.substitute','POST',['invoice'=>7],$body);
            self::fail('Restricted authority must not authorize production substitution');
        } catch (RuntimeException) {
            self::assertSame(0,$this->client->writes);
        }
        $this->client->sandbox=true;
        $this->client->ready=false;
        try {
            $this->prepare('api.nfse.invoices.substitute','POST',['invoice'=>7],$body);
            self::fail('Unready DPS was allowed');
        } catch (RuntimeException) {
            self::assertSame(0,$this->client->writes);
        }
        $this->client->ready=true;
        $this->client->profileReady=false;
        $this->expectException(RuntimeException::class);
        $this->prepare('api.nfse.invoices.substitute','POST',['invoice'=>7],$body);
    }

    public function testFiscalWritesRejectMismatchedEnvironmentAndForeignInvoices(): void
    {
        $this->client->sandbox=false;
        try {
            $this->prepare('api.nfse.invoices.emit','POST',['invoice'=>7],[
                'nfse_force_ajax'=>true,
            ]);
            self::fail('Restricted authority must not authorize production emission');
        } catch (RuntimeException) {
            self::assertSame(0,$this->client->writes);
        }
        $this->client->sandbox=true;
        $foreignCompany=2;
        $this->client->invoiceCompany=$foreignCompany;
        $this->expectException(RuntimeException::class);
        $this->prepare('api.nfse.invoices.emit','POST',['invoice'=>7],[
            'nfse_force_ajax'=>true,
        ]);
    }

    public function testProductionCancellationPreservesCurrentReceiptAndConfirmationPhrase(): void
    {
        $this->client->sandbox=false;
        $this->client->receiptId=12;
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
            self::assertSame(0,$this->client->writes);
        }
        self::assertSame('completed',$this->approval->decide(
            $view['request_id'],$view['csrf'],$view['cookie'],
            'approve','member@example.invalid','correct-password','CANCELAR',
        )['status']);
        self::assertSame(1,$this->client->writes);
    }

    public function testProductionReissueAndSubstitutionRequireCurrentReceiptAndDps(): void
    {
        $this->client->sandbox=false;
        $this->client->dpsEnvironment=1;
        $this->client->receiptId=12;
        $substitute=$this->prepare('api.nfse.invoices.substitute','POST',['invoice'=>7],[
            'nfse_substitution_receipt_id'=>12,
            'nfse_substitution_reason'=>'05',
            'nfse_substitution_description'=>'Synthetic correction authorized by operator',
            'nfse_force_ajax'=>true,
        ]);
        self::assertSame('completed',$this->approve($substitute)['status']);
        $this->client->receiptState='cancelled';
        $reissue=$this->prepare('api.nfse.invoices.reemit','POST',['invoice'=>7],[
            'nfse_receipt_id'=>12,'nfse_force_ajax'=>true,
        ]);
        self::assertSame('completed',$this->approve($reissue)['status']);
        self::assertSame(2,$this->client->writes);
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
            self::assertSame(0,$this->client->writes);
        }
    }

    public function testFiscalEventReadIsExplicitlyOnlyCalendarPreview(): void
    {
        $routes=array_column((new NfseReadCatalog())->list(),'route');
        self::assertContains('api.nfse.invoices.fiscal-assessment',$routes);
        $response=(new NfseReadService($this->client))->read(
            $this->store->load($this->subject),1,[
                'route'=>'api.nfse.invoices.fiscal-assessment',
                'route_params'=>['invoice'=>7],
            ],
        )['response'];
        self::assertSame('rtc_calendar_preview',$response['scope']);
        self::assertFalse($response['final_dps_checked']);
        self::assertSame($this->client->groupKey,$response['data'][0]['group_key']);
    }

    public function testFiscalEventAttestationRequiresApprovalButNotTransmissionGate(): void
    {
        putenv('MCP_NFSE_FISCAL_WRITES_ENABLED');
        $routes=array_column((new NfseWriteCatalog())->list(),null,'route');
        self::assertTrue($routes['api.nfse.invoices.fiscal-event-evidence.store']['enabled']);
        self::assertFalse($routes['api.nfse.invoices.emit']['enabled']);
        $body=[
            'fiscal_group_key'=>$this->client->groupKey,
            'taxable_event_date'=>'2026-11-01',
            'source_reference'=>'Signed synthetic service acceptance document',
            'acknowledge'=>true,
        ];
        $proposal=$this->prepare(
            'api.nfse.invoices.fiscal-event-evidence.store','POST',['invoice'=>7],$body,
        );
        self::assertSame('awaiting_human_approval',$proposal['status']);
        self::assertSame(0,$this->client->writes);
        self::assertSame('completed',$this->approve($proposal)['status']);
        self::assertSame('/nfse/invoices/7/fiscal-event-evidence',$this->client->lastWrite['path']);
        self::assertSame('POST',$this->client->lastWrite['method']);
        self::assertSame($body,$this->client->lastWrite['body']);
        self::assertArrayNotHasKey('company_id',$this->client->lastWrite['body']);
    }

    public function testFiscalEventAttestationRejectsUnprovenOrCrossCompanyData(): void
    {
        $body=[
            'fiscal_group_key'=>$this->client->groupKey,
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
                self::assertSame(0,$this->client->writes);
            }
        }
        $foreignCompany=2;
        $this->client->invoiceCompany=$foreignCompany;
        $this->expectException(RuntimeException::class);
        $this->prepare('api.nfse.invoices.fiscal-event-evidence.store','POST',
            ['invoice'=>7],$body);
    }

    public function testFiscalEventChangeDuringApprovalPreventsStaleAttestation(): void
    {
        $proposal=$this->prepare(
            'api.nfse.invoices.fiscal-event-evidence.store','POST',['invoice'=>7],[
                'fiscal_group_key'=>$this->client->groupKey,
                'taxable_event_date'=>'2026-11-01',
                'source_reference'=>'Synthetic independently reviewed event date',
                'acknowledge'=>true,
            ],
        );
        $this->client->eventDate='2026-10-31';
        $this->expectException(RuntimeException::class);
        try {
            $this->approve($proposal);
        } finally {
            self::assertSame(0,$this->client->writes);
            self::assertSame('outcome_unconfirmed',
                $this->approval->status($this->subject,$proposal['request_id'])['status']);
        }
    }

    public function testPersistedFiscalGroupKeyAcceptsPipeAndDecimalRate(): void
    {
        (new NfseWriteCatalog())->validate(
            'api.nfse.invoices.emit',[
                'nfse_force_ajax'=>true,
                'nfse_fiscal_group_key'=>$this->client->groupKey,
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
                self::assertSame(0,$this->client->writes);
            }
        }
    }
}
