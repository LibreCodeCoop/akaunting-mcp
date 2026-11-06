<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Configure and operate NFS-e through the MCP

The MCP uses the **same authenticated NFS-e REST endpoints** as the Akaunting module. It does not calculate taxes, decide a company's legal regime, store PFX certificates or create an alternative fiscal issuer.

## Before configuring a company

1. Create the company in Akaunting and grant the operator only the necessary company-scoped permissions.
2. Configure the module's Vault/OpenBao infrastructure and upload the company's certificate through the **existing secure Akaunting administration flow**. Certificate files, passwords and secret-store credentials must not be pasted into ChatGPT or passed through an MCP JSON mutation.
3. Use `akaunting_api_read` with `api.nfse.settings.company-profiles.index` to inspect the active company profile and the `sandbox_mode` value. The value `true`/`1` selects **produção restrita**; `false`/`0` selects production.
4. Consult `api.nfse.settings.tax-associations.index` and `api.nfse.dps.readiness`. An active profile or a green basic configuration badge does not prove that a DPS can be transmitted.
5. Supply verified tax-regime, municipality, service and taxpayer facts. The assistant may organize and validate them against the module's published fields, but must not infer tax identities, exemption, legal competence or authorization from a name or percentage.

## Review and approve a configuration change

`akaunting_mutation_routes` lists the reviewed operations. `akaunting_prepare_mutation` accepts one exact route, method, selected `company_id`, typed path parameters and JSON body. The MCP returns a short-lived approval link and does not execute the change.

The following NFS-e operations are available when the matching module API is deployed:

| Route | Method | Purpose |
| --- | --- | --- |
| `api.nfse.dps.advance` | POST | Explicitly advance the shared DPS counter with its expected next number, justification and personal approval; never allocate/emit a note |
| `api.nfse.bulk.dispatch` | POST | Submit a reviewed batch of 1–100 existing invoices to the native emission queue **only in produção restrita**; no immediate HTTP transmission |
| `api.nfse.invoices.refresh` | POST | Reconcile the state of the current fiscal receipt with the government API; requires the current receipt ID and separate approval |
| `api.nfse.settings.company-profiles.update` | PATCH | Update non-secret fiscal parameters for the selected existing profile |
| `api.nfse.settings.tax-associations.store` | POST | Confirm the fiscal identity and role of an existing Akaunting tax |
| `api.nfse.settings.tax-associations.revise` | PATCH | Record a dated revision of a confirmed tax association |
| `api.nfse.settings.approximate-taxes.store` | POST | Record an explicitly sourced approximate-tax estimate |
| `api.nfse.invoices.fiscal-event-evidence.store` | POST | Attest a documented taxable-event date for an existing fiscal group |
| `api.nfse.invoices.emit` | POST | Issue a single NFS-e **only in produção restrita**, after DPS and invoice preflight |
| `api.nfse.invoices.reemit` | POST | Reissue the current **cancelled** receipt only in produção restrita, after the same preflight |
| `api.nfse.invoices.substitute` | POST | Substitute the current **emitted** receipt directly in produção restrita; no intervening cancellation |
| `api.nfse.invoices.cancel` | DELETE | Cancel the current authorized NFS-e **only in produção restrita**, preserving its financial invoice |

The profile PATCH accepts `fiscal_parameters` only, never `pfx_file`, `pfx_password` or arbitrary nested fields. It cannot activate the production gateway (`sandbox_mode=false`) or automatic issuance (`emission_policy=emit_on_send`); these transitions require separate operational review outside the MCP. Tax association confirmation requires an existing tax ID, legal provenance, effective dates and `acknowledge=true`. Approximate tax rates are decimal **strings**, not floating-point numbers.

Each prepared operation must be reviewed in the HTTPS approval page by the **same personal Akaunting user**, who reauthenticates and explicitly authorizes it. A cancellation requires typing `CANCELAR`; a native Core deletion continues to require `EXCLUIR`. The approved request is single-use and expires after ten minutes. Verify the result with `akaunting_mutation_status` and a separate read.

## Read-only operational POSTs

Some native fiscal APIs use HTTP `POST` for a **read-only computation**.
The MCP exposes only two such reviewed operations through
`akaunting_api_read` and an optional `post_body`, not through the write
approval mechanism:

- `api.nfse.settings.vault.diagnostics`: omit `post_body` or send an
  empty JSON object. Returns operational status and strictly boolean
  capability flags, never Vault credentials or certificate content.
- `api.nfse.bulk.plan`: send `post_body: {"invoice_ids":[7,8]}` using
  actual, distinct invoice IDs (1–100). Returns the native queue preview,
  blocked invoices, already issued invoices and inaccessible IDs. It **does
  not** enqueue or transmit any invoice.

The MCP validates these operations' paths, methods, bodies, JSON responses
and company boundaries. An ordinary API `POST` is **not** a read; the
exception is limited to these verified services. This also means that a
preview never counts as authorization for a later bulk dispatch.

## Reviewed operational mutations

- `api.nfse.dps.advance`: prepare an explicit monotonic adjustment using
  `expected_next_number`, `next_number` as decimal strings, a 12–500
  character `reason`, and `confirmed: true`. The MCP checks the shared
  series and next-number state before and after browser approval. The
  module's authoritative transaction enforces the actual sequence rule.
  This operation can skip DPS numbers and must not be used casually.
- `api.nfse.invoices.refresh`: supply `route_params: {"invoice":7}`
  and `body: {"nfse_receipt_id":12,"nfse_force_ajax":true}`. The MCP
  verifies the currently linked receipt and owned parent invoice; this
  reconciles status and **does not issue a replacement NFS-e**.
- `api.nfse.bulk.dispatch`: supply up to 100 verified `invoice_ids`,
  after reviewing their results from `api.nfse.bulk.plan`. A single
  personal browser approval freezes the invoice list and company;
  the MCP rechecks all parent invoices, active profile and restricted DPS
  readiness immediately before dispatch. The module queues the batch;
  workers subsequently perform each individual fiscal preflight. The
  operation remains **disabled outside produção restrita** or when the
  fiscal write feature switch is off. An uncertain dispatch must be
  reconciled using `api.nfse.bulk.runs` before attempting another run.

All mutating operations require the existing independent browser approval.
No MCP tool directly authorizes or executes a mutation.

## Document the taxable event before issuing

Read `api.nfse.invoices.fiscal-assessment` for the selected invoice to obtain its persisted `group_key` and the **calendar-only** RTC diagnostics (`final_dps_checked=false`). This is not a final DPS compliance verdict. The commercial invoice issue date is not evidence of when the taxable service event occurred.

The operator must supply a verified event date and a source document reference. Prepare `api.nfse.invoices.fiscal-event-evidence.store` with `fiscal_group_key` copied exactly from the read, `taxable_event_date` in `YYYY-MM-DD`, a meaningful `source_reference` and `acknowledge=true`. The MCP does not infer or attest an event on its own. The browser approval and a fresh group/assessment comparison are mandatory; the module additionally rejects unresolved emission attempts. This configuration action remains available while the fiscal transmission switch is disabled.

## Restricted fiscal issuance and cancellation

The deployment switch `MCP_NFSE_FISCAL_WRITES_ENABLED` defaults to `false`. An operator may set it to `true` only after verifying the deployed module's unified legal preflight, active and reconciled DPS authority, restricted environment and synthetic integration tests. This switch does not bypass the separate browser approval or any native fiscal guard.

Before preparing an issue request, the MCP independently checks company membership, the parent invoice's ownership/type, the selected company's active profile and `sandbox_mode`, the module's DPS readiness, the invoice's fiscal profile preview and absence of an existing receipt. The module still owns every authoritative validation, fiscal number allocation, SEFIN transmission, attempt journal and recovery.

An issue proposal uses `api.nfse.invoices.emit` with `{"nfse_force_ajax":true}` and an invoice ID. If the preview contains multiple fiscal groups, the correct `nfse_fiscal_group_key` must be selected explicitly; the assistant must not guess. A cancellation proposal requires the **current** `nfse_receipt_id`, an allowed `cancel_reason` (`1`, `2` or `9`) and a specific `cancel_justification` of 15–255 characters. A reemission proposal uses `api.nfse.invoices.reemit` with `{"nfse_receipt_id": 12, "nfse_force_ajax": true}` (substitute the actual latest receipt ID), **only after that receipt was cancelled**. The MCP verifies its cancelled state through the reviewed fiscal ledger before preparing an approval and rechecks it immediately before dispatch. Reemission never cancels a receipt implicitly and never handles split fiscal groups. A direct substitution proposal uses `api.nfse.invoices.substitute` with the **current emitted** `nfse_substitution_receipt_id`, an allowed reason code (`01`, `02`, `03`, `04`, `05` or `99`), a specific 15–255 character `nfse_substitution_description`, and `nfse_force_ajax: true`. The MCP verifies the current emitted receipt through the reviewed fiscal ledger, frozen invoice evidence, DPS readiness, and fiscal preview both when preparing and just before approval execution. It cannot substitute a cancelled, previously substituted, foreign or stale receipt; no separate cancellation occurs.

The MCP rejects fiscal issue/cancel proposals if the profile points to **production**, if the DPS authority is inactive, if the invoice is not ready, if the selected company differs or if a newer receipt appeared. The same evidence is checked immediately before an approved request is executed. An uncertain network result is **not** authorization to retry; inspect the attempt journal and reconcile through the module's supported recovery flow.

## Limits

- This increment does **not** upload or replace certificates, provision a new company profile, change Vault secrets, download XML/PDF or import ADN documents. Reviewed bulk emission is limited to **produção restrita** with independent browser approval and native queue preflight.
- Production NFS-e issuance and cancellation are intentionally unavailable through the MCP until a separately reviewed production policy and legal preflight are proven. The module's own REST endpoints and permissions remain authoritative.
- A confirmed tax association does not prove legal correctness. Never auto-create IBS/CBS as financial line taxes or assume a cooperative is exempt from a specific withholding without documented, transaction-specific evidence.
- A complete onboarding still requires secure certificate intake, official tax/municipal evidence, DPS-series activation and verification of the installed module's version and readiness.
- Use only disposable synthetic Akaunting installations and fake SEFIN for automated tests. A live produção restrita issue/cancel test is a separately verified operational action, not part of CI.

See [API coverage](API-READ-CONTRACT.md), [security](SECURITY.md) and [deployment](DEPLOY.md).
