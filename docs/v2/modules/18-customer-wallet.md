# Customer Wallet

**Product version:** 2.0  
**Module:** 18  
**Module status:** Specified for review; not implemented  
**Sources:** [User Types](./01-user-types.md), [Roles and Permissions](./03-roles-and-permissions.md), [Customer and Agent Management](./04-customer-and-agent-management.md), [Fees and Deductions](./05-fees-and-deductions.md), [Thrift Plans and Savings Cycles](./06-thrift-plans-and-savings-cycles.md), [Collections, Digital Thrift Card, and Reconciliation](./07-collections-thrift-card-and-reconciliation.md), [Withdrawals and Payout Approvals](./08-withdrawals-and-payout-approvals.md), [Reversals and Financial Corrections](./09-reversals-and-financial-corrections.md), [Transaction Ledger, Balances, and Statements](./10-transaction-ledger-balances-and-statements.md)  
**Downstream dependencies:** [Dashboard and Operational Analytics](./11-dashboard-and-operational-analytics.md), [Reports and Exports](./12-reports-and-exports.md), [Notifications and Communication](./13-notifications-and-communication.md), [Business Settings and Configuration](./15-business-settings-and-configuration.md)  
**Design documents:** [High-level design](../implementation_plan/18-customer-wallet.md), [Low-level design](../implementation_plan/18-customer-wallet-lld.md)

## 1. Purpose and decision status

Today a Customer's money leaves a thrift plan only through a Module 08 withdrawal request. That request takes money from exactly one plan and pays it straight out by cash, bank transfer or an offline record. Closing a plan pays nothing out by itself, and Module 08 §5.4 forbids an unallocated Customer balance.

The Customer wallet adds one Customer-level balance that sits between the plans and the outside world:

1. **Holding.** Savings leave a plan by moving into the wallet. This includes partial withdrawals, full withdrawals and end-of-cycle closure.
2. **Payout.** Every external Customer payout (cash, bank transfer, offline) is paid from the wallet and only from the wallet.
3. **Contribution.** The wallet is a contribution method alongside cash, transfer, POS and other. An Agent can fund a plan contribution from the Customer's wallet.

The product owner confirmed these decisions on 19 November 2026:

| Decision                      | Outcome                                                                                                                                                   |
| ----------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Payout route                  | All plan exits (partial, full, end-of-cycle) move money into the wallet. External payouts are paid only from the wallet.                                  |
| Wallet funding                | Money enters the wallet only from plan transfers in v1. There are no direct cash or transfer top-ups.                                                     |
| Wallet contribution authority | The assigned Agent records it as a collection method. It posts immediately with no Admin approval. Module 09 reversal applies.                            |
| Transfer review               | A plan→wallet transfer posts on Agent confirmation with no Admin review. Admin control sits at the wallet payout, where money leaves the business.        |
| Fee placement                 | Plan fees settle when money moves from the plan into the wallet, using the plan's immutable fee snapshot. Wallet payouts carry no fee or deduction in v1. |
| End-of-cycle closure          | An End-of-cycle transfer does not close the plan. The Agent closes it through the separate Module 06 plan action once every settlement gate passes.       |
| Automatic transfer            | When a plan becomes Completed, the system moves all its savings into the wallet automatically as an End-of-cycle transfer (§6.4).                         |
| Other defaults                | The §18 defaults are accepted: same-plan refunding is allowed, one live wallet payout per Customer, and no wallet expiry, dormancy fee or interest.       |

Everything else in this document is **proposed for review**, including the cutover rule (§11), the reversal rules (§10) and the automatic-transfer retry and alert timings (§6.4).

## 2. Initial scope, exclusions, and ownership

### 2.1 Initial scope

- One wallet per Customer, in NGN, stored as integer kobo and derived from the ledger. It is never a stored balance column.
- A plan→wallet transfer of partial, full or end-of-cycle type, using Module 08's existing quote, fee and closure rules.
- An automatic End-of-cycle transfer when a plan becomes Completed.
- A wallet payout through the existing Module 08 approval, reservation, expiry, hold and rail (cash, bank transfer, offline) machinery.
- A wallet-funded plan contribution recorded through Module 07's collection screen as the method **Wallet**.
- Reversal of each of the three wallet events through Module 09.
- A wallet view for the Customer, the assigned Agent and Admins. Wallet lines on statements, reports and the dashboard. Notifications and audit.

### 2.2 Deferred and prohibited scope

Customer self-service actions, direct wallet top-up by cash, transfer, POS or card, transfers between Customers, interest or returns on wallet balances, overdrafts or negative balances, wallet fees, scheduled or automatic wallet debits, automatic plan closure, multiple currencies, multiple wallets per Customer, dormancy or escheat processing, and splitting a payout across rails are deferred.

The wallet is not a new custody account. It does not hold physical cash or a bank balance, and it never stands in for Agent receivable, business cash, fee earnings, unapplied funds or refund payables. No role may use the wallet to record a fee-earnings draw, an Agent remittance, a fee refund or a free-form ledger adjustment.

### 2.3 Ownership matrix

| Owner             | Authoritative responsibility                                                                                                                                    |
| ----------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| This module       | Wallet terms, wallet liability and availability contract, plan→wallet transfer, wallet payout source rules, wallet contribution method rules, and wallet views. |
| Module 04         | Customer operational status, current assignment, Agent eligibility, and archival gates (extended by §4.3).                                                      |
| Module 05         | Fee snapshot, withdrawal and completion fee quotes, and collection fee components. They apply at the plan→wallet transfer and at wallet contribution.           |
| Module 06         | Plan state, cycle-attributed liability, closure gates, and early-termination preparation.                                                                       |
| Module 07         | Collection receipt, allocation, batch and fee-component recording for a wallet contribution.                                                                    |
| Module 08         | Request terms, reservations, review, expiry, holds and execution rails. The wallet changes only the source and permitted methods.                               |
| Module 09         | Linked compensation of posted wallet events.                                                                                                                    |
| Module 10         | Account catalogue, balanced posting, balance contracts, statements and integrity.                                                                               |
| Modules 11–13, 15 | Metrics, reports, notifications, feature flags and readiness.                                                                                                   |

## 3. Terms, amount contract, and invariants

- **Wallet liability (W):** money the business owes the Customer that is no longer attributed to any plan. It equals wallet credits minus wallet debits in the Customer's wallet-liability subsidiary.
- **Live wallet reservation (RW):** the gross claim of one unposted wallet payout request.
- **Wallet available (AW):** `W − sum(RW)`. Never subtract a reservation from W itself.
- **Plan savings liability (L) and plan availability:** unchanged from Modules 08 and 10. They exclude wallet entries and wallet reservations.
- **Total Customer liability:** `L + W`. Reports and statements show L and W separately and as a total.
- **Plan→wallet transfer:** gross plan debit `G`, fee `F`, deduction `D`, and wallet credit `P = G − F − D`. P must be positive.
- **Wallet payout:** gross wallet debit `G = P`. In v1, `F = 0` and `D = 0`.
- **Wallet contribution:** wallet tender `T`, plan savings credit `S` and collection fee `Fc`, where `T = S + Fc`.

Invariants:

1. W and AW are never negative. Every command that debits the wallet locks the Customer and checks AW under that lock.
2. Every wallet movement is a balanced, immutable Module 10 posting group linked to exactly one source record. No wallet balance column, cache or snapshot is authoritative.
3. Plan-attributed savings and the wallet are separate ledger accounts. A plan's cycle balances still add up to L exactly, so Module 08's per-plan attribution check is unchanged.
4. Money leaves the business to a Customer only by a wallet payout. A plan never pays a Customer directly once the wallet is enabled (see §11 for requests already in flight).
5. A wallet credit comes only from a plan→wallet transfer (Agent-initiated or automatic on completion) or from a Module 09 compensation that restores a previously debited wallet amount.
6. Fees are taken once. The plan fee snapshot settles at transfer, the collection fee at contribution, and nothing at wallet payout.

## 4. Authority, authentication, and eligibility

### 4.1 Closed-role action matrix

| Action                                    | Customer   | Current eligible assigned Agent       | Admin                                                           |
| ----------------------------------------- | ---------- | ------------------------------------- | --------------------------------------------------------------- |
| View wallet balance and history           | Own wallet | Currently assigned Customers          | Business-wide read                                              |
| Transfer plan savings to the wallet       | No         | Yes, subject to §6.1 gates            | No                                                              |
| Request a wallet payout                   | No         | Yes                                   | No                                                              |
| Automatic transfer on plan completion     | No         | No (system action; Agent sees status) | No                                                              |
| Cancel a pending wallet payout            | No         | Yes, under Module 08 §10              | No                                                              |
| Approve, reject or revoke a wallet payout | No         | No                                    | One fresh-authenticated Admin with `withdrawals.review`         |
| Execute a payout rail                     | No         | No                                    | Unchanged from Module 08 (`withdrawals.review`, `cash.execute`) |
| Record a wallet-funded contribution       | No         | Yes, as a collection                  | No                                                              |
| Initiate a reversal of a wallet event     | No         | Yes, through Module 09                | Approve with `reversals.review`                                 |

No new Admin permission is introduced. `withdrawals.review` does not grant contribution or transfer authority, and recording a collection does not grant payout authority.

### 4.2 Agent eligibility

Every Agent action requires the Module 04 `PerformAssignedCustomerWork` capability, a current assignment, and the server re-checking the assignment version at commit. This is the same rule as for collections and withdrawals.

### 4.3 Customer operational-status matrix

| Operation                         | Active    | Inactive  | Restricted                                         | Archived      |
| --------------------------------- | --------- | --------- | -------------------------------------------------- | ------------- |
| View wallet                       | Allowed   | Allowed   | Allowed                                            | Allowed       |
| Plan→wallet transfer              | Allowed   | Allowed   | Blocked                                            | Blocked       |
| Automatic transfer on completion  | Allowed   | Allowed   | Pending until the restriction is lifted            | Not reachable |
| Wallet payout request and posting | Allowed   | Allowed   | Blocked; reject allowed; hold overlay as Module 08 | Blocked       |
| Wallet contribution               | Allowed   | Blocked   | Blocked                                            | Blocked       |
| Reversal of a wallet event        | Module 09 | Module 09 | Module 09 corrective exception                     | Restore first |

Inactive Customers may settle existing funds (`allowsWithdrawalOfExistingFunds`) but cannot make new contributions (`canTransact`). **Archival** additionally requires `W = 0` and no live wallet reservation.

## 5. Wallet balance contract

`W(C,T)` = credits minus debits on the `customer_wallet_liability_ngn` account for Customer C, counting groups committed by cutoff T.

`AW(C,T) = W(C,T) − sum(live wallet-source reservations for C)`.

When the wallet account is unmapped, unbalanced, or disagrees with the posting source records, the wallet shows **Unavailable**, never zero. Every wallet-debiting command fails with a 503 and an explicit owner message, matching Module 08's unavailable-owner rule. Plan savings reads keep working when the wallet is unavailable, and wallet reads keep working when a plan source is unavailable.

## 6. Flows

### 6.1 Plan→wallet transfer

1. The Agent opens **Move to wallet** on a plan, chooses Partial, Full or End-of-cycle, and enters G for a Partial transfer.
2. The server quotes with the existing Module 08 preview: cycle availability, fee F from the plan's fee snapshot, deduction D, and `P = G − F − D > 0`. The quote has a fingerprint and expires after the existing ten-minute window.
3. The Agent confirms. In one financial transaction, the server:
    - re-runs the preview under lock and compares the fingerprint and versions;
    - creates a withdrawal record with source **Plan** and method **Wallet**, and creates and immediately consumes its plan reservation;
    - posts **Dr plan savings liability G / Cr wallet liability P / Cr fee income F / Cr other deduction D**;
    - settles the fee obligation;
    - sets the record to Posted;
    - runs the existing full-withdrawal closure path (`early_termination_prepared` → Paused → settlement gates → Closed, or `closure_pending_at` with retry), exactly as for a posted Full withdrawal today;
    - writes audit and notification outbox records.
4. No Admin review is required, because no money leaves the business and the fee is fixed by the agreed snapshot. _(Confirmed by the product owner, 19 November 2026.)_

Partial, Full and End-of-cycle keep their Module 08 §5 meanings. An End-of-cycle transfer still needs a Completed plan. Closure after an End-of-cycle transfer remains the separate Module 06 plan action (confirmed by the product owner, 19 November 2026).

### 6.2 Wallet payout

1. The Agent opens **Pay out from wallet**, enters G ≤ AW, and chooses an enabled rail and a verified destination (bank) or recipient (cash), using the same rules as Module 08 §11.
2. Submission creates a withdrawal request with source **Wallet**, a live wallet reservation G and state Pending review. There is one live wallet payout per Customer.
3. One Admin approves or rejects under fresh authentication. Expiry, holds, cancellation and revocation are unchanged from Module 08.
4. The rail executes exactly as today. On definitive success it posts **Dr wallet liability G / Cr rail payout account G** (business cash, payout clearing or business bank) and consumes the reservation.
5. Unknown outcomes, failures, returns and conflicts follow Module 08 §12 unchanged. A return restores W through Module 09.

### 6.3 Wallet-funded contribution

1. On the collection screen the Agent chooses method **Wallet**. The screen shows AW, and T must be ≤ AW.
2. Module 07 computes allocations and the collection fee components as for any method (T = S + Fc).
3. In one financial transaction, the server locks the Customer, re-checks AW and posts **Dr wallet liability T / Cr plan savings liability S (plan-dimensioned) / Cr fee income Fc**. It writes the receipt and allocations, updates plan completion and the completion fee, and writes audit and notification records.
4. The receipt joins the Agent's per-day **Wallet** batch. This batch has no physical custody, so it creates no remittance, settlement or cash-shortage exposure, and it is reconciled by construction.
5. No Admin approval. Corrections use Module 09. The cash-only "partly collected" correction does not apply.

### 6.4 Automatic transfer on plan completion

When a plan becomes Completed (every active slot exactly funded, Module 07 §7.2), the system moves its savings into the wallet without an Agent action.

1. The completion transaction records one **sweep** for that completion event. It moves no money itself, so a receipt never fails because the sweep cannot run.
2. After the completion commits, a queued job runs the sweep. In one financial transaction it locks the Customer and plan, then checks that:
    - the `wallet` feature is enabled and the wallet account is mapped;
    - the plan is still Completed, and its completion event is the one the sweep was recorded for;
    - the Customer is Active or Inactive;
    - no other plan-source request on the plan is live;
    - a valid End-of-cycle quote exists (Module 05 completion fee included once) with `P > 0`.
3. When every check passes, it posts exactly as an Agent End-of-cycle transfer (§6.1): G = the plan's whole available savings, F from the fee snapshot, and P credited to the wallet. The transfer records the system as executor and the currently assigned Agent for attribution. The sweep becomes **Posted**.
4. When a check fails, nothing posts. The sweep becomes **Pending** with a named blocker (for example `customer_restricted`, `fee_unsettled`, `wallet_unavailable`, `live_request`). A scheduled command retries pending sweeps every five minutes. A sweep still pending after 24 hours notifies the assigned Agent and appears on the Admin exceptions view.
5. When the plan leaves Completed before the sweep posts (for example, a receipt reversal un-funds it), the sweep becomes **Superseded** and does nothing. A later completion records a new sweep.
6. The plan stays Completed with zero savings after the sweep. Closure remains the Agent's Module 06 action (§1).
7. The Agent can still run a manual End-of-cycle transfer while a sweep is pending, for example after resolving the blocker. Both paths take the same locks. Whichever posts first moves the money; the other finds nothing available and finishes as Posted (sweep) or with a "nothing to transfer" message (Agent).
8. Only completions that happen after the `wallet` feature is activated get a sweep. Plans that were already Completed before activation need the Agent's manual End-of-cycle transfer.

A receipt reversal on a plan that has already been swept needs the sweep's transfer reversed first, under the §10 wallet-availability gate.

## 7. Wallet-source request lifecycle

Wallet payouts reuse Module 08's states: `pending_review`, `approved`, `payout_processing`, `outcome_unknown`, `payment_failed`, `posted`, `rejected`, `cancelled`, `expired`, and the hold overlay. A plan→wallet transfer appears only as `posted`, because it is created and posted in one transaction. A transfer that fails validation leaves no record or reservation behind.

## 8. Screens and user experience

- **Customer wallet page** (Agent, Admin and Customer scopes): shows W, live reservations, AW, an "as of" UTC time, and paginated wallet history (transfer in, contribution out, payout out, compensation), each with its reference and source plan.
- **Plan detail:** the withdrawal action becomes **Move to wallet**. The quote shows G, F, D and the wallet credit P. A full transfer explains that the plan will close. A Completed plan shows its automatic transfer status (Posted, Pending with the blocker in plain words, or Superseded).
- **Collection create:** **Wallet** appears as a method when it is enabled. It shows AW and blocks amounts above it. No evidence upload is needed.
- **Wallet payout create:** the same layout as today's withdrawal create, with the source fixed to the wallet and no fee row.
- **Admin review queue:** wallet payouts carry a **Wallet** source badge. Review evidence shows W, RW and AW instead of plan balances.
- Pages follow `.ai/rules/pages.md` (breadcrumbs, header classes, filters) and DESIGN.md. Every amount uses the shared money formatter. An unavailable wallet renders as "Unavailable", never ₦0.

## 9. Notifications

All notifications are queued (`.ai/rules/notifications.md`) and written as outbox intents in the posting transaction.

| Event                                        | Customer     | Assigned Agent | Admin                 |
| -------------------------------------------- | ------------ | -------------- | --------------------- |
| Plan→wallet transfer posted                  | Yes          | Yes            | No                    |
| Automatic transfer pending over 24 hours     | No           | Yes            | Exceptions view       |
| Wallet contribution posted                   | Yes          | Yes            | No                    |
| Wallet payout lifecycle (submitted → posted) | As Module 08 | As Module 08   | As Module 08          |
| Wallet event reversed                        | Yes          | Yes            | Reviewer as Module 09 |

Messages show masked references and amounts only. They never include destinations, balances of other Customers or private reasons.

## 10. Reversals and corrections

| Original event       | Compensation (new linked group)                                              | Extra gate                                                                                                                  |
| -------------------- | ---------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| Plan→wallet transfer | Dr wallet P / Dr fee income F / Dr other deduction D / Cr plan savings G     | AW ≥ P at approval, otherwise Blocked; never negative. A closed plan follows Module 09's closed-cycle correction exception. |
| Wallet contribution  | Dr plan savings S / Dr fee income Fc / Cr wallet T; release allocations      | Same allocation-release and plan-completion rules as `CollectionReversalOwner`.                                             |
| Wallet payout        | Existing bank-return and cash-recovery contracts, restoring wallet liability | Unchanged Module 08 and 09 gates.                                                                                           |

Originals are never edited. A reversal that would make W, AW or plan availability negative stays Blocked until the dependency is resolved.

## 11. Enablement and cutover

- Two default-off feature codes: **wallet** (transfers and payouts) and **collection_wallet** (wallet contribution method). Each needs readiness evidence, the finance-signed wallet account mapping and the Module 15 publish flow.
- While **wallet** is enabled, a plan-source request accepts only the Wallet method. Cash, bank and offline are offered only for wallet-source requests.
- Plan-source requests already live on an external rail when the flag turns on finish on that rail. They are not migrated or cancelled automatically.
- Every Customer starts with W = 0. No balance migration is needed.
- Turning **wallet** off blocks new transfers and wallet payouts. Existing wallet balances stay visible, and live wallet payouts continue to finish.

## 12. Audit and financial evidence

Each wallet event records the actor, assignment version, Customer version, plan version (where relevant), quote fingerprint, posting reference, idempotency key, and pre/post W, AW, L and cycle availability. Audit families: `wallet_transfer`, `wallet_payout` (extends `withdrawal`), `wallet_contribution` (extends `collection`). Records are append-only and retained under Module 14.

## 13. Non-functional requirements

- Exactly-once posting under retries and concurrent requests. A replay of the same attempt reference returns the original result.
- One serialization point: the Customer row lock. Two wallet debits for the same Customer cannot both pass the AW check.
- Wallet position reads take bounded queries, with no N+1 across history pages.
- Integrity: the platform integrity check fails closed when the sum of wallet posting groups disagrees with W or when a wallet reservation has no live request.
- Accessibility and 390 px layout as for other financial pages.

## 14. Worked examples

### 14.1 Completion into the wallet, then bank payout

A ₦2,000 × 31 plan completes with ₦62,000 posted. The completion fee is ₦2,000. The automatic transfer on completion posts G ₦62,000 / F ₦2,000 / P ₦60,000: plan L falls by ₦62,000, W rises to ₦60,000 and fee income rises by ₦2,000. The Agent closes the plan, which now has zero liability. The Agent requests a ₦60,000 bank payout: AW becomes ₦0 while it is live. The Admin approves, the rail succeeds, and W becomes ₦0. Total liability moved from ₦62,000 to ₦0, with `62,000 = 60,000 + 2,000`.

### 14.2 Wallet-funded contribution

W = ₦60,000. The Agent records a ₦10,000 Wallet contribution to a new plan whose collection fee is ₦0 per receipt. Posting: Dr wallet ₦10,000 / Cr plan savings ₦10,000. W = ₦50,000, the new plan's L = ₦10,000, and total liability stays ₦60,000.

### 14.3 Competing payout and contribution

W = ₦50,000. At the same moment the Agent submits a ₦40,000 wallet payout and records a ₦20,000 wallet contribution. Both take the Customer lock. Whichever commits second sees the reduced AW (₦10,000 after the payout, or ₦30,000 after the contribution) and is rejected with no record, reservation or posting.

### 14.4 Reversal blocked by spent wallet

A ₦30,000 partial transfer (F = 0) was posted in error, so W = ₦30,000. A ₦30,000 wallet payout then posts, so W = ₦0. A reversal of the transfer needs AW ≥ ₦30,000, so it is Blocked. The payout must be returned and compensated first, or the business must resolve the case through a separate approved correction.

## 15. Release gates

1. Finance signs the wallet account mapping (class, normal balance, customer dimension) and the three posting patterns.
2. Operations owns the pending-sweep alert and the Admin exceptions view before the `wallet` feature is activated.
3. The Module 08 rails used for wallet payouts each keep their own existing release gates. Enabling the wallet does not enable any rail.
4. Isolated MySQL concurrency proof for §14.3, for reversal racing a payout, and for the sweep racing a manual transfer.
5. Readiness evidence recorded in `FinancialReleaseEvidenceService` before either feature code can be activated.

## 16. Indexed functional requirements

| ID         | Requirement                                                                                                                                                             | Detail           |
| ---------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------- |
| WAL-FR-001 | Maintain one NGN wallet per Customer as a separate ledger liability, derived from postings and never stored.                                                            | Sections 3, 5    |
| WAL-FR-002 | Compute AW = W − live wallet reservations. Keep plan savings and wallet availability independent, and never let either go negative.                                     | Sections 3, 5    |
| WAL-FR-003 | Return Unavailable (503 for writes) when the wallet owner, mapping or integrity fails, without hiding plan data.                                                        | Section 5        |
| WAL-FR-004 | Transfer Partial, Full and End-of-cycle plan savings to the wallet using Module 08 quote and Module 05 fees, posting G = P + F + D atomically.                          | Section 6.1      |
| WAL-FR-005 | Run the existing closure path after a Full transfer, and keep End-of-cycle closure as the Module 06 action.                                                             | Section 6.1      |
| WAL-FR-006 | Allow external Customer payouts only from wallet-source requests while the wallet is enabled. Restrict plan-source requests to the Wallet method.                       | Sections 6.2, 11 |
| WAL-FR-007 | Process wallet payouts through Module 08 reservation, single-Admin review, expiry, holds and rails, with F = D = 0 and one live wallet payout per Customer.             | Section 6.2      |
| WAL-FR-008 | Record wallet-funded contributions as the Wallet collection method, with T ≤ AW checked under the Customer lock and T = S + Fc posted atomically.                       | Section 6.3      |
| WAL-FR-009 | Place wallet receipts in a per-Agent, per-day Wallet batch with no custody, remittance or settlement exposure.                                                          | Section 6.3      |
| WAL-FR-010 | Enforce the role matrix and customer-status matrix, including the archival gate W = 0 with no live wallet reservation.                                                  | Section 4        |
| WAL-FR-011 | Reverse each wallet event through Module 09 with linked compensation and the AW gate.                                                                                   | Section 10       |
| WAL-FR-012 | Provide scoped wallet views and add wallet lines to statements, reports and dashboard metrics.                                                                          | Sections 8, 12   |
| WAL-FR-013 | Send queued notifications and append-only audit for every wallet event.                                                                                                 | Sections 9, 12   |
| WAL-FR-014 | Gate wallet features behind default-off feature codes with readiness, and finish in-flight plan payouts on their original rail at cutover.                              | Section 11       |
| WAL-FR-015 | Make every wallet command idempotent by attempt reference and posting key, and serialize wallet debits on the Customer lock.                                            | Section 13       |
| WAL-FR-016 | Automatically transfer a Completed plan's savings to the wallet once per completion event, retrying blocked sweeps, superseding stale ones and alerting after 24 hours. | Section 6.4      |

## 17. Acceptance scenarios and traceability

These are future release scenarios, not claims of implementation.

| ID         | Requirements    | Scenario and expected result                                                                                                                                                                                     |
| ---------- | --------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| WAL-AC-001 | WAL-FR-001, 002 | After transfers, contributions and payouts, W and AW equal the sums from ledger entries and live reservations. Plan cycle balances still add up to L.                                                            |
| WAL-AC-002 | WAL-FR-003      | Unmapped wallet account: the wallet view shows Unavailable, wallet writes return 503 with no record, and plan detail still renders.                                                                              |
| WAL-AC-003 | WAL-FR-004      | Partial transfer with a percentage fee posts the exact G, F, D and P; a stale fingerprint or changed plan version is rejected without posting.                                                                   |
| WAL-AC-004 | WAL-FR-004, 005 | Full transfer of an Active plan closes it, or leaves it closure-pending with retry when a gate blocks. End-of-cycle requires a Completed plan.                                                                   |
| WAL-AC-005 | WAL-FR-006      | With the wallet enabled, a plan-source cash or bank request is rejected. A wallet-source request offers only enabled rails.                                                                                      |
| WAL-AC-006 | WAL-FR-007      | Wallet payout approve → bank success posts Dr wallet / Cr payout clearing and consumes the reservation. Reject, cancel and expire release it. A second live wallet payout is refused.                            |
| WAL-AC-007 | WAL-FR-008      | Wallet contribution above AW is rejected. An exact-AW contribution posts T = S + Fc and completes the plan when every slot is funded.                                                                            |
| WAL-AC-008 | WAL-FR-009      | The wallet batch shows zero expected custody, no remittance prompt, and no cash-shortage exception.                                                                                                              |
| WAL-AC-009 | WAL-FR-010      | Inactive Customer: transfer and payout allowed, contribution refused. Restricted: blocked except reject. Archival blocked while W > 0.                                                                           |
| WAL-AC-010 | WAL-FR-011      | Transfer reversal posts the exact compensation when AW ≥ P, and is Blocked after the wallet is spent. Contribution reversal releases the allocations.                                                            |
| WAL-AC-011 | WAL-FR-012      | Customer, assigned Agent and Admin each see only their scoped wallet. A foreign Agent gets 403 or 404. Statement, report and dashboard wallet totals match the ledger.                                           |
| WAL-AC-012 | WAL-FR-013      | Each event writes one audit record and queued notification intents in the posting transaction. A rolled-back post writes neither.                                                                                |
| WAL-AC-013 | WAL-FR-014      | A live plan-source bank request posts on its rail after the flag turns on. Turning the flag off blocks new wallet actions and keeps balances visible.                                                            |
| WAL-AC-014 | WAL-FR-015      | Replaying an attempt reference returns the original record. A concurrent payout and contribution (MySQL) leave exactly one committed and AW ≥ 0.                                                                 |
| WAL-AC-015 | WAL-FR-011, 015 | A transfer reversal racing a wallet payout (MySQL) never takes AW below zero.                                                                                                                                    |
| WAL-AC-016 | WAL-FR-016      | The receipt that completes a plan commits, and the queued sweep posts G, F and P exactly once. A replayed job or a concurrent manual End-of-cycle transfer posts nothing extra.                                  |
| WAL-AC-017 | WAL-FR-016      | A Restricted Customer's sweep stays Pending with `customer_restricted`, alerts after 24 hours, and posts on the next retry after the restriction lifts. A receipt reversal before the sweep marks it Superseded. |

## 18. Confirmed defaults

The product owner accepted these defaults on 19 November 2026:

- A wallet contribution may fund the same plan that a partial transfer just left. Both fees apply and no special rule is added.
- One live wallet payout per Customer at a time.
- Wallet balances have no expiry, dormancy fee or interest in v1.
- The automatic-sweep default was **not** accepted. A Completed plan's savings move to the wallet automatically (§6.4).
