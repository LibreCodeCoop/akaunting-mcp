<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace AkauntingMcp;

use RuntimeException;

/**
 * Business boundary shared by the legacy resource tools and native API reads.
 * Every read must validate tenant ownership before optional field projection.
 */
final class ReadResponseGuard
{
    public static function assertNestedCompany(array $record, int $companyId, int $depth = 0): void
    {
        if ($depth > 12) {
            throw new RuntimeException('Nested resource exceeds safe depth');
        }
        if (array_key_exists('company_id', $record)
            && (string) $record['company_id'] !== (string) $companyId) {
            throw new RuntimeException('Nested resource company mismatch');
        }
        foreach ($record as $value) {
            if (is_array($value)) {
                self::assertNestedCompany($value, $companyId, $depth + 1);
            }
        }
    }

    public static function redact(array $record, int $depth = 0): array
    {
        if ($depth > 12) {
            // Unexpected recursive data is not partially exposed.
            throw new RuntimeException('Response nesting exceeds safe depth');
        }
        $safe = [];
        foreach ($record as $key => $value) {
            if (is_string($key) && preg_match(
                '/(?:password|secret|token|api[_-]?key|private[_-]?key|certificate|pfx|pem|passphrase|signing[_-]?key|authorization|credential|vault[_-]?path)/i',
                $key,
            ) === 1) {
                continue;
            }
            $safe[$key] = is_array($value) ? self::redact($value, $depth + 1) : $value;
        }
        return $safe;
    }
}
