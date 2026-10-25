<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace AkauntingMcp;

use RuntimeException;

/**
 * Fail-closed projection of Akaunting's flawed users collection. Upstream
 * indexing may return users of other companies; never disclose them or reuse
 * unfiltered metadata, embedded owner information or role memberships.
 *
 * This is an independent defense-in-depth boundary, not a substitute for
 * Akaunting's native read-auth-users permission and API authentication.
 */
final class UserScopeFilter
{
    public static function page(array $response, int $companyId, int $page): array
    {
        $items = $response['data'] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            throw new RuntimeException('Invalid upstream users collection');
        }

        $visible = [];
        foreach ($items as $record) {
            if (!is_array($record)) {
                throw new RuntimeException('Invalid upstream user resource');
            }
            $userId = $record['id'] ?? null;
            if (!is_int($userId) || $userId <= 0) {
                throw new RuntimeException('Unverifiable user identity');
            }

            $companies = $record['companies']['data'] ?? null;
            if (!is_array($companies) || !array_is_list($companies)) {
                // No company evidence means no disclosure.
                continue;
            }
            $member = false;
            foreach ($companies as $company) {
                if (is_array($company) && isset($company['id'])
                    && (string) $company['id'] === (string) $companyId) {
                    $member = true;
                    break;
                }
            }
            if (!$member) {
                continue;
            }

            $roles = $record['roles']['data'] ?? [];
            if (!is_array($roles) || !array_is_list($roles)) {
                $roles = [];
            }
            $safeRoles = [];
            foreach ($roles as $role) {
                if (!is_array($role) || !is_int($role['id'] ?? null)) {
                    continue;
                }
                $safeRoles[] = [
                    'id' => $role['id'],
                    'name' => is_string($role['name'] ?? null) ? $role['name'] : null,
                    'code' => is_string($role['code'] ?? null) ? $role['code'] : null,
                ];
            }
            $visible[] = [
                'id' => $userId,
                'name' => is_string($record['name'] ?? null) ? $record['name'] : null,
                'email' => is_string($record['email'] ?? null) ? $record['email'] : null,
                'enabled' => (bool) ($record['enabled'] ?? false),
                'roles' => $safeRoles,
            ];
        }

        $upstreamNext = $response['links']['next'] ?? null;
        $hasNext = is_string($upstreamNext) && $upstreamNext !== '';
        if (!$hasNext) {
            $meta = $response['meta'] ?? [];
            $currentPage = $meta['current_page'] ?? null;
            $lastPage = $meta['last_page'] ?? null;
            if (is_int($currentPage) && is_int($lastPage)) {
                $hasNext = $lastPage > $currentPage;
            }
        }

        return [
            'company_id' => $companyId,
            'data' => $visible,
            'page' => $page,
            'visible_on_page' => count($visible),
            // An accurate total is available only after scanning all pages.
            // Never propagate the upstream total: it counts other tenants.
            'upstream_has_next_page' => $hasNext,
            'effective_permissions_exposed_by_api' => false,
        ];
    }
}
