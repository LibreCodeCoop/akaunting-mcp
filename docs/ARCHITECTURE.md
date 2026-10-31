<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Architecture and trust boundaries

The service is a separate, approval-gated PHP MCP server for Akaunting 3.x. It does
not install code into Akaunting and does not implement tax/NFS-e issuance.

## Request lifecycle

1. ChatGPT negotiates the supported legacy MCP Streamable HTTP protocol
   (2025-06-18 or 2025-03-26) through a private tunnel.
2. An independent OAuth broker validates a pre-registered client, exact redirect
   URI, the single resource audience, CSRF ticket and PKCE S256 challenge.
3. User credentials are validated against Akaunting's authenticated company
   membership endpoint before an encrypted personal credential record is created.
4. The broker issues a short-lived JWT access token and a rotating refresh
   token. Ticket reservation, code consumption and refresh rotation are
   protected by an exclusive state lock. An upstream 429 during code/refresh
   issuance does not destroy the still-valid credential.
5. A tool operation loads the linked identity and checks native company
   membership before reading an allowlisted API resource.
6. The returned records are checked for company/type consistency before secret
   filtering and optional top-level field projection. Foreign-company nested
   data is rejected rather than silently mixed into the selected company.

The bearer audience, issuer, client, scope, expiry and local generation are
validated on each access. Revocation disables the generation and removes
encrypted credentials. There is no shared cross-user response cache.

## Code map

- `src/Config.php`: strict origins, required configuration and private storage.
- `src/Store.php`: encrypted credentials, atomic writes and file locks.
- `src/OAuth.php`: code/PKCE, refresh, JWT, revocation and concurrency.
- `public/oauth.php`: browser login, metadata, safe errors and HTTP headers.
- `public/mcp.php` and `src/Mcp.php`: private HTTP and JSON-RPC tool transport.
- `src/Api.php`: authenticated REST GET, TLS checks, redirect refusal, response cap.
- `src/RouteCatalog.php` and `src/api-routes.json`: reviewed named GET route allowlist.
- `src/ApiBrowser.php` and `src/Toolset.php`: authorization-aware read tools.
- `src/UserScopeFilter.php` and `src/SettingsScopeFilter.php`: special fail-closed
  projections for unsafe upstream listing endpoints.
- `src/RecordProjection.php`: output-only field selection; never controls authorization.

## Tool and route policy

Twelve tools are exposed. Nine are read-only; the additional tools discover mutating routes, prepare browser approvals and read their status. The model cannot execute a mutation. The repository includes a
reviewed reference API-route manifest with core and optional module routes.
The manifest is not an inventory of a customer's installed modules, proof of
endpoint availability, or evidence of individual authorization. Each target
deployment must regenerate and review its own registered routes before
enabling optional integrations. Route changes require side-effect, ACL,
company-isolation and regression testing. Unknown or mutating GET operations
must never be proxied.

The stock Akaunting users and settings collection APIs have known tenant
boundary defects. Their raw responses and global collection metadata must not
be returned. Every tool remains subject to Akaunting's own permissions.

## Deployment boundary

The MCP app and its internal HTTP reverse proxy occupy a private Docker
network, distinct from financial application and database networks. A separate
OAuth-facing reverse proxy exposes only the minimum browser endpoints. The
MCP endpoint is reachable by ChatGPT through the private tunnel; no finance
database or queue network is attached.

Advertise only MCP protocol revisions covered by interoperability tests.
Before adding a new protocol version, verify per-request metadata, HTTP
headers, OAuth discovery and client compatibility in the synthetic HTTP suite.
