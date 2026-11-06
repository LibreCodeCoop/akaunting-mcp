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
