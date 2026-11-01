<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Read-only API coverage and extension policy

**Current scope:** nine read tools plus three approval tools, with core Akaunting REST resources and reviewed installed-module GET routes. This document describes the implemented route-catalog approach, **not** a proposal to create additional endpoints specifically for MCP.

## Native API contract

`akaunting_resources` reports registered resource categories and route counts. `akaunting_api_routes` lists reviewed registered safe GET routes and their permission hints. `akaunting_api_read` accepts **exact route names and typed parameters**, not arbitrary HTTP paths or hosts.

The catalog is generated from the target application's real API registration using `bin/generate-api-catalog.php` and versioned as `src/api-routes.json`. Review and regenerate it after a supported Akaunting or module upgrade. Only known read operations are eligible; GET routes that change server state are excluded.

Company membership is verified for each selected company, followed by native Akaunting ACL checks and strict response filtering. Users and settings have additional allowlist-based safeguards; route registration alone does not demonstrate permission. Type-sensitive document/contact endpoints require the proper record discriminator.

## Functional limitations

Reviewed native CRUD routes are prepared using exact named API routes and are executed only after independent browser approval. No model-exposed MCP tool performs a write. Module-specific actions, side-effecting GET routes and NFS-e issuance remain unavailable until their API contracts and authorization are reviewed. NFS-e is not integrated. A reusable, permissioned read-only REST contract must be supplied by the fiscal module before any NFS-e capabilities are exposed. Future fiscal APIs should be implemented and tested inside that module for its own use and other integrations, **never added solely to satisfy the MCP**.

## New modules

Before adding a route: validate that it is useful outside MCP, registered as a native API route, non-mutating, protected by native permissions, company-scoped, safe for recursive serialization and covered by synthetic tests with allowed and denied accounts. Keep the generic route catalog explicit; do not introduce a broad proxy.

See [security architecture](SECURITY.md) and [development and testing](DEVELOPMENT.md).

## Approved mutation workflow

The versioned `src/api-write-routes.json` catalog is generated with `bin/generate-write-catalog.php` from registered Akaunting routes; review changes before enabling them. The `akaunting_mutation_routes` tool lists supported operations; `akaunting_prepare_mutation` freezes one method, resource, target, company and JSON body. It returns a short-lived browser approval URL. The same user must reauthenticate and approve explicitly on the issuer host. No MCP tool can approve or execute it. Use `akaunting_mutation_status` to verify the result and an ordinary read to verify the updated record. Every change requires a fresh approval. Never retry an unconfirmed execution without investigating upstream.
