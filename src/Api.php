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

    public function routeCatalog(): RouteCatalog
    {
        return $this->catalog ??= new RouteCatalog();
    }

    public function __construct(private readonly Config $config) {}

    public function get(string $email, string $password, string $path, array $query = []): array
    {
        // The installed application's reviewed GET route manifest is the only
        // source of discoverable API paths. Legacy read actions with GET side
        // effects (enable/disable/received) are never in that manifest.
        $internal = preg_match('~^/companies/[1-9][0-9]*/owner$~D', $path) === 1;
        if (!$internal && !$this->routeCatalog()->permitsPath($path)) {
            throw new RuntimeException('API resource not permitted');
        }
        if ($email === '' || $password === '') {
            throw new RuntimeException('Missing personal credentials');
        }
        $url = $this->config->akauntingUrl.'/api'. $path;
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
