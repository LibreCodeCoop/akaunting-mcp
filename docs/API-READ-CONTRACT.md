<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
# API coverage and extension policy

For a reproducible comparison of installed API routes against the reviewed MCP
catalogs, see [API-to-MCP operation inventory](API-PARITY.md). Unclassified route drift
fails CI; explicitly listed pending routes remain unavailable until their
transport, input/output contracts and authorization tests are implemented.

**Current scope:** reviewed Akaunting REST reads, Field Studio metadata/values/audit routes, selected NFS-e JSON GET routes and two explicitly reviewed read-only NFS-e POST operations, together with a separately approved mutation workflow. The module integrations consume native API contracts rather than implementing new endpoints specifically for MCP.

## Native API contract

`akaunting_resources` reports registered resource categories and route counts. `akaunting_api_routes` lists reviewed safe GET routes and the specifically verified non-mutating POST operations, with method and permission hints. `akaunting_api_read` accepts **exact route names and typed parameters**, not arbitrary HTTP paths or hosts.

### Authenticated live discovery

For installations with an NFS-e module exposing the native `api.nfse.discovery.index` contract, `akaunting_api_routes` queries that endpoint **after validating the user's OAuth-linked identity and selected company**. The endpoint inspects registered Laravel routes but lists only those explicitly marked with `mcp_read_contract` (a side-effect-free JSON response projection and native `read-*` permission). Discoverable routes are available immediately through the existing generic `akaunting_api_read` tool, without publishing a new MCP catalog or refreshing the ChatGPT plugin.

Dynamic reads require exact route names and numeric path IDs, reject unknown query fields and methods, verify an invoice's company/type through the Core API before invoice-scoped access, allow only explicit JSON response paths, reject unknown owner IDs and raw XML/PDF or private-key artifacts, and redact sensitive fields. The live declaration cannot replace a reviewed static route, and the read permit is scoped to **one GET call**. The manifest is read afresh per MCP invocation; no cross-user or cross-company route cache is used. Older module releases returning HTTP 404 fall back to the static catalog; other discovery failures fail closed.

**Important:** HTTP GET by itself is not evidence of safety; module authors must explicitly annotate each safe new JSON read. Discovery does not automatically permit writes, legacy state-changing GETs, certificate/Vault access, fiscal downloads or external side effects. New writes remain on the existing separately approved mutation contract and require exact typed input, unchanged target verification and independent user authorization. The module's discovery endpoint is not a database, metadata or arbitrary URL proxy.

The core catalog is generated from the target application's real API registration using `bin/generate-api-catalog.php` and versioned as `src/api-routes.json`. Existing NFS-e JSON GET operations remain separately allowlisted in `src/NfseReadCatalog.php`, matching `LibreCodeCoop/akaunting-nfse` API route registration. Review both after an Akaunting or module upgrade; never auto-include arbitrary module endpoints. GET routes with side effects, raw XML/PDF downloads, and live government-backed ADN distribution are excluded.

Company membership is verified for each selected company, followed by native Akaunting ACL checks and strict response filtering. Users and settings have additional allowlist-based safeguards; route registration alone does not demonstrate permission. Type-sensitive document/contact endpoints require the proper record discriminator.

## Functional limitations

Reviewed native CRUD routes use exact named API routes, a frozen per-user approval plan and explicit user authorization. The default executor is the non-read-only native MCP write tool; legacy browser approval remains an option. NFS-e's reviewed JSON reads are accessible by `akaunting_api_routes` and `akaunting_api_read` with exact names and `nfse_filters`; the MCP constructs the module's required `/{company_id}/api/nfse/...` path from a verified company ID. For invoice-scoped reads the MCP independently checks the parent native sales invoice before exposing fiscal details. Unexpected company IDs or raw fiscal XML in a JSON response fail closed. Other NFS-e JSON responses rely on the module's native company filtering and ACL, which must be covered by its own integration tests. The MCP does not grant any additional permission.

A small set of NFS-e JSON mutations is explicitly reviewed in `src/NfseWriteCatalog.php`: updates to an existing fiscal profile, confirmed tax associations, sourced approximate taxes, operator-approved taxable-event attestations, and **single-invoice issuance, cancellation, reemission and substitution in either explicit fiscal environment**. These use the module's native API and the existing single-use native host-confirmed or legacy browser approval; they do not create a separate fiscal pipeline. Before issuing, the MCP checks the company-owned invoice, active profile, DPS readiness and fiscal preview, then repeats the checks after approval. The module remains responsible for legal preflight, fiscal allocation and transmission. Certificate/PFX uploads, Vault secrets, XML/PDF transfers, production bulk dispatch and government ADN distribution remain unavailable. See [NFS-e operations](NFSE-WRITES.md).


## Non-mutating NFS-e POST reads

Two reviewed native POST endpoints perform only a safe computation:
`api.nfse.settings.vault.diagnostics` (a read-only Vault capability probe)
and `api.nfse.bulk.plan` (a fiscal emission queue preview). The MCP accepts
a bounded `post_body` object only for these two names, verifies the method,
tenant, result shape and redaction, and returns JSON immediately without
asking for financial write approval. This is not a generic POST proxy.

The native `api.nfse.dps.advance`, `api.nfse.invoices.refresh`, and
`api.nfse.bulk.dispatch` endpoints are separately available through
`akaunting_prepare_mutation`, with exact typed payloads, snapshot rechecks
and individual native host-confirmed or legacy browser approval. Only bulk dispatch can enqueue new NFS-e
work; it remains restricted to produção restrita. Refresh and DPS advance
do not emit invoices.

## Field Studio API contracts

The reviewed `api.field-studio.*` routes use the native Akaunting module's
`/api/v1/field-studio` API, company-aware personal credentials, and per-record
permissions for invoices, bills, transactions, contacts, items and document
lines. Read `definitions.index`/`show`, `values.show`, `records.search`, and
`audit.index` through `akaunting_api_read`. Supply their exact
`route_params` for `definition`, `entity_type`, and `entity_id`, and optional
`module_filters` for `entity_type`, `entity_id`, `field_key`, `value` and
`limit` as listed in `akaunting_api_routes`. Each route validates its filter
types and tenant ownership independently of arbitrary user-supplied paths.

The seven mutation contracts are available through
`akaunting_mutation_routes` and `akaunting_prepare_mutation`: create, edit and
archive field definitions, and create, patch, replace or remove individual
record values. Editing definitions requires `expected_version`; updating
record values accepts `values` and optional `versions`. This does not grant
permission to change a field unless the native Field Studio API grants that
user the relevant Akaunting record permission. The MCP freezes the current
definition or record-values snapshot before approval and checks it again
immediately before dispatch. Every change still requires confirmation through the native mutating tool (or the legacy
browser approval); uncertain outcomes must be reconciled before retrying.

After updating the MCP server, refresh the ChatGPT integration's tool catalog
once to expose the generic `module_filters` input. New Field Studio routes
then reuse the same named-route tools rather than adding a new tool per
operation. Source of truth: `LibreCodeCoop/akaunting-field-studio`,
`Routes/api.php` and `docs/api.md`.

## New modules

Before adding a read route: verify that it is useful outside MCP, native, non-mutating, permissioned, company-scoped, safe to serialize and covered by synthetic allowed/denied tests. A write route additionally needs an explicit field schema, target-state comparison, independent approval, idempotency/recovery analysis and negative authorization tests. Never add arbitrary URL proxying.

See [security architecture](SECURITY.md) and [development and testing](DEVELOPMENT.md).

## Approved mutation workflow

The versioned `src/api-write-routes.json` catalog is generated with `bin/generate-write-catalog.php` from registered Akaunting routes; review changes before enabling them. `akaunting_mutation_routes` lists supported operations, and `akaunting_prepare_mutation` freezes one method, route, target, company and JSON body into an encrypted single-use plan. By default it returns `awaiting_host_confirmation` and a request ID, **not** a browser link. Invoke the explicitly non-read-only `akaunting_execute_prepared_mutation` tool only with the user's authorization through the ChatGPT host; it reuses the already-linked personal OAuth session and validates identity, company, route and target again. The legacy `approval_transport=browser` flow still returns an HTTPS approval URL and requires separate reauthentication. Use `akaunting_mutation_status` and an independent read to verify results. The MCP cannot force how each host presents tool confirmation: before enabling writes in another client, confirm the client actually requests human confirmation. An unknown execution outcome must be investigated before preparing a new operation.

## DPS diagnostic and official read-only lookup

The reviewed routes api.nfse.dps.readiness and api.nfse.dps.lookup are module JSON GET contracts available after a matching module deployment. They require native settings-read permission, company ownership and typed filters. The lookup number is limited to the authority high-water or its immediate successor. A found, not_found or inconclusive result is not a permission to transmit. The MCP rejects any official lookup response that claims safe_to_emit or exclusive_series_attested. No MCP read allocates, activates, claims or POSTs a DPS.
