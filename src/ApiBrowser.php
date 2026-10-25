<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace AkauntingMcp;

use RuntimeException;

/**
 * Typed, GET-only access to the API routes actually installed in Akaunting,
 * with native user ACL and a second explicit company/response boundary.
 * No arbitrary URLs, API path fragments, GET actions with side effects,
 * unsafe settings dump, or raw users endpoint exposed to the model.
 */
final class ApiBrowser
{
    private readonly RouteCatalog $catalog;

    public function __construct(private readonly Api $api)
    {
        $this->catalog=$api->routeCatalog();
    }

    public function catalog(): array
    {
        $routes = $this->catalog->list();
        return [
            'registered_read_routes'=>$routes,
            'count'=>count($routes),
            'mutations_enabled'=>false,
            'permission_note'=>'Registration is not proof of authorization; each request uses the linked personal user ACL and selected company.',
            'sensitive_resources'=>'Users and settings have additional tenant and secret filtering.',
        ];
    }

    public function read(array $creds, int $company, array $args): array
    {
        // Validate before any upstream API call; apply only after tenant and
        // secret checks so projection cannot weaken authorization.
        $fields = RecordProjection::validate($args['fields'] ?? null);
        $name=$args['route'] ?? null;
        if (!is_string($name) || strlen($name)>120) throw new RuntimeException('Missing API route identifier');
        $params=$args['route_params'] ?? [];
        if (!is_array($params) || (array_is_list($params) && $params!==[])) {
            throw new RuntimeException('Invalid path parameters');
        }
        $route=$this->catalog->resolve($name,$params);
        $page=$args['page'] ?? 1;
        $limit=$args['limit'] ?? 25;
        $search=$args['search'] ?? '';
        $type=$args['record_type'] ?? null;
        if (!is_int($page) || $page<1 || $page>10000 || !is_int($limit)
            || $limit<1 || $limit>50 || !is_string($search)
            || strlen($search)>200 || preg_match('/[\x00-\x1F\x7F]/',$search)) {
            throw new RuntimeException('Invalid API pagination or filters');
        }
        $hasType=str_starts_with($name,'api.documents.') || str_starts_with($name,'api.contacts.');
        if ($hasType) {
            if (!is_string($type) || !preg_match('/^[a-z][a-z0-9_-]{0,49}$/D',$type)
                || stripos($search,'type:')!==false) {
                throw new RuntimeException('Document/contact type required for ACL');
            }
            $search='type:'.$type.($search!==''?' '.$search:'');
        } elseif ($type!==null) {
            throw new RuntimeException('Record type not applicable to API route');
        }
        $query=['company_id'=>$company,'page'=>$page,'limit'=>$limit];
        if ($search!=='') $query['search']=$search;
        if (str_starts_with($name,'api.translations.')) {
            if ($fields !== null) {
                throw new RuntimeException('Field selection not supported for translations');
            }
            // Localization is not tenant-owned business data, but the user is
            // still authenticated to the native API and an assigned company.
            $value=$this->api->get($creds['email'],$creds['password'],$route['path'],
                ['company_id'=>$company]);
            return ReadResponseGuard::redact(['route'=>$name,'data'=>$value]);
        }

        if ($name==='api.companies.show' && ($params['company'] ?? null)!==$company) {
            throw new RuntimeException('Requested company does not match selected company');
        }

        if (str_starts_with($name,'api.documents.transactions.')) {
            $docId=$params['document'] ?? null;
            if (!is_int($docId)) throw new RuntimeException('Missing document ID');
            // Native nested-controller ACL may not validate the parent record.
            // Independently verify the document and its type before retrieving
            // any transactions or nested details.
            $parent=$this->api->get($creds['email'],$creds['password'],'/documents/'.$docId,
                ['company_id'=>$company,'search'=>'type:'.$type]);
            $this->assertCompany($parent,$company,'api.documents.show',$type);
        }

        $value=$this->api->get($creds['email'],$creds['password'],$route['path'],$query);
        if ($name==='api.settings.index' || $name==='api.settings.show') {
            return RecordProjection::apply(
                SettingsScopeFilter::apply($value,$company,$name==='api.settings.show'), $fields
            );
        }
        if ($name==='api.users.index') {
            return RecordProjection::apply(UserScopeFilter::page($value,$company,$page), $fields);
        }
        if ($name==='api.users.show') {
            $row=$value['data'] ?? null;
            if (!is_array($row) || array_is_list($row)) {
                throw new RuntimeException('Invalid API user detail');
            }
            $filtered=UserScopeFilter::page(['data'=>[$row]],$company,1);
            if (count($filtered['data'])!==1) throw new RuntimeException('User not in requested company');
            return RecordProjection::apply(['company_id'=>$company,'data'=>$filtered['data'][0]], $fields);
        }
        $this->assertCompany($value,$company,$name,$hasType?$type:null);
        return RecordProjection::apply(ReadResponseGuard::redact($value), $fields);
    }

    /** Fail closed when the upstream controller or module omits company IDs. */
    private function assertCompany(array $result,int $company,string $route,?string $type): void
    {
        $data=$result['data'] ?? null;
        if (!is_array($data)) throw new RuntimeException('Missing API response records');
        $rows=array_is_list($data)?$data:[$data];
        if (str_starts_with($route,'api.companies.')) {
            // Core company collection uses user()->companies(), so its records
            // are independently scoped to user membership. The detail route
            // must match the company selected for this request.
            if ($route==='api.companies.show') {
                if (!isset($rows[0]['id']) || (string)$rows[0]['id']!==(string)$company) {
                    throw new RuntimeException('Company record mismatch');
                }
            }
            return;
        }
        foreach($rows as $row) {
            if (!is_array($row) || (string)($row['company_id'] ?? '')!==(string)$company) {
                throw new RuntimeException('Response does not belong to selected company');
            }
            if ($type!==null && !str_starts_with($route,'api.documents.transactions.')
                && ($row['type'] ?? null)!==$type) {
                throw new RuntimeException('API document/contact type mismatch');
            }
            ReadResponseGuard::assertNestedCompany($row, $company);
        }
    }

}
