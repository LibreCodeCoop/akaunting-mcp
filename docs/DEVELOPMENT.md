<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Development, testing and contribution quality

This is the permanent developer guide for the independent, approval-gated Akaunting MCP. Use [deployment](DEPLOY.md) for installation and operations, [architecture](ARCHITECTURE.md) for trust boundaries and [API contract](API-READ-CONTRACT.md) when changing route coverage.

## Requirements

Docker Engine and Compose v2; the application image runs PHP 8.5. The runtime copies only the source needed to serve MCP and has **no Composer runtime dependency**. Composer is used to install development tools, not to connect to accounting data.

The root `require-dev` contains only `bamarni/composer-bin-plugin`. PHPUnit, PHPStan and PHP-CS-Fixer each have their own manifest, lockfile and independent dependencies in `vendor-bin/phpunit/`, `vendor-bin/phpstan/` and `vendor-bin/php-cs-fixer/`. Composer never needs to load all testing dependencies into the runtime application. The generated `vendor/` directories remain ignored, while all four lockfiles are versioned.

For PHP 8.5 and Composer 2 environments, install the locked development tools with:

~~~sh
composer install --no-interaction --prefer-dist --no-progress --no-scripts
composer bin all install --no-interaction --prefer-dist --no-progress --no-scripts
composer check
composer audit --locked --no-interaction
composer bin all audit --locked --no-interaction
~~~

Use the pinned Composer container or the corresponding workflow commands if PHP 8.5 is not available on the workstation. Only manifests and locks in each `vendor-bin/` package should be updated with `composer bin <tool> update`; do not copy one tool's vendor dependencies into another or expose these dependencies in the Docker runtime. Individual CI workflows install only the toolchain they exercise.

The isolated PHPUnit toolchain uses PHPUnit 13.4 (requires PHP 8.4 or later; CI runs PHP 8.5). The `phpunit.xml.dist` schema must match the installed major version and must reject PHPUnit deprecations and an empty test suite. Treat major upgrades as deliberate compatibility work: run `composer test` with the new locked toolchain and collect PCOV coverage before merging its dependency PR. PHPUnit and other development packages are not included in the application runtime.

Tests and fixtures must use synthetic users, companies and records. Never run mutating requests or use real customer financial records, administrative credentials, live identity secrets or operational database copies in CI.

## Run the synthetic suite

Run the following from the repository root:

~~~sh
docker build -t akaunting-mcp:ci .
docker run --rm --network none \
  -v "$PWD/tests:/app/tests:ro" -w /app \
  --entrypoint php akaunting-mcp:ci tests/run.php
docker run --rm --network none \
  -v "$PWD/tests:/app/tests:ro" -w /app \
  --entrypoint sh akaunting-mcp:ci tests/run_perf_connection.sh
docker run --rm --network none \
  -v "$PWD/tests:/app/tests:ro" -w /app \
  --entrypoint sh akaunting-mcp:ci tests/integration/http-smoke.sh
docker compose --env-file .env.example config --quiet
~~~

The synthetic tests cover read-only tool contracts, OAuth and PKCE, token revocation and rotation, company isolation, route restrictions, tool argument validation, response redaction and error handling. The local HTTP smoke uses a disposable loopback fixture in a network-disabled container; it does not contact an Akaunting deployment.

The independent workflows in [GitHub Actions](../.github/workflows/) check PHP lint, PHPUnit, PHPStan, PHP-CS-Fixer, Composer validation and audit, Docker smoke, DCO, Conventional Commits, ShellCheck, Actionlint, Zizmor, REUSE, public source scope and secrets. Use the pinned tooling from those workflows locally; do not replace the application runtime with Composer development packages. Do not assume a local test run means that hosted CI or the production integration has been approved.

## Behavioral coverage gate

PHPUnit exercises independent business behaviors and source code with a PCOV
line-coverage gate in CI (minimum **77%** of `src/` at the initial baseline).
Use `composer test` for a normal local run. To inspect coverage with a PHP
runtime that has PCOV enabled, execute the same command used in
[the PHPUnit workflow](../.github/workflows/phpunit.yml) and run
`php tools/check-coverage.php coverage.xml 77`.

This percentage **does not** include code exercised in child processes by
the separate HTTP/cURL and Docker smoke suites. Do not confuse a line-level
coverage percentage with complete behavioral assurance. All nine MCP tool
families, OAuth/PKCE/refresh/revocation, cross-company permissions, denied
mutations, API read-only routing, Docker isolation, and CLI/Shell operations
must continue to pass their synthetic acceptance tests before deployment.

## Acceptance coverage by behavior

Use this matrix when changing an application boundary. Keep tests focused on
**what a caller can do or cannot do**, rather than private method calls, class
layout or internal connection identities.

| Observable behavior | Automated regression checks |
| --- | --- |
| OAuth authorization, PKCE, bearer audience and revocation | `AuthenticationBehaviorTest`, `tests/run.php`, `tests/integration/http-smoke.sh` |
| Production configuration and encrypted credential state | `ConfigurationBehaviorTest`, `AuthenticationBehaviorTest`, `OperationsBehaviorTest` |
| All read and approval-preparation MCP tools, JSON-RPC and error redaction | `McpTransportBehaviorTest`, `tests/run.php`, `tests/integration/http-smoke.sh` |
| Tenant isolation, native and legacy route parity, nested relationships, fields and secrets | `ReadSafetyBehaviorTest`, `SpecializedReadBehaviorTest`, `ContractsTest` |
| REST transport, status codes, redirects, compression and bounded bodies | `tests/run_perf_connection.sh`, `tests/integration/http-smoke.sh` |
| Key generation, revocation and private tunnel key entry | `OperationsBehaviorTest` with disposable users and filesystem |
| Tunnel readiness and denial of unsafe startup | `OperationsBehaviorTest`, `tests/integration/docker-smoke.sh` |
| Git commit policies, catalog generator, checksum verification, scope and SPDX | `ToolingTest`, `OperationsBehaviorTest`, REUSE, Actionlint and ShellCheck |
| FPM/Nginx/OAuth edge networking and runtime packaging | `tests/integration/docker-smoke.sh` |
| Synthetic performance analysis and coverage regression gate | `OperationsBehaviorTest`, `tools/check-coverage.php` |

Do not delete tests solely because they are not counted in PHPUnit source-line
coverage. Integration tests are executed as isolated PHP/cURL/Docker processes
and deliberately make no contact with live financial data. A test failure in
an authorization boundary must block deployment even when line coverage rises.

## Distribution and disk footprint

The source checkout retains isolated development toolchains in the ignored
`vendor-bin/*/vendor/` directories; this is normal for local development,
not an application runtime dependency. Do not version or ship these packages
with the MCP image. The Dockerfile copies only runtime `src/`, `public/`
HTTP endpoints and `bin/revoke-user.php`; offline key-generation and
catalog-generation tools stay in the source checkout. The Docker smoke
verifies this separation on every run.

Measure the actual source and image size for a release using `du`,
`docker image inspect` and a reviewed source archive. Avoid a hardcoded
deployment-specific disk footprint or hostnames in public documentation.

## Extend the API safely

Add a failing regression test before changing any authorization or company-isolation behavior. API routes are identified by **reviewed native route names**, not unvalidated paths or user-provided URLs. To prepare a proposed catalog update, obtain route metadata from an authorized **disposable** Akaunting instance:

~~~sh
php artisan route:list --path=api --json > routes.json
php bin/generate-api-catalog.php routes.json > proposed-catalog.json
~~~

Run the Laravel command inside the test Akaunting instance; run the generator in the MCP checkout. The inputs contain route metadata, not client business data. Review each newly proposed GET route for effects, permissions, tenant boundaries and potential secrets; do not overwrite the versioned catalog before review. Module APIs should exist for their product's own needs and other integrations, not solely to serve MCP.

Never provide tools that directly execute or approve writes: only the authenticated browser can do so after review. Treat cached membership/ACL decisions as security-sensitive. Never weaken the upstream authorization or recursive company validation to improve response time.

## Benchmarking and performance

The optional [local synthetic benchmark](../tools/benchmark-local.php) measures PHP/IO components such as encrypted vault reads, token verification and tool serialization. Run it against a built runtime image without network access:

~~~sh
docker run --rm --network none \
  -v "$PWD/tools:/app/tools:ro" -w /app \
  --entrypoint php akaunting-mcp:ci tools/benchmark-local.php
~~~

The results are microbenchmarks, not real API or tunnel latency. Record environment-specific timings **outside the source checkout**. Do not introduce shared cross-user response caches, bypass membership checks or automatically retry HTTP 429 to improve apparent performance. The connector reuses a cURL connection within each request and bounds decompressed response size and returned fields.

## Public source and supply-chain checks

Before staging code, inspect the source tree and ensure it contains no production-specific runbooks, hostnames, infrastructure paths, customer records, keys, temporary dumps, copied upstream controllers or derived upstream patches. These belong in separate restricted storage **outside** the repository.

After staging changes, run:

~~~sh
php tools/check-release-scope.php --self-test
php tools/check-release-scope.php
git diff --cached --check
~~~

The scope checker evaluates the proposed public source, including the Git index and public documentation. For local organization-specific hostnames, set MCP_PUBLICATION_PRIVATE_MARKERS to an access-restricted text file **outside** the repository; never embed its values in CI or source. Generic scope checks also run in GitHub Actions.

Run a redacted secrets scan against the actual source snapshot and all reachable Git history; review the exact archive before distribution. Git ignore rules, a clean working tree and a clean scanner result cannot prove that prior commits, workflow artifacts or GitHub issues are clean. When a known secret was actually exposed, rotate it and assess references and external copies.

Review image digest pinning, Composer dependency licenses and advisories, the AGPL SPDX notices, upstream Akaunting's *version-specific* BSL restrictions, and confidential vulnerability disclosure before release. No merge, public repository, release or deployment is implied by CI success.

## Public release checklist

Complete **all** checks against the same immutable, reviewed release candidate:

- **Distribution boundary:** archive and file inventory match the reviewed source tree; no private operational files, copied upstream patches, runtime secrets, customer data or unreviewed Git objects enter the public repository.
- **Secrets and privacy:** run redacted automated scans on files, Git objects, all branches, tags and reachable history; manually classify every hit and rotate any credential known to have been exposed, even if its former location was deleted.
- **History and GitHub surface:** favor a clean repository with a curated signed history. Audit any issues, PRs, reviews, workflow logs, artifacts, releases and external references before transferring them. Retain restricted private evidence separately; a rewrite or force-push does not erase third-party copies.
- **Rights and provenance:** examine the license shipped with the exact deployed Akaunting version, the MCP AGPL, relevant third-party notices, SPDX/REUSE results and potential copied upstream code. No MCP license may be presented as granting rights to operate Akaunting outside its applicable terms.
- **Build and dependencies:** rebuild the pinned runtime from the candidate; verify image provenance, SBOM and vulnerability scan, Composer lockfiles and published dependency licenses. Resolve high/critical findings or obtain a documented, risk-owner-approved exception.
- **Security and correctness:** all PHP lint, PHPUnit, static analysis, code style, API/authorization/isolation regression tests, integration smoke, scope, secret, REUSE and workflow-security checks pass. Recheck after the final commit rather than relying on results from an earlier working tree.
- **Public communication:** README functionality matches actual read-only tools, OAuth and tenant boundaries, NFS-e limitations, professional services, independent status and trademarks. The vulnerability reporting channel is private and tested.
- **Final authorization:** review the target organization, repository identity, visibility, exact signed DCO commits and export archive. No public release, visibility change or migration of operational metadata without specific authorization; inspect the actual public surface immediately after authorized publication.

Keep private audit findings and commercial licensing negotiations outside the public source tree.

## Git conventions

Use small coherent commits, Conventional Commits, the Developer Certificate of Origin (DCO) and the approved signed-commit workflow. Preserve contributor attribution and third-party notices; never squash away signatures as an automatic maintenance step. See [CONTRIBUTING.md](../CONTRIBUTING.md) and [security policy](../SECURITY.md).
