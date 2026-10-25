<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace AkauntingMcp\Tests;

use AkauntingMcp\Api;
use AkauntingMcp\Config;
use AkauntingMcp\Mcp;
use AkauntingMcp\RecordProjection;
use AkauntingMcp\RouteCatalog;
use AkauntingMcp\SettingsScopeFilter;
use AkauntingMcp\Store;
use AkauntingMcp\Toolset;
use AkauntingMcp\UserScopeFilter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ContractsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/akaunting-phpunit-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        // Only remove the dedicated synthetic directory generated for this test.
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    private function config(): Config
    {
        return new Config('https://accounting.example.invalid',
            'https://accounting.example.invalid/mcp-auth',
            'https://api.openai.com/v1/mcp/tunnel_synthetic', 'test-client',
            'https://chatgpt.com/connector/oauth/synthetic', $this->directory,
            random_bytes(32), ['localhost']);
    }

    public function testManifestContainsOnlyReviewedRoutes(): void
    {
        $catalog = new RouteCatalog();
        self::assertCount(40, $catalog->list());
        self::assertTrue($catalog->permitsPath('/documents/7'));
        self::assertFalse($catalog->permitsPath('/documents/7/received'));
        self::assertFalse($catalog->permitsPath('/nfse/read/services'));
        self::assertSame('/departments/12', $catalog->resolve('api.departments.show', ['department'=>12])['path']);
        $this->expectException(RuntimeException::class);
        $catalog->resolve('api.departments.show', ['department'=>'../secrets']);
    }

    public function testProjectionPreservesTenantFields(): void
    {
        self::assertSame(['data'=>[['id'=>7, 'company_id'=>1, 'name'=>'Synthetic']]],
            RecordProjection::apply(['data'=>[['id'=>7,'company_id'=>1,'name'=>'Synthetic','hidden'=>'Excluded']]], ['name']));
        $this->expectException(RuntimeException::class);
        RecordProjection::validate(['../etc/passwd']);
    }

    public function testUsersNeverExposeOtherTenantsOrGlobalCounts(): void
    {
        $response = ['data'=>[
            ['id'=>1,'email'=>'allowed@example.invalid','companies'=>['data'=>[['id'=>4]]],
                'roles'=>['data'=>[['id'=>5,'name'=>'Accountant','code'=>'accountant','secret'=>'untrusted']]]],
            ['id'=>2,'email'=>'foreign@example.invalid','companies'=>['data'=>[['id'=>9]]]],
        ],'meta'=>['total'=>5000,'current_page'=>1,'last_page'=>2]];
        $filtered = UserScopeFilter::page($response,4,1);
        self::assertCount(1,$filtered['data']);
        self::assertSame('allowed@example.invalid',$filtered['data'][0]['email']);
        self::assertArrayNotHasKey('meta',$filtered);
        self::assertArrayNotHasKey('secret',$filtered['data'][0]['roles'][0]);
        self::assertTrue($filtered['upstream_has_next_page']);
    }

    public function testSettingsAreFilteredToReviewedCompanyKeys(): void
    {
        $filtered = SettingsScopeFilter::apply(['data'=>[
            ['company_id'=>2,'key'=>'company.name','value'=>'Selected'],
            ['company_id'=>3,'key'=>'company.name','value'=>'Unrelated'],
            ['company_id'=>2,'key'=>'company.secret_token','value'=>'sensitive'],
        ],'meta'=>['total'=>999]],2);
        self::assertSame([['company_id'=>2,'key'=>'company.name','value'=>'Selected']],$filtered['data']);
        self::assertArrayNotHasKey('meta',$filtered);
    }

    public function testUnauthenticatedMcpCallDoesNotAccessFinance(): void
    {
        $config = $this->config();
        $mcp = new Mcp(new Toolset(new Store($config),new Api($config)),
            'https://api.openai.com/.well-known/oauth-protected-resource/v1/mcp/tunnel_synthetic');
        $call = static fn(array $message): ?array => $mcp->execute(null,json_encode($message,JSON_THROW_ON_ERROR));
        $discovery = $call(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/list']);
        self::assertCount(9,$discovery['result']['tools']);
        $unauthorized = $call(['jsonrpc'=>'2.0','id'=>2,'method'=>'tools/call',
            'params'=>['name'=>'akaunting_api_read','arguments'=>['company_id'=>1,'route'=>'api.users.index']]]);
        self::assertTrue($unauthorized['result']['isError']);
        self::assertArrayHasKey('mcp/www_authenticate',$unauthorized['result']['_meta']);
        self::assertNull($call(['jsonrpc'=>'2.0','method'=>'notifications/initialized']));
    }

    public function testUndeclaredToolArgumentsAreRejected(): void
    {
        $config = $this->config();
        $store = new Store($config);
        $subject = $store->saveLogin('synthetic@example.invalid','synthetic-password',1);
        $mcp = new Mcp(new Toolset($store,new Api($config)),
            'https://api.openai.com/.well-known/oauth-protected-resource/v1/mcp/tunnel_synthetic');
        $result = $mcp->execute($subject,json_encode([
            'jsonrpc'=>'2.0','id'=>8,'method'=>'tools/call',
            'params'=>['name'=>'akaunting_resources','arguments'=>['unregistered_input'=>'ignored']],
        ],JSON_THROW_ON_ERROR));
        self::assertTrue($result['result']['isError'], 'MCP must enforce additionalProperties=false declared in tools/list');
    }
}
