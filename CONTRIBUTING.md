<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Contributing

Contributions to this independent AGPL-3.0-or-later MCP are welcome once its repository is made available. Review the [read-only API contract](docs/API-READ-CONTRACT.md), [security design](docs/SECURITY.md), [development and quality checks](docs/DEVELOPMENT.md), and [licensing](docs/LICENSING.md).

Keep each change scoped and verifiable, with synthetic tests proving allowed and denied permissions, company isolation, error handling and absence of writes. Do not introduce a general-purpose API proxy, unreviewed GET endpoints, administrator credentials or features that create or modify financial records.

Sign commits under the Developer Certificate of Origin (DCO) using your authenticated identity. Keep copyright and SPDX notices accurate and preserve third-party attribution. Do not incorporate Akaunting source files or compatibility patches into this repository without a separate rights review.

**Security reports:** do not disclose sensitive vulnerabilities in public issues or pull requests. Use the private contact process in [SECURITY.md](SECURITY.md). Never submit real accounting records, tokens, infrastructure identifiers or dumps as tests or diagnostics.
