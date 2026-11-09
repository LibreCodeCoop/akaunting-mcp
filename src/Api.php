<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace AkauntingMcp;

use RuntimeException;

/** Strict Akaunting REST client; no redirects, no arbitrary paths, no response-body logging. */
class Api
{
    /**
     * One cURL connection per PHP request. The membership check and subsequent
     * REST read use the same host, credentials and TLS connection; never reuse
     * this object between MCP identities or independent HTTP requests.
     */
    private ?\CurlHandle $connection = null;
    private ?string $connectionIdentity = null;
    private ?RouteCatalog $catalog = null;
    /** @var array{path:string,company_id:int,tenant_prefix:bool}|null */
    private ?array $discoveredRead = null;

    public function issuer(): string
    {
        return $this->config->issuer;
    }

    public function routeCatalog(): RouteCatalog
    {
        return $this->catalog ??= new RouteCatalog();
    }

    public function __construct(private readonly Config $config) {}

    /**
     * Permit exactly one GET derived from an authenticated, validated route
     * declaration. This temporary permit never applies to a later operation.
     *
     * @param array<string,mixed> $approved
     * @param array<string,int|string> $query
     * @return array<string,mixed>
     */
    public function getDiscovered(
        string $email, string $password, array $approved, array $query
    ): array {
        $path = $approved['path'] ?? null;
        $company = $approved['company_id'] ?? null;
        $prefix = $approved['tenant_prefix'] ?? null;
        if ($this->discoveredRead !== null || !is_string($path)
            || preg_match('~^/[a-z0-9-]+(?:/[a-z0-9-]+)*$~D', $path) !== 1
            || !is_int($company) || $company < 1
            || ($query['company_id'] ?? null) !== $company || !is_bool($prefix)) {
            throw new RuntimeException('Invalid discovered read authorization');
        }
        $this->discoveredRead = [
            'path'=>$path, 'company_id'=>$company, 'tenant_prefix'=>$prefix,
        ];
        try {
            return $this->get($email, $password, $path, $query);
        } finally {
            $this->discoveredRead = null;
        }
    }

    public function get(string $email, string $password, string $path, array $query = []): array
    {
        // The installed application's reviewed GET route manifest is the only
        // source of discoverable API paths. Legacy read actions with GET side
        // effects (enable/disable/received) are never in that manifest.
        $internal = preg_match('~^/companies/[1-9][0-9]*/owner$~D', $path) === 1;
        $discovered = $this->discoveredRead !== null
            && $this->discoveredRead['path'] === $path
            && $this->discoveredRead['company_id'] === ($query['company_id'] ?? null);
        // Fixed bootstrap path, not a route supplied by the model. The server
        // permits only authenticated, company-owned discovery metadata.
        $discoveryBootstrap = $path === '/nfse/discovery'
            && is_int($query['company_id'] ?? null)
            && $query['company_id'] > 0;
        if (!$internal && !$discovered && !$discoveryBootstrap
            && !$this->routeCatalog()->permitsPath($path)) {
            throw new RuntimeException('API resource not permitted');
        }
        if ($email === '' || $password === '') {
            throw new RuntimeException('Missing personal credentials');
        }
        // Akaunting modules register /{company_id}/api/nfse/*, whereas the
        // Core registers /api/*. Keep the native tenant route path intact.
        $modulePrefix='';
        if (str_starts_with($path,'/nfse/')
            || ($discovered && $this->discoveredRead['tenant_prefix'])) {
            $company=$query['company_id'] ?? null;
            if (!is_int($company)) throw new RuntimeException('NFS-e company ID is required');
            $modulePrefix='/'.self::companyId($company);
        }
        $url = $this->config->akauntingUrl.$modulePrefix.'/api'. $path;
        if ($query) {
            $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        // Never reuse a Basic-auth connection across different identities or
        // after credential rotation, even in long-running test/CLI consumers.
        $identity = hash('sha256', $email."\0".$password);
        if ($this->connectionIdentity !== null
            && !hash_equals($this->connectionIdentity, $identity)) {
            $this->connection = null;
            $this->connectionIdentity = null;
        }
        if ($this->connection === null) {
            $handle = curl_init();
            if ($handle === false) {
                throw new RuntimeException('Cannot initialize API request');
            }
            $this->connection = $handle;
            $this->connectionIdentity = $identity;
        }
        $handle = $this->connection;
        $body = '';
        $overLimit = false;
        // cURL invokes this callback on *decompressed* chunks. Bound response
        // memory before accepting more data, even for a very small gzip body.
        $receive = static function (\CurlHandle $curl, string $chunk) use (&$body, &$overLimit): int {
            $size = strlen($chunk);
            if ($size > 2_000_000 - strlen($body)) {
                $overLimit = true;
                return 0;
            }
            $body .= $chunk;
            return $size;
        };
        // Set URL, authentication and all security-critical options on EACH
        // call, including after an error, to prevent stale request settings.
        try {
            if (!curl_setopt_array($handle, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_WRITEFUNCTION => $receive,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_USERPWD => $email.':'.$password,
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: LibreCode-Akaunting-MCP/0.1'],
                // Receive compressed JSON without changing the parsed payload.
                // libcurl decodes supported gzip/deflate responses transparently.
                CURLOPT_ENCODING => '',
                CURLOPT_FRESH_CONNECT => false,
                CURLOPT_FORBID_REUSE => false,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => $this->config->allowLoopbackHttp ? (CURLPROTO_HTTP | CURLPROTO_HTTPS) : CURLPROTO_HTTPS,
            ])) {
                throw new RuntimeException('Cannot configure API request');
            }
            $completed = curl_exec($handle);
            $code = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
            if ($completed === false || $overLimit) {
                // A failed transfer can leave a dead socket. The next call
                // starts fresh instead of reusing a possibly broken handle.
                $this->connection = null;
                $this->connectionIdentity = null;
            }
            if ($overLimit) {
                throw new RuntimeException('Akaunting response exceeds safe size');
            }
            if ($completed === false || $code < 200 || $code >= 300) {
                throw new RuntimeException('Akaunting request rejected (HTTP '.($code ?: 'unavailable').')');
            }
            // Company membership check legitimately returns HTTP 200 with an empty body.
            if (trim($body) === '' && $path === '/companies/'.($query['company_id'] ?? '').'/owner') {
                return [];
            }
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new RuntimeException('Unexpected Akaunting response');
            }
            return $data;
        } finally {
            // Keep the connection for the next sequential request in this
            // PHP request. The CurlHandle is closed by PHP object destruction.
            unset($handle);
        }
    }

    /**
     * Native write with independently approved request parameters.
     * The verified write catalog must permit the exact method and path.
     */
    public function mutate(
        string $email, string $password, string $method, string $path, int $companyId,
        array $body, ?string $recordType=null
    ): array {
        if (!(new WriteRouteCatalog())->permits($method,$path)) {
            throw new RuntimeException('Unregistered API mutation');
        }
        return $this->sendJson($email,$password,$method,$path,$companyId,$body,$recordType);
    }

    /** Only two reviewed native read-only POSTs: fiscal bulk preview and Vault diagnosis. */
    public function postReadOnly(
        string $email,string $password,string $path,int $companyId,array $body
    ): array {
        if (!(new NfseReadOnlyPostCatalog())->permitsPath($path)) {
            throw new RuntimeException('Unregistered read-only POST');
        }
        return $this->sendJson($email,$password,'POST',$path,$companyId,$body);
    }

    /** Fresh cURL connection isolates each JSON POST/PUT/PATCH/DELETE from GET. */
    private function sendJson(
        string $email,string $password,string $method,string $path,int $companyId,
        array $body,?string $recordType=null
    ): array {
        if ($email==='' || $password==='') throw new RuntimeException('Missing personal credentials');
        self::companyId($companyId);
        $payload = json_encode($body === [] ? new \stdClass() : $body,
            JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if (strlen($payload) > 32768) throw new RuntimeException('Mutation request too large');

        // Fiscal module routes are tenant-prefixed, unlike the Core API.
        // Keep the company in both the path and query for the native guard.
        $modulePrefix=str_starts_with($path,'/nfse/')?'/'.$companyId:'';
        $query=['company_id'=>$companyId];
        // Akaunting Core chooses the controller ACL from search=type:invoice
        // or search=type:customer, NOT from the JSON body. Without it, even
        // legitimate users receive a misleading HTTP 403 for writes.
        $typedCorePath=preg_match('~^/(documents|contacts)(?:/|$)~D',$path)===1;
        if ($typedCorePath) {
            if ($recordType===null || !preg_match('/^[a-z][a-z0-9_-]{0,49}$/D',$recordType)) {
                throw new RuntimeException('Core entity type required for native write ACL');
            }
            if (isset($body['type']) && $body['type']!==$recordType) {
                throw new RuntimeException('Core write body type does not match approved ACL type');
            }
            $query['search']='type:'.$recordType;
        } elseif ($recordType!==null) {
            throw new RuntimeException('Core entity type is not applicable to this route');
        }
        $url = $this->config->akauntingUrl.$modulePrefix.'/api'.$path.'?'.http_build_query(
            $query, '', '&', PHP_QUERY_RFC3986
        );
        $handle = curl_init();
        if ($handle === false) throw new RuntimeException('Cannot initialize write request');
        $response = '';
        $overLimit = false;
        $receive = static function (\CurlHandle $curl, string $chunk) use (&$response, &$overLimit): int {
            $size = strlen($chunk);
            if ($size > 2_000_000 - strlen($response)) {
                $overLimit = true;
                return 0;
            }
            $response .= $chunk;
            return $size;
        };
        try {
            $settings = [
                CURLOPT_URL=>$url, CURLOPT_CUSTOMREQUEST=>$method,
                CURLOPT_RETURNTRANSFER=>true, CURLOPT_WRITEFUNCTION=>$receive,
                CURLOPT_FOLLOWLOCATION=>false, CURLOPT_MAXREDIRS=>0,
                CURLOPT_HTTPAUTH=>CURLAUTH_BASIC, CURLOPT_USERPWD=>$email.':'.$password,
                CURLOPT_HTTPHEADER=>[
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'User-Agent: LibreCode-Akaunting-MCP/0.2',
                ],
                CURLOPT_ENCODING=>'', CURLOPT_TIMEOUT=>20, CURLOPT_CONNECTTIMEOUT=>5,
                CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_PROTOCOLS=>$this->config->allowLoopbackHttp
                    ? CURLPROTO_HTTP|CURLPROTO_HTTPS : CURLPROTO_HTTPS,
            ];
            if ($method!=='DELETE' || $body!==[]) $settings[CURLOPT_POSTFIELDS]=$payload;
            if (!curl_setopt_array($handle,$settings)) {
                throw new RuntimeException('Cannot configure API mutation');
            }
            $success = curl_exec($handle);
            $code = (int)curl_getinfo($handle,CURLINFO_HTTP_CODE);
            if ($overLimit) throw new RuntimeException('Akaunting response exceeds safe size');
            if ($success === false || $code < 200 || $code >= 300) {
                throw new RuntimeException('Akaunting request rejected (HTTP '.($code?:'unavailable').')');
            }
            if (trim($response) === '') return ['http_status'=>$code];
            $data = json_decode($response,true,64,JSON_THROW_ON_ERROR);
            if (!is_array($data)) throw new RuntimeException('Unexpected Akaunting mutation response');
            return ['http_status'=>$code, 'data'=>ReadResponseGuard::redact($data)];
        } finally {
            unset($handle);
        }
    }
    public function verify(string $email, string $password, int $companyId): void
    {
        self::companyId($companyId);
        // The owner/membership route is itself authenticated via Akaunting's
        // API middleware and returns HTTP 200 only for an assigned company.
        // Each protected read verifies native company membership before
        // consulting any business record.
        $this->get($email, $password, '/companies/'.$companyId.'/owner', ['company_id' => $companyId]);
    }

    public static function companyId(int $id): int
    {
        if ($id <= 0 || $id > 2147483647) {
            throw new RuntimeException('Invalid company ID');
        }
        return $id;
    }
}
