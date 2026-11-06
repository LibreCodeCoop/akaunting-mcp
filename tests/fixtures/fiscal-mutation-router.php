<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Local-only synthetic HTTP fixture. No credentials, SEFIN or business data.
$path=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
$method=$_SERVER['REQUEST_METHOD']??'';
$body=json_decode(file_get_contents('php://input'),true);
header('Content-Type: application/json');
if (($_SERVER['QUERY_STRING']??'')!=='company_id=1') {
    http_response_code(400);
    echo '{"reason":"wrong_company_query"}';
    exit;
}
if ($path==='/1/api/nfse/settings/vault/diagnostics' && $method==='POST'
    && $body===[]) {
    echo '{"state":"operational","reason":"verified","capabilities":{"network":true,"authentication":true,"kv_v2":true,"certificate_read":true,"certificate_write":true}}';
    exit;
}
if ($path==='/1/api/nfse/bulk/plan' && $method==='POST'
    && $body===['invoice_ids'=>[7,8]]) {
    echo '{"data":{"queued":[{"invoice_id":7,"company_id":1}],"blocked":[],"already_issued":[8],"not_found_or_forbidden":[]}}';
    exit;
}
if ($path==='/1/api/nfse/dps/advance' && $method==='POST'
    && $body===[
        'expected_next_number'=>'900000000000008',
        'next_number'=>'900000000000100',
        'reason'=>'Synthetic documented adjustment for test',
        'confirmed'=>true,
    ]) {
    echo '{"data":{"series":"00001","previous_next":"900000000000008","next_number":"900000000000100"}}';
    exit;
}
if ($path==='/1/api/nfse/invoices/7/refresh' && $method==='POST'
    && $body===['nfse_receipt_id'=>12,'nfse_force_ajax'=>true]) {
    echo '{"data":{"status":"synthetic_refreshed"}}';
    exit;
}
if ($path==='/1/api/nfse/bulk/dispatch' && $method==='POST'
    && $body===['invoice_ids'=>[7,8]]) {
    http_response_code(202);
    echo '{"data":{"run_id":21,"reused":false,"dispatched":2,"queued":2,"blocked":0,"already_issued":0}}';
    exit;
}
if ($path==='/1/api/nfse/settings/company-profiles/1' && $method==='PATCH'
    && $body===['fiscal_parameters'=>['emission_policy'=>'manual']]) {
    echo '{"data":{"status":"synthetic_updated"}}';
    exit;
}
if ($path==='/1/api/nfse/invoices/7/fiscal-event-evidence' && $method==='POST'
    && ($body['fiscal_group_key']??null)==='service:0107|tax:010701|mun:123|rate:5.00'
    && ($body['acknowledge']??null)===true) {
    http_response_code(201);
    echo '{"status":"attested"}';
    exit;
}
if ($path==='/1/api/nfse/invoices/7/substitute' && $method==='POST'
    && $body===[
        'nfse_substitution_receipt_id'=>12,
        'nfse_substitution_reason'=>'05',
        'nfse_substitution_description'=>'Synthetic fiscal correction for integration testing',
        'nfse_force_ajax'=>true,
    ]) {
    echo '{"data":{"status":"synthetic_substituted"}}';
    exit;
}
if ($path==='/1/api/nfse/invoices/7/reemit' && $method==='POST'
    && $body===['nfse_receipt_id'=>12,'nfse_force_ajax'=>true]) {
    echo '{"data":{"status":"synthetic_reemitted"}}';
    exit;
}
if ($path==='/1/api/nfse/invoices/7' && $method==='DELETE'
    && ($body['nfse_receipt_id']??null)===12
    && ($body['cancel_reason']??null)==='9') {
    echo '{"data":{"status":"synthetic_cancelled"}}';
    exit;
}
http_response_code(404);
echo '{"reason":"unexpected_fiscal_path"}';
