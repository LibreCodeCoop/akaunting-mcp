<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace AkauntingMcp\Tests;

use AkauntingMcp\Api;
use AkauntingMcp\Config;
use AkauntingMcp\Store;
use AkauntingMcp\Toolset;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReadSafetyBehaviorTest extends TestCase
{
    private string $directory;
    private Config $config;
    private Store $store;
    private Toolset $tools;
    private Api $upstream;
    private string $subject;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/mcp-behavior-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
        $config = new Config(
            'https://accounting.example.invalid',
            'https://accounting.example.invalid/mcp-auth',
            'https://api.openai.com/v1/mcp/tunnel_synthetic',
            'synthetic-client',
            'https://chatgpt.com/connector/oauth/synthetic',
            $this->directory,
            random_bytes(32),
            ['localhost'],
        );
        $this->config = $config;
        $this->store = new Store($config);
        $this->subject = $this->store->saveLogin('reader@example.invalid', 'synthetic-password', 1);
        $this->upstream = new class($config) extends Api {
            public ?array $responseOverride = null;
            public int $businessReads = 0;
            public function get(string $email, string $password, string $path, array $query = []): array
            {
                if ($email !== 'reader@example.invalid' || $password !== 'synthetic-password') {
                    throw new RuntimeException('Unauthorized identity');
                }
                if (($query['company_id'] ?? null) !== 1) {
                    throw new RuntimeException('Company unavailable to this account');
                }
                if ($path === '/companies/1/owner') {
                    return [];
                }
                if ($path !== '/categories') {
                    throw new RuntimeException('Unexpected fixture route');
                }
                $this->businessReads++;
                return $this->responseOverride ?? [
                    'data'=>[['id'=>7, 'company_id'=>1, 'name'=>'Authorized category']],
                ];
            }
        };
        $this->tools = new Toolset($this->store, $this->upstream);
    }

    protected function tearDown(): void
    {
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($walk as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($this->directory);
    }

    public static function readMethods(): array
    {
        return [
            'resource tool' => ['akaunting_list_records'],
            'native API route' => ['akaunting_api_read'],
        ];
    }

    private function read(string $method, array $extra = []): array
    {
        $args = $method === 'akaunting_api_read'
            ? ['company_id'=>1, 'route'=>'api.categories.index']
            : ['company_id'=>1, 'resource'=>'categories'];
        return $this->tools->call($this->subject, $method, array_merge($args, $extra));
    }

    #[DataProvider('readMethods')]
    public function testSensitiveFieldsAreAbsentFromEveryReadInterface(string $method): void
    {
        $this->upstream->responseOverride = [
            'data'=>[[
                'id'=>7, 'company_id'=>1, 'name'=>'Authorized category',
                'credential'=>'must-not-leak',
                'password'=>'must-not-leak',
                'vault_path'=>'must-not-leak',
                'certificate'=>'must-not-leak',
                'nested'=>['api_key'=>'must-not-leak', 'name'=>'Allowed child'],
            ]],
        ];
        $response = $this->read($method);
        $json = json_encode($response, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('must-not-leak', $json);
        self::assertSame('Authorized category', $response['data'][0]['name']);
        self::assertSame('Allowed child', $response['data'][0]['nested']['name']);
    }

    #[DataProvider('readMethods')]
    public function testFieldSelectionCannotExposeSecretsOrForeignCompany(string $method): void
    {
        $this->upstream->responseOverride = [
            'data'=>[['id'=>7, 'company_id'=>1, 'credential'=>'must-not-leak', 'name'=>'Visible']],
        ];
        $result = $this->read($method, ['fields'=>['credential','name']]);
        self::assertSame('Visible', $result['data'][0]['name']);
        self::assertArrayNotHasKey('credential', $result['data'][0]);
        self::assertSame(1, $result['data'][0]['company_id']);

        $this->upstream->responseOverride = [
            'data'=>[['id'=>7, 'company_id'=>1, 'nested'=>['company_id'=>2], 'name'=>'Visible']],
        ];
        $this->expectException(RuntimeException::class);
        $this->read($method, ['fields'=>['name']]);
    }

    #[DataProvider('readMethods')]
    public function testAbsentTenantEvidenceIsRejected(string $method): void
    {
        $this->upstream->responseOverride = ['data'=>[['id'=>7, 'name'=>'Unverifiable']]];
        $this->expectException(RuntimeException::class);
        $this->read($method);
    }

    public function testUnusedLegacyPingCannotBecomeAnUnreviewedApiRoute(): void
    {
        $api = new Api($this->config);
        $this->expectException(RuntimeException::class);
        $api->get('reader@example.invalid','synthetic-password','/ping');
    }

    public function testUnassignedCompanyIsDeniedBeforeReadingRecords(): void
    {
        $this->expectException(RuntimeException::class);
        try {
            $this->tools->call($this->subject, 'akaunting_list_records',
                ['resource'=>'categories','company_id'=>2]);
        } finally {
            self::assertSame(0, $this->upstream->businessReads);
        }
    }
}
