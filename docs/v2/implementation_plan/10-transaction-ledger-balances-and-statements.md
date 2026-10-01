# Module 10 — Transaction Ledger, Balances, and Statements

## Summary

Implement [Module 10](../modules/10-transaction-ledger-balances-and-statements.md) in gated stages. Extend the existing NGN ledger, derive authoritative balances, project scoped transaction history, and provide reproducible Customer statements. Record each of `LED-AC-001`–`055` as Passed, Failed, or Blocked. No financial action is enabled without its owning workflow and complete accounting contract.

## Implementation tasks

| ID      | Task                                                                                                                                                                                                                                                               | Requirements                           | Status      |
| ------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | -------------------------------------- | ----------- |
| LED-T01 | Extend the controlled account, posting-group and entry schema with source, cycle, actor, mapping-version and cutoff data. Backfill only provable historical dimensions; preserve immutable entries and existing references.                                        | `LED-FR-001`–`009`, `013`, `020`–`021` | In Progress |
| LED-T02 | Unify eligible collection and fee owner commands behind balanced, versioned posting and durable idempotency. Serialize a commit watermark and atomically retain source links and audit. Keep payout, deduction and reversal posting gated pending owner contracts. | `LED-FR-002`–`009`, `035`–`036`        | In Progress |
| LED-T03 | Derive Customer, cycle, Agent receivable, custody and fee positions from entries, subtract live gross reservations once, and fail closed on absent or inconsistent data.                                                                                           | `LED-FR-014`–`019`, `034`              | In Progress |
| LED-T04 | Build a versioned, rebuildable business transaction projection with one root transaction per source event, linked components and compensations, stable public references, integrity checks and incident states.                                                    | `LED-FR-010`–`013`, `020`–`024`        | In Progress |
| LED-T05 | Add scoped transaction search, detail, balance summaries and role-specific Vue screens. Bind stable cursors to scope, filters, sort, cutoff and projection version.                                                                                                | `LED-FR-025`–`027`, `033`–`034`        | In Progress |
| LED-T06 | Add one-Customer statement preview, immutable issuance, correction links, authorized history and downloads. Gate PDF issuance until renderer, artifact, identity and retention contracts are verified.                                                             | `LED-FR-029`–`032`                     | In Progress |
| LED-T07 | Add permission-gated business exports, safe notification intents, audit, performance and recovery evidence only after their owners' policies are approved.                                                                                                         | `LED-FR-028`, `037`–`040`              | Blocked     |
| LED-T08 | Verify each acceptance scenario and record dependency evidence and release status.                                                                                                                                                                                 | `LED-AC-001`–`055`                     | Blocked     |

## Interfaces and defaults

- Add named, scoped routes for transaction list/detail, Customer balances, statement preview/history/generation/download and authorized integrity reads. Vue uses Wayfinder. Customer reads own records, Agent reads currently assigned Customers, and Admin has baseline read access. Business export also requires `reports.export`.
- Adopt the Module 10 draft defaults: `TXN-YYYYMMDD-NNNNNN` public transaction references, Posted/Reversed financial statuses, newest committed first, 25 rows by default with 25/50/100 choices, a 366-date interactive and statement range, and cutoff-based Issued/Superseded statements. Preserve true occurrence and UTC commit times.
- Owner services submit closed, versioned posting commands. No client endpoint accepts journal lines or editable balances. Existing collection and fee posting is the integration baseline; payout execution, arbitrary deductions, reversal compensation, manual adjustments, PDF and business exports remain gated until their contracts exist.
- A mixed savings-and-fee receipt has one root business transaction with distinct components. Customer savings is posted liability less live gross reservations exactly once. A missing authoritative source yields Unavailable, never zero.

## Verification and release

Cover exact kobo arithmetic, balanced and duplicate posting, mixed receipts, reservations, cycle reconciliation, role scope and reassignment, cursor stability, cutoff replay, late posting, compensation linkage, rebuild and integrity incidents with focused Pest tests. Run affected tests, Pint, scoped PHPStan, Vue type/build checks and route inspection. Prove concurrency and rollback on the production database engine before enabling affected financial writes. Record upstream payout, reversal, PDF, export, retention and recovery scenarios as Blocked until the required contracts and evidence exist.

## Implementation checkpoint — 24 September 2026

- `LED-T01`–`LED-T03` are in progress. Existing collection/fee postings now retain occurrence, timezone, cycle, source and entry dimensions. Customer liability and live reservation views use authoritative entries. Payout, deduction, reversal and complete cross-account reconciliation await their owner contracts.
- `LED-T04`–`LED-T05` are in progress. A rebuildable versioned projection groups each posted receipt into one transaction, projects confirmed remittances separately, verifies balanced source groups, records integrity incidents and promotes atomically. Scoped transaction search/detail and balance screens use signed cursors and fail closed on an unavailable projection.
- `LED-T06` is in progress. Scoped one-Customer statement preview computes opening, activity and closing at a recorded projection watermark. Immutable issuance, supersession, PDF and downloads are blocked by the artifact and policy contracts.
- `LED-T07`–`LED-T08` remain blocked for full acceptance by export/privacy/retention/recovery policy and unfinished payout/correction owners. Focused tests, local MySQL migrations and empty-ledger rebuild, Pint, scoped PHPStan, Vue type checking and production build pass. The 55-scenario acceptance matrix, nonempty historical backfill, concurrency, restore and performance evidence remain outstanding.
- The adjacent Customer registration suite has 24 existing failures unrelated to this change: its helper calls `RegistrationFeeService::publishRule()` without the required `Request` argument already present in `HEAD`, and fixed historical publication dates are rejected by the current date rule. It is not release evidence for Module 10.

## Approved assumptions

The Module 10 draft product defaults are adopted. No new package or production financial authority is implied by this plan. A scenario cannot be marked Completed while its upstream owner or release gate remains unresolved.

## Coordinated financial implementation checkpoint — 30 September 2026

Task: Complete the approved cash financial owner contracts and private document workflows.
Result: Balanced immutable accounting with direct delegated execution, authoritative scopes, durable replay and protected artifacts.
Scope: Modules 05–10 and dependent projections, notices, audit, reports and settings; bank rails and evidence uploads remain deferred.
Verification: Focused Pest, Pint, scoped PHPStan, frontend checks/build, isolated MySQL races and authenticated accessibility checks before activation.

Projection owners include cash withdrawals, receipt/withdrawal/deduction compensation, fee concessions, external refund payments and earnings draws. Statement issuance captures current confirmed preview fingerprints, immutable encrypted snapshots and reproducible controls; supersession links preserve original issued bytes. FinancialArtifactTest passes 7 cases, including stale previews, protected access, retry generations, cancellation finality, expiry and holds. A 200-line statement rendered to six A4 pages and was visually checked at the first and final pages. Controlled replacement projections, complete correction dependencies and operational certification remain open.

Final local verification: complete PHP suite 964 passed / 7,339 assertions, 48 environment-dependent skips; isolated MySQL financial races 3 passed / 24 assertions; changed-owner PHPStan, Pint, changed-frontend lint/format, TypeScript and production build pass. Repository-wide PHPStan retains 91 diagnostics; repository-wide frontend formatting remains unsuccessful. Authenticated financial browser acceptance and the explicitly named residual owner requirements remain open.

## Financial workflow release-readiness checkpoint — 1 October 2026

Replacement/recovery projections and clearing balances are implemented; shared artifact recovery fences publication and deduplicates notices. Complete effective totals and full artifact retention acceptance remain outstanding.

Status remains **In Progress** for integrated release acceptance. Actual tests, partial authenticated Admin browser evidence, isolated operational exercises and accountable sign-off blockers are recorded in the [financial release package](./financial-workflow-release-readiness.md). Live financial flags remain off; no approval or unavailable acceptance scenario is marked Passed.
