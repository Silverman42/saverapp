# Module 05 — Fees and Deductions

## Summary

Implement versioned fee terms, immutable fee obligations and entries, a minimal balanced ledger posting boundary, scoped fee views, and safe lifecycle queries. Build on the existing registration-fee rule, snapshot, and obligation foundation. Cross-module actions remain unavailable until their owning workflows provide authoritative contracts.

Adopt the proposed NGN/kobo calculation, recognition, status, waiver, and refund defaults in Module 05. Adopt Module 10's proposed account classes and posting patterns for the ledger slice. Manual fees and other deductions remain disabled until their purposes, Customer descriptions, and accounting destinations are approved.

## Baseline and dependencies

- Laravel 13.32, MySQL, Inertia Laravel 3.3, Vue 3, Wayfinder 0.1, Spatie Laravel Permission 8.3, and Pest 4 are installed.
- Registration fee rules, snapshots, and obligations already exist. The current registration workflow atomically writes a snapshot and positive obligation; explicit zero rules create no payable.
- The current database has no fee rules, snapshots, or obligations. Migrations must still preserve deployed data and fail when historical status cannot be represented accurately as immutable entries.
- Modules 06–09 and the full Module 10 ledger are not implemented. This plan builds only the ledger slice needed to establish fee posting contracts. Plan triggers, collection allocations, withdrawal settlement, reversal approval, external refund payout, and business-draw cash backing remain gated until authoritative owners are available.

## Implementation tasks

| ID | Task | Requirements | Current status |
| --- | --- | --- | --- |
| FEE-T01 | Confirm Module 05 policy defaults, Module 10 account classes and posting patterns, and explicit gates for manual charges and unavailable owner workflows. | Section 17 | Completed — confirmed by user before implementation. |
| FEE-T02 | Extend fee rules, snapshots, and obligations for immutable terms, fee-model inputs, source identity, and entry-derived obligation balances; migrate existing rows safely. | `FEE-FR-002`–`008`, `029` | Implemented. Migration halts on unsupported or inconsistent historical fee data; migration has not been applied. |
| FEE-T03 | Add the controlled ledger account catalogue and internal balanced-posting boundary with immutable groups/entries, source-key uniqueness, integer amounts, and transactional audit evidence. | `FEE-FR-002`, `011`, `024`, `029`, `030` | Implemented. Seeded account mappings remain unconfigured, so postings fail closed. |
| FEE-T04 | Expand versioned rule publication and preview to registration and plan fee options, validate effective intervals and supported model/timing combinations, and preserve immutable snapshots. | `FEE-FR-003`–`007`, `009`, `010`, `036` | Implemented for registration and plan rules/quotes. Plan snapshot consumption and trigger use remain gated on Module 06. |
| FEE-T05 | Provide obligation assessment, outstanding-state queries, partial external settlement interface, Admin waiver, and correction of unsettled assessments with authorization and idempotency. | `FEE-FR-008`, `011`, `012`, `019`, `020`, `027`–`030` | Implemented for registration assessment, partial settlement commands, Admin waiver, and unsettled corrections. Receipt posting remains gated on Module 07 and approved mappings. |
| FEE-T06 | Provide scoped Admin, assigned-Agent, and Customer fee histories and a business earnings/obligation overview with safe filters, stable pagination, and unavailable states. | `FEE-FR-024`, `031`, `032` | Implemented for scoped profile histories and the Admin obligation/earnings view. Earnings and refund payable remain unavailable until accounts are mapped. |
| FEE-T07 | Define and expose typed internal contracts for plan triggers, collection allocations, withdrawal fee quotes, reversal corrections, lifecycle gates, audit, and post-commit notifications. | `FEE-FR-013`–`018`, `021`–`023`, `025`–`030`, `033`–`035` | Implemented for typed quote confirmation, idempotent snapshot assessment, fee position/cycle queries, transactional audit, and queued notices. Owner workflows remain gated. |
| FEE-T08 | Record acceptance evidence for all Module 05 requirements and scenarios; complete only after owning modules and financial gates are available. | `FEE-FR-001`–`036`; `FEE-AC-001`–`048` | Blocked on cross-module owner contracts, approved mappings, and scenario acceptance. |

## Interfaces and ownership boundaries

- Rule and quote services return the applicable immutable rule/snapshot version, currency, amount, basis, timing, source identity, and explicit availability state. Percent calculations use integer basis points and deterministic half-up rounding; monetary calculations never use floating point.
- Internal posting commands accept a closed source/event shape and a stable idempotency key. The ledger validates source uniqueness, approved account mappings, required dimensions, and balanced entries before commit. Client requests cannot supply ledger lines or account codes.
- Obligation state derives from immutable assessment, settlement, waiver, correction, and compensation entries. An unavailable settlement or balance dependency returns unavailable and blocks the action; it never becomes a zero balance.
- Lifecycle callers receive authoritative outstanding fee and refund-payable results. Module 04 owns archival and Customer status; Modules 06–09 own plan, collection, withdrawal, and reversal workflows; Module 10 owns the complete ledger, balances, and statements; Modules 13–14 own notification and canonical audit search/retention policy.
- Manual fee and other-deduction posting is disabled pending approved purpose and destination mappings. External refund payout and fee-earnings withdrawal posting remain disabled without owner-provided payout, reservation, cash-backing, and accounting contracts.

## Acceptance mapping

| Acceptance scenarios | Primary owning tasks |
| --- | --- |
| `FEE-AC-001`–`006` | FEE-T03–T05, FEE-T07 |
| `FEE-AC-007`–`011` | FEE-T02, FEE-T04, with Module 04/Authentication integration |
| `FEE-AC-012`–`017` | FEE-T03, FEE-T05, with Modules 07–09 integration |
| `FEE-AC-018`–`023` | FEE-T03, FEE-T07, with Modules 07–08 integration |
| `FEE-AC-024`–`028` | FEE-T05; manual fee/deduction cases remain Blocked pending approved mappings |
| `FEE-AC-029`–`035` | FEE-T05–T07, with Modules 04, 07–10 integration |
| `FEE-AC-036`–`040` | FEE-T03–T05, FEE-T07, with Modules 04, 06–10 integration |
| `FEE-AC-041`–`048` | FEE-T03–T08, with Modules 04, 06–14 integration |

## Verification scenarios

- Preserve positive and explicit-zero registration snapshots; require reconfirmation on a stale rule version and never reprice existing terms.
- Reject decimal precision, overflow, negative and unsupported values; calculate percentages with integer half-up rounding and assess once per source trigger.
- Keep obligation totals non-negative under partial settlement, waiver races, duplicate attempts, and unknown outcomes; fail closed when balance, reservation, audit, or source authority is unavailable.
- Keep ledger posting groups balanced and immutable, reject duplicate source keys, and preserve Customer liability, Agent cash responsibility, fee income, and other-deduction destinations as separate accounts.
- Deny forged permissions, stale assignment/status, cross-Customer access, protected fields, and inaccessible IDs without leaking private reasons or balances.
- Mark unavailable owner workflows Blocked in acceptance evidence instead of claiming success from isolated fee tests.

Implementation checks completed: PHP syntax validation, `php artisan route:list --path=admin/fees`, Wayfinder generation, `npm run types:check`, `npm run build`, and Laravel Pint. Pest scenarios were not run, and the migrations were not applied to the shared database in this branch.

## Assumptions and deferred decisions

- The adopted policy defaults do not authorize manual charges, arbitrary adjustments, bank execution, external refund payment, or fee-earnings draws.
- Approved counter-account names and mapped destinations are supplied by the Module 10/business-settings owners. Until then, affected ledger commands fail closed.
- Existing non-pending fee obligation states without linked evidence cannot be backfilled faithfully; the migration must stop and require an explicit reconciliation before proceeding.
- No dependency changes are introduced. The full Pest suite, PHPStan, frontend checks, and build remain final release gates after task implementation.


## Coordinated financial implementation checkpoint — 30 September 2026

Task: Complete the approved cash financial owner contracts and private document workflows.
Result: Balanced immutable accounting with direct delegated execution, authoritative scopes, durable replay and protected artifacts.
Scope: Modules 05–10 and dependent projections, notices, audit, reports and settings; bank rails and evidence uploads remain deferred.
Verification: Focused Pest, Pint, scoped PHPStan, frontend checks/build, isolated MySQL races and authenticated accessibility checks before activation.

Controlled fixed charge categories have immutable versions, purpose, Customer description, approved mapping versions and replay-safe publication. Manual fees create unpaid obligations; deductions preserve gross reservations and support Agent-request/Admin-review full compensation. Concessions distinguish savings restoration from external refund entitlement and cash payment. Draws require fees.manage plus explicitly delegated cash.execute and the smaller of undrawn earnings and verified free cash. ManualChargeTest passes 7 tests / 46 assertions; FeeRefundAndDrawTest passes 3 / 48. Full plan-trigger correction, recovery ownership and integrated certification remain open; flags stay false.

Final local verification: complete PHP suite 964 passed / 7,339 assertions, 48 environment-dependent skips; isolated MySQL financial races 3 passed / 24 assertions; changed-owner PHPStan, Pint, changed-frontend lint/format, TypeScript and production build pass. Repository-wide PHPStan retains 91 diagnostics; repository-wide frontend formatting remains unsuccessful. Authenticated financial browser acceptance and the explicitly named residual owner requirements remain open.
