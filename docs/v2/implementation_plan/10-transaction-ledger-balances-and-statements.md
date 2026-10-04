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

## Completion work — 4 October 2026

Nothing here enables a financial action: payout, deduction and reversal posting stay behind their owners' default-off flags, and no mapping, grant or live setting was changed.

**Built.**

- A full posted reversal turns the projected original into **Reversed**, with a compensating reference on the original and an original reference on the reversal. Original rows are never edited. A no-money correction links the same way.
- Transaction detail shows signed components (G, F, D, P for withdrawals), a dated timeline (requested, approved, occurred, committed) and, for staff only, the responsible actors. Customers never receive actor names.
- Ledger accounts with `retired_at` set refuse new postings at the single entry point (`LedgerEntry` creation). History and rebuilds still read them.
- The production engine refuses UPDATE and DELETE on `ledger_posting_groups` and `ledger_entries` with MySQL triggers. SQLite test databases keep only the model guards because existing suites damage rows on purpose to prove readers fail closed.
- A failed rebuild no longer loses its incident when run from the command, and no longer hides history. The projection state is **stale** when an earlier verified projection exists but the ledger is ahead or the latest rebuild failed: search and detail keep serving it up to its watermark with a visible notice, while balances and every balance-sensitive action stay unavailable. A scheduled `ledger:rebuild-transactions --if-stale` catches up.
- A clean rebuild marks an incident **recovered**. Only an Admin with `reconciliation.manage` and fresh authentication resolves it, with a note, and an audit event. A still-failing incident cannot be resolved.
- Statements: the preview takes no row lock on reads (issuance still locks); a reservation outage withholds only the availability figure; the preview and the PDF show per-type totals, reserved, available and unpaid fees as separate positions outside the closing balance; the history marks superseded statements.
- Denied transaction reads are audited, and the ledger, balance and preview routes are rate-limited with their own buckets (inline `throttle:N,M` limits share one counter per user unless given a prefix).

**Not done, with reason.**

- Failing closed on `statement_pdf` and `report_exports` when no business configuration exists: `BusinessSettings::ensureFeature` allows every capability until Module 15 business configuration is effective, and the suite relies on that. Changing it needs an owner decision on legacy behaviour.
- Business-wide export, PDF accessibility and live device review, backup and restore against production, retention and key custody: external owner work (LED-T07/T08).

**Load profile (LED-AC-052).** `tests/Feature/LedgerLoadProfileTest.php` runs only with `LEDGER_LOAD_PROFILE=1` on the isolated MySQL database. It seeds the Module 07 dataset (10,000 Customers, 30 Agents, 20,000 plans, 2,000,000 slots) plus 200,000 balanced postings with projection rows, a fixture Customer with 1,990 statement rows, then measures 20 samples per read and five statement generations through the real HTTP kernel at concurrency 1, writing `/private/tmp/saverapp-ledger-load-profile.json`.

## Acceptance matrix

| Scenario   | Status                          | Evidence                                                                                                                                | Open clause or blocker                                                          |
| ---------- | ------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------- |
| LED-AC-001 | Passed                          | `LedgerAcceptanceGapsPostingTest` unbalanced command posts nothing; `LedgerImmutabilityMySqlTest` triggers; model guards                |                                                                                 |
| LED-AC-002 | Passed                          | `LedgerAcceptanceGapsPostingTest` kobo caps, overflow fail-closed in rebuild and statement                                              |                                                                                 |
| LED-AC-003 | Passed | `LedgerIntegrityTest` retired account refuses postings, history readable; `LedgerAcceptanceClosureTest` |  |
| LED-AC-004 | Blocked | Columns and damaged-actor detection (`FeeSavingsApplicationTest`); `LedgerAcceptanceClosureTest` approver retained on the immutable group | Lifecycle retention policy (owner decision) |
| LED-AC-005 | Passed                          | `LedgerAcceptanceGapsPostingTest` dimension and currency defects                                                                        |                                                                                 |
| LED-AC-006 | Passed | `LedgerAcceptanceGapsPostingTest` unique references, scope-safe 404, cursor replay; `LedgerReferenceMySqlConcurrencyTest` (4 processes, 200 unique references) |  |
| LED-AC-007 | Passed                          | `LedgerAcceptanceGapsPostingTest` no journal route; Admin with every permission refused                                                 |                                                                                 |
| LED-AC-008 | Passed                          | `LedgerAcceptanceGapsPostingTest` remittance lines and zero savings effect                                                              |                                                                                 |
| LED-AC-009 | Passed                          | `CollectionTest`; `FeeSavingsApplicationTest`                                                                                           |                                                                                 |
| LED-AC-010 | Passed                          | `WithdrawalDeductionTest` G, F, D and P posted once and projected                                                                       |                                                                                 |
| LED-AC-011 | Passed                          | `CollectionTest`; `FeeRefundAndDrawTest`; `CashExecutionTest`                                                                           |                                                                                 |
| LED-AC-012 | Passed                          | `CollectionTest` mixed receipt; `ReportTest`                                                                                            |                                                                                 |
| LED-AC-013 | Passed                          | `LedgerIntegrityTest` Reversed status with links; `ReportTest` readers accept Reversed originals                                        |                                                                                 |
| LED-AC-014 | Passed                          | `LedgerAcceptanceGapsPostingTest` late posting keeps occurrence date and later UTC commit                                               |                                                                                 |
| LED-AC-015 | Passed                          | Position, batch and owner reads agree (`CashExecutionTest`, `CollectionTest`)                                                           |                                                                                 |
| LED-AC-016 | Passed                          | `LedgerAcceptanceGapsPostingTest` two reservations leave 30,000                                                                         |                                                                                 |
| LED-AC-017 | Passed                          | `WithdrawalTest`; `CashExecutionTest`; MySQL reservation boundary                                                                       |                                                                                 |
| LED-AC-018 | Passed                          | `CollectionTest`; `CustomerBalanceBatchTest`; `ReportTest`                                                                              |                                                                                 |
| LED-AC-019 | Passed                          | `CashExecutionTest`; `PostedPayoutReassignmentTest`                                                                                     |                                                                                 |
| LED-AC-020 | Passed                          | `LedgerAcceptanceGapsIntegrityTest` total liability across statuses                                                                     |                                                                                 |
| LED-AC-021 | Passed | `LedgerIntegrityTest` and `LedgerAcceptanceGapsIntegrityTest` stale watermark blocks balance, batch, statement; `LedgerAcceptanceClosureTest` (approval with projection rows deleted) |  |
| LED-AC-022 | Passed                          | `LedgerAcceptanceGapsIntegrityTest` full reversal only; original rows identical                                                         |                                                                                 |
| LED-AC-023 | Passed                          | `LedgerAcceptanceGapsIntegrityTest` review-only Admin refused everywhere else                                                           |                                                                                 |
| LED-AC-024 | Passed                          | `LedgerAcceptanceGapsPostingTest` closed month and immutable occurrence date                                                            |                                                                                 |
| LED-AC-025 | Passed                          | `LedgerAcceptanceGapsIntegrityTest` rebuild reproduces rows and statuses                                                                |                                                                                 |
| LED-AC-026 | Passed                          | `LedgerIntegrityTest` stale history after a failed rebuild; `LedgerAcceptanceGapsIntegrityTest`                                         |                                                                                 |
| LED-AC-027 | Passed                          | `LedgerAcceptanceGapsIntegrityTest` receipt, withdrawal, remittance and compensation mismatches open incidents                          |                                                                                 |
| LED-AC-028 | Passed | `LedgerIntegrityTest`, `LedgerAcceptanceGapsIntegrityTest` incident reference, recovery and explicit resolution; `LedgerAcceptanceGapsIntegrityTest` scoped incident (other Customers stay readable) |  |
| LED-AC-029 | Passed                          | `LedgerAcceptanceGapsIntegrityTest` 73 rows page once across 25-row pages                                                               |                                                                                 |
| LED-AC-030 | Passed                          | `LedgerAcceptanceGapsIntegrityTest` range, cursor and filter rejection                                                                  |                                                                                 |
| LED-AC-031 | Passed | `LedgerIntegrityTest` reversal detail actors and links; `LedgerAcceptanceClosureTest` |  |
| LED-AC-032 | Passed                          | `LedgerAcceptanceGapsAccessTest` six-viewer scope matrix                                                                                |                                                                                 |
| LED-AC-033 | Passed                          | `LedgerAcceptanceGapsAccessTest` former Agent loses transaction and artifact access                                                     |                                                                                 |
| LED-AC-034 | Passed                          | `LedgerAcceptanceGapsAccessTest`; `FinancialArtifactTest`                                                                               |                                                                                 |
| LED-AC-035 | Passed                          | `ReportTest`; `FinancialArtifactTest`                                                                                                   |                                                                                 |
| LED-AC-036 | Passed                          | `LedgerAcceptanceGapsStatementTest`; `FinancialArtifactTest`                                                                            |                                                                                 |
| LED-AC-037 | Passed                          | `LedgerAcceptanceGapsStatementTest`; `LedgerIntegrityTest` type totals                                                                  |                                                                                 |
| LED-AC-038 | Passed                          | `LedgerAcceptanceGapsStatementTest`; `LedgerIntegrityTest` reserved, available and unpaid fees                                          |                                                                                 |
| LED-AC-039 | Passed | `FinancialArtifactTest` supersession preserves original bytes; `LedgerAcceptanceClosureTest` |  |
| LED-AC-040 | Passed | Superseded label on the statement history; `LedgerAcceptanceClosureTest` (Customer notice on corrected statement) |  |
| LED-AC-041 | Partial                         | `LedgerAcceptanceGapsStatementTest` app, preview and rendered view agree; integrity-checked download                                    | Tagged, accessible PDF (external)                                               |
| LED-AC-042 | Passed                          | `LedgerAcceptanceGapsStatementTest` inconsistent source and tampered snapshot; `LedgerIntegrityTest` outage withholds only availability |                                                                                 |
| LED-AC-043 | Passed                          | `LedgerAcceptanceGapsAccessTest` exact props per role                                                                                   |                                                                                 |
| LED-AC-044 | Blocked                         |                                                                                                                                         | Live assistive-technology and device review                                     |
| LED-AC-045 | Passed                          | `CollectionTest`; `FinancialArtifactTest`; database unique keys                                                                         |                                                                                 |
| LED-AC-046 | Passed                          | `FinancialWorkflowMySqlConcurrencyTest`; `BankPayoutMySqlConcurrencyTest`                                                               |                                                                                 |
| LED-AC-047 | Passed                          | `CollectionTest`; `CashExecutionTest`                                                                                                   |                                                                                 |
| LED-AC-048 | Passed                          | `LedgerIntegrityTest` scheduled `--if-stale` catch-up; `CollectionTest` outage cases                                                    |                                                                                 |
| LED-AC-049 | Passed | `FinancialArtifactRecoveryAcceptanceTest`; `ReversalTest`; `LedgerAcceptanceClosureTest` |  |
| LED-AC-050 | Partial                         | `LedgerAcceptanceGapsAccessTest` audit events; `LedgerIntegrityTest` denied reads audited                                               | Export audit (no export of raw ledger exists)                                   |
| LED-AC-051 | Passed                          | `LedgerAcceptanceGapsAccessTest`; `AuditWorkspaceTest`                                                                                  |                                                                                 |
| LED-AC-052 | Passed (isolated local profile) | `LedgerLoadProfileTest`: balance and first page, lookup, preview and 1,990-row statement incl. PDF at p95 within targets                | Production-equivalent concurrency and device profile                            |
| LED-AC-053 | Blocked                         | `FinancialWorkflowRestoreMySqlTest` local restore                                                                                       | Production backup and restore drill                                             |
| LED-AC-054 | Blocked                         | `FinancialArtifactTest` protection and expiry                                                                                           | Retention, legal hold and key custody owner                                     |
| LED-AC-055 | Passed                          | `LedgerAcceptanceGapsStatementTest` six dependency removals                                                                             |                                                                                 |
