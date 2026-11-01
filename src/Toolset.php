<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace AkauntingMcp;

use RuntimeException;

/** Company-authorized reads and independently approved mutation proposals. */
final class Toolset
{
    /** Readable core REST resources; write paths deliberately absent. */
    public const RESOURCES = [
        'companies', 'dashboards', 'items', 'contacts', 'documents',
        'accounts', 'reconciliations', 'transactions', 'transfers', 'reports',
        'categories', 'currencies', 'taxes',
        'departments', 'dismissals', 'employees',
    ];

    public function __construct(private readonly Store $store, private readonly Api $api) {}

    public function definitions(): array
    {
        $company = ['type'=>'integer','minimum'=>1,'description'=>'Akaunting company ID. Access verified for the authenticated user.'];
        $resource = ['type'=>'string','enum'=>self::RESOURCES];
        $fieldSelection = ['type'=>'array','minItems'=>1,'maxItems'=>12,'uniqueItems'=>true,
            'items'=>['type'=>'string','pattern'=>'^[a-zA-Z][a-zA-Z0-9_]{0,49}$'],
            'description'=>'Optional top-level record fields; id, company_id and type are kept. Omitting fields returns the full authorized record.'];
        $readAnnotations = ['readOnlyHint'=>true,'destructiveHint'=>false,'idempotentHint'=>true,'openWorldHint'=>true];
        $tools = [
            ['name'=>'akaunting_whoami', 'description'=>'Verify the personal Akaunting identity linked to this MCP session. No financial records.', 'inputSchema'=>['type'=>'object','properties'=>new \stdClass()]],
            ['name'=>'akaunting_resources', 'description'=>'List supported core REST resource types and their API coverage, not proof of user permission.', 'inputSchema'=>['type'=>'object','properties'=>new \stdClass()]],
            ['name'=>'akaunting_list_companies', 'description'=>'List only companies returned by the current user\'s Akaunting API permissions, with pagination.', 'inputSchema'=>['type'=>'object','properties'=>[
                'page'=>['type'=>'integer','minimum'=>1,'maximum'=>10000], 'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>50],
            ]]],
            ['name'=>'akaunting_list_records', 'description'=>'Read authorized records using filters and pagination; no changes.', 'inputSchema'=>[
                'type'=>'object', 'properties'=>[
                    'resource'=>$resource, 'company_id'=>$company,
                    'page'=>['type'=>'integer','minimum'=>1,'maximum'=>10000],
                    'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>50],
                    'search'=>['type'=>'string','maxLength'=>200],
                    'record_type'=>['type'=>'string','description'=>'Required for contacts/documents; e.g. customer, vendor, invoice or bill. Upstream ACL is type-sensitive.'],
                    'fields'=>$fieldSelection,
                ], 'required'=>['resource','company_id'],
            ]],
            ['name'=>'akaunting_get_record', 'description'=>'Retrieve one authorized core REST record by numeric ID.', 'inputSchema'=>[
                'type'=>'object','properties'=>['resource'=>$resource,'company_id'=>$company,'record_id'=>['type'=>'integer','minimum'=>1],
                    'record_type'=>['type'=>'string','description'=>'Required for contacts/documents to apply the right upstream ACL.'],
                    'fields'=>$fieldSelection],
                'required'=>['resource','company_id','record_id'],
            ]],
            ['name'=>'akaunting_probe_resource', 'description'=>'Check resource accessibility for the personal account; returns only status, never record contents.', 'inputSchema'=>[
                'type'=>'object','properties'=>['resource'=>$resource,'company_id'=>$company,
                    'record_type'=>['type'=>'string','description'=>'Required for contacts/documents.']], 'required'=>['resource','company_id'],
            ]],
            ['name'=>'akaunting_list_users', 'description'=>'List company-assigned Akaunting users and their role names only when the personal account has native read-auth-users permission. Filters upstream cross-company entries and suppresses unsafe global totals; does not expose full effective permissions.', 'inputSchema'=>[
                'type'=>'object','properties'=>['company_id'=>$company,
                    'page'=>['type'=>'integer','minimum'=>1,'maximum'=>10000],
                    'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>25]],
                'required'=>['company_id'],
            ]],
            ['name'=>'akaunting_api_routes',
                'description'=>'Discover reviewed GET-only native and installed-module API routes with required permission hints. Route listing does not prove the current account is authorized.',
                'inputSchema'=>['type'=>'object','properties'=>['company_id'=>$company],
                    'required'=>['company_id']]],
            ['name'=>'akaunting_api_read',
                'description'=>'Read an API route selected by its registered name and exact typed parameters. Native user ACL, company membership, cross-tenant filtering and secret redaction apply. GET only; mutating GET routes are excluded.',
                'inputSchema'=>['type'=>'object','properties'=>[
                    'company_id'=>$company,
                    'route'=>['type'=>'string','maxLength'=>120,'description'=>'Exact name returned by akaunting_api_routes; e.g. api.departments.index or api.documents.transactions.show.'],
                    'route_params'=>['type'=>'object','description'=>'Route placeholder names to positive IDs; translations use locale/file strings.'],
                    'page'=>['type'=>'integer','minimum'=>1,'maximum'=>10000],
                    'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>50],
                    'search'=>['type'=>'string','maxLength'=>200],
                    'record_type'=>['type'=>'string','description'=>'Required for contacts, documents and nested document transactions.'],
                    'fields'=>$fieldSelection,
                ], 'required'=>['company_id','route']]],

        ];
        foreach ($tools as &$tool) {
            $tool['annotations'] = $readAnnotations;
            $tool['securitySchemes'] = [['type'=>'oauth2', 'scopes'=>['mcp:read']]];
            $tool['inputSchema']['additionalProperties'] = false;
        }
        unset($tool);
        $writeRoutes = [
            ['name'=>'akaunting_mutation_routes',
                'description'=>'List the 57 reviewed native create/update/delete API routes and their methods. Listing does not authorize any changes.',
                'inputSchema'=>['type'=>'object','properties'=>['company_id'=>$company], 'required'=>['company_id']]],
            ['name'=>'akaunting_prepare_mutation',
                'description'=>'PREPARE ONLY: display an exact proposed Akaunting change and create a short-lived browser approval link. Does NOT change financial records. Each subsequent change requires separate human review, reauthentication and consent in the independent browser.',
                'inputSchema'=>['type'=>'object','properties'=>[
                    'company_id'=>$company,
                    'route'=>['type'=>'string','maxLength'=>120],
                    'method'=>['type'=>'string','enum'=>['POST','PUT','PATCH','DELETE']],
                    'route_params'=>['type'=>'object','description'=>'Exact registered path parameters as positive integer IDs.'],
                    'body'=>['type'=>'object','description'=>'Exact JSON object of proposed changes; never arbitrary URL paths.'],
                    'record_type'=>['type'=>'string','description'=>'Required for contacts/documents; e.g. vendor, customer, invoice, bill.'],
                ],'required'=>['company_id','route','method']]],
            ['name'=>'akaunting_mutation_status',
                'description'=>'Read status of one prepared mutation. Only an independent authenticated browser approval can cause execution; the assistant cannot approve or submit changes.',
                'inputSchema'=>['type'=>'object','properties'=>[
                    'company_id'=>$company,
                    'request_id'=>['type'=>'string','pattern'=>'^[0-9a-f]{64}$'],
                ],'required'=>['company_id','request_id']]],
        ];
        foreach ($writeRoutes as &$tool) {
            $tool['annotations']=[
                'readOnlyHint'=>$tool['name']!=='akaunting_prepare_mutation',
                'destructiveHint'=>false,
                'idempotentHint'=>$tool['name']!=='akaunting_prepare_mutation',
                'openWorldHint'=>true,
            ];
            $tool['securitySchemes']=[['type'=>'oauth2','scopes'=>['mcp:read']]];
            $tool['inputSchema']['additionalProperties']=false;
            $tools[]=$tool;
        }
        unset($tool);
        return $tools;
    }

    public function call(string $subject, string $name, array $args): array
    {
        $creds = $this->store->load($subject);
        if ($name === 'akaunting_whoami') {
            $this->api->verify($creds['email'],$creds['password'],$creds['company_id']);
            return ['authenticated'=>true, 'email'=>$creds['email'], 'company_id'=>$creds['company_id'], 'writes_enabled'=>true, 'human_approval_required'=>true];
        }
        if ($name === 'akaunting_resources') {
            return ['resources'=>self::RESOURCES, 'mutations_enabled'=>true, 'human_approval_required'=>true, 'registered_mutation_routes'=>count((new WriteRouteCatalog())->list()),
                'registered_api_read_routes'=>count((new RouteCatalog())->list()),
                'route_discovery_tool'=>'akaunting_api_routes',
                'read_tool'=>'akaunting_api_read',
                'nfse_api'=>'not_registered_in_installed_akaunting_api',
                'user_audit'=>'company_scoped_roles_without_effective_permission_details'];
        }
        $company = ($name === 'akaunting_list_companies') ? $creds['company_id'] : ($args['company_id'] ?? null);
        if (!is_int($company)) {
            throw new RuntimeException('company_id must be a number');
        }
        $this->api->verify($creds['email'],$creds['password'],Api::companyId($company));
        if (in_array($name,['akaunting_mutation_routes','akaunting_prepare_mutation','akaunting_mutation_status'],true)) {
            $approvals=new ApprovalService($this->store,$this->api,$this->api->issuer());
            if ($name==='akaunting_mutation_routes')return $approvals->routes();
            if ($name==='akaunting_prepare_mutation') {
                return $approvals->prepare($subject,$creds,$company,$args);
            }
            $request=$args['request_id'] ?? null;
            if (!is_string($request))throw new RuntimeException('Invalid mutation request ID');
            return $approvals->status($subject,$request);
        }        if ($name === 'akaunting_list_users') {
            $page = $args['page'] ?? 1;
            $limit = $args['limit'] ?? 25;
            if (!is_int($page) || $page < 1 || $page > 10000
                || !is_int($limit) || $limit < 1 || $limit > 25) {
                throw new RuntimeException('Invalid user-list pagination');
            }
            $response = $this->api->get($creds['email'], $creds['password'], '/users',
                ['company_id'=>$company, 'page'=>$page, 'limit'=>$limit]);
            return UserScopeFilter::page($response, $company, $page);
        }
        if ($name === 'akaunting_api_routes') {
            return (new ApiBrowser($this->api))->catalog();
        }
        if ($name === 'akaunting_api_read') {
            return (new ApiBrowser($this->api))->read($creds,$company,$args);
        }
        if ($name === 'akaunting_list_companies') {
            $page = $args['page'] ?? 1;
            $limit = $args['limit'] ?? 25;
            if (!is_int($page) || $page < 1 || $page > 10000 || !is_int($limit) || $limit < 1 || $limit > 50) {
                throw new RuntimeException('Invalid company pagination');
            }
            return ReadResponseGuard::redact($this->api->get($creds['email'],$creds['password'], '/companies', ['company_id'=>$company,'page'=>$page,'limit'=>$limit]));
        }
        $resource = $args['resource'] ?? null;
        if (!is_string($resource) || !in_array($resource,self::RESOURCES,true)) {
            throw new RuntimeException('Resource not permitted');
        }
        $recordType = $this->recordType($resource, $args['record_type'] ?? null);
        $fields = RecordProjection::validate($args['fields'] ?? null);
        if ($name === 'akaunting_get_record') {
            $id = $args['record_id'] ?? null;
            if (!is_int($id) || $id <= 0) {
                throw new RuntimeException('Invalid record_id');
            }
            if ($resource === 'companies' && $id !== $company) {
                throw new RuntimeException('Company target differs from selected context');
            }
            $query = ['company_id'=>$company];
            if ($recordType !== null) $query['search']='type:'.$recordType;
            $result = $this->api->get($creds['email'],$creds['password'],'/'.$resource.'/'.$id,$query);
            $this->assertResultContext($result, $resource, $company, $recordType);
            return RecordProjection::apply(ReadResponseGuard::redact($result), $fields);
        }
        if ($name === 'akaunting_list_records' || $name === 'akaunting_probe_resource') {
            $page = $name === 'akaunting_probe_resource' ? 1 : ($args['page'] ?? 1);
            $limit = $name === 'akaunting_probe_resource' ? 1 : ($args['limit'] ?? 25);
            $search = $args['search'] ?? '';
            if (!is_int($page) || $page < 1 || $page > 10000 || !is_int($limit) || $limit < 1 || $limit > 50
                || !is_string($search) || strlen($search) > 200 || preg_match('/[\x00-\x1F\x7F]/', $search)) {
                throw new RuntimeException('Invalid paging or search parameter');
            }
            if ($recordType !== null && stripos($search,'type:') !== false) {
                throw new RuntimeException('Ambiguous record type search');
            }
            $query = ['company_id'=>$company, 'page'=>$page, 'limit'=>$limit];
            if ($recordType !== null) {
                $query['search'] = 'type:'.$recordType;
            }
            if ($name !== 'akaunting_probe_resource' && $search !== '') {
                $query['search'] = isset($query['search']) ? $query['search'].' '.$search : $search;
            }
            $result = $this->api->get($creds['email'],$creds['password'],'/'.$resource,$query);
            $this->assertResultContext($result, $resource, $company, $recordType);
            if ($name === 'akaunting_probe_resource') {
                return ['resource'=>$resource, 'company_id'=>$company, 'accessible'=>true];
            }
            return RecordProjection::apply(ReadResponseGuard::redact($result), $fields);
        }
        throw new RuntimeException('Unknown MCP tool');
    }

    /** Akaunting's contacts/documents ACL is selected from search=type:<type>. */
    private function recordType(string $resource, mixed $value): ?string
    {
        if (!in_array($resource,['contacts','documents'],true)) {
            if ($value !== null) throw new RuntimeException('Record type only applies to contacts/documents');
            return null;
        }
        if (!is_string($value) || !preg_match('/^[a-z][a-z0-9_-]{0,49}$/D',$value)) {
            throw new RuntimeException('contacts/documents require a valid record_type');
        }
        return $value;
    }

    /** Fail closed on an upstream type/tenant mismatch, including API controller ID lookups. */
    private function assertResultContext(array $result, string $resource, int $company, ?string $recordType): void
    {
        $data = $result['data'] ?? null;
        if (!is_array($data)) throw new RuntimeException('Missing API resource data');
        $rows = array_is_list($data) ? $data : [$data];
        foreach ($rows as $row) {
            if (!is_array($row)) throw new RuntimeException('Invalid API resource record');
            // The upstream users endpoint is deliberately excluded: its collection
            // returns users belonging only to other companies. The remaining
            // record resources must declare their company_id explicitly.
            if ($resource !== 'companies' && (!array_key_exists('company_id', $row)
                || (string)$row['company_id'] !== (string)$company)) {
                throw new RuntimeException('Upstream tenant mismatch');
            }
            if ($resource !== 'companies') {
                ReadResponseGuard::assertNestedCompany($row, $company);
            }
            if ($recordType !== null && ($row['type'] ?? null) !== $recordType) {
                throw new RuntimeException('Upstream record type mismatch');
            }
        }
    }

}
