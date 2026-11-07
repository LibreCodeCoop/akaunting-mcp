<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AkauntingMcp\Tests;

use CurlHandle;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * Runs only against disposable localhost HTTP fixtures started by http-smoke.sh.
 * OAuth and mutation state live in a dedicated temporary directory.
 */
final class HttpSmokeIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $path=getenv('MCP_SMOKE_MUTATION_STATE');
        self::assertIsString($path);
        self::assertFileExists($path);
        self::assertNotFalse(file_put_contents($path,'[]'));
    }

    /** @return array{0:int,1:string,2:string} */
    private static function httpRequest(string $path, string $method = 'GET', ?string $body = null, array $headers = []): array
    {
        $curl = curl_init('http://127.0.0.1:8876'.$path);
        if (!$curl instanceof CurlHandle) {
            throw new RuntimeException('Smoke HTTP initialization failed');
        }
        $hostDefined = count(array_filter($headers, static fn(string $h): bool => str_starts_with(strtolower($h), 'host:'))) > 0;
        $requestHeaders = [...($hostDefined ? [] : ['Host: localhost']), ...$headers];
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $requestHeaders,
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($curl);
        if (!is_string($raw)) {
            throw new RuntimeException('Smoke connection failed');
        }
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $length = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        return [$status, substr($raw, 0, $length), substr($raw, $length)];
    }

    /** @return array<string,mixed> */
    private static function jsonMessage(string $path, array $message, array $headers = []): array
    {
        [$code, , $body] = self::httpRequest($path, 'POST', json_encode($message, JSON_THROW_ON_ERROR),
            ['Content-Type: application/json', ...$headers]);
        self::assertTrue($code === 200, 'http_json_rpc_status');
        return json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    }

    /** @return array{auth:list<string>,tokens:array<string,mixed>,params:array<string,mixed>} */
    private function authorize(): array
    {
        $verifier = str_repeat('A', 43);
        $challengeHash = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $params = [
            'response_type'=>'code', 'client_id'=>'synthetic-client',
            'redirect_uri'=>'https://chatgpt.com/connector/oauth/synthetic',
            'scope'=>'mcp:read', 'code_challenge_method'=>'S256', 'code_challenge'=>$challengeHash,
            'resource'=>'https://api.openai.com/v1/mcp/tunnel_synthetic',
            'state'=>'synthetic-state-0123456789',
        ];
        [$status, $responseHeaders, $html] = self::httpRequest('/mcp-auth/authorize?'.http_build_query($params));
        preg_match('/name="ticket" value="([^"]+)"/', $html, $ticket);
        preg_match('/name="csrf" value="([^"]+)"/', $html, $csrf);
        preg_match('/Set-Cookie:\s*akaunting_mcp_flow=([^;\r\n]+)/i', $responseHeaders, $cookie);
        self::assertTrue($status === 200 && isset($ticket[1], $csrf[1], $cookie[1]), 'oauth_authorization_form');

        $loginBody = http_build_query([
            'ticket'=>$ticket[1], 'csrf'=>$csrf[1],
            'email'=>'test@example.invalid', 'password'=>'synthetic-password', 'company_id'=>1,
        ], '', '&', PHP_QUERY_RFC3986);
        [$status, $responseHeaders] = self::httpRequest('/mcp-auth/login', 'POST', $loginBody, [
            'Content-Type: application/x-www-form-urlencoded',
            'Cookie: akaunting_mcp_flow='.$cookie[1],
        ]);
        preg_match('/Location:\s*([^\r\n]+)/i', $responseHeaders, $location);
        parse_str((string)parse_url($location[1] ?? '', PHP_URL_QUERY), $returned);
        self::assertTrue($status === 303 && isset($returned['code'])
            && ($returned['state'] ?? null) === $params['state']
            && ($returned['iss'] ?? null) === 'https://localhost/mcp-auth', 'oauth_login_redirect');

        [$status, , $body] = self::httpRequest('/mcp-auth/token', 'POST', http_build_query([
            'grant_type'=>'authorization_code', 'client_id'=>'synthetic-client',
            'redirect_uri'=>'https://chatgpt.com/connector/oauth/synthetic',
            'code'=>$returned['code'], 'code_verifier'=>$verifier,
        ], '', '&', PHP_QUERY_RFC3986), ['Content-Type: application/x-www-form-urlencoded']);
        $tokens = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        self::assertTrue($status === 200 && is_string($tokens['access_token'] ?? null)
            && is_string($tokens['refresh_token'] ?? null), 'oauth_code_exchange');
        $auth = ['Authorization: Bearer '.$tokens['access_token']];
        $who = self::jsonMessage('/mcp', ['jsonrpc'=>'2.0','id'=>3,'method'=>'tools/call',
            'params'=>['name'=>'akaunting_whoami','arguments'=>new stdClass()]], $auth);
        $identity = json_decode($who['result']['content'][0]['text'] ?? '{}',true,16,JSON_THROW_ON_ERROR);
        self::assertTrue(($identity['authenticated'] ?? false) === true &&
            ($identity['writes_enabled'] ?? false) === true && ($identity['human_approval_required'] ?? false), 'oauth_authenticated_whoami');
        return ['auth'=>$auth,'tokens'=>$tokens,'params'=>$params];
    }

    public function testOAuthMetadataAndUnauthenticatedBoundary(): void
    {
        [$status, , $metadata] = self::httpRequest('/.well-known/oauth-authorization-server/mcp-auth');
        $details = json_decode($metadata, true, 32, JSON_THROW_ON_ERROR);
        self::assertTrue($status === 200 && ($details['code_challenge_methods_supported'] ?? null) === ['S256'], 'oauth_metadata');
        [$status, , $protected] = self::httpRequest('/.well-known/oauth-protected-resource/mcp');
        $protected = json_decode($protected, true, 32, JSON_THROW_ON_ERROR);
        self::assertTrue($status === 200 && ($protected['scopes_supported'] ?? null) === ['mcp:read'], 'mcp_protected_metadata');
        [$status] = self::httpRequest('/mcp');
        self::assertTrue($status === 405, 'mcp_get_rejected');
        [$status] = self::httpRequest('/mcp', 'POST', '{}', ['Host: attacker.example.invalid', 'Content-Type: application/json']);
        self::assertTrue($status === 421, 'mcp_host_rejected');
        [$status] = self::httpRequest('/mcp', 'POST', '{}', ['Origin: https://attacker.example.invalid', 'Content-Type: application/json']);
        self::assertTrue($status === 403, 'mcp_origin_rejected');

        $tools = self::jsonMessage('/mcp', ['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/list']);
        $names=array_column($tools['result']['tools'] ?? [], 'name');
        self::assertContains('akaunting_whoami',$names);
        self::assertContains('akaunting_api_read',$names);
        self::assertContains('akaunting_prepare_mutation',$names);
        self::assertNotContains('execute_mutation',$names);
        $challenge = self::jsonMessage('/mcp', ['jsonrpc'=>'2.0','id'=>2,'method'=>'tools/call',
            'params'=>['name'=>'akaunting_whoami','arguments'=>new stdClass()]]);
        self::assertTrue(($challenge['result']['isError'] ?? null) === true &&
            isset($challenge['result']['_meta']['mcp/www_authenticate']), 'oauth_linking_challenge');
    }

    public function testAuthorizedCoreAndFiscalReads(): void
    {
        ['auth'=>$auth]=$this->authorize();
        $records = self::jsonMessage('/mcp', ['jsonrpc'=>'2.0','id'=>4,'method'=>'tools/call',
            'params'=>['name'=>'akaunting_list_records', 'arguments'=>[
                'company_id'=>1,'resource'=>'categories','page'=>1,'limit'=>1]]], $auth);
        $record = json_decode($records['result']['content'][0]['text'] ?? '{}',true,16,JSON_THROW_ON_ERROR);
        self::assertTrue(($record['data'][0]['company_id'] ?? null) === 1, 'mcp_paginated_company_read');
        $foreign = self::jsonMessage('/mcp', ['jsonrpc'=>'2.0','id'=>5,'method'=>'tools/call',
            'params'=>['name'=>'akaunting_list_records','arguments'=>[
                'company_id'=>2,'resource'=>'categories','page'=>1,'limit'=>1]]], $auth);
        self::assertTrue(($foreign['result']['isError'] ?? false) === true, 'mcp_foreign_company_rejected');

        $native = self::jsonMessage('/mcp', [
            'jsonrpc'=>'2.0','id'=>6,'method'=>'tools/call',
            'params'=>['name'=>'akaunting_api_read','arguments'=>[
                'company_id'=>1,'route'=>'api.categories.index','limit'=>1,
            ]],
        ], $auth);
        $nativeData = json_decode($native['result']['content'][0]['text'] ?? '{}',true,16,JSON_THROW_ON_ERROR);
        self::assertTrue(($nativeData['data'][0]['company_id'] ?? null) === 1
            && ($nativeData['data'][0]['name'] ?? null) === 'Synthetic category',
            'native_api_read_authorized');

        // NFS-e JSON reads keep native company-prefixed routing and reviewed names;
        // neither the agent nor an OAuth token can choose an arbitrary fiscal path.
        $nfseRead=self::jsonMessage('/mcp',[
            'jsonrpc'=>'2.0','id'=>61,'method'=>'tools/call',
            'params'=>['name'=>'akaunting_api_read','arguments'=>[
                'company_id'=>1,'route'=>'api.nfse.national-services.index',
                'nfse_filters'=>['lc116'=>'0107','limit'=>2],
            ]],
        ],$auth);
        $nfse=json_decode($nfseRead['result']['content'][0]['text'] ?? '{}',true,16,JSON_THROW_ON_ERROR);
        self::assertTrue(($nfseRead['result']['isError']??true)===false
            && ($nfse['route']??null)==='api.nfse.national-services.index'
            && ($nfse['response']['data'][0]['code']??null)==='0107',
            'nfse_native_company_prefix_and_json_contract');

        $webdavRead=self::jsonMessage('/mcp',[
            'jsonrpc'=>'2.0','id'=>65,'method'=>'tools/call',
            'params'=>['name'=>'akaunting_api_read','arguments'=>[
                'company_id'=>1,'route'=>'api.nfse.settings.artifacts.show',
            ]],
        ],$auth);
        $webdav=json_decode($webdavRead['result']['content'][0]['text'] ?? '{}',true,16,JSON_THROW_ON_ERROR);
        self::assertTrue(($webdavRead['result']['isError']??true)===false
            && ($webdav['response']['data']['host']??null)==='dav.example.invalid'
            && ($webdav['response']['data']['password_configured']??null)===true
            && !array_key_exists('webdav_url',$webdav['response']['data']??[])
            && !array_key_exists('webdav_password',$webdav['response']['data']??[]),
            'nfse_webdav_safe_metadata_without_credentials');

        $fiscalStatus=self::jsonMessage('/mcp',[
            'jsonrpc'=>'2.0','id'=>66,'method'=>'tools/call',
            'params'=>['name'=>'akaunting_api_read','arguments'=>[
                'company_id'=>1,'route'=>'api.nfse.invoices.post-emission-status',
                'route_params'=>['invoice'=>7],
            ]],
        ],$auth);
        $fiscal=json_decode($fiscalStatus['result']['content'][0]['text'] ?? '{}',true,16,JSON_THROW_ON_ERROR);
        self::assertTrue(($fiscalStatus['result']['isError']??true)===false
            && ($fiscal['response']['data']['status']??null)==='emitted'
            && ($fiscal['response']['data']['artifacts']['xml']['ready']??null)===true,
            'nfse_status_allows_metadata_without_releasing_xml');

        $nfseReadiness=self::jsonMessage('/mcp',[
            'jsonrpc'=>'2.0','id'=>63,'method'=>'tools/call',
            'params'=>['name'=>'akaunting_api_read','arguments'=>[
                'company_id'=>1,'route'=>'api.nfse.dps.readiness',
            ]],
        ],$auth);
        $nfseReady=json_decode($nfseReadiness['result']['content'][0]['text'] ?? '{}',true,16,JSON_THROW_ON_ERROR);
        self::assertTrue(($nfseReadiness['result']['isError']??true)===false
            && ($nfseReady['response']['data']['mechanically_ready']??null)===false,
            'nfse_dps_readiness_is_not_issuance_authorization');

        $nfseLookup=self::jsonMessage('/mcp',[
            'jsonrpc'=>'2.0','id'=>64,'method'=>'tools/call',
            'params'=>['name'=>'akaunting_api_read','arguments'=>[
                'company_id'=>1,'route'=>'api.nfse.dps.lookup',
                'nfse_filters'=>['number'=>'900000000000008'],
            ]],
        ],$auth);
        $lookup=json_decode($nfseLookup['result']['content'][0]['text'] ?? '{}',true,16,JSON_THROW_ON_ERROR);
        self::assertTrue(($nfseLookup['result']['isError']??true)===false
            && ($lookup['response']['data']['official_state']??null)==='not_found'
            && ($lookup['response']['data']['safe_to_emit']??null)===false,
            'nfse_official_read_only_lookup_cannot_enable_post');
        $unsafeFiscal=self::jsonMessage('/mcp',[
            'jsonrpc'=>'2.0','id'=>62,'method'=>'tools/call',
            'params'=>['name'=>'akaunting_api_read','arguments'=>[
                'company_id'=>1,'route'=>'api.nfse.invoices.emit',
                'route_params'=>['invoice'=>7],
            ]],
        ],$auth);
        self::assertTrue(($unsafeFiscal['result']['isError']??false)===true,
            'nfse_issuance_is_not_a_read_tool');
    }

    public function testNativeMutationReusesLinkedMcpOAuthWithoutNewBrowserLogin(): void
    {
        ['auth'=>$auth]=$this->authorize();
        $prepare=self::jsonMessage('/mcp',[
            'jsonrpc'=>'2.0','id'=>80,'method'=>'tools/call',
            'params'=>['name'=>'akaunting_prepare_mutation','arguments'=>[
                'company_id'=>1,'route'=>'api.contacts.update','method'=>'PATCH',
                'route_params'=>['contact'=>7],'record_type'=>'vendor',
                'body'=>['name'=>'Synthetic native confirmation'],
            ]],
        ],$auth);
        self::assertFalse($prepare['result']['isError']??true);
        $prepared=json_decode($prepare['result']['content'][0]['text'],true,16,JSON_THROW_ON_ERROR);
        self::assertSame('awaiting_host_confirmation',$prepared['status']);
        self::assertArrayNotHasKey('approval_url',$prepared);
        self::assertSame([],json_decode((string)file_get_contents(
            (string)getenv('MCP_SMOKE_MUTATION_STATE')
        ),true));

        // The only credentials supplied are the same Bearer token already
        // connected to ChatGPT. No browser cookies, username or password.
        $confirm=[
            'jsonrpc'=>'2.0','id'=>81,'method'=>'tools/call',
            'params'=>['name'=>'akaunting_execute_prepared_mutation','arguments'=>[
                'company_id'=>1,'request_id'=>$prepared['request_id'],
                'route'=>'api.contacts.update','method'=>'PATCH',
            ]],
        ];
        $confirmed=self::jsonMessage('/mcp',$confirm,$auth);
        self::assertFalse($confirmed['result']['isError']??true);
        $result=json_decode($confirmed['result']['content'][0]['text'],true,16,JSON_THROW_ON_ERROR);
        self::assertSame('completed',$result['status']);
        $writes=json_decode((string)file_get_contents(
            (string)getenv('MCP_SMOKE_MUTATION_STATE')
        ),true);
        self::assertCount(1,$writes);
        self::assertSame('PATCH',$writes[0]['method']);
        self::assertSame('Synthetic native confirmation',$writes[0]['body']['name']);

        $replay=self::jsonMessage('/mcp',$confirm,$auth);
        self::assertTrue($replay['result']['isError']??false);
        self::assertCount(1,json_decode((string)file_get_contents(
            (string)getenv('MCP_SMOKE_MUTATION_STATE')
        ),true));

        $status=self::jsonMessage('/mcp',[
            'jsonrpc'=>'2.0','id'=>82,'method'=>'tools/call',
            'params'=>['name'=>'akaunting_mutation_status','arguments'=>[
                'company_id'=>1,'request_id'=>$prepared['request_id'],
            ]],
        ],$auth);
        $mutation=json_decode($status['result']['content'][0]['text'],true,16,JSON_THROW_ON_ERROR);
        self::assertSame('completed',$mutation['status']);
    }

    public function testMutationsRequireIndependentBrowserApproval(): void
    {
        ['auth'=>$auth]=$this->authorize();
        // A model can propose a write, but the only executor is the issuer-host
        // browser form requiring independent reauthentication and explicit consent.
        $prepare = static fn(array $request): array => self::jsonMessage('/mcp', [
            'jsonrpc'=>'2.0','id'=>30,'method'=>'tools/call',
            'params'=>['name'=>'akaunting_prepare_mutation','arguments'=>$request],
        ], $auth);
        $proposal=$prepare([
            'company_id'=>1,'route'=>'api.contacts.update','method'=>'PATCH',
            'route_params'=>['contact'=>7],'record_type'=>'vendor',
            'body'=>['name'=>'Synthetic updated person'],
            'approval_transport'=>'browser',
        ]);
        self::assertTrue(($proposal['result']['isError'] ?? true) === false, 'mutation_prepared_not_executed');
        $prepared=json_decode($proposal['result']['content'][0]['text'],true,16,JSON_THROW_ON_ERROR);
        self::assertTrue($prepared['status']==='awaiting_human_approval'
            && str_contains($prepared['approval_url'],'/mcp-auth/approve?request=')
            && json_decode((string)file_get_contents((string)getenv('MCP_SMOKE_MUTATION_STATE')),true)===[],
            'mutation_requires_browser_consent');

        [$status,$headers,$approvalPage]=self::httpRequest('/mcp-auth/approve?request='.$prepared['request_id']);
        preg_match('/name="csrf" value="([^"]+)"/',$approvalPage,$approvalCsrf);
        preg_match('/Set-Cookie:\s*akaunting_mcp_approval=([^;\r\n]+)/i',$headers,$approvalCookie);
        self::assertTrue($status===200 && isset($approvalCsrf[1],$approvalCookie[1])
            && str_contains($approvalPage,'Synthetic updated person')
            && str_contains($approvalPage,'PATCH')
            && str_contains($approvalPage,'api.contacts.update'), 'mutation_review_is_explicit');

        $form=http_build_query([
            'request'=>$prepared['request_id'],'csrf'=>$approvalCsrf[1],
            'decision'=>'approve','email'=>'test@example.invalid',
            'password'=>'incorrect-password',
        ],'','&',PHP_QUERY_RFC3986);
        [$status]=self::httpRequest('/mcp-auth/approve','POST',$form,[
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: https://localhost',
            'Cookie: akaunting_mcp_approval='.$approvalCookie[1],
        ]);
        self::assertTrue($status===409
            && json_decode((string)file_get_contents((string)getenv('MCP_SMOKE_MUTATION_STATE')),true)===[],
            'approval_rejects_wrong_password');

        $form=http_build_query([
            'request'=>$prepared['request_id'],'csrf'=>$approvalCsrf[1],
            'decision'=>'approve','email'=>'test@example.invalid',
            'password'=>'synthetic-password',
        ],'','&',PHP_QUERY_RFC3986);
        [$status,, $done]=self::httpRequest('/mcp-auth/approve','POST',$form,[
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: https://localhost',
            'Cookie: akaunting_mcp_approval='.$approvalCookie[1],
        ]);
        $executed=json_decode((string)file_get_contents((string)getenv('MCP_SMOKE_MUTATION_STATE')),true);
        self::assertTrue($status===200 && str_contains($done,'Operação realizada')
            && count($executed)===1 && $executed[0]['method']==='PATCH'
            && $executed[0]['body']['name']==='Synthetic updated person',
            'approved_write_reaches_synthetic_akaunting_exactly_once');

        [$status]=self::httpRequest('/mcp-auth/approve','POST',$form,[
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: https://localhost',
            'Cookie: akaunting_mcp_approval='.$approvalCookie[1],
        ]);
        self::assertTrue($status===409
            && count(json_decode((string)file_get_contents((string)getenv('MCP_SMOKE_MUTATION_STATE')),true))===1,
            'approved_write_cannot_replay');

        $mutStatus=self::jsonMessage('/mcp',[
            'jsonrpc'=>'2.0','id'=>31,'method'=>'tools/call','params'=>[
                'name'=>'akaunting_mutation_status',
                'arguments'=>['company_id'=>1,'request_id'=>$prepared['request_id']],
            ],
        ],$auth);
        $mutState=json_decode($mutStatus['result']['content'][0]['text'],true,16,JSON_THROW_ON_ERROR);
        self::assertTrue($mutState['status']==='completed','mutation_status_reports_success');

        $crossTenant=$prepare([
            'company_id'=>1,'route'=>'api.contacts.update','method'=>'PATCH',
            'route_params'=>['contact'=>7],'record_type'=>'vendor',
            'body'=>['name'=>'Unauthorized','company_id'=>2],
        ]);
        self::assertTrue(($crossTenant['result']['isError']??false)===true
            && count(json_decode((string)file_get_contents((string)getenv('MCP_SMOKE_MUTATION_STATE')),true))===1,
            'mutating_cross_company_payload_rejected');

        $delete=$prepare([
            'company_id'=>1,'route'=>'api.contacts.destroy','method'=>'DELETE',
            'route_params'=>['contact'=>7],'record_type'=>'vendor',
            'approval_transport'=>'browser',
        ]);
        $deleteRequest=json_decode($delete['result']['content'][0]['text'],true,16,JSON_THROW_ON_ERROR);
        [$status,$headers,$page]=self::httpRequest('/mcp-auth/approve?request='.$deleteRequest['request_id']);
        preg_match('/name="csrf" value="([^"]+)"/',$page,$csrfDelete);
        preg_match('/Set-Cookie:\s*akaunting_mcp_approval=([^;\r\n]+)/i',$headers,$cookieDelete);
        self::assertTrue($status===200 && str_contains($page,'Digite EXCLUIR'),'destructive_action_requires_phrase');
        $rejection=http_build_query([
            'request'=>$deleteRequest['request_id'],'csrf'=>$csrfDelete[1],
            'decision'=>'reject',
        ],'','&',PHP_QUERY_RFC3986);
        [$status]=self::httpRequest('/mcp-auth/approve','POST',$rejection,[
            'Content-Type: application/x-www-form-urlencoded',
            'Cookie: akaunting_mcp_approval='.$cookieDelete[1],
        ]);
        self::assertTrue($status===200
            && count(json_decode((string)file_get_contents((string)getenv('MCP_SMOKE_MUTATION_STATE')),true))===1,
            'browser_rejection_does_not_change_records');
        $create=$prepare([
            'company_id'=>1,'route'=>'api.contacts.store','method'=>'POST',
            'record_type'=>'vendor','body'=>['name'=>'Synthetic newly created vendor'],
            'approval_transport'=>'browser',
        ]);
        $createPlan=json_decode($create['result']['content'][0]['text'],true,16,JSON_THROW_ON_ERROR);
        [$status,$headers,$page]=self::httpRequest('/mcp-auth/approve?request='.$createPlan['request_id']);
        preg_match('/name="csrf" value="([^"]+)"/',$page,$csrfCreate);
        preg_match('/Set-Cookie:\s*akaunting_mcp_approval=([^;\r\n]+)/i',$headers,$cookieCreate);
        [$status]=self::httpRequest('/mcp-auth/approve','POST',http_build_query([
            'request'=>$createPlan['request_id'],'csrf'=>$csrfCreate[1],
            'decision'=>'approve','email'=>'test@example.invalid','password'=>'synthetic-password',
        ],'','&',PHP_QUERY_RFC3986),[
            'Content-Type: application/x-www-form-urlencoded','Origin: https://localhost',
            'Cookie: akaunting_mcp_approval='.$cookieCreate[1],
        ]);
        $writes=json_decode((string)file_get_contents((string)getenv('MCP_SMOKE_MUTATION_STATE')),true);
        self::assertTrue($status===200 && count($writes)===2
            && $writes[1]['method']==='POST'
            && $writes[1]['body']['type']==='vendor'
            && $writes[1]['body']['company_id']===1,
            'created_record_requires_explicit_human_approval');

        $delete=$prepare([
            'company_id'=>1,'route'=>'api.contacts.destroy','method'=>'DELETE',
            'route_params'=>['contact'=>7],'record_type'=>'vendor',
            'approval_transport'=>'browser',
        ]);
        $deletePlan=json_decode($delete['result']['content'][0]['text'],true,16,JSON_THROW_ON_ERROR);
        [$status,$headers,$page]=self::httpRequest('/mcp-auth/approve?request='.$deletePlan['request_id']);
        preg_match('/name="csrf" value="([^"]+)"/',$page,$csrfDelete);
        preg_match('/Set-Cookie:\s*akaunting_mcp_approval=([^;\r\n]+)/i',$headers,$cookieDelete);
        [$status]=self::httpRequest('/mcp-auth/approve','POST',http_build_query([
            'request'=>$deletePlan['request_id'],'csrf'=>$csrfDelete[1],
            'decision'=>'approve','email'=>'test@example.invalid',
            'password'=>'synthetic-password','confirmation'=>'EXCLUIR',
        ],'','&',PHP_QUERY_RFC3986),[
            'Content-Type: application/x-www-form-urlencoded','Origin: https://localhost',
            'Cookie: akaunting_mcp_approval='.$cookieDelete[1],
        ]);
        $writes=json_decode((string)file_get_contents((string)getenv('MCP_SMOKE_MUTATION_STATE')),true);
        self::assertTrue($status===200 && count($writes)===3 && $writes[2]['method']==='DELETE'
            && $writes[2]['path']==='/api/contacts/7',
            'destructive_approval_executes_exactly_once');
        $unsafeRoute = self::jsonMessage('/mcp', [
            'jsonrpc'=>'2.0','id'=>7,'method'=>'tools/call',
            'params'=>['name'=>'akaunting_api_read','arguments'=>[
                'company_id'=>1,'route'=>'api.documents.received',
            ]],
        ], $auth);
        self::assertTrue(($unsafeRoute['result']['isError'] ?? null) === true,
            'unreviewed_mutating_get_rejected');
    }

    public function testRefreshAndInvalidHttpRequests(): void
    {
        ['auth'=>$auth,'tokens'=>$tokens,'params'=>$params]=$this->authorize();
        [$status, $headers] = self::httpRequest(
            '/mcp-auth/authorize?'.http_build_query(array_replace($params,[
                'redirect_uri'=>'https://attacker.example.invalid/callback',
            ])),
        );
        self::assertTrue($status === 400 && !preg_match('/^Location:/mi',$headers),
            'oauth_callback_injection_no_redirect');

        [$status, , $unauth] = self::httpRequest('/mcp','POST',json_encode([
            'jsonrpc'=>'2.0','id'=>8,'method'=>'resources/read',
            'params'=>['uri'=>'finance://private'],
        ],JSON_THROW_ON_ERROR),['Content-Type: application/json']);
        self::assertTrue($status === 401 && str_contains($unauth,'Authentication required'),
            'unsupported_unauthenticated_operations_rejected');

        [$status, , $oversized] = self::httpRequest('/mcp','POST',str_repeat('X',524289),
            ['Content-Type: application/json', ...$auth]);
        $oversizedResponse = json_decode($oversized,true,16,JSON_THROW_ON_ERROR);
        self::assertTrue($status === 200 && ($oversizedResponse['error']['code'] ?? null) === -32600,
            'oversized_mcp_request_is_bounded');

        $refreshForm = [
            'grant_type'=>'refresh_token','client_id'=>'synthetic-client',
            'refresh_token'=>$tokens['refresh_token'],
        ];
        [$status, , $refreshed] = self::httpRequest('/mcp-auth/token','POST',
            http_build_query($refreshForm,'','&',PHP_QUERY_RFC3986),
            ['Content-Type: application/x-www-form-urlencoded']);
        $newTokens=json_decode($refreshed,true,16,JSON_THROW_ON_ERROR);
        self::assertTrue($status === 200
            && is_string($newTokens['access_token'] ?? null)
            && ($newTokens['refresh_token'] ?? null) !== $tokens['refresh_token'],
            'refresh_token_rotates_after_authorization');

        [$status, , $replayed] = self::httpRequest('/mcp-auth/token','POST',
            http_build_query($refreshForm,'','&',PHP_QUERY_RFC3986),
            ['Content-Type: application/x-www-form-urlencoded']);
        $replayResponse=json_decode($replayed,true,16,JSON_THROW_ON_ERROR);
        self::assertTrue($status === 400 && ($replayResponse['error'] ?? null) === 'invalid_request',
            'refresh_token_replay_rejected');
    }
}
