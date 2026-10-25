<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Install, deploy and operate Akaunting MCP

This guide applies to a new installation of the independent, **read-only** MCP server. It does not install Akaunting or grant a license for Akaunting. Read [licensing](LICENSING.md), [architecture](ARCHITECTURE.md) and [security](SECURITY.md) before connecting a finance system.

## Requirements

- An accessible, HTTPS-enabled Akaunting 3.x REST API and an individual user with appropriate permissions.
- Docker Engine, Docker Compose v2 and an HTTPS reverse proxy under your control.
- An eligible ChatGPT/OpenAI environment with a registered **private MCP tunnel** and OAuth client/callback. Follow [the connection guide](CONNECTING-CHATGPT.md).
- DNS and a valid certificate for the OAuth issuer. Its HTTPS host must match the Akaunting API host unless the separate-host option has been explicitly reviewed.
- A pre-existing Docker network for the public HTTPS reverse proxy if enabling the optional OAuth edge. The Compose file deliberately does not create or configure that external proxy.

The MCP application, OAuth edge and tunnel run in their own Compose project. They must not be attached to the Akaunting database, cache or secret-management networks.

## 1. Obtain the source and prepare configuration

Use a trusted copy or reviewed Git checkout of this repository. From its root:

~~~sh
cp .env.example .env.deploy
chmod 600 .env.deploy
mkdir -p secrets
chmod 700 secrets
~~~

Edit the **local** .env.deploy file. Do not commit it or paste its contents into logs or tickets. The following fields are required:

| Variable | Meaning |
| --- | --- |
| AKAUNTING_BASE_URL | HTTPS origin of the Akaunting REST API |
| MCP_OAUTH_ISSUER | HTTPS authorization-server issuer, including its dedicated path |
| MCP_OAUTH_ORIGIN | HTTPS origin hosting the OAuth browser endpoints |
| MCP_OAUTH_CLIENT_ID | Exact registered client identifier |
| MCP_OAUTH_REDIRECT_URI | Exact callback URI registered for this client (no wildcard) |
| OPENAI_TUNNEL_ID | Registered private tunnel identifier |
| MCP_RESOURCE_URI | Canonical tunnel resource URL; must match the tunnel ID |
| MCP_PROXY_NETWORK | Name of the **existing** Docker proxy network when using the oauth-edge profile |

Also review MCP_ALLOWED_HOSTS, MCP_OAUTH_EDGE_ALIAS, APP_UID and APP_GID for the deployment. Do not copy the example placeholders unchanged into a live configuration. The production resource must use the OpenAI tunnel resource shape validated in [src/Config.php](../src/Config.php). Do not guess a tunnel ID or OAuth callback.

## 2. Provision private secret files

Create a dedicated encryption key. The command below uses a temporary PHP CLI container if PHP is not installed on the host:

~~~sh
docker run --rm --network none \
  --user "$(id -u):$(id -g)" \
  -v "$PWD:/app:ro" -v "$PWD/secrets:/private" -w /app \
  php:8.5.12-cli-alpine php bin/generate-key.php /private/mcp_token_key
~~~

This script refuses to replace an existing key. **Do not regenerate the key for an existing encrypted state volume:** losing it makes previously saved credentials unreadable.

From an interactive terminal, securely enter the restricted tunnel runtime API key:

~~~sh
bash bin/save-runtime-key.sh
~~~

It writes secrets/openai_tunnel_runtime_api_key with restricted filesystem permissions without echoing the value.

**Verify ownership for non-root containers.** Docker Compose file-backed secrets can preserve the *host file's* owner and mode instead of applying the optional Compose secret UID/GID mapping. The application image runs as UID 10001 and the tunnel runs as the APP_UID/APP_GID configured in .env.deploy. If the operator who generated the keys owns files with mode 0600, these containers may fail to read them. For the example configuration where both services run as UID/GID 10001, an authorized deployment administrator can prepare the secret files with:

~~~sh
sudo chown 10001:10001 secrets/mcp_token_key secrets/openai_tunnel_runtime_api_key
sudo chmod 0400 secrets/mcp_token_key secrets/openai_tunnel_runtime_api_key
~~~

If APP_UID/APP_GID differ from 10001, grant the tunnel user read access to **its own** secret instead; the application key must remain readable by UID 10001. Keep the parent secrets/ directory restricted, and test access as the relevant container users. Do not make the key files world-readable to work around ownership errors. Keep both files out of Git, CI artifacts and troubleshooting output; use encrypted backups and an approved rotation procedure.

## 3. Prepare the HTTPS OAuth edge

The Compose oauth-edge profile connects a dedicated Nginx instance to the existing external proxy network, but it does not itself provision TLS or the host-level virtual host. Review [the example reverse-proxy locations](../docker/nginx/akaunting-proxy-locations.conf.example) and configure them for **your** hostname and the issuer path.

Allow only the OAuth discovery, authorization, login and token endpoints through the public HTTPS proxy. Do **not** expose the internal MCP endpoint, local service ports, health endpoints or state files to the internet. The private tunnel must connect to the internal MCP service over its isolated Docker network.

See [connecting ChatGPT](CONNECTING-CHATGPT.md) for callback registration and client settings.

## 4. Validate and start the service

Check configuration structure without printing resolved configuration or credentials:

~~~sh
docker compose --env-file .env.deploy --profile oauth-edge --profile chatgpt config --quiet
~~~

Run the offline/synthetic suite described in [development and testing](DEVELOPMENT.md). After configuring TLS, the external network and registered OAuth/tunnel credentials, an operator may start the approved deployment:

~~~sh
docker compose --env-file .env.deploy --profile oauth-edge --profile chatgpt up -d --build
~~~

Use the profiles that match the chosen deployment; the base app and internal Nginx do not publish a public HTTP port. Start only in an environment whose upstream version, network boundaries, license and rollback arrangements have been reviewed.

## 5. Confirm behavior without financial changes

- Check HTTPS OAuth metadata and PKCE browser login using an account intended for the connection.
- Confirm the linked identity with the read-only akaunting_whoami tool and verify that writes are disabled.
- Confirm a small authorized company-scoped read and rejection of a company/resource that a synthetic account cannot access.
- Confirm the internal MCP endpoint is reachable only through the private tunnel.
- Check that logs contain no passwords, OAuth codes, tokens, financial records or stack traces.

Never use cloned real financial records, production mutations or administrative credentials as a shortcut for integration testing. The MCP does not provide NFS-e issuance, record modifications or permission management.

## Operation, updates and rollback

Record the approved source revision and image digest, state-volume reference, relevant configuration hash and last known-good configuration **privately** before upgrading. Back up the encrypted state volume and the encryption key separately, with restricted access and tested restoration. Do not send customer accounting data to CI or development environments.

After an Akaunting or installed-module update, regenerate the allowed route catalog from the **target application** following [the API contract](API-READ-CONTRACT.md), manually review new GET routes for side effects, authorization and company scope, then rerun the security and synthetic tests. Never automatically enable newly discovered module routes.

The Nginx edge resolves the private PHP service through Docker's internal DNS
on each request. After an application replacement, verify both the private
MCP resource metadata and the public OAuth discovery endpoint **without**
restarting the proxies. The Docker smoke checks this replacement scenario;
if either endpoint fails, restore the previous image and investigate the
internal service name, health and DNS before accepting the release.

**One-time migration from old static Nginx configuration:** an existing
Nginx container may still reference an older *inode* of
`docker/nginx/mcp.conf` or `docker/nginx/oauth.conf` after a checkout or
atomic file replacement. Merely calling `nginx -s reload` does not update
a stale Docker file bind mount. After validating the new configuration and
preserving a rollback, recreate only the two Nginx services while the old
PHP application is still running:

~~~sh
docker compose --env-file .env.deploy --profile oauth-edge --profile chatgpt \
  up --no-build --no-deps --force-recreate -d nginx oauth-edge
~~~

Confirm that both MCP and OAuth discovery still work before recreating
`app`. Subsequent application-only updates benefit from dynamic Docker DNS
and do not need to restart the proxies. The synthetic Docker smoke tests
this static-to-dynamic migration and the following application replacement.

On regression, revert the MCP image and configuration without deleting persistent volumes. Check that the former version can still read the encrypted state format before restoring service. Revoke linked accounts via the supported MCP revocation mechanism and disable upstream credentials where appropriate.

**Operational limits:** API reads are constrained by Akaunting ACLs and company membership, and upstream authorization errors or HTTP 429 are not ignored. The MCP does not correct upstream API defects for applications other than this connector. Report potential vulnerabilities privately using [the security policy](../SECURITY.md).
