<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace AkauntingMcp\Tests;

use AkauntingMcp\Api;
use AkauntingMcp\ApprovalService;
use AkauntingMcp\Config;
use AkauntingMcp\Store;
use AkauntingMcp\WriteRouteCatalog;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MutationApprovalBehaviorTest extends TestCase
{
    private string $directory;
    private Config $config;
    private Store $store;
    private string $subject;
    private Api $api;
    private ApprovalService $approval;

    protected function setUp(): void
    {
        $this->directory=sys_get_temp_dir().'/approval-synthetic-'.bin2hex(random_bytes(8));
        mkdir($this->directory,0700);
        $this->config=new Config(
            'https://accounting.example.invalid','https://accounting.example.invalid/mcp-auth',
            'https://api.openai.com/v1/mcp/tunnel_synthetic','test-client',
            'https://chatgpt.com/connector/oauth/synthetic',
            $this->directory,random_bytes(32),['localhost'],
        );
        $this->store=new Store($this->config);
        $this->subject=$this->store->saveLogin('member@example.invalid','correct-password',1);
        $this->api=new class($this->config) extends Api {
            public int $writes=0;
            public string $name='Original member';
            public array $lastWrite=[];

            public function verify(string $email,string $password,int $companyId): void
            {
                if ($email!=='member@example.invalid' || $password!=='correct-password'
                    || $companyId!==1) {
                    throw new RuntimeException('Synthetic identity not authorized');
                }
            }
            public function get(string $email,string $password,string $path,array $query=[]): array
            {
                if ($path==='/contacts/7') {
                    return ['data'=>['id'=>7,'company_id'=>1,'type'=>'vendor','name'=>$this->name]];
                }
                if ($path==='/users/4') {
                    return ['data'=>['id'=>4,'companies'=>['data'=>[['id'=>1]]]]];
                }
                if ($path==='/users/5') {
                    return ['data'=>['id'=>5,'companies'=>['data'=>[['id'=>2]]]]];
                }
                if ($path==='/companies/1') {
                    return ['data'=>['id'=>1,'name'=>'Synthetic company']];
                }
                if ($path==='/documents/2') {
                    return ['data'=>['id'=>2,'company_id'=>1,'type'=>'bill','status'=>'paid']];
                }
                if ($path==='/documents/2/transactions/10') {
                    return ['data'=>['id'=>10,'company_id'=>1,'document_id'=>2]];
                }                if ($path==='/contacts/8') {
                    return ['data'=>['id'=>8,'company_id'=>2,'type'=>'vendor','name'=>'Other company']];
                }
                throw new RuntimeException('Missing synthetic API resource');
            }
            public function mutate(
                string $email,string $password,string $method,string $path,int $companyId,array $body
            ): array {
                ++$this->writes;
                $this->lastWrite=compact('method','path','companyId','body');
                return ['http_status'=>$method==='DELETE'?204:200];
            }
        };
        $this->approval=new ApprovalService($this->store,$this->api,$this->config->issuer);
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

    private function prepare(string $method='PATCH',array $body=['name'=>'Updated synthetic member'],int $id=7): array
    {
        return $this->approval->prepare($this->subject,$this->store->load($this->subject),1,[
            'route'=>'api.contacts.'.$this->action($method),
            'method'=>$method,'route_params'=>['contact'=>$id],
            'record_type'=>'vendor','body'=>$body,
        ]);
    }

    private function action(string $method): string
    {
        return $method==='DELETE'?'destroy':'update';
    }

    public function testAChangeRequiresAuthenticatedBrowserApprovalAndCanRunOnlyOnce(): void
    {
        $proposal=$this->prepare();
        self::assertSame('awaiting_human_approval',$proposal['status']);
        self::assertStringContainsString('/mcp-auth/approve?request=',$proposal['approval_url']);
        self::assertSame(0,$this->api->writes);
        self::assertSame('pending',$this->approval->status($this->subject,$proposal['request_id'])['status']);
        $view=$this->approval->review($proposal['request_id']);
        $this->expectException(RuntimeException::class);
        try {
            $this->approval->decide($view['request_id'],$view['csrf'],$view['cookie'],
                'approve','member@example.invalid','wrong-password');
        } finally {
            self::assertSame(0,$this->api->writes);
        }
    }

    public function testHumanApprovalExecutesFrozenPlanAndRejectsReplay(): void
    {
        $proposal=$this->prepare('PATCH',['name'=>'Updated synthetic member','company_id'=>1]);
        $view=$this->approval->review($proposal['request_id']);
        $result=$this->approval->decide($view['request_id'],$view['csrf'],$view['cookie'],
            'approve','member@example.invalid','correct-password');
        self::assertSame('completed',$result['status']);
        self::assertSame(1,$this->api->writes);
        self::assertSame('PATCH',$this->api->lastWrite['method']);
        self::assertSame('/contacts/7',$this->api->lastWrite['path']);
        self::assertSame(1,$this->api->lastWrite['companyId']);
        self::assertSame('Updated synthetic member',$this->api->lastWrite['body']['name']);
        self::assertSame('completed',$this->approval->status($this->subject,$proposal['request_id'])['status']);
        try {
            $this->approval->decide($view['request_id'],$view['csrf'],$view['cookie'],
                'approve','member@example.invalid','correct-password');
            self::fail('Replay must be rejected');
        } catch (RuntimeException) {
            self::assertSame(1,$this->api->writes);
        }
    }

    public function testRejectDoesNotRequirePasswordAndNeverWrites(): void
    {
        $proposal=$this->prepare();
        $view=$this->approval->review($proposal['request_id']);
        $result=$this->approval->decide($view['request_id'],$view['csrf'],$view['cookie'],'reject');
        self::assertSame('rejected',$result['status']);
        self::assertSame(0,$this->api->writes);
        self::assertSame('rejected',$this->approval->status($this->subject,$proposal['request_id'])['status']);
    }

    public function testDifferentCompanyOrIdentityCanNeverAuthorize(): void
    {
        try {
            $this->prepare('PATCH',['name'=>'Other','company_id'=>2]);
            self::fail('Cross-company body was accepted');
        } catch (RuntimeException) {
            self::assertSame(0,$this->api->writes);
        }
        try {
            $this->prepare('PATCH',['name'=>'Other'],8);
            self::fail('Foreign company record was accepted');
        } catch (RuntimeException) {
            self::assertSame(0,$this->api->writes);
        }
        $proposal=$this->prepare();
        $view=$this->approval->review($proposal['request_id']);
        try {
            $this->approval->decide($view['request_id'],$view['csrf'],$view['cookie'],'approve',
                'attacker@example.invalid','correct-password');
            self::fail('Wrong person approved');
        } catch (RuntimeException) {
            self::assertSame(0,$this->api->writes);
        }
    }

    public function testCsrfAndCookieAreRequiredForAnIndependentApproval(): void
    {
        $proposal=$this->prepare();
        $view=$this->approval->review($proposal['request_id']);
        foreach ([
            ['wrong',$view['cookie']],
            [$view['csrf'],'wrong'],
        ] as [$csrf,$cookie]) {
            try {
                $this->approval->decide($view['request_id'],$csrf,$cookie,'approve',
                    'member@example.invalid','correct-password');
                self::fail('Forged approval accepted');
            } catch (RuntimeException) {
                self::assertSame(0,$this->api->writes);
            }
        }
    }

    public function testDeletingRequiresAdditionalExplicitPhrase(): void
    {
        $proposal=$this->prepare('DELETE',[]);
        $view=$this->approval->review($proposal['request_id']);
        try {
            $this->approval->decide($view['request_id'],$view['csrf'],$view['cookie'],
                'approve','member@example.invalid','correct-password');
            self::fail('Unconfirmed deletion accepted');
        } catch (RuntimeException) {
            self::assertSame(0,$this->api->writes);
        }
        $this->approval->decide($view['request_id'],$view['csrf'],$view['cookie'],
            'approve','member@example.invalid','correct-password','EXCLUIR');
        self::assertSame(1,$this->api->writes);
        self::assertSame('DELETE',$this->api->lastWrite['method']);
    }

    public function testChangedTargetRequiresAnotherApprovalAndIsNeverRetried(): void
    {
        $proposal=$this->prepare();
        $view=$this->approval->review($proposal['request_id']);
        $this->api->name='Changed by another user';
        try {
            $this->approval->decide($view['request_id'],$view['csrf'],$view['cookie'],
                'approve','member@example.invalid','correct-password');
            self::fail('Stale target accepted');
        } catch (RuntimeException) {
            self::assertSame(0,$this->api->writes);
            self::assertSame('outcome_unconfirmed',
                $this->approval->status($this->subject,$proposal['request_id'])['status']);
        }
    }

    public function testPendingFinancialPlanIsEncryptedAndRevocationInvalidatesIt(): void
    {
        $proposal=$this->prepare('PATCH',['name'=>'Sensitive synthetic account metadata']);
        $onDisk=(string)file_get_contents($this->directory.'/oauth-state.json');
        self::assertStringNotContainsString('Sensitive synthetic account metadata',$onDisk);
        self::assertStringNotContainsString('correct-password',$onDisk);
        $this->store->revoke('member@example.invalid');
        $this->expectException(RuntimeException::class);
        $this->approval->review($proposal['request_id']);
    }

    public function testCreateRequiresIndependentApprovalAndAddsCompanyAndContactType(): void
    {
        $proposal=$this->approval->prepare($this->subject,$this->store->load($this->subject),1,[
            'route'=>'api.contacts.store','method'=>'POST','record_type'=>'vendor',
            'body'=>['name'=>'New synthetic member'],
        ]);
        self::assertSame(0,$this->api->writes);
        self::assertSame('New synthetic member',$proposal['summary']['body']['name']);
        $view=$this->approval->review($proposal['request_id']);
        $this->approval->decide($view['request_id'],$view['csrf'],$view['cookie'],
            'approve','member@example.invalid','correct-password');
        self::assertSame(1,$this->api->writes);
        self::assertSame('POST',$this->api->lastWrite['method']);
        self::assertSame('/contacts',$this->api->lastWrite['path']);
        self::assertSame('vendor',$this->api->lastWrite['body']['type']);
        self::assertSame(1,$this->api->lastWrite['body']['company_id']);
    }

    public function testNestedTransactionMustMatchItsDocumentAndType(): void
    {
        $proposal=$this->approval->prepare($this->subject,$this->store->load($this->subject),1,[
            'route'=>'api.documents.transactions.update','method'=>'PATCH',
            'route_params'=>['document'=>2,'transaction'=>10],
            'record_type'=>'bill','body'=>['description'=>'Corrected synthetic note'],
        ]);
        self::assertSame('/documents/2/transactions/10',
            $this->approval->review($proposal['request_id'])['plan']['path']);
        self::assertSame(0,$this->api->writes);
    }

    public function testNativeUserAndCompanyScopeAreCheckedBeforePreparingMutations(): void
    {
        $user=$this->approval->prepare($this->subject,$this->store->load($this->subject),1,[
            'route'=>'api.users.update','method'=>'PATCH',
            'route_params'=>['user'=>4],'body'=>['name'=>'Synthetic user'],
        ]);
        self::assertSame('awaiting_human_approval',$user['status']);
        $company=$this->approval->prepare($this->subject,$this->store->load($this->subject),1,[
            'route'=>'api.companies.update','method'=>'PATCH',
            'route_params'=>['company'=>1],'body'=>['name'=>'Synthetic company'],
        ]);
        self::assertSame('awaiting_human_approval',$company['status']);
        try {
            $this->approval->prepare($this->subject,$this->store->load($this->subject),1,[
                'route'=>'api.users.update','method'=>'PATCH',
                'route_params'=>['user'=>5],'body'=>['name'=>'Wrong company user'],
            ]);
            self::fail('Cross-company user update was prepared');
        } catch (RuntimeException) {
            self::assertSame(0,$this->api->writes);
        }
    }

    public function testSecretFieldsAreMaskedButEncryptedPlanPreservesPayload(): void
    {
        $proposal=$this->approval->prepare($this->subject,$this->store->load($this->subject),1,[
            'route'=>'api.users.store','method'=>'POST',
            'body'=>['name'=>'Synthetic account','password'=>'PrivateExample123!'],
        ]);
        self::assertSame('[REDACTED]',$proposal['summary']['body']['password']);
        $view=$this->approval->review($proposal['request_id']);
        self::assertSame('PrivateExample123!',$view['plan']['body']['password']);
        self::assertStringNotContainsString('PrivateExample123!',
            (string)file_get_contents($this->directory.'/oauth-state.json'));
    }

    public function testLinkedAccountRotationInvalidatesApproval(): void
    {
        $proposal=$this->prepare();
        $view=$this->approval->review($proposal['request_id']);
        $this->store->saveLogin('member@example.invalid','correct-password',1);
        try {
            $this->approval->decide($view['request_id'],$view['csrf'],$view['cookie'],
                'approve','member@example.invalid','correct-password');
            self::fail('Old login generation was accepted');
        } catch (RuntimeException) {
            self::assertSame(0,$this->api->writes);
        }
    }

    public function testMutationStatusRejectsOtherSubjectsAndUnknownTickets(): void
    {
        $proposal=$this->prepare();
        $this->expectException(RuntimeException::class);
        $this->approval->status(str_repeat('a',64),$proposal['request_id']);
    }
    public function testRepeatedInvalidApprovalsAreRateLimited(): void
    {
        $proposal=$this->prepare();
        $view=$this->approval->review($proposal['request_id']);
        for($n=0;$n<5;$n++){
            try {
                $this->approval->decide($view['request_id'],$view['csrf'],$view['cookie'],
                    'approve','member@example.invalid','incorrect-password');
                self::fail('Invalid password was authorized');
            } catch (RuntimeException) {
                self::assertSame(0,$this->api->writes);
            }
        }
        $this->expectException(RuntimeException::class);
        $this->approval->decide($view['request_id'],$view['csrf'],$view['cookie'],
            'approve','member@example.invalid','correct-password');
    }
    public function testWriteCatalogDoesNotPermitUnknownMethodsOrArbitraryPaths(): void
    {
        $routes=new WriteRouteCatalog();
        self::assertCount(57,$routes->list());
        self::assertSame('/contacts/7',$routes->resolve('api.contacts.update','PATCH',['contact'=>7])['path']);
        self::assertTrue($routes->permits('DELETE','/categories/2'));
        self::assertFalse($routes->permits('GET','/contacts/7'));
        self::assertFalse($routes->permits('DELETE','/oauth/token'));
        $this->expectException(RuntimeException::class);
        $routes->resolve('api.contacts.update','POST',['contact'=>7]);
    }
}
