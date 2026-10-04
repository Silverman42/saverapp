# Module 08 — Withdrawals and Payout Approvals

## Summary

Build the request and review workflow from [Module 08](../modules/08-withdrawals-and-payout-approvals.md) on the existing Module 06–07 financial foundations. Payout execution and posting remain blocked. No method is enabled in production, so submission and reservation acquisition remain disabled until an approved method can actually pay.

## Implementation tasks

| ID      | Task                                                                                                                                                            | Requirements                            | Status                                                                                                             |
| ------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| WDL-T01 | Add cycle-attributed authoritative savings and a shared Customer lock for balance-sensitive writes; fail closed on unattributed entries.                        | `WDL-FR-004`, `006`, `021`              | Implemented; production-database concurrency proof pending                                                         |
| WDL-T02 | Persist immutable request terms, live gross reservations, attempt bindings, decisions, holds, deadlines, and event history.                                     | `WDL-FR-005`, `007`–`014`, `020`, `029` | Implemented for unexecuted requests                                                                                |
| WDL-T03 | Quote from the cycle fee snapshot and implement scoped preview, submission, cancellation, one-Admin review, safe revocation, expiry, and restriction holds.     | `WDL-FR-001`–`014`, `020`–`021`         | Implemented behind method gate; broader acceptance pending                                                         |
| WDL-T04 | Add scoped Agent, Admin, and Customer request views, safe notifications, and append-only audit.                                                                 | `WDL-FR-022`–`024`, `026`               | Implemented; accessibility and delivery acceptance pending                                                         |
| WDL-T05 | Keep payout methods, execution, and posting gated until method authority, custody, evidence, finality, recovery, return, and accounting contracts are approved. | `WDL-FR-015`–`019`, `028`               | Cash and a simulated bank rail implemented, every flag off; live use blocked by owner sign-off and a real provider |
| WDL-T06 | Verify amount, authority, concurrency, idempotency, lifecycle, reservation, notification, and unavailable-owner behavior; record release evidence.              | `WDL-AC-001`–`044`                      | 37 Passed, 3 Partial, 4 Blocked; remaining scenarios need owner decisions or external infrastructure (4 October 2026) |

## Interfaces and defaults

- Named routes provide scoped list/detail, create/preview/submit, attempt lookup, approve/reject/cancel/revoke. Server commit checks the preview fingerprint, stable operation UUID, current versions, attestation, and confirmation.
- One request consumes one cycle; gross debit `G` is reserved, fee `F` comes from the immutable plan snapshot, initial other deduction `D` is zero, and net payout `P = G - F` must be positive. One live request per cycle. Quotes expire after ten minutes.
- Pending review expires seven days after submission. Approved or definitively failed requests expire seven days after approval or the latest failed attempt. Restricted holds preserve the reservation and pause expiry, with at least 24 hours to review after lift.
- Current eligible assigned Agents submit/cancel Pending requests. One fresh-authenticated Admin with `withdrawals.review` approves/rejects and may revoke safely before execution. Customer reads own history only. Approval never means payment.
- The method registry is disabled by default. Without a complete enabled payout contract, preview explains unavailability and submission fails without creating a request or reservation. Test fixtures may enable an isolated method to verify the staged workflow.

## Verification and release gates

- Run focused Pest tests, static analysis, Pint, Vue type/build checks, and route inspection. Verify Module 06–07 database/concurrency prerequisites before enabling any financial write.
- Keep payout execution, posting, unknown-outcome recovery, bank/cash evidence, return/reversal, and dependent acceptance scenarios Blocked. No generic mark-paid action or guessed balance is permitted.

## Payout execution decision package — proposed, not approved

The following decisions are required before `WithdrawalMethodRegistry` can resolve any production method. The current registry returns unavailable; no request, reservation, execution, or posting becomes available by adopting this proposal. The accountable approvers are **roles to obtain sign-off from**, not newly granted application permissions. Record the named approver, decision date, mapping version, and evidence in the method contract before changing the gate.

| Decision and accountable approver                                                              | Recommended contract and consequence                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| ---------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Method and executor authority — business operations owner plus security/IAM owner              | Enable each rail separately. A named payout operations role or service identity, separately authorized for execution, may start only an approved request; `withdrawals.review` alone is insufficient. Bind the identity, permission, limits, separation of duties, and revocation behavior to a versioned method. No generic Admin mark-paid or Agent self-attested payment. If no lawful executor is approved, retain the gate.                                                                                                                                                                    |
| Verified destination or cash recipient — customer operations owner plus compliance/fraud owner | Bank payout uses a Customer-owned, independently verified, versioned destination token and masked name/account display. Cash uses a verified Customer or approved recipient identity and contemporaneous handoff acknowledgement from an authorized custodian. Destination/recipient change invalidates the request and requires a new quote and approval. A missing or disputed match produces no send.                                                                                                                                                                                            |
| Funding, custody, and accounts — finance/controller plus treasury/cash operations owner        | Approve a per-method, per-currency NGN map for funding cash/bank clearing, Customer liability G, net payout P, withdrawal fee F, and any later approved D. Require funding sufficiency and immutable mapping version at execution and posting. The current ledger catalogue has `BusinessCash`, `AgentReceivable`, `CustomerSavingsLiability`, and `FeeIncome`, but an account code is not an approved payout custody map; add any necessary controlled clearing account through the owning ledger process. Without balanced mapping, no method is enabled.                                         |
| Finality and unknown recovery — payment operations owner plus finance/controller               | Bind one durable execution identity to request, amount, method, destination version, and funding source before side effect. A method must supply authoritative success and definitive no-transfer proofs, query/reconciliation by the **same** identity, timeout/duplicate handling, and an operations owner for aged unknowns. Proven success posts G/P/F/D exactly once; proven no-transfer keeps G reserved for bounded retry or safe revocation. Ambiguity keeps G reserved, blocks resend and expiry, and opens an exception until resolved. Provider acceptance alone is not payout finality. |
| Returns and chargebacks — payment operations owner plus finance/controller                     | Preserve the original Posted withdrawal. Link provider/cash return evidence and a separate custody event; route any Customer liability restoration and fee consequence through Module 09's approved full-bundle contract. A partial return, chargeback, or unrecoverable payment remains an owned exception; no automatic request rollback, reservation release, or manual balance edit.                                                                                                                                                                                                            |
| Protected evidence and retention — security/privacy owner plus records/legal owner             | Store destination and receipt proofs privately with encryption, scanning, checksum, immutable supplement history, scoped retrieval, masked display, and access audit. Approve retention, legal hold, provider evidence retrieval, and permitted Customer/staff disclosure with Modules 14 and 16 before live use. A missing proof or unapproved storage lifecycle blocks the method.                                                                                                                                                                                                                |

**Method acceptance contract.** For each proposed cash or bank method, the owning team supplies a signed method-version record containing the above decisions, limits, definitive state mapping, provider/cash reconciliation procedure, reversal/return owner, account map, failure drills, and evidence examples. A bank method additionally proves provider idempotency, callback authenticity, settlement distinction, and safe query after timeout. A cash method additionally proves who can release funds, how the recipient and handoff are witnessed, and how disputed or missing acknowledgement becomes Outcome unknown. Exercise `WDL-AC-024`–`030`, `042`, and `044` against that exact method version. Cash acceptance in Module 07 is not approval of cash payout.

**Implementation order after approval.** (1) Finance signs the account/custody map and operations/security sign one method's authority, recipient/destination and evidence contract. (2) Implement that rail's versioned registry and protected evidence intake; keep other rails disabled. (3) Add durable execution identity, authoritative same-attempt reconciliation, and the exactly-once balanced posting boundary, including failure recovery after proven external success. (4) Add linked return exception handling and Module 09 compensation only after its owner contracts are approved. (5) Pass method-specific MySQL races, fault injection, protected-evidence, operational recovery and acceptance scenarios; obtain release approval before enabling the method. No implementation step implies production approval.

## Current checkpoint

The migration, staged request/review services, scoped Inertia pages, hold integration, expiry command, audit, and notification intents are written. The production method registry rejects every method. Focused withdrawal and collection tests pass in the isolated test database; Vue type checking, production build, route inspection, and scoped PHPStan pass. The Module 08 migration was applied to the local MySQL database as batch 18. Module 06–07 concurrency proof, Module 08 full acceptance matrix, and every payout-execution contract remain outstanding before any live submission can be enabled.

## Coordinated financial implementation checkpoint — 30 September 2026

Task: Complete the approved cash financial owner contracts and private document workflows.
Result: Balanced immutable accounting with direct delegated execution, authoritative scopes, durable replay and protected artifacts.
Scope: Modules 05–10 and dependent projections, notices, audit, reports and settings; bank rails and evidence uploads remain deferred.
Verification: Focused Pest, Pint, scoped PHPStan, frontend checks/build, isolated MySQL races and authenticated accessibility checks before activation.

Cash execution is excluded from bootstrap grants and requires explicit delegation. Immutable hash-verified method contracts bind custodian, recipient and amount to Customer acknowledgement. Unknown handoffs preserve reservations and block another payment. CashExecutionTest passes 9 / 104 including posting, audit, outbox and projection failure rollback; CashRecoveryTest passes 2 / 24. Three isolated MySQL races / 24 assertions prove duplicate acknowledgement, competing deductions and payout/deduction serialization. Partial or disputed recovery, generic refund/draw return recovery, complete failure injection and authenticated browser acceptance remain open; payment flags stay false.

Final local verification: complete PHP suite 964 passed / 7,339 assertions, 48 environment-dependent skips; isolated MySQL financial races 3 passed / 24 assertions; changed-owner PHPStan, Pint, changed-frontend lint/format, TypeScript and production build pass. Repository-wide PHPStan retains 91 diagnostics; repository-wide frontend formatting remains unsuccessful. Authenticated financial browser acceptance and the explicitly named residual owner requirements remain open.

## Financial workflow release-readiness checkpoint — 1 October 2026

Partial recovery, original-attempt ownership, scoped recovery previews and clearing consumption are implemented. Direct recipient dispute decisions and the exceptional disposition acceptance matrix remain outstanding.

Status remains **In Progress** for integrated release acceptance. Actual tests, partial authenticated Admin browser evidence, isolated operational exercises and accountable sign-off blockers are recorded in the [financial release package](./financial-workflow-release-readiness.md). Live financial flags remain off; no approval or unavailable acceptance scenario is marked Passed.

## Simulated bank-transfer rail and Module 08 completion work — 4 October 2026

Everything below is local and default-off. Production binds `UnavailablePayoutProvider`; `FakePayoutProvider` is bound only in `local` and `testing`. No live flag, grant, mapping or business setting was changed. A real provider, the finance account map, release evidence and accountable owner sign-off remain required before any live use.

**Authority (approved).** For the simulated rail one Admin may approve, verify the destination and execute. Execution needs `withdrawals.review` with fresh authentication; destination verification needs `customers.manage` with fresh authentication. Cash is unchanged: the approver may also be the cash executor. Separate duties remain an owner decision for any live rail.

**Method contract.** `BankMethodCatalogue::VERSION_ONE` is stored in `cash_method_versions` as `bank_transfer` v1 and hash-verified. It fixes the account map (liability, `payout_clearing_ngn`, `business_bank_ngn`, fee, deduction, `cash_recovery_clearing_ngn`), finality rules, the per-transfer limit (₦5,000,000), the daily limit (₦50,000,000) and three attempts per request. Changing a limit means a new version.

**Destinations.** The assigned Agent registers a Customer-owned account with an attestation. The provider's name enquiry is compared with the Customer's name (exact, partial, mismatch). The account number is resolved to a provider token and is never stored, logged, audited or flashed. An Admin with `customers.manage` verifies, rejects or revokes. One account cannot be registered for two Customers. A replacement is refused while a live request uses the current destination. Revocation puts live requests on a `destination_invalid` hold that blocks payout but does not pause expiry.

**Execution.** `BankPayoutService::start` commits a `prepared` attempt, the idempotency key and state Payout processing before any external call. The queued `DispatchBankPayout` job holds no transaction while calling the provider. A result is final only when a same-key query returns it; a callback only records evidence and triggers that query. A recorded success posts in a separate transaction (idempotency key `bank-withdrawal-{id}`, one reservation consumption), so a posting failure never causes a second transfer and the reconciler (`withdrawals:reconcile-bank-payouts`, every minute) retries it. A timeout is Outcome unknown, which blocks resend, expiry and revocation. "Not found" is a failure only after 30 minutes and never after the provider reported Accepted. A late success after a definitive failure becomes a `provider_conflict` hold plus an exception record and posts nothing.

**Ledger.** Success posts Dr liability G / Cr `payout_clearing_ngn` P / Cr fee F / Cr deduction D. Settlement posts Dr clearing / Cr bank and does not touch liability. A provider return after posting records custody movement into recovery clearing and leaves the withdrawal Posted; a full return is compensated through the Module 09 owner (behind `withdrawals.bank_compensation_enabled`).

**Deduction D.** Zero unless `withdrawals.deduction_enabled` names a published fixed-amount deduction category whose destination mapping is unchanged. D is computed on the server, frozen at submission, part of the quote fingerprint, and checked again at approval and start. It is credited to `other_deduction_destination_ngn` and restored with the fee on full-return compensation.

**Defects fixed.** Lifting a restriction no longer throws for `hold_revalidation_required`; a definitive failed attempt restarts the seven-day window and applies a restriction hold; archival is blocked while a payout is in flight; balance-owner outages return 503 instead of 500; expiry commits each request on its own; cash sufficiency at posting counts other reserved cash; the Create page offers only methods that pass their business-setting gate.

**Evidence.** `BankPayoutTest`, `BankPayoutDestinationTest`, `BankPayoutIntegrationTest`, `WithdrawalDeductionTest`, `WithdrawalDefectTest` and the existing withdrawal and cash suites pass locally. `BankPayoutMySqlConcurrencyTest` passes six races on the isolated MySQL database: callback against reconciler, duplicate callback, two executors, restriction against start, deduction against start and settlement against return. Repository-wide PHPStan has zero diagnostics.

## Acceptance matrix

Status is **Passed** only when every clause has automated evidence, **Partial** when some clause has none, and **Blocked** when it needs an owner decision, a real provider, devices or production infrastructure. Nothing here certifies a release.

| Scenario   | Status                           | Evidence                                                                                                                            | Open clause or blocker                                 |
| ---------- | -------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------ |
| WDL-AC-001 | Passed | `WithdrawalTest` roles and assignment; `WithdrawalAcceptanceAuthorizationTest` |  |
| WDL-AC-002 | Passed | `WithdrawalTest` review grant; `CashExecutionTest` rejection; `WithdrawalAcceptanceAuthorizationTest` |  |
| WDL-AC-003 | Passed | `WithdrawalTest` stale and other Agent; `WithdrawalAcceptanceAuthorizationTest` |  |
| WDL-AC-004 | Passed | `CollectionAuditAcceptanceTest` FEE-AC-035; `WithdrawalAcceptanceLifecycleTest` |  |
| WDL-AC-005 | Passed | `WithdrawalDefectTest` revalidation hold; FEE-AC-035; `WithdrawalAcceptanceLifecycleTest` |  |
| WDL-AC-006 | Passed | `WithdrawalTest`; `CashExecutionTest`; `WithdrawalAcceptanceEligibilityTest` |  |
| WDL-AC-007 | Passed | `CashExecutionTest` Completed cycles; `WithdrawalAcceptanceEligibilityTest` |  |
| WDL-AC-008 | Passed | `WithdrawalTest` unattributed savings; `WithdrawalAcceptanceEligibilityTest` |  |
| WDL-AC-009 | Passed | `WithdrawalTest` protected fields; `WithdrawalDeductionTest`; `WithdrawalAcceptanceEligibilityTest` |  |
| WDL-AC-010 | Partial                          | `BankPayoutDestinationTest` (unverified, third-party, revoked, replaced, masked)                                                    | Evidence file and stale-link cases (uploads deferred)  |
| WDL-AC-011 | Passed                           | `CashExecutionTest` exact gross percentage                                                                                          |                                                        |
| WDL-AC-012 | Passed | `EarlyTerminationPolicyTest`; `WithdrawalFeeQuoteTest`; `WithdrawalDeductionTest` fee plus deduction; `WithdrawalAcceptanceEligibilityTest` |  |
| WDL-AC-013 | Passed | `WithdrawalTest` reservation; plan card asserts; `WithdrawalAcceptanceEligibilityTest` |  |
| WDL-AC-014 | Passed | MySQL reservation races; `WithdrawalMySqlConcurrencyTest` (isolated MySQL) |  |
| WDL-AC-015 | Passed | `CashExecutionTest` cancelled and rejected; `WithdrawalAcceptanceEligibilityTest` |  |
| WDL-AC-016 | Passed | `BankPayoutTest`, `CashExecutionTest`; `WithdrawalAcceptanceLifecycleTest` |  |
| WDL-AC-017 | Passed | `WithdrawalTest` grant; `WithdrawalDeductionTest` contract change; `WithdrawalAcceptanceEligibilityTest` |  |
| WDL-AC-018 | Passed | MySQL admission tests; `WithdrawalMySqlConcurrencyTest` (isolated MySQL) |  |
| WDL-AC-019 | Passed | `WithdrawalTest`, `CashExecutionTest`; `WithdrawalAcceptanceReplayTest` |  |
| WDL-AC-020 | Passed | `WithdrawalDefectTest` (hold on failure, deadline reset, expiry isolation); destination hold expires in `BankPayoutDestinationTest`; `WithdrawalAcceptanceLifecycleTest` |  |
| WDL-AC-021 | Passed | MySQL admission tests; `WithdrawalAcceptanceAuthorizationTest` |  |
| WDL-AC-022 | Passed | `CustomerReassignmentTest`; `PostedPayoutReassignmentTest`; `WithdrawalAcceptanceAuthorizationTest` |  |
| WDL-AC-023 | Passed | `CustomerReassignmentTest`; `WithdrawalAcceptanceAuthorizationTest` |  |
| WDL-AC-024 | Blocked                          | Contract and gate tests (`CashMethodContractTest`, `BankPayoutDestinationTest` availability)                                        | Per-method owner approval, limits and sign-off         |
| WDL-AC-025 | Partial                          | Cash `CashExecutionTest`; bank `BankPayoutTest` success and failure                                                                 | Bank is simulated; real provider finality is Blocked   |
| WDL-AC-026 | Passed (cash and simulated bank) | `CashExecutionTest`; `BankPayoutTest` unknown, late success, accepted                                                               | Real provider reconciliation is Blocked                |
| WDL-AC-027 | Passed                           | `CashExecutionTest`; `BankPayoutTest`; `WithdrawalDeductionTest`                                                                    |                                                        |
| WDL-AC-028 | Passed | Cash rollback bundle; bank success that fails to post then posts once; `WithdrawalAcceptanceFaultTest` (every bundle write) |  |
| WDL-AC-029 | Passed | Settlement leaves liability unchanged (`BankPayoutTest`); distinct dates shown on the Show page; `WithdrawalAcceptanceVisibilityTest` (approval date now rendered) |  |
| WDL-AC-030 | Partial                          | `CashRecoveryTest`; `BankPayoutTest` and `BankPayoutIntegrationTest` returns and compensation                                       | Disputed and partial disposition matrix                |
| WDL-AC-031 | Passed | Submit, start, callback replay; MySQL duplicate callback; `WithdrawalAcceptanceReplayTest` |  |
| WDL-AC-032 | Passed | `WithdrawalTest` attempt lookup; `WithdrawalAcceptanceAuthorizationTest` |  |
| WDL-AC-033 | Passed | `FinancialWorkflowMySqlConcurrencyTest`; `BankPayoutMySqlConcurrencyTest`; `WithdrawalMySqlConcurrencyTest` (isolated MySQL) |  |
| WDL-AC-034 | Passed | `WithdrawalDefectTest` 503 on balance outage; unattributed savings; `WithdrawalAcceptanceFaultTest` (balance and audit outages over HTTP) |  |
| WDL-AC-035 | Passed | `WithdrawalTest`; `BankPayoutDestinationTest` page props; `WithdrawalAcceptanceVisibilityTest` |  |
| WDL-AC-036 | Blocked                          |                                                                                                                                     | Live assistive-technology and device sessions          |
| WDL-AC-037 | Passed | `WithdrawalTest` notices; `WithdrawalDefectTest` revalidation notice; `WithdrawalAcceptanceVisibilityTest` |  |
| WDL-AC-038 | Passed | Audit rollback with posting; bank events carry unique operation identity; `WithdrawalAcceptanceVisibilityTest`; denied and conflicting decisions and submissions now emit `withdrawal.decision_denied` / `withdrawal.submission_denied`; no export path exists |  |
| WDL-AC-039 | Blocked                          | `FinancialWorkflowRestoreMySqlTest`                                                                                                 | Production-equivalent load and restart                 |
| WDL-AC-040 | Passed | Posted-only projection; `WithdrawalAcceptanceVisibilityTest` |  |
| WDL-AC-041 | Passed                           | `CashExecutionTest`                                                                                                                 |                                                        |
| WDL-AC-042 | Blocked | Gates default off; `CashMethodContractTest`; `WithdrawalAcceptanceVisibilityTest` compensation flag | Owner sign-off for live compensation |
| WDL-AC-043 | Passed | `CashExecutionTest` fixed once; `WithdrawalAcceptanceLifecycleTest` |  |
| WDL-AC-044 | Passed | `BankPayoutMySqlConcurrencyTest` restriction against start; `WithdrawalDefectTest` hold on failure; `WithdrawalMySqlConcurrencyTest` (isolated MySQL) |  |
