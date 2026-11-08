<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace AkauntingMcp;

use RuntimeException;

/**
 * Generic adapter for explicitly advertised, company-owned, side-effect-free
 * JSON GET contracts. Only projected record paths leave this boundary.
 * The source route retains its native ACL on every request.
 */
final class DiscoveredReadService
{
    public function __construct(private readonly Api $api) {}

    /** @param array<string,string> $creds
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function read(array $creds, int $companyId, array $args): array
    {
        $name = $args['route'] ?? null;
        if (!is_string($name) || strlen($name) > 120
            || array_key_exists('fields', $args)
            || array_key_exists('record_type', $args)
            || array_key_exists('search', $args)
            || array_key_exists('page', $args)
            || array_key_exists('limit', $args)) {
            throw new RuntimeException('Discovered API requires exact named route and parameters');
        }

        $discovery = new LiveApiDiscovery($this->api);
        $found = null;
        foreach ($discovery->routes($creds, $companyId) as $spec) {
            if ($spec['name'] === $name) {
                $found = $spec;
                break;
            }
        }
        if ($found === null) {
            throw new RuntimeException('Unknown or unreviewed discovered API route');
        }

        $params = $args['route_params'] ?? [];
        $nfseFilters = $args['nfse_filters'] ?? [];
        $moduleFilters = $args['module_filters'] ?? [];
        if (!is_array($params) || (array_is_list($params) && $params !== [])
            || !is_array($nfseFilters) || !is_array($moduleFilters)
            || (array_is_list($nfseFilters) && $nfseFilters !== [])
            || (array_is_list($moduleFilters) && $moduleFilters !== [])
            || ($nfseFilters !== [] && $moduleFilters !== [])) {
            throw new RuntimeException('Invalid discovered API input structure');
        }
        $approved = $discovery->resolve($found, $params, $companyId);
        $query = $discovery->query($approved['query'],
            $nfseFilters !== [] ? $nfseFilters : $moduleFilters);
        $query['company_id'] = $companyId;

        if ($approved['scope'] === 'invoice') {
            $invoiceId = $params['invoice'];
            $parent = $this->api->get(
                $creds['email'], $creds['password'], '/documents/'.$invoiceId,
                ['company_id'=>$companyId, 'search'=>'type:invoice'],
            );
            $doc = $parent['data'] ?? null;
            if (!is_array($doc) || array_is_list($doc)
                || (string)($doc['company_id'] ?? '') !== (string)$companyId
                || ($doc['type'] ?? null) !== 'invoice'
                || ($doc['id'] ?? null) !== $invoiceId) {
                throw new RuntimeException('Cannot verify discovered API invoice ownership');
            }
            ReadResponseGuard::assertNestedCompany($doc, $companyId);
        }

        $response = $this->api->getDiscovered(
            $creds['email'], $creds['password'], $approved, $query,
        );

        $projected = [];
        foreach ($approved['response_paths'] as $pointer) {
            $parts = array_slice(explode('/', $pointer), 1);
            $value = $response;
            foreach ($parts as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    throw new RuntimeException('Discovered API response contract missing');
                }
                $value = $value[$segment];
            }
            $this->assertRecords($value, $companyId,
                $approved['scope'] === 'invoice' ? $params['invoice'] : null);
            $destination = &$projected;
            foreach ($parts as $segment) {
                if (!isset($destination[$segment])) {
                    $destination[$segment] = [];
                }
                $destination = &$destination[$segment];
            }
            $destination = $value;
            unset($destination);
        }

        $this->assertNoArtifacts($projected);
        ReadResponseGuard::assertNestedCompany($projected, $companyId);

        return [
            'route'=>$name,
            'company_id'=>$companyId,
            'response'=>ReadResponseGuard::redact($projected),
        ];
    }

    private function assertRecords(mixed $data, int $companyId, ?int $invoiceId): void
    {
        if ($data === null || $data === []) {
            return;
        }
        if (!is_array($data)) {
            throw new RuntimeException('Discovered API response is not records');
        }
        $records = array_is_list($data) ? $data : [$data];
        if (count($records) > 50) {
            throw new RuntimeException('Discovered API returns too many records');
        }
        foreach ($records as $record) {
            if (!is_array($record) || array_is_list($record)
                || (string)($record['company_id'] ?? '') !== (string)$companyId
                || ($invoiceId !== null && (string)($record['invoice_id'] ?? '') !== (string)$invoiceId)) {
                throw new RuntimeException('Unverified discovered API record owner');
            }
        }
    }

    /** @param array<array-key,mixed> $value */
    private function assertNoArtifacts(array $value, int $depth = 0): void
    {
        if ($depth > 12) {
            throw new RuntimeException('Discovered API response too deeply nested');
        }
        foreach ($value as $key => $item) {
            if (is_string($key)
                && preg_match('/^(?:raw_?xml|authorized_xml|xml|pdf|pfx|p12|pem|private_key|certificate|artifact_bytes)$/i', $key)) {
                throw new RuntimeException('Discovered API includes binary or fiscal artifact');
            }
            if (is_string($item) && (strlen($item) > 65536
                || preg_match('/^\s*(?:<\?xml\b|<(?:NFSe|DPS)\b|%PDF-|data:application\/pdf)/i', $item) === 1)) {
                throw new RuntimeException('Discovered API contains unsafe document payload');
            }
            if (is_array($item)) {
                $this->assertNoArtifacts($item, $depth + 1);
            }
        }
    }
}
