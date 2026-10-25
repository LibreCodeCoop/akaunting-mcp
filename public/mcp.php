<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

use AkauntingMcp\Api;
use AkauntingMcp\Config;
use AkauntingMcp\Mcp;
use AkauntingMcp\OAuth;
use AkauntingMcp\Store;
use AkauntingMcp\Toolset;

require dirname(__DIR__).'/src/bootstrap.php';
ini_set('display_errors','0');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Content-Type: application/json');
try {
    $config = Config::fromEnvironment();
    $host = strtolower(explode(':',(string)($_SERVER['HTTP_HOST'] ?? ''))[0]);
    if (!in_array($host, $config->allowedHosts,true)) {
        http_response_code(421);
        exit;
    }
    if (isset($_SERVER['HTTP_ORIGIN']) && !in_array($_SERVER['HTTP_ORIGIN'],explode(',',getenv('MCP_ALLOWED_ORIGINS') ?: ''),true)) {
        http_response_code(403);
        exit;
    }
    $store = new Store($config);
    $oauth = new OAuth($config,$store,new Api($config));
    $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '',PHP_URL_PATH);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET' && $path === '/.well-known/oauth-protected-resource/mcp') {
        header('Content-Type: application/json');
        echo json_encode($oauth->protectedMetadata(), JSON_THROW_ON_ERROR);
        exit;
    }
    if ($path !== '/mcp') {
        http_response_code(404);
        exit;
    }
    if ($method !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit;
    }
    $raw = file_get_contents('php://input', false, null, 0, 524289);
    if (!is_string($raw)) {
        throw new RuntimeException('Missing request body');
    }
    $resource = parse_url($config->resource);
    $metadataUrl = $resource['scheme'].'://'.$resource['host'].'/.well-known/oauth-protected-resource'.($resource['path'] ?? '');
    $request = json_decode($raw, true, 24);
    // Handshake and tool metadata are public to the private tunnel only.
    // Financial records remain inaccessible until personal OAuth completes.
    $discoveryOrLinking = is_array($request) && ($request['jsonrpc'] ?? null) === '2.0'
        && in_array($request['method'] ?? null,
            ['initialize','ping','tools/list','tools/call','notifications/initialized'], true);
    $subject = null;
    try {
        $subject = $oauth->bearer($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    } catch (\Throwable) {
        if (!$discoveryOrLinking) {
            http_response_code(401);
            header('WWW-Authenticate: Bearer resource_metadata="'.$metadataUrl.'", error="invalid_token", scope="mcp:read"');
            echo '{"error":"Authentication required"}';
            exit;
        }
    }
    $result = (new Mcp(new Toolset($store,new Api($config)), $metadataUrl))->execute($subject,$raw);
    if ($result === null) {
        http_response_code(202);
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode($result,JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
} catch (\Throwable $error) {
    // Never expose upstream HTTP errors, authentication material or financial data.
    error_log('Akaunting MCP internal error: '.get_class($error));
    http_response_code(500);
    header('Content-Type: application/json');
    echo '{"error":"MCP server unavailable"}';
}
