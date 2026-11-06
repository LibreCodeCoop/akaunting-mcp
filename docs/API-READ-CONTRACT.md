<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
# API coverage and extension policy

For a reproducible comparison of installed API routes against the reviewed MCP
catalogs, see [API-to-MCP parity audit](API-PARITY.md). Unclassified route drift
fails CI; explicitly listed pending routes remain unavailable until their
transport, input/output contracts and authorization tests are implemented.

**Current scope:** reviewed Akaunting REST reads, reviewed Field Studio metadata/values/audit routes and selected NFS-e module JSON GET routes, together with a separately approved mutation workflow. The module integrations consume native API contracts rather than implementing new endpoints specifically for MCP.

## Native API contract

`akaunting_resources` reports registered resource categories and route counts. `akaunting_api_routes` lists reviewed registered safe GET routes and their permission hints. `akaunting_api_read` accepts **exact route names and typed parameters**, not arbitrary HTTP paths or hosts.

The core catalog is generated from the target application's real API registration using `bin/generate-api-catalog.php` and versioned as `src/api-routes.json`. NFS-e's reviewed JSON GET operations are separately allowlisted in `src/NfseReadCatalog.php`, matching `LibreCodeCoop/akaunting-nfse` API route registration. Review both after an Akaunting or module upgrade; never auto-include arbitrary module endpoints. GET routes with side effects, raw XML/PDF downloads, and live government-backed ADN distribution are excluded.

Company membership is verified for each selected company, followed by native Akaunting ACL checks and strict response filtering. Users and settings have additional allowlist-based safeguards; route registration alone does not demonstrate permission. Type-sensitive document/contact endpoints require the proper record discriminator.

## Functional limitations

Reviewed native CRUD routes are prepared using exact named API routes and are executed only after independent browser approval. No model-exposed MCP tool performs a write. NFS-e's reviewed JSON reads are accessible by `akaunting_api_routes` and `akaunting_api_read` with exact names and `nfse_filters`; the MCP constructs the module's required `/{company_id}/api/nfse/...` path from a verified company ID. For invoice-scoped reads the MCP independently checks the parent native sales invoice before exposing fiscal details. Unexpected company IDs or raw fiscal XML in a JSON response fail closed. Other NFS-e JSON responses rely on the module's native company filtering and ACL, which must be covered by its own integration tests. The MCP does not grant any additional permission.

A small set of NFS-e JSON mutations is explicitly reviewed in `src/NfseWriteCatalog.php`: updates to an existing fiscal profile, confirmed tax associations, sourced approximate taxes, operator-approved taxable-event attestations, and **single-invoice issuance, cancellation, reemission and substitution restricted to produção restrita**. These use the module's native API and the existing single-use browser approval; they do not create a separate fiscal pipeline. Before issuing, the MCP checks the company-owned invoice, active profile, DPS readiness and fiscal preview, then repeats the checks after approval. The module remains responsible for legal preflight, fiscal allocation and transmission. Certificate/PFX uploads, Vault secrets, XML/PDF transfers, production fiscal POSTs, bulk fiscal writes and government ADN distribution remain unavailable. See [NFS-e operations](NFSE-WRITES.md).

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
immediately before dispatch. Every change still requires the independent
browser approval; uncertain outcomes must be reconciled before retrying.

After updating the MCP server, refresh the ChatGPT integration's tool catalog
once to expose the generic `module_filters` input. New Field Studio routes
then reuse the same named-route tools rather than adding a new tool per
operation. Source of truth: `LibreCodeCoop/akaunting-field-studio`,
`Routes/api.php` and `docs/api.md`.

## New modules

Before adding a read route: verify that it is useful outside MCP, native, non-mutating, permissioned, company-scoped, safe to serialize and covered by synthetic allowed/denied tests. A write route additionally needs an explicit field schema, target-state comparison, independent approval, idempotency/recovery analysis and negative authorization tests. Never add arbitrary URL proxying.

See [security architecture](SECURITY.md) and [development and testing](DEVELOPMENT.md).

## Approved mutation workflow

The versioned `src/api-write-routes.json` catalog is generated with `bin/generate-write-catalog.php` from registered Akaunting routes; review changes before enabling them. The `akaunting_mutation_routes` tool lists supported operations; `akaunting_prepare_mutation` freezes one method, resource, target, company and JSON body. It returns a short-lived browser approval URL. The same user must reauthenticate and approve explicitly on the issuer host. No MCP tool can approve or execute it. Use `akaunting_mutation_status` to verify the result and an ordinary read to verify the updated record. Every change requires a fresh approval. Never retry an unconfirmed execution without investigating upstream.

## DPS diagnostic and official read-only lookup

The reviewed routes api.nfse.dps.readiness and api.nfse.dps.lookup are module JSON GET contracts available after a matching module deployment. They require native settings-read permission, company ownership and typed filters. The lookup number is limited to the authority high-water or its immediate successor. A found, not_found or inconclusive result is not a permission to transmit. The MCP rejects any official lookup response that claims safe_to_emit or exclusive_series_attested. No MCP read allocates, activates, claims or POSTs a DPS.
