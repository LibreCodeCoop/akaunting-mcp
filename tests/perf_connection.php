<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use AkauntingMcp\Api;
use AkauntingMcp\Config;
$c = new Config('http://127.0.0.1:8875','https://localhost/issuer',
    'https://api.openai.com/v1/mcp/tunnel_test','synthetic',
    'https://chatgpt.com/connector/oauth/test',sys_get_temp_dir(),
    random_bytes(32),['localhost'],true);
$api = new Api($c);
$api->verify('test@example.invalid','synthetic-password',1);
$first = $api->get('test@example.invalid','synthetic-password','/categories',
    ['company_id'=>1,'page'=>1,'limit'=>1]);
if (($first['data'][0]['name'] ?? '')!=='Synthetic category') {
    throw new RuntimeException('Compressed JSON decoding failed');
}
echo "authorized_read_after_membership=PASS\n";
echo "gzip_decode_and_company_data=PASS\n";
try {
    $api->get('test@example.invalid','synthetic-password','/categories',
        ['company_id'=>1,'search'=>'simulate429']);
    throw new RuntimeException('HTTP 429 not blocked');
} catch (RuntimeException $e) {
    if (!str_contains($e->getMessage(),'HTTP 429')) throw $e;
}
$after = $api->get('test@example.invalid','synthetic-password','/categories',
    ['company_id'=>1,'limit'=>1]);
if (($after['data'][0]['company_id']??null)!==1) {
    throw new RuntimeException('Handle not reusable after 429');
}
echo "http_429_recovery=PASS\n";
try {
    $api->get('changed@example.invalid','synthetic-password','/categories',
        ['company_id'=>1]);
    throw new RuntimeException('Different identity accepted by upstream fixture');
} catch (RuntimeException $e) {
    if (!str_contains($e->getMessage(),'HTTP 401')) throw $e;
}
$restored = $api->get('test@example.invalid','synthetic-password','/categories',['company_id'=>1]);
if (($restored['data'][0]['name']??null)!=='Synthetic category') {
    throw new RuntimeException('Original identity could not read after another identity was denied');
}
echo "identity_credentials_not_reused=PASS\n";
try {
    $api->get('test@example.invalid','synthetic-password','/categories',
        ['company_id'=>1,'search'=>'simulateInflate']);
    throw new RuntimeException('Inflated gzip body was accepted');
} catch (RuntimeException $e) {
    if ($e->getMessage() !== 'Akaunting response exceeds safe size') throw $e;
}
$api->get('test@example.invalid','synthetic-password','/categories',['company_id'=>1]);
echo "gzip_expansion_size_limit=PASS\n";
try {
    $api->get('test@example.invalid','synthetic-password','/documents/1/received');
    throw new RuntimeException('GET mutator was allowed');
} catch (RuntimeException $e) {
    if ($e->getMessage()!=='API resource not permitted') throw $e;
}
echo "unsafe_get_rejected=PASS\n";
foreach ([
    'simulateRedirect'=>'HTTP 302',
    'simulateMalformedJson'=>'json-error',
    'simulateNonObjectJson'=>'Unexpected Akaunting response',
] as $search=>$failure) {
    try {
        $api->get('test@example.invalid','synthetic-password','/categories',
            ['company_id'=>1,'search'=>$search]);
        throw new RuntimeException('Malformed upstream response unexpectedly accepted');
    } catch (RuntimeException|JsonException $error) {
        $message=$error->getMessage();
        if ($failure === 'json-error') {
            if (!$error instanceof JsonException) throw $error;
        } elseif (!str_contains($message,$failure)) {
            throw $error;
        }
    }
}
$success=$api->get('test@example.invalid','synthetic-password','/categories',['company_id'=>1]);
if (($success['data'][0]['id']??null)!==11) {
    throw new RuntimeException('API connection did not recover after malformed responses');
}
echo "upstream_redirect_and_malformed_payload_rejected=PASS\n";
