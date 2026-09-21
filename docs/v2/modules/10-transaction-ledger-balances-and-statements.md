# Transaction Ledger, Balances, and Statements

**Product version:** 2.0  
**Module:** 10  
**Module status:** Detailed draft for review  
**Sources and dependencies:** [PRD](../../PRD.md), [User Types](./01-user-types.md), [Authentication](./02-authentication.md), [Roles and Permissions](./03-roles-and-permissions.md), [Customer and Agent Management](./04-customer-and-agent-management.md), [Fees and Deductions](./05-fees-and-deductions.md), [Thrift Plans and Savings Cycles](./06-thrift-plans-and-savings-cycles.md), [Collections, Digital Thrift Card, and Reconciliation](./07-collections-thrift-card-and-reconciliation.md), [Withdrawals and Payout Approvals](./08-withdrawals-and-payout-approvals.md), [Reversals and Financial Corrections](./09-reversals-and-financial-corrections.md)

## 1. Purpose and specification status

This module defines the authoritative financial subledger, the balances derived from it, the user-facing transaction history projected from it, and reproducible Customer statements. It provides the common accounting contract used by collections, fees, deductions, withdrawals, reversals, reconciliation, profiles, dashboards, reports, archival checks, and dispute investigation.

The PRD requires mathematically accurate balances, a unified transaction history, unique transaction references, Customer statements, and strict separation between Customer money and business earnings. Modules 01–07 further establish immutable compensating corrections, integer-kobo amounts, gross withdrawal reservations, Agent cash responsibility, and closed role permissions. Those rules are inherited. Account names, posting schemas, statement finality, pagination, and operating controls below are **proposed product decisions** unless stated otherwise.

The subledger is the authoritative source of posted financial effects. A transaction list, thrift card, fee-obligation status, withdrawal request, batch state, dashboard total, cached balance, or exported statement is a projection or workflow record. None may directly overwrite the subledger.

## 2. Initial scope and exclusions

Initial scope includes one NGN ledger for the configured business; balanced posting groups and immutable entries; Customer, cycle, Agent and business dimensions; contribution, fee, deduction, withdrawal, remittance, reversal and refund projections supplied by authorized owning workflows; current/as-of balances; scoped transaction search/detail; Customer statements; rebuild/integrity verification; and governed business exports.

| Included                                                                                              | Deferred or owned elsewhere                                                                           |
| ----------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------- |
| NGN amounts stored as integer kobo and checked cumulative arithmetic                                  | Foreign exchange, multiple currencies within one posting group, crypto or securities accounting       |
| Business subledger accounts and subsidiary Customer/Agent/cycle dimensions                            | Full statutory general ledger, tax filing, bank accounting package integration                        |
| Posted financial truth and non-posting reservation integration                                        | Collection, fee, withdrawal, reversal, refund and reconciliation workflow decisions                   |
| Append-only linked compensation from an authorized owning workflow                                    | Arbitrary manual journal screen, editable balances, direct Admin adjustment permission                |
| Customer statements rendered in application and downloadable PDF when the document service is enabled | Email/WhatsApp delivery, bulk statement runs, scheduled statements, certified legal statements        |
| Single-Customer scoped download and business/multi-Customer report export with proper authority       | Raw ledger-entry CSV, audit-log export, accounting-software export until formats/privacy are approved |
| Rebuildable derived projections and balance caches                                                    | Treating a mutable cache or search index as the financial source of truth                             |

The PRD lists “Adjustment” as a transaction type, but no existing role or permission authorizes arbitrary financial adjustments. The type is reserved for a future named, reviewed owning workflow with explicit counter-account, reason, approval and correction rules. It is unavailable in initial scope.

## 3. Accounting principles and invariants

1. Every posted financial event is represented by exactly one durable posting group containing at least two immutable ledger entries.
2. Within a posting group and currency, total debits equal total credits exactly. A group that does not balance cannot post.
3. Money is an integer number of kobo. Proposed maximum individual command/component/entry value is 999,999,999,999 kobo, aligned with Modules 05, 07 and 08; cumulative balances use a wider overflow-safe representation and are not truncated to that cap. No financial calculation or persisted amount uses binary floating point.
4. Every entry posts to a reviewed account and includes all dimensions required by that account. A generic suspense or “other” account is not a substitute for missing policy.
5. Posted groups and entries cannot be edited, deleted, re-dated, re-parented or reattributed. Corrections use linked compensating groups.
6. The unique posting key and source event bind one economic event to one posted group. Retries return the same result rather than posting again.
7. Workflow status and reconciliation status do not alter posted money. Only an authorized balanced posting or compensation changes a balance.
8. Customer savings liability, business fee earnings, Agent receivables, business custody assets, payables and reservations remain separately named and displayed.
9. Every displayed balance is reproducible from authoritative entries plus, for available savings, authoritative live reservations at an explicit cutoff.
10. No Admin role or permission implies a manual ledger-posting capability. `audit.view` and `reports.export` are read/export authorities only.

If an invariant fails, affected financial writes fail closed. Reads show a specific unavailable/investigation state rather than a guessed zero or silently repaired total.

## 4. Ledger data model

### 4.1 Account catalogue

An account has an immutable account ID/code, display name, account class, normal balance, currency, supported dimensions, effective interval, status and version. Proposed initial classes and controlled accounts include:

| Class / normal balance                            | Controlled purpose                                                                                                        |
| ------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| Asset / debit                                     | Business cash, bank, POS/provider clearing and other approved business custody assets.                                    |
| Agent receivable / debit                          | Money recorded as received into an Agent's custody and still due to the business; subsidiary by original recording Agent. |
| Customer savings liability / credit               | Principal owed to each Customer; subsidiary by Customer and, where required, source cycle.                                |
| Refund or payout payable / credit                 | Approved obligation owed but not yet paid where an owning workflow expressly creates it.                                  |
| Fee income / credit                               | Recognized business fee earnings, distinct by approved fee category/source.                                               |
| Other deduction destination / defined by policy   | Approved income or payable account for a named deduction; never assumed to be fee income.                                 |
| Business equity/expense/clearing / policy-defined | Only accounts required to keep an approved workflow complete; unavailable until their purpose and authority are reviewed. |

Account retirement prevents new source events from selecting it while preserving historical entries and balance computation. Renaming changes presentation prospectively without changing stored references or old statement snapshots. Account creation/mapping belongs to reviewed business configuration; Module 10 introduces no UI or permission to create accounts.

### 4.2 Posting group

Each posting group stores:

- Immutable server ID and unique human-readable posting reference.
- Source module, source event type and immutable source record/version.
- Idempotency/posting key, correlation ID and optional parent/compensated-group reference.
- Currency, debit total and credit total in kobo.
- Financial occurrence/effective date supplied under the owning policy.
- Server `committed_at` timestamp in UTC and captured business timezone/version for reporting.
- Initiating actor, approving/reviewing actor where applicable, and system executor separately.
- Customer, cycle, Agent, withdrawal, fee obligation, receipt, batch or reconciliation references required by the event.
- Posting schema/account-mapping version and durable audit-event reference.
- Posted or Compensated projection state derived from linked groups; no editable group status.

One source event cannot create two live posting groups. A combined event, such as a contribution with fee application or withdrawal with fee, uses one atomic group or a transactionally inseparable set with one correlation/root event and exact all-or-none recovery semantics.

### 4.3 Ledger entry

Each entry stores immutable entry ID, group ID, account ID/version, debit or credit side, positive integer-kobo amount, currency, required subsidiary dimensions, description code, and ordinal. Zero, negative, both-sided, directionless, or currency-mismatched entries are invalid. The entry cannot use display text as its accounting classification.

Dimensions must identify the Customer for Customer liability, the original responsible Agent for Agent receivable, and the appropriate cycle/obligation/request/custody destination where the source contract requires them. A current Customer reassignment does not change an historical entry's recording Agent or subsidiary dimensions.

### 4.4 References and uniqueness

Proposed human-readable financial transaction format is `TXN-YYYYMMDD-NNNNNN`, generated from a server sequence and presentation date in the captured business timezone; the numeric suffix is at least six digits and expands rather than wrapping if volume requires it. References are globally unique within the configured business, never reused, and do not carry authorization. Internal IDs and unique posting/source keys remain authoritative even if the display format changes.

Compensation groups receive new references and link to the original. External bank/provider references live in protected source/evidence records and use method/provider/destination namespaces; they are not accepted as the internal transaction ID. A reference collision fails safely and retries generation without repeating the financial event.

## 5. Posting patterns and owner boundary

Owning modules validate eligibility, amounts, approvals, physical-money state and policy. They submit a versioned posting command from a closed catalogue. The ledger validates the command schema, current mapping, dimensions, uniqueness and balance, then posts atomically. It cannot infer missing approval, fee, destination or payment success.

| Event                                                    | Conceptual balanced effect                                                                                                                                                |
| -------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Cash savings contribution                                | Debit original Agent receivable; credit Customer savings liability for gross savings.                                                                                     |
| Savings received directly into approved business custody | Debit mapped business asset/clearing; credit Customer savings liability.                                                                                                  |
| External fee receipt                                     | Debit approved custody/Agent receivable; credit fee income and settle linked obligation through Module 05.                                                                |
| Savings-funded fee                                       | Debit Customer savings liability; credit fee income; linked obligation settlement remains visible.                                                                        |
| Other authorized deduction                               | Debit Customer savings liability; credit the specifically approved income/payable destination.                                                                            |
| Customer withdrawal                                      | Debit Customer liability by gross debit G; credit payout asset/custody by net payout P, fee income by F, and approved deduction destinations by D, where `G = P + F + D`. |
| Cash remittance from Agent                               | Debit business custody asset; credit the same original Agent receivable; Customer liability unchanged.                                                                    |
| Fee-earnings withdrawal                                  | Debit the applicable business earnings/equity-clearing account under Module 05; credit payout custody. Customer liability unchanged.                                      |
| Approved refund/concession                               | Follow Module 05's defined savings restoration or external refund-payable/payout contract; never masquerade as a contribution.                                            |
| Approved reversal                                        | New balanced compensation of the eligible original/dependent bundle; original entries stay unchanged.                                                                     |

These are conceptual patterns; release requires a reviewed chart and exact schema. A ledger endpoint accepts only authenticated service commands from the owning workflow, never arbitrary client-supplied account lines. `fees.manage`, `deductions.manage`, `withdrawals.review`, `reversals.review`, or `reconciliation.manage` authorizes only its defined business workflow, not free-form debits and credits.

## 6. Transaction projection

### 6.1 Meaning

A user transaction is a stable business-facing projection over one root source event and its posting group(s), allocations, actors, status and linked compensations. It hides double-entry mechanics by default while retaining drill-down references for authorized investigation. One receipt split between savings and fee may appear as one receipt detail with distinct financial components and as correctly classified component rows where a report requires them; aggregates must not double count the parent and components.

Required fields include transaction ID/reference, Customer where applicable, type/subtype, positive gross amount, customer-facing signed effect, currency, financial occurrence date, UTC committed timestamp, display timezone, status, safe payment method/reference, description, plan/cycle, source record, created/recorded actor, approval actor if applicable, current/historical Agent distinction, compensation links and statement eligibility.

### 6.2 Type catalogue

| Type         | Customer-history meaning                                                                                                              |
| ------------ | ------------------------------------------------------------------------------------------------------------------------------------- |
| Contribution | Posted savings principal received; show gross savings and plan allocations.                                                           |
| Withdrawal   | Posted gross savings debit G, net payout P, fee F and other deductions D separately.                                                  |
| Fee          | Assessment is non-cash obligation activity; payment/application/recognition/refund effects are identified explicitly.                 |
| Deduction    | Authorized posted savings deduction with named category/destination.                                                                  |
| Reversal     | Linked compensation, with affected original type and net result.                                                                      |
| Refund       | Restoration of savings or external refund payable/payment under the owning policy.                                                    |
| Remittance   | Agent/business custody movement; excluded from Customer savings history unless shown as a non-balance-changing operational reference. |
| Adjustment   | Reserved and disabled until a detailed owning workflow and authority exist.                                                           |

Plan estimates, missed/skipped slots, pending fee obligations, pending/rejected withdrawals, live reservations, collection drafts, unconfirmed remittances and reconciliation state changes are not posted transactions. They may appear in separate request/obligation/reservation timelines and statement notes, never in posted activity totals.

### 6.3 Status and dates

Proposed initial financial transaction statuses are **Posted** and **Reversed**. Module 09 permits only a full posting-group compensation initially; partial compensation and partial reversal are deferred. A Posted transaction may show a separate **Reversal pending** workflow overlay without changing its financial status or net effect. Pending review, Rejected and Cancelled are reversal-workflow states, not posted-ledger states. Module 09 has no Approved-but-unposted state: approval and full compensation commit atomically as **Approved and posted**. An original transaction becomes Reversed only when that compensation succeeds.

Every transaction distinguishes:

- **Occurrence/effective date:** the business event date validated by its owning module, used for activity-period presentation.
- **Committed at:** immutable UTC instant the group entered the ledger, used for cutoff/replay/order and operational audit.
- **Received/requested/approved/paid dates:** source-specific dates retained and labelled; never substituted for each other.
- **Plan date/timezone:** used for slot/card semantics, not automatically the statement transaction date.
- **Statement cutoff:** committed-at boundary determining which entries existed in an issued view.

Late posting retains its true permitted occurrence date and later committed timestamp. A statement/report must disclose both when material. No client-supplied date changes `committed_at`, restores old authority or enters a closed/unsupported period.

## 7. Balance contracts

### 7.1 Customer savings and availability

For Customer C at cutoff T:

`Customer savings liability(C,T) = credit entries − debit entries in C's savings-liability subsidiary, for groups committed by T and not excluded by the as-of query.`

Equivalent business-language view:

`net contributions − net Customer cash payouts − net authorized savings deductions`, where withdrawal cash P plus included fee/deductions F+D equals gross debit G and the Customer liability is debited by G exactly once.

`Available savings(C,T) = Customer savings liability(C,T) − sum of live gross withdrawal reservations at reservation cutoff T.`

Reservations are authoritative encumbrance records from Module 08, not ledger entries and not negative contributions. Draft has no reservation. Pending review, Approved—awaiting payout, Payout processing, Outcome unknown and Payment failed retain live gross G; an active hold overlay preserves the underlying state and reservation. Posted consumes R atomically. Rejected, Cancelled and Expired release R without a ledger entry. A request changes available savings only when its live gross reservation exists. Never subtract a reservation twice or subtract G plus its included F/D. Outcome unknown cannot be retried or executed anew until resolved under Module 08.

Customer liability and available savings must not be negative. If a correction would breach liability or reservations, the owning workflow resolves dependencies first or remains Blocked. Outstanding fee obligations, future plan estimates, missed slots, Agent shortages and reconciliation states create no silent savings hold.

### 7.2 Plan/cycle balances

Cycle net contributions derive from Customer-liability contribution entries dimensioned to that cycle and their compensations. Cycle withdrawals/deductions are attributed only where the owning event references that cycle. A cycle balance is an analytical subsidiary and must reconcile to the Customer total across included cycles plus any explicitly allowed non-cycle Customer entries. No transaction is moved to a new cycle by editing history.

Thrift-card funded slots use Module 07 allocations, not liability debits from withdrawals. Withdrawing savings does not make a Paid slot unpaid. Plan estimates and remaining targets are never included in posted balance.

### 7.3 Business and Agent balances

| Measure                            | Authoritative derivation and limitation                                                                                                               |
| ---------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| Total Customer liability           | Sum of all Customer savings-liability subsidiary balances, including Inactive, Restricted and Archived Customers with outstanding liability.          |
| Agent receivable                   | Debit balance by original Agent for entrusted money minus confirmed remittance/eligible compensation. Reassignment does not transfer it.              |
| Business custody                   | Debit balances of approved cash/bank/POS/clearing assets, separated by account; not labelled Customer balance.                                        |
| Gross fee recognized               | Credits to approved fee-income accounts before refunds/compensations.                                                                                 |
| Net fee earnings                   | Net balance/effect of fee-income recognition, refunds and corrections under Module 05.                                                                |
| Fee earnings available to withdraw | Module 05's conservative minimum of recognized balance and verified free cash after protected liabilities/payables; not the fee-income account alone. |
| Outstanding fee obligations        | Derived by Module 05 from assessment/settlement/waiver entries; not included in savings or recognized earnings until its defined recognition event.   |
| Live reservations                  | Withdrawal-store total of gross G; shown separately from posted liabilities/assets and reconciled to reserved request records.                        |

Assets do not equal spendable profit. Customer liability, fee earnings and Agent receivables must never be combined into one generic “balance.” A complete chart includes any additional approved payables/equity/clearing required to interpret business position; missing accounts block claims of a fully balanced business financial position.

Withdrawal reporting names **gross savings debited (G)**, **net cash paid to Customers (P)**, **included withdrawal fees (F)** and **other included deductions (D)** separately. “Total withdrawals” must state whether it means gross savings debit or net cash payout; proposed Customer savings summaries use gross G because that is the liability reduction, while cash-flow summaries use P. No report may sum G and P as separate withdrawals.

### 7.4 Balance snapshots and caches

Current-balance tables and reporting snapshots may accelerate reads but are derived. Each stores ledger watermark/cutoff, projection version, computed value, generation time and status. Updating them is atomic with posting or replayable from the committed event stream. A cache lag displays its as-of time and cannot authorize a withdrawal, fee application, archival or other balance-sensitive action. Such actions read an authoritative consistent ledger/reservation view.

An authoritative zero is returned only when the account/subsidiary is available and contains no net effect. Missing service, incomplete rebuild, unknown watermark or integrity failure returns Unavailable, never zero.

## 8. Corrections, finality, and periods

Posted entries are final in the sense that they cannot be mutated; business effects may be compensated by later authorized groups. The owning reversal/refund module determines eligibility, request/approval, physical-money handling and dependent bundle. One `reversals.review` decision can authorize only the defined compensation bundle for its original event. It grants no manual journal, independent waiver, deduction, write-off or cash-return authority.

A compensation group references the original full posting group and, when necessary, dependency groups. It uses the current approved schema, records the reason/request/reviewer, and shows the original and compensation together. Initial scope does not reverse selected components or partially returned/delivered money. A withdrawal group can reverse only when the payout was never delivered or the full net payout has authoritatively returned; uncertain or partial return requires a future recovery/adjustment workflow. Chained compensation is prohibited unless the owning policy explicitly defines reversal-of-reversal semantics; initial corrections normally use a new correct business transaction after compensating the error.

Proposed initial period policy: there is no user-controlled ledger backdating or period-close override in this module. Owning modules may accept a bounded occurrence date, but `committed_at` is always current server time. An eventual close/reopen workflow requires a separate permission, accounting effects and audit specification; `business.settings.manage` does not imply it. Until then, issued statements disclose their cutoff and can be superseded by permitted late postings/corrections.

## 9. Projection, rebuild, and integrity reconciliation

### 9.1 Deterministic rebuild

Given the same immutable accounts, groups, entries, source versions and cutoff, rebuild must reproduce every ledger balance and financial transaction projection exactly. Rebuild runs in an isolated version, verifies counts/hashes/totals, then atomically promotes only a complete verified projection. During rebuild, existing verified reads may continue with a visible watermark; balance-sensitive writes use the authoritative posting store and cannot rely on the incomplete projection.

Search indexing, statement generation and reporting consume versioned projections. Missing index rows cannot remove ledger money. Reindex/replay uses source IDs and projection keys idempotently. Projection code changes create a new version and comparison evidence before promotion; they never rewrite entries.

### 9.2 Integrity controls

Continuously or on scheduled checks verify:

- Every group balances and contains valid account/currency/dimensions.
- Every entry belongs to exactly one committed group.
- Source event/posting key/group relationships are unique and resolvable.
- Subsidiary sums equal their controlling account totals.
- Customer, cycle and Agent projections reconcile to their entry dimensions.
- Posted withdrawal gross debits equal payout plus included fee/deduction components.
- Live reservation totals equal eligible withdrawal reservation records and no posted/cancelled request remains live.
- Fee obligation settlement/recognition projections reconcile to Module 05 and fee-income entries.
- Collection/remittance projections reconcile to Agent receivable and custody entries without changing Customer liability.
- Compensation links and projected statuses match actual compensating entries.

An integrity mismatch creates an incident/reference, freezes dependent financial writes at the narrowest safe scope, preserves unaffected reads/writes, alerts authorized operational staff, and remains auditable. An Admin cannot click “recalculate balance” to overwrite the difference. Resolution repairs projections from authoritative entries or uses a separately authorized business correction; it never edits history.

Operational collection/remittance reconciliation in Module 07 compares money/evidence and does not mark ledger entries correct by changing batch status. Ledger integrity reconciliation checks internal accounting consistency. Both retain separate owners, evidence and results.

## 10. Transaction search and detail

### 10.1 Search and filters

Scoped transaction lists support exact/partial transaction reference, permitted Customer identity/ID, type/subtype, posted status, occurrence-date range, committed-at range, payment method, cycle/plan, original/current Agent where allowed, amount range and reversal linkage. Customer phone/name search follows Module 04 privacy/normalization rules. Filters cannot broaden resource scope or reveal suggestions/counts outside it.

Proposed defaults: newest `committed_at` first; stable tie-breaker transaction ID; page size 25 with 25/50/100 choices. Occurrence-date sort is separately selectable and uses committed-at/ID tie-breakers. Use opaque cursor pagination bound to actor scope, query, sort, projection version and cutoff. Changing filters/sort restarts at the first page. Counts and totals apply to the complete scoped result, not the loaded page.

Date inputs state timezone and inclusivity. Invalid/reversed ranges, unsafe wildcards and above-limit ranges fail validation. Proposed interactive maximum is 366 occurrence dates; wider business analysis uses a governed report/export job. Searches should not expose whether an inaccessible transaction exists.

### 10.2 Transaction detail

Detail shows safe business fields, signed Customer effect, gross/component breakdown, current projection status, original/compensation links, source cycle, occurrence and committed dates, method/reference, actor roles, and a posting trace/reference appropriate to the viewer. It explains that remittance/reconciliation status does not change Customer credit.

Customers do not see internal account codes, other Customers, Agent settlement totals, private evidence or internal reasons. Agents see full permitted Customer transaction history while currently assigned and masked own settlement references where Module 07 allows; former assignment alone grants no Customer detail. Admin baseline access sees business financial detail but protected evidence, approval mutations, audit payload and exports retain separate permissions.

Direct URLs, cursors and downloadable artifacts re-check current scope. A known transaction ID grants no access. After reassignment, the former Agent's cached/detail/download access ends immediately while historical recording attribution remains visible to currently authorized viewers.

## 11. Authorization and exports

| Action                                     | Customer                       | Agent                                                                 | Admin                                                                 |
| ------------------------------------------ | ------------------------------ | --------------------------------------------------------------------- | --------------------------------------------------------------------- |
| View transaction list/detail               | Own Customer financial records | Current assigned Customers; permitted masked own settlement summaries | Business-wide baseline read access                                    |
| View current balances                      | Own balances                   | Current assigned Customers and own permitted settlement totals        | Business-wide baseline read access                                    |
| Generate/download one Customer statement   | Own statement                  | Current assigned Customer                                             | Business-wide baseline read access, subject to current Customer scope |
| Export multiple Customers/business report  | No                             | No                                                                    | `reports.export`                                                      |
| View raw detailed audit event              | No                             | No                                                                    | `audit.view`                                                          |
| Post arbitrary ledger group/change balance | No                             | No                                                                    | No permission exists                                                  |

A single-Customer statement is part of scoped record access, not a business-wide export. Proposed initial rate/size controls apply equally by role. An Agent cannot retain it after reassignment; generated links are short-lived and reauthorize download. Business/multi-Customer exports require `reports.export`, create an auditable job, re-check permission at execution and release, and omit raw audit/security/evidence data unless a separately approved export explicitly permits it.

`reports.export` does not grant `audit.view`, account configuration, financial mutation, approval or unmasked security evidence. `audit.view` does not grant statement export or posting. Export files include scope, filters, timezone, cutoff, generation time and projection version; encrypted transport/storage and expiry follow the approved Reporting policy. If that policy is unavailable, business export remains Blocked.

## 12. Customer statements

### 12.1 Contents and calculation

A statement is for exactly one Customer and one inclusive occurrence-date period in a declared timezone, proposed maximum 366 dates. It stores an immutable statement ID/reference, Customer identity snapshot and ID, period, currency, generation timestamp, ledger committed-at cutoff, reservation cutoff when included, projection/schema versions, requester and generation status.

Required content:

- Opening posted savings liability immediately before the period, considering only groups committed by the statement cutoff.
- Period activity ordered by occurrence date, committed-at and transaction ID, including contributions, gross withdrawals with net/fee/deduction breakdown, savings-applied fees, other deductions, refunds and reversals.
- Totals by type with original, compensation and net amounts clearly distinguished.
- Closing posted savings liability, satisfying `opening + signed net activity = closing`.
- Live reserved total and available savings at generation cutoff as separate informational values, when the authoritative reservation service is available.
- Plan/cycle reference, safe payment method/reference, transaction reference, occurrence date and later-posted indicator where applicable.
- Statement cutoff, generated-at time, timezone, currency, page numbering, verification/reference information, and correction/supersession notices.

Externally paid fees may appear in a separate fee activity section but do not reduce Customer savings. Unpaid fee obligations may appear as an informational amount sourced from Module 05 and never enter opening/closing savings. Missed slots and estimates are optional nonfinancial plan information and cannot be mixed into posted totals. Agent remittance is excluded because it does not change Customer money.

### 12.2 As-of, issuance, and corrections

Preview is transient. **Issued** means an immutable rendered snapshot was successfully created at a declared cutoff; it does not mean the period is legally/accounting-final. Proposed statuses: Generating, Issued, Failed, Superseded. A failed job has no downloadable partial statement and can retry the same generation key.

Regenerating the same period later may differ because a permitted late posting or compensation committed after the first cutoff. Never replace the old file. Issue a new statement linked as a successor, mark the earlier one Superseded when the new issue specifically corrects it, and show what transaction references/cutoff changed. An ordinary user-requested later as-of copy may coexist without alleging an error. A correction notice never edits the old PDF or ledger.

For a posting with an occurrence date before the statement start but committed after the old cutoff, a new statement recomputes opening balance. For one inside the period, it changes activity and closing. The previous statement remains reproducible at its stored cutoff. If an eventual period-close policy provides finality, this module must be revised before displaying Final; initial scope never labels a statement Final.

### 12.3 Generation and rendering

The generator reads one consistent ledger/projection cutoff and reservation cutoff. If balance, activity, source, reservation or identity snapshot cannot be consistently obtained, fail generation with no contradictory file; reservation unavailability may omit only the separately labelled available-savings panel when the product explicitly permits a posted-balance-only statement. Never substitute zero.

PDF output, when enabled, uses readable NGN formatting, repeating table headers, page numbers, accessible document metadata, no clipped rows and no color-only status meaning. The application view and PDF must agree on reference, cutoff and totals. Each artifact is integrity-hashed and stored with controlled access; a hash is evidence of unchanged content, not proof that the underlying period will never receive a later correction.

Customers can generate/view their own statement; a current assigned Agent with permitted read access and an Admin baseline viewer may generate for an authorized Customer. An operationally Inactive Agent may retain the read access Module 04 permits while remaining unable to mutate Customer finances. Statement generation does not post money, approve a request, notify an external recipient or bypass current status/privacy. Archived Customers retain permitted read access. Delivery by email/WhatsApp is deferred until recipient authorization, privacy and delivery audit are specified.

## 13. Screens and user experience

| Surface                   | Required behaviour                                                                                                                                  |
| ------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| Customer transactions     | Current posted/available/reserved values, scoped filters, signed effects and type/status/date distinctions; own records only.                       |
| Agent Customer ledger     | Assigned Customer balances/history, plan and original actor context; no editable amount or Admin approval action.                                   |
| Admin ledger explorer     | Business-wide read-only search, Customer/Agent/cycle dimensions, integrity indicator and permission-gated export link; no manual journal button.    |
| Transaction detail        | Section 10.2 fields, linked source/correction timeline and safe explanation of financial effect.                                                    |
| Customer statement centre | Period/timezone selection, preview metadata, generation progress, issued/superseded history and scoped download.                                    |
| Balance summary           | Separately labelled Customer liability, available/reserved savings, Agent receivables, custody, fee earnings and obligations with cutoff/watermark. |
| Integrity operations      | Authorized operational incident view, mismatch category/scope/watermark and rebuild status; investigation access never becomes edit authority.      |

Loading uses skeleton/text without placeholder zero. Empty authoritative history says No posted transactions. Unavailable, stale, rebuilding, permission-denied and no-filter-match states are distinct. A stale balance displays its cutoff and blocks dependent confirmation. Scope loss clears sensitive rows/artifacts and returns to an accessible route.

Use semantic tables/lists, accessible signed amount labels, text plus color for status, keyboard-operable filters/pagination/dialogs, visible focus, clear errors and mobile layouts without essential horizontal scrolling. Persist filter/page state only within the authorized session; clear it on account switch or scope loss.

## 14. Idempotency, concurrency, and failure handling

Every posting command, projection event, statement generation and export job uses a durable idempotency key bound to actor/service, source, normalized payload and action. Same key/payload returns the original result; changed payload conflicts. A timeout yields Unknown outcome and lookup by the same key before retry. Database/source uniqueness remains effective after transient request-cache expiry.

Posting serializes or otherwise validates affected subsidiaries, source versions and live reservation dependencies. Concurrent commands may both post only when each remains valid and the combined result preserves all invariants. Examples: two contributions may both credit savings; competing withdrawal/deduction/reversal commands cannot spend the same availability; one source event cannot post twice; a correction and original posting cannot cross into an orphaned state.

The financial commit includes entries, group, source linkage, projection event, balance-watermark work and canonical audit record atomically or through an equivalently durable recoverable protocol. Search index, analytics, rendered statement and ordinary notification failure may lag without rolling back money, but show their watermark and repair automatically. No API returns successful posting before the durable financial result exists.

If an account mapping, upstream approval, source record, reservation, required dimension, currency, audit sink or posting schema is unavailable/invalid, commit nothing. If the ledger commits and response is lost, source lookup resolves it. Recovery never creates an unbalanced placeholder or deletes one side.

## 15. Notifications and audit

This module emits posting/projection events for owning modules to create their required Customer/Agent/Admin notifications. It does not invent a new notification for internal double-entry lines. Transaction/posting details in a notice use safe business components and link to an authorized detail view; they never disclose account internals, another Customer, private evidence or audit payload.

Statement issue/failure and supersession create in-app operational notifications for the requester and Customer where applicable. No external statement attachment is sent in initial scope. Notification work is queued after durable generation/posting, deduplicated by event, re-checks recipient scope, and cannot repeat money or statement issue if delivery fails.

Canonical audit covers posting command outcome, group/reference/source, balance-sensitive denial, duplicate/conflict, correction linkage, projection/rebuild/integrity result, transaction/statement/export access, statement generation/supersession, and account/schema version use. Record actor/service/permission, source/target, time, reason, amounts/currency, versions/watermarks, result and correlation IDs without authentication secrets, payment credentials or raw evidence.

Audit history is append-only and detailed access requires `audit.view`. Financial transaction access does not expose audit payload. `audit.view` does not permit posting, rebuild promotion, statement export or incident resolution. A required financial audit event commits durably with the posting; audit-index/search failure cannot erase it.

## 16. Performance, retention, recovery, and security

Proposed targets on a documented representative dataset/device/network: current Customer balance and first 25 transactions within two seconds at p95; scoped reference lookup within one second at p95; statement preview metadata within two seconds at p95; a 366-date, 2,000-row statement generated within 30 seconds at p95 as an asynchronous job when needed. Define concurrent workload, dataset scale and measurement environment before release; these are proposed service objectives, not grounds to acknowledge an undurable financial write early.

Ledger groups, entries, source/idempotency links, correction links, issued statement metadata/hashes and canonical financial audit are retained for the business's approved financial-record period. Initial scope provides no user purge/delete action. Exact legal retention, data-subject response, post-retention deletion/anonymization, backup geography and statement-artifact expiry require approved Data Retention policy before production. Identity masking must preserve financial referential integrity.

Backups and point-in-time recovery include account catalogue, groups, entries, source keys, reservation references, projection versions and statement metadata. Recovery drills verify last durable cutoff, zero duplicate/lost groups, balance invariants, subsidiary/control equality and reproducible sampled statements. Recovery promotes no projection until integrity checks pass. Define recovery point/time objectives before launch; missing objectives or untested restoration block production readiness.

Encrypt financial/evidence/artifact data in transit and at rest, use least-privilege service identities, separate posting from read/export credentials, rate-limit enumeration and generation, and authorize every download. Logs/errors mask sensitive references. Security controls do not replace the business authorization checks in Module 03.

## 17. Dependency and enablement gates

Before financial posting or statements are enabled, approve and verify:

- Chart of accounts, normal balances, exact event schemas, mappings, dimensions and version migration.
- Integer-kobo bounds and overflow-safe cumulative arithmetic; align the proposed 999,999,999,999-kobo individual value cap from Modules 05/07 where applicable.
- Module 07 contribution/remittance contract and Module 05 fee/deduction/refund/earnings contract.
- Withdrawal contract for gross G reservation, net P, fee F, deduction D, payout success/unknown result and release/consumption.
- Module 09 full-group-only reversal contract for eligibility, dependencies, compensation, custody/returned money, withdrawal delivery/return certainty and reversal-of-reversal policy.
- Authoritative reservation store and consistent ledger/reservation cutoff reads.
- Business and plan timezone/date policies, occurrence-date ranges and future period-close ownership.
- Immutable source IDs, posting/idempotency keys, atomic audit and durable outbox/replay.
- Projection/rebuild/integrity incident procedures and operational owner; no manual balance overwrite.
- Statement identity snapshot, PDF renderer/accessibility, artifact storage/access, supersession and retention.
- Reporting/export formats, privacy, expiry, job authorization and `reports.export` enforcement.
- Performance/load, backup/restore and recovery objectives with recorded evidence.

Unavailable integrations produce **Blocked** scenarios and unavailable actions, not Passed results, inferred zeros, generic accounts or broadened permissions.

## 18. Indexed functional requirements

| ID         | Requirement                                                                                                | Detail      |
| ---------- | ---------------------------------------------------------------------------------------------------------- | ----------- |
| LED-FR-001 | Maintain an authoritative immutable balanced NGN subledger distinct from user projections.                 | 1, 3        |
| LED-FR-002 | Use positive integer-kobo entries and overflow-safe calculations without floating point.                   | 3, 4.3      |
| LED-FR-003 | Maintain a versioned controlled account catalogue with explicit class/normal balance/purpose.              | 4.1         |
| LED-FR-004 | Store immutable uniquely keyed posting groups with source, actor, dates, schema and audit references.      | 4.2         |
| LED-FR-005 | Require valid debit/credit entries, currency equality, required dimensions and exact balance.              | 4.3         |
| LED-FR-006 | Generate globally unique durable transaction/posting references without treating them as authorization.    | 4.4         |
| LED-FR-007 | Accept only closed owning-workflow posting schemas and never arbitrary client journal lines.               | 5           |
| LED-FR-008 | Implement reviewed contribution, fee, deduction, withdrawal, remittance, refund and reversal patterns.     | 5           |
| LED-FR-009 | Keep Customer liability, custody, Agent receivable, fees, payables and destinations separate.              | 4.1, 5, 7.3 |
| LED-FR-010 | Project business transactions without double counting parent/component rows.                               | 6.1         |
| LED-FR-011 | Provide a stable transaction type catalogue and keep non-posted workflows outside posted totals.           | 6.2         |
| LED-FR-012 | Derive Posted/Reversed status from entries/compensation, not editable workflow labels.                     | 6.3         |
| LED-FR-013 | Preserve occurrence, commit, source and statement-cutoff date semantics and timezone.                      | 6.3         |
| LED-FR-014 | Derive Customer savings liability exactly from its subsidiary entries.                                     | 7.1         |
| LED-FR-015 | Derive available savings from liability minus authoritative live gross reservations once.                  | 7.1         |
| LED-FR-016 | Prevent negative Customer liability/availability and silent holds or estimated-money inclusion.            | 7.1         |
| LED-FR-017 | Derive cycle balances without changing thrift-slot funding or historical attribution.                      | 7.2         |
| LED-FR-018 | Derive and distinctly label total liability, Agent receivable, custody and fee measures.                   | 7.3         |
| LED-FR-019 | Treat balance caches/snapshots as rebuildable cutoff-labelled derivatives.                                 | 7.4         |
| LED-FR-020 | Correct posted money only through authorized linked balanced compensation.                                 | 8           |
| LED-FR-021 | Preserve immutable history and prohibit undeclared backdating/period override/manual adjustment.           | 2, 8        |
| LED-FR-022 | Deterministically rebuild versioned projections without changing authoritative entries.                    | 9.1         |
| LED-FR-023 | Verify group, subsidiary, source, reservation, fee, withdrawal, remittance and compensation integrity.     | 9.2         |
| LED-FR-024 | Fail safely and investigate mismatches without manual balance overwrite.                                   | 3, 9.2      |
| LED-FR-025 | Provide scoped search/filter/sort/cursor pagination and full-result totals.                                | 10.1        |
| LED-FR-026 | Provide authorized transaction detail with component, actor, date and correction trace.                    | 10.2        |
| LED-FR-027 | Enforce Customer-own, current-Agent and Admin baseline-read scopes on all financial reads.                 | 10.2, 11    |
| LED-FR-028 | Require `reports.export` for business/multi-Customer export and keep `audit.view` independent.             | 11          |
| LED-FR-029 | Generate one-Customer statements with reproducible opening/activity/closing balances.                      | 12.1        |
| LED-FR-030 | Show reservations/availability, fees and nonfinancial information separately from posted statement totals. | 12.1        |
| LED-FR-031 | Preserve statement cutoff/as-of metadata and append issued/superseded correction history.                  | 12.2        |
| LED-FR-032 | Render consistent authorized in-app/PDF statements with integrity and accessibility controls.              | 12.3        |
| LED-FR-033 | Provide safe role-specific ledger, balance, detail, statement and integrity screens.                       | 13          |
| LED-FR-034 | Distinguish authoritative empty, stale, unavailable, rebuilding and scope-loss states.                     | 7.4, 13     |
| LED-FR-035 | Enforce durable idempotency and concurrency across source posting and balance dependencies.                | 14          |
| LED-FR-036 | Commit financial source linkage/projection/audit atomically and recover uncertain outcomes.                | 14          |
| LED-FR-037 | Emit safe deduplicated notification events without coupling delivery to financial finality.                | 15          |
| LED-FR-038 | Durably audit posting, reads, exports, statements, rebuilds and integrity incidents with least privilege.  | 15          |
| LED-FR-039 | Meet reviewed performance/security/retention/backup/recovery requirements without weakening durability.    | 16          |
| LED-FR-040 | Block release on missing accounting, workflow, reservation, statement or operational owner contracts.      | 17          |

## 19. Acceptance scenarios and release evidence

Use fixtures with at least two Customers, two Agents and a reassignment; active/inactive/restricted/archived records; multiple cycles; partial/advance contributions; external and savings-funded fees; deductions; gross withdrawal reservations and posted payouts; remitted/unremitted Agent cash; linked reversals/refunds; issued/superseded statements; and deliberate projection/integrity failures. Evidence records scenario/requirement IDs, exact entries and pre/post balances, actors/permissions/versions, cutoff/timezone, result, audit reference and Passed/Failed/Blocked status.

| ID         | Requirement mapping    | Testable expected result                                                                                                                                                                                                                                                                                                        |
| ---------- | ---------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| LED-AC-001 | LED-FR-001, LED-FR-005 | Each valid event creates immutable entries whose debit and credit totals match; an unbalanced group posts nothing.                                                                                                                                                                                                              |
| LED-AC-002 | LED-FR-002             | Kobo arithmetic preserves exact ₦2,000.01 as 200001; proposed cap value 999,999,999,999 succeeds where the owning event permits, cap+1/fractional/negative/overflow inputs fail, and larger cumulative totals do not truncate.                                                                                                  |
| LED-AC-003 | LED-FR-003             | Retired account blocks new selection but retains balances/history; renamed display does not change old references.                                                                                                                                                                                                              |
| LED-AC-004 | LED-FR-004             | Group retains source, actor/approver, occurrence/commit dates, schema/version and audit references after all lifecycle changes.                                                                                                                                                                                                 |
| LED-AC-005 | LED-FR-005, LED-FR-009 | Missing Customer/Agent/cycle dimension or currency/account mismatch fails without partial entry or projection.                                                                                                                                                                                                                  |
| LED-AC-006 | LED-FR-006             | Concurrent reference generation remains globally unique; known reference alone cannot bypass record scope.                                                                                                                                                                                                                      |
| LED-AC-007 | LED-FR-007             | Direct client-supplied ledger lines and Admin manual-journal attempts are denied even with every current Admin permission.                                                                                                                                                                                                      |
| LED-AC-008 | LED-FR-008, LED-FR-009 | Cash contribution debits original Agent receivable and credits Customer liability; remittance moves custody only and credits liability zero times.                                                                                                                                                                              |
| LED-AC-009 | LED-FR-008, LED-FR-009 | External fee receipt and savings fee application recognize fee through distinct custody/liability effects without double income.                                                                                                                                                                                                |
| LED-AC-010 | LED-FR-008, LED-FR-015 | Withdrawal with G=10,000, F=500, D=200 posts P=9,300 and one Customer liability debit of 10,000; reservation G is consumed once.                                                                                                                                                                                                |
| LED-AC-011 | LED-FR-008, LED-FR-009 | Agent remittance, fee-earnings withdrawal and Customer withdrawal affect their defined accounts and never merge Customer money with earnings.                                                                                                                                                                                   |
| LED-AC-012 | LED-FR-010             | Mixed savings/fee receipt detail and component reports reconcile to one root amount without parent-plus-component double count.                                                                                                                                                                                                 |
| LED-AC-013 | LED-FR-011, LED-FR-012 | Pending/rejected request, unpaid obligation, missed slot and unconfirmed remittance do not appear as Posted; only Module 09 Approved-and-posted full compensation changes the original projection to Reversed without deleting it.                                                                                              |
| LED-AC-014 | LED-FR-013             | Late permitted occurrence shows original activity date and later UTC commit; sort/cutoff and statement treatment remain reproducible.                                                                                                                                                                                           |
| LED-AC-015 | LED-FR-014             | Customer subsidiary entry sum equals displayed liability across contribution, withdrawal, fee/deduction and correction activity.                                                                                                                                                                                                |
| LED-AC-016 | LED-FR-015             | Liability 50,000 with live gross reservations 12,000 and 8,000 yields available 30,000; pending unreserved request does not change it.                                                                                                                                                                                          |
| LED-AC-017 | LED-FR-015, LED-FR-016 | Module 08 Draft has no R; Pending review, Approved — awaiting payout, Payout processing, Outcome unknown and Payment failed retain R whether or not a hold overlay is active; Posted consumes R; Rejected/Cancelled/Expired release it. Included fee is not subtracted again and concurrency cannot make availability negative. |
| LED-AC-018 | LED-FR-016             | Outstanding fee, missed target, Agent shortage and plan estimate change no Customer balance/hold; unavailable input returns Unavailable, not zero.                                                                                                                                                                              |
| LED-AC-019 | LED-FR-017             | Customer withdrawal reduces cycle/Customer money as dimensioned but retains already Paid thrift slots; reassignment changes no cycle entries.                                                                                                                                                                                   |
| LED-AC-020 | LED-FR-018             | Total Customer liability equals subsidiary sum including non-active Customers; Agent/custody/fee metrics remain distinctly labelled and reconciled.                                                                                                                                                                             |
| LED-AC-021 | LED-FR-019, LED-FR-034 | Lagging cache shows watermark and cannot approve a balance-sensitive action; authoritative empty history alone displays zero.                                                                                                                                                                                                   |
| LED-AC-022 | LED-FR-020             | Eligible Module 09 Approved-and-posted full reversal adds a balanced linked group; original entries/reference remain byte-for-byte unchanged and readable. Selected-component/partial reversal is unavailable.                                                                                                                  |
| LED-AC-023 | LED-FR-020, LED-FR-021 | `reversals.review` cannot create unrelated waiver/deduction/journal or edit dates; reserved Adjustment remains unavailable.                                                                                                                                                                                                     |
| LED-AC-024 | LED-FR-021             | Owning bounded occurrence date retains current commit time; no permission provides a period-close/backdate override.                                                                                                                                                                                                            |
| LED-AC-025 | LED-FR-022             | Full rebuild at the same cutoff reproduces entry counts, balances, transaction statuses and statement input hashes exactly.                                                                                                                                                                                                     |
| LED-AC-026 | LED-FR-022             | Failed/incomplete rebuild never becomes current; verified old projection remains labelled while authoritative writes remain safe.                                                                                                                                                                                               |
| LED-AC-027 | LED-FR-023             | Inject each group/source/subsidiary/reservation/fee/withdrawal/remittance/compensation mismatch and verify it is detected.                                                                                                                                                                                                      |
| LED-AC-028 | LED-FR-024             | Integrity incident freezes only dependent scope, raises durable reference and cannot be “fixed” with an editable balance field.                                                                                                                                                                                                 |
| LED-AC-029 | LED-FR-025             | Filters/search/sort apply after scope; stable cursor produces no gaps/duplicates and total reflects all matching rows, not current page.                                                                                                                                                                                        |
| LED-AC-030 | LED-FR-025             | Invalid/overwide dates fail; Customer/Agent suggestions and counts reveal no inaccessible records.                                                                                                                                                                                                                              |
| LED-AC-031 | LED-FR-026             | Detail correctly shows signed effect, G/P/F/D components, dates, actors, source and original/compensation timeline.                                                                                                                                                                                                             |
| LED-AC-032 | LED-FR-027             | Customer sees own only; Agent sees current assignments; Admin baseline sees business data; direct URL/cursor/download obeys identical scope.                                                                                                                                                                                    |
| LED-AC-033 | LED-FR-027             | Reassignment immediately removes former Agent Customer history/artifact access without changing their historical recording attribution or own masked settlement.                                                                                                                                                                |
| LED-AC-034 | LED-FR-028             | Baseline Admin and `audit.view` holder without `reports.export` cannot run business export; exporter gains no audit/mutation capability.                                                                                                                                                                                        |
| LED-AC-035 | LED-FR-028             | Export re-checks authority at execution/release, carries filter/timezone/cutoff/version and exposes no raw audit/security/evidence fields.                                                                                                                                                                                      |
| LED-AC-036 | LED-FR-029             | Statement opening plus signed period activity equals closing exactly, and each line resolves to an authorized transaction.                                                                                                                                                                                                      |
| LED-AC-037 | LED-FR-029             | Contribution, gross withdrawal/component, fee application, deduction, refund and reversal totals reconcile without counting external fees against savings.                                                                                                                                                                      |
| LED-AC-038 | LED-FR-030             | Statement shows live reserved/available and unpaid fee information separately; Agent remittance, missed slots and estimates do not enter posted totals.                                                                                                                                                                         |
| LED-AC-039 | LED-FR-031             | Issued statement retains cutoff/hash; later pre-period posting changes new opening, creates a new issue/supersession link and leaves old file reproducible.                                                                                                                                                                     |
| LED-AC-040 | LED-FR-031             | In-period correction changes new activity/closing and links notice; neither old statement nor ledger is edited or misleadingly labelled Final.                                                                                                                                                                                  |
| LED-AC-041 | LED-FR-032             | App and PDF totals/reference/cutoff match; multipage output has headers/pages/accessibility and authorized integrity-checked download.                                                                                                                                                                                          |
| LED-AC-042 | LED-FR-032             | Inconsistent ledger/activity source fails generation without partial PDF; allowed reservation outage omits only labelled availability, never substitutes zero.                                                                                                                                                                  |
| LED-AC-043 | LED-FR-033             | Customer, Agent and Admin screens expose only their actions/data; no screen offers editable balances/manual journals.                                                                                                                                                                                                           |
| LED-AC-044 | LED-FR-033, LED-FR-034 | Mobile/keyboard/screen-reader flow distinguishes type/status/dates and loading/empty/stale/unavailable/rebuilding/scope-loss without color-only cues.                                                                                                                                                                           |
| LED-AC-045 | LED-FR-035             | Same idempotency key/payload returns one group/transaction; changed payload conflicts after cache expiry as well.                                                                                                                                                                                                               |
| LED-AC-046 | LED-FR-035             | Concurrent contribution/withdrawal/deduction/reversal commands serialize or revalidate to preserve uniqueness, reservations and non-negative balances.                                                                                                                                                                          |
| LED-AC-047 | LED-FR-036             | Failure before financial commit leaves no group/source/projection/audit success; response loss after commit resolves one result by original key.                                                                                                                                                                                |
| LED-AC-048 | LED-FR-036             | Search/analytics/statement/notice outage after commit leaves one valid posting and catches up from durable projection events.                                                                                                                                                                                                   |
| LED-AC-049 | LED-FR-037             | Notification retry is event-deduplicated, scope-checked and cannot repeat posting/statement issue; internal account/evidence data stays absent.                                                                                                                                                                                 |
| LED-AC-050 | LED-FR-038             | Posting, denial, read/download, export, statement, rebuild/promotion and incident actions have protected durable audit; secrets remain excluded.                                                                                                                                                                                |
| LED-AC-051 | LED-FR-038             | `audit.view` grants detailed audit read only and cannot post/rebuild-promote/export statements or access another role's unauthorized artifact.                                                                                                                                                                                  |
| LED-AC-052 | LED-FR-039             | Documented p95 load tests meet or report proposed targets without returning success before durability.                                                                                                                                                                                                                          |
| LED-AC-053 | LED-FR-039             | Backup/restore drill reaches declared cutoff with no missing/duplicate/unbalanced groups and reproduces sampled statements before promotion.                                                                                                                                                                                    |
| LED-AC-054 | LED-FR-039             | No user can purge financial history/artifacts outside approved retention; storage/download/log paths enforce encryption, expiry and masking policy.                                                                                                                                                                             |
| LED-AC-055 | LED-FR-040             | Remove each chart/workflow/reservation/audit/statement/export/recovery dependency in turn; affected scenarios/actions report Blocked, never Passed/default zero.                                                                                                                                                                |

## 20. Worked examples

### 20.1 Customer savings and available balance

A Customer has NGN 60,000 posted contributions, NGN 10,000 net cash payouts, NGN 2,000 withdrawal fees included in those gross debits, and NGN 3,000 other savings deductions. Posted liability is `60,000 − 10,000 − 2,000 − 3,000 = NGN 45,000`. A live withdrawal reservation of gross NGN 12,000 makes available savings NGN 33,000. The fee within the pending request is already inside that gross reservation; it is not held again.

When the request posts with P=NGN 11,000 and F=NGN 1,000, debit Customer liability G=NGN 12,000 once, credit payout custody NGN 11,000, credit fee income NGN 1,000, and consume the NGN 12,000 reservation. New liability and availability are both NGN 33,000 if no other reservations exist.

### 20.2 Contribution, fee and remittance separation

Agent A receives NGN 6,500 cash: NGN 6,000 savings and NGN 500 external registration-fee payment. Post Agent A receivable debit NGN 6,500, Customer savings liability credit NGN 6,000 and fee income credit NGN 500 through the linked obligation settlement. Customer savings is NGN 6,000, not NGN 6,500 or NGN 5,500.

Agent A remits NGN 6,000. Debit business cash NGN 6,000 and credit Agent A receivable NGN 6,000. The remaining Agent receivable is NGN 500; Customer savings and fee income do not change. Customer reassignment to Agent B does not move that receivable.

### 20.3 Statement cutoff and late posting

Statement S1 for August is issued September 2 with a ledger cutoff at 10:00 UTC and closing savings NGN 40,000. On September 3, an authorized late receipt with August 31 occurrence date commits for NGN 2,000. S1 remains immutable and reproducible at its cutoff. S2 for the same period includes the late line, closing NGN 42,000, later-posted disclosure and a successor link. Neither statement changes the receipt's true September 3 commit timestamp.

### 20.4 Reversal with a dependency block

An erroneous NGN 10,000 contribution is part of Customer liability NGN 15,000, while live gross reservations total NGN 8,000. Reversal would leave liability NGN 5,000 below reservations. The ledger rejects the compensation command; it does not release the reservations or edit the original. The authorized withdrawal/reversal workflows resolve dependencies first, then submit a new versioned compensation command if still eligible.

## 21. Decisions for review

Review the proposed account catalogue/naming, human reference format, projection types/statuses, 366-date interactive/statement range, cutoff-based statement semantics, Issued/Superseded status model, statement PDF scope, cursor/page defaults, performance targets, and disabling of all arbitrary Adjustment/manual-journal actions.

Before implementation, finalize upstream Withdrawal and Reversal specifications; exact chart/event schemas; amount limits; occurrence-date and period-close ownership; reservation consistency protocol; statement identity snapshot/renderer; export/privacy/retention policy; projection incident owner; and backup recovery objectives. No missing choice may be replaced by a generic account, editable balance, hidden hold, inferred approval, widened role permission, or untraceable correction.
