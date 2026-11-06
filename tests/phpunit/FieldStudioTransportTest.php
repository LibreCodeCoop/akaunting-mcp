<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace AkauntingMcp\Tests;

use AkauntingMcp\Api;
use AkauntingMcp\ApiBrowser;
use AkauntingMcp\Config;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FieldStudioTransportTest extends TestCase
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
        $fixture=dirname(__DIR__).'/fixtures/field-studio-router.php';
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
        self::fail('Synthetic Field Studio API fixture did not start');
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

    public function testReviewedReadTargetsNativeV1RouteWithCompanyAndTypedQuery(): void
    {
        $result=(new ApiBrowser($this->api()))->read([
            'email'=>'synthetic@example.invalid','password'=>'synthetic-password',
        ],1,[
            'route'=>'api.field-studio.definitions.index',
            'module_filters'=>['entity_type'=>'invoice'],
        ]);
        self::assertSame(1,$result['company_id']);
        self::assertSame('cost_center',$result['response']['data'][0]['field_key']);
    }

    public function testReviewedValueReadAndPatchUseNativeV1WithoutRewritingBody(): void
    {
        $api=$this->api();
        $response=(new ApiBrowser($api))->read([
            'email'=>'synthetic@example.invalid','password'=>'synthetic-password',
        ],1,[
            'route'=>'api.field-studio.values.show',
            'route_params'=>['entity_type'=>'invoice','entity_id'=>7],
        ]);
        self::assertSame('Operations',$response['response']['data'][0]['value']);
        $result=$api->mutate(
            'synthetic@example.invalid','synthetic-password','PATCH',
            '/v1/field-studio/records/invoice/7/values',1,
            ['values'=>['cost_center'=>'Sales'],'versions'=>['cost_center'=>2]],
        );
        self::assertSame(200,$result['http_status']);
        self::assertSame('Sales',$result['data']['data'][0]['value']);
    }

    public function testReviewedDefinitionCreateAndValueDeleteUseExactMethods(): void
    {
        $api=$this->api();
        $created=$api->mutate(
            'synthetic@example.invalid','synthetic-password','POST',
            '/v1/field-studio/definitions',1,[
                'entity_type'=>'invoice','field_key'=>'cost_center',
                'label'=>'Cost center','field_type'=>'text',
            ],
        );
        self::assertSame(201,$created['http_status']);
        self::assertSame(14,$created['data']['data']['id']);

        $deleted=$api->mutate(
            'synthetic@example.invalid','synthetic-password','DELETE',
            '/v1/field-studio/records/invoice/7/values/cost_center',1,[],
        );
        self::assertSame(204,$deleted['http_status']);
    }

    public function testUnregisteredAndCrossTenantHttpPathsFailBeforeTransport(): void
    {
        $api=$this->api();
        foreach ([
            fn()=> $api->mutate(
                'synthetic@example.invalid','synthetic-password','PATCH',
                '/v1/field-studio/records/customer/7/values',1,[
                    'values'=>['cost_center'=>'Other'],
                ]),
            fn()=> $api->get(
                'synthetic@example.invalid','synthetic-password',
                '/v1/field-studio/internal/admin',['company_id'=>1],
            ),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('Unreviewed Field Studio path unexpectedly accepted');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }
}
