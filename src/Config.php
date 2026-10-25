<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace AkauntingMcp;

use RuntimeException;

final readonly class Config
{
    public function __construct(
        public string $akauntingUrl,
        public string $issuer,
        public string $resource,
        public string $oauthClientId,
        public string $oauthRedirectUri,
        public string $dataDir,
        public string $encryptionKey,
        public array $allowedHosts,
        public bool $allowLoopbackHttp = false,
    ) {}

    public static function fromEnvironment(): self
    {
        $base = rtrim(self::required('AKAUNTING_BASE_URL'), '/');
        $issuer = rtrim(self::required('MCP_OAUTH_ISSUER'), '/');
        $resource = self::required('MCP_RESOURCE_URI');
        $clientId = self::required('MCP_OAUTH_CLIENT_ID');
        $redirect = self::required('MCP_OAUTH_REDIRECT_URI');
        $dataDir = self::required('MCP_DATA_DIR');
        $keyFile = self::required('MCP_TOKEN_ENCRYPTION_KEY_FILE');
        $key = @file_get_contents($keyFile);
        if (!is_string($key) || ($decoded = base64_decode(trim($key), true)) === false || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('Invalid encryption key file');
        }
        $testInsecure = getenv('APP_ENV') === 'testing' && getenv('AKAUNTING_ALLOW_INSECURE_LOOPBACK') === 'true';
        self::validateOrigin($base, $testInsecure);
        self::validateOrigin($issuer);
        $path = (string) parse_url($issuer, PHP_URL_PATH);
        if (!preg_match('~^/[a-z0-9-]+$~D', $path) || $path === '/oauth') {
            throw new RuntimeException('OAuth issuer needs a dedicated path');
        }
        if (parse_url($issuer, PHP_URL_HOST) !== parse_url($base, PHP_URL_HOST) && getenv('MCP_ALLOW_SEPARATE_ISSUER_HOST') !== 'true') {
            throw new RuntimeException('OAuth issuer host must match Akaunting host unless explicitly configured');
        }
        if (!filter_var($resource, FILTER_VALIDATE_URL) || parse_url($resource, PHP_URL_SCHEME) !== 'https'
            || parse_url($resource, PHP_URL_USER) !== null || parse_url($resource, PHP_URL_PASS) !== null
            || parse_url($resource, PHP_URL_QUERY) !== null || parse_url($resource, PHP_URL_FRAGMENT) !== null) {
            throw new RuntimeException('Invalid MCP resource URL');
        }
        if (!filter_var($redirect, FILTER_VALIDATE_URL) || parse_url($redirect, PHP_URL_SCHEME) !== 'https'
            || parse_url($redirect, PHP_URL_USER) !== null || parse_url($redirect, PHP_URL_PASS) !== null) {
            throw new RuntimeException('Invalid OAuth redirect URI');
        }
        if (getenv('APP_ENV') === 'production') {
            $tunnelId = getenv('OPENAI_TUNNEL_ID');
            if (!is_string($tunnelId) || !preg_match('/^tunnel_(?:[a-z0-9]{4}_)?[a-z0-9]{32}$/D', $tunnelId)
                || $resource !== 'https://api.openai.com/v1/mcp/'.$tunnelId) {
                throw new RuntimeException('Production resource must match OpenAI Secure MCP Tunnel');
            }
        }
        if (!is_dir($dataDir) && !mkdir($dataDir, 0700, true) && !is_dir($dataDir)) {
            throw new RuntimeException('Cannot create private data directory');
        }
        @chmod($dataDir, 0700);
        return new self($base, $issuer, $resource, $clientId, $redirect, $dataDir, $decoded,
            array_values(array_filter(array_map('trim', explode(',', getenv('MCP_ALLOWED_HOSTS') ?: 'localhost,127.0.0.1,nginx')))), $testInsecure);
    }

    private static function validateOrigin(string $value, bool $allowLoopback = false): void
    {
        $parts = parse_url($value);
        $host = parse_url($value, PHP_URL_HOST);
        $scheme = parse_url($value, PHP_URL_SCHEME);
        if (!is_array($parts) || !is_string($host) || !preg_match('/^[a-z0-9.-]+$/iD', $host)
            || !is_string($scheme)
            || !in_array($scheme, ['https', ...($allowLoopback ? ['http'] : [])], true)
            || ($scheme === 'http' && !in_array($host, ['localhost', '127.0.0.1'], true))
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ($parts['path'] ?? '') !== '' && !preg_match('~^/(?:[a-zA-Z0-9_-]+/?)*$~D', (string)$parts['path'])) {
            throw new RuntimeException('Invalid configured origin');
        }
    }

    private static function required(string $name): string
    {
        $value = getenv($name);
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException('Missing environment variable '.$name);
        }
        return trim($value);
    }
}
