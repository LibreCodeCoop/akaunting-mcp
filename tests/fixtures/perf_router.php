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
// Official DPS fixture: read-only GET and status values only.
if ($path === '/1/api/nfse/dps/readiness' || $path === '/1/api/nfse/dps/lookup') {
    parse_str((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY), $query);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
        || ($query['company_id'] ?? '') !== '1') {
        http_response_code(403); echo '{"error":"invalid_company"}'; exit;
    }
    if ($path === '/1/api/nfse/dps/lookup') {
        if (($query['number'] ?? '') !== '900000000000008') {
            http_response_code(422); echo '{"error":"invalid_number"}'; exit;
        }
        echo json_encode(['data'=>[
            'official_state'=>'not_found', 'number'=>'900000000000008',
            'safe_to_emit'=>false, 'exclusive_series_attested'=>false,
        ]], JSON_THROW_ON_ERROR);
        exit;
    }
    echo json_encode(['data'=>[
        'company_id'=>1, 'mechanically_ready'=>false,
        'external_reconciliation'=>'not_proven_by_this_endpoint',
        'reasons'=>['series_inactive'],
    ]], JSON_THROW_ON_ERROR);
    exit;
}
// Reviewed fiscal responses expose metadata, never raw XML or WebDAV secrets.
if (in_array($path, [
    '/api/documents/7',
    '/1/api/nfse/invoices/7/post-emission-status',
    '/1/api/nfse/settings/artifacts',
],true)) {
    parse_str((string)parse_url($_SERVER['REQUEST_URI'] ?? '',PHP_URL_QUERY),$query);
    if (($_SERVER['REQUEST_METHOD'] ?? '')!=='GET'
        || ($query['company_id']??'')!=='1') {
        http_response_code(403);
        echo '{"error":"invalid_company"}';
        exit;
    }
    if ($path==='/api/documents/7') {
        echo '{"data":{"id":7,"company_id":1,"type":"invoice"}}';
        exit;
    }
    if ($path==='/1/api/nfse/settings/artifacts') {
        echo json_encode(['data'=>[
            'configured'=>true,'host'=>'dav.example.invalid',
            'username_configured'=>true,'password_configured'=>true,
            'path_template'=>'nfse/{cnpj}/{year}',
            'filename_template'=>'{chave_acesso}',
            'store_xml'=>true,'store_pdf'=>false,
        ]],JSON_THROW_ON_ERROR);
        exit;
    }
    echo json_encode(['data'=>[
        'status'=>'emitted','poll'=>false,
        'artifacts'=>[
            'xml'=>['ready'=>true,'download_url'=>'/1/api/nfse/receipts/5/artifacts/xml'],
            'danfse'=>['ready'=>false,'download_url'=>null],
        ],
    ]],JSON_THROW_ON_ERROR);
    exit;
}
// Synthetic NFS-e route uses the module's company-prefixed HTTP contract.
if ($path === '/1/api/nfse/national-services') {
    parse_str((string) parse_url($_SERVER['REQUEST_URI'] ?? '',PHP_URL_QUERY),$query);
    if (($_SERVER['REQUEST_METHOD'] ?? '')!=='GET'
        || ($query['company_id']??'')!=='1'
        || ($query['lc116']??'')!=='0107') {
        http_response_code(403);
        echo '{"error":"invalid_tenant_or_filter"}';
        exit;
    }
    echo json_encode(['data'=>[['code'=>'0107','description'=>'Synthetic NFS-e service']]],JSON_THROW_ON_ERROR);
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
