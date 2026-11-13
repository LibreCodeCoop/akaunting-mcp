<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Akaunting MCP Server

**Consult Akaunting from ChatGPT and prepare changes that execute only after explicit, independent human authorization.**

Developed by [LibreCode Coop](https://librecode.coop/), Akaunting MCP Server is an independent integration that connects an existing Akaunting 3.x installation to ChatGPT through the Model Context Protocol (MCP). Authorized staff can explore supported records in natural language instead of navigating multiple screens. The integration uses individual Akaunting permissions and verifies company boundaries on data reads.

## What your organization can do

- **Find authorized financial records.** Consult invoices, contacts, accounts, transactions, categories, reports and other resources supported by the installed REST API.
- **Keep individual access controls.** People connect with their own Akaunting credentials; the MCP does not provide a shared administrator identity or additional privileges.
- **Preserve company separation.** Each request verifies company membership, and the MCP applies additional response filtering.
- **Use a private connection.** ChatGPT connects through OAuth with PKCE and a private MCP tunnel; the internal MCP HTTP endpoint is not made public.
- **Control your infrastructure.** You operate the Akaunting installation, deployment and encrypted credential storage. Information explicitly retrieved into a ChatGPT conversation is nevertheless processed by the connected service under its own privacy terms and controls.

The MCP provides read tools and a company-scoped mutation workflow: route discovery, proposal preparation, native execution and status. Safe JSON GET routes explicitly declared by compatible installed modules can be discovered at runtime, without editing the MCP route catalog; unknown writes remain unavailable until separately reviewed and approval-gated. Field Studio definitions and record values also use the same named-route tools with native record-level permissions. **The default is confirmation of the explicitly mutating MCP tool by the ChatGPT host** using the OAuth identity already connected to ChatGPT: no separate browser tab, e-mail or password. The two-step flow is: `akaunting_prepare_mutation` freezes and previews the exact operation; `akaunting_execute_prepared_mutation` is a clearly marked write tool and must be confirmed by the user. A legacy HTTPS browser approval form remains available when `approval_transport=browser`. Both paths validate upstream permissions, company, exact target state and encrypted single-use plans. An uncertain write outcome must be reconciled rather than retried.

Core resource categories include companies, dashboards, items, contacts, documents, accounts, reconciliations, transactions, transfers, reports, categories, currencies and taxes. Optional Akaunting modules may provide additional categories after their native APIs and permissions have been reviewed. A route being registered does not mean every user is authorized to call it.

**Coverage and limitations:** The catalog permits reviewed native Akaunting reads and NFS-e JSON resources for settings, fiscal history, the ledger, closing, ADN review and bulk status. The MCP can also prepare company-scoped fiscal-parameter updates, explicit tax associations, sourced approximate taxes, and **single-note issuance, cancellation, reemission and substitution in the selected fiscal environment (produção restrita or produção)**, all requiring user confirmation of the mutating tool (or explicit legacy browser approval). It uses the module's own REST API and never implements a separate fiscal issuer. Certificate upload, Vault secret changes and protected XML/PDF/CSV downloads are not available as direct MCP data transfer; a reviewed secure-handoff tool links to native authenticated Akaunting pages without carrying secrets or file contents. Government-backed ADN distribution and municipal advisory refresh require separate, bounded one-use approval for their operational POST routes; legacy side-effecting GET routes are not available as read-only MCP actions. Fiscal email composition is browser-only because sending can trigger NFS-e issuance. Production bulk dispatch remains unavailable through MCP. Reviewed read-only POST diagnostics and bulk plans, fiscal receipt refresh and explicit DPS advance are supported; reviewed bulk dispatch remains restricted to produção restrita with individual approval. Fiscal transmission requires a separately enabled deployment gate and an active matching DPS authority. The installed module, DPS authority and fiscal preflight must be verified before any fiscal transmission. See [NFS-e configuration and operations](docs/NFSE-WRITES.md), the [API-to-MCP operation inventory](docs/API-PARITY.md) and the [native Web/API/domain traceability matrix](https://github.com/LibreCodeCoop/akaunting-nfse/blob/main/docs/web-api-domain-parity.md).

## Getting started

Already have an Akaunting installation? Follow [the deployment guide](docs/DEPLOY.md) to prepare private configuration, required secret files, Docker services, HTTPS and a private MCP tunnel. Then [connect ChatGPT](docs/CONNECTING-CHATGPT.md) using your individually authorized Akaunting account. Run the synthetic tests before using business data. The MCP never requires direct access to Akaunting's database.

## How security works

Users authorize the MCP individually through an OAuth authorization-code flow with PKCE. The authorization broker validates the linked account through Akaunting, stores the required credentials encrypted in restricted runtime storage and checks upstream identity, native permissions, company membership and returned record scope. Only reviewed API routes are exposed; writes require user authorization through ChatGPT native tool confirmation (default), with a legacy browser approval fallback, and short-lived, single-use encrypted server state. Direct arbitrary API proxying is not supported.

The MCP protects its own output, but it cannot repair security defects in Akaunting for other clients. Use HTTPS for OAuth, do not publish the internal MCP listener and never use a shared administrative account as a routine integration identity.

## Professional services from LibreCode

**LibreCode Coop** provides architecture assessment, secure deployment, systems integration, customization, training, consulting, maintenance and support for organizations adopting Akaunting integrations and AI-assisted workflows.

**[Talk to LibreCode about your integration](mailto:contact@librecode.coop?subject=Akaunting%20MCP%20integration)** · [LibreCode website](https://librecode.coop/)

Contracting LibreCode is optional for using or adapting the MCP source. Professional services do **not** grant any Akaunting commercial license.

## Documentation

- [Installation, deployment, upgrades and rollback](docs/DEPLOY.md)
- [Connect ChatGPT using OAuth and a private tunnel](docs/CONNECTING-CHATGPT.md)
- [Architecture and trust boundaries](docs/ARCHITECTURE.md)
- [API coverage and extension rules](docs/API-READ-CONTRACT.md)
- [NFS-e configuration and approved fiscal operations](docs/NFSE-WRITES.md)
- [Security design](docs/SECURITY.md) and [private vulnerability reporting](SECURITY.md)
- [Development, tests, CI, benchmarking and publication checks](docs/DEVELOPMENT.md)
- [Licensing and third-party rights](docs/LICENSING.md)
- [Contributing](CONTRIBUTING.md)

## License and independence

The **MCP is a separate program**, licensed under [AGPL-3.0-or-later](LICENSE). Akaunting is not part of the MCP distribution. Certain Akaunting releases, including 3.1.21, use the **Business Source License (BSL 1.1)** with restrictions on production use; each organization must check the license applicable to its actual Akaunting version. [Akaunting 3.1.21 licensing reference](https://github.com/akaunting/akaunting/blob/3.1.21/LICENSE.txt) applies only to that release; other Akaunting versions may have different terms. See [licensing details](docs/LICENSING.md).

**Independent integration:** This is not an official Akaunting product. LibreCode does not claim endorsement by, affiliation with or authority to license the Akaunting vendor. Akaunting is a trademark of its respective owner.
