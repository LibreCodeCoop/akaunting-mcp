<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

use AkauntingMcp\Api;
use AkauntingMcp\Config;
use AkauntingMcp\Mcp;
use AkauntingMcp\OAuth;
use AkauntingMcp\Store;
use AkauntingMcp\Toolset;
require dirname(__DIR__).'/src/bootstrap.php';

function test(bool $condition, string $label): void {
    if (!$condition) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
function mustFail(Closure $fn, string $label): void {
    try { $fn(); } catch (Throwable) {test(true,$label);return;}
    test(false, $label);
}

final class FakeApi extends Api
{
    public array $calls=[];
    public ?string $typeOverride = null;
    public ?int $companyOverride = null;
    public bool $omitCompanyId = false;
    public bool $foreignNestedCompany = false;
    public bool $rateLimitNextMembership = false;
    public mixed $onMembership = null;
    public function get(string $email,string $password,string $path,array $query=[]): array {
        $this->calls[] = [$email, $path, $query];
        if ($email !== 'employee@example.invalid' || $password !== 'correct-horse-battery') throw new RuntimeException('unauthorized');
        $company = $query['company_id'] ?? 0;
        if (!in_array($company,[1,3],true)) throw new RuntimeException('unauthorized company');
        if ($path === '/companies/'.$company.'/owner') {
            if ($this->onMembership !== null) {
                $callback = $this->onMembership;
                $this->onMembership = null;
                $callback();
            }
            if ($this->rateLimitNextMembership) {
                $this->rateLimitNextMembership = false;
                throw new RuntimeException('Akaunting request rejected (HTTP 429)');
            }
            return [];
        }
        if ($path === '/companies') return ['data'=>[['id'=>1,'name'=>'One'],['id'=>3,'name'=>'Three']]];
        if ($path === '/users') return [
            'data'=>[
                ['id'=>21,'name'=>'Assigned user','email'=>'assigned@example.invalid',
                    'enabled'=>true,'password'=>'must-not-leak',
                    'companies'=>['data'=>[['id'=>1],['id'=>3]]],
                    'roles'=>['data'=>[['id'=>7,'name'=>'Manager','code'=>'manager','private_token'=>'never-leak']]]],
                ['id'=>22,'name'=>'Unassigned user','email'=>'unassigned@example.invalid',
                    'enabled'=>true,'companies'=>['data'=>[['id'=>3]]],
                    'roles'=>['data'=>[['id'=>8,'name'=>'Administrator','code'=>'admin']]]],
                ['id'=>23,'name'=>'Unknown memberships','email'=>'unknown@example.invalid',
                    'enabled'=>true,'roles'=>['data'=>[]]],
            ],
            'links'=>['next'=>null], 'meta'=>['total'=>100],
        ];
        if ($path === '/users/21') {
            $users = $this->get($email,$password,'/users',$query);
            return ['data'=>$users['data'][0]];
        }
        if ($path === '/users/22') {
            $users = $this->get($email,$password,'/users',$query);
            return ['data'=>$users['data'][1]];
        }
        if ($path === '/settings' || $path === '/settings/5') {
            $rows=[
                ['id'=>5,'company_id'=>1,'key'=>'company.name','value'=>'Synthetic Company'],
                ['id'=>6,'company_id'=>1,'key'=>'company.secret_token','value'=>'TOP-SECRET-FIXTURE'],
                ['id'=>7,'company_id'=>3,'key'=>'company.name','value'=>'Other tenant'],
                ['id'=>8,'company_id'=>1,'key'=>'default.currency','value'=>'BRL'],
            ];
            return ['data'=>$path==='/settings/5'?$rows[0]:$rows,
                'meta'=>['total'=>4]];
        }
        if (preg_match('~^/(?:departments|employees|dismissals)(?:/[1-9][0-9]*)?$~D',$path)) {
            return ['data'=>[['id'=>12,'company_id'=>$this->companyOverride ?? $company,
                'name'=>'Synthetic module entry','authorization_token'=>'must-not-leak']]];
        }
        if ($path === '/documents/7/transactions') {
            return ['data'=>[['id'=>41,'company_id'=>$this->companyOverride ?? $company,
                'amount'=>'12.00','access_token'=>'must-not-leak']]];
        }
        if (str_starts_with($path,'/translations/')) return ['data'=>['sample'=>'Translated']];
        if (preg_match('~^/documents~',$path)) { if (!str_starts_with((string)($query['search']??''),'type:invoice')) throw new RuntimeException('missing type discriminator'); $row = ['id'=>7,'company_id'=>$this->companyOverride ?? $company,'type'=>$this->typeOverride ?? 'invoice','name'=>'Synthetic invoice','password'=>'must-not-leak','contact'=>['access_token'=>'must-not-leak','company_id'=>$this->foreignNestedCompany ? 3 : $company]]; if ($this->omitCompanyId) unset($row['company_id']); return ['data'=>[$row]]; }
        return ['data'=>[]];
    }
}

$dir = sys_get_temp_dir().'/akaunting-mcp-tests-'.bin2hex(random_bytes(6));
mkdir($dir,0700,true);
try {
    $config = new Config('https://akaunting.test','https://akaunting.test/mcp-auth',
        'https://api.openai.com/v1/mcp/tunnel_test_resource','chatgpt-client',
        'https://chatgpt.com/connector/oauth/synthetic-callback-id',$dir,random_bytes(32),['localhost']);
    mustFail(fn()=> (new Api($config))->get('poc@example.invalid','fixture','/users/22/disable'),
        'GET disable users route is denied even though Akaunting registers it');
    mustFail(fn()=> (new Api($config))->get('poc@example.invalid','fixture','/documents/7/received'),
        'GET received document route is denied because it mutates state');
    mustFail(fn()=> (new Api($config))->get('poc@example.invalid','fixture','/nfse/read/secret-vault'),
        'NFS-e API not registered and never proxied');
    mustFail(fn()=> (new Api($config))->get('poc@example.invalid','fixture','/../../settings'),
        'API path traversal is rejected');
    $api = new FakeApi($config);
    $store = new Store($config);
    $oauth = new OAuth($config,$store,$api);
    $tools = new Toolset($store,$api);
    $api->calls=[];
    $api->verify('employee@example.invalid','correct-horse-battery',1);
    mustFail(fn()=> $api->verify('employee@example.invalid','correct-horse-battery',2),
        'Membership route independently denies unassigned companies');
    $verifier = str_repeat('A',43);
    $challenge = rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
    $params = ['response_type'=>'code','client_id'=>'chatgpt-client', 'redirect_uri'=>$config->oauthRedirectUri,
        'resource'=>$config->resource,'scope'=>'mcp:read','code_challenge'=>$challenge,
        'code_challenge_method'=>'S256','state'=>'fakestate-1234567890'];
    mustFail(fn()=> $oauth->start(array_replace($params, ['code_challenge_method'=>'plain'])), 'PKCE plain rejected');
    mustFail(fn()=> $oauth->start(array_replace($params, ['redirect_uri'=>'https://evil.example/callback'])), 'OAuth callback injection rejected');
    mustFail(fn()=> $oauth->start(array_replace($params, ['redirect_uri'=>'https://chatgpt.com/connector/oauth/another-callback-id'])),
        'OAuth refuses another ChatGPT connection callback even on the same trusted origin');
    mustFail(fn()=> $oauth->start(array_replace($params, ['redirect_uri'=>'https://chatgpt.com/connector_platform_oauth_redirect'])),
        'OAuth refuses the stable callback when this connection is registered with a specific callback');
    mustFail(fn()=> $oauth->start(array_replace($params, ['resource'=>'https://evil.example/mcp'])), 'OAuth audience mismatch rejected');
    $noResourceParams=$params;
    unset($noResourceParams['resource']);
    $flow = $oauth->start($noResourceParams);
    test(isset($flow['ticket'], $flow['csrf']), 'ChatGPT OAuth authorization without resource defaults to fixed single audience');
    $explicitFlow = $oauth->start($params);
    test(isset($explicitFlow['ticket']), 'Authorization with exact canonical resource remains valid');
    mustFail(fn()=> $oauth->login($flow['ticket'],$flow['csrf'],'invalid', 'employee@example.invalid','correct-horse-battery',1,'test-ip'), 'CSRF session cookie enforced');
    mustFail(fn()=> $oauth->login($flow['ticket'],$flow['csrf'],$flow['ticket'], 'employee@example.invalid','wrong-password',1,'test-ip'), 'Wrong upstream password rejected');
    mustFail(fn()=> $oauth->login($flow['ticket'],$flow['csrf'],$flow['ticket'], 'employee@example.invalid','correct-horse-battery',2,'test-ip'), 'Unassigned company denied at login');
    $reentrantRejected = false;
    $api->onMembership = static function () use ($oauth, $flow, &$reentrantRejected): void {
        try {
            $oauth->login($flow['ticket'],$flow['csrf'],$flow['ticket'],
                'employee@example.invalid','correct-horse-battery',1,'test-ip');
        } catch (RuntimeException) {
            $reentrantRejected = true;
        }
    };
    $redirect = $oauth->login($flow['ticket'],$flow['csrf'],$flow['ticket'],'employee@example.invalid','correct-horse-battery',1,'test-ip');
    test($reentrantRejected, 'Parallel OAuth login cannot redeem the same ticket twice');
    parse_str((string)parse_url($redirect,PHP_URL_QUERY),$query);
    test(($query['state'] ?? '') === $params['state'] && is_string($query['code'] ?? null), 'OAuth browser login issues bound code and state');
    test(($query['iss'] ?? '') === $config->issuer && ($oauth->metadata()['authorization_response_iss_parameter_supported'] ?? false) === true,
        'OAuth authorization response identifies the exact issuer');
    parse_str((string)parse_url($oauth->authorizationError($params), PHP_URL_QUERY), $err);
    test(($err['error'] ?? '') === 'invalid_request' && ($err['state'] ?? '') === $params['state'] && ($err['iss'] ?? '') === $config->issuer,
        'OAuth authorization error identifies issuer and preserves validated state');
    mustFail(fn()=> $oauth->authorizationError(array_replace($params, ['redirect_uri'=>'https://evil.invalid/callback'])), 'OAuth errors never redirect to an unregistered URL');
    $form = ['grant_type'=>'authorization_code', 'client_id'=>'chatgpt-client', 'resource'=>$config->resource,
        'redirect_uri'=>$config->oauthRedirectUri,'code'=>$query['code'],'code_verifier'=>'B'.substr($verifier,1)];
    mustFail(fn()=> $oauth->token($form), 'Incorrect PKCE verifier rejected');
    $form['code_verifier'] = $verifier;
    mustFail(fn()=> $oauth->token(array_replace($form,['resource'=>'https://evil.example/mcp'])),
        'Token exchange rejects an explicitly mismatched resource');
    $noResourceForm=$form;
    unset($noResourceForm['resource']);
    $api->rateLimitNextMembership = true;
    mustFail(fn()=> $oauth->token($noResourceForm), 'Authorization code retry survives transient upstream 429');
    $issued = $oauth->token($noResourceForm);
    test(isset($issued['access_token'],$issued['refresh_token']),
        'Authorization code exchanged for MCP tokens without optional resource');
    $parts=explode('.', $issued['access_token']);
    $claims=json_decode(base64_decode(strtr($parts[1] ?? '', '-_', '+/')), true);
    test(is_array($claims) && ($claims['aud'] ?? null) === $config->resource
        && ($claims['scope'] ?? null) === 'mcp:read',
        'Issued token retains exact fixed audience and scope when resource is omitted');
    mustFail(fn()=> $oauth->token($form), 'Authorization code replay rejected');
    $subject = $oauth->bearer('Bearer '.$issued['access_token']);
    test(hash_equals($subject,$store->subject('employee@example.invalid')), 'Token identity bound to verified personal login');
    mustFail(fn()=> $oauth->bearer('Bearer '.$issued['access_token'].'tamper'), 'JWT tampering rejected');
    $toolList = $tools->definitions();
    test(count($toolList) === 9 && !str_contains(json_encode($toolList),'apply_change'), 'Only nine non-mutating tools exposed');
    test(count(array_filter($toolList, static fn(array $t): bool =>
        ($t['securitySchemes'] ?? null) === [['type'=>'oauth2','scopes'=>['mcp:read']]])) === 9,
        'Every tool explicitly requires OAuth mcp:read for ChatGPT linking');
    test(count(Toolset::RESOURCES) === 16 && !in_array('users',Toolset::RESOURCES,true),'Tenant-safe REST resources declared');
    $me = $tools->call($subject,'akaunting_whoami',[]);
    test(($me['writes_enabled'] ?? true) === false, 'Writes remain disabled');
    $companies = $tools->call($subject,'akaunting_list_companies',['page'=>2,'limit'=>1]);
    test(($companies['data'][1]['id'] ?? null) === 3, 'User-company enumeration uses upstream authorization and pagination');
    $docs = $tools->call($subject,'akaunting_list_records',['resource'=>'documents','record_type'=>'invoice','company_id'=>3,'page'=>2,'limit'=>5,'search'=>'customer']);
    test($docs['data'][0]['id'] === 7 && !isset($docs['data'][0]['password'])
        && !isset($docs['data'][0]['contact']['access_token']), 'Financial records returned with sensitive fields filtered');
    $last = end($api->calls);
    test(($last[2]['company_id'] ?? null) === 3 && ($last[2]['page'] ?? null) === 2
        && ($last[2]['limit'] ?? null) === 5, 'Filtering and pagination use authorized company context');
    mustFail(fn()=> $tools->call($subject,'akaunting_list_records',['resource'=>'documents','record_type'=>'invoice','company_id'=>2]), 'Company boundary verified by Akaunting');
    mustFail(fn()=> $tools->call($subject,'akaunting_list_records',['resource'=>'documents','company_id'=>1]), 'Contacts/documents require type discriminators for upstream ACL');
    $scopedUsers=$tools->call($subject,'akaunting_list_users', ['company_id'=>1, 'page'=>1, 'limit'=>25]);
    test(count($scopedUsers['data']) === 1 && $scopedUsers['data'][0]['email'] === 'assigned@example.invalid'
        && $scopedUsers['data'][0]['roles'][0]['code'] === 'manager'
        && !isset($scopedUsers['meta']) && !isset($scopedUsers['total'])
        && !str_contains(json_encode($scopedUsers),'unassigned@example.invalid')
        && !str_contains(json_encode($scopedUsers),'unknown@example.invalid')
        && !str_contains(json_encode($scopedUsers),'must-not-leak')
        && !str_contains(json_encode($scopedUsers),'never-leak'),
        'Users projection filters foreign company accounts, hidden relations and global totals');
    $otherUsers=$tools->call($subject,'akaunting_list_users', ['company_id'=>3]);
    test(count($otherUsers['data']) === 2 && $otherUsers['company_id'] === 3,
        'User listing responds to the selected company and confirms each membership');
    mustFail(fn()=> $tools->call($subject,'akaunting_list_users', ['company_id'=>2]),
        'User enumeration denies company not assigned to the caller');
    mustFail(fn()=> $tools->call($subject,'akaunting_list_records',['resource'=>'documents','record_type'=>'invoice','company_id'=>1,'search'=>'type:bill']), 'Conflicting record type filters rejected');
    mustFail(fn()=> $tools->call($subject,'akaunting_list_records',['resource'=>'users','company_id'=>1]), 'Unscoped users API is blocked by MCP policy');
    mustFail(fn()=> $tools->call($subject,'akaunting_get_record',['resource'=>'users','company_id'=>1,'record_id'=>7]), 'Unscoped user details also blocked');
    $api->typeOverride='bill';
    mustFail(fn()=> $tools->call($subject,'akaunting_get_record',['resource'=>'documents','company_id'=>1,'record_type'=>'invoice','record_id'=>7]), 'ID lookup cannot cross invoice/bill ACL using mismatched discriminator');
    $api->typeOverride=null;
    $api->companyOverride=3;
    mustFail(fn()=> $tools->call($subject,'akaunting_get_record',['resource'=>'documents','company_id'=>1,'record_type'=>'invoice','record_id'=>7]), 'Returned records from a different company are rejected');
    $api->companyOverride=null;
    $api->foreignNestedCompany=true;
    mustFail(fn()=> $tools->call($subject,'akaunting_list_records',['resource'=>'documents','company_id'=>1,'record_type'=>'invoice']), 'Nested foreign-tenant records rejected by legacy list tool');
    mustFail(fn()=> $tools->call($subject,'akaunting_get_record',['resource'=>'documents','company_id'=>1,'record_type'=>'invoice','record_id'=>7]), 'Nested foreign-tenant records rejected by legacy detail tool');
    $api->foreignNestedCompany=false;
    $api->omitCompanyId=true; // gitleaks:allow - synthetic fixture property, not an API credential
    mustFail(fn()=> $tools->call($subject,'akaunting_list_records',['resource'=>'documents','company_id'=>1,'record_type'=>'invoice']), 'Missing upstream company_id is rejected');
    $api->omitCompanyId=false;
    $projected=$tools->call($subject,'akaunting_list_records',[
        'resource'=>'documents','record_type'=>'invoice','company_id'=>1,
        'fields'=>['name','password']]);
    test(array_keys($projected['data'][0]) === ['id','company_id','type','name']
        && !isset($projected['data'][0]['password'])
        && !isset($projected['data'][0]['contact']),
        'Requested fields reduce payload only after record ACL and secret filtering');
    $projectedModule=$tools->call($subject,'akaunting_api_read',[
        'company_id'=>1,'route'=>'api.departments.index','fields'=>['name']]);
    test(array_keys($projectedModule['data'][0]) === ['id','company_id','name'],
        'Module record projection keeps identity and tenant fields');
    mustFail(fn()=> $tools->call($subject,'akaunting_api_read',[
        'company_id'=>1,'route'=>'api.departments.index','fields'=>['name','../secret']]),
        'Invalid field names are rejected without exposing API content');
    $api->companyOverride=3;
    mustFail(fn()=> $tools->call($subject,'akaunting_api_read',[
        'company_id'=>1,'route'=>'api.departments.index','fields'=>['name']]),
        'Projecting fewer fields never bypasses tenant validation');
    $api->companyOverride=null;
    $apiRoutes=$tools->call($subject,'akaunting_api_routes',['company_id'=>1]);
    test(($apiRoutes['count'] ?? 0) === 40
        && in_array('api.employees.index',array_column($apiRoutes['registered_read_routes'],'route'),true),
        'Catalog reads forty reviewed registered native and module GET routes');
    test(!in_array('api.documents.received',array_column($apiRoutes['registered_read_routes'],'route'),true)
        && !in_array('api.users.disable',array_column($apiRoutes['registered_read_routes'],'route'),true)
        && !in_array('api.nfse.read.configuration',array_column($apiRoutes['registered_read_routes'],'route'),true),
        'GET mutators and unregistered NFS-e API do not enter the route catalog');
    $departments=$tools->call($subject,'akaunting_api_read',
        ['company_id'=>1,'route'=>'api.departments.index','page'=>1,'limit'=>10]);
    test(($departments['data'][0]['id'] ?? null)===12
        && !isset($departments['data'][0]['authorization_token']),
        'Installed module API records are returned with secret redaction');
    $oldDepartments=$tools->call($subject,'akaunting_list_records',
        ['resource'=>'departments','company_id'=>1,'limit'=>1]);
    test(($oldDepartments['data'][0]['id'] ?? null)===12,
        'Old list_records tool discovers Employee module resources too');
    $scopedSettings=$tools->call($subject,'akaunting_api_read',
        ['company_id'=>1,'route'=>'api.settings.index']);
    test(count($scopedSettings['data'])===2
        && !str_contains(json_encode($scopedSettings),'TOP-SECRET-FIXTURE')
        && !str_contains(json_encode($scopedSettings),'Other tenant')
        && !isset($scopedSettings['meta']),
        'Native settings are filtered by company and allowlisted non-secret keys');
    $setting=$tools->call($subject,'akaunting_api_read',
        ['company_id'=>1,'route'=>'api.settings.show','route_params'=>['setting'=>5]]);
    test(($setting['data']['value'] ?? null)==='Synthetic Company',
        'Authorized single setting is returned only by its safe key');
    $userShow=$tools->call($subject,'akaunting_api_read',
        ['company_id'=>1,'route'=>'api.users.show','route_params'=>['user'=>21]]);
    test(($userShow['data']['email'] ?? null)==='assigned@example.invalid'
        && !str_contains(json_encode($userShow),'must-not-leak'),
        'Native user show is projected with membership proof and role names');
    mustFail(fn()=> $tools->call($subject,'akaunting_api_read',
        ['company_id'=>1,'route'=>'api.users.show','route_params'=>['user'=>22]]),
        'Users outside selected company remain hidden by API detail route');
    $nested=$tools->call($subject,'akaunting_api_read',
        ['company_id'=>1,'route'=>'api.documents.transactions.index',
            'route_params'=>['document'=>7],'record_type'=>'invoice']);
    test(($nested['data'][0]['id'] ?? null)===41
        && !isset($nested['data'][0]['access_token']),
        'Nested document transactions verify parent company and document type');
    $api->companyOverride=3;
    mustFail(fn()=> $tools->call($subject,'akaunting_api_read',
        ['company_id'=>1,'route'=>'api.departments.index']),
        'Module API cannot expose rows from another company');
    $api->companyOverride=null;
    mustFail(fn()=> $tools->call($subject,'akaunting_api_read',
        ['company_id'=>1,'route'=>'api.documents.received','route_params'=>['document'=>7],'record_type'=>'invoice']),
        'Generic API read cannot invoke GET routes with side effects');
    mustFail(fn()=> $tools->call($subject,'akaunting_api_read',
        ['company_id'=>1,'route'=>'api.employees.show','route_params'=>['employee'=>'../secrets']]),
        'Generic API route path placeholders disallow traversal');
    mustFail(fn()=> $tools->call($subject,'akaunting_list_records',['resource'=>'documents','record_type'=>'invoice','company_id'=>1,'search'=>"name:\nunsafe"]), 'Control characters rejected from search');
    mustFail(fn()=> $tools->call($subject,'akaunting_list_records',['resource'=>'/etc/passwd','company_id'=>1]), 'Arbitrary API paths rejected');
    mustFail(fn()=> $tools->call($subject,'akaunting_get_record',['resource'=>'transactions','company_id'=>1,'record_id'=>-2]), 'Invalid record id rejected');
    mustFail(fn()=> $tools->call($subject,'akaunting_get_record',['resource'=>'companies','company_id'=>1,'record_id'=>3]), 'Selected company cannot be changed through another resource ID');
    $resourceMetadataUrl='https://api.openai.com/.well-known/oauth-protected-resource/v1/mcp/tunnel_test_resource';
    $mcp = new Mcp($tools,$resourceMetadataUrl);
    $mcpRequest = fn($method,$params=[],$id=1) => $mcp->execute($subject,json_encode(['jsonrpc'=>'2.0','id'=>$id,'method'=>$method,'params'=>$params],JSON_THROW_ON_ERROR));
    $init = $mcpRequest('initialize',['protocolVersion'=>'2025-06-18']);
    test(($init['result']['serverInfo']['name'] ?? '') === 'LibreCode Akaunting MCP','MCP initializes with serverInfo');
    test(count($mcpRequest('tools/list')['result']['tools'] ?? []) === 9, 'MCP tools/list returns read-only schema');
    $publicRequest = fn($method,$params=[]) => $mcp->execute(null,json_encode([
        'jsonrpc'=>'2.0','id'=>93,'method'=>$method,'params'=>$params],JSON_THROW_ON_ERROR));
    test(($publicRequest('initialize',['protocolVersion'=>'2025-06-18'])['result']['serverInfo']['name'] ?? '') === 'LibreCode Akaunting MCP',
        'Unauthenticated initialize may discover server without reading finance data');
    test(count($publicRequest('tools/list')['result']['tools'] ?? []) === 9,
        'Unauthenticated tools/list exposes only tool schemas');
    $previousApiCalls=count($api->calls);
    $challenge=$publicRequest('tools/call',['name'=>'akaunting_whoami','arguments'=>[]]);
    $bearerChallenge=$challenge['result']['_meta']['mcp/www_authenticate'][0] ?? '';
    test(($challenge['result']['isError'] ?? false) === true
        && is_string($bearerChallenge) && str_contains($bearerChallenge,$resourceMetadataUrl)
        && str_contains($bearerChallenge,'error="invalid_token"')
        && !str_contains(json_encode($challenge),'employee@example.invalid'),
        'Tool call without OAuth issues a scoped challenge without exposing an identity');
    test(count($api->calls) === $previousApiCalls,
        'Anonymous tool call does not contact upstream Akaunting or expose records');
    $called = $mcpRequest('tools/call',['name'=>'akaunting_get_record','arguments'=>['resource'=>'documents','record_type'=>'invoice','company_id'=>1,'record_id'=>7]]);
    test(($called['result']['isError'] ?? true) === false, 'MCP tools/call works with authorized account');
    $blocked = $mcpRequest('tools/call',['name'=>'akaunting_list_records','arguments'=>['resource'=>'documents','record_type'=>'invoice','company_id'=>2]]);
    test(($blocked['result']['isError'] ?? false) === true && !str_contains(json_encode($blocked),'correct-horse-battery'), 'Tool errors do not leak credential material');
    $api->rateLimitNextMembership = true;
    $throttled = $mcpRequest('tools/call',['name'=>'akaunting_list_records',
        'arguments'=>['resource'=>'categories','company_id'=>1,'limit'=>1]]);
    $notice = $throttled['result']['content'][0]['text'] ?? '';
    test(($throttled['result']['isError'] ?? false) === true
        && is_string($notice) && str_contains($notice,'rate limit')
        && str_contains($notice,'Do not retry immediately')
        && !str_contains(json_encode($throttled),'correct-horse-battery'),
        'MCP returns a safe rate-limit advisory instead of provoking immediate retry');
    mustFail(fn()=> $oauth->token(['grant_type'=>'refresh_token','client_id'=>'chatgpt-client',
        'resource'=>'https://evil.example/mcp','refresh_token'=>$issued['refresh_token']]),
        'Refresh rejects an explicitly mismatched resource');
    $api->rateLimitNextMembership = true;
    mustFail(fn()=> $oauth->token(['grant_type'=>'refresh_token','client_id'=>'chatgpt-client',
        'refresh_token'=>$issued['refresh_token']]),
        'Simulated upstream HTTP 429 blocks refresh without consuming the valid refresh token');
    $new = $oauth->token(['grant_type'=>'refresh_token','client_id'=>'chatgpt-client',
        'refresh_token'=>$issued['refresh_token']]);
    test(isset($new['access_token']), 'Refresh survives upstream 429, retaining the fixed resource audience');
    mustFail(fn()=> $oauth->token(['grant_type'=>'refresh_token','client_id'=>'chatgpt-client','resource'=>$config->resource,'refresh_token'=>$issued['refresh_token']]), 'Refresh token rotation enforced');
    test(is_string($new['access_token'] ?? null), 'Refresh exchanges succeed with current upstream credentials');
    $flow2 = $oauth->start($params);
    $redirect2 = $oauth->login($flow2['ticket'],$flow2['csrf'],$flow2['ticket'],'employee@example.invalid','correct-horse-battery',1,'test-ip');
    parse_str((string)parse_url($redirect2,PHP_URL_QUERY),$q2);
    $form2 = $form; $form2['code'] = $q2['code'];
    $issued2 = $oauth->token($form2);
    mustFail(fn()=> $oauth->bearer('Bearer '.$issued['access_token']), 'Old access tokens invalidated upon relink');
    test($oauth->bearer('Bearer '.$issued2['access_token']) === $subject, 'New link generation accepted');
    $store->revoke('employee@example.invalid');
    mustFail(fn()=> $oauth->bearer('Bearer '.$issued2['access_token']), 'Revocation denies valid previously issued JWT');
    mustFail(fn()=> $store->load($subject), 'Revocation destroys encrypted stored credentials');
    test(is_file($dir.'/users/'.$subject.'.blocked'), 'Revoked subject cannot silently relink');
} catch (Throwable $e) {
    fwrite(STDERR,'TEST ERROR: '.$e->getMessage()."\n");
    exit(1);
}

// Remove generated synthetic test fixtures, without touching other paths.
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
foreach ($iter as $item) { $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); }
rmdir($dir);
echo "All synthetic tests passed.\n";
