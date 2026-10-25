<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace AkauntingMcp;

use RuntimeException;

/** OAuth authorization-code/PKCE S256 broker using the user's Akaunting Basic credentials. */
final class OAuth
{
    public function __construct(
        private readonly Config $config,
        private readonly Store $store,
        private readonly Api $api,
    ) {}

    public function metadata(): array
    {
        return [
            'issuer'=>$this->config->issuer,
            'authorization_endpoint'=>$this->config->issuer.'/authorize',
            'token_endpoint'=>$this->config->issuer.'/token',
            'response_types_supported'=>['code'],
            'authorization_response_iss_parameter_supported'=>true,
            'grant_types_supported'=>['authorization_code','refresh_token'],
            'code_challenge_methods_supported'=>['S256'],
            'token_endpoint_auth_methods_supported'=>['none'],
            'scopes_supported'=>['mcp:read'],
        ];
    }

    public function protectedMetadata(): array
    {
        return [
            'resource'=>$this->config->resource,
            'authorization_servers'=>[$this->config->issuer],
            'scopes_supported'=>['mcp:read'],
        ];
    }

    public function start(array $query, string $ip = 'unknown'): array
    {
        // Some ChatGPT connections omit the RFC 8707 resource indicator. As this
        // broker has exactly one configured MCP audience, omission resolves only
        // to that audience. Explicitly supplied mismatched values are rejected.
        if (($query['response_type'] ?? null) !== 'code' || ($query['scope'] ?? '') !== 'mcp:read'
            || ($query['code_challenge_method'] ?? '') !== 'S256'
            || (array_key_exists('resource', $query) && $query['resource'] !== $this->config->resource)) {
            throw new RuntimeException('Unsupported OAuth request');
        }
        $this->client($query['client_id'] ?? null, $query['redirect_uri'] ?? null);
        $challenge = $query['code_challenge'] ?? null;
        $state = $query['state'] ?? null;
        if (!is_string($challenge) || !preg_match('/^[a-zA-Z0-9_-]{43}$/D', $challenge)
            || !is_string($state) || strlen($state) < 16 || strlen($state) > 512) {
            throw new RuntimeException('Invalid PKCE challenge or state');
        }
        $ticket = self::random();
        $csrf = self::random();
        $this->store->state(function (array &$db) use ($ticket, $csrf, $state, $challenge, $ip): void {
            $remote = hash_hmac('sha256', $ip, $this->config->encryptionKey);
            $window = $db['authorize_attempts'][$remote] ?? ['count'=>0,'expires'=>time()+60];
            if (($window['expires'] ?? 0) < time()) {
                $window = ['count'=>0,'expires'=>time()+60];
            }
            if ($window['count'] >= 20 || count($db['tickets']) >= 1000) {
                throw new RuntimeException('OAuth authorization rate limit');
            }
            $db['authorize_attempts'][$remote] = ['count'=>$window['count']+1,'expires'=>$window['expires']];
            $db['tickets'][self::digest($ticket)] = [
                'client'=>$this->config->oauthClientId,
                'redirect'=>$this->config->oauthRedirectUri,
                'challenge'=>$challenge,
                'state'=>$state,
                'csrf'=>self::digest($csrf),
                'expires'=>time()+300,
                'attempts'=>0,
            ];
        });
        return ['ticket'=>$ticket, 'csrf'=>$csrf];
    }

    public function login(string $ticket, string $csrf, string $cookie, string $email, string $password, int $companyId, string $ip): string
    {
        if (strlen($ticket) > 100 || strlen($csrf) > 100 || $ticket === '' || $cookie === '' || !hash_equals($cookie, $ticket)) {
            throw new RuntimeException('Invalid browser OAuth session');
        }
        $key = self::digest($ticket);
        // Consume an attempt even on authentication failure; limit by ticket and remote IP.
        $entry = $this->store->state(function (array &$db) use ($key, $csrf, $ip): array {
            $entry = $db['tickets'][$key] ?? null;
            if (!is_array($entry) || !hash_equals((string)($entry['csrf'] ?? ''), self::digest($csrf))
                || ($entry['expires'] ?? 0) <= time() || ($entry['attempts'] ?? 0) >= 5
                || ($entry['authenticating'] ?? false) === true) {
                throw new RuntimeException('OAuth login expired or invalid');
            }
            $remote = hash_hmac('sha256', $ip, $this->config->encryptionKey);
            $window = $db['login_attempts'][$remote] ?? ['count'=>0, 'expires'=>time()+600];
            if (($window['expires'] ?? 0) < time()) {
                $window = ['count'=>0, 'expires'=>time()+600];
            }
            if ($window['count'] >= 15) {
                throw new RuntimeException('Too many sign-in attempts');
            }
            $db['login_attempts'][$remote] = ['count'=>$window['count']+1,'expires'=>$window['expires']];
            $db['tickets'][$key]['attempts']++;
            // Reserve the ticket under the OAuth state lock before making the
            // upstream call; a concurrent login may not redeem it.
            $db['tickets'][$key]['authenticating'] = true;
            return $entry;
        });
        try {
            $this->api->verify($email, $password, $companyId);
            $subject = $this->store->saveLogin($email, $password, $companyId);
            $code = self::random();
            $this->store->state(function (array &$db) use ($key, $entry, $code, $subject): void {
                // Fail closed if the ticket was consumed while verification ran.
                if (($db['tickets'][$key]['authenticating'] ?? false) !== true) {
                    throw new RuntimeException('OAuth login no longer active');
                }
                unset($db['tickets'][$key]);
                $db['codes'][self::digest($code)] = [
                    'client'=>$entry['client'], 'redirect'=>$entry['redirect'],
                    'challenge'=>$entry['challenge'], 'subject'=>$subject,
                    'expires'=>time()+120,
                ];
            });
        } catch (\Throwable $error) {
            $this->store->state(static function (array &$db) use ($key): void {
                if (isset($db['tickets'][$key])) {
                    unset($db['tickets'][$key]['authenticating']);
                }
            });
            throw $error;
        }
        return $entry['redirect'].'?'.http_build_query(['code'=>$code, 'state'=>$entry['state'], 'iss'=>$this->config->issuer], '', '&', PHP_QUERY_RFC3986);
    }

    /** Redirect an invalid authorization request only to the pre-registered client callback. */
    public function authorizationError(array $query): string
    {
        $this->client($query['client_id'] ?? null, $query['redirect_uri'] ?? null);
        $state = $query['state'] ?? null;
        if (!is_string($state) || strlen($state) < 16 || strlen($state) > 512) {
            throw new RuntimeException('Invalid OAuth error state');
        }
        return $this->config->oauthRedirectUri.'?'.http_build_query([
            'error'=>'invalid_request', 'state'=>$state, 'iss'=>$this->config->issuer,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function token(array $form): array
    {
        $this->client($form['client_id'] ?? null, $this->config->oauthRedirectUri);
        // Token audience is always the configured resource, never user input.
        // Allow omission for the single-audience ChatGPT client, but reject
        // supplied mismatches to prevent cross-resource token confusion.
        if (array_key_exists('resource', $form) && $form['resource'] !== $this->config->resource) {
            throw new RuntimeException('Invalid resource audience');
        }
        if (($form['grant_type'] ?? null) === 'authorization_code') {
            $code = $form['code'] ?? null;
            $verifier = $form['code_verifier'] ?? null;
            if (!is_string($code) || strlen($code) > 100 || !is_string($verifier)
                || !preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier)
                || ($form['redirect_uri'] ?? null) !== $this->config->oauthRedirectUri) {
                throw new RuntimeException('Invalid code exchange');
            }
            $challenge = self::b64url(hash('sha256',$verifier,true));
            $subject = $this->store->state(function (array &$db) use ($code, $challenge): string {
                $digest = self::digest($code);
                $entry = $db['codes'][$digest] ?? null;
                if (!is_array($entry) || ($entry['expires'] ?? 0) <= time()
                    || !hash_equals((string)$entry['challenge'], $challenge)) {
                    throw new RuntimeException('Invalid authorization code or PKCE');
                }
                // Keep the code until account validation and atomic token issue
                // succeed, so upstream transient errors allow a safe retry.
                return $entry['subject'];
            });
            return $this->issue($subject, null, $code, $challenge);
        }
        if (($form['grant_type'] ?? null) === 'refresh_token') {
            $refresh = $form['refresh_token'] ?? null;
            if (!is_string($refresh) || strlen($refresh) > 100) {
                throw new RuntimeException('Invalid refresh token');
            }
            // Check the token without consuming it. A 429, timeout, or other
            // upstream verification failure must not destroy the only valid
            // refresh token; rotation is committed atomically inside issue().
            $subject = $this->store->state(function (array &$db) use ($refresh): string {
                $digest = self::digest($refresh);
                $entry = $db['refresh'][$digest] ?? null;
                if (!is_array($entry) || ($entry['expires'] ?? 0) <= time()
                    || ($entry['client'] ?? '') !== $this->config->oauthClientId
                    || !is_string($entry['subject'] ?? null)) {
                    throw new RuntimeException('Expired or revoked refresh token');
                }
                return $entry['subject'];
            });
            return $this->issue($subject, $refresh);
        }
        throw new RuntimeException('Unsupported OAuth grant');
    }

    private function issue(string $subject, ?string $previousRefresh = null,
        ?string $previousCode = null, ?string $expectedChallenge = null): array
    {
        $credentials = $this->store->load($subject);
        // A changed/disabled Akaunting password must also block token refresh.
        $this->api->verify($credentials['email'], $credentials['password'], $credentials['company_id']);
        $now = time();
        $jwt = self::jwt([
            'iss'=>$this->config->issuer, 'aud'=>$this->config->resource, 'sub'=>$subject,
            'scope'=>'mcp:read', 'token_use'=>'access', 'client_id'=>$this->config->oauthClientId,
            'generation'=>$credentials['generation'],
            'iat'=>$now, 'nbf'=>$now, 'exp'=>$now+600, 'jti'=>self::random(),
        ], $this->config->encryptionKey);
        $refresh = self::random();
        $this->store->state(function (array &$db) use ($refresh, $subject, $previousRefresh, $previousCode, $expectedChallenge): void {
            if ($previousCode !== null) {
                // A code is consumed only when the corresponding refresh token
                // is stored, both under the same inter-process lock.
                $oldKey = self::digest($previousCode);
                $entry = $db['codes'][$oldKey] ?? null;
                if (!is_array($entry) || ($entry['expires'] ?? 0) <= time()
                    || ($entry['client'] ?? null) !== $this->config->oauthClientId
                    || ($entry['redirect'] ?? null) !== $this->config->oauthRedirectUri
                    || ($entry['subject'] ?? null) !== $subject
                    || !is_string($expectedChallenge)
                    || !hash_equals((string)($entry['challenge'] ?? ''), $expectedChallenge)) {
                    throw new RuntimeException('Invalid authorization code or PKCE');
                }
                unset($db['codes'][$oldKey]);
            }
            if ($previousRefresh !== null) {
                // Check and rotate under one exclusive lock. Concurrent reuse
                // can neither mint another refresh token nor revive a revoked
                // one, even if verification happened outside the lock.
                $oldKey = self::digest($previousRefresh);
                $entry = $db['refresh'][$oldKey] ?? null;
                if (!is_array($entry) || ($entry['expires'] ?? 0) <= time()
                    || ($entry['client'] ?? '') !== $this->config->oauthClientId
                    || ($entry['subject'] ?? null) !== $subject) {
                    throw new RuntimeException('Expired or revoked refresh token');
                }
                unset($db['refresh'][$oldKey]);
            }
            $db['refresh'][self::digest($refresh)] = [
                'subject'=>$subject, 'client'=>$this->config->oauthClientId,
                'expires'=>time()+7*86400,
            ];
        });
        return ['access_token'=>$jwt,'token_type'=>'Bearer','expires_in'=>600,'scope'=>'mcp:read','refresh_token'=>$refresh];
    }

    public function bearer(string $header): string
    {
        if (!preg_match('/^Bearer ([A-Za-z0-9_.-]{60,4096})$/D', $header, $m)) {
            throw new RuntimeException('Bearer authorization required');
        }
        $parts = explode('.', $m[1]);
        if (count($parts) !== 3) {
            throw new RuntimeException('Invalid bearer token');
        }
        $headerJson = self::decode($parts[0]);
        $payload = self::decode($parts[1]);
        if ($headerJson !== ['alg'=>'HS256', 'typ'=>'JWT']) {
            throw new RuntimeException('Invalid JWT algorithm');
        }
        $key = self::jwtKey($this->config->encryptionKey);
        if (!hash_equals(self::b64url(hash_hmac('sha256',$parts[0].'.'.$parts[1],$key,true)), $parts[2])) {
            throw new RuntimeException('Invalid token signature');
        }
        if (($payload['iss'] ?? null) !== $this->config->issuer || ($payload['aud'] ?? null) !== $this->config->resource
            || ($payload['scope'] ?? null) !== 'mcp:read' || ($payload['token_use'] ?? null) !== 'access'
            || ($payload['client_id'] ?? null) !== $this->config->oauthClientId
            || !is_int($payload['exp'] ?? null) || $payload['exp'] <= time()
            || !is_int($payload['nbf'] ?? null) || $payload['nbf'] > time()+30
            || !is_int($payload['generation'] ?? null) || !is_string($payload['sub'] ?? null)) {
            throw new RuntimeException('Invalid token claims');
        }
        $subject = Store::assertSubject($payload['sub']);
        if ($this->store->generation($subject) !== $payload['generation']) {
            throw new RuntimeException('Revoked token generation');
        }
        return $subject;
    }

    public static function jwt(array $payload, string $secret): string
    {
        $a = self::b64url(json_encode(['alg'=>'HS256','typ'=>'JWT'],JSON_THROW_ON_ERROR));
        $b = self::b64url(json_encode($payload,JSON_THROW_ON_ERROR));
        return $a.'.'.$b.'.'.self::b64url(hash_hmac('sha256',$a.'.'.$b,self::jwtKey($secret),true));
    }

    private static function jwtKey(string $secret): string
    {
        return hash_hkdf('sha256', $secret, 32, 'akaunting-mcp/jwt-v1');
    }

    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function decode(string $data): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/D', $data)) {
            throw new RuntimeException('Invalid token encoding');
        }
        $raw = base64_decode(strtr($data, '-_', '+/'), true);
        $decoded = is_string($raw) ? json_decode($raw, true, 16) : null;
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid token payload');
        }
        return $decoded;
    }

    private function client(mixed $id, mixed $redirect): void
    {
        if (!is_string($id) || !is_string($redirect)
            || !hash_equals($this->config->oauthClientId,$id) || !hash_equals($this->config->oauthRedirectUri,$redirect)) {
            throw new RuntimeException('OAuth client not registered');
        }
    }

    private static function random(): string
    {
        return self::b64url(random_bytes(32));
    }

    private static function digest(string $data): string
    {
        return hash('sha256',$data);
    }
}
