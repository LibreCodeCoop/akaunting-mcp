<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace AkauntingMcp\Tests;

use AkauntingMcp\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConfigurationBehaviorTest extends TestCase
{
    private string $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir().'/mcp-configuration-'.bin2hex(random_bytes(8));
        mkdir($this->sandbox,0700);
        file_put_contents($this->sandbox.'/key',base64_encode(random_bytes(32))."\n");
        chmod($this->sandbox.'/key',0600);
    }

    protected function tearDown(): void
    {
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->sandbox,\FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach($walk as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->sandbox);
    }

    private function withEnvironment(array $overrides, callable $test): void
    {
        $tunnel = 'tunnel_0123456789abcdef0123456789abcdef';
        $environment = array_replace([
            'APP_ENV'=>'production',
            'AKAUNTING_BASE_URL'=>'https://accounting.example.invalid',
            'MCP_OAUTH_ISSUER'=>'https://accounting.example.invalid/mcp-auth',
            'MCP_RESOURCE_URI'=>'https://api.openai.com/v1/mcp/'.$tunnel,
            'MCP_OAUTH_CLIENT_ID'=>'synthetic-client',
            'MCP_OAUTH_REDIRECT_URI'=>'https://chatgpt.com/connector/oauth/synthetic',
            'MCP_DATA_DIR'=>$this->sandbox.'/state',
            'MCP_TOKEN_ENCRYPTION_KEY_FILE'=>$this->sandbox.'/key',
            'OPENAI_TUNNEL_ID'=>$tunnel,
            'MCP_ALLOW_SEPARATE_ISSUER_HOST'=>'false',
            'AKAUNTING_ALLOW_INSECURE_LOOPBACK'=>'false',
        ],$overrides);
        $before=[];
        foreach($environment as $key=>$value) {
            $before[$key]=getenv($key);
            putenv($key.'='.$value);
        }
        try {
            $test();
        } finally {
            foreach($before as $key=>$value) {
                $value === false ? putenv($key) : putenv($key.'='.$value);
            }
        }
    }

    public function testValidProductionConfigurationRestrictsTheAppToTheRegisteredTunnel(): void
    {
        $this->withEnvironment([],function(): void {
            $config=Config::fromEnvironment();
            self::assertSame('https://accounting.example.invalid',$config->akauntingUrl);
            self::assertSame('https://api.openai.com/v1/mcp/tunnel_0123456789abcdef0123456789abcdef',
                $config->resource);
            self::assertFalse($config->allowLoopbackHttp);
            self::assertDirectoryExists($config->dataDir);
            self::assertSame(0700,fileperms($config->dataDir)&0777);
        });
    }

    public static function invalidProductionSettings(): array
    {
        return [
            'non HTTPS API' => [['AKAUNTING_BASE_URL'=>'http://accounting.example.invalid']],
            'API with credentials in URL' => [['AKAUNTING_BASE_URL'=>'https://user:pass@accounting.example.invalid']],
            'API with query parameters' => [['AKAUNTING_BASE_URL'=>'https://accounting.example.invalid?leak=1']],
            'issuer without dedicated path' => [['MCP_OAUTH_ISSUER'=>'https://accounting.example.invalid']],
            'reserved issuer path' => [['MCP_OAUTH_ISSUER'=>'https://accounting.example.invalid/oauth']],
            'cross-host issuer without authorization' => [['MCP_OAUTH_ISSUER'=>'https://other.example.invalid/mcp-auth']],
            'insecure resource' => [['MCP_RESOURCE_URI'=>'http://api.openai.com/v1/mcp/tunnel_0123456789abcdef0123456789abcdef']],
            'different tunnel audience' => [['MCP_RESOURCE_URI'=>'https://api.openai.com/v1/mcp/tunnel_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']],
            'unregistered tunnel' => [['OPENAI_TUNNEL_ID'=>'tunnel_invalid']],
            'insecure callback' => [['MCP_OAUTH_REDIRECT_URI'=>'http://chatgpt.com/connector/oauth/synthetic']],
        ];
    }

    #[DataProvider('invalidProductionSettings')]
    public function testUnsafeConfigurationIsRejectedBeforeServingRequests(array $overrides): void
    {
        $this->withEnvironment($overrides,function(): void {
            $this->expectException(RuntimeException::class);
            Config::fromEnvironment();
        });
    }

    public function testBrokenOrMissingKeyPreventsStartup(): void
    {
        $this->withEnvironment(['MCP_TOKEN_ENCRYPTION_KEY_FILE'=>$this->sandbox.'/missing'],function(): void {
            $this->expectException(RuntimeException::class);
            Config::fromEnvironment();
        });
    }

    public function testHttpLoopbackIsAllowedOnlyForExplicitTesting(): void
    {
        $this->withEnvironment([
            'APP_ENV'=>'testing',
            'AKAUNTING_ALLOW_INSECURE_LOOPBACK'=>'true',
            'AKAUNTING_BASE_URL'=>'http://127.0.0.1:8875',
            'MCP_ALLOW_SEPARATE_ISSUER_HOST'=>'true',
        ],function(): void {
            self::assertTrue(Config::fromEnvironment()->allowLoopbackHttp);
        });
        $this->withEnvironment([
            'APP_ENV'=>'production',
            'AKAUNTING_ALLOW_INSECURE_LOOPBACK'=>'true',
            'AKAUNTING_BASE_URL'=>'http://127.0.0.1:8875',
        ],function(): void {
            $this->expectException(RuntimeException::class);
            Config::fromEnvironment();
        });
    }
}
