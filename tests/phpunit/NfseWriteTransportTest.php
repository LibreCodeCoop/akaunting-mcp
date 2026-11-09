<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace AkauntingMcp\Tests;

use AkauntingMcp\Api;
use AkauntingMcp\Config;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NfseWriteTransportTest extends TestCase
{
    /** @var resource|false */
    private $server=false;
    /** @var array<int,resource> */
    private array $pipes=[];
    private int $port=0;

    protected function setUp(): void
    {
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$message);
        self::assertIsResource($socket);
        $address=stream_socket_get_name($socket,false);
        self::assertIsString($address);
        $this->port=(int)substr(strrchr($address,':'),1);
        fclose($socket);
        $fixture=dirname(__DIR__).'/fixtures/fiscal-mutation-router.php';
        $this->server=proc_open([
            PHP_BINARY,'-S','127.0.0.1:'.$this->port,$fixture,
        ],[
            0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w'],
        ],$this->pipes,dirname(__DIR__,2));
        self::assertIsResource($this->server);
        for ($i=0;$i<30;$i++) {
            $connection=@fsockopen('127.0.0.1',$this->port,$errno,$message,0.1);
            if (is_resource($connection)) {
                fclose($connection);
                return;
            }
            usleep(30000);
        }
        self::fail('Synthetic fiscal HTTP fixture did not start');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) proc_terminate($this->server);
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) fclose($pipe);
        }
        if (is_resource($this->server)) proc_close($this->server);
    }

    private function api(): Api
    {
        return new Api(new Config(
            'http://127.0.0.1:'.$this->port,
            'https://accounting.example.invalid/mcp-auth',
            'https://api.openai.com/v1/mcp/tunnel_synthetic','test-client',
            'https://chatgpt.com/connector/oauth/synthetic',
            sys_get_temp_dir(),random_bytes(32),['localhost'],true,
        ));
    }

    public function testFiscalPatchUsesTenantPrefixedNativeApiWithoutInjectingCompanyId(): void
    {
        $result=$this->api()->mutate(
            'synthetic@example.invalid','synthetic-password','PATCH',
            '/nfse/settings/company-profiles/1',1,
            ['fiscal_parameters'=>['emission_policy'=>'manual']],
        );
        self::assertSame(200,$result['http_status']);
        self::assertSame('synthetic_updated',$result['data']['data']['status']);
    }

    public function testFiscalCancellationSendsReviewedDeleteJsonToTenantPath(): void
    {
        $result=$this->api()->mutate(
            'synthetic@example.invalid','synthetic-password','DELETE',
            '/nfse/invoices/7',1,
            ['nfse_receipt_id'=>12,'cancel_reason'=>'9',
                'cancel_justification'=>'Synthetic cancellation for test'],
        );
        self::assertSame(200,$result['http_status']);
        self::assertSame('synthetic_cancelled',$result['data']['data']['status']);
    }

    public function testFiscalReemissionSendsReviewedPostToTenantPath(): void
    {
        $result=$this->api()->mutate(
            'synthetic@example.invalid','synthetic-password','POST',
            '/nfse/invoices/7/reemit',1,
            ['nfse_receipt_id'=>12,'nfse_force_ajax'=>true],
        );
        self::assertSame(200,$result['http_status']);
        self::assertSame('synthetic_reemitted',$result['data']['data']['status']);
    }

    public function testFiscalSubstitutionUsesReviewedPostWithExactReceiptId(): void
    {
        $result=$this->api()->mutate(
            'synthetic@example.invalid','synthetic-password','POST',
            '/nfse/invoices/7/substitute',1,[
                'nfse_substitution_receipt_id'=>12,
                'nfse_substitution_reason'=>'05',
                'nfse_substitution_description'=>'Synthetic fiscal correction for integration testing',
                'nfse_force_ajax'=>true,
            ],
        );
        self::assertSame(200,$result['http_status']);
        self::assertSame('synthetic_substituted',$result['data']['data']['status']);
    }

    public function testFiscalEventEvidencePostsApprovedJsonToTenantScopedApi(): void
    {
        $result=$this->api()->mutate(
            'synthetic@example.invalid','synthetic-password','POST',
            '/nfse/invoices/7/fiscal-event-evidence',1,[
                'fiscal_group_key'=>'service:0107|tax:010701|mun:123|rate:5.00',
                'taxable_event_date'=>'2026-11-01',
                'source_reference'=>'Synthetic source document',
                'acknowledge'=>true,
            ],
        );
        self::assertSame(201,$result['http_status']);
        self::assertSame('attested',$result['data']['status']);
    }

    public function testReviewedReadOnlyFiscalPostUsesTenantScopedNativeApi(): void
    {
        $api=$this->api();
        $preview=$api->postReadOnly(
            'synthetic@example.invalid','synthetic-password',
            '/nfse/bulk/plan',1,['invoice_ids'=>[7,8]],
        );
        self::assertSame(200,$preview['http_status']);
        self::assertSame(7,$preview['data']['data']['queued'][0]['invoice_id']);
        $diag=$api->postReadOnly(
            'synthetic@example.invalid','synthetic-password',
            '/nfse/settings/vault/diagnostics',1,[],
        );
        self::assertSame(200,$diag['http_status']);
        self::assertSame('operational',$diag['data']['state']);
        foreach (['/nfse/bulk/dispatch','/nfse/invoices/7/emit'] as $unsafe) {
            try {
                $api->postReadOnly(
                    'synthetic@example.invalid','synthetic-password',$unsafe,1,[],
                );
                self::fail('A write endpoint was accepted as a read');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function testOperationalFiscalActionsUseExactTenantScopedJsonRoutes(): void
    {
        $api=$this->api();
        $dps=$api->mutate(
            'synthetic@example.invalid','synthetic-password',
            'POST','/nfse/dps/advance',1,[
                'expected_next_number'=>'900000000000008',
                'next_number'=>'900000000000100',
                'reason'=>'Synthetic documented adjustment for test',
                'confirmed'=>true,
            ],
        );
        self::assertSame(200,$dps['http_status']);
        self::assertSame('900000000000100',$dps['data']['data']['next_number']);
        $refresh=$api->mutate(
            'synthetic@example.invalid','synthetic-password',
            'POST','/nfse/invoices/7/refresh',1,[
                'nfse_receipt_id'=>12,'nfse_force_ajax'=>true,
            ],
        );
        self::assertSame('synthetic_refreshed',$refresh['data']['data']['status']);
        $bulk=$api->mutate(
            'synthetic@example.invalid','synthetic-password',
            'POST','/nfse/bulk/dispatch',1,['invoice_ids'=>[7,8]],
        );
        self::assertSame(202,$bulk['http_status']);
        self::assertSame(2,$bulk['data']['data']['dispatched']);
        $this->expectException(RuntimeException::class);
        $api->mutate(
            'synthetic@example.invalid','synthetic-password',
            'POST','/nfse/bulk/plan',1,['invoice_ids'=>[7,8]],
        );
    }

    public function testUnknownFiscalPathCannotReachHttpTransport(): void
    {
        $this->expectException(RuntimeException::class);
        $this->api()->mutate(
            'synthetic@example.invalid','synthetic-password','POST',
            '/nfse/settings/vault',1,['nfse'=>['bao_token'=>'forbidden']],
        );
    }
}
