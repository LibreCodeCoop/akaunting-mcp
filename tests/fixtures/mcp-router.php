<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Development and synthetic-smoke router. Production uses two separate nginx edges.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/mcp' || $path === '/.well-known/oauth-protected-resource/mcp') {
    require dirname(__DIR__, 2).'/public/mcp.php';
} else {
    require dirname(__DIR__, 2).'/public/oauth.php';
}
