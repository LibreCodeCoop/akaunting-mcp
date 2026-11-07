<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// This local-only fixture simulates Akaunting Core's controller permission
// resolver: no search=type:invoice/customer means HTTP 403, even if the JSON
// body has the right type.
$path=parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
$method=(string)($_SERVER['REQUEST_METHOD']??'GET');
$doc=is_string($path) && str_starts_with($path,'/api/documents');
$contact=is_string($path) && str_starts_with($path,'/api/contacts');
if ((!$doc && !$contact) || !in_array($method,['POST','PATCH','DELETE'],true)) {
    http_response_code(404);
    exit;
}
$type=(string)($_GET['search']??'');
if (!preg_match('/^type:(invoice|bill|customer|vendor)$/D',$type)
    || (int)($_GET['company_id']??0)!==1
    || ($doc && !in_array($type,['type:invoice','type:bill'],true))
    || ($contact && !in_array($type,['type:customer','type:vendor'],true))) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error'=>'ACL requires typed search']);
    exit;
}
$body=json_decode((string)file_get_contents('php://input'),true);
if (!is_array($body) && $method!=='DELETE') {
    http_response_code(422);
    exit;
}
http_response_code(match($method){'POST'=>201,'PATCH'=>200,'DELETE'=>204});
header('Content-Type: application/json');
if ($method!=='DELETE') {
    echo json_encode(['data'=>['type'=>substr($type,5),'method'=>$method,'path'=>$path,'company_id'=>1]],JSON_THROW_ON_ERROR);
}
