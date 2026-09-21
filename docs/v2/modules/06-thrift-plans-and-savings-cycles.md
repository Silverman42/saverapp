# Thrift Plans and Savings Cycles

**Product version:** 2.0  
**Module:** 06  
**Module status:** Detailed draft for review  
**Sources:** [Version 1 PRD](../../PRD.md), [User Types](./01-user-types.md), [Authentication](./02-authentication.md), [Roles and Permissions](./03-roles-and-permissions.md), [Customer and Agent Management](./04-customer-and-agent-management.md)  
**Financial dependencies:** [Fees and Deductions](./05-fees-and-deductions.md), [Collections, Digital Thrift Card, and Reconciliation](./07-collections-thrift-card-and-reconciliation.md); forthcoming Withdrawals, Reversals, Reporting, and Business Configuration modules

## 1. Purpose and decision status

A thrift plan defines a Customer's agreed contribution amount, contribution schedule, cycle duration, and fee terms. This module specifies creation, safe amendment, pauses, progress, completion, settlement gates, closure, cancellation, and renewal. Plans describe expected activity; financial owners record actual money and determine liabilities.

The PRD establishes daily contribution plans, one active daily plan per Customer by default, multiple historical cycles, digital thrift cards, contribution-count completion, and renewal using the existing Customer identity. Modules 01–03 establish Agent-only plan creation and management, Customer own-record read access, Admin business-wide read access, current-assignment enforcement, and immutable financial history. Module 04's Customer/Agent lifecycle and eligibility rules remain draft dependencies, not new confirmed decisions here.

Unless already established by those sources, the detailed policies below are **proposed for review**. In particular, the daily-only initial scope, field limits, one-open-cycle invariant, dates, state machine, term-locking policy, early closure, and renewal gates require product approval before implementation. A numbered requirement expresses intended behaviour, not evidence that the feature exists or a decision has been approved. An unresolved owner contract blocks dependent release; it does not authorize a guessed default.

## 2. Scope and ownership

### 2.1 Initial scope

- One configured business; NGN savings plans, money represented as integer kobo.
- Individual daily plans with a fixed contribution amount and a finite number of contribution slots.
- Current assigned-Agent creation/management, preview and confirmation, versioned pre-activity corrections, explicit pause/resume, contribution progress, completion, settled closure, zero-activity cancellation, and renewal.
- Read-only plans and historical terms for Customers, currently assigned Agents, and Admins within their defined scope.
- Schedule contracts, fee snapshot links, financial gate queries, notifications, audit, concurrency controls, failure handling, and accessible responsive screens.

### 2.2 Deferred scope

Weekly/monthly/custom frequencies, selected collection weekdays, holiday calendars, multiple simultaneous daily plans, variable contribution amounts, interest, investment returns, joint plans, cross-Customer transfers, multi-currency, bulk creation, scheduled automatic renewal, offline plan mutations, imported historical plans, and deleted/merged cycles are deferred. Do not expose them as supported options or accept them through an API. The PRD's “unless otherwise configured” concurrency exception requires a future explicit configuration and migration specification; there is no initial silent override.

### 2.3 Owning boundaries

| Owner                   | Authoritative responsibility                                                                                                                                                             |
| ----------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| This module             | Agreed plan terms/revisions, stable dated slots, lifecycle, lineage, progress evaluation, and validated closure/cancellation gates.                                                      |
| Module 04               | Customer identity/status history, effective assignment, Agent availability and account/operational eligibility.                                                                          |
| Module 05               | Fee rules, applicability, immutable snapshots, fee basis/timing/rounding, obligations, recognition, payment/application, waivers/refunds and fee earnings.                               |
| Module 07/shared ledger | Contribution posting and allocation, receipt count, actual thrift-card states, balanced immutable financial entries, liability/available-savings outputs, Agent cash and reconciliation. |
| Withdrawals/Reversals   | Reservation, request approval, payout, approved corrective counter-entries, and their settlement states.                                                                                 |
| Business Configuration  | Authoritative business timezone and supported plan policy configuration; financial changes use the relevant existing Admin permissions.                                                  |
| Reporting/Audit         | Historical reports/statements, retention, masking, export and privileged audit access.                                                                                                   |

An Agent selecting an available fee option is not configuring a fee rule. Admin permissions such as `customers.manage`, `fees.manage`, or `business.settings.manage` never confer Agent plan-management capability. There is no `plans.manage` permission in the closed catalogue.

## 3. Terminology and invariant rules

- **Cycle:** one plan record with its own identity, agreed terms, slots, fees and history. Renewal creates another cycle linked to the predecessor; it never resets the predecessor.
- **Contribution slot:** one expected contribution unit with a stable identifier, ordinal Day 1…Day N, agreed amount, and local due date. A slot is not a receipt or a calendar-month boundary.
- **Scheduled end date:** due date of slot N, derived from the schedule. It is a projection, not a guaranteed payout date or proof of completion.
- **Net funded slot:** Module 07 confirms live allocated principal equals the slot's agreed amount after approved counter-entries. Fee entries are not contribution allocations.
- **Completed:** all slots net funded. Financial settlement can still be outstanding.
- **Closed:** no further normal activity, with authoritative financial/operational settlement gates passed; may represent normal completion or explicitly documented early termination.
- **Cancelled:** a retained zero-activity cycle terminated before financial/operational obligations were created.
- **Open cycle:** Active, Paused, or Completed. These all occupy the Customer's one daily-plan capacity until Closed or Cancelled.

Maintain at most one open daily cycle per Customer, enforced atomically for every creation, renewal, retry, or transition. Pausing cannot create capacity for another plan. A past scheduled end date or full withdrawal cannot release capacity. Plan names may repeat; immutable IDs and cycle lineage distinguish them. No lifecycle action changes a Customer ID, assignment, status, registration-fee snapshot, or historical financial attribution.

## 4. Plan fields and validation

| Field                       | Entry / source                                        | Validation and meaning                                                                                                                                                                                                   |
| --------------------------- | ----------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Customer                    | Required selection                                    | Existing currently assigned Customer; operationally Active for creation/renewal. Recheck at commit. Invited Customer login state does not independently block eligible Agent operations.                                 |
| Plan name                   | Required Agent input                                  | Plain text, trimmed, 1–100 Unicode characters; no markup execution. Not a unique key.                                                                                                                                    |
| Contribution amount         | Required Agent input                                  | Positive NGN amount with at most two decimal places; stored as integer kobo. Proposed range ₦1.00–₦10,000,000.00; reject extra precision rather than silently round. Any lower business cap must be shown and versioned. |
| Currency                    | System supplied                                       | NGN initial scope; immutable after creation. Never infer a conversion.                                                                                                                                                   |
| Start date                  | Required Agent input                                  | Valid local calendar date; today or a future date, proposed maximum 365 calendar days ahead. Backdated creation deferred.                                                                                                |
| Number of contribution days | Required Agent input                                  | Integer 1–366 inclusive; count of slots, not month duration or receipt count. Reject zero, fractions and overflow.                                                                                                       |
| Collection frequency        | Required fixed choice                                 | Daily only initially; every local calendar day including weekends, no automatic holiday exclusion. Unsupported frequencies rejected.                                                                                     |
| Timezone                    | Business source, snapshotted                          | Required valid IANA timezone; initial recommended business value `Africa/Lagos`. UTC timestamps remain separate. No browser-local timezone inference.                                                                    |
| Scheduled end date          | System calculated                                     | Start date plus N−1 calendar days in snapshotted timezone. Read-only. Optional PRD expected date is implemented by this authoritative calculated field initially.                                                        |
| Fee option                  | Required applicable option                            | Current version from Module 05, including explicit no-fee option when authorized by its policy. No Agent-entered fee amount/rate/timing override.                                                                        |
| Fee snapshot                | System reference                                      | Immutable rule/version, currency, model, basis, contribution-unit amount, timing, rounding and applicability evidence from Module 05. Retain original and successor references on a permitted pre-activity revision.     |
| Notes                       | Optional Agent input                                  | Plain text, maximum 2,000 characters. Explicitly Customer-visible service notes; do not copy private Module 04 notes or investigation reasons here.                                                                      |
| Lifecycle reason            | Required on amendment/pause/resume/cancel/early close | Trimmed plain text 1–500 characters, with separate Customer-facing explanation if the internal reason contains sensitive information.                                                                                    |
| Public plan ID              | System generated                                      | Proposed `PLN-000001` style immutable reference, uniqueness business-wide; internal ID separate. Gaps allowed; never reused.                                                                                             |
| Revision / row version      | System maintained                                     | Monotonically increasing terms revision and mutation concurrency version; clients cannot overwrite.                                                                                                                      |
| Creator / effective Agent   | Trusted sources                                       | Immutable creator reference; current responsible Agent derived from current Customer assignment, never copied as durable access authority.                                                                               |
| Predecessor / successor     | System maintained                                     | Renewal lineage; at most one initial successor per predecessor under this initial scope. Not an unrestricted Agent-edited relation.                                                                                      |
| Timestamps and intervals    | System generated                                      | UTC creation/update/action timestamps, actor references, schedule revision, pause intervals and completion/closure/cancellation evidence.                                                                                |

Validation runs on the server as well as the form. Normalize display input without stripping legitimate names; return safe field-specific errors. Reject unknown/protected fields and mismatched Customer/plan IDs. Financial summaries and actual slot states are read-only owner outputs. Calculate expected gross with checked integer arithmetic; never use binary floating point for stored money.

## 5. Authority and eligibility

### 5.1 Role matrix

| Operation                              | Customer | Current eligible assigned Agent | Active Admin                    |
| -------------------------------------- | -------- | ------------------------------- | ------------------------------- |
| View permitted plan/terms/card/history | Own only | Assigned Customers              | Business-wide baseline read     |
| Create/renew/amend plan                | No       | Subject to Customer/state/gates | No                              |
| Pause/resume/cancel/close              | No       | Subject to lifecycle rules      | No                              |
| Record contribution                    | No       | Module 07 only                  | No                              |
| Initiate withdrawal/reversal           | No       | Owning workflow only            | No Agent-side initiation        |
| Approve payout/reversal or manage fees | No       | No                              | Owning permission/workflow only |

An Agent must have completed activation/MFA, operational status Active, a currently permitted Authentication session, current effective assignment, and applicable domain eligibility. Preserve Module 04's temporary-lock exception for an otherwise legitimate existing session; it is not equivalent to suspension. An Inactive Agent may retain permitted reads but cannot manage plans. Suspended/Deactivated access follows Authentication.

### 5.2 Customer status matrix

| Plan action                                 | Active                         | Inactive                        | Restricted                                                                        | Archived                      |
| ------------------------------------------- | ------------------------------ | ------------------------------- | --------------------------------------------------------------------------------- | ----------------------------- |
| Read own/permitted records                  | Allowed                        | Allowed                         | Allowed                                                                           | Allowed                       |
| Create/renew/term amendment                 | Eligible Agent                 | Blocked                         | Blocked                                                                           | Blocked                       |
| Explicit pause                              | Eligible Agent                 | Eligible Agent; preserves pause | Eligible Agent; no financial effect                                               | Blocked                       |
| Resume collections                          | Eligible Agent and valid terms | Blocked                         | Blocked                                                                           | Blocked                       |
| New contribution or catch-up/advance        | Module 07 eligible plan        | Blocked                         | Blocked                                                                           | Blocked                       |
| Completion evaluation from existing actuals | System read/evaluation         | System read/evaluation          | System read/evaluation                                                            | Historical read only          |
| Settlement/payout                           | Owning eligible workflow       | Owning eligible workflow        | Blocked by hold                                                                   | Blocked                       |
| Normal or early closure                     | Settled gates                  | Settled gates                   | Only non-financial closure with all gates independently satisfied; no hold bypass | Already terminal              |
| Zero-activity cancellation                  | Eligible Agent and gates       | Eligible Agent and gates        | Non-financial only with zero obligations                                          | Already terminal              |
| Corrective reversal                         | Owning review                  | Owning review                   | Owning review; separate authority                                                 | Restore first under Module 04 |

Restricted status must not block correction of an erroneous financial record, but correction still requires the owning review workflow. Non-financial closure/cancellation under a restriction is proposed only where all owner gates independently certify zero obligations and no prohibited financial posting is required. An unavailable owner result blocks it. Customer restoration does not reopen a cycle or permit new activity until status and Agent eligibility explicitly allow it.

## 6. Creation and confirmation

1. Eligible Agent opens creation from the assigned Customer's profile or scoped plan directory.
2. Load current Customer/assignment/eligibility, existing open-cycle gate, timezone/configuration and applicable Module 05 options. Unknown prerequisites block confirmation.
3. Validate input and generate the proposed stable ordinal/date schedule. Display Customer, daily amount, N slots, dates, timezone, expected gross, agreed fee basis/timing, and separately labelled estimate. Explain daily includes weekends and elapsed dates do not automatically complete the plan.
4. Record Customer agreement as a required Agent attestation in the confirmation, including actor/time and terms revision. This is a proposed operational acknowledgement, not Customer financial self-service or a claim of electronic signature. Customer login activation is not required. A Customer may raise a discrepancy through the business; no Customer approve/create endpoint is introduced.
5. Confirm one server-bound operation reference. At commit recheck input, current Agent/session/assignment versions, Customer status, open-cycle invariant, configuration/fee version, and referenced resource relationships.
6. Persist one Active cycle, terms/fee snapshot linkage, all N slots, lifecycle/audit evidence, operation binding and notification work atomically. Snapshot validation failure commits nothing. Creation itself posts no savings, fee revenue, collection receipt, withdrawal or reservation.
7. Return the created reference and current state. Send permitted notification after commit; delivery failure does not undo or duplicate creation.

If configuration or fee terms change between preview and commit, return a conflict and display a new preview requiring explicit reconfirmation. Do not silently choose a different rate/no-fee option. Creation must not proceed with a guessed fee or unknown timezone. Future-start Active plans occupy capacity immediately, but have no current-due slot until the start date; permitted advance funding follows Module 07, not a creation-time debit.

## 7. Schedule, progress and collection contract

### 7.1 Dates and slots

For a daily plan, slot i has due date `start_date + (i − 1) local calendar days`. Calendar arithmetic must correctly handle month/year boundaries and leap dates. A slot's local-day boundary is midnight-to-midnight in its snapshotted timezone; daylight-saving zones, if configured, use calendar-day arithmetic rather than adding 24 UTC hours. Receipts/financial events have separate actual occurrence and server posting timestamps owned by Module 07.

Never treat 31 contribution days as “one month,” identify a slot by receipt number, or infer completion from the last date. Due date, received date, posted date, and allocation date have distinct meanings. Backdated receipt permissions do not authorize backdated plan creation or term changes.

Changing the business timezone applies prospectively to new cycles only. An existing plan's dates, due-day interpretation and historical reports retain its timezone snapshot. Configuration migration for existing cycles is deferred.

### 7.2 Actual progress

Module 07 supplies live allocations and their versions, slot funded amount, and actual states Paid/Partial/Pending/Missed/Advance plus applicable skip/blocked explanations. This module supplies expectations and eligibility intervals; it never marks a slot Paid or synthesizes a receipt. Under Module 07's proposed classification, Missed requires a past unpaid eligible due day; explicit Skipped is a reasoned non-financial annotation and does not reduce N, count as funded, waive the shortfall, or cause normal completion. A skipped unfunded slot requires eligible catch-up or settled early termination.

- Funded slots: number whose net live principal allocation equals the expected amount.
- Remaining contribution units: N minus funded slots; partial-slot shortfall is displayed separately.
- Expected gross: N × agreed amount.
- Actual contributed: net posted principal for this cycle, not collected cash awaiting posting or the current liability after withdrawals.
- Completion: every slot fully funded, no allocation shortfall or unsupported unmatched principal. Ten receipts allocated to one slot count as one contribution unit; one receipt allocated to three slots can fund three units.

Partial, multiple-day, advance, catch-up allocation, overpayment rejection, slot caps, reversal effects and manual skip authority belong to Module 07. The plan's finite slots cannot absorb money beyond their total expected principal without an explicit owning workflow. Contributions cannot migrate to a future renewed cycle by changing a relation or plan date. Advance-paid future slots remain visibly future-funded; early funding can complete a cycle before its scheduled end date, without automatic payout or closure.

### 7.3 Status and service interruptions

Retain original schedule and effective explicit pause intervals, Customer eligibility intervals and relevant Agent service-availability intervals. Customer Inactive/Restricted status suppresses current actionable collections without changing plan lifecycle or slot dates. Agent unavailability removes that Agent's authority and raises Module 04 service work; it does not turn the Customer Inactive, auto-pause their plan, extend dates, post payments, or declare days missed.

Explicit plan pause excludes its slots from actionable expected collections while preserving scheduled expectations and historical actuals. Reporting must distinguish original schedule, eligible-to-collect expectations, interruption reason category and actual posted contributions. Do not count unavailable/blocked periods as collectible shortfalls without the dated eligibility context.

On resume/reactivation/reassignment, preserve slots and actuals. Unfunded earlier slots may be caught up after current eligibility checks through Module 07; no automatic payment, skip, missed transition or silent schedule extension occurs. Current-due work and earlier outstanding catch-up work must be labelled separately so the same slot is not counted twice. Module 07 determines card status with the interval contract; gaps in history block definitive status classification rather than produce fabricated Paid/Missed values.

## 8. Lifecycle and action matrix

### 8.1 States and transitions

| State     | Meaning / entry                                                      | Permitted plan actions                                                                                                                      |
| --------- | -------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| Active    | Confirmed open cycle; may be future-start or schedule elapsed        | Read, eligible collections, allowed pre-activity amendment, pause, zero-activity cancel, settled early close; system completion evaluation. |
| Paused    | Explicit Agent pause with effective interval                         | Read, permitted pre-activity amendment, eligible resume, zero-activity cancel, settled early close; no new collections.                     |
| Completed | All slots net funded; remains open pending settlement                | Read, eligible withdrawal/fee/correction workflows, closure after gates. No new ordinary collections, term amendment or automatic renewal.  |
| Closed    | Normal completed settlement or documented settled early termination  | Historical read and separately approved correction investigation; no normal mutations or automatic reopen.                                  |
| Cancelled | Zero financial/obligation activity terminated with retained identity | Historical read; no new financial activity or reopen.                                                                                       |

Allowed explicit transitions: Active → Paused; Paused → Active; Active/Paused → Cancelled with cancellation gates; Active/Paused → Closed with early-termination gates; Completed → Closed with normal gates. System evaluation permits Active/Paused → Completed only from authoritative fully funded actuals; preserve any previous pause history. Plan creation creates Active directly; saving an unconfirmed browser form is not a durable Draft cycle. Unsubmitted forms do not reserve capacity.

A confirmed reversal before closure that creates a shortfall moves Completed → Paused with a linked progress-correction event, not directly into collectible Active. Resume requires eligible Agent/Customer review. Preserve the earlier completion event and evaluate fee consequences through Module 05; do not silently reverse/reassess fees. Recompletion records another event without duplicate once-per-cycle charges.

If an approved correction affects a Closed cycle, retain Closed and raise a linked post-closure exception with its authoritative outstanding obligations. Do not invent new slots or automatically reopen/renew. Module 04 archival gates must see that exception. The detailed authority and procedure for reopening a financially closed cycle is a decision gate for the correction owner; unsupported reopening is denied initially. An existing renewed successor is not rewritten or cancelled by a predecessor exception.

### 8.2 Pause and resume

Require a reason and Customer-facing explanation; preview retained dates, funded/outstanding slots and permitted effects. Pausing neither reserves nor releases funds and does not automatically cancel pending withdrawals/reversals or waive fees. Resume closes the explicit interval and shows elapsed slots plus current obligations. If start/end dates have elapsed, resume remains possible without date amendment; eligible catch-up uses original slots. A status restriction cannot be bypassed by resuming the plan.

### 8.3 Completion

Evaluate from the complete authoritative slot/allocation version, never a paginated list or client total. Append a durable completion event and a single event reference consumed idempotently by Module 05. Fees determines whether a charge is due and whether it can apply; insufficient available savings leaves the obligation unpaid rather than forcing a negative balance. Completion does not guarantee net payout, mark Agent remittance reconciled, close a plan, or initiate/approve a withdrawal.

## 9. Editing and amendment rules

### 9.1 Before financial or obligation activity

An eligible assigned Agent may amend name, amount, start date, N, fee option and Customer-visible notes for Active/Paused plans only while the Customer is Active and all authoritative no-activity gates pass. Proposed gate: no posted contribution/fee/withdrawal/deduction, assessed fee obligation, live reservation, pending financial request, queued financial command, receipt/cash liability, allocation or unresolved correction/reconciliation for the cycle. The registration-fee obligation is a Customer-level separate obligation; it is disclosed but does not by itself redefine this cycle's activity gate.

Show old/new terms, schedule and estimate; require reason and renewed Customer-agreement attestation. Persist a new revision and snapshot references without erasing old revisions. Unaffected slot IDs remain stable. Regeneration before any activity may supersede changed/removed slots with retained revision history; never reuse a removed slot ID or permit allocations to superseded slots. After the first financial/obligation activity, monetary and schedule terms lock even if that activity was later reversed, to preserve the original agreement and traceability.

### 9.2 After activity

Name and Customer-visible service notes may be corrected by the eligible assigned Agent for nonterminal plans and Active Customers, with reason, before/after history and notification; this does not change fee bases, slots or transactions. Closed/Cancelled records are read-only. Internal audit explanations may append through the audit owner rather than edit the terminal plan.

Amount, currency, start date, N, timezone, frequency, fee model/rate/basis/timing and Customer ownership remain locked. Wrong financial terms require settlement/early closure and a new correctly agreed cycle, or a separately authorized financial correction procedure; an Admin cannot unlock them through Customer management. No amendment causes retroactive reallocation, fee repricing, automatic refund, or new contribution posting.

## 10. Estimates and authoritative financial values

Display expected gross, estimated agreed fee and estimated payout separately from actual contributed, posted Customer liability, live reservations, available savings, due/unpaid fees, applied charges and actual payouts. Shared available-savings contract: posted Customer liability minus live payout reservations. Customer-level available savings is not automatically a cycle's withdrawable amount; Withdrawals must define plan attribution and eligible source balances.

For an illustrative one-day completion-fee plan of ₦2,000 × 31, expected gross is ₦62,000, estimated fee ₦2,000 and estimated payout ₦60,000 under the selected Module 05 policy. This is not an actual balance. Earlier withdrawals, partial funding, corrections, approved deductions, fee timing, reservations or unpaid obligations can change actual settlement.

Use Module 05's snapshot to quote fixed/one-day/percentage estimates. One-day uses the snapshotted contribution-unit amount. Percentage completion estimates use expected gross, while actual Module 05 computation uses its specified net posted principal after approved reversals and before withdrawals; percentage-per-withdrawal charges use the withdrawal owner's gross savings debit contract. Manual discretionary charges are not invented as estimates; show that separately authorized future charges cannot be predicted. Unsupported fee combinations block selection.

Plan creation/amendment/completion estimates do not post a fee or recognize earnings. No silent registration-fee deduction, double-counting of applied fees, negative available savings, or locally computed balance from recent receipts. Unknown/stale fee or ledger sources show unavailable/as-of state and block dependent financial/closure confirmations. Existing immutable posted amounts remain visible when their live summary source is unavailable, with clear as-of context.

## 11. Settlement, closure and cancellation

### 11.1 Normal closure

Eligible assigned Agent requests Completed → Closed for Active/Inactive Customers, or permitted non-financial Restricted closure under Section 5.2. Preview authoritative gates and consequences; require confirmation and reason. Closure does not itself pay money, apply/waive fees or approve a correction.

At commit all relevant owners must certify:

- Cycle-attributed posted Customer liability is zero, including any unallocated/exception principal and currency-precision residuals.
- No live reservation or pending/unposted withdrawal, payout, correction, deduction, refund, charge, allocation, receipt or financial job remains for the cycle.
- All cycle fee obligations are paid/applied/waived/refunded or otherwise terminal under Module 05; no outstanding amount or dispute is hidden by a zero savings balance.
- All attributable Agent cash/remittance and reconciliation obligations and exceptions are resolved through Module 07.
- No open post-completion discrepancy, unsupported ownership mapping, or unknown obligation result remains.

Customer-level registration fees remain visible and block Customer archival under Module 04; Module 05 must identify whether one is tied to this cycle's settlement. Do not silently transfer/forgive it to close a plan. If a Customer-level financial owner cannot reliably attribute an obligation to cycles, closure is Blocked pending that contract rather than assuming it is unrelated.

### 11.2 Early termination

Active/Paused → Closed is proposed for deliberately ending an incompletely funded cycle. It requires all normal settlement gates, a required early-termination reason and Customer-facing explanation, confirmation of funded/unfunded slots, and explicit fee-owner outcome for premature termination. Unfunded slots remain historical unfulfilled expectations with terminal early-termination context; they are not marked Paid, erased or reused. Proposed default for review with Module 05: if the cycle has net posted principal, the full agreed once-per-cycle fixed/one-day fee becomes due if not already assessed; a cycle-completion percentage uses net posted principal after approved reversals and before withdrawals. No automatic pro-rating by paid or missed days, new registration fee, duplicated previously assessed fee, or refund is implied. A previously used cycle whose principal was fully reversed requires an explicit correction/fee disposition; it cannot use fee-free zero-activity cancellation. Withdrawal-timed/other supported fee outcomes remain Module 05's authoritative contract. If its result or the approved policy is unavailable, the action remains blocked rather than applying this prose as an independent charge engine. Customer Inactive may settle/close existing savings without changing plan terms or restoring contribution eligibility.

### 11.3 Cancellation

Cancel only an Active/Paused zero-activity cycle: the strict no-activity gate in Section 9.1 passes, there has never been financial activity, and every fee owner confirms zero cycle obligations and no cancellation charge. Cancellation requires reason/confirmation, retains ID/terms/schedule/revisions/lineage/audit, and occupies no open-cycle capacity after commit. A transaction later reversed to zero does not qualify. Financially used cycles take settled closure, not cancellation. Failed cancellation commits nothing.

### 11.4 Authoritative gate protocol

Gate queries return owner, record/version, outcome, as-of timestamp and blocking references with scope-safe explanations. Lock/version-check the relevant plan, Customer/assignment and obligation/reservation state, or use an equivalent serialized owner protocol, so a new contribution/request/fee command cannot slip between gate query and closure. Marking Closed/Cancelled must atomically prevent new normal commands across all owners. Unknown or conflicting gates fail closed. Do not trust a browser checkbox, rounded display balance, cached zero or timeout as proof of settlement.

## 12. Renewal and reassignment

### 12.1 Renewal

Renewal is eligible Agent creation for the same currently Active Customer from a Closed predecessor (normal or early) or Cancelled zero-activity predecessor. Completed alone is insufficient. Enforce no existing open cycle, one successor link, current authority and the ordinary creation contract. Show carried defaults as editable input: name, amount, N and fee option require renewed agreement; start date must satisfy current validation and is not silently the next date after an elapsed predecessor. Fetch current applicable Module 05 rules; do not reuse old fee rates without explicit current applicability.

Atomic creation links new cycle ↔ predecessor and binds one operation reference. New slots and fee snapshot have new identities; Customer identity, lifetime balances, registration date, assignments and all previous cycles remain. Renewal never generates another registration fee merely because a new cycle was created, moves residual funds, duplicates a predecessor fee, restores login access, or activates an Inactive Customer. Historical cycles are searchable and clearly distinguished by ID/date/state.

### 12.2 Reassignment

Module 04 changes current service responsibility without changing plan ID, state, timezone, agreement, dates, allocations, balances, fee snapshots, completion events or lineage. Historical creator/collecting Agent references remain. Former Agent loses plan access immediately across forms, jobs, links and exports. New eligible Agent can view permitted full history and perform allowed lifecycle actions; authority to approve fees, withdrawals or reversals does not transfer to an Agent.

Open forms must reload against assignment and plan versions. Queued original-Agent commands are denied/cancelled safely, never executed under the replacement's identity. Agent unavailability does not release one-open-plan capacity; an Admin addresses service through explicit reassignment, not direct plan management. Reconciliation liability from prior collections stays with its original Agent. No plan-related pending request is recreated, repriced, auto-approved or cancelled solely by reassignment.

## 13. Directories and profile screens

| Screen                 | Required content and actions                                                                                                                                                                                                                                    |
| ---------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Scoped plan directory  | Customer/name/plan ID, amount/currency, start/end/timezone, funded/N, lifecycle, Customer status separately, current Agent where permitted, fee summary, last update. Search/filter by permitted Customer, ID/name, lifecycle, date range and Agent for Admins. |
| Create/edit preview    | Validated fields; generated slot dates; old/new revision comparison where relevant; fee source/version/basis/timing; estimates labelled; eligibility/capacity blockers; attestation/confirmation.                                                               |
| Plan detail            | Agreed terms/revisions, lifecycle/intervals, current eligibility explanation, original creator/current responsible Agent, sourced financial summary, fee snapshot/actual obligations, digital card, payments/requests, lineage and permitted history.           |
| Lifecycle confirmation | Current versions, consequences, authoritative gate checklist and blocking references, reason/Customer-facing explanation, success reference or safe conflict/failure.                                                                                           |
| Customer own view      | Read-only agreement, card, progress/shortfalls, estimates versus actual, Customer-facing interruption/early-termination explanations, prior cycles, permitted statement/contact link. No financial mutation controls.                                           |
| Admin oversight        | Business-wide read-only plans and interruption/settlement exceptions; links to separately permitted reassignment, fee, reconciliation or approval workflows. No plan management buttons.                                                                        |

Proposed pagination: 25 rows default, permitted sizes 25/50/100, default newest-created first with ID tie-breaker. Server filtering/counts/pagination must respect current scope; never derive total balances/progress from the page. Preserve filters when returning from detail. Archived/terminal cycles are accessible through explicit filters without disappearing from historical statements.

Hide actions prohibited by role. For otherwise eligible role actions blocked by status/plan/owner dependency, show a disabled control with a safe concrete reason and next step. Distinguish Active plan from Active Customer and usable Agent session. Loading never displays financial zeros; empty initial data says No plan yet; section failures preserve independently valid read sections and block dependent confirmations. Stale sources show as-of time. Reload after assignment/status change, deny old direct links safely, and clear sensitive out-of-scope cached content.

Support mobile card/table access, labelled inputs, keyboard navigation, accessible confirmations, focus/error guidance, and text/icons in addition to status colors. Slot detail exposes ordinal, local due date, expected/allocated/shortfall amounts and interruption context. Do not expose private Customer/Agent notes, privileged personnel/security reasons, credentials or other Customers' plan counts.

## 14. Failure, retry and concurrency requirements

- Bind each confirmed mutation to a stable server operation reference, actor/action/resource, normalized payload and expected versions. Retry the same operation after duplicate clicks, timeout or lost response. Same reference/different payload is rejected; do not mint a new cycle while outcome is unknown.
- A resolved committed operation returns its original outcome only to a currently authorized viewer. Lost assignment cannot be bypassed by reading an old operation receipt. A committed plan's identity survives an invitation/notification failure.
- Creation/revision/lifecycle transition, schedule/snapshot linkage, operation binding, durable audit capture and delivery work commit together. External deliveries occur after commit; rollback creates no partial plan/slots or orphaned actionable charge.
- Use serialized or equivalent atomic Customer capacity enforcement; simultaneous creation/renewal yields at most one open cycle. Stale plan/terms/assignment/status/configuration/owner versions return conflict, requiring fresh preview rather than overwriting later work.
- Financial owners recheck plan lifecycle and slot revision at contribution, reservation, fee, deduction or payout execution as applicable. Term amendment racing first contribution must serialize so either the amended agreement is confirmed before posting or amendment is denied without financial reallocation.
- Pause/restriction racing a collection: exactly one effective ordering; a receipt already durably posted retains history, a later ineligible command fails without partial posting. Closure racing correction/reservation must fail/retry safely if a gate version changed.
- Completion processing and its fee event are idempotent and recoverable from authoritative actuals. Re-delivery cannot generate repeated once-per-cycle fees. Owner callbacks validate plan/Customer/event relationships and versions, without trusting request-supplied status.
- Missing ledger/fee/eligibility dependencies, queue failure before durable commit, unsupported snapshot or gate protocol and unavailable audit persistence fail closed for dependent mutations. A post-commit delivery outage leaves durable retryable work and a visible receipt; it does not repeat the lifecycle action.
- User input error, authority denial, conflict, dependency failure and uncertain operation outcome have distinct safe interface states. Preserve nonsensitive entered data when permitted. Do not reveal an out-of-scope plan's existence.

## 15. Notifications and audit

### 15.1 Notification matrix

| Event                                         | Recipients / proposed channels                                          | Permitted message                                                                                                       |
| --------------------------------------------- | ----------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| Creation/renewal or terms amendment           | Customer in-app/email, current assigned Agent in-app receipt            | Plan reference, agreed terms/date/fee summary or changes, Customer-visible notes, safe contact; no claim of paid money. |
| Pause/resume/cancel/closure/early termination | Customer in-app/email; current Agent in-app                             | Effective state/time, user-facing reason, consequences and authorized next step.                                        |
| Completion or post-completion shortfall       | Customer in-app/email; current Agent in-app                             | Lifecycle event or material correction, outstanding settlement or shortfall, no automatic payout/fee approval promise.  |
| Owner dependency/settlement failure           | Acting Agent safe receipt; permitted owner staff work queue if relevant | Blocking category/reference within scope; no broadcast of private financial reasons.                                    |
| Closed-cycle discrepancy                      | Current assigned Agent; relevant authorized financial owners            | Safe exception reference, gate consequences, separate required corrective workflow.                                     |

Use Module 13's canonical delivery discipline and Module 04's lifecycle/current-Agent recipient rules: durable outbox, deduplicate event/recipient/channel, bounded retries, scope checks before send/retrieval, current Agent routing, minimal email subjects and authorized failure visibility. Routine notices cannot reveal statements/private notes in emails to an Invited or revoked-access account. The notification integration must use the recorded/verified-address policies of Authentication; email acceptance is not proof of agreement. Delivery failure never rolls back or repeats a plan mutation. Customer financial statements and balances remain available through authorized views, not broadly attached notifications.

### 15.2 Audit evidence

Durably record creation/agreement, every terms revision and snapshot linkage, explicit pauses/resumes, completion/recompletion/shortfall events, attempted and successful cancellation/closure/renewal, authoritative gate outcomes, dependency failures, idempotency conflicts, denied scope/role attempts, and post-closure discrepancies. Include event/operation/plan/Customer IDs, actor or trusted system source, immutable original creator, current assignment/version at action, UTC timestamp, terms/plan/owner versions, safe before/after references, reason, outcome, predecessor/successor and linked financial references.

Preserve completed financial records and all historical revisions/intervals. Audit is append-only; corrections append linked explanations. Detailed business audit requires `audit.view`; ordinary Customer-facing plan history is a scoped projection, not privileged audit access. No passwords, MFA secrets, invitation/session tokens, raw sensitive exports or third-party personal data in audit. Retention/reveal/export rules stay with Reporting/Audit; until defined, no plan screen offers unrestricted audit export. Admin business-wide report export requires `reports.export`; Agent/Customer statement scope remains with its owner.

## 16. Numbered functional requirements

| ID         | Requirement                                                                                                              | Specification      |
| ---------- | ------------------------------------------------------------------------------------------------------------------------ | ------------------ |
| TPC-FR-001 | Restrict initial scope to individual fixed-amount NGN daily finite cycles; reject unsupported modes.                     | Sections 2, 4      |
| TPC-FR-002 | Enforce Agent-only creation/management and role-safe read scope server-side.                                             | Section 5          |
| TPC-FR-003 | Recheck current Agent session/MFA/operational readiness, assignment and Customer action eligibility at commit.           | Sections 5, 12, 14 |
| TPC-FR-004 | Enforce at most one Active/Paused/Completed daily cycle per Customer atomically.                                         | Section 3          |
| TPC-FR-005 | Validate all fields/limits and reject protected/unknown fields and mismatched relationships.                             | Section 4          |
| TPC-FR-006 | Store NGN integer kobo, validate precision/ranges and use checked calculation.                                           | Sections 4, 10     |
| TPC-FR-007 | Snapshot timezone and generate N stable local-calendar slots with derived final date.                                    | Sections 4, 7.1    |
| TPC-FR-008 | Preserve timezone/schedules under configuration, assignment and status changes.                                          | Sections 7, 12     |
| TPC-FR-009 | Resolve applicable immutable fee terms through Module 05; prohibit Agent overrides/default guesses.                      | Sections 4, 6, 10  |
| TPC-FR-010 | Preview agreement/fee estimates and collect Agent agreement attestation before creation/revision.                        | Sections 6, 9      |
| TPC-FR-011 | Atomically create cycle/slots/snapshot links/audit/operation/delivery records with no financial posting.                 | Sections 6, 14     |
| TPC-FR-012 | Bind mutations to operation references and resolve retries/uncertain outcomes without duplication.                       | Section 14         |
| TPC-FR-013 | Reject stale configuration, terms, plan, assignment/status or owner gate versions.                                       | Sections 6, 14     |
| TPC-FR-014 | Derive actual progress from complete live owner allocations, not elapsed dates or receipt count.                         | Section 7.2        |
| TPC-FR-015 | Delegate actual card statuses/partial/advance/multi-day/catch-up allocations and finite-slot limits to Module 07.        | Section 7          |
| TPC-FR-016 | Preserve original schedules and dated eligibility/interruption history without fabricated payments/missed slots.         | Section 7.3        |
| TPC-FR-017 | Enforce explicit lifecycle/state/action matrices and deny unsupported transitions.                                       | Sections 5, 8      |
| TPC-FR-018 | Pause/resume with reason/history and current eligibility, without releasing capacity or financial effects.               | Section 8.2        |
| TPC-FR-019 | Complete only fully net funded cycles; produce one idempotent owner event per completion occurrence.                     | Section 8.3        |
| TPC-FR-020 | Preserve completion history on reversal, pause shortfall cycles and avoid automatic collection resumption/repeated fees. | Section 8.1        |
| TPC-FR-021 | Restrict financial/schedule amendments to verified never-used cycles and retain superseded revisions/slots.              | Section 9.1        |
| TPC-FR-022 | Lock financial terms after any financial/obligation activity; allow only specified nonterminal descriptive corrections.  | Section 9.2        |
| TPC-FR-023 | Clearly separate estimates from actual contribution, liability, reservations, available savings and owner fee outcomes.  | Section 10         |
| TPC-FR-024 | Never post financial amounts or registration-fee deductions through plan lifecycle/estimate actions.                     | Sections 6, 10     |
| TPC-FR-025 | Close only using complete versioned authoritative settlement gates with concurrency protection.                          | Section 11         |
| TPC-FR-026 | Require explicit settled early-termination outcome, agreed fee-owner contract and preserved unfunded history.            | Section 11.2       |
| TPC-FR-027 | Cancel only never-used zero-obligation cycles and preserve their identities/history.                                     | Section 11.3       |
| TPC-FR-028 | Keep Closed/Cancelled terminal; surface linked post-closure exceptions to financial/archive gates.                       | Sections 8.1, 11   |
| TPC-FR-029 | Renew by creating a fresh linked agreed cycle for the same eligible Customer after predecessor termination.              | Section 12.1       |
| TPC-FR-030 | Preserve Customer identity, prior cycles, finances and registration-fee history during renewal.                          | Section 12.1       |
| TPC-FR-031 | Apply immediate reassignment scope change without rewriting plan/financial/historical attribution.                       | Section 12.2       |
| TPC-FR-032 | Provide scoped searchable paginated directories, complete detail/revision/lineage and contextual actions.                | Section 13         |
| TPC-FR-033 | Show safe loading/empty/error/as-of/blocking states and accessible responsive controls/cards.                            | Section 13         |
| TPC-FR-034 | Fail dependent mutations closed on unavailable/unknown owner gates and preserve atomic outcomes.                         | Sections 11.4, 14  |
| TPC-FR-035 | Recheck lifecycle/slot versions in queued/financial commands; serialize races with posting/closure.                      | Section 14         |
| TPC-FR-036 | Emit scoped deduplicated notifications and handle delivery failure without undoing mutations.                            | Section 15.1       |
| TPC-FR-037 | Capture append-only durable lifecycle/agreement/gate/failure evidence with safe masking and access.                      | Section 15.2       |
| TPC-FR-038 | Block release of unsupported interfaces/authority/fee/timezone/correction policies instead of granting new capability.   | Sections 1, 17, 19 |

## 17. Acceptance scenarios and traceability

Development checks may use a documented contract test double exercising owner version/failure semantics; record that evidence as contract-level verification. Release acceptance requires authoritative owner integrations. An absent integration is **Blocked**, not Passed, regardless of a passing test double. Future implementation verification must record outcome, build/configuration versions and evidence; the table does not assert completed tests.

| ID         | Requirements           | Scenario / expected result                                                                                                                                   |
| ---------- | ---------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| TPC-AC-001 | TPC-FR-001, TPC-FR-005 | Submit weekly, foreign currency, interest, multiple owners or bulk input; reject without a plan or financial effect.                                         |
| TPC-AC-002 | TPC-FR-002             | Customer/Admin call creation/amend/pause/closure APIs directly; deny regardless of Admin grants.                                                             |
| TPC-AC-003 | TPC-FR-002, TPC-FR-032 | Customer own plan succeeds; another Customer's ID/search/count/revision/notification never reveals existence or data.                                        |
| TPC-AC-004 | TPC-FR-003             | Inactive/onboarding/suspended Agent mutation fails; legitimate existing temporary-lock session follows Module 04 exception.                                  |
| TPC-AC-005 | TPC-FR-003             | Create for Active/Invited Customer as eligible Agent succeeds; Customer login activation is not falsely required.                                            |
| TPC-AC-006 | TPC-FR-003, TPC-FR-017 | Inactive/Restricted/Archived Customer creation/renewal/amendment fails; Inactive existing settlement is available through its owner.                         |
| TPC-AC-007 | TPC-FR-004             | Attempt second plan while first is Active, Paused or Completed, including past final date/full withdrawal; reject.                                           |
| TPC-AC-008 | TPC-FR-004, TPC-FR-013 | Two agents/requests race creation or renewal for one Customer; at most one open cycle commits.                                                               |
| TPC-AC-009 | TPC-FR-005, TPC-FR-006 | Exercise field boundaries, blank/Unicode text, amount fractions/overflow, zero/fractional N and mismatched Customer; only valid input succeeds.              |
| TPC-AC-010 | TPC-FR-007             | 31 slots from a January start cross into next month if needed; Day N derives from N−1 days, not month end.                                                   |
| TPC-AC-011 | TPC-FR-007, TPC-FR-008 | Leap year/year boundary and DST-configured timezone retain correct local dates; browser timezone or later config change does not move slots.                 |
| TPC-AC-012 | TPC-FR-005, TPC-FR-007 | Backdated, >365-day future start and unsupported expected-date override fail; future-start valid plan occupies capacity.                                     |
| TPC-AC-013 | TPC-FR-009             | Missing fee/timezone/unsupported combination blocks creation; explicit applicable no-fee version succeeds without guessed zero.                              |
| TPC-AC-014 | TPC-FR-009, TPC-FR-013 | Fee/config changes after preview; conflict and new explicit review, never silently reprice.                                                                  |
| TPC-AC-015 | TPC-FR-010             | Confirmation contains all agreed terms and Agent attestation; absent confirmation cannot create a cycle.                                                     |
| TPC-AC-016 | TPC-FR-011, TPC-FR-024 | Valid creation commits one complete cycle boundary and no contribution, fee earnings, reservation or registration-fee deduction.                             |
| TPC-AC-017 | TPC-FR-011, TPC-FR-034 | Fail each persistence/snapshot/audit boundary; no partial active slots/plan/charge or premature message remains.                                             |
| TPC-AC-018 | TPC-FR-012             | Repeat click/retry/lost response resolves same plan/event; same key/different payload rejects.                                                               |
| TPC-AC-019 | TPC-FR-012, TPC-FR-031 | Original Agent loses assignment before retrieving committed operation; deny old receipt access without creating replacement.                                 |
| TPC-AC-020 | TPC-FR-014             | Ten partial receipts funding one slot count as one funded unit; one receipt funding three slots counts as three.                                             |
| TPC-AC-021 | TPC-FR-014, TPC-FR-019 | Final due date passes with unpaid/partial slots; plan does not complete; early advance-full funding may complete.                                            |
| TPC-AC-022 | TPC-FR-015             | Partial/multi-day/advance/catch-up allocations obey owner slots/caps; overpayment cannot create extra plan days.                                             |
| TPC-AC-023 | TPC-FR-016             | Customer status changes preserve dates/paid history; current actionable expectations exclude blocked periods, no automatic paid/missed entries.              |
| TPC-AC-024 | TPC-FR-016, TPC-FR-031 | Agent becomes unavailable/reassigned; slots and prior actor/cash attribution persist and service interruption is distinct from payment failure.              |
| TPC-AC-025 | TPC-FR-017, TPC-FR-018 | Pause/resume retains dated intervals/slots, no balance/reservation effect, no second-plan capacity; blocked Customer cannot resume.                          |
| TPC-AC-026 | TPC-FR-016, TPC-FR-018 | Resume after scheduled final date; display original outstanding slots and allow only eligible owner catch-up, no silent date extension.                      |
| TPC-AC-027 | TPC-FR-017             | Every unsupported transition/reopen/Customer-side action fails without lifecycle/financial mutation.                                                         |
| TPC-AC-028 | TPC-FR-019             | All slots net funded; durable completion event, no payout/closure; duplicate delivery produces no duplicate once-per-cycle fee.                              |
| TPC-AC-029 | TPC-FR-020             | Approved reversal creates Completed shortfall; transition Paused, retain completion history, owner handles fee correction and eligible review before resume. |
| TPC-AC-030 | TPC-FR-021             | Never-used Active/Paused cycle amended; reason/re-attestation, old revision/snapshot retained, superseded slot IDs cannot accept funds.                      |
| TPC-AC-031 | TPC-FR-021, TPC-FR-035 | First receipt/reservation/fee obligation races term amendment; serialize, never post against silently rewritten terms.                                       |
| TPC-AC-032 | TPC-FR-022             | Once-used then fully reversed cycle still refuses monetary/date/fee-term edits; allowed name/notes correction retains before/after.                          |
| TPC-AC-033 | TPC-FR-023, TPC-FR-024 | ₦2,000 × 31 preview shows ₦62,000/₦2,000/₦60,000 labelled estimates; actual withdrawals/fees/reservations are separate owner values.                         |
| TPC-AC-034 | TPC-FR-023             | Percentage/fixed/one-day fee basis uses snapshot and owner outcomes; no percentage-first-contribution or unknown early-fee guess.                            |
| TPC-AC-035 | TPC-FR-023, TPC-FR-034 | Ledger/fee summary fails/stales; show unavailable/as-of, not zero or derived page balance; dependent confirmation blocked.                                   |
| TPC-AC-036 | TPC-FR-025             | Individually fail liability, residual kobo, reservation, fee, request, correction, reconciliation/cash, job and attribution gates; closure fails.            |
| TPC-AC-037 | TPC-FR-025, TPC-FR-035 | New reservation/contribution/correction appears between gate query and closure; stale gates cannot commit Closed.                                            |
| TPC-AC-038 | TPC-FR-025             | Fully funded and fully settled cycle closes; no closure-triggered payout/waiver/posting, history retained.                                                   |
| TPC-AC-039 | TPC-FR-026             | Incomplete cycle settles under explicit owner early-fee contract and closes early; unfulfilled slots retained, never paid/skipped by invented command.       |
| TPC-AC-040 | TPC-FR-026, TPC-FR-038 | Early-termination fee policy unavailable; action blocked, no assumed pro-rate/refund.                                                                        |
| TPC-AC-041 | TPC-FR-027             | Never-used zero-obligation cycle cancels and releases capacity; any prior reversed receipt or pending charge prevents cancellation.                          |
| TPC-AC-042 | TPC-FR-017, TPC-FR-025 | Restricted closure/cancel succeeds only if entirely non-financial and all gates zero; status cannot permit fee/payout to pass gates.                         |
| TPC-AC-043 | TPC-FR-028             | Closed-cycle approved correction raises linked exception visible to archive/owner gates, keeps Closed and any successor unchanged.                           |
| TPC-AC-044 | TPC-FR-029, TPC-FR-030 | Renew terminated predecessor for eligible same Customer; fresh IDs/slots/current fee snapshot, one lineage, no new registration fee or money migration.      |
| TPC-AC-045 | TPC-FR-029             | Renew Completed/open predecessor, inactive Customer, already-linked predecessor or duplicate race; deny or resolve original successor.                       |
| TPC-AC-046 | TPC-FR-031, TPC-FR-035 | Reassign during form/job; former Agent action denied, new Agent sees permitted history, no impersonation or financial reset.                                 |
| TPC-AC-047 | TPC-FR-032             | Scoped directory search/filter/count/pagination and historical lineage work across repeated names; no cross-scope metadata.                                  |
| TPC-AC-048 | TPC-FR-033             | Mobile/keyboard/accessibility and loading/empty/error states expose text statuses and safe reasons, no guessed financial zero.                               |
| TPC-AC-049 | TPC-FR-036             | Delivery outage after lifecycle commit leaves one committed action and retryable deduplicated notice; current scope/privacy checked at delivery.             |
| TPC-AC-050 | TPC-FR-037             | Every material event/gate/denial has durable masked evidence; Customer history excludes privileged reasons and detailed audit requires `audit.view`.         |
| TPC-AC-051 | TPC-FR-038             | Missing owner interface, reopening authority or configuration decision marks dependent checks Blocked and cannot grant a new Admin permission.               |

## 18. Verification and release adequacy

Required fixtures include Active/Inactive/Restricted/Archived Customers, invited and activated Customer accounts, two Agents and assignment histories, Agent onboarding/Inactive/suspension/valid temporarily locked sessions, all five plan states, daily dates across month/year/leap/DST boundaries, zero-activity and historically used/reversed cycles, partial/advance/multiple-day/late allocations, live reservations, paid/unpaid/waived/refunded fees, unreconciled Agent cash, closed-cycle exceptions, and owner unavailability/stale-version races.

Verify invariants at persistent boundaries, not only button visibility: one open cycle, immutable agreed financial history, every slot's ownership/date/revision, no duplicate mutation/completion fee, no unauthorized financial posting, and version-safe closure. Capture one acceptance outcome per scenario with linked contract/implementation evidence and unresolved decisions. Integration failures or unapproved policy choices cannot be counted as successful acceptance.

## 19. Proposed choices and decision gates

| Decision                         | Draft recommendation / release implication                                                                                                                                                                                                                                         |
| -------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Daily frequency and range        | Daily all-calendar-day schedules, 1–366 slots, field limits in Section 4; approve before implementation. Additional frequencies need a dedicated schedule contract.                                                                                                                |
| Concurrency capacity             | One Active/Paused/Completed daily cycle per Customer; no renewal before terminal predecessor. Approve tighter-than-PRD open-cycle interpretation.                                                                                                                                  |
| Start and timezone               | Today/future at most 365 days, no backdated creation; timezone immutable per cycle. Business Configuration must establish authoritative settings/change scope.                                                                                                                     |
| Agreement evidence               | Agent attestation and Customer notice initially; any legal signature/consent requirement belongs to a separately agreed owner workflow.                                                                                                                                            |
| Term amendment                   | Financial terms lock after any activity/assessment, even if later reversed; descriptive corrections only under specified scope.                                                                                                                                                    |
| Interruptions                    | Preserve dates and suppress actionable expectations using effective intervals; catch-up uses original slots. Module 07 must finalize actual Missed/Skipped/blocked classification without fabricating payments.                                                                    |
| Completion corrections           | Completed shortfall becomes Paused; Closed remains terminal with an exception. Closed-cycle reopening/correction settlement needs explicit owner rules and existing authority mapping.                                                                                             |
| Settlement mapping               | Ledger/Withdrawals/Fees/Reconciliation must provide complete cycle-attributed gates and serialization. Unknown attribution blocks closure/archival.                                                                                                                                |
| Early termination                | Proposed full fixed/one-day cycle fee on early termination with net posted principal; completion percentage on net posted principal after reversals and before withdrawals. Module 05 owns actual due/charge outcome; approve this default, with no implicit pro-rating or refund. |
| Restricted non-financial endings | Permit cancellation/closure only when independently settled with no blocked financial action; approve this narrow lifecycle interpretation with Module 04.                                                                                                                         |
| Renewal                          | Fresh current rules/agreement, same Customer, at most one successor, no automatic carry-over of money or registration fee.                                                                                                                                                         |
| Notifications/exports/retention  | Apply cross-module scope/delivery policies; deferred Reporting/Audit contracts block unrestricted retention/reveal/export features.                                                                                                                                                |

## 20. Related modules

This module consumes Modules 01–05 and supplies stable schedule/lifecycle interfaces to Module 07. Withdrawals and Approvals, Reversals, Ledger/Reporting, Business Configuration and Audit must complete their respective financial, reservation, gate, migration and retention contracts before dependent features are released. This document does not introduce another accounting ledger or expand the role/permission catalogue.
