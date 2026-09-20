# Withdrawals and Payout Approvals

**Product version:** 2.0  
**Module:** 08  
**Module status:** Detailed draft for review  
**Sources:** [Version 1 PRD](../../PRD.md), [User Types](./01-user-types.md), [Authentication](./02-authentication.md), [Roles and Permissions](./03-roles-and-permissions.md), [Customer and Agent Management](./04-customer-and-agent-management.md), [Fees and Deductions](./05-fees-and-deductions.md), [Thrift Plans and Savings Cycles](./06-thrift-plans-and-savings-cycles.md), [Collections, Digital Thrift Card, and Reconciliation](./07-collections-thrift-card-and-reconciliation.md)  
**Downstream dependencies:** forthcoming Reversals and Corrections; Ledger, Balances, and Statements; Reporting and Audit; Business Configuration

## 1. Purpose and decision status

This module defines how an assigned Agent requests a payout from a Customer's posted savings, how one authorized Admin approves or rejects it, how funds are reserved, and how an approved external payout becomes an immutable financial posting. It prevents duplicate payouts, overdrawn Customer savings, fee double charging, approval bypass, and false success when payment outcome is unknown.

The PRD establishes partial, full, and cycle-completion withdrawals, available-balance checks, payment methods, confirmations, and append-only financial history. Modules 01–03 replace the PRD's single-collector authority with a closed role model: the currently assigned eligible Agent initiates; one active Admin with `withdrawals.review` approves or rejects; the Customer has read-only access; an Admin cannot initiate and an Agent cannot approve. These boundaries are confirmed.

Unless already established by Modules 01–07, details below are **proposed for review**. This includes request expiry, supported methods, fresh Admin authentication, cancellation rules, evidence fields, hold behavior, full-withdrawal meaning, and payout-executor integration. Undefined external payout rails or execution authority are release gates, not permission to treat approval as payment.

## 2. Initial scope, exclusions, and ownership

### 2.1 Initial scope

- One configured business and NGN amounts stored as integer kobo.
- Individual partial, full, and end-of-cycle withdrawal requests from exactly one source savings cycle.
- Agent submission, one-Admin review, a live gross reservation, Customer-status holds, expiry/cancellation/rejection, payment execution result intake, atomic posting, failed/unknown-result recovery, read views, notifications, and audit.
- Cash and bank-transfer method records only after their execution authority, custody mapping, evidence, and finality contracts pass Section 18's gates.
- One fee quote from the selected cycle's immutable Module 05 snapshot. Initial withdrawal-specific non-fee deduction amount is zero unless a later reviewed contract supplies independently authorized terms.

### 2.2 Deferred and prohibited scope

Customer self-service requests; Admin initiation; Agent approval; scheduled/recurring payouts; batch withdrawals; split source cycles; split payout methods; cross-Customer transfer; third-party beneficiary payout; cheque/card/crypto/mobile-money rails; multi-currency; overdrafts; credit; negative balances; payout edits after submission; partial execution of one approved request; cash advances before approval; offline approval/execution; automatic approval; risk scoring; maker-checker rules beyond the fixed Agent/Admin separation; and destructive deletion are deferred.

No role may use a withdrawal to record a fee-earnings business draw, Agent remittance, fee refund, correction, reconciliation adjustment, or arbitrary ledger debit. Module 05 owns business fee draws/refund entitlements; Module 07 owns receipts/remittance; Reversals owns corrections.

### 2.3 Ownership matrix

| Owner | Authoritative responsibility |
| --- | --- |
| This module | Request terms/state, gross reservation, quote linkage, review decision, hold/expiry/cancellation, payout execution orchestration, posting bundle, and withdrawal history. |
| Module 04 | Customer operational/account states, current effective assignment, Agent eligibility, reassignment/offboarding handover and archival gates. |
| Module 05 | Fee snapshot, timing/basis/rounding, once-only trigger, fee assessment/application, and any future withdrawal-specific deduction contract. |
| Module 06 | Source cycle identity/state, cycle-attributed posted principal/liability, completion/closure gates, and settlement lineage. |
| Module 07 / shared ledger | Posted Customer liability, receipt/custody mappings, immutable balanced-entry protocol, and authoritative available-savings input. |
| Authentication / Module 03 | Sessions, MFA/fresh authentication and fixed roles/permissions. |
| External payout adapter/custody owner | Method availability, verified destination, execution authorization, provider idempotency, result/finality evidence and settlement. |
| Reversals | Agent-initiated, one-Admin-approved linked compensation after posting. |
| Statements/Reporting/Audit | Financial presentation, exports, retention, privileged audit access and reporting date policy. |

## 3. Terms, amount contract, and invariants

- **Posted Customer liability (L):** savings owed to the Customer after posted contributions, payouts and savings-funded fees/deductions. It still includes funds reserved for an unposted withdrawal.
- **Live gross reservation (R):** claim on savings for one unposted request. It changes availability but does not change liability or represent payout.
- **Available savings (A):** `L − sum(live gross reservations)`. Never subtract a reservation from liability itself.
- **Gross savings debit (G):** amount requested from Customer savings and reserved. It is the request amount in the initial UI/API.
- **Withdrawal fee (F):** Module 05 quote settled from G when the payout posts.
- **Withdrawal deduction (D):** a separately authorized future withdrawal-time non-fee deduction. Initial scope uses `D = 0` unless the dependency contract is explicitly enabled.
- **Net Customer payout (P):** `G − F − D`. Require `P > 0`.
- **Source cycle available:** cycle-attributed posted liability minus that cycle's other live gross reservations and any separately authoritative source constraint.
- **Payout execution:** attempt to transfer/deliver P using the approved destination and method. Approval is not execution.
- **Posted withdrawal:** immutable balanced financial bundle after definitive successful payout evidence.

At request submission, require `0 < G ≤ min(customer available savings, source cycle available)` and a valid quote with `F + D < G`. The reservation equals G, not P. A Customer's total liability must include reserved savings in reporting; an available-balance view separately subtracts live reservations.

At successful posting, atomically:

1. consume exactly this request's live reservation G;
2. debit Customer savings liability once by G;
3. credit/reduce the approved payout asset or custody account by P;
4. credit fee income/settle the linked fee by F under Module 05;
5. credit the approved non-fee destination by D, if a future contract enables it; and
6. persist the request/transaction/evidence/audit/outbox links.

Thus `G = P + F + D`. No component may also debit savings separately. Submission, review, approval, hold, notification, provider acceptance without finality, reconciliation, or screen state does not independently alter liability or recognize F/D. Reject arithmetic overflow and never use binary floating-point arithmetic.

All posted entries are balanced, immutable, uniquely identified, and linked. A correction appends an approved compensating bundle; it cannot edit the request, approval, provider evidence, or original financial entries. A payout cannot consume another request's reservation.

## 4. Authority, authentication, and eligibility

### 4.1 Closed-role action matrix

| Action | Customer | Current eligible assigned Agent | Admin |
| --- | --- | --- | --- |
| View request/history | Own records | Currently assigned Customers; historical own-action scope as allowed by Module 04 | Business-wide baseline read |
| Create/submit request | No | Yes, subject to all gates | No |
| Edit draft before submission | No | Yes | No |
| Cancel submitted request before approval | No | Current assigned Agent under Section 10 | No |
| Approve/reject | No | No | Exactly one active Admin with `withdrawals.review` |
| Manually release a reservation outside its request transition | No | No | No; Rejected/Cancelled/Expired transitions release atomically, and a release failure is an integrity incident rather than a balance-edit action |
| Execute/record payout result | No | No inferred authority | Defined payout adapter/operator only after release gate |
| Reverse posted withdrawal | No | Current assigned Agent initiates through Reversals | One Admin with `reversals.review` approves |
| Export business-wide requests | No | No | `reports.export` separately |
| View detailed audit | No | No | `audit.view` separately |

`withdrawals.review` does not imply `reversals.review`, `fees.manage`, `deductions.manage`, `reconciliation.manage`, `customers.manage`, `customers.reassign`, payout execution authority, or business settings authority. Baseline Admin read access does not permit a decision. There is no value threshold or second Admin approval in v2: one qualified Admin decision is sufficient for any amount. The Agent initiator and Admin reviewer are inherently different roles.

Proposed policy: approval/rejection, revocation of an unexecuted approval, and exceptional expiry resolution require fresh Admin password-and-MFA authentication using Authentication's shared freshness policy. Do not define another interval here. The server checks current account/session, current grant, Customer status, request version, quote and reservation immediately before decision commit.

### 4.2 Agent and Customer account eligibility

To create, submit or cancel, the Agent must have an activated account with required MFA, operational status Active, a currently usable session, current effective assignment, and no open offboarding block. Module 04's legitimate-session temporary-lock exception remains applicable; a new login failure does not revoke an otherwise explicitly valid session. Inactive, Suspended or Deactivated Agents cannot mutate requests.

Customer login activation is not required because the Customer does not initiate. An Invited, temporarily locked or otherwise login-inaccessible Customer may have an Agent-initiated request if operational status and all financial gates allow it. Account suspension/deactivation does not itself erase requests, release reservations, or prove business ineligibility. The interface shows account and operational states separately.

### 4.3 Customer operational-status matrix

| Operation | Active | Inactive | Restricted | Archived |
| --- | --- | --- | --- | --- |
| Read permitted records | Allowed | Allowed | Allowed | Allowed |
| Initiate request against existing savings | Eligible Agent | Proposed allowed settlement | Blocked | Blocked |
| Approve/reject pending request | Eligible Admin | Eligible Admin | Reject allowed; approval blocked | Archived should have no unresolved request |
| Start/continue payout execution | Eligible owning executor | Proposed allowed settlement | Blocked; activate or retain the hold overlay where applicable | Blocked |
| Post definitive successful payout | Allowed | Allowed if request remains settlement-eligible | Blocked while restriction exists | Blocked |
| Correct erroneous posted withdrawal | Reversals | Reversals | Reversals corrective exception | Restore first |

Inactive withdrawal is a proposed explicit settlement contract: it consumes existing savings and creates no plan, contribution, fee policy, or new Customer obligation beyond the already agreed withdrawal fee. Restricted immediately blocks initiation, approval and unposted payout, including an already approved request. Rejecting an existing request remains allowed because it releases rather than pays funds. Archived blocks all withdrawal mutations; archival itself requires no live request/reservation.

## 5. Withdrawal types and source-plan attribution

### 5.1 Partial withdrawal

Agent chooses G less than the source cycle's current withdrawable amount. Permitted for Active, Paused or Completed cycles when Customer status allows settlement. It does not complete, close, pause, renew, or change slots. An Active/Paused cycle may continue contributions against unchanged terms after posting.

### 5.2 Full withdrawal

“Full” means all currently withdrawable cycle-attributed savings for the selected cycle at submission, not all lifetime Customer money or a promise that no later corrections/contributions exist. Proposed rule: G equals source cycle available before this request; after this request reserves, source availability becomes zero. Fee F is funded inside G, so P may be less than G. Revalidate at approval and posting; a source increase does not silently enlarge the request, while a decrease invalidates/holds it.

Full withdrawal does not automatically close a cycle. An Active/Paused plan remains open unless separately early-terminated through Module 06. A Completed plan closes only after payout posting and every Module 06 settlement gate passes through a separate Agent plan action. No automatic waiver, slot change or renewal occurs.

### 5.3 End-of-cycle payout

Requires source plan state Completed and an authoritative settlement quote. Proposed G equals all cycle-attributed liability eligible for settlement before this request, and P is the resulting net payout. Module 05 must include any completion/withdrawal-timed fee exactly once and identify previously paid/applied/waived charges. If the fee is outstanding but not permitted from savings, unsupported, exceeds G, or produces `P ≤ 0`, submission is blocked pending valid fee settlement/disposition.

Posting an end-of-cycle payout does not itself set Closed. It supplies authoritative zero-liability/payout evidence to Module 06; reconciliation, fee/refund, correction, queued-job and other closure gates still apply. Early termination uses Module 06's reviewed fee outcome and then a partial/full settlement request as applicable; do not label an incomplete cycle Completed.

### 5.4 Source invariant

Every request identifies exactly one plan/cycle and currency. Initial scope does not withdraw an unallocated business-wide Customer balance or combine cycles. Module 06 supplies cycle-attributed liability and validates Active/Paused/Completed eligibility; Closed/Cancelled cycles reject new requests. If historical contributions cannot be attributed reliably, the request is Blocked rather than assigned to the newest plan. Plan reassignment, renaming, scheduled end or renewal does not rewrite source attribution.

Proposed initial concurrency rule: a source cycle may have at most one live withdrawal request whose primary state is Pending review, Approved — awaiting payout, Payout processing, Outcome unknown or Payment failed. A hold overlay does not create another primary state or release this capacity. This preserves an unambiguous once-only withdrawal-fee quote and Customer instruction. A terminal Rejected/Cancelled/Expired request releases capacity; Posted permits a later request only if that cycle still has eligible liability and its fee marker produces a valid current quote. Do not quote the same once-per-cycle fee to two competing live requests.

## 6. Request fields and validation

| Field | Requirement |
| --- | --- |
| Request ID | Server-generated immutable proposed format `WDL-000001`; never reused; public reference does not grant access. |
| Customer | Current assigned scoped record, server bound; cannot be supplied as authority. |
| Source cycle | Required eligible cycle, stable ID/revision/currency and source-balance version. |
| Type | Partial, Full, or End-of-cycle, consistent with Section 5. |
| Gross debit G | Positive NGN integer kobo; at most two entered decimals; proposed maximum 999,999,999,999 kobo; type/source/availability limits also apply. |
| Fee F | Server quote from Module 05 snapshot/trigger; read-only amount, model/basis/rounding/version/expiry and once-only marker. |
| Deduction D | Zero initially; future server-authorized amount/reference only. Never free-form in this module. |
| Net payout P | Server-calculated `G − F − D`, positive; read-only. |
| Requested payout method | Cash or bank transfer only when enabled by method registry; one method/request. |
| Destination | Cash recipient identity/acknowledgement policy or verified Customer bank destination reference; encrypted/masked and never arbitrary third-party payee initially. |
| Customer instruction evidence | Proposed required Agent attestation of Customer instruction plus method/destination; optional protected attachment subject to evidence policy. Not Customer login approval. |
| Reason/purpose | Required Customer-visible plain text, trimmed 1–500 characters. |
| Internal notes | Optional protected plain text, maximum 1,000 characters; omitted from Customer view/email. |
| Requested occurrence date | Proposed current business date only; backdated/future payout requests deferred. Actual payout/provider date remains separate. |
| Expiry | Server generated under Section 9; never client-extended. |
| Versions/actors/times | Trusted Customer/assignment/plan/liability/reservation/quote/request versions; initiator and server UTC timestamps. |

Trim text, reject executable markup/control characters except permitted newlines, unknown fields, foreign IDs, unsupported methods/currency, zero/negative/excess/extra-decimal values, `F + D ≥ G`, arithmetic overflow, stale quotes, unavailable balances, and unverified destination references. Client validation aids correction; server validation is authoritative. Do not silently round, cap, reduce, re-source, waive, or change payout method.

Bank destination data must come from an approved verified Customer payout-destination workflow. Store a token/reference, bank identifier, masked account digits and verified payee name needed for review; do not copy full credentials into audit, notifications, URLs or general search. Cash recipient verification and custody handoff are release gates. Evidence uploads follow Module 07's protected malware/type/size/retrieval principles and the final retention policy.

## 7. Quote, preview, submission, and reservation

1. Eligible Agent selects an assigned Customer, eligible source cycle/type, G, enabled method/destination and reason.
2. Load authoritative posted Customer liability, all live reservations, cycle-attributed available amount, plan/status/assignment versions, Module 05 quote and method registry. Unknown/unavailable owner data blocks preview.
3. Preview shows L, other live reservations, A, source amount, G, F, D, P, fee basis/timing, method/destination mask, plan/type, expiry policy, and post-payout projected liability/availability. Clearly distinguish gross savings debit from cash received.
4. Agent attests that the Customer instructed the request and confirms one server-bound attempt. This is operational evidence, not Customer authentication or signature.
5. At commit, re-check Agent/session/assignment, Customer/plan status, quote, destination/method, balances and versions. Atomically create one Pending review request, one live reservation G, durable attempt binding, audit event and notification intent. No liability debit, payout, fee recognition, or slot change occurs.
6. Return request and reservation references. Delivery failure does not undo or repeat submission.

Reservation acquisition must serialize with contributions, other requests/reservations, fee/deduction applications, reversals, plan closing/archival and payout posting. Two requests that individually fit stale A cannot jointly reserve more than current liability/source amount. An Agent cannot submit a replacement while the first attempt's commit outcome is unknown.

If a fee quote/version changes before submission, require a fresh preview and confirmation. After submission, freeze G/type/source/method/destination and the quote inputs. A valid change requires cancellation/rejection followed by a new request; it never edits the existing reserved request. Module 05 may revalidate outcome at approval/posting without silently changing Customer terms.

## 8. Lifecycle states, hold overlay, and action matrix

| State | Reservation | Meaning / permitted next actions |
| --- | --- | --- |
| Draft | None | Client-side/incomplete input; not a financial request. Preview, discard or submit. |
| Pending review | Live G | Submitted and awaiting one Admin decision. Admin approve/reject; current Agent may cancel before review. |
| Approved — awaiting payout | Live G | Approval committed; executor may begin only when all gates pass. Authorized Admin may revoke before execution under Section 10. |
| Payout processing | Live G | One execution attempt accepted/in progress. No cancellation, expiry, new execution or reposting. |
| Outcome unknown | Live G | Provider/custody response cannot establish success/failure. Reconcile same attempt; never retry with a new payment. |
| Payment failed | Live G | Definitive no-transfer result. Retry same approved request within limits or revoke/expire; no liability effect. |
| Rejected | Released atomically | Admin rejected before payout processing; terminal, no liability effect. |
| Cancelled | Released atomically | Agent cancelled Pending review, or authorized Admin revoked an Approved — awaiting payout/Payment failed request before execution; terminal. |
| Expired | Released atomically | Safe automatic/authorized expiry before execution; terminal. |
| Posted | Consumed atomically | Definitive payout and balanced bundle committed; terminal except Reversals. |

Store exactly one primary lifecycle state plus an optional hold overlay containing hold status, reason, start/end times and version. A hold may apply only while the primary state is Pending review, Approved — awaiting payout or Payment failed. It preserves the underlying state, decision and live reservation; while held, approval, execution and posting are blocked, while safe rejection or revocation remains available as defined below. “Submitted,” “reconciled,” “notification sent,” or “provider accepted” are evidence/substates, not alternate financial postings. Primary-state and hold changes append events with actor/system source, UTC time, reason, versions and source evidence.

Allowed primary-state paths are Draft → Pending review; Pending review → Approved — awaiting payout/Rejected/Cancelled/Expired; Approved — awaiting payout → Payout processing/Cancelled/Expired; Payout processing → Posted/Payment failed/Outcome unknown; Outcome unknown → Posted/Payment failed only through authoritative reconciliation; Payment failed → Payout processing/Cancelled/Expired. Applying or lifting a hold does not change the primary state. Lifting a hold revalidates the preserved primary state but never approves, retries, executes or pays automatically. Posted never returns to an editable or approved state.

After Posted, **Reversal pending** and **Reversed** are linked transaction projections owned by Module 09, not withdrawal-request primary states. The withdrawal request remains Posted and retains its original payout history while the linked correction supplies the current net effect.

## 9. Review, approval, rejection, and expiry

### 9.1 Review screen and decision

The reviewer sees Customer and current Agent; account/operational states separately; source plan/type; request and expiry; L/reservations/A/source balance; G/F/D/P; fee calculation and prior charge disposition; method/masked destination; instruction evidence; reason; relevant history/duplicates; and risk/owner failures without exposing credentials or unrelated Customers.

For approval, require `withdrawals.review`, proposed fresh password/MFA, confirmation and an Admin decision note of 1–500 characters. Re-check no restriction/archival, current plan eligibility, exact live reservation ownership/amount, positive net P, unchanged request/quote/destination versions, and that no posting/reversal/expiry won. Approval creates no payout/ledger entry and does not release reservation.

For rejection, require `withdrawals.review`, proposed fresh authentication, a required internal reason and Customer-facing explanation of 1–500 characters each. Rejection and reservation release commit atomically. A reviewer may reject a Pending request whether or not its hold overlay is active; once execution begins, use result/reversal handling, not retroactive rejection.

Approval and rejection cannot both commit. Two Admins racing use the same request version; one decision wins. One Admin approval is sufficient regardless of amount; configuring a second approval through the UI is not supported initial behavior.

### 9.2 Expiry policy

Proposed defaults: Pending review expires seven calendar days after submission; Approved/Payment failed expires seven calendar days after approval or last definitive failed attempt, whichever is later. Calculate deadlines in UTC while displaying business timezone. A method-specific shorter provider-token lifetime can block execution but cannot silently release the business reservation before this workflow safely expires/revokes it.

Expiry releases G only if no execution is in Payout processing or Outcome unknown and commits the terminal event/release/audit atomically. Restriction hold pauses automatic expiry and retains reservation, matching Module 04's approved-but-unpaid hold; on lift, recompute a minimum 24-hour review window from the restored state. This is a proposed safeguard requiring review because long holds reduce available savings. An Admin may reject/revoke safely while held. Payout processing and Outcome unknown never auto-expire.

No automatic resubmission, approval or payment occurs on expiry. A new request uses a new ID/attempt and current quote after the terminal release.

## 10. Cancellation, holds, reassignment, and offboarding

The current assigned eligible Agent may cancel Pending review with reason and confirmation while no Admin decision is committed. An Agent cannot cancel Approved — awaiting payout, Payout processing, Outcome unknown or Posted. An Admin with `withdrawals.review` may revoke an Approved — awaiting payout or Payment failed request before any execution attempt starts, with fresh authentication, internal reason and Customer explanation; state becomes Cancelled and reservation releases atomically. This revocation is not a reversal or financial edit.

When Customer becomes Restricted, atomically or through a serialized status/request protocol activate the hold overlay on Pending review, Approved — awaiting payout and Payment failed requests; block review approval and any new execution start. Execution start must acquire a durable lease under the same status ordering before an irreversible external side effect. If restriction wins, execution cannot start. If execution start wins, the status history records the in-flight exception: authoritative success must finish the already-started posting even if restriction commits meanwhile, while definitive no-transfer becomes Payment failed with the hold overlay active. This completion is recovery of an irreversible attempt, not authorization for a new payout. If Posted commits first, preserve it and do not imply the later restriction reversed money. Lifting restriction clears the overlay only after revalidation and never auto-approves, retries or pays. Archived transition must fail while any live reservation/request/payable result exists.

Reassignment preserves request ID, source, G/F/D/P, quote, reservation, initiator, approval/evidence and state. Former Agent immediately loses Customer request access and cancellation/follow-up authority, except masked historical own-action records permitted by Module 04. Replacement Agent gains current scoped follow-up and may cancel a still-Pending request; they do not become initiator or repeat Customer instruction evidence merely due to reassignment. A stale former-Agent action or queued job fails current assignment checks.

Agent Inactive/Suspended/offboarding blocks new initiation/cancellation but does not cancel requests or release reservations. Offboarding handover must name the replacement/current owner for pending Agent-side follow-up; final deactivation gates on supported handover. Original Agent attribution remains. Admin reviewer loss of access/grant after decision does not invalidate a committed approval, but a pending decision fails. Payout execution authority is separately rechecked.

## 11. Payout methods, execution, and evidence

### 11.1 Method registry and evidence

Each enabled method has a versioned registry entry defining custody/funding account, destination verification, executor identity/authority, required evidence, idempotency support, definitive success/failure/unknown semantics, timeout/reconciliation, value limits and operational availability. Missing/disabled/unknown mapping blocks submission or execution; do not fall back to cash.

| Method | Proposed request evidence | Required execution evidence / unresolved gate |
| --- | --- | --- |
| Bank transfer | Verified Customer-owned destination token, bank, masked account, verified name | Funding account, provider idempotency key/reference, initiation/settlement times, amount/currency, payee match, signed/provider result. Provider integration/finality and who can initiate remain gates. |
| Cash | Customer instruction and approved pickup/recipient identity reference | Named authorized custodian/executor, cash source, handoff time, Customer receipt/acknowledgement and evidence. Custody authority and reliable receipt policy remain gates. |

Do not store online-banking credentials, PINs, OTPs, full secrets, or provider tokens in request/audit. Encrypt destination/evidence references, malware-scan uploads, authorize every retrieval, use short-lived protected downloads, and log protected access as required by evidence policy. Customer-visible views show masked destinations and safe receipt references.

### 11.2 Execution protocol

1. Executor loads the Approved — awaiting payout request and current method registry, Customer/plan/status, reservation, fee quote, destination and funding/custody availability.
2. Re-check G/P/F/D, reservation, live source/liability, no hold/expiry/reversal, executor authority, method availability and evidence requirements. Liability may have changed through a correction; never rely on approval-time display alone.
3. Create/reuse one execution attempt and provider idempotency key bound to request, G/P/method/destination/funding account. Move to Payout processing before external side effect using a recoverable outbox/state protocol.
4. Send exactly P to the approved destination. Changed method/destination/amount requires revocation/new request, not mutation.
5. A definitive success invokes the atomic posting boundary in Section 3. If provider success exists but local posting initially fails, retain recoverable Payout processing/Outcome unknown evidence; never send P again. Reconcile and finish the same posting identity.
6. A definitive no-transfer response moves to Payment failed with reservation intact and safe reason. Retry uses the same approved request but a new bounded execution-attempt identity according to method rules; it cannot change financial terms.
7. Timeout, disconnected response, conflicting provider state, missing cash acknowledgement after claimed handoff, or ambiguous result moves to Outcome unknown. Preserve reservation and block retries/new execution/release until authoritative reconciliation resolves success or no-transfer.

No initial payout execution path is enabled until at least one method contract identifies a lawful system/operator authority consistent with Modules 01/03. `withdrawals.review` approval alone is not execution authorization; an Admin cannot use an internal “mark paid” button without verified external evidence. An Agent cannot physically pay or record success merely because they initiated.

### 11.3 Posting and reconciliation

Financial posting uses one immutable transaction group containing Customer liability debit G, payout P, fee F, optional D, reservation consumption and source-plan/request links. Its posting timestamp is server UTC. Retain request date, approval date, execution-attempt time, provider/cash occurrence time and provider settlement time as distinct evidence dates; the future statement policy chooses and labels the displayed transaction/effective date without changing posting order.

Provider settlement after an already definitive authorized payout moves custody/clearing accounts under the method's accounting map and does not debit Customer liability again. A provider rejection before definitive payout is Payment failed. A return after Posted is not a simple failed request: route to the reviewed payout-return/reversal contract, preserve original posting, and determine whether liability restoration and fee disposition are safe. Unsupported returns remain an exception and block cycle/Customer closure.

## 12. Failure, retry, idempotency, and concurrency

- Bind each request-attempt key to business, authenticated Agent, operation, normalized payload and current assignment scope. Same key/same payload returns one authorized result; changed payload conflicts. Separate keys still face reservation/source uniqueness and funds checks.
- Bind approval/rejection/revocation attempts to Admin, operation, request version and decision payload. A retry returns the committed decision if current read scope permits; it never repeats release/approval.
- Persist request+reservation+attempt+audit+notification intent atomically. Persist state transitions, reservation changes and required audit atomically. Posting uses the full balanced bundle or none.
- An unknown submission outcome remains Pending confirmation until the same key resolves. Do not create a second request. An unknown execution never releases funds or sends again.
- Uniqueness covers public ID, live reservation per request, request posting group, fee trigger/application, provider/cash execution source/reference, payout destination version, lifecycle event and notification event/recipient/channel.
- Serialize request submission/posting/release with contributions, savings charges/deductions, other reservations/payouts, receipt reversals, plan lifecycle and Customer restriction/archival. Invariants are checked at commit, not only preview.
- Financial correction that would make `L < live R` is blocked until the owners resolve reservations or use an approved coordinated correction; no negative availability.
- Definite validation/authorization/insufficient-funds failure commits no request/reservation/posting. Unknown dependency, stale version, arithmetic overflow, audit durability failure or unavailable authoritative owner fails closed.
- Post-commit notification/search/report projection failure retries from durable outbox without repeating business mutation. Projection lag is labelled with as-of status and never used to approve funds.
- No cleanup may delete Posted, Outcome unknown, evidence/source identities or duplicate-prevention bindings. Expired/Rejected/Cancelled remain in history.

## 13. Screens and user experience

### 13.1 Agent workspace

Scoped directory columns: request ID, Customer, source plan/type, G, P, method, state/hold, submitted/expiry time and permitted next action. Default newest submitted first with request ID tie-break; 25 rows/page and proposed 25/50/100 choices. Filters: Customer/name/phone/public IDs under Module 04 privacy rules, plan, type, state/hold, method, submission/date range and “needs my action.” Former-assignment Customer rows disappear except masked own-action/settlement history allowed by Module 04.

Creation is available from Customer profile, plan detail and withdrawal directory only to eligible current Agent. Review-friendly preview places G/F/D/P, available/source savings and destination together. Blocked actions state the safe cause/next step—restriction, insufficient availability, unsupported method, stale quote, ineligible plan—not a misleading generic error. Do not offer Admin initiation or Customer request controls.

### 13.2 Admin review queue

Baseline Admin may read the queue/detail; only current `withdrawals.review` displays decision/revocation actions. Default work requiring attention—Pending review, requests with an active hold overlay, Approved — awaiting payout and Payment failed—first, then oldest deadline, with request ID tie-break. Filters include primary state and hold separately, Customer/current/original Agent, plan/type, method, amount range, submission/decision/deadline and evidence completeness. Show requester and reviewer separately; never imply reviewer created the request.

Confirmation explicitly says approval reserves but does not itself prove payout; if a synchronous enabled adapter is ever coupled, preview must clearly show both independently and preserve state evidence. Reject/revoke confirmation shows G release. The execution/exception view distinguishes provider processing, unknown, definitive failed, definitive posted and settlement return.

### 13.3 Customer view and statements

Customer sees own request ID, plan/type, G, F, D, P, safe method/destination mask, primary state, hold status, dates, Customer-facing reason/outcome and linked Posted transaction/receipt. Customer cannot see internal notes, reviewer private reason, evidence secrets, business funding accounts or other Customers. Rejected/cancelled/expired requests remain visible but do not appear as financial debits. An active hold shows a safe explanation and business contact, not investigation detail.

Pending review and Approved — awaiting payout reservations appear separately from posted savings liability and available savings. Statements list only Posted financial entries as withdrawal transactions, with reversals as linked compensating entries; a request-history section may list non-posted outcomes distinctly. Never render an unavailable balance, fee, evidence or result as zero/success.

### 13.4 Interaction and accessibility

Provide responsive layouts without essential horizontal scrolling, semantic headings/tables, labelled inputs/errors, keyboard/touch operation, visible focus, screen-reader state/amount announcements, text in addition to color, safe timeout recovery and confirmation summaries. Distinguish loading, empty scope, no matches, unavailable owner, access revoked, validation error, pending confirmation and mutation failure. Preserve allowed non-secret input after validation errors; never cache full destination/evidence secrets in browser storage.

## 14. Notifications

| Event | Recipients | Proposed content/channel |
| --- | --- | --- |
| Request submitted | Customer and current Agent in-app; authorized review queue | Request/type, G/F/P, plan, method mask, reservation/expiry, no promise of approval/payment. Customer email proposed. |
| Approved/rejected/cancelled/expired | Customer and current Agent in-app; email for Customer | Decision/outcome, G/P, safe reason, reservation consequence, next permitted step. Internal reason omitted. |
| Hold applied/lifted | Customer and current Agent in-app; authorized review queue | Safe hold/resume explanation; no automatic payout promise. Deduplicate unchanged hold. |
| Execution processing/failed/unknown | Current Agent and authorized operational/review staff | Accurate state, safe method/reference and required action; Customer notified of material delay without false failure/success. |
| Posted | Customer and current Agent in-app plus Customer email | Receipt, G/F/D/P, method mask, plan, posting/occurrence date, resulting balances/as-of time. |
| Return/reversal progress | Owning workflow recipients | Preserve distinction between returned funds, requested correction and posted compensation. |

Queue notifications only after durable state commit. Use event/recipient/channel idempotency, bounded retries, current scope at dispatch/retrieval and safe templates. Reassignment suppresses old Agent Customer content; Admin recipient eligibility is checked at delivery. Delivery failure never reverses, reposts, releases or pays. Uncertain email acceptance is not request/payment uncertainty. Preferences, retention and mandatory-financial-notice policy remain owner decisions.

## 15. Audit and financial evidence

Audit draft/preview access only where policy requires, and always record submission/reservation, approval/rejection/revocation, hold/lift, expiry, execution attempt/result/unknown reconciliation, posting, provider return, denied/conflicting/unknown attempts, evidence access, and export. Each durable event includes event/request/reservation/transaction IDs; business; Customer/source plan; actor/role or system/adapter source; current assignment/grant context; G/F/D/P/currency; method/destination version mask; before/after state and request version; relevant liability/reservation versions; server UTC time; attempt/correlation/provider-safe reference; outcome and protected reason/evidence references.

Financial entries additionally retain counter-account mapping version, quote/snapshot/fee marker, execution finality evidence and balanced-group identity. Failed/denied actions accurately state non-commit or unknown result. Never audit credentials, MFA codes, full bank numbers, provider secrets, unrestricted attachments, or arbitrary raw failed payloads.

Audit is append-only and durable with the mutation; failure of required local audit capture blocks mutation. `audit.view` is required for privileged detailed audit, `reports.export` separately for business export. Customer/Agent history is a scoped product projection, not unrestricted audit. Retention may archive protected evidence but cannot remove financial links, finality proofs or idempotency identities while obligations/history require them.

## 16. Non-functional requirements

- **Integrity and recovery:** financial and reservation invariants survive restart, duplicate delivery and partial infrastructure outage. Recovery reconstructs request states/reservations/postings from durable authoritative records without guessed success.
- **Security/privacy:** server authorization on every read/write/evidence download; encryption in transit/at rest for destination/evidence; secrets excluded from logs/URLs; malware/content checks; least-privilege service credentials; tamper-evident provider evidence.
- **Availability:** read views may show labelled stale/as-of projections, but submission/review/posting requires available authoritative balance, reservation, plan, fee, status, audit and method owners.
- **Performance:** proposed measurement target, subject to supported dataset profile: p95 scoped list/read under 2 seconds and submission/review commit response under 3 seconds excluding fresh-auth and external payout latency. An accepted asynchronous operation returns durable state/reference within that target, never premature financial success.
- **Scalability:** stable cursor/page ordering and correct whole-filter totals at the declared supported dataset size; money totals use overflow-safe integers beyond individual-entry limits.
- **Accessibility:** target WCAG 2.2 AA for forms, queues, dialogs, tables and status messaging; release evidence covers keyboard, screen reader, zoom/reflow, contrast and error recovery.
- **Observability:** metrics/alerts for aged Pending review, Approved — awaiting payout and Outcome unknown requests; active hold overlays; reservation leaks; provider conflicts; posting recovery; duplicate-source rejection; audit/outbox backlog; and authorization denial—without exposing Customer secrets.
- **Time:** store event timestamps in UTC and preserve business timezone/method occurrence date separately. Deadline/display and statement date semantics are deterministic across timezone/calendar changes.

## 17. Worked examples

### 17.1 Partial withdrawal with no fee

Customer liability is ₦86,000 and there are no reservations. Agent requests G = ₦30,000, F = D = ₦0, so P = ₦30,000. Submission reserves ₦30,000: liability stays ₦86,000 and availability becomes ₦56,000. Approval does not change either figure. Definitive posting consumes the reservation and debits liability by ₦30,000, leaving liability/availability ₦56,000.

### 17.2 Percentage withdrawal fee

For a 2% withdrawal-time fee and G = ₦10,000, Module 05 returns F = ₦200; D = ₦0 and P = ₦9,800. Reserve G = ₦10,000. Posting debits Customer liability once by ₦10,000, pays ₦9,800 and credits ₦200 fee income. It must not debit another ₦200. A retry resolves the same posting.

### 17.3 Competing requests

Liability is ₦50,000. Request A reserves G = ₦30,000, leaving A = ₦20,000. Request B for ₦25,000 fails even though liability still displays ₦50,000; B for ₦20,000 may reserve if its source-cycle constraint also passes. Rejection of A releases ₦30,000 without changing liability.

### 17.4 Restriction after approval

An Approved — awaiting payout request reserves ₦40,000. The Customer becomes Restricted before payout, so the request retains that primary state with its hold overlay active and keeps the ₦40,000 reservation; no posting occurs. Lifting the restriction clears the overlay only after current checks and never sends automatically. If an authorized Admin safely revokes it first, cancellation releases G.

### 17.5 Unknown bank-transfer result

Provider times out after accepting execution identity. State is Outcome unknown; liability and reservation remain unchanged, and no second transfer may start. If authoritative reconciliation proves success, the original identity posts G/P/F once. If it proves no transfer, state becomes Payment failed with reservation retained for governed retry/revocation.

### 17.6 End-of-cycle fee and closure

Completed cycle has ₦62,000 attributed liability and one-day fee ₦2,000 due inside payout. G = ₦62,000, F = ₦2,000, P = ₦60,000. Posting leaves cycle liability zero and settles fee once. Plan remains Completed until Agent invokes Module 06 closure and reconciliation/correction/job gates all pass.

## 18. Release gates and proposed decisions

Dependent functionality is **Blocked**, not Passed, when any owner below is missing or unavailable:

1. Approve the single-source-cycle model; definitions of Partial, Full and End-of-cycle; G as the entered/reserved gross debit; positive P; and no partial execution.
2. Approve Pending review/Approved — awaiting payout expiry defaults, hold-paused deadlines, Agent Pending review cancellation and Admin pre-execution revocation with proposed fresh authentication.
3. Confirm Inactive Customer settlement initiation/posting, Restricted reservation-preserving holds/rejection, and Archived prohibition with Module 04.
4. Confirm Module 05 quote lifetime, gross-debit percentage basis, fee marker/revalidation, previously assessed/waived fee treatment, and `D = 0` until a separate authorized deduction contract exists.
5. Provide authoritative cycle-attributed liability, total liability, live gross reservations, available savings, closure gates and serialized reservation API.
6. For each payment method, approve executor role/service authority consistent with the closed role catalogue, verified Customer destination, custody/account mapping, funding sufficiency, idempotency, evidence, definitive finality/unknown recovery, return handling and operational limits. No method is live before this gate.
7. Approve cash recipient verification/handoff evidence and bank provider/settlement semantics; no generic “mark paid.”
8. Define payout-return and posted-withdrawal reversal bundles, fee consequences and impossible-to-recover/chargeback handling in Reversals; no manual adjustment workaround.
9. Approve statement effective-date/display rules, business timezone, evidence retention/reveal, notification channels, export policy, dataset/performance profile and WCAG evidence.
10. Approve one-live-request-per-cycle initial concurrency and the restriction/irreversible-execution ordering: restriction-first blocks send; execution-first must resolve that exact external attempt safely.

## 19. Indexed functional requirements

| ID | Requirement | Detail |
| --- | --- | --- |
| WDL-FR-001 | Enforce closed roles: current eligible Agent initiates, one `withdrawals.review` Admin decides, Customer reads own records, Admin cannot initiate and Agent cannot approve. | Section 4 |
| WDL-FR-002 | Separate Customer authentication state from operational/financial eligibility and recheck current Agent/Admin authority at commit. | Section 4 |
| WDL-FR-003 | Enforce Active/Inactive settlement/Restricted hold/Archived prohibition and preserve corrective reversal exception. | Sections 4.3, 10 |
| WDL-FR-004 | Support Partial, Full and End-of-cycle withdrawals with explicit distinct meanings and one eligible source cycle. | Section 5 |
| WDL-FR-005 | Validate all request fields, integer-kobo limits, positive P, protected destination/evidence and immutable trusted metadata. | Section 6 |
| WDL-FR-006 | Calculate and disclose `G = P + F + D`, source/total balances and Module 05 quote without double charging or floating arithmetic. | Sections 3, 7 |
| WDL-FR-007 | Acquire exactly one live gross reservation G atomically with submitted request/audit/outbox, without changing liability. | Section 7 |
| WDL-FR-008 | Freeze submitted terms and require cancel/reject plus a new request for changed amount/source/method/destination. | Section 7 |
| WDL-FR-009 | Maintain the defined append-only request/hold/execution lifecycle and allow only documented transitions. | Section 8 |
| WDL-FR-010 | Review complete current evidence/balances and require exactly one authorized fresh-authenticated Admin decision. | Section 9.1 |
| WDL-FR-011 | Release reservation atomically on safe rejection/cancellation/expiry without liability or earnings effect. | Sections 8–10 |
| WDL-FR-012 | Apply proposed deadlines, pause expiry while Restricted, and never expire Payout processing/Outcome unknown. | Section 9.2 |
| WDL-FR-013 | Restrict Agent cancellation to Pending and Admin revocation to approved/failed pre-execution requests, with reasons/version checks. | Section 10 |
| WDL-FR-014 | Preserve request/reservation/actors through reassignment/offboarding while changing current scoped follow-up immediately. | Section 10 |
| WDL-FR-015 | Enable a payout method only with versioned authority, custody, verified destination, evidence, finality, limits and accounting mapping. | Section 11.1 |
| WDL-FR-016 | Execute P once using a bound provider/cash identity; distinguish definitive success, definitive failure and unknown result. | Section 11.2 |
| WDL-FR-017 | Preserve reservation and block retries/releases during Payout processing/Outcome unknown; reconcile the same attempt. | Sections 8, 11.2 |
| WDL-FR-018 | Atomically consume G and post one balanced bundle debiting liability G and crediting payout P, fee F and approved D once. | Sections 3, 11.3 |
| WDL-FR-019 | Keep posting/request/approval/execution/occurrence/settlement dates distinct and route provider returns to an owning correction workflow. | Section 11.3 |
| WDL-FR-020 | Bind idempotent request/decision/execution attempts and financial/source uniqueness to prevent duplicates under retries/restarts. | Section 12 |
| WDL-FR-021 | Serialize funds, source, status, fee, plan, reversal, reservation, closure and archival races with fail-closed current checks. | Section 12 |
| WDL-FR-022 | Provide correctly scoped accessible Agent/Admin/Customer screens, status explanations and separate reservation/liability presentation. | Section 13 |
| WDL-FR-023 | Send deduplicated scope-checked notifications after commit without financial rollback/replay or false payout claims. | Section 14 |
| WDL-FR-024 | Capture append-only protected audit/financial evidence with separate audit/export grants and no secrets. | Section 15 |
| WDL-FR-025 | Meet reviewed integrity, security, availability, performance, accessibility, observability and time requirements. | Section 16 |
| WDL-FR-026 | Supply source-aware Posted/request projections to statements/reports without presenting non-posted states as transactions. | Sections 11.3, 13.3 |
| WDL-FR-027 | Keep plan lifecycle independent: payout never silently completes/closes/renews/changes slots; closure consumes authoritative settlement evidence. | Sections 5, 17.6 |
| WDL-FR-028 | Leave unsupported executor, payout rail, return/reversal, deduction, accounting or evidence integrations Blocked without invented authority/manual balance edits. | Sections 2, 18 |
| WDL-FR-029 | Permit at most one live request per source cycle initially and serialize restriction with durable execution start so once-only fees and irreversible results remain correct. | Sections 5.4, 10–12 |

## 20. Acceptance scenarios and traceability

These are future release scenarios, not claims of implementation or completed tests. Each evidence record includes scenario/requirement IDs, build and fixture, actor/account/grants/assignment/status, request/source/quote/reservation versions, exact pre/post L/R/A/G/F/D/P and ledger totals, method evidence, expected/observed states/audit/notifications, and Passed/Failed/Blocked. UI hiding alone is not proof of server authorization.

| ID | Requirements | Scenario and expected result |
| --- | --- | --- |
| WDL-AC-001 | WDL-FR-001 | Agent initiates assigned Customer request; Customer/Admin initiation and Agent approval endpoints fail with no request/reservation. |
| WDL-AC-002 | WDL-FR-001, WDL-FR-010 | Baseline Admin and wrong-grant Admin cannot decide; one current `withdrawals.review` Admin approves/rejects any amount; no second approval required. |
| WDL-AC-003 | WDL-FR-002 | Customer Invited/locked account with Active status remains Agent-operable; Agent Inactive/Suspended/Deactivated or stale assignment cannot mutate. |
| WDL-AC-004 | WDL-FR-003 | Active and proposed Inactive existing-savings requests pass owner gates; Restricted/Archived initiation fails. Restriction blocks approval/payout and retains live request reservation. |
| WDL-AC-005 | WDL-FR-003, WDL-FR-009 | Restrict an Approved — awaiting payout request, then lift; its primary state remains Approved — awaiting payout while the hold overlay blocks posting, the overlay clears only after revalidation, and it never auto-pays. Revocation while held releases safely. |
| WDL-AC-006 | WDL-FR-004 | Partial G below source amount succeeds; Full requires exact current source available; amount increase after submission does not enlarge it. |
| WDL-AC-007 | WDL-FR-004, WDL-FR-027 | End-cycle rejects Active/Paused source, succeeds for Completed valid quote, and Posted remains Completed until separate closure gates/action. |
| WDL-AC-008 | WDL-FR-004 | Closed/Cancelled/unattributed/multi-cycle source request fails without guessing or moving balances. |
| WDL-AC-009 | WDL-FR-005 | Validate amount zero/negative/max/max+1/extra decimals/overflow, text lengths/control markup, foreign IDs and protected-field injection; valid NGN stored exactly in kobo. |
| WDL-AC-010 | WDL-FR-005, WDL-FR-015 | Unverified/third-party/stale destination, unsupported method, infected/oversized evidence and stale protected link fail without leaking full destination or secrets. |
| WDL-AC-011 | WDL-FR-006 | G ₦10,000/F 2%/D 0 yields P ₦9,800; quote clearly labels all values and posting cannot debit a second fee. |
| WDL-AC-012 | WDL-FR-006 | Fixed fee ≥ G, future unsupported D, stale/missing Module 05 quote or arithmetic overflow blocks submission without silently cap/waive/prorate. |
| WDL-AC-013 | WDL-FR-007 | Liability ₦86,000/no reservations: submit G ₦30,000; liability stays ₦86,000, live R ₦30,000, A ₦56,000, no payout/income. |
| WDL-AC-014 | WDL-FR-007, WDL-FR-021 | Two requests race against ₦50,000; G ₦30,000 winner leaves only ₦20,000 reservable, so stale G ₦25,000 loses. |
| WDL-AC-015 | WDL-FR-008 | Attempt to edit submitted G/type/source/method/destination/quote; reject. Terminal release plus new request uses new ID/current quote. |
| WDL-AC-016 | WDL-FR-009 | Exercise every allowed state path and invalid transition; only documented transitions append, no state checkbox edit or liability side effect. |
| WDL-AC-017 | WDL-FR-010 | Review uses current balances/source/fee/evidence; missing freshness, grant, reservation, positive P, or unchanged versions prevents approval. |
| WDL-AC-018 | WDL-FR-010 | Two Admin decisions race; exactly one Approved or Rejected event commits and approval itself posts no money. |
| WDL-AC-019 | WDL-FR-011 | Reject/Agent-cancel/Admin-revoke/safe-expire each releases exactly G once, leaves L/fees unchanged and retains terminal history. |
| WDL-AC-020 | WDL-FR-012 | Pending review/Approved — awaiting payout/Payment failed deadline expires safely; Restricted hold pauses the deadline and restores the proposed 24-hour window; Payout processing/Outcome unknown never auto-releases. |
| WDL-AC-021 | WDL-FR-013 | Agent cancels Pending only; cannot cancel Approved/Processing/Posted. Admin revokes Approved before execution only; stale execution race has one ordered result. |
| WDL-AC-022 | WDL-FR-014 | Reassign pending/approved request; amounts/state/reservation/initiator remain, former Agent loses access/action, replacement gains current follow-up without impersonation. |
| WDL-AC-023 | WDL-FR-014 | Suspend/offboard initiating Agent; request persists, no reservation release, formal handover gates final deactivation, original attribution stays. |
| WDL-AC-024 | WDL-FR-015 | Each enabled cash/bank method proves executor, funding/custody map, verified destination, limits/evidence/finality; any missing contract reports Blocked and no fallback cash. |
| WDL-AC-025 | WDL-FR-016 | Definitive bank/cash success posts once; definitive no-transfer becomes Payment failed with R live and no liability debit. |
| WDL-AC-026 | WDL-FR-016, WDL-FR-017 | Timeout/ambiguous cash handoff becomes Outcome unknown; repeated click/new execution/release blocked until same attempt proves success or no-transfer. |
| WDL-AC-027 | WDL-FR-018 | Post G ₦10,000/P ₦9,800/F ₦200: consume R ₦10,000, debit L once ₦10,000, credit payout/fee exactly, balanced group/marker once. |
| WDL-AC-028 | WDL-FR-018 | Inject failure at reservation consumption, liability, payout, fee, transaction, audit or outbox persistence; entire posting bundle commits or none, while proven external success remains recoverable without resend. |
| WDL-AC-029 | WDL-FR-019 | Store and render request/approval/post/provider occurrence/settlement dates distinctly; later provider settlement never debits L again. |
| WDL-AC-030 | WDL-FR-019, WDL-FR-028 | Provider return after Posted creates owned exception/correction path; no status rollback, deletion, fee guess or generic manual adjustment. |
| WDL-AC-031 | WDL-FR-020 | Duplicate submit/decision/execution/post callbacks and restart resolve one request/reservation/decision/payment/post; changed payload conflicts. |
| WDL-AC-032 | WDL-FR-020 | Lost submission response resolves same attempt; reassignment before replay applies current read scope and cannot create a replacement request. |
| WDL-AC-033 | WDL-FR-021 | Contribution/deduction/reversal/second payout/restriction/closure races serialize; no `L < live R`, negative A, overdraw, payout while the hold overlay is active, or closed-cycle request. |
| WDL-AC-034 | WDL-FR-021 | Audit/balance/reservation/fee/status owner unavailable or stale returns safe failure/Blocked, never cached-zero authorization. |
| WDL-AC-035 | WDL-FR-022 | Direct URLs/search/filter/counts/actions for Customer/Agent/Admin stay scoped; private reason/evidence/funding data omitted and revoked access clears stale detail. |
| WDL-AC-036 | WDL-FR-022 | Desktop/mobile keyboard/screen-reader use distinguishes G/F/D/P, L/R/A and every loading/empty/error/unknown state without color-only meaning or essential horizontal scroll. |
| WDL-AC-037 | WDL-FR-023 | Emit each notification family, duplicate/retry/fail delivery and reassign recipient; only current safe recipients receive deduplicated accurate state, with no financial replay. |
| WDL-AC-038 | WDL-FR-024 | Verify committed/denied/conflicting/unknown/evidence-access events and balanced links; detailed audit/export grants stay separate and secrets/full bank data absent. |
| WDL-AC-039 | WDL-FR-025 | Restart/load/security/accessibility tests preserve invariants; measured targets and dataset profile recorded, and target miss is reported rather than false Passed. |
| WDL-AC-040 | WDL-FR-026 | Statements include Posted withdrawal/correction entries only; request history separately shows primary request state and hold status, reservations separate from L, and authoritative dates/as-of labels. |
| WDL-AC-041 | WDL-FR-027 | Full payout of Active plan does not close/reduce slots; completed payout supplies settlement evidence but reconciliation/fee/job blockers still stop closure. |
| WDL-AC-042 | WDL-FR-028 | Undefined executor/rail, future D, payout return, reversal, accounting mapping, cash verification or statement policy remains Blocked with no invented permission/mark-paid/balance edit. |
| WDL-AC-043 | WDL-FR-029, WDL-FR-006 | A second live request for the same cycle is blocked even when funds fit; terminal release permits a newly quoted request, and a later request after Posted respects the durable once-only fee marker. |
| WDL-AC-044 | WDL-FR-029, WDL-FR-003, WDL-FR-017 | Race restriction against execution start: restriction-first sends no payout and activates the hold; durable execution-first resolves that exact irreversible attempt to Posted or Payment failed with the hold active, without a second send or unrelated payout. |

Required fixtures include at least two Agents and assignments/reassignment histories; Agent active/inactive/locked/suspended/offboarding states; baseline and split-grant Admins with grant revocation mid-request; Customer account states and all four operational statuses; Active/Paused/Completed/Closed/Cancelled cycles; partial/full/end-cycle amounts; prior paid/waived/withdrawal-timed fees; sufficient/insufficient/source-mismatched liability; multiple live reservations; cash/bank enabled/disabled/unknown outcomes; payout returns; provider duplicate callbacks; missing owners; UTC/business-date boundaries; protected evidence; and concurrent submission/decision/posting/status/closure operations.

## 21. Proposed decisions summary

- G is the Agent-entered gross savings debit and the reservation amount; P is what the Customer receives after authoritative F and future D. P must remain positive.
- One request uses one cycle and one method; full means all currently withdrawable amount from that source, not lifetime Customer savings.
- Pending and approved/failed requests expire after proposed seven-day windows; restriction pauses expiry and keeps the reservation, with proposed 24 hours after lift.
- Agent may cancel Pending; `withdrawals.review` Admin may reject or revoke before execution with proposed fresh password/MFA. Approval never proves payout.
- Inactive Customers may settle existing savings through this workflow; Restricted holds preserve reservation and Archived is blocked.
- External payout executor/rail authority, cash handoff evidence, bank finality, returns, and reversal bundles must be approved before enabling a method. No generic “mark paid.”
- Posted accounting consumes G and debits liability once; fee/deduction components never create another savings debit.
- Initial scope permits one live request per source cycle; restriction and durable execution start have an explicit ordering so an already irreversible result is neither resent nor omitted from the ledger.
