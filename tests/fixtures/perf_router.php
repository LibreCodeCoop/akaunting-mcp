<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
header('Content-Type: application/json');
header('Cache-Control: no-store');
if (($_SERVER['PHP_AUTH_USER'] ?? '') !== 'test@example.invalid'
    || ($_SERVER['PHP_AUTH_PW'] ?? '') !== 'synthetic-password') {
    http_response_code(401);
    echo '{"error":"invalid_login"}';
    exit;
}
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if ($path === '/api/companies/1/owner') {
    http_response_code(200);
    exit;
}
if ($path==='/api/contacts/7' || $path==='/api/contacts') {
    $method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
    $state=getenv('MCP_SMOKE_MUTATION_STATE') ?: '';
    $query=[];
    parse_str((string)parse_url($_SERVER['REQUEST_URI'] ?? '',PHP_URL_QUERY),$query);
    if (($query['company_id']??'')!=='1') {
        http_response_code(403);echo '{"error":"foreign_company"}';exit;
    }
    if ($path==='/api/contacts/7' && $method==='GET') {
        echo json_encode(['data'=>['id'=>7,'company_id'=>1,'type'=>'vendor','name'=>'Synthetic member']],JSON_THROW_ON_ERROR);
        exit;
    }
    if (($method==='PATCH' && $path==='/api/contacts/7')
        || ($method==='POST' && $path==='/api/contacts')
        || ($method==='DELETE' && $path==='/api/contacts/7')) {
        $input=json_decode((string)file_get_contents('php://input'),true,16);
        if (!is_array($input) || ($input['company_id']??null)!==1
            || ($input['type']??'vendor')!=='vendor') {
            http_response_code(422);echo '{"error":"invalid_payload"}';exit;
        }
        if ($state==='') {http_response_code(500);exit;}
        $old=is_file($state)?json_decode((string)file_get_contents($state),true):[];
        $old[]= ['method'=>$method,'path'=>$path,'body'=>$input];
        file_put_contents($state,json_encode($old,JSON_THROW_ON_ERROR),LOCK_EX);
        if ($method==='DELETE') {http_response_code(204);exit;}
        echo json_encode(['data'=>['id'=>7,'company_id'=>1,'type'=>'vendor','name'=>$input['name']??'Synthetic member']],JSON_THROW_ON_ERROR);
        exit;
    }
    http_response_code(405);exit;
}if ($path !== '/api/categories') {
    http_response_code(404);
    echo '{"error":"not_found"}';
    exit;
}
parse_str((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY), $query);
if (($query['search'] ?? '') === 'simulate429') {
    http_response_code(429);
    echo '{"error":"rate_limited"}';
    exit;
}
if (($query['search'] ?? '') === 'simulateRedirect') {
    header('Location: http://attacker.example.invalid/internal', true, 302);
    exit;
}
if (($query['search'] ?? '') === 'simulateMalformedJson') {
    echo '{"data":';
    exit;
}
if (($query['search'] ?? '') === 'simulateNonObjectJson') {
    echo '"string_is_not_a_resource"';
    exit;
}
$payload = json_encode(['data'=>[['id'=>11,'company_id'=>1,
    'name'=>'Synthetic category',
    ...(($query['search'] ?? '') === 'simulateInflate'
        ? ['large'=>str_repeat('X',2_100_000)] : [])]],
    'meta'=>['total'=>1]],JSON_THROW_ON_ERROR);
if (str_contains((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''),'gzip')) {
    header('Content-Encoding: gzip');
    echo gzencode($payload);
} else {
    echo $payload;
}
