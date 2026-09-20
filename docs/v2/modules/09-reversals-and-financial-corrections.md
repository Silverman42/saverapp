# Reversals and Financial Corrections

**Product version:** 2.0  
**Module:** 09  
**Module status:** Detailed draft for review  
**Sources:** [Version 1 PRD](../../PRD.md), [User Types](./01-user-types.md), [Authentication](./02-authentication.md), [Roles and Permissions](./03-roles-and-permissions.md), [Customer and Agent Management](./04-customer-and-agent-management.md), [Fees and Deductions](./05-fees-and-deductions.md), [Thrift Plans and Savings Cycles](./06-thrift-plans-and-savings-cycles.md), [Collections, Digital Thrift Card, and Reconciliation](./07-collections-thrift-card-and-reconciliation.md), and Module 08 when finalized

## 1. Purpose and specification status

This module defines how an erroneous posted Customer financial transaction is corrected without deleting or rewriting history. It establishes the reversal request, one-Admin review, atomic balanced compensation, dependency handling, and durable evidence required to restore the accounting effect safely.

The confirmed baseline is that an eligible Agent initiates an accessible transaction reversal, one Admin with `reversals.review` approves or rejects it regardless of value, an Agent cannot approve, an Admin cannot initiate, and completed financial history cannot be deleted or silently altered. Detailed eligibility, dependency, evidence, correction and recovery policies below are **proposed for review** unless inherited from Modules 01–07. Numbered requirements describe intended behaviour and are not claims of implementation or approval.

## 2. Scope and exclusions

### 2.1 Initial scope

- Full reversal of one eligible posted Customer contribution receipt, including its savings and external-fee tender components.
- Full reversal of an erroneous posted fee receipt, savings-funded fee application, other deduction, or completed Customer withdrawal, where every required dependent effect can be compensated atomically.
- Agent initiation, Admin review, cancellation/rejection, immutable evidence, current assignment handover, notifications, audit and safe retries.
- Compensation of directly dependent entries required to make the selected original transaction's reversal internally consistent.
- Recalculation events and exceptions for plan slots/completion, fee obligations/earnings, available savings/reservations, Agent custody and reconciliation.

### 2.2 Deferred or separately owned

- Partial reversal of a receipt, payout, fee application, deduction or allocation.
- Editing a posted amount, date, Customer, plan, method, allocation, actor, approval, evidence or accounting account.
- Reallocation between slots without reversing and recording a correct replacement receipt.
- Independent manual journal entries, opening-balance corrections, bad-debt write-offs, debt forgiveness, chargebacks, recovery collection, inter-Customer transfers and negative contributions.
- Customer-initiated disputes, arbitration, appeal and legal retention procedures beyond the operational issue/contact path.
- Execution of external cash returns, bank recalls, Customer recovery payments, valid-charge refunds or refund-payout processing without their owning workflow.
- Reversing a posted business fee-earnings draw, remittance/reconciliation record, waiver, refund entitlement, or unpaid obligation assessment through the Customer reversal workflow.
- Bulk reversals and automated reversal approval.

A deferred correction has no generic “adjust balance” fallback. The original remains effective until an authorized owning workflow can post a complete correction.

## 3. Terms and correction taxonomy

| Term | Meaning |
| --- | --- |
| Original transaction | Immutable posted financial event selected for correction. |
| Reversal request | Versioned request asserting that an eligible original is erroneous and proposing a complete compensation bundle. It has no financial effect before approval/posting. |
| Compensation bundle | Balanced immutable entries and required owner events that remove the original's current live financial effect and its defined direct dependencies. |
| Dependent effect | Fee, slot allocation, obligation state, trigger marker, reservation, custody or reconciliation effect whose validity depends directly on the original. |
| Full reversal | Compensation of the entire remaining live effect of the selected original and every required dependent effect. |
| Replacement | New, separately confirmed correct transaction after a reversal. Never an edit or automatic replay. |
| Valid-charge refund | Business decision to return a correctly assessed/settled charge; owned by Module 05 and not evidence that the original was erroneous. |
| Adjustment | Separately authorized correction where no eligible original can be exactly compensated; deferred until authority/accounting policy exists. |
| Physical return/recovery | Real movement of money after an original receipt or payout. A database reversal cannot assert that this occurred. |

### 3.1 Taxonomy and owning route

| Situation | Initial route | Why |
| --- | --- | --- |
| Erroneous posted Customer contribution or combined savings/fee receipt | This module: Agent request → Admin approval → full compensation | Removes posted liability, slot funding, custody and direct fee effects together. |
| Erroneous posted external fee receipt, savings fee application or other deduction | This module if it is a Customer financial entry and all direct effects are compensable | Restores the relevant obligation/liability/income effects without granting a waiver or refund. |
| Erroneous completed Customer withdrawal/payout | This module only after payout/funds disposition is authoritatively resolved under Section 8 | Reversing a ledger entry is not recovery of cash already paid. |
| Incorrect pending/unposted contribution or withdrawal request | Cancel/reject in its owning module | There is no posted financial effect to reverse. |
| Incorrect unpaid discretionary fee assessment | Module 05 `fees.manage` assessment correction | No posted-money reversal; `reversals.review` is not a substitute. |
| Valid fee concession/refund | Module 05 `fees.manage` refund workflow | The charge was valid; it must not be labelled erroneous. |
| Unpaid-fee waiver | Module 05 `fees.manage` | Waiver is deliberate forgiveness, not reversal. |
| New fee/deduction | Module 05 with `fees.manage` or `deductions.manage` | A reversal approval cannot create independent charges. |
| Incorrect remittance, reconciliation decision or evidence | Module 07 with `reconciliation.manage` | It corrects custody/reconciliation records, not Customer financial history through Agent initiation. |
| Erroneous business fee-earnings draw | Module 05 dedicated `fees.manage` compensation | Business-only action, outside Agent Customer scope. |
| Unable to identify an exact eligible original or exact counter-account | Deferred adjustment/investigation | Never force an approximate reversal or hidden balance edit. |

One reversal approval authorizes only the reviewed original and its displayed mechanically required dependency bundle. It does not authorize a new fee, waiver, valid-charge refund, unrelated deduction, write-off, reconciliation decision, payout, recovery, replacement transaction or correction of another original.

## 4. Authority, scope and separation of duties

| Action | Customer | Eligible current assigned Agent | Admin |
| --- | --- | --- | --- |
| View reversal affecting a Customer | Own permitted history | Current assigned scope | Business-wide baseline read summary; sensitive evidence scoped below |
| Initiate Customer financial reversal | No | Yes, subject to eligibility | No, including with `reversals.review` |
| Edit/cancel own pending request | No | Requesting Agent while still authorized; cancellation rules apply | No |
| Approve/reject/post decision | No | No | One Admin with `reversals.review` |
| Add review evidence/comment | Issue evidence only where permitted | Pending request within current task scope | Reviewing Admin with `reversals.review` |
| Waive/refund/assess fee or post deduction | No | No | Separate owning permission only |
| Resolve reconciliation/remittance | No | No | `reconciliation.manage` only |
| Detailed audit / business export | No | No | `audit.view` / `reports.export` respectively |

Initiation requires an Agent role, completed activation/MFA, operationally Active status, an Authentication-permitted current session, current effective Customer assignment, access to the original and Customer status eligibility. Preserve Authentication and Module 04's legitimate-session temporary-lock exception; Suspended/Deactivated access remains blocked. A Customer may report an error through a service channel but cannot create the system request.

The reviewer must be an active Admin currently holding `reversals.review`, complete proposed fresh password-and-MFA authentication using Authentication's shared freshness policy, and have no temporary restriction that blocks the permission. The Agent initiator and Admin reviewer are inherently distinct accounts. Review never lets the Admin act as the Agent or rewrite the Agent's submission.

`reversals.review` does not imply `fees.manage`, `deductions.manage`, `withdrawals.review`, `reconciliation.manage`, `customers.manage` or payout authority. Those permissions also cannot initiate a reversal. A system-generated deterministic dependency entry may be included only when the original owning contract explicitly requires it; discretionary action remains separate.

## 5. Request data and validation

Every request records:

- Immutable public/internal request ID and operation/idempotency reference.
- Customer, original transaction ID/type/version, posting group and every proposed dependent reference/version.
- Original gross amount, currency, component/allocation values, posting/received/occurrence dates, actor and current live effect.
- Requesting Agent, assignment/version at initiation, current case owner, initiation timestamp and source channel.
- Required reason category and trimmed internal explanation of 1–1,000 characters.
- Required Customer-facing explanation of 1–500 characters that omits private investigation and third-party data.
- Proposed compensation preview: balanced entries, affected slots/plan, liability/available savings, reservations, obligations, fee earnings, custody and reconciliation before/after.
- Evidence metadata and immutable content/checksum references; never secrets, full payment credentials or unrelated documents.
- State/version, decision actor/time/reason, fresh-auth reference without credentials, posting group/result and notification/audit references.

Proposed reason categories: duplicate posting, wrong Customer, wrong amount/allocation, payment not received, incorrect fee/deduction, incorrect payout record, and other documented error. Categories do not change authority or accounting. A wrong amount still requires full reversal followed by a new correct transaction; it is not partially edited.

Initial evidence accepts JPEG, PNG, WebP or PDF, proposed maximum 5 MB per file and three files per evidence record, plus safe text, aligned with Module 07. Malware/type/size/checksum processing must complete before review. Preserve original evidence; permitted supplements append with actor/time and cannot replace it. Detailed evidence is visible only to the requester/current task owner and Admin reviewers with a business need; Customers receive the explanation and resulting entries, not internal files or third-party data. Final retention/reveal rules are a release dependency of Audit/Reporting.

Validate all identifiers and relationships server-side. All financial values are integer kobo in the original currency; initial supported currency is NGN. Recompute the bundle from authoritative owner data instead of accepting client-provided entries or totals. Unknown, stale, non-integer or mismatched data fails without a request or posting.

## 6. Request states and transitions

| State | Meaning | Allowed transition |
| --- | --- | --- |
| Pending review | Valid request committed; original remains financially effective | Reject, cancel, or approve-and-post after fresh preview |
| Rejected | Admin found request ineligible/unsupported/unproven | Terminal; a materially new issue may use a new request linked to this history |
| Cancelled | Requesting Agent withdrew pending request before review commitment | Terminal; no financial effect |
| Approved and posted | Admin approval and the full compensation bundle committed atomically | Terminal; corrections to this result require a separately eligible linked workflow |

There is no durable Approved-but-unposted state in initial scope. Approval and posting form one commit boundary; an unknown network response is resolved by operation lookup, not represented as a new business state. A failed attempt leaves Pending review with safe failure evidence if no bundle committed. Never display Reversed until durable posting succeeds.

Only the requesting Agent may cancel while Pending review and currently authorized. Reassignment does not invalidate a legitimately initiated request; instead Module 04 transfers task follow-up to the replacement Agent without changing the original requester. The replacement cannot rewrite or cancel on the original Agent's behalf in initial scope. An Admin may reject an obsolete request but cannot cancel it or transform it into another correction.

Reject/reason fields are required, 1–500 characters, with a safe Customer-facing explanation when communicated. Rejection/cancellation has no accounting, plan, obligation, reservation or custody effect. State transitions use expected version and are immutable after commitment.

## 7. Eligibility and dependency graph

### 7.1 Original eligibility

The original must be posted, within the requesting Agent's current Customer scope, non-Archived, not already fully compensated, and supported by an owner contract capable of generating an exact full bundle. Pending, rejected, cancelled, draft, failed or merely approved-but-unposted events are corrected in their owner workflow. An original with any effective partial correction is ineligible for initial full-only reversal unless the owner can define the exact remaining bundle; otherwise it is an adjustment release gate.

Active, Inactive and Restricted Customers allow an eligible corrective reversal because correcting erroneous history must not be blocked by participation/payout holds. Restricted status does not allow a new payout, refund, fee or replacement contribution. Archived Customers must be restored to Inactive under Module 04 before posting a correction; discovery creates an investigation and archive blocker without mutating the archived ledger.

No generic age limit is proposed. Closed/finalized reporting periods do not make an error correct, but the accounting-period owner must provide a current-period compensation policy before posting. Never backdate the compensation to the original date. Show original occurrence/posting dates and actual reversal posting date separately.

### 7.2 Dependency traversal

Build a directed dependency graph from immutable source links, not amounts/names guessed by the client. The preview must classify every live downstream effect as:

1. **Mechanically compensable in bundle** under an explicit owner contract.
2. **Independent and retained**, with reason why the original reversal does not affect it.
3. **Blocking prerequisite**, requiring resolution through its owning workflow before review can approve.
4. **Unsupported/unknown**, which fails closed and opens an investigation rather than approximating.

Traversal includes, where applicable:

- Contribution principal and slot allocations, plan funded counts/completion events and subsequent renewal/closure state.
- Immediate first-contribution fee assessment/application and percentage-fee basis/settlement/refund consequences.
- Customer liability, live payout reservations, completed/pending payouts and deductions that depend on resulting availability.
- External fee allocation, obligation balance, recognized earnings, refund payable and once-per-cycle trigger marker.
- Recording Agent cash responsibility, remittance/batch/reconciliation/shortage and custody location.
- Statements, reports, notifications and audit projections that must display the linked correction.

Do not recursively reverse valid independent later activity merely to make approval convenient. For example, a later withdrawal backed partly by the erroneous contribution is a blocking dependency unless reservation/payout owners can safely resolve the resulting liability; the approval cannot silently reverse that withdrawal too.

### 7.3 Physical-money disposition

For any original receipt, the dependency preview must classify the tender, independently from whether its Customer allocation was wrong:

- **Never received / duplicated record:** supported evidence shows no corresponding physical tender; compensate the original custody/clearing effect.
- **Received and still controlled:** remove the erroneous Customer allocation but reclassify the same money to an explicitly defined unapplied-receipt/suspense liability or controlled-funds account; do not make cash disappear or recognize income. A correct replacement may consume that exact linked amount only through a separately confirmed Module 07 contract, without recording a second physical receipt.
- **Received then fully returned:** link a separate evidenced return posting; the reversal does not itself claim the return.
- **Partially returned, lost, spent or uncertain:** block initial full reversal until the recovery/suspense/adjustment owner defines the complete entries.

The custody classification, evidence, amount, method/account and versions are reviewed at approval. A plan/Customer correction and physical-money correction may have different dates, but must reconcile as one supported bundle or remain blocked.

### 7.4 One-open-request rule

At most one Pending review reversal may target the same original transaction or posting group. Enforce atomically across retries and Agents. A terminal rejection/cancellation permits a new request only with a new operation reference and material explanation/evidence; link prior attempts. An Approved and posted full reversal permanently prevents another live reversal of that original.

## 8. Type-specific compensation

### 8.1 Contribution or combined receipt

Reverse the full live receipt, not selected slots/components. First apply Section 7.3: a false duplicate may compensate the custody effect; actually received money must remain in controlled custody/suspense or have a separately evidenced return. Atomically:

- Append balanced counter-entries removing the gross savings contribution from Customer liability.
- Remove every live allocation made by that receipt and recompute card slots from net allocations; preserve original receipt/allocation history.
- Compensate any external-fee tender component, settlement/recognized earnings, and Agent/business custody entry according to its original source.
- Compensate a directly dependent first-contribution fee assessment/application only as Module 05 requires. Restore a still-valid obligation where appropriate; do not invent waiver/refund.
- Recalculate completion-percentage basis and produce explicit Module 05 outcomes. Reduced already-settled fee becomes its defined refund/correction obligation; increased charge cannot be invented outside snapshotted terms.
- Update the original Agent's cash responsibility or linked unapplied-funds custody according to the reviewed physical disposition, even after reassignment; never move historical custody debt to the replacement Agent or make controlled cash disappear.
- Invoke Module 06's completion correction contract. A Completed cycle with a new shortfall becomes Paused and requires eligible review/resume; a Closed cycle remains Closed with a linked exception and archive blocker. Do not alter a successor cycle.

If liability reduction would make liability lower than live payout reservations or completed downstream payouts, approval is blocked until the owning withdrawal/recovery workflow supplies a valid coordinated result. Never allow negative Customer liability, take another Customer's funds or release a reservation through reversal authority.

### 8.2 Fee receipt, application or deduction

A full erroneous external fee-receipt reversal compensates the original received-funds/custody and recognized-income entries and restores the underlying valid obligation to outstanding where applicable. It does not assert physical cash was returned. If money has been remitted or returned, custody/recovery evidence and owner entries must describe the actual path.

A full erroneous savings-funded fee application restores Customer liability, reverses fee recognition/settlement and restores or cancels the obligation according to why the entry was erroneous. Reversing a valid assessment's payment normally restores that valid debt; cancelling the assessment itself requires Module 05's authorized correction. A fee already refunded or drawn from business earnings may block until Module 05 supplies a coherent bundle.

A full erroneous other deduction restores Customer liability and compensates its original destination/income. `reversals.review` does not authorize a replacement deduction or classification change. A valid fee/deduction returned as a concession uses the refund workflow, never an “error” category to bypass `fees.manage`/`deductions.manage`.

### 8.3 Completed withdrawal or payout

A withdrawal reversal may cover the full posting group only: gross Customer-liability debit, net payout, included withdrawal-timed fee/deductions, reservation consumption and trigger markers. It cannot simply restore savings while pretending the Customer returned money.

Before approval, the payout owner must authoritatively classify disposition:

- **Never delivered / false or failed payout posting:** evidence and provider/cash state prove the business still controls the net payout; compensate liability, cash/clearing, included fee/deductions and markers atomically.
- **Delivered then fully returned:** a separate immutable recovery/return event proves the complete net payout returned to controlled custody; link it and compensate the original group exactly once.
- **Delivered and not fully returned or uncertain:** reversal is blocked. Preserve the withdrawal and use the future recovery/receivable or adjustment workflow; no Customer savings restoration or false cash entry.

Partial payout return implies partial correction and is deferred. Rejection, cancellation or expiration of an unposted withdrawal belongs to Module 08 and creates no reversal request. An approved withdrawal awaiting payout retains its reservation and is cancelled/rejected/held under Module 08, not “reversed.”

### 8.4 Remittance and reconciliation records

Incorrect remittance evidence, batch membership, reconciliation decision or exception resolution is corrected through Module 07 by an Admin with `reconciliation.manage`. These records are not Customer financial originals that an Agent reverses. If a Customer receipt reversal affects a reconciled/remitted batch, its original reconciliation remains historically true for that version; append a new linked exception/supplemental reconciliation effect. The Admin reviewer cannot use `reversals.review` to declare cash remitted, resolve shortage or edit evidence.

## 9. Review and posting workflow

### 9.1 Initiation

1. Eligible current Agent opens an accessible posted transaction and selects **Report and request reversal**.
2. The server checks scope, Customer status, original live effect, duplicate request and supported type; it loads the first dependency preview.
3. Agent supplies category, internal reason, Customer-facing explanation and required evidence, then reviews that the entire original will be reversed and a correction must be recorded separately.
4. At commit recheck Agent/session/status/assignment, original and dependency versions. Atomically create Pending review, task ownership, audit and notification work. No financial effect occurs.

### 9.2 Admin review

1. Admin opens the queue and sees original, prior attempts, evidence, request/assignment history, complete owner-generated dependency graph and before/after financial preview.
2. The system clearly identifies blocked, stale, unsupported and physical-money dependencies. The Admin cannot remove required bundle items or add independent actions.
3. Reject with reason, or choose Approve and complete required fresh password-and-MFA authentication. Show Customer, original, full amounts, liability/availability/reservations, slot/plan state, fee/obligation/earnings, custody/reconciliation and notifications.
4. At commit recheck `reversals.review`, security restrictions, request/original/owner versions, Customer non-Archived state, exact relationships, payout disposition, ledger balance and all blocking dependencies.
5. Append the complete balanced compensation, owner events, Approved and posted state, decision evidence, audit and durable delivery work atomically. Any failure commits none of them and leaves the request Pending review for a fresh preview.

### 9.3 Replacement

After posting, the currently eligible assigned Agent may create a new correct receipt or initiate another owning action under its current validation. If the original tender was actually received and reclassified as unapplied funds, the replacement must consume the exact linked suspense amount through Module 07 rather than record the same physical money again; until that contract exists, this correction remains a release gate. It has a new ID, occurrence/received and actual posting timestamps, confirmation and accounting group, linked optionally as replacement of the reversed original. Never automatically clone/replay input, backdate recorded time, reuse provider reference without collision review, or treat reversal approval as replacement authorization.

## 10. Reassignment, Agent lifecycle and Customer lifecycle

Reassignment preserves requester, original actor, request state/evidence, original transaction attribution, all amounts and Admin decisions. Current task access moves to the replacement Agent as defined by Module 04, but historical actor fields never change. Former Agent loses Customer/request access immediately. Queued former-Agent edits/cancellations fail; a previously valid committed initiation stays reviewable.

Agent inactivity, suspension or offboarding blocks new initiation and self-service follow-up, but does not cancel a committed request or erase original cash responsibility. The replacement Agent may add permitted follow-up evidence after independently verifying it, identified as the supplement actor; they cannot impersonate or rewrite the requester. An Admin reviewer may decide without the original Agent's login. Unresolved requests, custody effects and related exceptions remain offboarding gates or formally handed-over work without forgiving liability.

Customer Inactive/Restricted status permits correction but no prohibited new activity. Restriction holds payouts/refunds/replacement transactions despite reversal eligibility. Archival requires no pending correction and all resulting liabilities, plans, fee/refund, custody and reconciliation exceptions settled. If an error is discovered after archival, record an investigation, restore the same Customer to Inactive through Module 04, then initiate/post within normal authority. Restoration never performs the reversal automatically.

Plan Closed/Cancelled state does not authorize rewrite. A correction against historical financial activity in a Closed plan preserves terminal state and creates the linked exception required by Module 06; cancellation cannot have financial activity and any discovered contradiction blocks archival/integrity checks. Renewal/successor plans are not recomputed or cancelled.

## 11. Concurrency, idempotency and failure handling

- Bind initiation, cancellation, rejection and approval/posting to unique server operation keys, actor/action/request and normalized payload. Same key/same payload returns the committed result; same key/different payload conflicts.
- An unknown response is resolved through same-operation lookup before allowing another attempt. Do not infer failure from timeout or create a second request/bundle.
- Use expected versions for request, original, posting group, assignment, Customer status, plan/slots, obligation/marker, reservation/payout, custody/batch and reconciliation dependencies.
- Serialize original live-effect changes with reversal posting. Only one full compensation can win. A contribution, withdrawal, fee settlement, refund, remittance or plan closure racing review either precedes a newly generated preview or causes conflict; it is never omitted silently.
- Recompute all balances and the complete dependency graph at final commit. Cached preview, rounded display amount, paginated history, notification delivery or manual checkbox is not authority.
- The ledger rejects unbalanced entries, wrong currency/account, duplicate source links, arithmetic overflow, negative disallowed liability/obligation, reservation deficit and unsupported partial effect.
- Financial posting, request decision, plan/fee/reservation/custody owner events, durable audit and outbox work form one coordinated boundary or an owner-supported exactly-once protocol. Unknown partial owner outcome is an investigation; do not announce success or retry blindly.
- Denial/validation/dependency failure creates no request where initiation failed and no financial/decision state where review failed. Safe failed-attempt audit may be appended without leaking an out-of-scope original.
- Notification/evidence-render failure after a committed outcome does not undo or repeat it. Persist retryable work and show authorized delivery/evidence-processing status.
- Restore service from immutable originals, compensations and owner events; projections/cards/statements must be rebuildable without editing history.

## 12. Screens and operational views

### 12.1 Agent

Transaction detail shows original status, gross/components, Customer/plan, dates, current live effect, prior correction requests and **Request reversal** only when role/scope/status/type eligibility allows it. Otherwise show a safe reason such as already reversed, pending request, Archived Customer, unsupported dependency or scope lost.

The form shows full-only impact, required reason/explanation/evidence, physical-money declaration where relevant, and warning that approval does not automatically return/recover cash or create a replacement. Agent request list defaults newest-first with ID tie-breaker and filters Pending/Rejected/Cancelled/Approved-and-posted. Reassignment and scope apply to rows, counts, search and direct links.

### 12.2 Admin

Review queue displays pending count and rows by requested time, Customer, original type/reference/amount, initiating/current Agent, dependency status and age; default oldest-pending first with request-ID tie-breaker. Search/filter results and counts remain business-scoped. Detail exposes complete immutable original, evidence with masking, request history, owner-version/as-of states, dependency graph, balanced preview and approve/reject controls only with current `reversals.review`.

Admins without `reversals.review` have baseline permitted summaries but no sensitive evidence or mutation controls. Detailed audit remains behind `audit.view`; exporting business/multi-Customer results requires `reports.export`. Independent fee/reconciliation actions link to their owning screens only when the Admin separately holds those grants; they are not embedded in approval.

### 12.3 Customer and shared presentation

Customer transaction history shows the original and linked reversal as separate entries, effective net result, posting dates, Customer-facing explanation and replacement link where applicable. Do not delete the original, label physical money Returned without proof, expose internal reason/evidence, or count a reversal as new contribution/withdrawal. Statements and summaries use effective compensated entries while retaining gross/reversal disclosure.

Proposed pagination is 25 rows default with 25/50/100 options. Loading does not show guessed zero/eligibility. Empty, stale, conflict, unavailable-owner, evidence-processing, unknown-outcome and access-lost states have distinct guidance. Preserve permitted filters; clear sensitive cached data on scope loss. Forms and graphs support keyboard/focus/error navigation, screen-reader labels, responsive layouts and status text/icons beyond color.

## 13. Notifications and audit

| Event | Recipients / channel | Content boundary |
| --- | --- | --- |
| Request submitted | Requesting Agent receipt; current assigned Agent task if different; Admin review queue | Request/original references, state, safe dependency status; no email evidence attachment. |
| Rejected/cancelled | Customer in-app/email when a prior error notice requires resolution; requester/current Agent in-app | State/time and Customer-facing explanation; internal review reason only to permitted staff. |
| Approved and posted | Customer in-app/email; requester/current Agent and reviewer receipt | Original and correction references, effective amounts/date, plan/fee/withdrawal consequences and next step; never claim physical return without owner proof. |
| Blocked dependency or post-closure exception | Current Agent and relevant authorized owner queue | Safe blocking category/reference; no broad disclosure of evidence/private reasons. |
| Delivery failure | Authorized task owner | Delivery status/retry, without repeating financial posting. |

Use durable outbox, event/recipient/channel deduplication, bounded retries and scope recheck at delivery/retrieval. Reassignment routes current operational notices without disclosing former Agent private context. Email subjects avoid financial amounts and sensitive identifiers; do not attach statements/evidence. Delivery failure never changes request/financial outcome.

Audit initiation attempts, successful requests, evidence additions, assignment/task handover, preview versions, cancellations/rejections, approval attempts and posted bundle, dependency/gate failures, stale/idempotency conflicts, unauthorized access, notifications and post-closure exceptions. Each event includes event/request/original/posting-group IDs, actor/system source, role/required permission, Customer/assignment, UTC time, action/outcome, safe reason, before/after and owner versions, entry/event references and device/session context where available.

Audit is append-only and cannot be edited from reversal screens. Retain the original and compensation for the approved financial-record period; the proposed default from Module 14 is seven years after business-record/cycle closure or later linked settlement, extended by any longer applicable class or hold. No application user may delete them, and controlled expiry must preserve the required integrity/tombstone evidence. Evidence retention/reveal/export remains a release gate. Never log passwords, MFA codes/secrets, sessions/tokens, raw payment credentials or unnecessarily exposed evidence. Customer-facing history is not detailed `audit.view` access.

## 14. Non-functional and release requirements

- Financial arithmetic uses integer kobo and checked overflow; no floating point or display-rounded decision.
- Authorization, eligibility, relationship and versions are enforced server-side on every read/mutation/background execution; UI hiding is not security.
- The approval path must preserve atomicity/exactly-once results across process/database/message failures and be recoverable by operation/source IDs.
- Dependency graph and preview identify owner/as-of/version; unavailable/stale data fails closed and never becomes zero/no dependency.
- Sensitive evidence is encrypted in transit/at rest, malware scanned, access logged and delivered through short-lived authorized retrieval; final retention/deletion policy must be approved before production.
- Queue/detail/search should meet the product's measured performance profile without weakening full dependency evaluation; no arbitrary latency target is asserted without capacity/service objectives.
- Monitor repeated requests, duplicate originals, posting failures, unresolved unknown outcomes, aged pending cases and cross-scope attempts without auto-approving or exposing Customer data.
- Backups/restoration must retain referential integrity among original, request, compensation, owner events and audit. Reconciliation tests prove all ledger postings balance and projections rebuild.
- Accessibility target and supported browsers/devices must be established at product level; initial implementation must at least meet the interaction requirements in Section 12.

Release is blocked until the shared ledger chart/accounts, Module 08 withdrawal states/reservation/payout-finality and recovery contract, Module 05 fee compensation/marker rules, Module 06 completion/closed exception contract, Module 07 custody/reconciliation compensation, evidence policy and coordinated posting protocol are implemented and contract-tested. A missing contract cannot be filled by manual balance editing or broader role authority.

## 15. Functional requirements

| ID | Requirement | Sections |
| --- | --- | --- |
| REV-FR-001 | Correct supported erroneous posted Customer entries only through immutable linked full compensation. | 2–3 |
| REV-FR-002 | Prohibit deletion/editing, partial reversals and generic balance adjustments in initial scope. | 2, 8 |
| REV-FR-003 | Route valid-charge refunds, waivers, new deductions, remittance corrections and business draws to their independent owners/permissions. | 3.1, 4 |
| REV-FR-004 | Permit only an eligible current assigned Agent to initiate a Customer financial reversal. | 4, 9.1 |
| REV-FR-005 | Permit one Admin with `reversals.review` to approve/reject any value; prohibit Admin initiation and Agent approval. | 4, 9.2 |
| REV-FR-006 | Require fresh Admin authentication and recheck permission/restrictions at decision commit. | 4, 9.2 |
| REV-FR-007 | Record validated reason, Customer explanation, immutable original/dependency references and scoped evidence. | 5 |
| REV-FR-008 | Enforce Pending review, Rejected, Cancelled and Approved-and-posted states without an approved-unposted gap. | 6 |
| REV-FR-009 | Restrict cancellation to the authorized requesting Agent while pending and make terminal decisions immutable. | 6 |
| REV-FR-010 | Validate original posting/live effect/status/scope/type and reject unposted/already-compensated/unsupported originals. | 7.1 |
| REV-FR-011 | Allow corrective posting for Active/Inactive/Restricted Customers and require Archived restoration. | 7.1, 10 |
| REV-FR-012 | Build an authoritative versioned dependency graph and fail closed on unknown/blocking effects. | 7.2 |
| REV-FR-013 | Enforce one pending and at most one posted full reversal per original/posting group. | 7.4 |
| REV-FR-014 | Compensate a full contribution/combined receipt, slot funding, direct fees and custody atomically. | 8.1 |
| REV-FR-015 | Prevent liability below reservations/downstream payouts and prohibit unrelated automatic reversals. | 7.2, 8.1 |
| REV-FR-016 | Apply Module 06 completion/closed-cycle correction rules without altering successors. | 8.1, 10 |
| REV-FR-017 | Compensate erroneous fee receipts/applications/deductions while preserving valid obligation semantics and permission boundaries. | 8.2 |
| REV-FR-018 | Reverse a completed withdrawal only with authoritative full payout disposition/return and complete group compensation. | 8.3 |
| REV-FR-019 | Classify receipt/payout physical disposition separately from ledger correction; preserve controlled money and block uncertain/partial return. | 3, 7.3, 8.2–8.3 |
| REV-FR-020 | Keep remittance/reconciliation corrections under `reconciliation.manage` and append effects to prior reconciled versions. | 8.4 |
| REV-FR-021 | Commit decision, balanced entries, owner events, audit and outbox atomically/exactly once. | 9.2, 11 |
| REV-FR-022 | Require separately confirmed current-authority replacement transactions with new identities/times. | 9.3 |
| REV-FR-023 | Preserve requests/actors/custody through reassignment, Agent unavailability and offboarding handover. | 10 |
| REV-FR-024 | Preserve Customer status holds, archive gates and terminal-plan exceptions without automatic activity. | 10 |
| REV-FR-025 | Make all mutations idempotent and resolve timeouts/unknown results before retrying. | 11 |
| REV-FR-026 | Serialize concurrent original/dependency changes and reject stale versions before posting. | 11 |
| REV-FR-027 | Provide scoped Agent/Admin/Customer screens, queues, history and safe error/accessibility states. | 12 |
| REV-FR-028 | Send scoped deduplicated lifecycle notifications without coupling delivery to financial outcome. | 13 |
| REV-FR-029 | Retain append-only masked audit and immutable original/compensation/evidence access history. | 13 |
| REV-FR-030 | Meet integer arithmetic, authorization, evidence security, observability, recovery and contract-test release gates. | 14 |

## 16. Acceptance scenarios and traceability

An acceptance scenario is **Blocked**, not Passed, when an authoritative owner or approved policy is missing. Evidence records build/configuration, fixture, actor/permission/version, operation IDs, exact pre/post entries and expected/actual owner events.

| ID | Requirements | Scenario and expected result |
| --- | --- | --- |
| REV-AC-001 | REV-FR-001, REV-FR-002 | Reverse an eligible full contribution; original remains visible, linked balanced compensation removes live effect, and direct edit/delete/partial API attempts fail. |
| REV-AC-002 | REV-FR-003 | Attempt to label a valid charge as error, waive debt, add a deduction, correct remittance or reverse business draw through this flow; route/deny with no effect. |
| REV-AC-003 | REV-FR-004 | Eligible active current Agent initiates for assigned Active, Inactive and Restricted Customers; unassigned/inactive/suspended Agent and Customer fail safely. |
| REV-AC-004 | REV-FR-005 | Customer/Admin initiation and Agent approval endpoints fail; one Admin with `reversals.review` approves any supported value without a second approver. |
| REV-AC-005 | REV-FR-005, REV-FR-006 | Admin lacks/revokes permission or fresh authentication expires before commit; decision/posting fails and stays Pending review. |
| REV-AC-006 | REV-FR-007 | Missing/overlong reasons, unsafe explanation, invalid/malicious/oversize evidence and mismatched references fail; valid immutable evidence remains attributable. |
| REV-AC-007 | REV-FR-008 | Submit, reject and cancel separate requests; terminal histories persist and none changes money. Successful approval has no durable approved-unposted state. |
| REV-AC-008 | REV-FR-009, REV-FR-023 | Requester cancels while pending; replacement/former Agent and stale requester cannot cancel after reassignment or decision. |
| REV-AC-009 | REV-FR-010 | Draft/failed/pending transaction, already reversed original, unsupported partial correction and wrong Customer/type are rejected without existence leakage. |
| REV-AC-010 | REV-FR-011 | Correct Restricted Customer without enabling payout/new transaction; Archived Customer blocks until identity-preserving restoration to Inactive. |
| REV-AC-011 | REV-FR-012 | Graph contains compensable, retained, blocking and unknown dependencies; only complete supported graph can reach approval. |
| REV-AC-012 | REV-FR-013, REV-FR-025 | Concurrent Agents/retries target same original; one Pending request and at most one compensation commit. |
| REV-AC-013 | REV-FR-014 | Reverse multi-slot combined savings/fee receipt; remove all live allocations/components, update liability/obligation/earnings/custody once and preserve history. |
| REV-AC-014 | REV-FR-014, REV-FR-019 | Reverse false duplicate, actually received/wrong-Customer, returned and uncertain receipt fixtures; compensate nonexistent custody, preserve real money in linked suspense or evidenced return, and block uncertainty without claiming cash returned. |
| REV-AC-015 | REV-FR-015 | Contribution reversal would put liability below live reservation/completed payout; approval blocks without releasing or reversing the unrelated withdrawal. |
| REV-AC-016 | REV-FR-016 | Reversal unfunds Completed plan; it becomes Paused with prior completion retained. Closed plan stays Closed with exception and successor unchanged. |
| REV-AC-017 | REV-FR-017 | Reverse valid-assessment fee payment/application; restore valid obligation and liability/income/custody as applicable, without waiver/refund/new fee. |
| REV-AC-018 | REV-FR-017 | Reverse erroneous deduction; restore Customer liability and destination exactly, while replacement deduction requires separate grant/action. |
| REV-AC-019 | REV-FR-018, REV-FR-019 | False-undelivered or fully returned payout posts complete withdrawal-group compensation; delivered/unreturned/partial/unknown payout blocks. |
| REV-AC-020 | REV-FR-018 | Pending/rejected/cancelled/approved-unpaid withdrawal is sent to Module 08 cancellation/hold path and creates no reversal. |
| REV-AC-021 | REV-FR-020 | Receipt reversal touches reconciled batch; old review stays immutable and linked exception/supplement appears; reviewer cannot mark remittance resolved. |
| REV-AC-022 | REV-FR-021 | Fault each ledger/plan/fee/reservation/custody/audit boundary; either complete bundle commits once or no decision/effect commits. |
| REV-AC-023 | REV-FR-022 | After reversal, replacement uses new ID/current confirmation/time/Agent, never auto-replays or backdates the original. |
| REV-AC-024 | REV-FR-023 | Reassign/suspend/offboard after valid initiation; request remains reviewable, actors/cash liability stay original, task access follows current scope. |
| REV-AC-025 | REV-FR-024 | Pending correction blocks archive; later archived discovery records investigation and requires restoration, without automatic financial mutation. |
| REV-AC-026 | REV-FR-025 | Lose responses during initiation/approval and retry same/different payload; same returns committed result, different conflicts, no duplicates. |
| REV-AC-027 | REV-FR-026 | Race approval with new payout reservation, fee refund, remittance, plan closure or another compensation; stale preview fails and reloads complete graph. |
| REV-AC-028 | REV-FR-027 | Verify scoped searches/counts/direct links across roles/reassignment, responsive keyboard/screen-reader flow, and safe loading/stale/unknown states. |
| REV-AC-029 | REV-FR-027 | Customer statement shows original and reversal/net effect without private evidence, deletion or false physical-return claim. |
| REV-AC-030 | REV-FR-028 | Delivery fails after commit; one financial result remains, retry is deduplicated and current scope is checked before delivery. |
| REV-AC-031 | REV-FR-029 | Every request/decision/bundle/failure has masked immutable audit; detailed access/export obeys independent grants and secrets never appear. |
| REV-AC-032 | REV-FR-030 | Exercise overflow, unbalanced entry, evidence malware/access, owner outage, restore/rebuild and missing contract; fail closed or rebuild exact projections. |

## 17. Worked examples

### 17.1 Duplicate contribution

Agent A accidentally posts two distinct ₦2,000 cash receipts to Day 5 and Day 6. The current assigned Agent requests reversal of the duplicate receipt. The preview removes ₦2,000 Customer liability, its one slot allocation and Agent A's ₦2,000 cash responsibility. If Day 6 had contributed to completion, the plan correction is included. Approval appends balanced entries; it does not delete either receipt. If the Customer truly paid for Day 6 later, the Agent records a new receipt.

### 17.2 Combined receipt with fee

A ₦3,000 cash receipt allocated ₦2,000 to savings and ₦1,000 to a registration-fee obligation was posted to the wrong Customer. Full reversal removes ₦2,000 from that Customer's liability/slot, compensates the ₦1,000 fee settlement and income, restores the valid obligation, and compensates the original Agent's ₦3,000 custody effect. The correct Customer receives a separately recorded receipt after approval; the reversal cannot move the old receipt between Customers.

### 17.3 Reservation conflict

Customer liability is ₦10,000, including an erroneous ₦4,000 contribution, with a live ₦7,000 payout reservation. Reversal would leave liability ₦6,000 below the reservation. Approval is blocked. `reversals.review` cannot release the reservation or shrink the payout request; the withdrawal owner must resolve it through its authorized workflow before a refreshed review.

### 17.4 Delivered withdrawal

A posted ₦10,000 gross withdrawal paid ₦9,800 to the Customer and applied a ₦200 fee. Discovering wrong Customer selection does not restore ₦10,000 savings immediately. If the ₦9,800 is authoritatively confirmed returned, the full payout group, fee marker/income and Customer liability can be compensated atomically. If only ₦5,000 returns or delivery is uncertain, initial full reversal stays blocked and the future recovery/adjustment owner handles the real receivable.

## 18. Proposed decisions and release gates

| Decision | Draft recommendation / consequence |
| --- | --- |
| Scope | Full reversal only, one original posting group; replacement is separate. Partial corrections remain blocked. |
| States | Pending review, Rejected, Cancelled, Approved and posted; approval/posting atomic. |
| Evidence | Up to three JPEG/PNG/WebP/PDF files, 5 MB each per evidence record, immutable supplements; finalize retention/reveal before release. |
| Fresh authentication | Admin review uses Authentication's shared password-and-MFA freshness policy; no competing interval. |
| Correction period | No arbitrary age cutoff; current-period compensation date with original date preserved. Accounting period policy must exist. |
| Withdrawal correction | Require proven never-delivered or fully returned net payout; partial/uncertain recovery remains blocked pending recovery/adjustment workflow. |
| Dependency breadth | Only direct required compensation in one approval; independent later activity is retained or blocks, never silently bundled. |
| Completed/closed plans | Completed shortfall becomes Paused; Closed remains terminal with linked exception. Confirm with Module 06 implementation. |
| Reconciled custody | Append exception/supplement while retaining prior batch review; Module 07 defines accounts and resolution. |
| Valid refunds/waivers | Remain Module 05 actions under their own permissions; never represented as erroneous reversal. |
| Authority gaps | Do not create an Admin initiator, Agent approver, manual-journal role or new permission. Revise Module 03 explicitly before adding capability. |
| Posting architecture | Shared balanced ledger and exactly-once owner protocol must be contract-tested before any approval endpoint is enabled. |

## 19. Related modules

This module consumes role/scope/status from Modules 01–04; fee obligations, earnings, refunds and trigger markers from Module 05; plan progress and closed exceptions from Module 06; receipts, allocations, custody and reconciliation from Module 07; and reservation/payout states from Module 08. Reporting, Statements, Notifications and Audit consume its immutable original/compensation links. No downstream module may interpret reversal as deletion, edit, automatic refund or evidence that physical money moved.
