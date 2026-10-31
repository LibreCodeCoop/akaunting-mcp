<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

/**
 * Validate the synthetic Docker Compose topology and OAuth/MCP responses.
 * This runs in a network-disabled PHP CLI container; no business data is used.
 */
function smokeExpect(bool $ok, string $description): void
{
    if (!$ok) {
        throw new RuntimeException('Synthetic Docker smoke failed: ' . $description);
    }
}

function smokeJson(string $mode): array
{
    $source = $mode === 'config' ? 'php://stdin' : match ($mode) {
        'resource', 'oauth', 'tools' => '/smoke/' . $mode . '.json',
        default => throw new InvalidArgumentException('Unknown smoke contract'),
    };
    $contents = file_get_contents($source);
    if (!is_string($contents)) {
        throw new RuntimeException('Cannot read smoke fixture');
    }
    $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException('Expected JSON object');
    }
    return $data;
}

function checkDockerSmoke(string $mode, array $data): void
{
    if ($mode === 'config') {
        $root = getenv('SMOKE_DIR');
        $proxy = getenv('MCP_PROXY_NETWORK');
        smokeExpect(is_string($root) && $root !== '' && is_string($proxy) && $proxy !== '',
            'missing synthetic context');
        smokeExpect(($data['services']['app']['image'] ?? null) === 'akaunting-mcp:ci', 'test application image');
        smokeExpect(($data['networks']['backend']['internal'] ?? null) === true, 'private Docker backend');
        smokeExpect(($data['networks']['backend']['ipam']['config'][0]['subnet'] ?? null) ===
            getenv('MCP_SMOKE_BACKEND_SUBNET'), 'synthetic backend subnet');
        smokeExpect(($data['networks']['existing_proxy']['name'] ?? null) === $proxy, 'isolated synthetic proxy');
        foreach (['app', 'nginx', 'oauth-edge'] as $service) {
            smokeExpect(empty($data['services'][$service]['ports']), 'host ports on ' . $service);
            smokeExpect(($data['services'][$service]['read_only'] ?? null) === true,
                'read-only filesystem on ' . $service);
        }
        smokeExpect(!isset($data['services']['tunnel']), 'private tunnel never started');
        smokeExpect(($data['secrets']['mcp_token_key']['file'] ?? null) === $root . '/secrets/mcp_token_key',
            'synthetic encryption secret path');
        smokeExpect(str_ends_with((string) ($data['services']['app']['environment']['AKAUNTING_BASE_URL'] ?? ''),
            '.invalid'), 'synthetic upstream only');
        foreach (($data['services']['app']['volumes'] ?? []) as $volume) {
            smokeExpect(($volume['type'] ?? '') !== 'bind', 'no private bind-mounted state');
        }
        return;
    }
    if ($mode === 'resource') {
        smokeExpect(($data['resource'] ?? null) ===
            'https://api.openai.com/v1/mcp/tunnel_0123456789abcdef0123456789abcdef', 'resource audience');
        smokeExpect(($data['authorization_servers'] ?? null) ===
            ['https://accounting.example.invalid/akaunting-mcp-auth'], 'OAuth issuer reference');
        smokeExpect(($data['scopes_supported'] ?? null) === ['mcp:read'], 'read scope');
        return;
    }
    if ($mode === 'oauth') {
        smokeExpect(($data['issuer'] ?? null) ===
            'https://accounting.example.invalid/akaunting-mcp-auth', 'OAuth issuer');
        smokeExpect(($data['code_challenge_methods_supported'] ?? null) === ['S256'], 'PKCE methods');
        smokeExpect(($data['scopes_supported'] ?? null) === ['mcp:read'], 'OAuth scope');
        return;
    }
    if ($mode === 'tools') {
        smokeExpect(!isset($data['error']), 'no MCP tool discovery error');
        smokeExpect(is_array($data['result']['tools'] ?? null)
            && count($data['result']['tools']) === 12, 'exactly twelve MCP tools');
        return;
    }
    throw new InvalidArgumentException('Unknown smoke contract');
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        $arguments = $_SERVER['argv'] ?? [];
        if (PHP_SAPI !== 'cli' || count($arguments) !== 2) {
            throw new InvalidArgumentException('Usage: php tests/integration/check-docker-smoke.php MODE');
        }
        checkDockerSmoke((string) $arguments[1], smokeJson((string) $arguments[1]));
        echo "Smoke {$arguments[1]}: PASS\n";
    } catch (Throwable $error) {
        fwrite(STDERR, 'Smoke fixture validation failed: ' . get_class($error) . "\n");
        exit(1);
    }
}
