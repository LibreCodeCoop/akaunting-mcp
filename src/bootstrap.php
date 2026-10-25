<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'AkauntingMcp\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__.'/'.substr($class,strlen($prefix)).'.php';
        if (is_file($file)) require $file;
    }
});
