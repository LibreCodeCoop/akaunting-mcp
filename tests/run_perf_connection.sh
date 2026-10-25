#!/bin/sh
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -eu
cd /app
php -S 127.0.0.1:8875 tests/fixtures/perf_router.php >/tmp/fixture-server.log 2>&1 &
server_pid=$!
trap 'kill "$server_pid" 2>/dev/null || true' EXIT INT TERM
sleep 1
php tests/perf_connection.php
