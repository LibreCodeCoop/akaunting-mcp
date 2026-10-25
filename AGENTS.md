<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Agent guidance

Review `README.md`, `docs/SECURITY.md`, `docs/DEVELOPMENT.md` and `docs/LICENSING.md` before changing code or documentation.

- Keep deployment-specific runbooks, hostnames, operational reports, upstream patch evidence and integration-lab copies **outside the source repository**, not merely in Git-ignored folders. Never commit credentials, production records, encrypted runtime state, environment files or logs.
- Never use production business data or mutable financial operations for tests. Use disposable Akaunting installations with synthetic multi-company users.
- Keep MCP write tools disabled. Any future mutation workflow requires separately approved authentication, authorization and independent human confirmation.
- Always preserve native Akaunting ACLs, personal identity, verified company membership and fail-closed response checks. A filtered MCP response does not constitute a fix for vulnerabilities in upstream Akaunting.
- The catalog permits only reviewed non-mutating native API routes. Regenerate and test it after upstream/module changes. Never create endpoints in a module solely for MCP or enable arbitrary URL proxying.
- Do not expose the internal MCP HTTP transport. Protect the browser OAuth edge with HTTPS and use a private tunnel.
- Run synthetic API/OAuth/tenant/transport tests, Docker checks and licensing/secret scans before release. Coordinate simultaneous local changes with other active workstreams.
- Prefer PHP for repository utilities, parsers, validation and tests. Reserve small shell scripts for Git and Docker orchestration; avoid introducing another scripting runtime into CI.
- Keep PHPUnit, PHPStan and PHP-CS-Fixer isolated in their respective `vendor-bin/` manifests and lockfiles. Root `require-dev` must contain only the Composer bin plugin; never install development tooling in the runtime image.
- Use signed, DCO-compliant Git commits through the project's authorized Git Signing service. Never alter GitHub resources or publish/deploy without the separately required authorization.
- Keep private vulnerability evidence outside the public tree and follow confidential reporting procedures.
