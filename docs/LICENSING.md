<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Licensing, upstream rights and trademarks

**Two separate programs:** The MCP source is licensed under AGPL-3.0-or-later (see [LICENSE](../LICENSE)). It communicates with Akaunting through the latter's supported REST API. The MCP distribution is not a redistribution of Akaunting, and this license **does not** authorize production use of Akaunting.

For example, **Akaunting 3.1.21** ships a `LICENSE.txt` under **Business Source License 1.1 (BSL 1.1)**. Its Additional Use Grant restricts production use above the specified user, company or invoice thresholds (two users, one company, one thousand invoices), rebranded/white-label distribution, and use as an `Accounting Service` defined in that license. The license provides a version-specific change to GPLv3 four years after the relevant public release date. **Do not infer that all Akaunting versions have already changed to GPLv3.**

Read the license shipped with the exact Akaunting release and review any commercial license, entitlement, usage thresholds, downstream terms and restrictions **before** deploying the combined solution. The [version-pinned Akaunting 3.1.21 license](https://github.com/akaunting/akaunting/blob/3.1.21/LICENSE.txt) is a reference for that release; a newer installation must be assessed independently. [Akaunting's commercial on-premise plans](https://akaunting.com/plans-on-premise) do not by themselves demonstrate that a systems integrator has reseller, managed-hosting or third-party accounting-service rights. Obtain explicit terms for the intended arrangement. See also the [Akaunting REST API documentation](https://akaunting.com/hc/docs/developers/restful-api/).

The independent MCP uses its own source code, PHP standard/runtime features and external runtime container packages. No Akaunting source-tree model, controller or vendor compatibility patch is part of the intended public MCP distribution. Any separate patch applied to Akaunting remains governed by its own provenance and upstream rights; publishing the MCP must not silently relicense it.

LibreCode can offer installation, integration, configuration, consulting and maintenance services without representing itself as an Akaunting licensor. Services must not be advertised as including rights to operate the Akaunting application beyond those actually held by the customer. Akaunting names are used only to identify compatibility; this project is independent and is neither official nor endorsed.

This is a technical rights inventory, not a legal opinion. Obtain specialist advice for software distribution, managed hosting, third-party accounting services or commercial deployments beyond the Additional Use Grant.
