<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Connect ChatGPT to the private Akaunting MCP

This guide covers **connecting a deployed MCP** to ChatGPT. For infrastructure prerequisites, secrets, Docker Compose and HTTPS setup, start with [deployment and operation](DEPLOY.md). An individual Akaunting user and a valid private-tunnel registration are required; the MCP is not an official Akaunting product.

## Register the connection

1. In the eligible ChatGPT/OpenAI environment, provision a private MCP tunnel for this server and an appropriate restricted runtime credential. Verify the currently supported tunnel registration method for your workspace.
2. Register the OAuth client and the **exact** redirect URI required by this ChatGPT connection. The broker uses authorization code with PKCE S256 and an explicit client ID; it does not accept wildcard redirects.
3. Set AKAUNTING_BASE_URL, MCP_OAUTH_ISSUER, MCP_OAUTH_ORIGIN, MCP_OAUTH_CLIENT_ID, MCP_OAUTH_REDIRECT_URI, OPENAI_TUNNEL_ID and MCP_RESOURCE_URI in the private configuration described in [DEPLOY.md](DEPLOY.md). The resource audience must match the registered tunnel, and the issuer needs its own path under HTTPS.
4. Configure the external HTTPS proxy to expose only the OAuth browser endpoints. The internal MCP HTTP transport must remain private; do not paste a Docker-internal URL into a public MCP URL field.
5. Connect ChatGPT using the supported **private tunnel** method and the registered client and scope. The server advertises the OAuth scope mcp:read, including for native write tools that require explicit host confirmation. Complete the browser login with an individual Akaunting account assigned to the intended company.
6. Test akaunting_whoami before accessing any business record. Validate one small authorized read and denial of an unassigned company or unauthorized resource using synthetic test accounts.

When upgrading the MCP server with new tools or updated input schemas, refresh the existing ChatGPT workspace app's action catalog and explicitly enable the reviewed new actions before testing them in a new conversation. Redeploying the service or tunnel does **not** automatically update the previously approved ChatGPT tool snapshot. After refreshing the tool catalog, verify the new non-read-only `akaunting_execute_prepared_mutation` action is available and that ChatGPT requests user confirmation before invoking it. Use `approval_transport=browser` only for the legacy fallback. No additional Akaunting login should be necessary for the native path.

The [non-secret configuration example](../.env.example) illustrates the required variable names. Example callback and tunnel values are placeholders, **not universal configuration values**.

## Authentication behavior

The broker validates the Akaunting account against the selected company, stores the required credentials encrypted at rest, and issues short-lived MCP access tokens with a fixed resource audience. The MCP then checks the upstream user and company permissions for each requested business read. Read and write routes are restricted to reviewed native API patterns. Each write must be explicitly authorized by the user through ChatGPT's native MCP tool confirmation; the already-linked personal OAuth identity is rechecked by the server. The legacy issuer-host browser flow is optional. Do not trust a client that executes mutating tools without asking the user.

A route appearing in the catalog does not imply that the connected user can access it. Cross-company or unverified response data must not be returned by the MCP. Reviewed company-scoped financial and fiscal mutations are available only through a single-use approved proposal and an explicit host-confirmed write call; account/role permission changes, arbitrary paths, certificate/secret uploads and binary downloads are not enabled. If a ChatGPT client cannot enforce human confirmation for mutating tools, keep those tools disabled.

## Troubleshooting safely

| Symptom | What to check |
| --- | --- |
| Issuer or callback mismatch | Exact HTTPS issuer path, client registration, redirect URI and allowed origins |
| Authorization fails | The user's active Akaunting account, selected company membership and API privileges |
| Tool available but record denied | Native Akaunting ACLs, required document/contact type, selected company and module route availability |
| HTTP 429 | Upstream or intermediate rate limits; do not disable security controls or blindly retry |
| Tunnel unavailable | Private tunnel registration, restricted runtime credential, internal DNS and MCP endpoint readiness |

When investigating, inspect only error categories and bounded timing metrics. Never copy login passwords, tokens, authorization codes, decrypted state, customer financial data or internal server addresses into public logs, issues or chat transcripts.

See [security model](SECURITY.md), [supported API routes](API-READ-CONTRACT.md) and [development checks](DEVELOPMENT.md).
