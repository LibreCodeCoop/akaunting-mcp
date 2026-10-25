<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Security policy

The Akaunting MCP is a separately maintained, read-only integration. No public release or public support window is guaranteed by the presence of this document; consult published release announcements for any supported versions.

## Reporting privately

Please report potential vulnerabilities in the MCP **privately** to [contact@librecode.coop](mailto:contact@librecode.coop?subject=Confidential%20security%20report%3A%20Akaunting%20MCP) with the subject **Confidential security report: Akaunting MCP**. Do not include real credentials, client records, or customer financial data in the initial report. Include an affected version, a concise impact description, and reproduction steps using synthetic data. We will coordinate a private follow-up and responsible disclosure as capacity permits; no fixed remediation SLA is implied.

Do **not** open public issues containing exploit details or unpatched vulnerabilities. If the report concerns Akaunting's own code rather than this MCP, follow [Akaunting's upstream security policy](https://github.com/akaunting/akaunting/security/policy) and coordinate disclosure without posting customer data.

## Scope

Relevant security reports include authentication and OAuth/PKCE problems, incorrect company isolation, unauthorized access, exposure of secrets or financial records, unsafe routes or mutations, and distribution/supply-chain compromises.

The MCP is intended to be operated behind a private tunnel, with an HTTPS OAuth edge, individual Akaunting permissions, encrypted credential storage, strictly reviewed read-only API routes and independent response validation. See [security architecture](docs/SECURITY.md). A failure of an upstream system must be assessed in that system as well; MCP filtering is not a substitute for fixing that upstream vulnerability.
