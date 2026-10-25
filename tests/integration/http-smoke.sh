#!/bin/sh
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -eu
cd /app
umask 077
private_dir=$(mktemp -d /tmp/akaunting-http-smoke.XXXXXX)
upstream_pid=''
mcp_pid=''
cleanup() {
    if [ -n "$mcp_pid" ]; then kill "$mcp_pid" 2>/dev/null || true; fi
    if [ -n "$upstream_pid" ]; then kill "$upstream_pid" 2>/dev/null || true; fi
    rm -rf "$private_dir"
}
trap cleanup EXIT HUP INT TERM
export APP_ENV=testing
export AKAUNTING_ALLOW_INSECURE_LOOPBACK=true
export AKAUNTING_BASE_URL=http://127.0.0.1:8875
export MCP_OAUTH_ISSUER=https://localhost/mcp-auth
export MCP_ALLOW_SEPARATE_ISSUER_HOST=true
export MCP_OAUTH_CLIENT_ID=synthetic-client
export MCP_OAUTH_REDIRECT_URI=https://chatgpt.com/connector/oauth/synthetic
export MCP_RESOURCE_URI=https://api.openai.com/v1/mcp/tunnel_synthetic
export MCP_ALLOWED_HOSTS=localhost,127.0.0.1
export MCP_DATA_DIR="$private_dir/state"
export MCP_TOKEN_ENCRYPTION_KEY_FILE="$private_dir/key"
php -r 'file_put_contents(getenv("MCP_TOKEN_ENCRYPTION_KEY_FILE"),base64_encode(random_bytes(32)));'
php -S 127.0.0.1:8875 tests/fixtures/perf_router.php >"$private_dir/upstream.log" 2>&1 &
upstream_pid=$!
php -S 127.0.0.1:8876 tests/fixtures/mcp-router.php >"$private_dir/mcp.log" 2>&1 &
mcp_pid=$!
sleep 1
php tests/integration/http-smoke.php
