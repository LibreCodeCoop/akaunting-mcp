<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
use AkauntingMcp\Config;
use AkauntingMcp\Store;
require dirname(__DIR__).'/src/bootstrap.php';
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--confirm' || !is_string($argv[2] ?? null)) {
    fwrite(STDERR,"Usage: php bin/revoke-user.php --confirm account@example.invalid\n");
    exit(2);
}
(new Store(Config::fromEnvironment()))->revoke($argv[2]);
echo "Personal MCP access revoked. Also disable the upstream Akaunting credentials.\n";
