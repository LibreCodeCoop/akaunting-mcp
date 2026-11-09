<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace AkauntingMcp;

use RuntimeException;

/**
 * Explicit, evidence-backed exceptions to HTTP's GET-only read convention.
 * These native POST routes perform a pure calculation or an operational probe.
 * They do not persist changes, allocate DPS, enqueue jobs or submit invoices.
 */
final class NfseReadOnlyPostCatalog
{
    private const ROUTES = [
        'api.nfse.bulk.plan' => [
            'path'=>'/nfse/bulk/plan', 'permission'=>'read-sales-invoices',
        ],
        'api.nfse.settings.vault.diagnostics' => [
            'path'=>'/nfse/settings/vault/diagnostics','permission'=>'read-nfse-settings',
        ],
    ];

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        $out=[];
        foreach (self::ROUTES as $name=>$route) {
            $out[]=[
                'route'=>$name,'uri'=>'{company_id}/api'.$route['path'],
                'methods'=>['POST'],'source'=>'module','parameters'=>[],
                'required_permission_hints'=>[$route['permission']],
                'transport'=>'reviewed_read_only_post',
            ];
        }
        return $out;
    }

    public function contains(string $name): bool
    {
        return array_key_exists($name,self::ROUTES);
    }

    public function resolve(string $name,array $params): string
    {
        $route=self::ROUTES[$name]??null;
        if ($route===null || $params!==[]) {
            throw new RuntimeException('Unknown reviewed read-only POST or path parameters');
        }
        return $route['path'];
    }

    public function permitsPath(string $path): bool
    {
        foreach (self::ROUTES as $route) {
            if ($route['path']===$path) return true;
        }
        return false;
    }

    public function validateBody(string $name,array $body): void
    {
        if (!$this->contains($name)) throw new RuntimeException('Unreviewed read-only POST');
        if ($name==='api.nfse.settings.vault.diagnostics') {
            if ($body!==[]) throw new RuntimeException('Vault diagnostics accepts no request body');
            return;
        }
        if (array_keys($body)!==['invoice_ids']) {
            throw new RuntimeException('Bulk preview requires only invoice_ids');
        }
        $ids=$body['invoice_ids'];
        if (!is_array($ids) || !array_is_list($ids)
            || count($ids)<1 || count($ids)>100) {
            throw new RuntimeException('Bulk preview requires 1-100 invoice IDs');
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id<1 || $id>2147483647) {
                throw new RuntimeException('Invalid invoice identifier');
            }
        }
        if (count(array_unique($ids))!==count($ids)) {
            throw new RuntimeException('Duplicate invoice identifier');
        }
    }
}
