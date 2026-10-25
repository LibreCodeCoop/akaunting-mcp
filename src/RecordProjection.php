<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace AkauntingMcp;

use RuntimeException;

/**
 * Optional output-only projection: never used to make authorization decisions.
 * All upstream records must first pass ACL, tenant checks and secret filtering.
 * Field selection is deliberately shallow; relationships remain available
 * without projection for tasks which genuinely require them.
 */
final class RecordProjection
{
    public static function validate(mixed $fields): ?array
    {
        if ($fields === null) {
            return null;
        }
        if (!is_array($fields) || !array_is_list($fields)
            || $fields === [] || count($fields) > 12) {
            throw new RuntimeException('fields must contain 1 to 12 record keys');
        }
        $valid = [];
        foreach ($fields as $field) {
            if (!is_string($field) || preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,49}$/D', $field) !== 1) {
                throw new RuntimeException('Invalid field name');
            }
            $valid[$field] = true;
        }
        return array_keys($valid);
    }

    public static function apply(array $result, ?array $fields): array
    {
        if ($fields === null) {
            return $result;
        }
        $data = $result['data'] ?? null;
        if (!is_array($data)) {
            throw new RuntimeException('Record field projection unavailable');
        }
        $wanted = array_fill_keys([...$fields, 'id', 'company_id', 'type'], true);
        if (array_is_list($data)) {
            $result['data'] = array_map(static function ($row) use ($wanted): array {
                if (!is_array($row)) {
                    throw new RuntimeException('Unexpected projected record');
                }
                return array_intersect_key($row, $wanted);
            }, $data);
        } else {
            $result['data'] = array_intersect_key($data, $wanted);
        }
        return $result;
    }
}
