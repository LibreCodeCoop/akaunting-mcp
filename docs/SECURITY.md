<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Security and authorization model

The MCP is an independent, authorization-enforced client of the Akaunting REST API. Akaunting remains the source of identity, authorization and company membership; the MCP adds its own fail-closed controls. Its filtering does **not** repair vulnerabilities in Akaunting for other API clients.

## Authentication and session lifecycle

- Each user authorizes a separate MCP session through OAuth authorization code with PKCE S256, a registered client and an exact callback. The resource audience is fixed and checked for issued access tokens.
- User credentials are validated through the Akaunting API. The MCP stores only the credentials needed for authorized reads, encrypted in a restricted persistent state volume; the secretbox key is mounted separately.
- OAuth browser login tickets are exclusively reserved before upstream authentication. Code redemption and refresh rotation use atomic state transitions to prevent double redemption and loss of a valid code on an upstream transient failure.
- Revocation invalidates the session generation and removes encrypted stored credentials. Disabling or rotating an upstream Akaunting account remains a separate administrative responsibility.

## Data-access boundaries

- Every business request rechecks the linked identity and selected company's membership using the upstream API and its native ACLs.
- A reviewed, explicit native GET route catalog prevents arbitrary HTTP proxying, unknown endpoints and GET handlers that mutate data. A registered route does not imply that the user is authorized to read it.
- Company-scoped responses and nested relationships are validated before they reach optional field projection. User/setting resources receive additional allowlist projections and must not forward unauthorized collection metadata.
- Unexpected tool arguments, invalid IDs, redirect attempts, incompatible record types and unverified tenants fail closed.
- Sensitive fields such as passwords, authentication secrets, signing material and internal errors must not enter model-visible output or application logs. Financial data that the user **intentionally retrieves** may be processed by ChatGPT; review retention, privacy requirements and permitted data transfers accordingly.
- Request bounds, decompressed-body limits and pagination reduce accidental excessive API consumption. Upstream rate limits are not bypassed; HTTP 429 is not treated as an empty authorized result.

## Network and infrastructure

Keep the MCP listener on an isolated, private Compose network. Expose only OAuth discovery, authorization, login and token routes through an HTTPS reverse proxy. Connect the MCP through its configured private tunnel; do not publish the internal MCP endpoint directly. The MCP requires no direct connection to Akaunting database, queue, cache or secrets.

Use separate per-user Akaunting identities and avoid a shared administrator account. Protect the encryption key and state volume independently, restrict backups and review token/credential rotation plans. See [deployment and operation](DEPLOY.md) and [connecting ChatGPT](CONNECTING-CHATGPT.md).

## Validation and limitations

Financial changes use a separate, single-use, 10-minute browser approval flow, never an MCP execution tool. Pending requests are encrypted at rest and bound to user identity, OAuth credential generation, company, exact method, path and JSON payload. Approval demands a matching personal Akaunting login, secure Strict SameSite cookie, per-view CSRF token and an explicit user click. Deletions additionally demand an exact confirmation phrase. Only the approved browser request can execute; retries after unknown results are forbidden. Native ACLs and selected-company membership are verified again before execution. Test allowed and denied roles, company isolation, token revocation, OAuth PKCE and authorization boundaries against a **disposable instance with synthetic accounts**. Never validate writes using production financial data.

For vulnerability reports, follow the [private disclosure policy](../SECURITY.md). Do not publish proof-of-concept records, affected customer details or private infrastructure identifiers in issues, pull requests, logs or this documentation.
