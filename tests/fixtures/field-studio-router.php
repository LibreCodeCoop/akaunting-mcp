<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Pure synthetic contract fixture. Never accesses an Akaunting database.
$method=$_SERVER['REQUEST_METHOD']??'GET';
$path=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
$query=[];
parse_str((string)parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_QUERY),$query);
if (($query['company_id']??null)!=='1'
    || ($_SERVER['PHP_AUTH_USER']??null)!=='synthetic@example.invalid'
    || ($_SERVER['PHP_AUTH_PW']??null)!=='synthetic-password') {
    http_response_code(403);
    echo '{"error":"synthetic authentication"}';
    exit;
}
header('Content-Type: application/json');
if ($path==='/api/v1/field-studio/definitions' && $method==='GET'
    && ($query['entity_type']??null)==='invoice') {
    echo '{"data":[{"id":13,"company_id":1,"entity_type":"invoice","field_key":"cost_center","version":2}]}';
    exit;
}
if ($path==='/api/v1/field-studio/definitions' && $method==='POST') {
    $body=json_decode((string)file_get_contents('php://input'),true);
    if ($body===['entity_type'=>'invoice','field_key'=>'cost_center',
        'label'=>'Cost center','field_type'=>'text']) {
        http_response_code(201);
        echo '{"data":{"id":14,"company_id":1,"entity_type":"invoice","field_key":"cost_center"}}';
        exit;
    }
}
if ($path==='/api/v1/field-studio/records/invoice/7/values' && $method==='GET') {
    echo '{"data":[{"id":13,"company_id":1,"entity_type":"invoice","field_key":"cost_center","value":"Operations","value_version":2}]}';
    exit;
}
if ($path==='/api/v1/field-studio/records/invoice/7/values' && $method==='PATCH') {
    $body=json_decode((string)file_get_contents('php://input'),true);
    if ($body===['values'=>['cost_center'=>'Sales'],'versions'=>['cost_center'=>2]]) {
        echo '{"data":[{"id":13,"company_id":1,"entity_type":"invoice","field_key":"cost_center","value":"Sales","value_version":3}]}';
        exit;
    }
}
if ($path==='/api/v1/field-studio/records/invoice/7/values/cost_center'
    && $method==='DELETE') {
    http_response_code(204);
    exit;
}
http_response_code(404);
echo '{"error":"unexpected synthetic route"}';
