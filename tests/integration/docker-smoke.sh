#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
# Exercise an isolated Compose topology using synthetic configuration only.
# NEVER start the tunnel profile or contact a live accounting server.
set -euo pipefail

cd "$(dirname "$0")/../.."
umask 077

smoke_dir=$(mktemp -d "${TMPDIR:-/tmp}/akaunting-mcp-smoke.XXXXXXXX")
project="akaunting-mcp-smoke-$$"
proxy_network="${project}-proxy"
# The host may have exhausted its Docker default bridge pool. Use two isolated
# synthetic networks in a reserved benchmarking address range, not production.
subnet_octet=$(( ($$ % 120) * 2 ))
backend_subnet="198.18.${subnet_octet}.0/24"
proxy_subnet="198.18.$((subnet_octet + 1)).0/24"
export SMOKE_DIR="$smoke_dir" MCP_PROXY_NETWORK="$proxy_network"
export MCP_SMOKE_BACKEND_SUBNET="$backend_subnet"
created_network=0

compose() {
  docker compose \
    -p "$project" --profile oauth-edge \
    -f docker-compose.yml -f "$smoke_dir/compose.override.yml" \
    --env-file "$smoke_dir/env" "$@"
}

cleanup() {
  if [ "$created_network" -eq 1 ]; then
    compose down --volumes --remove-orphans >/dev/null 2>&1 || true
    docker network rm "$proxy_network" >/dev/null 2>&1 || true
  fi
  case "$smoke_dir" in
    "${TMPDIR:-/tmp}"/akaunting-mcp-smoke.*)
      rm -rf -- "$smoke_dir"
      ;;
  esac
}
trap cleanup EXIT

mkdir -p "$smoke_dir/secrets"
chmod 700 "$smoke_dir" "$smoke_dir/secrets"
openssl rand -base64 32 > "$smoke_dir/secrets/mcp_token_key"
printf 'synthetic-unused-tunnel-credential\n' > "$smoke_dir/secrets/openai_tunnel_runtime_api_key"
chmod 600 "$smoke_dir/secrets/"*
# File-backed Compose secrets preserve host ownership. Make synthetic secrets
# readable only by the non-root application user (UID 10001).
docker run --rm --network none --user 0:0 \
  -v "$smoke_dir/secrets:/private" --entrypoint sh akaunting-mcp:ci \
  -c 'chown 10001:10001 /private/mcp_token_key /private/openai_tunnel_runtime_api_key && chmod 0400 /private/*'

cat > "$smoke_dir/env" <<'EOF'
APP_UID=10001
APP_GID=10001
AKAUNTING_BASE_URL=https://accounting.example.invalid
MCP_OAUTH_ISSUER=https://accounting.example.invalid/akaunting-mcp-auth
MCP_OAUTH_ORIGIN=https://accounting.example.invalid
MCP_OAUTH_CLIENT_ID=synthetic-oauth-client
MCP_OAUTH_REDIRECT_URI=https://chatgpt.com/connector/oauth/synthetic
MCP_RESOURCE_URI=https://api.openai.com/v1/mcp/tunnel_0123456789abcdef0123456789abcdef
OPENAI_TUNNEL_ID=tunnel_0123456789abcdef0123456789abcdef
MCP_ALLOWED_HOSTS=nginx,127.0.0.1,localhost,accounting.example.invalid
MCP_PROXY_NETWORK=synthetic-overridden-by-environment
MCP_OAUTH_EDGE_ALIAS=akaunting-mcp-oauth-edge
EOF

# Reproduce upgrading an installation whose Nginx file mounts were
# initialized before dynamic Docker DNS resolution was implemented.
# These synthetic copies are the only files modified during the smoke.
mkdir -p "$smoke_dir/proxy-conf"
for name in mcp oauth; do
  sed -e '/resolver 127\.0\.0\.11 valid=5s ipv6=off;/d' \
      -e '/set \$mcp_php_backend app:9000;/d' \
      -e 's/fastcgi_pass \$mcp_php_backend;/fastcgi_pass app:9000;/' \
      "docker/nginx/$name.conf" > "$smoke_dir/proxy-conf/$name.conf"
  chmod 0644 "$smoke_dir/proxy-conf/$name.conf"
done

cat > "$smoke_dir/compose.override.yml" <<'EOF'
services:
  app:
    image: akaunting-mcp:ci
  nginx:
    volumes:
      - ${SMOKE_DIR}/proxy-conf/mcp.conf:/etc/nginx/conf.d/default.conf:ro
  oauth-edge:
    volumes:
      - ${SMOKE_DIR}/proxy-conf/oauth.conf:/etc/nginx/conf.d/default.conf:ro
secrets:
  mcp_token_key:
    file: ${SMOKE_DIR}/secrets/mcp_token_key
  openai_tunnel_runtime_api_key:
    file: ${SMOKE_DIR}/secrets/openai_tunnel_runtime_api_key
networks:
  backend:
    internal: true
    ipam:
      config:
        - subnet: ${MCP_SMOKE_BACKEND_SUBNET}
EOF

# Do not create or interact with the real deployment's proxy network.
docker network create --internal --subnet "$proxy_subnet" "$proxy_network" >/dev/null
created_network=1
php_smoke() {
  docker run --rm -i --network none --user "$(id -u):$(id -g)" \
    -v "$PWD/tests/integration:/app/tests/integration:ro" \
    -v "$smoke_dir:/smoke:ro" \
    -e SMOKE_DIR="$smoke_dir" -e MCP_PROXY_NETWORK="$proxy_network" \
    -e MCP_SMOKE_BACKEND_SUBNET="$backend_subnet" \
    -w /app --entrypoint php akaunting-mcp:ci \
    tests/integration/check-docker-smoke.php "$@"
}

compose config --quiet
compose config --format json | php_smoke config

# Verify the exact runtime package before starting services. Installation
# utilities remain available in the source checkout, not in the runtime image.
docker run --rm --network none --entrypoint sh akaunting-mcp:ci -ec '
  test -f /app/bin/revoke-user.php
  test -f /app/public/mcp.php
  test -f /app/public/oauth.php
  test ! -e /app/bin/generate-key.php
  test ! -e /app/bin/generate-api-catalog.php
  test ! -e /app/bin/save-runtime-key.sh
  test ! -e /app/public/router.php
  test ! -e /app/vendor
  test ! -e /app/vendor-bin
  test ! -e /app/tests
  test ! -e /app/tools
'

# Only app + Nginx proxies. The remote tunnel must remain stopped.
compose up -d --no-build app nginx oauth-edge >/dev/null
if compose ps --services --status running | grep -Fxq tunnel; then
  echo "FAIL: private tunnel started in synthetic smoke" >&2
  exit 1
fi

# Verify FPM through Nginx, not a single-container PHP built-in server.
protected="$smoke_dir/resource.json"
ready=0
for _ in $(seq 1 30); do
  if compose exec -T nginx wget -T 3 -t 1 -q -O - \
    http://127.0.0.1:8080/.well-known/oauth-protected-resource/mcp > "$protected" 2>/dev/null; then
    ready=1
    break
  fi
  sleep 1
done
if [ "$ready" -ne 1 ]; then
  echo "FAIL: internal MCP service did not become ready" >&2
  exit 1
fi

php_smoke resource

metadata="$smoke_dir/oauth.json"
compose exec -T oauth-edge wget -T 4 -t 1 -q \
  --header='Host: accounting.example.invalid' -O - \
  http://127.0.0.1:8081/.well-known/oauth-authorization-server/akaunting-mcp-auth > "$metadata"
php_smoke oauth

# Unauthenticated tool metadata is permitted on the PRIVATE transport only.
tools_json="$smoke_dir/tools.json"
compose exec -T nginx wget -T 4 -t 1 -q \
  --post-data='{"jsonrpc":"2.0","id":1,"method":"tools/list"}' \
  -O - http://127.0.0.1:8080/mcp > "$tools_json"
php_smoke tools

# Atomically replacing a host config file does not update the inode of an
# existing Docker file bind mount. Simulate the migration from static upstream
# resolution and require a one-time proxy recreation to bind the new configs.
for name in mcp oauth; do
  cp "docker/nginx/$name.conf" "$smoke_dir/proxy-conf/$name.conf.next"
  chmod 0644 "$smoke_dir/proxy-conf/$name.conf.next"
  mv "$smoke_dir/proxy-conf/$name.conf.next" "$smoke_dir/proxy-conf/$name.conf"
done
if compose exec -T nginx grep -Fq 'resolver 127.0.0.11' /etc/nginx/conf.d/default.conf; then
  echo "FAIL: file bind mount unexpectedly followed atomic config replacement" >&2
  exit 1
fi
compose up -d --no-build --no-deps --force-recreate nginx oauth-edge >/dev/null
compose exec -T nginx grep -Fq 'resolver 127.0.0.11' /etc/nginx/conf.d/default.conf
compose exec -T oauth-edge grep -Fq 'resolver 127.0.0.11' /etc/nginx/conf.d/default.conf
proxy_migration_ready=0
for _ in $(seq 1 15); do
  if compose exec -T nginx wget -T 3 -t 1 -q -O /dev/null \
      http://127.0.0.1:8080/.well-known/oauth-protected-resource/mcp 2>/dev/null \
    && compose exec -T oauth-edge wget -T 3 -t 1 -q \
      --header='Host: accounting.example.invalid' -O /dev/null \
      http://127.0.0.1:8081/.well-known/oauth-authorization-server/akaunting-mcp-auth 2>/dev/null; then
    proxy_migration_ready=1
    break
  fi
  sleep 1
done
if [ "$proxy_migration_ready" -ne 1 ]; then
  echo "FAIL: file-bind migration broke OAuth or MCP proxy" >&2
  exit 1
fi

# Replacing only the PHP app must not break either long-lived Nginx proxy.
# Test observable recovery, not a private container-IP implementation detail.
compose up -d --no-build --no-deps --force-recreate app >/dev/null
post_recreate_ready=0
for _ in $(seq 1 20); do
  if compose exec -T nginx wget -T 3 -t 1 -q -O /dev/null \
      http://127.0.0.1:8080/.well-known/oauth-protected-resource/mcp \
      2>/dev/null \
    && compose exec -T oauth-edge wget -T 3 -t 1 -q \
      --header='Host: accounting.example.invalid' -O /dev/null \
      http://127.0.0.1:8081/.well-known/oauth-authorization-server/akaunting-mcp-auth \
      2>/dev/null; then
    post_recreate_ready=1
    break
  fi
  sleep 1
done
if [ "$post_recreate_ready" -ne 1 ]; then
  echo "FAIL: proxies cannot serve OAuth/MCP after application replacement" >&2
  exit 1
fi

# Verify that the public-facing OAuth edge cannot reach MCP or internal URLs.
for path in mcp oauth/token healthz; do
  response=$(compose exec -T oauth-edge wget -T 4 -t 1 -S \
    --header='Host: accounting.example.invalid' \
    -O /dev/null "http://127.0.0.1:8081/$path" 2>&1 || true)
  if [[ "$response" != *"404 Not Found"* ]]; then
    echo "FAIL: OAuth edge unexpectedly allowed an internal route" >&2
    exit 1
  fi
done
response=$(compose exec -T oauth-edge wget -T 4 -t 1 -S \
  --header='Host: attacker.example.invalid' -O /dev/null \
  http://127.0.0.1:8081/.well-known/oauth-authorization-server/akaunting-mcp-auth 2>&1 || true)
if [[ "$response" != *"421 Misdirected Request"* ]]; then
  echo "FAIL: OAuth edge accepted an unrelated host" >&2
  exit 1
fi

echo "Docker Compose smoke passed: synthetic secrets, isolated network, no public MCP or live tunnel"
