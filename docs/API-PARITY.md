<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
# API-to-MCP operation inventory and compatibility gate

The **Akaunting API is authoritative**. The MCP catalog is a deliberately reviewed
consumer, not an independent source of fiscal or financial domain behavior.

The `bin/check-api-parity.php` auditor compares registered API operation names,
methods and URI templates against the MCP catalogs. It fails closed on:

- new routes not present in a reviewed catalog or in the explicitly recorded gap list;
- changes to methods or URI templates of an already reviewed MCP route;
- missing, malformed, duplicate or stale entries in the recorded gap list;
- malformed or unnamed module registrations that the source parser cannot account for.

The versioned `docs/contracts/pending-api-routes.json` records known, **unimplemented**
operations with a specific explanation. A pending entry is **not available** to MCP
clients; it does **not** mean that the route is authorized or safe. Reducing this
list to zero is required for full functional parity. The CI does not silently
reclassify missing routes as available. The report separately shows supported and
pending counts, and `--strict` fails while any pending route remains.

## Usage

Compare the NFS-e module's route registration directly from source:

```sh
php bin/check-api-parity.php \
  --module-source=/path/to/akaunting-nfse/Routes/api.php \
  --baseline=docs/contracts/pending-api-routes.json
```

Compare all registered API routes against the same reviewed MCP catalogs using a
route snapshot from a **disposable or appropriately authorized** Akaunting instance:

```sh
php artisan route:list --path=api --json > /tmp/akaunting-routes.json
php bin/check-api-parity.php \
  --laravel=/tmp/akaunting-routes.json \
  --baseline=docs/contracts/pending-api-routes.json
```

The NFS-e source check runs in MCP CI on every PR and on a daily schedule against
the module's current `main`. This catches new route names without booting a
financial system or exposing runtime credentials. Repeat the full Laravel route
comparison after each installed Akaunting Core or module upgrade. A clean source
comparison does not prove that the deployed server is using the same revision.

## Acceptance boundary

This is an **inventory and path/method drift guard**, not a substitute for tests of
request schemas, tenancy, permissions, idempotency, authenticated file transfer,
response contracts, or real fiscal behavior. A route is not ready for autonomous
use until its adapter and negative-path tests are in place. Existing HTTP GET
routes that mutate state must be treated as mutations requiring independent
approval, never silently added as safe reads. Binary documents and certificate
passwords require an explicitly designed, authenticated transfer boundary.

The MCP's generic read and prepared-mutation tools can remain stable as the
catalog expands, avoiding a ChatGPT connector refresh for each new route.
