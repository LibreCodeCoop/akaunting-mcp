<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Akaunting MCP Server

**Consult accounting information from ChatGPT using your existing Akaunting account, without giving the assistant permission to change records.**

Developed by [LibreCode Coop](https://librecode.coop/), Akaunting MCP Server is an independent integration that connects an existing Akaunting 3.x installation to ChatGPT through the Model Context Protocol (MCP). Authorized staff can explore supported records in natural language instead of navigating multiple screens. The integration uses individual Akaunting permissions and verifies company boundaries on data reads.

## What your organization can do

- **Find authorized financial records.** Consult invoices, contacts, accounts, transactions, categories, reports and other resources supported by the installed REST API.
- **Keep individual access controls.** People connect with their own Akaunting credentials; the MCP does not provide a shared administrator identity or additional privileges.
- **Preserve company separation.** Each request verifies company membership, and the MCP applies additional response filtering.
- **Use a private connection.** ChatGPT connects through OAuth with PKCE and a private MCP tunnel; the internal MCP HTTP endpoint is not made public.
- **Control your infrastructure.** You operate the Akaunting installation, deployment and encrypted credential storage. Information explicitly retrieved into a ChatGPT conversation is nevertheless processed by the connected service under its own privacy terms and controls.

The current implementation includes **nine read-only tools** for identity verification, authorized company discovery, resource listing and retrieval, permission probes, company-scoped user and role listings, and reviewed native API-route discovery and reads.

Core resource categories include companies, dashboards, items, contacts, documents, accounts, reconciliations, transactions, transfers, reports, categories, currencies and taxes. Optional Akaunting modules may provide additional categories after their native APIs and permissions have been reviewed. A route being registered does not mean every user is authorized to call it.

**Current limitations:** There are no MCP operations to create, update, delete, issue or cancel financial documents, send email, or manage permissions. NFS-e is **not integrated**; adding it requires a reusable, authorization-aware fiscal REST API contract. Effective per-user permission matrices are not exposed. Compatibility and authorization must be tested against each target Akaunting installation.

## Getting started

Already have an Akaunting installation? Follow [the deployment guide](docs/DEPLOY.md) to prepare private configuration, required secret files, Docker services, HTTPS and a private MCP tunnel. Then [connect ChatGPT](docs/CONNECTING-CHATGPT.md) using your individually authorized Akaunting account. Run the synthetic tests before using business data. The MCP never requires direct access to Akaunting's database.

## How security works

Users authorize the MCP individually through an OAuth authorization-code flow with PKCE. The authorization broker validates the linked account through Akaunting, stores the required credentials encrypted in restricted runtime storage and checks upstream identity, native permissions, company membership and returned record scope. Only reviewed, non-mutating API routes are exposed; direct arbitrary API proxying is not supported.

The MCP protects its own output, but it cannot repair security defects in Akaunting for other clients. Use HTTPS for OAuth, do not publish the internal MCP listener and never use a shared administrative account as a routine integration identity.

## Professional services from LibreCode

**LibreCode Coop** provides architecture assessment, secure deployment, systems integration, customization, training, consulting, maintenance and support for organizations adopting Akaunting integrations and AI-assisted workflows.

**[Talk to LibreCode about your integration](mailto:contact@librecode.coop?subject=Akaunting%20MCP%20integration)** · [LibreCode website](https://librecode.coop/)

Contracting LibreCode is optional for using or adapting the MCP source. Professional services do **not** grant any Akaunting commercial license.

## Documentation

- [Installation, deployment, upgrades and rollback](docs/DEPLOY.md)
- [Connect ChatGPT using OAuth and a private tunnel](docs/CONNECTING-CHATGPT.md)
- [Architecture and trust boundaries](docs/ARCHITECTURE.md)
- [Read-only API coverage and extension rules](docs/API-READ-CONTRACT.md)
- [Security design](docs/SECURITY.md) and [private vulnerability reporting](SECURITY.md)
- [Development, tests, CI, benchmarking and publication checks](docs/DEVELOPMENT.md)
- [Licensing and third-party rights](docs/LICENSING.md)
- [Contributing](CONTRIBUTING.md)

## License and independence

The **MCP is a separate program**, licensed under [AGPL-3.0-or-later](LICENSE). Akaunting is not part of the MCP distribution. Certain Akaunting releases, including 3.1.21, use the **Business Source License (BSL 1.1)** with restrictions on production use; each organization must check the license applicable to its actual Akaunting version. [Akaunting 3.1.21 licensing reference](https://github.com/akaunting/akaunting/blob/3.1.21/LICENSE.txt) applies only to that release; other Akaunting versions may have different terms. See [licensing details](docs/LICENSING.md).

**Independent integration:** This is not an official Akaunting product. LibreCode does not claim endorsement by, affiliation with or authority to license the Akaunting vendor. Akaunting is a trademark of its respective owner.
