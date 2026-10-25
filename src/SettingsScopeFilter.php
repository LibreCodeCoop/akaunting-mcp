<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace AkauntingMcp;

use RuntimeException;

/**
 * Upstream GET /api/settings is not company scoped: its index uses Setting::all().
 * Only explicitly reviewed non-secret setting fields for the requested company
 * may leave the MCP server, even if upstream returns global/foreign settings.
 */
final class SettingsScopeFilter
{
    private const SAFE_FIELDS = [
        'company.name','company.email','company.tax_number','company.phone',
        'company.address','company.website','company.currency',
        'company.country','company.city','company.zip_code','company.state',
        'company.fiscal_year','company.vat',
        'default.currency','default.list_limit','default.date_format',
        'default.time_format','default.locale','default.language',
        'localisation.timezone','localisation.language','localisation.date_format',
        'localisation.time_format','localisation.first_day_of_week',
    ];

    public static function apply(array $upstream,int $companyId,bool $single=false): array
    {
        $data=$upstream['data'] ?? null;
        if (!is_array($data)) throw new RuntimeException('Invalid upstream settings');
        $rows=$single ? [$data] : $data;
        if (!array_is_list($rows)) throw new RuntimeException('Invalid settings collection');
        $out=[];
        foreach ($rows as $row) {
            if (!is_array($row)) throw new RuntimeException('Invalid setting row');
            if ((string)($row['company_id'] ?? '') !== (string)$companyId) {
                if ($single) throw new RuntimeException('Setting does not belong to company');
                continue;
            }
            $key=$row['key'] ?? null;
            if (!is_string($key) || !in_array($key,self::SAFE_FIELDS,true)) {
                if ($single) throw new RuntimeException('Setting not safely exposed');
                continue;
            }
            $value=$row['value'] ?? null;
            if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value) && $value !== null) {
                if ($single) throw new RuntimeException('Complex setting value not safely exposed');
                continue;
            }
            if (is_string($value) && (strlen($value) > 300
                || preg_match('/-----BEGIN|(?:sk|pat|ghp|xox[bap])-[_A-Za-z0-9]{12,}/i',$value))) {
                if ($single) throw new RuntimeException('Setting value classified as sensitive');
                continue;
            }
            $out[]=['company_id'=>$companyId,'key'=>$key,'value'=>$value];
        }
        if ($single) {
            if (count($out)!==1) throw new RuntimeException('Setting not available');
            return ['data'=>$out[0]];
        }
        // Intentionally no unsafe upstream total. Client may page original data.
        return ['company_id'=>$companyId,'data'=>$out,'returned_on_page'=>count($out),
            'note'=>'Safe company settings only; secret, global and other-tenant settings omitted.',
            'next_page'=>self::nextPage($upstream)];
    }

    private static function nextPage(array $upstream): bool
    {
        $link=$upstream['links']['next'] ?? null;
        if (is_string($link) && $link!=='') return true;
        $meta=$upstream['meta'] ?? [];
        return is_numeric($meta['current_page'] ?? null) && is_numeric($meta['last_page'] ?? null)
            && $meta['last_page']>$meta['current_page'];
    }
}
