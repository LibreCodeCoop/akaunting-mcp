<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace AkauntingMcp\Tests;

use AkauntingMcp\Api;
use AkauntingMcp\Config;
use AkauntingMcp\OAuth;
use AkauntingMcp\Store;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AuthenticationBehaviorTest extends TestCase
{
    private string $sandbox;
    private Config $config;
    private Store $store;
    private OAuth $oauth;
    private Api $upstream;
    private string $verifier;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir().'/mcp-auth-'.bin2hex(random_bytes(8));
        mkdir($this->sandbox,0700);
        $this->config = new Config(
            'https://accounting.example.invalid',
            'https://accounting.example.invalid/mcp-auth',
            'https://api.openai.com/v1/mcp/tunnel_synthetic',
            'synthetic-client',
            'https://chatgpt.com/connector/oauth/synthetic',
            $this->sandbox,
            random_bytes(32),
            ['localhost'],
        );
        $this->store = new Store($this->config);
        $this->upstream = new class($this->config) extends Api {
            public bool $available = true;
            public function get(string $email, string $password, string $path, array $query = []): array
            {
                if (!$this->available || $email !== 'reader@example.invalid'
                    || $password !== 'synthetic-password'
                    || $path !== '/companies/1/owner' || ($query['company_id'] ?? null) !== 1) {
                    throw new RuntimeException('Upstream identity or company rejected');
                }
                return [];
            }
        };
        $this->oauth = new OAuth($this->config,$this->store,$this->upstream);
        $this->verifier = str_repeat('A',43);
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->sandbox,\FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach($items as $item){
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->sandbox);
    }

    private function authorize(array $override = [], int $company = 1): string
    {
        $params = array_replace([
            'response_type'=>'code',
            'client_id'=>$this->config->oauthClientId,
            'redirect_uri'=>$this->config->oauthRedirectUri,
            'resource'=>$this->config->resource,
            'scope'=>'mcp:read',
            'code_challenge_method'=>'S256',
            'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$this->verifier,true)),'+/','-_'),'='),
            'state'=>'synthetic-state-1234567890',
        ],$override);
        $flow = $this->oauth->start($params, 'synthetic-ip');
        $redirect = $this->oauth->login($flow['ticket'],$flow['csrf'],$flow['ticket'],
            'reader@example.invalid','synthetic-password',$company,'synthetic-ip');
        parse_str((string)parse_url($redirect,PHP_URL_QUERY),$values);
        self::assertSame($params['state'],$values['state']);
        self::assertSame($this->config->issuer,$values['iss']);
        self::assertIsString($values['code'] ?? null);
        return $values['code'];
    }

    private function exchange(string $code, array $overrides = []): array
    {
        return $this->oauth->token(array_replace([
            'grant_type'=>'authorization_code',
            'client_id'=>$this->config->oauthClientId,
            'redirect_uri'=>$this->config->oauthRedirectUri,
            'code'=>$code,
            'code_verifier'=>$this->verifier,
        ],$overrides));
    }

    public function testAuthorizedUserCanRefreshButOldTokensCannotBeReplayed(): void
    {
        $issued = $this->exchange($this->authorize());
        $subject = $this->oauth->bearer('Bearer '.$issued['access_token']);
        self::assertSame($this->store->subject('reader@example.invalid'), $subject);
        $refresh = $this->oauth->token([
            'grant_type'=>'refresh_token','client_id'=>$this->config->oauthClientId,
            'refresh_token'=>$issued['refresh_token'],
        ]);
        self::assertSame($subject,$this->oauth->bearer('Bearer '.$refresh['access_token']));
        try {
            $this->oauth->token([
                'grant_type'=>'refresh_token','client_id'=>$this->config->oauthClientId,
                'refresh_token'=>$issued['refresh_token'],
            ]);
            self::fail('Old refresh token must never be reusable');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }
        $this->store->revoke('reader@example.invalid');
        $this->expectException(RuntimeException::class);
        $this->oauth->bearer('Bearer '.$refresh['access_token']);
    }

    public function testWrongPkceDoesNotDestroyLegitimateAuthorizationCode(): void
    {
        $code = $this->authorize();
        try {
            $this->exchange($code,['code_verifier'=>str_repeat('B',43)]);
            self::fail('Mismatching PKCE must be rejected');
        } catch(RuntimeException){
            self::assertTrue(true);
        }
        self::assertIsString($this->exchange($code)['access_token']);
        $this->expectException(RuntimeException::class);
        $this->exchange($code);
    }

    public function testUpstreamFailureDoesNotDiscardRefreshToken(): void
    {
        $token = $this->exchange($this->authorize());
        $this->upstream->available = false;
        try {
            $this->oauth->token([
                'grant_type'=>'refresh_token','client_id'=>$this->config->oauthClientId,
                'refresh_token'=>$token['refresh_token'],
            ]);
            self::fail('Unavailable upstream identity must prevent refresh');
        } catch(RuntimeException){
            self::assertTrue(true);
        }
        $this->upstream->available = true;
        $rotated = $this->oauth->token([
            'grant_type'=>'refresh_token','client_id'=>$this->config->oauthClientId,
            'refresh_token'=>$token['refresh_token'],
        ]);
        self::assertIsString($rotated['access_token']);
    }

    public function testAccountCannotAuthorizeAnUnassignedCompany(): void
    {
        $this->expectException(RuntimeException::class);
        $this->authorize([],2);
    }

    public static function forbiddenAuthorizationRequests(): array
    {
        return [
            'implicit grant' => [['response_type'=>'token']],
            'write scope' => [['scope'=>'mcp:write']],
            'PKCE downgrade' => [['code_challenge_method'=>'plain']],
            'unknown callback' => [['redirect_uri'=>'https://attacker.example.invalid/callback']],
            'foreign resource' => [['resource'=>'https://attacker.example.invalid/mcp']],
            'short state' => [['state'=>'short']],
            'invalid PKCE challenge' => [['code_challenge'=>'abc']],
        ];
    }

    #[DataProvider('forbiddenAuthorizationRequests')]
    public function testInvalidOAuthRequestsCannotStartLogin(array $overrides): void
    {
        $params = array_replace([
            'response_type'=>'code','client_id'=>$this->config->oauthClientId,
            'redirect_uri'=>$this->config->oauthRedirectUri,
            'resource'=>$this->config->resource,'scope'=>'mcp:read',
            'code_challenge_method'=>'S256',
            'code_challenge'=>str_repeat('A',43),
            'state'=>'synthetic-state-1234567890',
        ],$overrides);
        $this->expectException(RuntimeException::class);
        $this->oauth->start($params,'synthetic-ip');
    }

    public function testAuthorizationRateLimitEnforcesABoundedLoginWindow(): void
    {
        $params = [
            'response_type'=>'code','client_id'=>$this->config->oauthClientId,
            'redirect_uri'=>$this->config->oauthRedirectUri,
            'resource'=>$this->config->resource,'scope'=>'mcp:read',
            'code_challenge_method'=>'S256','code_challenge'=>str_repeat('A',43),
            'state'=>'synthetic-state-1234567890',
        ];
        for($index=0;$index<20;$index++){
            self::assertNotEmpty($this->oauth->start($params,'synthetic-ip')['ticket']);
        }
        $this->expectException(RuntimeException::class);
        $this->oauth->start($params,'synthetic-ip');
    }

    public static function invalidClaims(): array
    {
        return [
            'wrong audience' => ['aud','https://attacker.example.invalid/resource'],
            'write scope' => ['scope','mcp:write'],
            'wrong issuer' => ['iss','https://attacker.example.invalid/oauth'],
            'expired' => ['exp',1],
            'not active yet' => ['nbf',2147483647],
            'wrong generation' => ['generation',2],
            'refresh used as access' => ['token_use','refresh'],
        ];
    }

    #[DataProvider('invalidClaims')]
    public function testBearerTokensCannotEscalateIdentityOrScope(string $claim, mixed $value): void
    {
        $subject = $this->store->saveLogin('reader@example.invalid','synthetic-password',1);
        $now=time();
        $claims = [
            'iss'=>$this->config->issuer,'aud'=>$this->config->resource,'sub'=>$subject,
            'scope'=>'mcp:read','token_use'=>'access','client_id'=>$this->config->oauthClientId,
            'generation'=>$this->store->generation($subject),
            'iat'=>$now,'nbf'=>$now,'exp'=>$now+600,'jti'=>'synthetic',
        ];
        $claims[$claim]=$value;
        $invalid = OAuth::jwt($claims,$this->config->encryptionKey);
        $this->expectException(RuntimeException::class);
        $this->oauth->bearer('Bearer '.$invalid);
    }

    public function testCredentialVaultNeverStoresPlaintextPasswords(): void
    {
        $subject = $this->store->saveLogin('reader@example.invalid','synthetic-password',1);
        $files = glob($this->sandbox.'/users/*.json');
        self::assertCount(1,$files);
        $contents = (string)file_get_contents($files[0]);
        self::assertStringNotContainsString('reader@example.invalid',$contents);
        self::assertStringNotContainsString('synthetic-password',$contents);
        self::assertSame(0600,fileperms($files[0]) & 0777);
        self::assertSame('synthetic-password',$this->store->load($subject)['password']);
    }
}
