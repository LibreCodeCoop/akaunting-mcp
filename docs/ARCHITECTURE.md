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
- `src/Api.php`: authenticated REST transport, TLS checks, redirect refusal and response caps.
- `src/RouteCatalog.php` and `src/api-routes.json`: reviewed named GET route allowlist.
- `src/ApiBrowser.php` and `src/Toolset.php`: authorization-aware reads and approval proposals.
- `src/NfseReadCatalog.php`, `src/NfseReadService.php` and `src/NfseWriteCatalog.php`:
  reviewed fiscal API contracts, safe response validation and environment-bound fiscal write preflight.
- `src/ApprovalService.php` and `src/WriteRouteCatalog.php`: frozen, single-use native host-confirmed or legacy browser approvals.
- `src/UserScopeFilter.php` and `src/SettingsScopeFilter.php`: special fail-closed
  projections for unsafe upstream listing endpoints.
- `src/RecordProjection.php`: output-only field selection; never controls authorization.

## Tool and route policy

Thirteen tools are exposed. Nine are read-only; mutation tools discover reviewed routes, prepare immutable requests, execute a frozen write through a clearly non-read-only native host-confirmed tool, and read status. The default flow uses the existing linked OAuth identity with no separate password; a browser approval remains an explicit fallback. The MCP cannot by itself prove how a host renders its confirmation UI. The repository includes a
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

## NFS-e API parity and reviewed MCP boundaries

The NFS-e module's #497 parity effort adds native JSON read contracts for
fiscal dashboard data, company email footer, company-owned customer email
preferences, IBGE UFs/municipalities and LC 116 catalog lookup. The MCP
allows these **only** through the typed, ACL-preserving NfseReadCatalog, with
bounded queries and validated URI parameters. A municipality UF must be an
uppercase two-letter state code; arbitrary path strings are rejected.

The native municipal-parameter GET may write advisory cache records; it is
**not** whitelisted as a side-effect-free MCP read. Separately reviewed
mutations are now available only after individual approval:

- `PATCH settings/email-footer` with exact, HTTPS-only typed values.
- `PATCH settings/artifacts` for **non-secret** WebDAV storage policies
  (safe naming templates, company/dedicated CNPJ-scoped roots, XML/PDF flags).
  The module's read contract includes `path_scope`, which the MCP validates
  explicitly. Credentials, endpoint URL, username and password cannot be
  proposed via this route. Updating an existing endpoint may perform the
  module's bounded WebDAV connection probe; it is never a read-only MCP call.
  Initial endpoint/credential setup still requires a secure human flow.
- `POST invoices/{invoice}/email-preferences` with customer-owned invoice
  verification and bounded subject and message fields.
- `POST invoices/refresh-all` with an explicit `confirmed=true` body and
  a current ledger snapshot, without initiating fiscal issuance.
- `POST invoices/{invoice}/fiscal-decision` with constrained decision/reason,
  documented justification, evidence when required, current fiscal attempts
  and verified invoice ownership. A new "issue" decision requires an
  authorized category; recording the decision does not itself emit an NFS-e.
- `POST settings/category-issuance` with a current-company category,
  a prospective `issue` or `do_not_issue` policy and justification.
  Changing an income category's authorization is a consequential action,
  not a mere reporting preference.
- `POST adn/documents/{document}/ignore` for a pending, company-owned
  received/intermediated document, never an already imported bill.
- `POST adn/documents/{document}/import` with explicit approval,
  company-owned existing vendor, expense category, enabled item and dates.
  It creates **only a draft bill** through the module's existing idempotent
  import transaction. Vendor creation is a separate protected operation.
  The MCP refuses vendor auto-creation, arbitrary financial status and
  payment fields.

- `POST adn/distribution/consult` runs one approved, bounded national
  distribution request using the existing company's certificate context and
  NSU. The MCP returns only the cursor and reconciliation counts; national
  XML and raw upstream documents are never sent as model-visible output.
- `POST municipal-parameters/consult` runs one approved official consultation
  and optional local advisory cache update, only for the selected company's
  municipality, service and competence. The MCP exposes provenance and stale
  status, not a legal conclusion about fiscal eligibility.
- `POST settings/company-profiles/{companyId}/cleanup` and
  `DELETE settings/company-profiles/{companyId}` require exact existing
  company profile state, recorded cleanup status and a fresh single-use
  approval. Delete requires `EXCLUIR` and must not reuse `CANCELAR`.

All of these proposals are one-use and compare the current reviewed
snapshot immediately before submission. They do not become read-only calls
or bypass company and Core permissions. External operational consultations
may have already reached the national service if a response is ambiguous;
never automatically retry such calls.
Direct invoice **email sending** remains blocked from the MCP mutation
catalog: it can trigger `DocumentSending` and thus the fiscal emission
lifecycle, and needs a distinct, reviewed confirmation/side-effect contract.

## Native secure browser handoffs

Sensitive or binary functions remain available through the **native authenticated
Akaunting UI**, not a JSON tunnel through the language model. The read-only
`akaunting_nfse_secure_handoff` tool verifies personal company membership
and the relevant native read resource before producing a fixed allowlisted URL:
PFX inspection and profile creation, Vault credential entry, closing CSV,
IBS/CBS evidence CSV, and the current receipt's authorized XML/DANFSe.
The selected native page still enforces its own session, CSRF and ACL.
A handoff **does not perform or approve an API mutation**, transfer private
bytes, or confer access solely because a link was generated.

These six operations remain **distinct from direct MCP route parity** in the
route audit until proper authenticated file/secret transports are implemented.
Do not count browser navigation as a successful JSON endpoint execution.

Deploying a new NFS-e module version is a prerequisite for consuming these
new endpoints; a reviewed catalog entry never establishes that a route is
installed or that the connected identity is authorized. No generic
path proxy, wildcard route discovery, or automatic write permission is
introduced.
