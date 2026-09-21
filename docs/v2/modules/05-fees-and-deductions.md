# Fees and Deductions

**Product version:** 2.0  
**Module status:** Detailed draft for review  
**Sources:** [PRD](../../PRD.md), especially Sections 22 and 27–33, 58–59; [User Types](./01-user-types.md), [Authentication](./02-authentication.md), [Authorization](./03-roles-and-permissions.md), [Customer and Agent Management](./04-customer-and-agent-management.md)  
**Related modules:** [Thrift Plans and Savings Cycles](./06-thrift-plans-and-savings-cycles.md), [Collections, Digital Thrift Card, and Reconciliation](./07-collections-thrift-card-and-reconciliation.md); future Withdrawals, Reversals, Reporting, and Business Settings

## 1. Purpose and specification status

Define how the business configures, assesses, receives, applies, waives, corrects, and reports fees and other deductions without confusing business earnings with Customer savings or Agent-held cash.

Confirmed boundaries from Modules 01–03 take precedence over the Version 1 PRD's single-collector model: only Agents register Customers, create/manage plans, record collections, and initiate Customer withdrawals/reversals; only appropriately authorized Admins perform protected fee/deduction actions or approve withdrawals/reversals. Customers only view financial records. Registration-fee acknowledgement is required for account activation, but payment is not.

All expanded financial policies below—including recognition on settlement, supported model combinations, rounding, deduction purposes, settlement gates, and notification defaults—are **proposed defaults for review**, rather than confirmed accounting, legal, or implementation policy. This document specifies product subledger behavior; statutory accounting/tax treatment requires an explicit policy before statutory reports or tax features are released.

## 2. Initial scope and boundaries

### 2.1 Included

- One configured business; proposed currency NGN, stored as integer kobo.
- Versioned registration and plan fee rules, including authoritative zero/no-fee rules.
- Immutable Customer registration and cycle snapshots; separate fee obligations and settlement entries.
- Fixed, one-day, percentage, and Admin-assessed manual fee models under the supported combinations in Section 5.
- Agent-recorded external fee receipts through Module 07; authorized savings application, full/partial waivers, and traceable correction/refund records.
- Separately permissioned manual non-fee deductions with confirmation and a disclosed purpose.
- Fee/deduction histories, outstanding obligations, business fee earnings, cash/settlement distinctions, lifecycle gates, notifications, audit, and reporting interfaces.

### 2.2 Deferred or gated

No automatic penalty/late-fee accrual, compounding, debt interest, taxes, multiple currencies, Agent commission sharing, retrospective rule migrations, bulk charging, arbitrary accounting adjustments, automatic registration-fee netting, or Customer online payment initiation. No Agent override of configured prices. Percentage at first contribution and blended/stacked cycle fee models are deferred.

Admin **recording of business fee-earnings withdrawals** is a proposed fee action under the existing `fees.manage` delegation, included in Section 9.4. It records an evidenced business draw and does not initiate Customer withdrawal processing or execute a bank transfer. Actual external Customer fee-refund payout and automated bank execution remain **release decision gates** until their owning payment workflow defines initiation, approval, evidence, finality, and cash/reservation treatment. Never invent a permission or reuse Agent-only Customer withdrawal initiation to pay business earnings.

## 3. Terminology and financial invariants

| Term                      | Meaning                                                                                                                                                            |
| ------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Rule version              | Immutable published fee policy with model, value/basis, timing, rounding, currency, and effective interval.                                                        |
| Snapshot                  | Terms selected and frozen for one Customer registration or one cycle; references a rule version and contains the calculation inputs needed to reproduce the terms. |
| Obligation                | Assessed amount the Customer owes; creation alone is neither receipt nor savings deduction nor recognized earnings.                                                |
| External fee receipt      | Money received separately from Customer savings and allocated to a fee obligation.                                                                                 |
| Savings application       | Posted charge funded from Customer savings; reduces Customer liability and settles the fee obligation.                                                             |
| Recognized fee earnings   | Net settled fees under this proposed product recognition policy, adjusted by posted linked corrections/refunds.                                                    |
| Other deduction           | Separately disclosed non-fee charge, authorized under `deductions.manage`; never concealed as a contribution allocation.                                           |
| Refund payable            | Amount the business owes after an authorized fee-refund entitlement is posted; separate from savings and unpaid original obligations.                              |
| Agent cash responsibility | Recorded cash/receipt responsibility of the receiving Agent; different from Customer liability and business income.                                                |

All money values use integer minor units. Display ₦2,000.00 for 200,000 kobo; reject more than two monetary decimal places rather than silently rounding entered amounts. Percentage rates use integer basis points: 2% = 200 basis points. Compute percentage fees with integer arithmetic and round half up once per assessment to the nearest kobo; preserve the unrounded numerator and rounded outcome or equivalent reproducible inputs. Never use floating-point arithmetic for financial decisions.

Posted ledger entries are balanced, immutable, uniquely identified, and linked to the assessed obligation and source transaction. Corrections append compensating entries; no edit/delete of posted amounts, dates, actors, or allocations. Operational history and audit accompany each financial change.

The shared product formulas are:

- **Customer savings liability:** posted contributions minus net cash paid to Customers (P) minus posted savings-funded fees/deductions, plus/minus linked compensating savings entries. For a withdrawal, P plus its included fee/deductions equals the gross liability debit G; do not subtract G and its included charges again.
- **Available savings:** posted Customer liability minus live payout reservations. This module introduces **no separate fee/charge holds**. An outstanding obligation alone does not reduce liability or availability.
- **Outstanding obligation:** assessed amount minus effective settled amount minus effective waived amount; never below zero. Refund entitlement/return of a valid charge does not automatically reopen the original obligation.
- **Net recognized fee earnings:** posted external fee settlements plus savings-funded fee settlements minus recognized linked fee reversals/refunds. Other deduction income is separately categorized.
- **Book fee balance:** net recognized fee earnings minus posted business fee-earnings withdrawals. **Drawable fee balance** additionally requires Section 9.4's cash backing and protection of refund obligations/distinct business reservations; the book figure alone is insufficient authority to pay.

A contribution posts its gross amount to Customer liability and thrift-card allocation. A separate fee entry shows any savings charge. A receipt's posted/reversed state determines financial effect; review, submission, reconciliation, notification delivery, and invitation acknowledgement do not independently create income or modify a posted contribution.

## 4. Authority and eligibility

### 4.1 Action matrix

| Action                                                   | Customer | Eligible assigned Agent                                | Admin                                                                             |
| -------------------------------------------------------- | -------- | ------------------------------------------------------ | --------------------------------------------------------------------------------- |
| View Customer fee/deduction records                      | Own only | Assigned Customers                                     | Business-wide baseline read                                                       |
| View business fee totals/rule catalogue                  | No       | Only selected Customer/cycle terms needed for work     | Baseline read                                                                     |
| Configure/publish/retire fee rules                       | No       | No                                                     | `fees.manage`                                                                     |
| Create registration/cycle snapshot                       | No       | Through authorized registration/plan workflow          | Cannot create Customer/plan                                                       |
| Record external fee receipt                              | No       | Module 07 receipt workflow                             | Prohibited collection recording                                                   |
| Assess discretionary manual fee                          | No       | No                                                     | `fees.manage`                                                                     |
| Apply an agreed outstanding fee to savings               | No       | Cannot directly debit savings                          | `fees.manage`, or constrained owning-module trigger bound to the agreed snapshot  |
| Waive an unpaid fee                                      | No       | No                                                     | `fees.manage`                                                                     |
| Record other deduction                                   | No       | No                                                     | `deductions.manage`                                                               |
| Initiate reversal of accessible Customer financial entry | No       | Owning reversal workflow                               | Cannot initiate Agent-only reversal                                               |
| Approve/post reversal decision                           | No       | No                                                     | `reversals.review`; one approval regardless of value                              |
| Authorize a valid-charge refund entitlement              | No       | No                                                     | Proposed `fees.manage`; actual payout is gated                                    |
| Record an evidenced business fee-earnings withdrawal     | No       | No                                                     | Proposed `fees.manage`; Section 9.4, separate from Customer withdrawal processing |
| Reconcile recorded external fee receipts                 | No       | View own system-created batch/status through Module 07 | `reconciliation.manage`                                                           |
| View detailed audit/export business reports              | No       | No                                                     | `audit.view` / `reports.export` separately                                        |

`fees.manage` does not imply `deductions.manage`, `reversals.review`, `withdrawals.review`, `reconciliation.manage`, or payout authority. A rule publication is not a plan edit; an Admin cannot use it to rewrite an existing cycle snapshot. Automated triggers use tightly defined system authority, an immutable source event, and the current eligibility checks; they are not an Admin collection endpoint.

Proposed sensitive Admin actions require fresh password-and-MFA authentication under Authentication's shared freshness policy: publishing a priced rule, manually applying charges, posting deductions, waiving fees, authorizing refund entitlements, and recording business fee-earnings withdrawals. Do not define a competing freshness interval here. Preview, reason, Customer-facing description for Customer-affecting actions, and explicit confirmation are required. Revoked permissions, stale sessions, and current restrictions are checked again at commit.

### 4.2 Customer status matrix

| Operation                                        | Active                     | Inactive                                     | Restricted                         | Archived               |
| ------------------------------------------------ | -------------------------- | -------------------------------------------- | ---------------------------------- | ---------------------- |
| Scoped read                                      | Allowed                    | Allowed                                      | Allowed                            | Allowed                |
| Assess discretionary new fee/deduction           | Authorized action          | Blocked                                      | Blocked                            | Blocked                |
| Assess/apply already agreed cycle/settlement fee | Owning trigger             | Existing agreement only                      | Blocked while held                 | Blocked                |
| Agent external receipt settling existing fee     | Allowed                    | Proposed existing-obligation-only settlement | Blocked while held                 | Blocked                |
| Waive existing unpaid fee                        | Authorized action          | Authorized action                            | Proposed non-payout relief allowed | Blocked; restore first |
| Correct erroneous posted entry                   | Approved reversal workflow | Same                                         | Same corrective exception          | Blocked; restore first |
| Refund a valid charge from savings               | Owning refund workflow     | Same                                         | Blocked while held                 | Blocked; restore first |
| Authorize/pay external valid-charge refund       | Owning gated workflow      | Same                                         | Blocked while held                 | Blocked; restore first |

The existing-obligation fee-receipt exception for Inactive Customers is a **proposed settlement clarification**: no savings contribution, new plan, discretionary charge, or incidental Customer financial mutation is allowed. An Inactive Customer's receipt must contain only existing-fee settlement; mixed receipts containing savings fail rather than silently dropping prohibited allocations. Restricted exceptions never imply payout permission. A status transition cannot erase fees, change recognition, release reservations, or settle Agent cash.

Acting Agents must pass Module 04's current account/readiness/assignment eligibility. Reassignment changes current service responsibility, not original receipt actors or their cash liability. Invited Customers may have fees and savings recorded by eligible Agents; their unpaid fee or unactivated login does not independently block account activation or otherwise eligible collections.

## 5. Rule configuration, fields, and models

### 5.1 Rule fields and validation

| Field                | Requirement                                                                                                                                        |
| -------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| Rule ID/version      | Server-generated immutable identifiers; version increases on publication; never reused.                                                            |
| Name                 | Required plain text, trimmed 1–100 characters.                                                                                                     |
| Kind                 | Registration or plan fee; manual assessment is a fee entry, not an unpublished configuration shortcut.                                             |
| Model                | No-fee, fixed, one-day, or percentage; only supported combinations below.                                                                          |
| Currency             | NGN in initial scope; never convert existing obligations.                                                                                          |
| Fixed amount         | Integer kobo, non-negative; zero requires explicit no-fee/zero-fee disclosure, not missing data. Positive assessed manual fees require amount > 0. |
| Percentage rate      | Integer 0–10,000 basis points; zero explicitly disclosed. Basis and timing required.                                                               |
| Timing               | Registration creation, first contribution, cycle completion, or withdrawal, according to kind/model.                                               |
| Settlement source    | Registration: external receipt by default. Plan: agreed savings application; optional external settlement must be expressly specified before use.  |
| One-day basis        | Snapshotted contractual daily contribution amount, not the number of collected/paid days.                                                          |
| Customer description | Required plain text, 1–500 characters; explains amount/rate, basis, timing, and source.                                                            |
| Effective time       | Server-validated UTC; initial publication effective immediately or at a validated future instant.                                                  |
| Retirement           | Ends applicability to new snapshots, without invalidating historical ones.                                                                         |
| Actor/reason/history | System actor/time/version plus internal reason, 1–500 trimmed characters.                                                                          |

Validate server-side and client-side. Reject unexpected protected fields, unsafe markup/control characters, unsupported timing/model combinations, overlong text, unknown currency, non-integer minor units/rates, arithmetic overflow, and invalid effective intervals. Proposed maximum individual money value is 999,999,999,999 kobo; cumulative calculations must still use overflow-safe integer arithmetic and must not truncate totals to that single-entry limit. Treat any smaller operational cap as an explicitly configured future business policy, not a client-only limit.

Exactly one applicable published registration rule may cover an instant, including an explicitly priced-zero version; overlapping applicability is a conflict. Plan rules may be a catalogue of alternatives but must have stable identifiers and eligibility. Only Agents choose among permitted published plan alternatives; they cannot invent an amount/rate or alter the snapshot. Rule changes create a new version, with impact preview listing affected **future** registrations/plans; no existing record changes.

### 5.2 Supported model/timing matrix

| Model      | First contribution                                   | Cycle completion                                      | Withdrawal                                                           |
| ---------- | ---------------------------------------------------- | ----------------------------------------------------- | -------------------------------------------------------------------- |
| No-fee     | No obligation/posting                                | No obligation/posting                                 | No obligation/posting                                                |
| Fixed      | Once per cycle at first posted contribution          | Once per cycle on completion event                    | Once per cycle on first successful withdrawal                        |
| One-day    | Same once-per-cycle timing; contractual daily amount | Same                                                  | Same                                                                 |
| Percentage | Deferred in initial scope                            | Once per cycle, rate × net posted cycle contributions | Per successful withdrawal, rate × that request's gross savings debit |

Registration supports a fixed or explicitly zero amount assessed once at registration. Manual fee supports an immediate Admin-assessed obligation and explicit source/Customer description; it is not an Agent-selectable cycle model. There is one configured cycle fee model per snapshot, with no stacked hidden charges.

For completion percentage, the basis is gross posted cycle contributions minus compensating contribution reversals; it excludes fees, deductions, payouts, outstanding receivables, and contributions to other cycles. For withdrawal percentage, **gross savings debit = net Customer payout + fee**. The request preview must accept a gross debit and show the resulting net payout; never ambiguously apply a percent to a net amount without the owning Withdrawal module explicitly defining conversion.

Fixed/one-day withdrawal fees are charged once per cycle, including early partial settlement; later withdrawals cannot charge them again. A rejected/cancelled/unposted withdrawal cannot consume the once-per-cycle marker or recognize a charge. If a request is fully reversed because it was erroneous, the reversal workflow must explicitly restore any associated marker/obligation according to linked entries, not reset it on a rejected UI retry.

Each withdrawal quote distinguishes gross debit, newly due fee, any already assessed/paid/waived cycle fee, and net payout. Never charge a first-contribution/completion fee again at payout. If a fixed fee exceeds the proposed gross debit or the net payout would be zero/non-positive, reject that payout quote and require a valid gross amount or explicit authorized fee disposition; do not silently cap, waive, or prorate the charge. A separately assessed outstanding cycle obligation cannot be counted as a second newly due fee. A waiver preserves the once-only assessment history; later triggers do not recreate waived debt.

**Proposed early-termination default:** an incomplete cycle may be settled early under Module 06. If it has any net posted principal, a completion-timed fixed/one-day fee becomes due in full once; no pro-rating by funded/missed days. A completion percentage uses actual net posted cycle contributions after approved reversals and before withdrawals. Already assessed once-per-cycle fees are not assessed again. The Agent's termination preview shows this disposition and uses an idempotent termination event. A cycle with no financial activity cancels without a fee; historical principal fully reversed requires correction/fee disposition through the owning workflow, not the zero-activity shortcut. Insufficient savings leave the obligation outstanding; closure waits for external settlement or authorized explicit waiver. Withdrawal-timed fees are assessed at successful payout, not merely ending a schedule. This draft default must be reflected in the cycle snapshot disclosure.

### 5.3 Publication workflow

1. Authorized Admin creates/edits a draft rule; published versions are immutable.
2. Validate model/basis/timing and show examples, current version, effective time, and future-only effect.
3. Fresh authentication, reason, and confirmation precede publication.
4. Re-check permission/current catalogue version, no registration-rule overlap, and validated pricing. Publish version, applicability interval, history/audit, and notification intent together.
5. Already-issued invitations/cycles retain their snapshots. Retirement appends an applicability-ending event/version; it does not edit a published pricing payload or erase earlier applicability history. It prevents new selection but permits existing agreed assessments and settlement.

Missing/inaccessible rules fail closed. A configured no-fee rule is authoritative zero; an unavailable integration is not zero.

## 6. Registration fee integration

The registration preview resolves the applicable authoritative version and returns amount, currency, explanation, snapshot reference, and applicability time/version. Module 04 commits one Customer profile/account/assignment, the immutable snapshot/applicability record, one non-zero obligation where applicable, audit, attempt binding, and invitation work as one usable boundary. An explicit zero version creates no payable/income, but retains the snapshot and applicability evidence.

If pricing changed after preview, commit nothing and require re-review/reconfirmation through Module 04. Missing rules, obligation persistence failure, or unavailable coordinated state blocks creation; never create a fee-less placeholder Customer. Concurrent/retried submissions cannot assess twice.

Authentication presents and records acknowledgement of exactly that snapshot. Resend, invited-email correction, activation, cancellation, invitation expiry, reassignment, archival restoration, or Agent return cannot reprice/reassess registration. An unpaid registration fee remains outstanding after activation; acknowledgement creates neither payment nor earnings. There is no automatic savings deduction for it. An Admin may explicitly waive it or, as a proposed exceptional settlement, apply it to savings only through Section 8 with a disclosed reason/source confirmation and sufficient availability; the invitation's original stated amount remains preserved.

Invitation cancellation does not automatically waive a valid obligation. Outstanding registration fees block Customer archival under Module 04 unless settled or explicitly waived. Module 06 separately defines the relevant cycle-obligation closure gates; an unrelated registration debt cannot silently reprice the cycle. Do not delete an unactivated Customer to discard unpaid fees or free their identity.

## 7. Obligation lifecycle and settlement

### 7.1 Obligation/entry fields

Every obligation stores ID, Customer, optional plan/cycle, kind/model, rule/snapshot reference, trigger ID, basis/rate or fixed input, assessed amount/currency, assessment/effective timestamps, original actor/system source, due condition, permitted source, Customer-facing explanation, and version. Settlement, waiver, correction, and refund entries link back with immutable amounts, actors/timestamps, source receipt/ledger entry, reason, and correlation/attempt references. Manual assessments have a recorded policy purpose; an empty rule reference cannot disguise an otherwise configured fee.

The outstanding balance/state derives from effective entries, not an editable status checkbox. Store original amounts and all compensations. Display due/outstanding separately from recognition, receipt reconciliation, and refund status.

### 7.2 State/action matrix

| Display state                  | Entry-derived condition                                  | Permitted next actions                                                                           |
| ------------------------------ | -------------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| Outstanding                    | Positive assessed amount; no effective settlement/waiver | External receipt, eligible savings application, waiver, assessment correction before settlement. |
| Partially settled              | Some settlement/waiver; positive remaining amount        | Settle remaining amount, waive remaining amount, or approved correction.                         |
| Settled                        | No outstanding amount; effective payment/application     | Read; approved correction or valid-charge refund.                                                |
| Waived                         | No outstanding amount entirely through waiver            | Read; correction/reinstatement only through explicit linked approved policy.                     |
| Partially paid and waived      | No outstanding; mixed settlement/waiver                  | Read; refund only against paid/applied portion.                                                  |
| Corrected/cancelled assessment | Linked cancellation removes unearned obligation          | Read historical assessment; no collection of cancelled remainder.                                |

No manual state reset. Registration/cycle obligation uniqueness is `(Customer, registration event)` or `(cycle, agreed fee trigger)`; per-withdrawal percentage uniqueness includes the withdrawal posting ID. Once-only triggers retain durable markers even when processing responses are lost.

### 7.3 External fee receipt

Only the currently eligible assigned Agent records a receipt in Module 07. Input includes Customer, obligation, received amount > 0, method, occurrence date, reference where required, and notes; Module 07 owns receipt validation, cash responsibility, posting finality, and reversal request mechanics.

Permit partial fee payments; reject overpayment above the current outstanding amount. Do not leave a hidden credit, convert overpayment to savings, or auto-allocate it to another obligation. One physical receipt split between savings and fee must have explicit allocations whose sum equals received money, each with its own semantic posting; commit all allocation records atomically or none. Receipt amount allocated to fees does not pay slots, inflate contributions, or reduce savings.

On final eligible receipt posting, settle the fee and recognize its amount once; debit the appropriate received-funds/Agent cash-responsibility account and credit fee income. Pending provider or unposted receipt states produce neither settlement nor earnings. Later remittance/reconciliation moves cash responsibility/assets as appropriate, with no second fee income. A remittance shortage does not unpay the Customer or silently reverse the posted fee.

## 8. Savings application, manual fees, and other deductions

### 8.1 Agreed fee application

When an eligible agreed plan trigger occurs, assess its obligation once. Savings application is a separate entry debiting Customer liability and crediting fee income; settle the obligation only for that posted amount. Initial automatic application is **full amount only**. If availability is insufficient, retain the outstanding obligation, post no fee application, and display why; never create a negative Customer balance or quietly spread it across future collections.

For a first-contribution trigger, Module 07 may atomically post the gross contribution, its slot allocations, assessment, and full application if sufficient savings are available. If there is insufficient savings for the full agreed fee, it may post the valid gross contribution plus assessment and leave the fee outstanding; the contribution is not rejected merely to fabricate net receipt equivalence. Show gross contribution, charge applied (including zero), and remaining obligation distinctly.

Application of an outstanding registration/manual fee requires an explicit authorized `fees.manage` action; it is not replayed by a future contribution. The Admin selects the obligation and agreed/explicit source, sees liability, live reservations, amount, remaining savings/outstanding fee, reason/description, and confirms. Do not net against reserved funds or unrelated Customer money. Partial savings application is deferred; an external partial fee receipt remains permitted.

Withdrawal-triggered application belongs to the owning withdrawal posting boundary: atomically consume the live reservation, debit savings by the gross request amount, recognize the approved fee, pay the net amount, and record the once-only trigger. Module 05 never pays the request independently or bypasses `withdrawals.review`. The reservation includes the gross debit, including fee; all cancellation/rejection/expiry and concurrency rules belong to Withdrawals.

### 8.2 Manual fee assessment

An Admin with `fees.manage` may assess a disclosed manual fee for an Active Customer with required purpose, positive amount, optional eligible cycle, permitted source, internal reason and Customer explanation. Preview and confirm; create the obligation without income. Immediate savings application may be explicitly chosen only if sufficient availability and eligibility exist, and assessment/application commit together in that case. A failed combined posting must not leave an unexpected assessed debt. Assessment-only is a distinct confirmed action.

Manual assessment must not replace a failed configured trigger, modify a snapshot, duplicate a pending/settled charge, or invent a penalty policy. Require an applicable approved business purpose before release; otherwise keep the action disabled.

### 8.3 Other deductions

`deductions.manage` authorizes the proposed Admin-only posting of an explicitly disclosed non-fee savings deduction. Required fields: Customer, optional cycle, category/purpose, positive amount, occurrence date, internal reason, Customer-facing explanation, and source reference where relevant. Proposed limits: internal reason and explanation 1–500 characters, notes ≤ 1,000; no future effective posting, arbitrary backdating of ledger commit time, or editable actor.

Initial categories may include administrative, transaction, and custom service charges only after each purpose, recipient/accounting destination, and disclosure policy is approved. Penalties remain deferred. Classify these as **other deduction income** or an explicitly approved payable destination; do not count every deduction as fee earnings or leave its counter-account undefined. An undefined category/accounting destination is a release gate, not permission for a generic debit.

Preview shows current posted/available savings, amount, destination, and resulting balances. Post only for an Active Customer, with fresh authentication, explicit confirmation, sufficient unreserved availability, durable balanced entry/audit, and idempotency. No debt-producing deduction, automatic retry after insufficient funds, split into multiple charges to bypass a cap, deduction against another Customer, or financial-history override. Corrections require Agent initiation and `reversals.review`; `deductions.manage` alone cannot reverse history.

## 9. Waivers, corrections, refunds, and earnings withdrawals

### 9.1 Waiver

An Admin with `fees.manage` may waive all or part of the unpaid remainder, using a positive amount no greater than outstanding, reason and Customer explanation, fresh authentication, preview, and confirmation. Append waiver evidence and reduce outstanding; create no cash receipt, savings deduction, income, or refund. Already-paid/applied fees require a refund/correction instead. Snapshot and original assessment remain visible.

An erroneous **unsettled** discretionary assessment may be cancelled through a linked `fees.manage` correction with evidence; this is an obligation correction, not reversal of posted money. A published registration snapshot is never overwritten; cancel/waive its unpaid obligation transparently. Reinstatement of a waiver is deferred pending policy; no hidden reappearance of debt.

### 9.2 Erroneous-entry reversal

An eligible assigned Agent initiates a reversal against an accessible original fee receipt/application/deduction. One Admin with `reversals.review` reviews evidence and approves/rejects, irrespective of amount. The future Reversals module owns eligibility, request states, concurrency, and physical-money treatment. Until supported, affected correction actions remain Blocked.

Reverse settlement/recognition and the appropriate original financial account using linked compensating entries, restoring the unpaid obligation where the original valid assessment still applies. Reverse a savings charge by restoring Customer liability. Reverse an erroneous fee receipt by compensating its original cash/responsibility entry; actual returned cash is a separately evidenced payment, never asserted merely because a database receipt was reversed.

If a contribution reversal changes a percentage-fee basis, require a linked recalculation decision in the approved reversal workflow. Reduced already-paid fee becomes a refund/correction obligation, not negative unpaid fees; unpaid overassessment receives a linked reduction. Increased charge cannot be invented from a correction without the snapshot's agreed terms. The associated trigger markers and withdrawal fee must be corrected together where required.

### 9.3 Refund of a valid charge

A business concession refund differs from reversing an erroneous entry. Proposed Admin `fees.manage` entitlement action requires reason, Customer explanation, amount no greater than net fee retained after previous refunds/corrections, source identification, and current eligibility. No refund of a waived/unpaid amount.

- A savings-funded fee concession may append a compensating fee-income entry and restore that same Customer's savings liability atomically. It creates no physical payout, no new contribution, and no slot credit. Keep this **distinct refund action** from Agent-initiated erroneous-entry reversal; confirm the authority distinction before release.
- An external-payment concession creates a separate refund payable and reduces net fee earnings; actual external payout is disabled until the owning module specifies authorized initiation, approval, reservation, payment evidence, duplicate prevention, and completion. Record entitlement as Payable, not Paid.
- Returning a valid charge does not recreate the original Customer debt. Excessive/repeated refunds, refunded-once races, and fee-income balance underflow are rejected or escalated through the explicit financial exception policy; never take other Customers' savings.

Refund entitlements/payment obligations block archival and fee-earnings withdrawals until settled or explicitly resolved. A later failed refund payment preserves the payable; notification failure cannot cancel it.

### 9.4 Fee earnings and business withdrawal recording

The business owns fee earnings in v2; an Agent's collected fees are attribution/cash-responsibility views, not an independently spendable Agent wallet. Show today/month/lifetime net recognized earnings, gross earned, refunds/corrections, fee-earnings withdrawals, book balance, unpaid obligations, refund payables, and cash backing separately.

An Admin with `fees.manage` may **record an evidenced business withdrawal of fee earnings**. This proposed Admin fee action is delegated by Module 03's existing grant. It is a separate business transaction, never Customer withdrawal initiation/approval, an Agent collection, an Agent wallet draw, or automated bank execution. Required fields: positive amount, occurrence date, method, business beneficiary, purpose/reason, verified executed-payment evidence/reference, funding account, and server actor/posting timestamp. Amount/date/text use Section 5's validation limits; reject future occurrence dates or reuse of the same executed-payment reference/source identity.

Before confirmation and atomically at posting, calculate:

- **Earned-undrawn limit:** net recognized fee earnings minus effective prior business fee-earnings withdrawals minus distinct live business-draw reservations, if a future owner introduces them.
- **Conservative cash limit:** verified remitted/settled available business cash minus full posted Customer savings liability minus separate external refund payables and other approved encumbrances **not already represented in that liability or other cash exclusions**.
- **Permitted recorded draw:** no greater than either non-negative limit. Unknown liquidity, missing liabilities/refunds, insufficient backing, or a negative free-cash result blocks posting; Agent receivables/unremitted collections are not drawable cash.

Protect full Customer liability: Customer withdrawal reservations are already included in that liability and must **not be subtracted a second time**. Separate account-specific restrictions or future cash reservations avoid duplicate counting. This conservative initial rule may block drawing income while savings cash remains with Agents; it is a proposed policy, not a measure of earnings.

Show earned/book/physically drawable amounts, funding account, beneficiary, source evidence, amount, and resulting values; require fresh password/MFA, reason and explicit confirmation. Post balanced business cash/distribution entries plus immutable draw history/audit/idempotency/delivery intent together. The draw reduces fee book balance and business cash without changing Customer liability, fee recognition, thrift-card slots, unpaid Customer fees, or Agent cash liability. No receipt, reconciliation, or waiver itself grants authority to execute a payment.

Proposed correction of a mistaken **business-only draw record** uses a dedicated `fees.manage` linked compensating recording action with original-source/evidence and the same fresh authentication/audit protections. It reverses the original business-account effect and cannot rewrite history or credit cash never actually returned. A verified returned business draw restores effective drawable book balance only alongside actual returned-funds evidence. This action grants no Admin initiation of Customer transaction reversals. A later fee refund exceeding remaining net earnings creates an explicit business funding exception; never finance it from Customer savings or delete historical draws. Automated transfer initiation, separate approval policy, Agent remuneration, and external refund payout remain outside initial scope.

## 10. Accounting examples and reconciliation

These examples illustrate the **proposed product ledger**, not statutory revenue treatment. Balancing counter-accounts are selected by the approved chart-of-accounts contract; absence of that contract blocks posting.

| Event                                                   | Customer liability / slots                                                       | Obligation                        | Fee earnings / received funds                           |
| ------------------------------------------------------- | -------------------------------------------------------------------------------- | --------------------------------- | ------------------------------------------------------- |
| Register with ₦1,000 fee                                | No savings or slots                                                              | ₦1,000 outstanding                | No income/cash; preserve snapshot.                      |
| Customer acknowledges and activates                     | No change                                                                        | Still ₦1,000                      | No change.                                              |
| Agent receives ₦400 external fee                        | No change                                                                        | ₦600 outstanding                  | ₦400 income and Agent received-funds responsibility.    |
| Admin waives remaining ₦600                             | No change                                                                        | Zero outstanding, paid-and-waived | No additional earnings/cash.                            |
| Daily ₦2,000 × 31 contributions, one-day completion fee | Gross ₦62,000 and 31 funded slots; separate ₦2,000 charge leaves ₦60,000 savings | Fee settled ₦2,000                | ₦2,000 income; application itself receives no new cash. |
| 2% completion fee on ₦100,000 net cycle contributions   | Liability falls by ₦2,000; slots unchanged                                       | ₦2,000 settled                    | ₦2,000 income, no new cash.                             |
| 2% withdrawal fee, gross debit ₦10,000                  | Debit liability ₦10,000, pay Customer ₦9,800                                     | ₦200 settled                      | ₦200 income; no second fee on retry.                    |
| ₦500 other service deduction                            | Liability decreases ₦500; slots unchanged                                        | Other-deduction entry             | ₦500 classified other income, not fee income.           |
| Refund ₦500 of a savings-funded valid fee               | Restore liability ₦500; no new contribution/slot                                 | Original debt stays settled       | Decrease fee income ₦500.                               |
| Approved external refund entitlement ₦400               | No savings/slot change                                                           | Separate refund payable ₦400      | Decrease fee income ₦400; actual payout still unpaid.   |

Suppose a Customer has liability ₦5,000, a live payout reservation ₦4,000, and an unpaid fee ₦2,000. Availability is ₦1,000. A full fee application fails; the outstanding obligation cannot borrow reserved funds or make liability negative. Liability remains ₦5,000 and the fee remains ₦2,000 outstanding.

Suppose one physical receipt is ₦3,000: ₦2,000 savings and ₦1,000 registration fee. Cash responsibility increases by ₦3,000, liability/slots increase only by ₦2,000, and fee income increases by ₦1,000. Reconciliation/remittance of ₦3,000 creates no second contribution or income. If the Agent later loses assignment, that receipt/cash responsibility remains attributed to them; a new Agent follows up the Customer's remaining obligations.

Reconciliation compares gross physical receipts, explicit savings/fee allocations, cash remittances, and other methods separately. A fee applied to savings contributes **zero new physical receipt**. Collection submission totals must not sum savings applications into received cash. Business reports include all Customer liabilities irrespective of account/operational status; outstanding fees never masquerade as cash or Customer savings.

## 11. Screens, accessibility, and reporting

### 11.1 Admin workspace

Baseline reads: fee overview with metric definitions/as-of time; rules/version history; obligation register; fee/deduction/refund entry register. Default newest-first with stable ID tie-break, 25 rows/page; proposed 25/50/100 choices. Filters: date range, Customer, current/original Agent as explicitly labelled, cycle, kind/model, obligation state, source, currency, refund status, and reconciliation status where available. Totals must use the whole scoped filtered dataset, not only the visible page.

Protected actions appear only with the matching current grant. Rule editor includes examples and future-only impact. Obligation detail shows original snapshot, assessment basis, settlement/waiver/correction/refund timeline, original/effective amounts, source entries, and blocked-action explanation. Other deductions use a distinct action and classification. Business draw recording is labelled separately from Customer withdrawal processing and bank execution; unknown cash backing blocks confirmation.

### 11.2 Agent and Customer views

Assigned Agents see their Customer's original fee terms, due/outstanding amount, paid/waived amounts, separate charges, and permitted receipt/reversal actions. Agent summaries label attribution versus cash responsibility and exclude business-wide earnings. After reassignment, old Customer details disappear; masked historical own-settlement access follows Module 04.

Customers see their own fee explanation, assessed/paid/waived/outstanding amounts, savings charges, other deductions, linked reversals/refunds, and a chronological history/statement. No payment/deduction/waiver mutation, internal reasons, audit payloads, other Customer information, or business earnings. A waived fee still shows its original terms and waiver outcome. Invited Customer fee presentation remains Authentication-owned.

Use NGN/currency labels, explicit gross/net/source labels, keyboard-accessible forms/dialogs, labelled validation, readable mobile layouts, and confirmation showing consequences. Distinguish loading, no records, no search matches, unavailable data, permission loss, and failed mutation. Never display unknown totals as zero or use colour alone for financial state. Retry failed reads safely; resolve uncertain writes rather than repeating with a new attempt.

Reports provide gross fees, net earnings, unpaid/waived obligations, actual cash receipts, savings applications, other deductions, refunds/payables, fee-earnings withdrawals, and Customer liability as separate metrics. Use UTC committed timestamps and the configured business reporting timezone, with deterministic date boundaries; retain receipt occurrence dates separately. Business/multi-Customer export requires `reports.export`, row/field scope, current authorization at download, and private-field redaction. Statement/audit retention, detailed reveal policy, and final statutory reports remain with their owners.

## 12. Failure, retry, concurrency, and integrity requirements

- Every mutation binds an attempt reference to business, authenticated actor or deterministic system trigger, operation, and normalized payload. Same attempt/same input resolves once; changed input conflicts. Replays observe current read scope; an attempt ID is not access authority.
- Durable uniqueness covers registration/cycle obligations, fee-trigger markers, receipt allocations, ledger posting IDs, refund entitlement/payment identities, business-draw executed-payment source identities, and notification event/recipient/channel. A delayed transport retry cannot become a second charge or business draw after restart.
- Persist mutation state, balanced postings where applicable, obligation settlement/markers, required audit, and delivery intent atomically or through an equivalent coordinated boundary with no partially usable financial result. Actual email/provider dispatch occurs only after commit.
- Definitive validation/insufficient funds/eligibility failure commits no attempted financial effects. Distinguish an assessment-only success from a failed requested combined assessment/application.
- Unknown commit outcome produces Pending confirmation; resolve the same attempt. Do not promise non-commit after a timeout or permit fresh attempts to multiply debt/earnings.
- Serialize availability checks with deductions, fee applications, withdrawal reservations/payouts, contributions/reversals, status transitions, archival, and cycle closure. Two individually affordable charges cannot jointly overdraw savings.
- Serialize obligation settlements/waivers/refunds; no negative outstanding amount, over-refund, waived debt collection, duplicate once-only charge, or charge against an archived/held Customer.
- Jobs re-check current Customer status, source validity, assignment where Agent authority applies, permissions, and snapshot terms before posting. Reassignment cannot impersonate the new Agent or transfer original cash responsibility.
- Price/version conflicts require re-preview and confirmation; published-rule replacement never mutates snapshot inputs. Failure/unavailability of balance, reservation, rule, ledger, audit durability, or lifecycle-gate integration fails closed.
- Remote audit indexing/report projections/notifications may retry after a durably committed local event. Durable audit capture failure prevents the mutation; downstream projection lag is labelled with as-of/refresh state and cannot grant funds availability.
- Financial entry cleanup may not delete posted or uncertain entries. Operational drafts may be abandoned with traceable history; retention/capacity policy must preserve duplicate-prevention identities.

## 13. Notifications and audit

### 13.1 Notification matrix

| Event                                           | Recipients                                                       | Proposed channel/content                                                                                                      |
| ----------------------------------------------- | ---------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------- |
| Registration fee presentation/acknowledgement   | Customer; authorized invitation manager for operational progress | Authentication-owned invitation/activation messages; immutable original terms.                                                |
| Rule publication/retirement                     | Current authorized fee managers                                  | In-app; version, effective time, future-only effect.                                                                          |
| Manual assessment/application/deduction         | Customer and current Agent                                       | In-app plus Customer email for new savings debit; amount, purpose, source, resulting balance/outstanding, safe record link.   |
| External fee receipt                            | Customer and receiving Agent/current permitted Agent             | Customer in-app plus email; Agent in-app receipt. Show allocated fee amount, safe payment reference and remaining obligation. |
| Waiver/correction/refund entitlement/completion | Customer and current Agent; relevant authorized operator         | In-app; email for material debit reversal/refund change; entitlement and actual payment clearly distinct.                     |
| Trigger assessed but not applied                | Current Agent and fee managers                                   | In-app actionable insufficient-funds/blocked-state notice, deduplicated until meaningful change.                              |
| Failed/uncertain delivery or posting            | Authorized originating/current operator                          | Safe operational issue; no false Customer success receipt or repeated unchanged alerts.                                       |

Notifications occur after durable commit, use event/recipient/channel deduplication, check current scope at dispatch/read, and omit internal reasons, sensitive evidence, credentials, raw audit history, and unauthorized balances. Email uses safe minimal content; no activation/recovery tokens outside Authentication. Proposed transient delivery retry: initial attempt plus two retries within 15 minutes; uncertain acceptance is recorded honestly, and provider duplicates cannot be represented as guaranteed exactly-once email.

Delivery failure cannot undo/reassess/repost a fee. Reassignment suppresses old Agent Customer-linked notifications; retain only the masked own-receipt/cash summary permitted by Module 04. Preferences and detailed retention belong to notification/privacy policies; required financial history remains available even when a notice cannot be delivered.

### 13.2 Audit

Record rule draft/publication/retirement, version conflicts, snapshot acquisition, assessment/trigger outcome, external receipt/application/deduction, waiver, correction request/decision/posting, refund entitlement/payment, export, denied action, conflicting/uncertain attempt resolution, and delivery failure where operationally relevant.

Required metadata: event ID, configured business, actor/role or system source, current grant/assignment context reference, Customer/cycle/obligation/rule/source entry, server UTC time, attempt/correlation ID, outcome, protected reason/explanation references, amount/currency/basis/rounded result, and before/after outstanding/liability where the operation changed them. Failed actions describe non-commit or uncertainty accurately. Do not include submitted credentials, tokens, payment-provider secrets, arbitrary raw failed payloads, or unrestricted identity/evidence in searchable logs.

Audit is append-only and durably captured with the mutation. Admin baseline financial reads do not confer `audit.view`; protected detailed audit and `reports.export` are distinct. Customer/Agent financial histories are scoped business records, not unrestricted audit feeds. Masking, reveal, retention, and audit export follow owning policies; no destructive retention may remove posted financial links or duplicate-prevention markers.

## 14. Owning-module contracts and release gates

| Owner             | Required contract                                                                                                                                                                                         |
| ----------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Authentication    | Immutable registration snapshot presentation/acknowledgement; unpaid activation; invitation resend/cancellation preserve financial records.                                                               |
| Module 04         | Atomic creation/attempt binding; status/assignment/archival gates; current Agent eligibility; Inactive existing-fee settlement clarification; refund/fee outstanding integration.                         |
| Module 05         | Rules/snapshots, fee quote/assessment identities, settlement/waiver/refund calculations, permitted sources, earnings classification, outstanding obligations and close/archive check.                     |
| Module 06         | Cycle identity/daily basis/terms, immutable snapshot, unique first-contribution/completion sources, proposed early-termination fee disposition, preserved fee disposition on closure/renewal.             |
| Module 07         | Agent-only savings/fee receipts, atomic explicit allocations, receipt posting/cash responsibility, date/method evidence, reconciliation/remittance without duplicate income.                              |
| Withdrawals       | Agent initiation/Admin review; gross debit/net payout fee quote; reservation includes fee; atomic payout+fee settlement; request denial/cancellation/partial/cycle settlement and fee once-only behavior. |
| Reversals         | Agent initiation/one Admin approval; eligible original/source links; balanced compensations, physically returned cash distinction, percentage recalculation/marker correction, refund linkage.            |
| Ledger/reporting  | Approved counter-accounts, immutable balanced posting, integer math, reservation/availability concurrency, metric as-of dates, linked entries, financial exports/retention.                               |
| Business settings | Currency/timezone/limits, seeded valid initial rule, sensitive-setting policy; setting changes do not reprice existing terms.                                                                             |

Do not invent a ledger mutation endpoint to fill missing approval/payout authority. A missing owner, unimplemented authoritative gate, unsupported early closure policy, unknown destination for deductions, or unavailable reservations leaves the affected action/scenario **Blocked**, not Passed. Registration can use an explicit seeded valid rule while payouts remain gated; no live collection release without the shared posting/accounting contract.

## 15. Indexed functional requirements

| ID         | Requirement                                                                                                                                                                                              | Detail                |
| ---------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------- |
| FEE-FR-001 | Preserve role prohibitions, scope, closed Admin grants, and current eligibility for every fee/deduction action.                                                                                          | Section 4             |
| FEE-FR-002 | Use integer kobo/basis points, supported currency, overflow-safe math, and deterministic half-up percentage rounding.                                                                                    | Sections 3, 5         |
| FEE-FR-003 | Validate rule fields/model combinations and retain exactly one authoritative registration rule at an instant.                                                                                            | Section 5             |
| FEE-FR-004 | Publish/retire immutable versioned rules with fresh authentication, reason, conflict protection, and future-only effect.                                                                                 | Section 5.3           |
| FEE-FR-005 | Freeze reproducible registration/cycle snapshots including explicitly zero terms; never reprice them.                                                                                                    | Sections 5–6          |
| FEE-FR-006 | Commit registration snapshot/obligation once in Module 04's atomic boundary; missing/changed rules block/reconfirm.                                                                                      | Section 6             |
| FEE-FR-007 | Keep activation/acknowledgement/resend/recovery/restoration independent of fee payment/recognition/reassessment.                                                                                         | Section 6             |
| FEE-FR-008 | Store obligation/settlement evidence and derive outstanding/state without editable financial-status overrides.                                                                                           | Section 7             |
| FEE-FR-009 | Assess fixed/one-day agreed fees once per cycle at supported triggers using the snapshotted basis.                                                                                                       | Section 5.2           |
| FEE-FR-010 | Calculate completion/withdrawal percentages against the specified net-contribution/gross-debit bases once per valid trigger.                                                                             | Section 5.2           |
| FEE-FR-011 | Separate obligation assessment, cash receipt, savings application, and earnings recognition.                                                                                                             | Sections 3, 7–8       |
| FEE-FR-012 | Record external fee receipts only through eligible assigned Agents with explicit obligations/partial allocations and no overpayment.                                                                     | Section 7.3           |
| FEE-FR-013 | Atomically split physical receipts into semantic savings/fee allocations whose sum equals receipt, without fee slot credit.                                                                              | Sections 7.3, 10      |
| FEE-FR-014 | Apply agreed fees to unreserved savings only with full sufficient availability and eligible source/status; insufficient application leaves debt unpaid.                                                  | Section 8.1           |
| FEE-FR-015 | Require explicit confirmed `fees.manage` action for registration/manual savings settlement; never silently auto-net future contributions.                                                                | Sections 6, 8         |
| FEE-FR-016 | Bind withdrawal fees to approved gross reservation and atomic payout posting; rejected/unposted requests do not earn fees/consume triggers.                                                              | Sections 5.2, 8.1     |
| FEE-FR-017 | Restrict manual assessment to approved disclosed purposes and a distinct assessment-only/combined operation.                                                                                             | Section 8.2           |
| FEE-FR-018 | Independently authorize/confirm other deductions under `deductions.manage`, with sufficient savings and defined destination/classification.                                                              | Section 8.3           |
| FEE-FR-019 | Waive only unpaid remainder through linked evidence without income, receipt, refund, or snapshot edits.                                                                                                  | Section 9.1           |
| FEE-FR-020 | Correct unpaid assessments transparently; protect settled entries and defer undefined waiver reinstatement.                                                                                              | Section 9.1           |
| FEE-FR-021 | Route erroneous financial reversals through Agent initiation and one `reversals.review` Admin, with linked compensating entries.                                                                         | Section 9.2           |
| FEE-FR-022 | Correct dependent percentage bases/trigger markers and distinguish cash reversal from actual cash refund.                                                                                                | Sections 5.2, 9.2     |
| FEE-FR-023 | Authorize valid-charge refunds only against retained paid/applied amounts; distinguish restored savings from external refund payable/payment.                                                            | Section 9.3           |
| FEE-FR-024 | Report business-owned fee earnings separately from liability, cash backing, other deductions, refund obligations, and Agent attribution.                                                                 | Sections 3, 9–11      |
| FEE-FR-025 | Record business fee-earnings draws under proposed `fees.manage` with evidence, earned-undrawn/cash limits and immutable corrections; gate external refund payouts/bank execution.                        | Sections 2.2, 9.3–9.4 |
| FEE-FR-026 | Preserve original Agent cash attribution through reassignment/reconciliation and never recognize receipts twice.                                                                                         | Sections 4, 7, 10     |
| FEE-FR-027 | Enforce Customer status matrix and authoritative fee/refund lifecycle gates without changing account access.                                                                                             | Sections 4.2, 6, 9    |
| FEE-FR-028 | Bind idempotent attempts/triggers to actor/source/input and resolve uncertain outcomes without duplicate debt/posting.                                                                                   | Section 12            |
| FEE-FR-029 | Commit balanced immutable ledger/state/audit/delivery intent atomically, with linked corrections and fail-closed source integrations.                                                                    | Sections 3, 12        |
| FEE-FR-030 | Serialize funds, obligation settlement/waiver/refund, status/archival, and trigger races with current commit authorization.                                                                              | Section 12            |
| FEE-FR-031 | Provide scoped accessible registers/details/actions with safe validation, gross/net/source labels, and distinct unavailable/empty/error states.                                                          | Section 11            |
| FEE-FR-032 | Provide separately defined as-of earnings/obligation/receipt/liability metrics and authorization-protected exports.                                                                                      | Section 11            |
| FEE-FR-033 | Deliver scoped deduplicated notification events with bounded retries, safe contents, and no financial rollback/replay.                                                                                   | Section 13.1          |
| FEE-FR-034 | Capture append-only protected audit evidence with separate detailed-read/export grants and durable financial links.                                                                                      | Section 13.2          |
| FEE-FR-035 | Honor initial-scope restrictions/decision gates and cross-module ownership; unsupported integrations remain Blocked.                                                                                     | Sections 2, 14, 17    |
| FEE-FR-036 | Quote early termination under disclosed full fixed/one-day or actual-principal completion-percentage defaults; prohibit duplicate/negative-net withdrawal fees and debt from zero-activity cancellation. | Section 5.2           |

## 16. Acceptance criteria and evidence

These are future verification scenarios, not claims of implementation or completed testing. Each result records scenario/requirement IDs, actor/grants/status/snapshot fixtures, expected/observed persisted entries and balances, audit/notification references, and Passed/Failed/Blocked. Ledger and concurrency checks inspect persisted invariants, not merely disabled buttons. Dependency-gated scenarios remain Blocked until their owning workflows exist.

| ID         | Requirements                       | Scenario and expected result                                                                                                                                                                                                                                                                                                                                             |
| ---------- | ---------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| FEE-AC-001 | FEE-FR-001                         | Attempt Customer fee mutation, Agent rule/deduction/approval, Admin receipt/plan creation, or fee management without matching grant; deny with no financial effect.                                                                                                                                                                                                      |
| FEE-AC-002 | FEE-FR-001, FEE-FR-030             | Revoke `fees.manage`/`deductions.manage`, suspend actor, or remove an Agent's assignment between preview and commit; reject stale unauthorized action. Reassignment alone does not remove an Admin's valid business-wide grant; revalidate the action and current follow-up owner.                                                                                       |
| FEE-AC-003 | FEE-FR-002                         | Compute 2% of ₦100,000 and rates yielding fractional kobo at/below/above half; results use integer math and documented half-up rounding.                                                                                                                                                                                                                                 |
| FEE-AC-004 | FEE-FR-002, FEE-FR-003             | Reject excessive decimals, negative/oversized amounts, overflow, fractional basis points, unknown currency, invalid times/text and unsupported combinations; accept exact valid boundaries.                                                                                                                                                                              |
| FEE-AC-005 | FEE-FR-003, FEE-FR-004             | Race two registration-rule publications/overlapping effective intervals; only a valid non-overlapping version wins, with prior history retained.                                                                                                                                                                                                                         |
| FEE-AC-006 | FEE-FR-004                         | Publish/retire with/without required freshness/reason/confirmation; only authorized valid publication affects new applicability.                                                                                                                                                                                                                                         |
| FEE-AC-007 | FEE-FR-005, FEE-FR-006             | Register against valid positive and explicitly zero versions; preserve correct snapshot, assess positive obligation exactly once, and create no zero payable/income.                                                                                                                                                                                                     |
| FEE-AC-008 | FEE-FR-006, FEE-FR-029             | Inject snapshot/obligation/audit/coordinated persistence failure; no usable partial Customer/assignment/fee or invitation dispatch remains.                                                                                                                                                                                                                              |
| FEE-AC-009 | FEE-FR-005, FEE-FR-006             | Change rule after registration/plan preview; stale submission requires reconfirmation. Later publication/retirement never reprices issued snapshot.                                                                                                                                                                                                                      |
| FEE-AC-010 | FEE-FR-007, FEE-FR-011             | Acknowledge unpaid invitation and activate; obligation stays outstanding, savings/income/cash unchanged, and valid Agent collection is not blocked solely by unpaid fee.                                                                                                                                                                                                 |
| FEE-AC-011 | FEE-FR-007                         | Resend/correct/cancel invitation, reassign, recover, archive/restore settled Customer; no new fee/snapshot repricing/earnings occurs.                                                                                                                                                                                                                                    |
| FEE-AC-012 | FEE-FR-008, FEE-FR-019             | Assess ₦1,000, receive ₦400, waive ₦600; states and remaining amounts derive correctly, income remains ₦400, original terms/history persist.                                                                                                                                                                                                                             |
| FEE-AC-013 | FEE-FR-009                         | Trigger first contribution/completion/first posted withdrawal repeatedly for fixed/one-day snapshot; assess once using the contractual daily amount, not actual receipt size/days.                                                                                                                                                                                       |
| FEE-AC-014 | FEE-FR-010, FEE-FR-022             | Completion percentage excludes reversed contributions, payouts/deductions/other cycles; approved later reversal creates linked basis correction/refund as required.                                                                                                                                                                                                      |
| FEE-AC-015 | FEE-FR-010, FEE-FR-016             | For 2% withdrawal and ₦10,000 gross debit, reserve/debit ₦10,000, fee ₦200, Customer payout ₦9,800; the liability formula subtracts ₦9,800 plus ₦200 once, never ₦10,000 plus another ₦200. Retry/rejected request never duplicates fee.                                                                                                                                 |
| FEE-AC-016 | FEE-FR-012                         | Eligible Agent records partial fee receipt, then remaining amount; settlement/income match receipt allocation, slots and savings do not change.                                                                                                                                                                                                                          |
| FEE-AC-017 | FEE-FR-012, FEE-FR-030             | Race fee payment and waiver or two payments; reject excess settlement/overpayment and preserve non-negative outstanding.                                                                                                                                                                                                                                                 |
| FEE-AC-018 | FEE-FR-013, FEE-FR-029             | Split ₦3,000 receipt into ₦2,000 savings/₦1,000 fee; cash totals ₦3,000, savings/slots ₦2,000, income ₦1,000. Failure in either allocation commits neither.                                                                                                                                                                                                              |
| FEE-AC-019 | FEE-FR-011, FEE-FR-026             | Pending receipt produces no income; one final posting recognizes once; submission/remittance/reconciliation/shortage do not recognize again or unpay Customer.                                                                                                                                                                                                           |
| FEE-AC-020 | FEE-FR-014                         | Liability ₦5,000/reservation ₦4,000/fee ₦2,000: application fails with savings/reservation intact and ₦2,000 unpaid; no hidden fee hold.                                                                                                                                                                                                                                 |
| FEE-AC-021 | FEE-FR-014                         | First gross contribution below full agreed fee posts valid contribution/assessment with no application; sufficient later explicit authorized settlement posts once.                                                                                                                                                                                                      |
| FEE-AC-022 | FEE-FR-015                         | Outstanding registration fee followed by contributions is never silently netted; explicit confirmed application has distinct fee entry and sufficient availability.                                                                                                                                                                                                      |
| FEE-AC-023 | FEE-FR-016, FEE-FR-030             | Race approved withdrawal posting with restriction/cancellation/other debit; atomic current reservation/status validation prevents payout or charge beyond available savings.                                                                                                                                                                                             |
| FEE-AC-024 | FEE-FR-017                         | Manual assessment-only creates debt without income; failed requested combined assessment/application creates neither unexpected debt nor posting; undefined purpose remains disabled.                                                                                                                                                                                    |
| FEE-AC-025 | FEE-FR-018                         | Admin with only `fees.manage` cannot deduct; only `deductions.manage` with valid defined purpose/availability/freshness/confirmation posts distinct other-deduction entry.                                                                                                                                                                                               |
| FEE-AC-026 | FEE-FR-018, FEE-FR-024             | ₦500 other deduction decreases savings without slot change; classify against approved destination and exclude from fee-income totals.                                                                                                                                                                                                                                    |
| FEE-AC-027 | FEE-FR-019                         | Waive unpaid/partial remainder with valid amount; reject zero/excess/paid-fee waiver and preserve savings, cash, income, and snapshot.                                                                                                                                                                                                                                   |
| FEE-AC-028 | FEE-FR-020, FEE-FR-021             | Correct unpaid assessment through linked evidence; prohibit editing settled amount or restoring waived debt through undefined reset action.                                                                                                                                                                                                                              |
| FEE-AC-029 | FEE-FR-021                         | Agent initiates and one `reversals.review` Admin approves an eligible posted fee/deduction reversal; compensating entries preserve original record and proper savings/obligation/income effects.                                                                                                                                                                         |
| FEE-AC-030 | FEE-FR-022                         | Reverse erroneous external receipt; restore valid unpaid obligation/counter-account while actual returned cash is not falsely recorded as paid.                                                                                                                                                                                                                          |
| FEE-AC-031 | FEE-FR-023                         | Refund savings-funded valid charge within retained amount; restore same Customer liability, reduce income, no slot/contribution/new debt; excess/refunded-twice rejected.                                                                                                                                                                                                |
| FEE-AC-032 | FEE-FR-023, FEE-FR-025             | External valid-charge refund entitlement creates distinct payable without savings credit or false Paid state; actual payout stays gated until authorized contract exists.                                                                                                                                                                                                |
| FEE-AC-033 | FEE-FR-024, FEE-FR-025             | Record business fee draw only with fees.manage/freshness/evidence and both earned-undrawn/cash backing limits; preserve Customer liability and recognition; reject unknown/negative cash or duplicate source and never use Agent Customer withdrawal endpoint.                                                                                                           |
| FEE-AC-034 | FEE-FR-026                         | Reassign Customer with historical external receipts/shortage; replacement gains scoped follow-up, original Agent keeps cash responsibility, no reassignment financial entry.                                                                                                                                                                                             |
| FEE-AC-035 | FEE-FR-027                         | Exercise Active/Inactive/Restricted/Archived matrix; Inactive existing-fee receipt settles only agreed outstanding fees, never savings, Restricted blocks normal charges/payouts, approved corrective reversal exception remains scoped.                                                                                                                                 |
| FEE-AC-036 | FEE-FR-027, FEE-FR-035             | Attempt Customer archival with unpaid registration/cycle fee, refund payable, live reservation or unavailable owner state; authoritative gate fails. Cycle closure checks attributable obligations under Module 06, rather than treating unrelated registration debt as a cycle fee. Explicit valid waiver permits only the appropriate fee gate.                        |
| FEE-AC-037 | FEE-FR-028                         | Double-click/restart/lose successful response; same actor/payload attempt resolves one obligation/posting; changed payload conflicts, uncertain outcome prevents new duplicate.                                                                                                                                                                                          |
| FEE-AC-038 | FEE-FR-028, FEE-FR-001             | Replay old attempt after reassignment/permission loss; no unauthorized result disclosure, new charge, or actor impersonation.                                                                                                                                                                                                                                            |
| FEE-AC-039 | FEE-FR-029                         | Inject ledger/audit durability failure versus downstream indexing/notification failure; core failure aborts, durable commit survives downstream retries without reposting.                                                                                                                                                                                               |
| FEE-AC-040 | FEE-FR-030                         | Race individually affordable charges/reservations/waivers/refunds/status/closure; serialize invariant checks to prevent overdraw, over-refund, duplicate trigger, or archived posting.                                                                                                                                                                                   |
| FEE-AC-041 | FEE-FR-031                         | Customer/Agent/Admin directory/detail responses and direct IDs obey scopes; private reasons/business metrics omitted; current assignment/grant governs actions.                                                                                                                                                                                                          |
| FEE-AC-042 | FEE-FR-031                         | Keyboard/mobile use, stable pagination/filter/sort, gross/net labels, validation/loading/empty/unavailable/revocation states work without guessed-zero totals or accidental mutation retries.                                                                                                                                                                            |
| FEE-AC-043 | FEE-FR-032                         | Compare complete filtered metrics/UTC reporting boundaries/as-of projections and exports with persisted ledger; unauthorized export/download or private fields denied.                                                                                                                                                                                                   |
| FEE-AC-044 | FEE-FR-033                         | Emit each notification family with duplicate events, delivery failure, uncertain acceptance and scope loss; authorized safe recipients only, bounded retry, no financial rollback/replay.                                                                                                                                                                                |
| FEE-AC-045 | FEE-FR-034                         | Verify committed/denied/conflicting/corrected events capture protected accurate metadata; append-only detailed audit requires `audit.view`, export separately gated, no secrets/raw failed identity leak.                                                                                                                                                                |
| FEE-AC-046 | FEE-FR-035                         | Unsupported percentage-first/stacked fees, penalties, unsupported early-completion policy, destination/payout authority or unavailable accounting owner remains Blocked; no invented role/capability.                                                                                                                                                                    |
| FEE-AC-047 | FEE-FR-025, FEE-FR-030             | Race two business draws; one cannot exceed net earned-undrawn or conservatively free remitted cash. Customer payout reservations inside liability are not double-subtracted; original Agent receivables are not cash backing. Linked business-only correction requires original evidence and actual returned funds when applicable, with no Customer reversal authority. |
| FEE-AC-048 | FEE-FR-036, FEE-FR-009, FEE-FR-010 | Terminate incomplete funded cycle: full agreed fixed/one-day or percentage on net posted principal before withdrawals is quoted once; zero-activity cancellation has no fee. Prior paid/waived fee never reappears; negative/zero net payout quote is rejected without silent cap/pro-rata; insufficient fee settlement blocks final closure.                            |

Required fixtures include baseline and split-grant Admins; Active/Inactive/Suspended/offboarding Agents; assigned/reassigned Customers in all statuses and invited/activated accounts; explicit zero/missing/changed fee versions; partially settled/waived/refunded obligations; first/completion/withdrawal fee cases; posted/reversed/held receipts; sufficient/insufficient/reserved balances; missing integrations; and simultaneous/unknown-outcome mutation attempts.

## 17. Proposed decisions requiring confirmation

1. NGN/kobo, entry maximum, basis-point range, half-up rounding, and the distinction between product fee recognition and statutory accounting.
2. Recognition only on successful external settlement/savings application; no receivable revenue at assessment/acknowledgement.
3. Supported timing/model combinations, once-per-cycle fixed/one-day fees, withdrawal percentage gross basis, and early-settlement fee disposition.
4. Full-only automatic savings application, no separate fee holds, no automatic registration-fee netting, and Inactive existing-obligation receipt exception.
5. Approved purposes/destinations for manual fees and other deductions; fresh Admin authentication for sensitive actions.
6. Waiver policy; valid-charge refund entitlement authority under `fees.manage` versus Agent-initiated erroneous-entry reversal; no automatic reinstatement.
7. Business ownership of fee earnings; Admin business-draw recording under `fees.manage`, conservative cash backing, business-only correction policy, and separately owned external refund/bank execution authority.
8. Approved counter-accounts, reconciliation cash responsibility, final payout/reservation/reversal interfaces, timezone/retention/export policy, and notification defaults.

These choices may be revised before implementation. Record a decision and update all affected module contracts/scenarios together; a policy change must not silently rewrite already-agreed Customer terms or posted financial history.
