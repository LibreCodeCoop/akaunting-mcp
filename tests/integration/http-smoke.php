<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

/** Synthetic HTTP smoke; runs inside a container with --network none. */
function requireCheck(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('Smoke failure: '.$label);
    }
    echo $label."=PASS\n";
}

/** @return array{0:int,1:string,2:string} */
function httpRequest(string $path, string $method = 'GET', ?string $body = null, array $headers = []): array
{
    $curl = curl_init('http://127.0.0.1:8876'.$path);
    if (!$curl instanceof CurlHandle) {
        throw new RuntimeException('Smoke HTTP initialization failed');
    }
    $hostDefined = count(array_filter($headers, static fn(string $h): bool => str_starts_with(strtolower($h), 'host:'))) > 0;
    $requestHeaders = [...($hostDefined ? [] : ['Host: localhost']), ...$headers];
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $requestHeaders,
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }
    $raw = curl_exec($curl);
    if (!is_string($raw)) {
        throw new RuntimeException('Smoke connection failed');
    }
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $length = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    return [$status, substr($raw, 0, $length), substr($raw, $length)];
}

/** @return array<string,mixed> */
function jsonMessage(string $path, array $message, array $headers = []): array
{
    [$code, , $body] = httpRequest($path, 'POST', json_encode($message, JSON_THROW_ON_ERROR),
        ['Content-Type: application/json', ...$headers]);
    requireCheck($code === 200, 'http_json_rpc_status');
    return json_decode($body, true, 32, JSON_THROW_ON_ERROR);
}

[$status, , $metadata] = httpRequest('/.well-known/oauth-authorization-server/mcp-auth');
$details = json_decode($metadata, true, 32, JSON_THROW_ON_ERROR);
requireCheck($status === 200 && ($details['code_challenge_methods_supported'] ?? null) === ['S256'], 'oauth_metadata');
[$status, , $protected] = httpRequest('/.well-known/oauth-protected-resource/mcp');
$protected = json_decode($protected, true, 32, JSON_THROW_ON_ERROR);
requireCheck($status === 200 && ($protected['scopes_supported'] ?? null) === ['mcp:read'], 'mcp_protected_metadata');
[$status] = httpRequest('/mcp');
requireCheck($status === 405, 'mcp_get_rejected');
[$status] = httpRequest('/mcp', 'POST', '{}', ['Host: attacker.example.invalid', 'Content-Type: application/json']);
requireCheck($status === 421, 'mcp_host_rejected');
[$status] = httpRequest('/mcp', 'POST', '{}', ['Origin: https://attacker.example.invalid', 'Content-Type: application/json']);
requireCheck($status === 403, 'mcp_origin_rejected');

$tools = jsonMessage('/mcp', ['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/list']);
requireCheck(count($tools['result']['tools'] ?? []) === 9, 'mcp_nine_tools');
$challenge = jsonMessage('/mcp', ['jsonrpc'=>'2.0','id'=>2,'method'=>'tools/call',
    'params'=>['name'=>'akaunting_whoami','arguments'=>new stdClass()]]);
requireCheck(($challenge['result']['isError'] ?? null) === true &&
    isset($challenge['result']['_meta']['mcp/www_authenticate']), 'oauth_linking_challenge');

$verifier = str_repeat('A', 43);
$challengeHash = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
$params = [
    'response_type'=>'code', 'client_id'=>'synthetic-client',
    'redirect_uri'=>'https://chatgpt.com/connector/oauth/synthetic',
    'scope'=>'mcp:read', 'code_challenge_method'=>'S256', 'code_challenge'=>$challengeHash,
    'resource'=>'https://api.openai.com/v1/mcp/tunnel_synthetic',
    'state'=>'synthetic-state-0123456789',
];
[$status, $responseHeaders, $html] = httpRequest('/mcp-auth/authorize?'.http_build_query($params));
preg_match('/name="ticket" value="([^"]+)"/', $html, $ticket);
preg_match('/name="csrf" value="([^"]+)"/', $html, $csrf);
preg_match('/Set-Cookie:\s*akaunting_mcp_flow=([^;\r\n]+)/i', $responseHeaders, $cookie);
requireCheck($status === 200 && isset($ticket[1], $csrf[1], $cookie[1]), 'oauth_authorization_form');

$loginBody = http_build_query([
    'ticket'=>$ticket[1], 'csrf'=>$csrf[1],
    'email'=>'test@example.invalid', 'password'=>'synthetic-password', 'company_id'=>1,
], '', '&', PHP_QUERY_RFC3986);
[$status, $responseHeaders] = httpRequest('/mcp-auth/login', 'POST', $loginBody, [
    'Content-Type: application/x-www-form-urlencoded',
    'Cookie: akaunting_mcp_flow='.$cookie[1],
]);
preg_match('/Location:\s*([^\r\n]+)/i', $responseHeaders, $location);
parse_str((string)parse_url($location[1] ?? '', PHP_URL_QUERY), $returned);
requireCheck($status === 303 && isset($returned['code'])
    && ($returned['state'] ?? null) === $params['state']
    && ($returned['iss'] ?? null) === 'https://localhost/mcp-auth', 'oauth_login_redirect');

[$status, , $body] = httpRequest('/mcp-auth/token', 'POST', http_build_query([
    'grant_type'=>'authorization_code', 'client_id'=>'synthetic-client',
    'redirect_uri'=>'https://chatgpt.com/connector/oauth/synthetic',
    'code'=>$returned['code'], 'code_verifier'=>$verifier,
], '', '&', PHP_QUERY_RFC3986), ['Content-Type: application/x-www-form-urlencoded']);
$tokens = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
requireCheck($status === 200 && is_string($tokens['access_token'] ?? null)
    && is_string($tokens['refresh_token'] ?? null), 'oauth_code_exchange');
$auth = ['Authorization: Bearer '.$tokens['access_token']];
$who = jsonMessage('/mcp', ['jsonrpc'=>'2.0','id'=>3,'method'=>'tools/call',
    'params'=>['name'=>'akaunting_whoami','arguments'=>new stdClass()]], $auth);
$identity = json_decode($who['result']['content'][0]['text'] ?? '{}',true,16,JSON_THROW_ON_ERROR);
requireCheck(($identity['authenticated'] ?? false) === true &&
    ($identity['writes_enabled'] ?? true) === false, 'oauth_authenticated_whoami');
$records = jsonMessage('/mcp', ['jsonrpc'=>'2.0','id'=>4,'method'=>'tools/call',
    'params'=>['name'=>'akaunting_list_records', 'arguments'=>[
        'company_id'=>1,'resource'=>'categories','page'=>1,'limit'=>1]]], $auth);
$record = json_decode($records['result']['content'][0]['text'] ?? '{}',true,16,JSON_THROW_ON_ERROR);
requireCheck(($record['data'][0]['company_id'] ?? null) === 1, 'mcp_paginated_company_read');
$foreign = jsonMessage('/mcp', ['jsonrpc'=>'2.0','id'=>5,'method'=>'tools/call',
    'params'=>['name'=>'akaunting_list_records','arguments'=>[
        'company_id'=>2,'resource'=>'categories','page'=>1,'limit'=>1]]], $auth);
requireCheck(($foreign['result']['isError'] ?? false) === true, 'mcp_foreign_company_rejected');

$native = jsonMessage('/mcp', [
    'jsonrpc'=>'2.0','id'=>6,'method'=>'tools/call',
    'params'=>['name'=>'akaunting_api_read','arguments'=>[
        'company_id'=>1,'route'=>'api.categories.index','limit'=>1,
    ]],
], $auth);
$nativeData = json_decode($native['result']['content'][0]['text'] ?? '{}',true,16,JSON_THROW_ON_ERROR);
requireCheck(($nativeData['data'][0]['company_id'] ?? null) === 1
    && ($nativeData['data'][0]['name'] ?? null) === 'Synthetic category',
    'native_api_read_authorized');

$unsafeRoute = jsonMessage('/mcp', [
    'jsonrpc'=>'2.0','id'=>7,'method'=>'tools/call',
    'params'=>['name'=>'akaunting_api_read','arguments'=>[
        'company_id'=>1,'route'=>'api.documents.received',
    ]],
], $auth);
requireCheck(($unsafeRoute['result']['isError'] ?? null) === true,
    'unreviewed_mutating_get_rejected');

[$status, $headers] = httpRequest(
    '/mcp-auth/authorize?'.http_build_query(array_replace($params,[
        'redirect_uri'=>'https://attacker.example.invalid/callback',
    ])),
);
requireCheck($status === 400 && !preg_match('/^Location:/mi',$headers),
    'oauth_callback_injection_no_redirect');

[$status, , $unauth] = httpRequest('/mcp','POST',json_encode([
    'jsonrpc'=>'2.0','id'=>8,'method'=>'resources/read',
    'params'=>['uri'=>'finance://private'],
],JSON_THROW_ON_ERROR),['Content-Type: application/json']);
requireCheck($status === 401 && str_contains($unauth,'Authentication required'),
    'unsupported_unauthenticated_operations_rejected');

[$status, , $oversized] = httpRequest('/mcp','POST',str_repeat('X',524289),
    ['Content-Type: application/json', ...$auth]);
$oversizedResponse = json_decode($oversized,true,16,JSON_THROW_ON_ERROR);
requireCheck($status === 200 && ($oversizedResponse['error']['code'] ?? null) === -32600,
    'oversized_mcp_request_is_bounded');

$refreshForm = [
    'grant_type'=>'refresh_token','client_id'=>'synthetic-client',
    'refresh_token'=>$tokens['refresh_token'],
];
[$status, , $refreshed] = httpRequest('/mcp-auth/token','POST',
    http_build_query($refreshForm,'','&',PHP_QUERY_RFC3986),
    ['Content-Type: application/x-www-form-urlencoded']);
$newTokens=json_decode($refreshed,true,16,JSON_THROW_ON_ERROR);
requireCheck($status === 200
    && is_string($newTokens['access_token'] ?? null)
    && ($newTokens['refresh_token'] ?? null) !== $tokens['refresh_token'],
    'refresh_token_rotates_after_authorization');

[$status, , $replayed] = httpRequest('/mcp-auth/token','POST',
    http_build_query($refreshForm,'','&',PHP_QUERY_RFC3986),
    ['Content-Type: application/x-www-form-urlencoded']);
$replayResponse=json_decode($replayed,true,16,JSON_THROW_ON_ERROR);
requireCheck($status === 400 && ($replayResponse['error'] ?? null) === 'invalid_request',
    'refresh_token_replay_rejected');
