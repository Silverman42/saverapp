# Collections, Digital Thrift Card, and Reconciliation

**Product version:** 2.0  
**Module:** 07  
**Module status:** Detailed draft for review  
**Sources:** [PRD](../../PRD.md), [User Types](./01-user-types.md), [Authentication](./02-authentication.md), [Roles and Permissions](./03-roles-and-permissions.md), [Customer and Agent Management](./04-customer-and-agent-management.md)  
**Dependencies:** [Fees and Deductions](./05-fees-and-deductions.md), [Thrift Plans and Savings Cycles](./06-thrift-plans-and-savings-cycles.md)

## 1. Purpose and specification status

This module defines how an eligible assigned Agent records money actually received, how those receipts fund a Customer's dated thrift slots, and how the business reconciles Agent-held money and non-cash receipts. It defines the accounting and correction contracts required before collections can be enabled.

The PRD establishes contribution recording, a digital thrift card, partial/multiple-day/advance payments, missed days, daily collection views, mathematical balance integrity, and traceable reversals. Modules 01–04 establish Agent-only collection recording, current assignment scope, Admin-only reversal review, operational eligibility, immediate access changes, and retained attribution. These are inherited requirements. Detailed allocation, date, evidence, batch, reconciliation, performance, and correction policies below are **proposed product decisions** unless explicitly inherited. They are reviewable requirements, not claims of implemented software or completed tests.

An Agent recording a receipt is attesting that the stated money was received through the stated method. A recorded contribution creates a Customer savings liability; it is not a plan estimate, a bank-confirmed guarantee, business income, or proof that an Agent has remitted the funds.

## 2. Initial scope and exclusions

Initial scope includes online individual receipt recording for daily plans; partial, catch-up, multiple-slot and advance allocation; separate fee-payment tender allocation; digital thrift cards; daily workspaces and summaries; immutable accounting references; system-created collection batches; Admin reconciliation and exception records; and contribution-reversal requests with one authorized Admin review.

| Included | Deferred or owned elsewhere |
| --- | --- |
| Cash, manually evidenced bank transfer/POS, and configured Other methods with explicit custody mapping | Payment gateways, automatic bank feeds, automatic transfer verification, chargeback integration |
| Current-day receipts and explicitly identified late-recorded past receipts | Future-dated receipts, arbitrary backdated ledger periods, opening-balance migration |
| One receipt with one or more explicit savings/fee components for one Customer | Multi-Customer bulk posting, bulk import, offline financial queue, cross-currency receipts |
| System-created daily Agent/method batches, remittance confirmation, exceptions and settlement evidence | Agent financial submission/edit authority without a reviewed Module 03 baseline change |
| Full reversal of an eligible original receipt and its required dependent compensation | Partial receipt reversals, independent adjustments, bad-debt write-offs, fee refunds or external payouts without owning authority |
| Read-only withdrawal/reservation integration in balances and summaries | Withdrawal approval, reservation creation, cash payout and execution mechanics: future Withdrawals module |
| Event/notification requirements and operational receipts | Detailed retention, reveal/export policy and dispute resolution: owning Audit, Reporting and Notifications modules |

A deferred feature has no fallback endpoint or manual balance override. A missing prerequisite leaves the relevant action unavailable with a clear explanation. Agent suspension remains available through Module 04 even while financial exceptions are unresolved.

## 3. Terms and ownership

| Term | Meaning and owner |
| --- | --- |
| Receipt | Immutable money-received event, recording Agent, Customer, tender components, receipt date and posting references; this module owns collection recording. |
| Contribution | Savings portion of a posted receipt that increases the Customer liability; excludes separately collected fees. |
| Allocation | Link from a live contribution amount to an immutable plan slot; this module owns funding allocation, Module 06 owns slot terms. |
| Slot | Dated scheduled contribution expectation with immutable ID/target and versioned terms from Module 06. |
| Thrift card | Projection of slot terms, live allocations, annotations and eligibility intervals; never the accounting source of truth. |
| Remittance | Transfer of previously recorded Agent-held money into business custody; not a second Customer contribution. |
| Reconciliation | Comparison of immutable recorded amounts, remittances and verified evidence; does not rewrite contribution balances. |
| Agent receivable | Amount due from a recording Agent for money entrusted to them; separate from Customer savings liability and fee earnings. |
| Posted | Receipt and balanced accounting committed durably; independent of reconciliation outcome. |
| Reversed | Original remains readable; one approved linked compensating posting removes its live effect. |

Module 05 owns fee assessment, obligation balances, recognition, payments and savings deductions. Module 06 owns schedule generation, daily targets, lifecycle and completion/closure gates. This module consumes those contracts and cannot invent fee charges, change plan terms or grant permissions.

## 4. Role, assignment and status eligibility

### 4.1 Authority matrix

| Action | Customer | Agent | Admin |
| --- | --- | --- | --- |
| Record a savings collection or external fee receipt | No | Current assigned eligible Agent only | Prohibited, including with `reconciliation.manage` or `fees.manage` |
| View Customer card, receipts and balance | Own records only | Currently assigned Customers | Business-wide baseline read access |
| Mark an eligible unfunded slot missed/skipped | No | Current assigned eligible Agent under Section 8 | No collection annotation mutation introduced here |
| Initiate receipt reversal | No | Accessible currently assigned Customer transaction | Prohibited; review does not imply initiation |
| Approve/reject reversal | No | No | One authorized Admin with `reversals.review`, irrespective of value |
| View Agent reconciliation | No business settlement details | Own scoped summaries only | Baseline business read summaries; protected evidence follows Section 12 |
| Record remittance evidence, reconcile, resolve exception | No | No new mutation capability | `reconciliation.manage` through this module's workflow |
| Export business or multiple-Customer reports | No | No new export grant | `reports.export`; separate audit access remains required |

Module 03 grants Agents **view own reconciliation status**, not reconciliation-management authority. System-created batches are the default collection-submission source. An Admin with `reconciliation.manage` may record evidence received from the Agent through an authorized external process, preserving the source and entering actor separately. A proposed Agent attachment/submission feature requires an explicit reviewed Module 03 capability extension before enablement; this module does not silently grant it. Read-only access must not be presented as a submit button.

### 4.2 Savings collection eligibility

At commit the recording Agent must have completed account activation and required MFA, a current session/request permitted by Authentication, Active operational status, current assignment to the Customer, and no applicable access restriction. Module 04's temporary-lock exception remains intact: an authentication-path lock does not invalidate a legitimate existing session unless Authentication separately revokes it. Such an operationally Active Agent may continue permitted existing assigned-Customer collections through that valid session, but cannot receive a new or reassigned Customer while marked Temporarily locked. Invited, MFA-setup, Suspended and Deactivated Agents cannot record receipts. The Customer must be operationally Active; their account may still be Invited because login activation is independent of Agent operations under Module 04. Inactive, Restricted and Archived Customers cannot receive new savings contributions, including catch-up and advance receipts.

The plan must permit contributions under Module 06 and expose allocatable slot capacity. Paused, Completed, Closed or Cancelled plans cannot bypass their lifecycle rules through the collection endpoint. A suspended Customer login does not by itself change their operational eligibility; a security hold explicitly applicable to staff financial actions must still be enforced. The interface must show these states separately.

Previously posted receipts can be reconciled while the Customer is Inactive or Restricted and the recording Agent is Inactive/Suspended. Corrective reversals remain eligible for Active, Inactive and Restricted Customers under current assignment and review safeguards; Archived Customers require restoration before corrective posting. Fee-only collection follows Module 05: an eligible current Agent may settle an existing agreed fee obligation for an Inactive Customer; Restricted and Archived Customers remain blocked. An Inactive Customer cannot receive a combined receipt containing any savings component. Agent inactivity prevents even fee-only receipt recording.

## 5. Receipt fields and validation

| Field | Required and validation |
| --- | --- |
| Customer and plan references | Customer required; savings component requires one eligible plan belonging to that Customer. Fee-only receipt uses the named fee obligation instead of fictitious plan slots. |
| Amounts and currency | NGN, positive integer kobo; proposed maximum receipt/tender and individual component value 999,999,999,999 kobo, consistent with Module 05. User entry accepts at most two decimal places and converts exactly. Module 06's per-slot target range and finite residual plan capacity still constrain savings allocations. Reject zero, negative, non-finite, above-cap, overflow, exponent-form API values and unsupported currencies. Cumulative totals use overflow-safe integer arithmetic without truncating to the single-entry cap. No floating-point financial arithmetic. |
| Tender total | Must equal savings component plus external fee-payment components exactly. Savings deductions after posting are separate ledger actions, not a second tender receipt. |
| Payment method | Required proposed choice: Cash, Bank transfer, POS or Other configured method. Method must resolve to an approved custody/asset/clearing account; Other requires a 1–100-character description. |
| Received date | Required business calendar date, default today. Proposed initial lookback: today and the prior 30 local calendar dates in the captured receipt business timezone. No future date. A past date requires a 1–500-character late-recording reason and must pass supported/open period controls; lookback does not override a closed period. |
| Non-cash reference | Required 1–150-character normalized provider/bank reference for transfer/POS and a declared destination/custody mapping. Never collect card numbers, CVV, PIN, login credentials or unrelated bank-statement history. |
| Non-cash evidence | Required attestation of receipt plus permitted evidence reference under Section 12. Pending or merely promised transfer cannot be marked received. |
| Slot allocation | Required for all savings kobo; positive allocations must equal the savings component, not exceed slot residual capacity or cross Customer/plan. |
| Fee allocations | Required for fee components; each references a payable obligation/version and respects Module 05 remaining amount/status. |
| Notes | Optional, trimmed, at most 500 characters. Customer-visible receipt description and private reconciliation notes are separate; never expose internal investigation notes through receipt descriptions. |
| Immutable system fields | Receipt/public transaction references, actor, current assignment reference, UTC recorded-at, business timezone/date, policy versions, tender mapping, posting group, request key, audit reference. Cannot be client-overwritten. |

The proposed receipt/tender cap is 999,999,999,999 kobo; any smaller operational cap must be explicit, versioned and displayed. Proposed initial late-recording lookback is today and the prior 30 local calendar dates, always subject to authoritative open-period controls. Method mappings, timezone and those period controls must exist before enablement; an undefined period service is not an unlimited default. Proposed receipt references follow the PRD's human-readable transaction reference pattern, with a server unique key independent of presentation date and non-reusable references across reversals.

The preview shows Customer, plan, received date, received amount by method, savings and fee split, slot targets/residuals, any immediate agreed fee application, liability change, available-savings result, and a confirmation statement. If any material input, allocation, fee policy or eligibility changes after preview, reload and reconfirm instead of silently posting a different result. Routine collections require the usable Agent session, not a new password/MFA challenge per receipt.

## 6. Dates and period semantics

Use the captured business timezone for receipt received-date summaries, posting-date reports and batch membership; proposed initial setting is Africa/Lagos. Slot due-day boundaries, card today/past/future status and daily schedule interpretation use the immutable Module 06 plan timezone. For each allocation, compare the receipt's actual received instant, or explicitly retained received local-date/time and timezone basis, with the slot boundary in its plan timezone to derive an advance tag; never compare raw date strings from different zones. If date-only evidence cannot resolve a cross-zone boundary, flag it for clarification instead of guessing an advance/missed label. Record server UTC `recorded_at` independently and retain receipt timezone/configuration and plan timezone references. A slot date is an expectation date; it need not equal the receipt's received date. An advance receipt is received now and allocated to future slots, never recorded as future money received.

Late recording of money received yesterday retains yesterday as `received_date` in its captured receipt business timezone and today as `recorded_at`. It is visible in the original received-date actuals and as a late-recorded item in today's posting activity. The plan timezone independently determines whether the allocation was advance/on-time/catch-up and whether a due slot is past. Record the Agent acting now and their current assignment; a past received date never restores a previous assignment or attributes a receipt to an unavailable Agent. A newly assigned Agent must not post money received by the former Agent as their own receipt: raise a reconciliation/correction investigation for authorized resolution, retaining who actually held the money.

Initial scope excludes locked financial-period edits. If a past receipt cannot be recorded in its real period, do not falsely substitute today as the received date; expose the owning adjustment/escalation path as unavailable until defined. Business timezone changes do not redetermine existing plan timezone, slot dates, due-day interpretation, recorded receipt dates, batch membership or historical reports; subsequent receipts capture the new business timezone without moving existing plan slots. A date-range report labels plan-slot-date, receipt received-date and recorded-at bases and timezone references clearly. The current workspace computes due slots at the current instant in each plan's own timezone, even if different from the current receipt/batch timezone.

## 7. Savings allocation rules

### 7.1 Allocation algorithm and preview

Proposed default: allocate savings to the oldest unfilled eligible slots in the chosen plan, completing partial slots first, then continuing through later slots, including future slots when money remains. The Agent may choose a different explicit allocation within eligible residual capacities after reviewing the dates and amounts. Validate the active schedule revision and slot version: superseded/removed slots cannot receive allocations, and IDs are never reused. Module 06 permits pre-activity revision with retained history; after any financial activity, including reversed activity, contribution terms are locked. No future slot outside the generated schedule may be fabricated. Every kobo must be allocated; unallocated overpayment and cross-plan automatic spillover are deferred.

| Pattern | Result |
| --- | --- |
| Normal daily payment | Funds one target slot; receipt count and funded-slot count each increase by one. |
| Partial | Funds less than the target; later receipts may fill exactly the residual. No penalty or balance reduction occurs merely because partial. |
| Multiple-day | One receipt funds several slots; receipt count increases by one, fully funded-slot count by the number completed. |
| Advance | Receipt received now funds future dated slots; cash/savings recognized now, future expectation covered. |
| Catch-up | Late receipt funds an earlier missed/partial/skipped slot; retains earlier slot date and actual receipt dates. |
| Exceeds remaining plan capacity | Reject before posting; propose a smaller savings component or separate payable fee component, never silently convert excess to fees. |

Preserve allocation IDs, original receipt, slot IDs, amounts and timestamps. No receipt-edit or drag-and-drop card interaction may move posted money between slots. An incorrect allocation follows the reviewed reversal/re-recording process, subject to downstream dependency checks. A receipt may fund only one plan in initial scope; separate plan receipts remain separately confirmed operations.

### 7.2 Plan completion contract

Module 06 receives the net live funded amount per slot, fully funded-slot count and unresolved correction state. Completing a plan depends on fully funded required slots, not the number of receipts or passage of its final date. Financial posting and an resulting completion transition must be consistent; no Completed display while allocation commit failed. Completion is distinct from financial settlement and closure.

A pause or Customer/Agent unavailability preserves schedule IDs, dates and funding. Eligible catch-up after resume may use original slots; unavailability never extends a schedule automatically. Under Module 06's proposed correction contract, a reversal that makes a Completed plan underfunded moves it to Paused with a linked correction event and retained completion history. An eligible assigned Agent must explicitly resume it for an Active Customer before another collection. A Closed plan retains Closed with a linked unresolved correction exception; no automatic reopening, successor-plan edit or fabricated completion is allowed. If a required correction contract is missing, block the affected correction rather than make inconsistent lifecycle records.

## 8. Thrift-card projection and attendance annotations

### 8.1 Slot display

| Primary display | Rule |
| --- | --- |
| Paid | Net live allocated amount equals target; never more than target. |
| Partial | Net amount is positive and below target, regardless of slot date; show exact residual and overdue indicator where applicable. |
| Pending | No live funding for a current/future eligible slot, or an explicit same-day pending annotation. |
| Missed | Past unfunded slot that was eligible for collection at its due time; an eligible Agent may also explicitly mark a due-day slot missed with a reason. |
| Skipped | Explicit Agent annotation on an unfunded eligible due/past slot with a reason; not a monetary transaction, waived target or funded day. |
| Blocked/paused expectation | Separate overlay indicating an eligibility or plan-pause interval; no automatic missed/skip label solely because work was blocked. |

Paid/Partial funding takes precedence over attendance annotations; preserve previous annotation history when catch-up replaces the display. Advance is a secondary tag on funding allocated before the slot date, not a second financial state. Reversed funding is removed from net funding and linked visibly in transaction history; the original receipt never disappears.

Initial annotations are assigned eligible Agent actions on an Active Customer/collectible plan. Reject skipping Paid/Partial slots, changing future slots to missed/skipped, or editing annotations outside current scope. Corrections append annotation history with reason and version; no accounting entries are created. Skipped slots remain eligible for catch-up and count as unfunded toward the fixed plan target. Skipping cannot complete or forgive a plan; permanent schedule changes belong to Module 06 and must satisfy its constraints.

### 8.2 Card totals and explanations

Show Customer, plan, daily target, start/end dates, plan/status overlays, slot dates/amounts, funded-slot count, remaining unfunded slots, gross posted contributions, reversals, net plan contributions, fees/deductions and linked transactions. Distinguish net plan contribution total from Customer lifetime savings liability and available savings; withdrawals may reduce savings without unfunding already paid slots. Fee receipt amounts never fund thrift slots. Do not show estimated future contributions as existing funds.

Views require accessible text labels and amount/date descriptions in addition to color. Tapping a slot reveals permitted linked receipts and allocations. Card regeneration from authoritative schedules and live allocations must produce the same result; a stale projection shows its as-of/version indicator and cannot authorize new funding.

## 9. Accounting, balances and atomic posting

### 9.1 Required posting contract

Before enablement a durable ledger service must support immutable balanced posting groups, unique posting keys, integer-kobo accounts, atomic receipt/allocation/fee application, durable audit and replay-safe result lookup. The account mapping, payout reservation service, correction bundle and period controls must be specified and verified. A page with computed totals is not sufficient evidence that these dependencies exist.

| Event | Required effect; conceptual debit/credit |
| --- | --- |
| Cash savings receipt | Debit recording Agent receivable; credit Customer savings liability for the gross savings component. |
| Evidenced transfer directly to business bank | Debit mapped business bank/clearing asset; credit Customer savings liability. Verification status stays separate; mapping/evidence must support recording as received. |
| Receipt to Agent-controlled approved destination | Debit that Agent's custody receivable; credit Customer liability. Do not treat it as already business-held cash. |
| External fee payment | Debit mapped custody/asset for the fee component; apply obligation/recognition entries from Module 05. No Customer savings credit. |
| Authorized fee savings application | Separate linked debit of Customer liability and Module 05 fee entries; gross contribution and fee remain individually visible. |
| Confirmed cash remittance | Debit mapped business custody asset; credit original Agent receivable; Customer liability unchanged. |
| Receipt reversal | Balanced compensation linked to original entries and dependent authorized bundle; preserve the original and recompute live allocations. |

Every posting group's debits equal credits in NGN. Asset/Agent-custody totals, Customer liability, revenue, fee obligation, earnings and earnings payouts remain separately named. Bank transfer/POS settlement fees must not reduce a Customer's gross credited amount silently; processor fees require a separately approved business-expense policy, otherwise that method cannot be enabled with such deductions.

### 9.2 Balance and reservation contract

`Customer savings liability = net posted savings contributions − net posted Customer cash payouts − net authorized savings deductions`, with linked compensations included in each net amount. Here a Customer cash payout means actual net cash P, and savings deductions include the withdrawal's fee F and any separately authorized included deductions: P plus those deductions equals gross Customer savings debit G. Never subtract gross G and then subtract its included F again. External fee payments, plan estimates, misses/skips, unsubmitted drafts, Agent shortages and batch status changes do not enter this formula.

`Available savings = Customer savings liability − live payout reservations`. Each withdrawal reservation covers its gross Customer savings debit G, including an agreed withdrawal fee F; net Customer cash payout P equals G minus F and any other separately authorized included deductions. Completion debits Customer liability G once, credits payout custody P and the separately recognized fee/deduction accounts, and releases the same gross reservation atomically. Never deduct F again from Customer liability or create an additional fee hold. Pending withdrawal approval alone must not invent another hold; the Withdrawals owner defines when a reservation is created/released/consumed. No unspecified registration-fee hold, anticipated fee reserve, negative Customer savings or silent fee deduction is permitted. Unpaid fees remain separately displayed under Module 05. A required full fee application that cannot fit available savings stays outstanding under Module 05's rule, rather than reducing savings below zero.

This module must query authoritative liability and reservations consistently for previews and commit checks. All liability-decreasing corrections/deductions coordinate with reservation/payout changes. Business total Customer liability includes outstanding savings for every operational status; an inactive list filter cannot hide debts. A ledger/projection mismatch blocks affected financial writes, creates an investigation and shows stale/unavailable data without substituting zero.

### 9.3 Commit sequence

Validate current role/scope/status, method, period, idempotency key, policy and versions; confirm exact allocations and tender split; prepare balanced contribution and applicable Module 05 entries; commit receipt, allocations, ledger, affected lifecycle projections, batch membership, audit and durable notification work together or through an equivalently atomic recoverable protocol. Return success only after the authoritative result is durable. A separate search/cache/notification outage may delay display/delivery but cannot create a partial financial result or repeat receipt posting.

If mandatory fee assessment/application cannot be persisted consistently, abort the receipt; if its agreed full application is unaffordable, commit the gross contribution and the explicit outstanding obligation according to Module 05. An unavailable fee service is not the same as unaffordable savings. Final receipt displays the actual bundle and changes, not just its initial preview.

## 10. Duplicate prevention, failures and concurrency

The client creates one stable request key per confirmed attempt. The server binds it to recording actor, Customer, plan, normalized tender/allocations and action. Replaying the same key/payload returns the original authorized result without another receipt, fee application, batch entry or notification. Same key with a different payload conflicts. Retain durable financial posting-key uniqueness even after request-cache expiration; keys are not reusable after reversal.

Do not silently merge legitimate identical cash receipts by Customer/amount/day. Distinct confirmed keys may represent distinct payments, subject to capacity checks. Warn about close apparent duplicates. Non-cash method/provider/destination/reference collisions trigger a safe investigation/conflict check; provider IDs have a defined namespace, and arbitrary reference normalization must not incorrectly equate distinct transfers. Uniqueness applies to the underlying transfer, not independently per Customer: two Customers cannot each be credited with the same transfer's full amount. Multi-Customer transfer splitting is deferred; any future implementation must verify the actual transfer once and atomically allocate no more than its amount across all linked receipts.

| Failure/race | Required result |
| --- | --- |
| Validation, permission or status failure | No committed receipt/fee/slot/batch effect; safe specific explanation within actor scope. |
| Lost response after possible commit | Show unresolved submission, retrieve by original request key and re-check retrieval scope; never prompt a new key until the outcome is resolved. |
| Server failure before commit | No partial receipt or successful notice; retry same key after safe result check. |
| Concurrent funding of same residual | Serialize/revalidate slot capacities; one succeeds or both fit, never overfund. |
| Reassignment/status/pause first | Old Agent or blocked-plan submission fails without posting. |
| Receipt first | Preserve recording Agent and amounts; later handover/lifecycle uses committed state. |
| Concurrent archival | Customer/financial gate coordination prevents a receipt entering after archival checks. |
| Concurrent deduction/reversal/reservation | Current liability/available checks prevent negative savings and double spending. |
| Offline/lost connectivity | No financial success, offline queue or locally paid card; unresolved online attempts keep same key and disclose unknown outcome. |

Local draft storage, if provided, excludes raw evidence/secrets and carries no posted meaning. A replayed background operation cannot substitute another Agent's identity. A receipt draft is not a cash liability record or valid printed confirmation.

## 11. Daily collection workspace and summary calculations

### 11.1 Operational list

The Agent's default workspace shows currently assigned eligible Customers with today's collectible daily slots, expected target, net funded amount, residual, slot/card status, plan and an immediate record-payment action. Search by permitted Customer name/ID; filter paid, partial, pending, missed, advance-covered and blocked work. Proposed sort: actionable outstanding first, then Customer name and ID; stable server pagination, default 25 rows, with totals independent of loaded pages.

A separate read-only blocked-work filter shows Customer inactivity/restriction, plan pause or Agent unavailability without treating blocked items as actionable missed collections. Already advance-funded slots remain visible with zero outstanding. Today's newly recorded catch-up/advance receipts appear in activity even when their allocated slots are not today. Admins can inspect the business workspace and filter recording/current Agent, but cannot enter a collection.

### 11.2 Required metrics and date basis

| Metric | Calculation and qualifier |
| --- | --- |
| Scheduled target | Sum of plan targets for dated slots; show eligible actionable target separately from blocked targets. |
| Covered due amount | Sum of net live allocations toward that day's eligible slots, including earlier advance receipts, capped per slot. |
| Outstanding due | Eligible target minus covered due; skipped remains unfunded and separately labelled. |
| Money received that day | Receipt savings/fee tender received on that date, split by method and component. Show gross receipts, reversals and net separately. |
| Posting activity | Recorded-at date receipts and late-recorded events; never add again to received-date totals. |
| Receipt count | Count posted receipts, with reversed count separate; distinct from funded-slot/Customer count. |
| Missed/partial/paid Customers | Distinct Customers for the selected slot-date scope with explicit status-count rules. Blocked and skipped are separate counts. |
| Cash held/remitted/outstanding | Ledger-derived original-Agent cash receivable, confirmed remittances and unresolved balance; not Customer available savings. |
| Withdrawals and fee income | Read-only owning-module posted amounts, reservation exclusions and stated date basis; external fee receipts distinct from savings deductions. |

Do not calculate shortfall as all money received today minus today's expected target: advance/catch-up and fee payments make them incomparable. Historical reports retain assignment/status intervals and label scheduled, eligible and received-date bases. Current eligibility totals may change after status transitions; dated finalized snapshots retain their as-of/version and correction history instead of silently losing previously collected money.

## 12. Payment evidence, batches and remittance

### 12.1 Evidence protection

Proposed attachments: JPEG, PNG, WebP or PDF, maximum 5 MB each and three files per evidence record; validate actual file type, scan, prohibit executable/encrypted unsupported content, and store outside public access. Require method reference, received date, amount, destination and source attestation even if an attachment is supplied. A screenshot is supporting evidence, not independent bank confirmation.

The recording Agent may supply the receipt's permitted payment evidence as part of their existing collection-recording capability. Separate remittance/reconciliation evidence is entered by authorized Admins unless the explicit Agent baseline extension in Section 4 is approved. Record submitter/source, received-at, entering actor and checksum/version. Evidence supplements append rather than rewrite original files or values. Failed scan/required upload blocks posting instead of issuing a receipt with nonexistent evidence.

Customers view their own receipt amount, method, safe reference and savings/fee split; raw remittance documents, private allegations and unrelated statement lines are excluded. Current Agents may view Customer receipt evidence needed for their assignment; former Agents receive masked own settlement summaries without Customer links. Raw settlement evidence requires `reconciliation.manage`; detailed audit requires `audit.view`. Downloads use short-lived authorization and current scope, not public URLs. Retention/deletion policy is owned by Audit/Reporting; no application delete of financial evidence history is introduced here.

### 12.2 System-created collection batches

Create deterministic batches by recording Agent, received business date, currency, method/custody mapping and batch revision. Posted receipt membership is unique; batches show gross savings/fee components, reversed/net amounts, custody target and included transaction references. Batch receipt membership and actor cannot be changed to make totals match remitted money.

At the configured day boundary, freeze a versioned system submission for Admin review. Receipts late-recorded for that date create a supplemental revision/batch linked to the original, not an invisible edit of a finalized batch. Existing confirmation remains historically true for its version; new amounts require new reconciliation. The day-boundary/timezone configuration and delayed-job recovery must be defined before batch enablement.

Batch operational states proposed: **Open → Ready for review → In review → Reconciled**, with **Exception** from review and **Reopened** for new evidence on a previously reconciled version. These are workflow labels, not contribution posting states. The system performs Open → Ready; an authorized reconciliation Admin starts review and records reason/evidence for outcomes. Exception resolution can return to In review; Reopened creates a new review event/version while retaining the original. No state transition can make an unpaid receivable vanish.

### 12.3 Remittance entry and custody

An Admin with `reconciliation.manage` records evidence of money transferred from the recording Agent to the business: original Agent, amount in kobo, receipt date, method/destination, reference, confirmed source, supporting evidence and linked batch allocation. Preview confirmed custody transfer and remaining Agent receivable; confirm explicitly and re-check permission, versions, amount and non-duplicate reference at commit.

Proposed remittance states: **Evidence recorded**, **Confirmed**, **Rejected**. Only confirmation with adequate verification posts custody transfer. Draft evidence or a promise does not reduce Agent receivable. Partial remittances are allowed; allocate each confirmed remittance once across compatible Agent/currency/custody batch balances. It cannot settle another Agent's debt automatically or exceed the remaining amount attributed without a separately identified overage exception.

No netting of cash remittance against an Agent's fee earnings or an unposted Customer withdrawal is allowed. Payout cash issued to an Agent requires a separately authorized payout accounting contract; until defined, reconciliation cannot invent a cash credit for it. A direct business-bank receipt needs confirmation against that asset/clearing mapping, not a fictitious Agent cash remittance. Method/mapping correction requires a separately reviewed compensation, not editing a batch method.

## 13. Reconciliation and exceptions

### 13.1 Review contract

For each version, compare net posted receipts with method-specific verified custody/settlement evidence and confirmed remittances. Cash expected from an Agent includes separate savings and collected-fee components because both were received, but they remain distinct accounting liabilities/income. Transfers/POS reconcile to destination/reference/clearing amounts; awaiting settlement remains an open item, not cash already submitted.

An authorized reviewer records expected, verified/remitted, outstanding and variance amounts, evidence, outcome, date, actor, reason and version. Proposed closure gate: all included receipts matched to permitted method evidence, all cash custody amounts confirmed, no unexplained variance, no pending correction that changes the batch, and no unresolved linked exception. Matching difference tolerance is zero kobo initially. A reviewer cannot close by accepting an unexplained shortage within a guessed tolerance.

| Exception | Required handling |
| --- | --- |
| Cash shortage | Retain full original Agent receivable and full Customer savings credit; record shortage case and recovery/remittance work. No Customer fee or savings reduction. |
| Cash overage | Record separately identified business custody/suspense and investigation under an approved account contract; do not assign to a Customer or recognize fee income without an authorized matched receipt. If suspense posting authority is undefined, keep evidence unresolved and block settlement. |
| Missing/duplicate transfer reference | Preserve posted contribution; investigate evidence and request an eligible correction if original recording was erroneous. |
| Failed/returned non-cash payment | Exception with original receipt intact; correction requires approved reversal, not reconciliation status change. |
| Misallocated/incorrect receipt | Current assigned Agent initiates linked reversal under Section 14; reconciliation permission cannot post correction. |
| Late-recorded payment or new evidence | Supplemental batch/reopened version, appended evidence and new review; retained previous closure. |
| Agent unavailable/offboarding | Authorized staff continue evidence review; original Agent responsibility preserved and unresolved gate reported to Module 04. |

### 13.2 Exception lifecycle and resolution

Proposed exception states: **Open → Investigating → Awaiting action → Resolved**, with **Reopened** when new conflicting evidence arrives. Each change requires `reconciliation.manage`, reason, current version and durable history. Resolution specifies the actual cause, verified remittance/match, or separately approved correction references; it cannot be a label-only financial settlement. A case may be administratively resolved while a separately identified receivable remains, but the batch/offboarding settlement gate remains unsatisfied until the receivable is settled through an explicitly authorized financial workflow.

Bad-debt forgiveness, receivable write-off, manual Customer adjustment and independent suspense-to-income transfer are deferred because they need explicit accounting and authority policies. Missing policy is a Blocked resolution, not permission to use `reconciliation.manage` as general money authority. Agent performance shows recorded, remitted, outstanding and exception amounts separately; it must not rank reconciliation success by deleting problem receipts.

## 14. Receipt reversal and correction boundaries

### 14.1 Initiation and review

An eligible Agent may request a full reversal of an accessible original receipt for their currently assigned Customer, including a receipt originally recorded by another Agent. Require original reference, 1–500-character reason, current effect summary and supporting correction evidence. Retain original initiator and recording actor separately from current follow-up owner. Propose one live reversal request per original receipt; repeated identical initiation is idempotent and competing requests conflict.

Proposed request states: **Pending review**, **Rejected**, **Cancelled**, **Approved and posted**. Approval and compensation post as one atomic action in initial scope; there is no display of approved money correction before durable posting. The requester may cancel only while pending and still authorized. One Admin with `reversals.review` can approve/reject any value; require explicit confirmation and proposed fresh password-and-MFA authentication using Authentication's 10-minute freshness mechanics. An Agent never approves; an Admin never fabricates an Agent request.

### 14.2 Compensation eligibility

Approval revalidates original live effect, Customer non-archival, current task ownership, reservations, liability, plan lifecycle, fee dependencies, prior compensation, Agent custody and period rules. A later reassignment or the initial Agent's suspension does not itself cancel a legitimately initiated request; Module 04 transfers service responsibility. The Admin must reload stale previews. Financial holds do not prohibit eligible corrective reversal for Restricted Customers.

Full compensation removes original live slot funding, reverses original receipt entries and atomically compensates directly dependent fee/recognition/application entries that must be undone to make the original reversal valid. The review must show every affected amount, fee obligation, custody account and resulting savings/reservations. One `reversals.review` approval may authorize only this defined original-transaction compensation bundle; it does not grant independent fee waivers (`fees.manage`), new deductions (`deductions.manage`), refunds/payouts, or reconciliation actions.

Settled Customer payouts, live reservations, withdrawn fee earnings, closed-plan correction constraints, returned tender, already remitted cash and period locks can make a proposed bundle unsafe. No negative Customer savings/available amount or unjustified credit to the original Agent is permitted. When money was already remitted, reversing an erroneous receipt is not evidence that cash was paid back: the ledger/custody compensation must follow an explicit approved contract, retaining actual business-held money and any return obligation separately. If that contract or dependent fee-earnings compensation is undefined, block approval and expose the dependency; never edit remittance history to force the bundle to balance.

Rejected/cancelled/failed requests have no accounting effect. Posted reversals preserve original receipt, audit, reason, reviewer, time, compensation references, original received date and actual correction date. Receipt history and statements distinguish gross original receipt, reversal and net totals. A correct replacement receipt requires new confirmation and current Agent eligibility; it is not automatically replayed or backdated. Partial reversals, fee refunds and independent financial adjustments remain owning-module decisions.

## 15. Reassignment, lifecycle and historical integrity

Customer reassignment transfers current collection service, open Customer-side reversal follow-up and permitted views to the new Agent while retaining previous transaction attribution, allocations, balances and original Agent custody debt. The previous Agent loses Customer-linked receipt/evidence/card/notification access immediately; masked own reconciliation totals may remain under Module 03/04. Record **current service Agent** separately from **recording Agent** in reports. No backdating of assignment may move money between Agents.

Agent inactivity/suspension/offboarding blocks new Agent collections. Existing batches, receivables and exceptions remain actionable to authorized Admins; the Agent's consent or login is not required for review. Module 04's deactivation gate queries authoritative unresolved custody, reconciliation and correction work; any unavailable check fails closed. Formal handover of follow-up never forgives the original Agent's shortage.

Customer archival queries settled liability/reservations, plans, fees, pending corrections and Customer-linked reconciliation issues. Purely unrelated Agent exceptions must not block an otherwise settled Customer, but an unresolved exception tied to that Customer is a blocker. Archived records are read-only; later discovered receipt errors create linked investigation and restoration to Inactive before corrective posting. Restoration does not replay collection drafts or reverse history.

## 16. Screen and interaction requirements

| Surface | Required contents and actions |
| --- | --- |
| Agent record-payment form | Current Customer/plan, target/residual suggestions, exact savings/fee split, method/date/evidence, allocation preview, confirmation and durable receipt/unknown-outcome status. |
| Customer/Agent thrift card | Accessible dated grid/list, primary funding and secondary advance/blocked tags, drill-down and distinct plan versus lifetime/available balances. Customer has no financial mutation action. |
| Agent activity | Received-date/recorded-at filters, own totals, distinct reversed transactions and masked past-customer settlement aggregates. |
| Admin collections | Business read-only collection filters, original/current Agent distinction, linked fee/ledger views; no record-payment control. |
| Admin reconciliation | Versioned queue, batch totals, protected evidence, remittance confirmation, exception work, outstanding custody and permission-gated review actions. |
| Reversal review | Original/compensation bundle, reasons/evidence, resulting balances/reservations, dependent blocks and permission/fresh-auth checks. |
| Receipt detail | Durable unique reference, actor, method, received/recorded dates, amounts/allocations, fees, posting/reversal and reconciliation states clearly separated. |

Authorized Agents can open the same validated record-payment form from their dashboard, the assigned Customer profile, daily collection list and thrift card. Preselect the currently permitted Customer/plan where context exists, then load live eligibility, residuals and policy. An entry point, saved URL or previous preview cannot bypass the same server-side validation/confirmation requirements.

Provide loading, empty, blocked and retryable error states. Unavailable financial data is labelled unavailable/stale with last successful timestamp, never zero. A lost response says outcome unknown and offers same-attempt lookup. On scope loss clear unauthorized Customer views and follow Authentication resume rules. Maintain focus, keyboard access, screen-reader labels and thumb-friendly controls; status cannot rely on color alone. Receipts are issued only after commit, and their URLs/downloads require current authorization.

## 17. Notifications and audit

Notify the Customer after a posted receipt/reversal with safe amount/date/method/reference and savings/fee distinction; notify the current assigned Agent for Customer-side corrective work. Agent reconciliation notices show their own batch/outstanding amounts without exposing reassigned Customer information. Exception/review tasks route to active Admins with the relevant permission. Internal shortages/allegations and raw evidence are excluded from Customer notices. Initial channels: in-app operational notices, email for posted financial receipt/reversal where a usable recipient account/channel exists; Notifications owns later preferences and fallback delivery policy.

Queue notices durably after commit, use immutable event deduplication, re-check recipient scope before dispatch/retrieval and apply bounded observable delivery retries. Invited Customers may receive permitted receipt email at their registered address without an activation bypass. Suspended users cannot retrieve through revoked sessions. Notification failure cannot undo or re-record money.

Audit events include receipt attempts/outcomes, allocation/attendance actions, idempotency conflict, scope denial, late recording, fee bundle, batch freezing/supplements, remittance evidence/confirmation, review outcomes/exceptions/reopening, reversal request/decision/posting and projection/ledger discrepancies. Capture actor/role/permission, record/version, original assignment, received date, UTC occurrence/recording time, amount/currency, method mapping, posting/evidence references, reason, result and correlation/request/event IDs. Append protected changes; never log credentials, tokens, payment secrets or raw unrelated evidence contents.

Successful financial mutations require a durable canonical event in the same recoverable commit. Denials may log outside the aborted transaction without leaking target data. Detailed audit requires `audit.view`; baseline business financial views are not audit access. Financial/audit history is append-only for all users. Retention, controlled reveal, audit export and financial report format remain owning policies; `reports.export` does not grant unspecified audit export or raw settlement evidence.

## 18. Non-functional requirements and enablement gates

Proposed measured targets on an agreed mobile-network/device and seeded-data profile: collection workspace usable within three seconds at p95; Customer search within one second at p95; valid online receipt durable response within two seconds at p95 excluding upload time. Define the measurement profile and representative concurrency/load before release; target misses are documented, never hidden by optimistic success before durable commit. Pagination and totals must remain correct at the declared supported dataset size. Measure the common prefilled cash daily-payment path from an already-open Customer row, with a proposed target of fewer than three deliberate interactions using a reviewed amount/allocation and explicit confirmation; evidence upload or overrides may require more. Optimizing interaction count must preserve confirmation and durable success semantics.

Money invariants, authorization and duplicate prevention are mandatory under load, restart and interruption even if performance degrades. Recovery must reconstruct receipts, cards, batches and summaries from durable ledger/schedule/allocation history without losing actor or duplicating postings. Evidence transmission/storage requires protected access, malware checks and safe exception messages. Final security/accessibility and retention requirements defer to their owning modules without leaving financial authorization optional.

Enable only after: reviewed accounting chart/method mappings and the proposed 999,999,999,999-kobo receipt/tender cap with Module 06 per-slot/capacity checks; authoritative payout reservation/available contract; Module 05 fee bundle and reversal dependency contract; Module 06 immutable plan-timezone/schedule/completion/correction contract; current assignment/status atomicity including the legitimate-session temporary-lock exception; idempotency and posting uniqueness; durable audit/notification work; tested batch/remittance verification; captured receipt/batch timezone, proposed 30-calendar-date lookback and authoritative open-period controls; and agreed evidence/performance profile. Any missing integration produces **Blocked** acceptance evidence for its dependent action, not Passed or a guessed default.

## 19. Indexed functional requirements

The following requirements summarize Sections 4–18; the detailed sections supply constraints, fields and state transitions. Every requirement has release evidence in Section 20.

| ID | Requirement | Detail |
| --- | --- | --- |
| COL-001 | Enforce Agent-only receipt recording and default-deny other financial actions. | 4.1 |
| COL-002 | Revalidate current assignment, Agent readiness and Customer/plan collection eligibility at commit. | 4.2, 10 |
| COL-003 | Preserve independent authentication and operational states, with invited Active Customers eligible. | 4.2 |
| COL-004 | Validate exact positive NGN kobo values, currency and configured bounds. | 5 |
| COL-005 | Require method/custody mapping, safe non-cash reference and evidence of actual receipt. | 5, 12.1 |
| COL-006 | Retain received date, UTC recorded-at and immutable timezone/version; reject future/unsupported dates. | 6 |
| COL-007 | Require explicit tender allocation to savings and named fee obligations without silent deductions. | 5, 9 |
| COL-008 | Allocate every savings kobo to valid residual slot capacity in one plan. | 7.1 |
| COL-009 | Support partial, multiple-day, catch-up and advance funding with a reviewable default/override. | 7.1 |
| COL-010 | Keep allocation history immutable and reject cross-Customer/plan or overcapacity funding. | 7.1, 10 |
| COL-011 | Coordinate funded-slot completion/correction with Module 06 and preserve schedule dates. | 7.2 |
| COL-012 | Derive card statuses from live funding/date/eligibility with accessible advance/blocked tags. | 8.1 |
| COL-013 | Allow only eligible nonfinancial missed/skipped annotations with reason/version/history. | 8.1 |
| COL-014 | Separate card/plan totals, lifetime liability, reservations and available savings. | 8.2, 9.2 |
| COL-015 | Commit immutable balanced ledger groups using approved custody/liability/fee mappings. | 9.1 |
| COL-016 | Keep Customer funds, Agent receivables, fee obligations/revenue and earnings separate. | 9.1–9.2 |
| COL-017 | Enforce authoritative liability minus live reservations and prevent double debit/negative savings. | 9.2 |
| COL-018 | Commit receipt, slots, fee bundle, ledger, lifecycle, batch, audit and durable notices consistently. | 9.3 |
| COL-019 | Implement persistent replay-safe receipt/financial keys and conflicts for changed payload. | 10 |
| COL-020 | Resolve unknown outcomes by original key and prevent false offline success. | 10 |
| COL-021 | Coordinate capacity, fee, reservation, reassignment, lifecycle and archival races. | 10, 15 |
| COL-022 | Provide eligible daily work and separately labelled blocked/history views within current scope. | 11.1 |
| COL-023 | Compute independently scoped received-date/slot-date/posting-date metrics without duplicate totals. | 11.2 |
| COL-024 | Protect evidence uploads, private content and current authorization at retrieval. | 12.1 |
| COL-025 | System-create uniquely attributed method/date batches and freeze versioned submissions. | 12.2 |
| COL-026 | Retain late supplements/reopened history without changing a reconciled version. | 12.2, 13 |
| COL-027 | Require `reconciliation.manage` for remittance verification and replay-safe balanced custody transfer. | 12.3 |
| COL-028 | Allocate partial remittances once without cross-Agent netting or unsupported payout/earnings offsets. | 12.3 |
| COL-029 | Reconcile method evidence/amounts with zero unexplained variance and pending-item closure gates. | 13.1 |
| COL-030 | Preserve Customer posted balances through shortages, overages and reconciliation-state changes. | 13 |
| COL-031 | Maintain versioned reasoned exceptions and block unsupported write-offs/adjustments. | 13.2 |
| COL-032 | Let current assigned Agents initiate idempotent full receipt reversal requests, without approval powers. | 14.1 |
| COL-033 | Require one `reversals.review` Admin, confirmation and proposed fresh authentication for approval. | 14.1 |
| COL-034 | Atomically post only eligible linked compensation bundles with current reservations/dependencies. | 14.2 |
| COL-035 | Preserve original receipt/actor/history and require separate current-authority replacement recording. | 14.2 |
| COL-036 | Preserve original Agent cash responsibility and immediately apply reassignment/privacy/lifecycle gates. | 15 |
| COL-037 | Deliver role-appropriate accessible screens, safe stale/empty/error states and authorized receipts. | 16 |
| COL-038 | Queue deduplicated scope-checked financial/operational notices without rolling back posting. | 17 |
| COL-039 | Durably audit financial/annotation/review attempts and protect append-only audit/evidence access. | 17 |
| COL-040 | Gate enablement on explicit dependencies and verify performance, recovery and invariants. | 18 |

## 20. Acceptance scenarios and release evidence

Use fixtures with at least two Agents, active/unavailable Agents, two current assignments, different Customer operational/account states, daily plans with partial/advance slots, named fee obligations, live reservations, remitted/unremitted receipts and reconciled/supplemental batches. Each scenario records build, fixture, exact pre/post ledger totals, role/permission/version, expected result and actual evidence. These are future verification scenarios; documentation does not assert they have passed.

| ID | Requirement mapping | Testable expected result |
| --- | --- | --- |
| COL-AC-001 | COL-001 | Customer and Admin collection API calls, including privileged Admins, are denied with no money/slot effect. |
| COL-AC-002 | COL-002 | Assigned ready Agent posts; another Agent or stale former assignee cannot post against the same Customer. |
| COL-AC-003 | COL-002, COL-003 | Active Invited Customer can receive an Agent collection; Inactive/Restricted/Archived Customer cannot. |
| COL-AC-004 | COL-002, COL-003 | Inactive/Suspended/MFA-incomplete Agent and Paused/Completed/Closed/Cancelled plan fail collection checks. Temporarily locked Active Agent with legitimate unrevoked session may collect for an existing eligible assignee but cannot receive a new/reassigned Customer; invalid/revoked session still fails. |
| COL-AC-005 | COL-004, COL-008 | NGN 2,000.01 becomes exactly 200001 kobo; validate the proposed 999,999,999,999-kobo tender cap and reject cap+1, zero/negative/extra decimals/overflow/unsupported currency without posting. Savings also obeys Module 06 per-slot cap and total residual capacity; cumulative totals can exceed the single-entry cap safely. |
| COL-AC-006 | COL-005 | Missing custody mapping or promised transfer fails; valid evidence/reference maps gross savings to declared asset/Agent receivable. |
| COL-AC-007 | COL-006, COL-012 | Today received timestamp, yesterday late receipt and future advance allocations retain distinct dates; future received date fails. Change business timezone for a new receipt: existing plan due-day/missed/advance comparisons retain plan timezone, while new receipt/batch retains its captured business timezone; historical dates do not shift. Cross-zone date-only ambiguity cannot silently choose a status. |
| COL-AC-008 | COL-006 | Past receipt requires reason: today−30 calendar dates is within the proposed lookback, today−31 is rejected; closed/unsupported period still fails within the lookback. New Agent cannot relabel former Agent-held money as their receipt. |
| COL-AC-009 | COL-007 | NGN 6,500 tender split as 6,000 savings and 500 fee credits only 6,000 savings; fee obligation settles under Module 05. |
| COL-AC-010 | COL-007, COL-018 | Changed fee/slot policy after preview requires re-confirmation, not silent adjusted posting. |
| COL-AC-011 | COL-008, COL-009 | NGN 3,000 against a NGN 5,000 slot creates Partial/residual 2,000; another 2,000 makes exactly Paid. |
| COL-AC-012 | COL-009 | One NGN 6,000 receipt funds three NGN 2,000 slots; one receipt count and three funded slots. |
| COL-AC-013 | COL-009 | NGN 10,000 today funds five future NGN 2,000 slots and increases today's received money once. |
| COL-AC-014 | COL-009 | Oldest-unfilled suggestion completes partial first; explicit valid override preserves chosen dates and exact total. |
| COL-AC-015 | COL-010 | Excess capacity, cross-Customer, different-plan slot, negative allocation and unallocated kobo reject the entire receipt. |
| COL-AC-016 | COL-011 | Final required slot completes count consistently; several partial receipts and calendar expiry alone do not complete plan. |
| COL-AC-017 | COL-011 | Pause/resume preserves IDs/dates; funded-completion reversal uses Module 06 correction state and does not reopen Closed silently. |
| COL-AC-018 | COL-012 | Fully/partly/unfunded past/today/future slots render Paid/Partial/Missed/Pending by documented rules, with accessible text. |
| COL-AC-019 | COL-012 | A blocked eligible-history interval renders blocked overlay rather than invented missed/skipped/payment. |
| COL-AC-020 | COL-013 | Skip/miss append reasoned versioned annotation with no ledger effect; skip cannot complete the cycle. |
| COL-AC-021 | COL-013 | Paid/Partial/future skip, unauthorized annotation and stale annotation change fail without money/status change. |
| COL-AC-022 | COL-012, COL-014 | Catch-up funds a skipped/missed slot, replacing primary display while retaining annotation and receipt-date history. |
| COL-AC-023 | COL-014 | Posted payout reduces lifetime savings but does not unfund prior Paid slots; plan contribution total remains distinct. |
| COL-AC-024 | COL-015, COL-016 | Cash savings posts equal Agent-receivable debit/Customer-liability credit; separate fee component is not savings. |
| COL-AC-025 | COL-015 | Each transfer/POS method produces balanced approved entries; disabled mapping/unknown processor deduction prevents enablement. |
| COL-AC-026 | COL-017 | Liability 10,000 with reservation 3,000 shows available 7,000; payout reservation consumption avoids double subtraction. |
| COL-AC-027 | COL-017 | Unpaid registration fee creates no silent hold/deduction; unaffordable configured full application remains outstanding, without negative savings. |
| COL-AC-028 | COL-018 | Fault before atomic commit leaves no receipt, slots, fee entries, batch amount or successful notice. |
| COL-AC-029 | COL-018 | Required fee/ledger/audit dependency outage blocks write; post-commit search/notice outage retains one durable valid receipt. |
| COL-AC-030 | COL-019 | Repeated key/same payload returns one receipt/bundle; changed payload conflicts; reversal does not allow key reuse. |
| COL-AC-031 | COL-019 | Two legitimate identical cash receipts with separate keys fit only residual capacity; repeated non-cash reference is investigated safely. |
| COL-AC-032 | COL-020 | Response loss after commit resolves original key to one receipt; scope loss denies Customer result without a second posting. |
| COL-AC-033 | COL-020 | No connectivity creates no paid card/receipt/offline success; unresolved online submission keeps original key. |
| COL-AC-034 | COL-021 | Concurrent partials cannot overfund a slot; exactly fitting independent receipts can both commit with balanced entries. |
| COL-AC-035 | COL-021 | Reassignment/hold/pause/archival races produce ordered valid outcomes and retain actor without partial postings. |
| COL-AC-036 | COL-021, COL-017 | Reservation/deduction/reversal race cannot produce negative available savings or consume a reservation twice. |
| COL-AC-037 | COL-022 | Agent workspace includes eligible assigned due slots and advance-covered rows; blocked view excludes unauthorized Customers. |
| COL-AC-038 | COL-023 | Today 2,000 advance-covered and 6,000 catch-up received show covered due and money received separately, not an incorrect 4,000 surplus. |
| COL-AC-039 | COL-023 | Pagination/filter changes preserve total calculation across all scoped rows; late recording is not counted twice. |
| COL-AC-040 | COL-024 | Unsupported/oversize/infected evidence and public/stale signed-link retrieval fail; safe references do not disclose credentials. |
| COL-AC-041 | COL-024, COL-036 | Reassigned Customer evidence disappears for former Agent while masked own settlement balance remains permitted. |
| COL-AC-042 | COL-025 | Each posted receipt enters one actor/date/method batch; freeze is replay-safe with accurate separate savings/fee totals. |
| COL-AC-043 | COL-025 | Agent has read own status only; financial reconciliation/evidence-submit endpoint remains denied without reviewed capability extension. |
| COL-AC-044 | COL-026 | Late prior-date receipt creates linked supplement; previous reconciled revision/evidence stays immutable and new amount is outstanding. |
| COL-AC-045 | COL-027 | Reconciliation Admin confirms evidenced remittance once; baseline Admin and Agent cannot confirm; Customer liability unchanged. |
| COL-AC-046 | COL-028 | Partial cash remittance reduces only original Agent receivable by confirmed amount; cross-Agent/earnings/unposted payout netting fails. |
| COL-AC-047 | COL-029 | Zero matched variance with adequate evidence can close; missing evidence, clearing settlement or pending correction blocks closure. |
| COL-AC-048 | COL-030 | Shortage 500 retains original credited savings and Agent 500 debt; changing batch to Exception changes no Customer balance. |
| COL-AC-049 | COL-030, COL-031 | Overage/missing transfer creates investigation; cannot be silently assigned to a Customer, fees or unsupported suspense income. |
| COL-AC-050 | COL-031 | Reasoned evidence-backed resolution/reopening retains earlier review; write-off/manual adjustment action is unavailable. |
| COL-AC-051 | COL-032 | Current Agent requests full reversal of permitted receipt once; requester cannot approve or initiate against unassigned Customer. |
| COL-AC-052 | COL-033 | One fresh-authenticated `reversals.review` Admin approves any value; missing grant/freshness fails without compensation. |
| COL-AC-053 | COL-034 | Approved original/dependent fee bundle is balanced and atomic; independent waiver/deduction authority is not inherited. |
| COL-AC-054 | COL-034 | Settled payout/live reservation/withdrawn fee earnings/unsupported remitted-cash dependency blocks unsafe compensation. |
| COL-AC-055 | COL-034, COL-035 | Posted reversal retains original/compensation history and recomputes slots/net totals once; reject/cancel has no effect. |
| COL-AC-056 | COL-035, COL-036 | Replacement Agent retains legitimate pending reversal follow-up; original actor/cash debt stays original; new corrected receipt needs separate eligibility/confirmation. |
| COL-AC-057 | COL-036 | Agent suspension immediately stops new money recording; Admin historical reconciliation and offboarding unresolved gates remain accurate. |
| COL-AC-058 | COL-036 | Customer archival is blocked by linked pending correction/exception; unrelated Agent issue alone does not block settled Customer. |
| COL-AC-059 | COL-037 | Dashboard, assigned Customer profile, daily list and thrift card Agent entry points open the same current validated payment form; saved links cannot bypass eligibility. Role surfaces show correct actions/states on mobile/keyboard/screen reader; unavailable ledger data never becomes zero. |
| COL-AC-060 | COL-038 | Repeated financial notice delivery is deduplicated; changed assignment masks former-Agent payload and failure never repeats money. |
| COL-AC-061 | COL-039 | Receipt/remittance/reversal/annotation have durable immutable actor/version/events; audit requires `audit.view` and exposes no payment/auth secrets. |
| COL-AC-062 | COL-040 | Restart/replay reconstructs cards/batches/net balances without duplicate posting; agreed p95/load/accessibility profiles have documented evidence. |
| COL-AC-063 | COL-040 | Missing accounting/reservation/fee/lifecycle/period/evidence contract reports dependent scenarios Blocked and feature unavailable. |

## 21. Worked examples

### 21.1 Mixed savings and registration-fee receipt

An Agent receives NGN 6,500 cash. The confirmed split is NGN 6,000 savings across three NGN 2,000 slots and NGN 500 against an outstanding registration fee. Customer savings increases by NGN 6,000; the fee obligation/recognition follows Module 05; original Agent cash receivable increases by NGN 6,500. NGN 6,000 is not reduced again by the externally paid fee. Remitting all NGN 6,500 moves custody to the business without another Customer savings credit.

### 21.2 Advance, partial and catch-up

Day 1 receives NGN 5,000 toward NGN 2,000 daily slots. Default funding makes Days 1 and 2 Paid, Day 3 Partial at NGN 1,000, with future funding tagged advance. Day 3 receives NGN 1,000 and becomes Paid. Day 4 remains unfunded and is missed after its eligible date; a Day 6 receipt funds Day 4 as catch-up. Received-date totals count money on Days 1, 3 and 6; slot-date coverage counts the linked expectation dates. No missed day creates a negative balance.

### 21.3 Shortage after a valid Customer collection

Agent A posts NGN 10,000 savings and later transfers NGN 9,500 to the business. Customer liability remains NGN 10,000; business custody increases NGN 9,500 and Agent A retains NGN 500 receivable. A shortage exception and offboarding blocker remain. Reassigning the Customer to Agent B changes future service only. Agent B does not inherit the NGN 500 debt.

### 21.4 Reversal blocked by reserved savings

Customer liability is NGN 10,000, including an erroneous NGN 4,000 receipt; a live payout reservation is NGN 7,000. Available savings is NGN 3,000. Reversing the receipt would leave liability NGN 6,000 below its reservation, so approval is blocked. The authorized withdrawal/correction owners must resolve the conflicting dependency through their reviewed workflows; reconciliation cannot release the hold or reduce Customer liability by editing a batch.

## 22. Decisions for review and deferred owner policies

Review the proposed required payment method/non-cash evidence, oldest-unfilled allocation with explicit override, no unallocated overpayment, Agent attendance annotations, business-date late-recording rules, zero-variance reconciliation gate, batch/supplement state names, receipt reversal initial full-only scope and fresh Admin step-up, attachment limits and measured performance profile.

Before implementation, review the proposed 999,999,999,999-kobo receipt/tender cap and 30-calendar-date late-recording lookback, and explicitly resolve authoritative open-period controls, chart of accounts/method mappings, captured business receipt/batch timezone versus immutable plan timezone, day boundary, custody verification sufficiency, remitted-original reversal/suspense posting authority, settled fee-earnings compensation, Module 06 completion correction, and the authoritative reservation service. Separate future policies must define Agent remittance evidence submission authority if desired, write-offs/independent adjustments, payout/fee refund execution, offline queues, automatic bank reconciliation, partial reversal, evidence retention/reveal and report exports. No undefined policy may be replaced with a silent balance edit, role expansion, charge or forgiveness.
