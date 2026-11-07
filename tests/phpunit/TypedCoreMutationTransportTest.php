<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace AkauntingMcp\Tests;

use AkauntingMcp\Api;
use AkauntingMcp\Config;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TypedCoreMutationTransportTest extends TestCase
{
    /** @var resource|false */
    private $server=false;
    /** @var array<int,resource> */
    private array $pipes=[];
    private int $port=0;

    protected function setUp(): void
    {
        $sock=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
        self::assertIsResource($sock);
        $endpoint=stream_socket_get_name($sock,false);
        self::assertIsString($endpoint);
        $this->port=(int)substr(strrchr($endpoint,':'),1);
        fclose($sock);
        $this->server=proc_open(
            [PHP_BINARY,'-S','127.0.0.1:'.$this->port,
                dirname(__DIR__).'/fixtures/core-typed-write-router.php'],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],
            $this->pipes,dirname(__DIR__,2),
        );
        self::assertIsResource($this->server);
        for($i=0;$i<40;$i++) {
            $c=@fsockopen('127.0.0.1',$this->port,$eno,$err,0.1);
            if(is_resource($c)) { fclose($c); return; }
            usleep(25000);
        }
        self::fail('Synthetic Core permission router did not start');
    }

    protected function tearDown(): void
    {
        if(is_resource($this->server)) proc_terminate($this->server);
        foreach($this->pipes as $pipe) if(is_resource($pipe)) fclose($pipe);
        if(is_resource($this->server)) proc_close($this->server);
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

    public function testDocumentCreateIsAcceptedOnlyWithTypedQuery(): void
    {
        $response=$this->api()->mutate(
            'synthetic@example.invalid','synthetic-password','POST',
            '/documents',1,['type'=>'invoice','amount'=>21500],'invoice',
        );
        self::assertSame(201,$response['http_status']);
        self::assertSame('invoice',$response['data']['data']['type']);
    }

    public function testDocumentAndContactUpdatesAndDeletionsUseApprovedType(): void
    {
        $api=$this->api();
        $doc=$api->mutate('synthetic@example.invalid','synthetic-password',
            'PATCH','/documents/7',1,['notes'=>'Updated'],'invoice');
        self::assertSame('invoice',$doc['data']['data']['type']);
        $contact=$api->mutate('synthetic@example.invalid','synthetic-password',
            'POST','/contacts',1,['type'=>'customer','name'=>'Synthetic'],'customer');
        self::assertSame('customer',$contact['data']['data']['type']);
        $deleted=$api->mutate('synthetic@example.invalid','synthetic-password',
            'DELETE','/documents/7',1,[],'invoice');
        self::assertSame(204,$deleted['http_status']);
    }

    public function testMissingOrMismatchedTypeFailsClosedBeforeMutation(): void
    {
        foreach ([
            fn()=> $this->api()->mutate('synthetic@example.invalid','pass',
                'POST','/documents',1,['type'=>'invoice']),
            fn()=> $this->api()->mutate('synthetic@example.invalid','pass',
                'POST','/documents',1,['type'=>'bill'],'invoice'),
            fn()=> $this->api()->mutate('synthetic@example.invalid','pass',
                'POST','/contacts',1,['type'=>'customer'],'invoice'),
            fn()=> $this->api()->mutate('synthetic@example.invalid','pass',
                'POST','/v1/field-studio/definitions',1,['entity_type'=>'invoice'],'invoice'),
        ] as $reject) {
            try {
                $reject();
                self::fail('Invalid typed core write was accepted');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }
}
