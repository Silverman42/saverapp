# Reports and Exports

**Product version:** 2.0  
**Module:** 12  
**Module status:** Detailed draft for review  
**Sources:** [Version 1 PRD](../../PRD.md), [User Types](./01-user-types.md), [Authentication](./02-authentication.md), [Roles and Permissions](./03-roles-and-permissions.md), [Customer and Agent Management](./04-customer-and-agent-management.md), [Fees and Deductions](./05-fees-and-deductions.md), [Thrift Plans and Savings Cycles](./06-thrift-plans-and-savings-cycles.md), [Collections, Digital Thrift Card, and Reconciliation](./07-collections-thrift-card-and-reconciliation.md), [Withdrawals and Payout Approvals](./08-withdrawals-and-payout-approvals.md), [Reversals and Financial Corrections](./09-reversals-and-financial-corrections.md), [Transaction Ledger, Balances, and Statements](./10-transaction-ledger-balances-and-statements.md), [Dashboard and Operational Analytics](./11-dashboard-and-operational-analytics.md)  
**Related owners:** business configuration/timezone, notifications, privacy/retention, document rendering, object storage, and audit

## 1. Purpose and decision status

This module defines reproducible operational and financial reports, their metrics, role/resource scope, drill-down behavior, and governed downloadable exports. It turns authoritative data from Modules 04–11 into analysis without inventing balances, changing financial records, widening access, or confusing Customer money, physical cash, Agent responsibility, and business earnings.

The PRD requires Customer, contribution, withdrawal, fee and collection-performance reports. Version 2 also needs plan/cycle, reconciliation/custody, Agent operations and exception views to support the multi-user workflows introduced by Modules 01–10.

The authorization boundary is confirmed: Customers see only their own information; Agents see only currently assigned Customers plus explicitly permitted masked summaries of their own historical collection/custody responsibility; Admins have baseline business-wide report read access; only an Admin with `reports.export` may create or download business-wide or multi-Customer report files. `reports.export` grants no financial mutation, approval, detailed audit-log access, or raw evidence access.

Unless established upstream, report definitions, limits, export formats, cutoff behavior, retention and performance targets below are **proposed for review**. A metric without an authoritative owner, historical dimension, cutoff-consistent query, privacy classification, or exact status/date basis remains unavailable rather than displaying a guessed value.

Module 11's stable metric contract is reused: every shared metric keeps one code, title, definition, unit, source, date basis, cutoff/as-of, filters and drill-down contract. Module 12 may add report-specific opening/closing and grouping metadata, but it cannot give a dashboard metric a second formula. Shared formula/version changes must update both consumers together and preserve prior export reproducibility.

## 2. Scope, exclusions, and report-versus-statement boundary

### 2.1 Initial scope

- Interactive, scoped report views for Customer financial summary; contributions; withdrawals; fees/deductions; collection performance; reconciliation/custody; Agent operational performance; plan/cycle; and operational/financial exceptions.
- Search, filters, grouping, full-result totals, stable drill-down and declared timezone/as-of/cutoff metadata.
- Admin-only CSV/PDF business or multi-Customer export jobs under `reports.export`.
- Immutable job specifications, reproducible cutoff-bound files, protected short-lived downloads, job notifications, audit, retries, expiry and privacy controls.
- One configured business, NGN integer-kobo owner data, and business timezone display.

### 2.2 Excluded or separately owned

- Module 10's single-Customer statement preview, issuance, PDF, supersession and statement history. A report PDF is not an issued Customer statement and must never use its verification wording/reference.
- Dashboards/cards intended for immediate navigation where another module owns them; this module supplies shared metric definitions when reused.
- Scheduled/recurring/email/WhatsApp reports, subscriptions, user-designed reports, saved report sharing, spreadsheet `.xlsx`, accounting-software feeds, APIs for third-party BI, cross-business comparison, forecasting, targets/commissions, tax/statutory filings and certified regulatory reports.
- Raw ledger-entry exports, arbitrary account dumps, detailed audit-log exports, security event exports, raw payment/custody evidence, bank credentials, identity-recovery data, internal/private notes and unrestricted attachments.
- Financial corrections, approvals, reconciliation decisions, Customer/Agent lifecycle changes, plan changes or any other mutation from a report.

### 2.3 Interactive report versus issued statement

| Characteristic | Interactive report / Module 12 export | Issued Customer statement / Module 10 |
| --- | --- | --- |
| Purpose | Analysis across selected records/metrics | Reproducible financial record for exactly one Customer |
| State | Live/cutoff-labelled projection; export is a snapshot of report query | Immutable Issued artifact with statement ID, cutoff/hash and supersession lineage |
| Scope | Own Customer, current Agent scope, or Admin business scope | One Customer under current record access |
| Download authority | Module 12 multi-Customer/business files require `reports.export` | Single-Customer statement follows Module 10 resource scope |
| Opening/activity/closing | Included only for defined balance reports | Required Customer liability reconciliation |
| Corrections | New run/file at new or same explicit cutoff; old export is not silently replaced | New issue/supersession; old statement remains reproducible |
| Wording | “Report generated at/as of” | “Statement issued,” never “Final” in initial scope |

An Admin or Agent who needs a Customer's formal statement uses Module 10. Module 12 must not recreate a statement-shaped PDF to bypass statement controls or `reports.export` controls.

## 3. Shared metric, time, and counting contract

### 3.1 Required metadata

Every interactive result and export identifies:

- report type and schema/metric-definition version;
- configured business, currency, viewer scope and redaction profile;
- inclusive user-selected local date range and declared IANA timezone;
- resolved UTC start/end boundaries;
- ledger `committed_at` cutoff and owner-projection cutoffs/watermarks;
- generation/query time in UTC and business-timezone display;
- applied filters, grouping, status/date basis and correction treatment; and
- stale/partial/unavailable owner status.

Initial default timezone is the configured business timezone; do not infer the browser timezone. Plan due-date analysis uses each cycle's snapshotted timezone for slot eligibility, while report period boundaries and receipt/business activity use the selected business reporting timezone. If these differ, label both. A timezone change applies prospectively to new default runs and never shifts a saved/exported job's boundaries.

### 3.2 Date and cutoff semantics

Financial activity reports filter by owner-validated occurrence/effective date, but include only postings whose immutable `committed_at ≤ cutoff`. Late-posted activity retains its occurrence date and later commit date and is disclosed/countable at the later cutoff. Transaction ordering and reproducibility use committed timestamp plus stable ID where occurrence dates tie.

Operational reports use the exact owner date:

- contribution cash/receipt activity: Module 07 received/business date;
- thrift expectation/fulfillment: Module 06 slot due date in plan timezone and eligibility intervals;
- withdrawal activity: only Posted withdrawal occurrence date from Modules 08/10; request/review dates appear only in workflow reports;
- fee/deduction income/activity: Module 05 assessment, settlement/application, recognition, waiver/refund or posting date as separately selected and labelled;
- reconciliation/custody: Module 07 batch business date, receipt/remittance occurrence and decision timestamps separately;
- plan/cycle: creation/start/scheduled end/completion/closure dates as explicitly filtered; and
- status/assignment/work queue: historical effective intervals or current state at as-of cutoff, never current values projected backward.

Initial interactive report range is proposed at 366 inclusive local dates. Admin export supports at most five years per job. “Lifetime” resolves from earliest retained authoritative record through the cutoff and is not a hidden unbounded browser query. Invalid/reversed/future-ended ranges fail validation; today ends at the current resolved cutoff, not a future midnight.

### 3.3 Counting and correction rules

- Posted financial totals come from Module 10 entries/projections effective at the cutoff, including linked compensation. Non-Posted requests—including requests with an active hold overlay—never enter posted totals.
- Show **gross original**, **corrections/reversals**, and **net effective** where material. Do not delete reversed activity or count both a parent transaction and its financial components in the same aggregate.
- A mixed receipt's savings component contributes to savings/contribution metrics; its fee component contributes to external fee settlement/recognized income under Module 05; gross tender contributes once to custody receipt totals. It is never three receipts.
- Withdrawal gross Customer debit G, Customer cash payout P, withdrawal fee F and approved deduction D are separate components satisfying `G = P + F + D`. Total Customer withdrawals/liability movement uses G once; cash paid uses P; fee reports use F; no component is double counted.
- Reconciliation/remittance moves custody/Agent receivable and never increases Customer contributions or fee income again.
- Live reservations reduce available savings, not posted liability. Outstanding fees/plan targets/missed slots/Agent shortages do not silently reduce liability.
- Current Customer/Agent status never removes historical posted activity. Archived/Inactive/Restricted Customers with balances remain in liability totals.
- Monetary aggregation uses integer kobo and overflow-safe sums; percentages are derived after exact numerator/denominator aggregation, not averaged row percentages.
- A zero denominator yields **Not applicable**, not 0% or 100%. Unknown/unavailable yields **Unavailable**, not zero.

### 3.4 Opening, activity, and closing

For a cutoff-bound balance report and selected occurrence-date period:

`Closing = Opening + signed net period activity`

Opening includes eligible postings with occurrence date before the period start that were committed by cutoff. Period activity includes occurrence dates inside the inclusive boundaries and committed by cutoff. Closing is the authoritative as-of balance after included activity. A later committed posting with an earlier occurrence date changes a later run's opening/activity as appropriate; prior exports retain their cutoff.

Apply this reconciliation independently to Customer savings liability, each cycle subsidiary, Agent receivable and each business custody account where owner mappings support it. Fee **income activity** is not a Customer liability balance. Fee book/drawable balance uses Module 05's owner formula and labels recognized income, refunds/corrections and fee-earnings draws separately. Never force unlike account classes into one generic closing “balance.”

## 4. Authorization and privacy scope

### 4.1 Interactive access matrix

| Report | Customer | Agent | Admin baseline |
| --- | --- | --- | --- |
| Customer financial summary | Own record only | Currently assigned Customers | Business-wide/customer drill-down |
| Contribution activity | Own posted activity | Currently assigned Customers; masked own historical custody summary separately | Business-wide |
| Withdrawal activity | Own requests/posted activity in scoped presentation | Currently assigned Customers | Business-wide |
| Fees/deductions | Own records | Currently assigned Customers | Business-wide/business earnings summary |
| Collection performance | Own plan/slot progress only, not Agent ranking | Current assigned portfolio and own recorded activity | Business-wide/Agent comparison |
| Reconciliation/custody | No business custody/Agent totals | Own status and masked responsibility allowed by Module 07 | Business-wide safe summary; raw evidence still permission-gated by owner |
| Agent operational performance | No | Own/current portfolio only; no peer directory | Business-wide |
| Plan/cycle | Own plans | Currently assigned Customers | Business-wide |
| Exceptions | Own safe Customer-facing holds/requests only | Current assigned tasks plus masked own custody issues | Business-wide safe queue |

Scope applies to rows, totals, counts, charts, group labels, filter options, search suggestions, drill-down, job metadata, notification text and files. A count or “no results” response must not reveal out-of-scope record existence. Agent historical `created_by`, collected-by or former assignment never restores Customer detail access. Module 07 may provide an aggregate/masked former-Agent custody responsibility without Customer identity.

Admin baseline business read does not expose raw identity-verification, authentication/security, unrestricted payment/custody evidence, private internal notes or detailed audit payloads. Each drill-down returns only fields authorized by its owning module. A report link cannot turn baseline read into a protected action.

### 4.2 Export authority

All Module 12 CSV/PDF downloads are business-wide or multi-Customer report artifacts and require an active Admin with `reports.export`. Customer/Agent interactive viewers have no Module 12 file export; they use permitted in-app views and Module 10 single-Customer statements. An Admin without `reports.export` may interactively view business reports but cannot create, run, retrieve, copy a download token for, or download a report artifact.

Re-check active account/session and `reports.export` at job submission, worker execution, artifact publication, notification retrieval and every download. Permission revocation cancels an unstarted/running job where safe, suppresses readiness delivery, and denies existing artifacts immediately. It does not delete immutable job/audit history. `reports.export` does not grant `audit.view`, and holding both does not make raw audit-log/evidence export part of this module.

Exports must apply the exact Admin business scope and field policy. A future narrower Admin scope requires scope snapshot plus current-scope intersection at run/download; never preserve broader historical access merely because a job was requested earlier.

## 5. Report catalogue

### 5.1 Customer financial summary

One row per scoped Customer at cutoff, with Customer ID/name, operational status, current Agent where permitted, open plan summary, opening liability, signed period contributions/withdrawal gross debits/savings-funded fees/other deductions/refunds/corrections, closing posted liability, live gross reservations, available savings, externally paid fees and outstanding fee/refund obligations separately.

- Lifetime contributions: net posted savings-principal credits from contribution events through cutoff; show original/reversal detail on drill-down.
- Total withdrawals: net posted gross Customer-liability debit G from withdrawal events, not P+F again.
- Total fees charged: separately show assessed, externally settled, savings applied, waived, refunded/corrected and outstanding. Only savings applications affect liability.
- Total deductions: net posted non-fee savings deductions; exclude fee F and withdrawal P.
- Closing/availability use Module 10/reservation owner, not arithmetic over recent rows.

Opening plus signed activity must equal closing. A summary row may drill to Module 10 transaction history, Module 05 obligations, Module 08 request/reservation history and Module 06 plans under current scope.

### 5.2 Contribution report

Detail row grain is one posted savings receipt component, with transaction/receipt ID, Customer, source cycle, received occurrence date, committed time, method, gross tender reference where safe, savings amount, slot allocations, recording Agent, current Agent separately, correction status and reconciliation/custody state. A mixed fee component is labelled but excluded from contribution principal.

Metrics: gross posted savings, contribution compensations, net savings contributions, receipt count, unique paying Customers, allocations to due/earlier/future slots, partial/advance/catch-up amounts, and method totals. Receipt count counts root posted receipt components after documented correction semantics; it never counts each slot allocation as a receipt. Reversed originals are shown separately and excluded from net.

Group by received local date, method, plan, Customer, recording Agent or current service Agent. “Recorded by Agent” uses immutable actor; “current portfolio” uses assignment as-of cutoff. Never use present assignment to rewrite historical Agent collection attribution.

### 5.3 Withdrawal report

Two explicit modes:

1. **Posted withdrawal activity:** one row per Posted withdrawal transaction, with request/transaction IDs, Customer, source cycle/type, G/P/F/D, method mask, occurrence/commit date, initiating Agent, approving Admin, correction status and safe payout reference. Totals separately sum G, P, F and D.
2. **Request workflow:** one row per request with primary state Pending review, Approved — awaiting payout, Payout processing, Outcome unknown, Payment failed, Rejected, Cancelled, Expired or Posted, plus separate hold status/reason category, live reservation, age/deadline and safe action owner. These rows do not enter posted withdrawal totals until Posted.

Group posted activity by occurrence date, type, method, plan, Customer or initiator. Group workflow by state/hold/current task owner. Reversal pending is an overlay; a posted compensation affects net posted totals only after Module 09 commits.

### 5.4 Fees and deductions report

Keep five families separate: assessed fee obligations, external fee receipts, savings-funded fee applications, other savings deductions, and business fee earnings/refunds/draws. For each, show the owner date/status/basis, Customer/cycle/category where permitted, original/net amount, source, actor, waiver/refund/correction and outstanding amount.

Metrics include gross assessed, external settled, savings applied, waived, outstanding, gross recognized fee income, refunds/corrections, net recognized fee earnings, other deduction income/payable by category, fee-earnings draws, book fee balance and Module 05 drawable balance. Never label assessed/unpaid/acknowledged fees as cash or recognized earnings. Fee receipts and applications settle obligations but only external receipts represent new cash.

Monthly/lifetime grouping follows the selected metric's declared date; a user cannot view one blended “fee date.” Agent grouping for external receipts uses recording Agent/custody attribution, not business ownership of earnings or current assignment.

### 5.5 Collection-performance report

This report presents two non-interchangeable views:

- **Schedule fulfillment:** eligible expected amount = sum of scheduled slot amounts due in the period while plan/Customer eligibility permits collection under Modules 04/06; funded amount = net live principal allocated by cutoff to those exact target slots; outstanding = max(expected − funded, 0); paid/partial/missed/pending slot counts use Module 07 state at cutoff. `Fulfillment % = funded target-slot amount / eligible expected amount × 100` when expected > 0. Advance funding of a target slot counts when evaluating that slot; catch-up/future money cannot inflate other target slots.
- **Received activity:** savings principal actually received in the period by received date, split into current-due, catch-up and advance allocation. It may exceed period expected and is never used as the fulfillment numerator without target-slot restriction.

Show original scheduled expectation separately from eligible expectation where pauses, status holds or Agent unavailability affect actionability. Preserve the dated intervals and policy version. No current status retroactively deletes expected/history. Proposed rounding: display percentage to one decimal using exact aggregate numerator/denominator; retain exact kobo/count inputs.

Group by slot due date, plan, Customer, current portfolio as-of cutoff, historical effective service Agent interval where available, or recording Agent for received activity. Labels must state the basis. “Collection performance” is operational context, not an employee compensation or misconduct decision.

### 5.6 Reconciliation and custody report

Admin business view separates:

- gross physical/non-cash receipts by method and original Agent;
- savings and fee allocations without duplicating gross tender;
- Agent receivable opening, additions, confirmed remittances/eligible compensations and closing;
- business cash/bank/POS/clearing opening, movements and closing by approved account;
- submitted/frozen/reviewed batch versions, supplemental batches, matched amount, unexplained variance, pending clearing/evidence/correction, reconciliation outcome and age; and
- shortages/overages/exceptions without silently writing them off or assigning them to Customers.

Customer liability is shown only as a separate comparison/control total, never added to cash. Business custody assets, Agent receivables, Customer liability and fee income are distinct account classes. Reassignment leaves original Agent responsibility/receipt actor unchanged. Raw remittance documents and sensitive provider evidence are not report/export fields; authorized owner screens may be linked.

Agent view contains only their own masked receipt/remittance/reconciliation status and responsibility total allowed by Module 07, with Customer identity removed after scope loss. Customer has no custody report.

### 5.7 Agent operational-performance report

Admin view uses clearly separated dimensions:

- **Current portfolio as-of cutoff:** current assigned Active/Inactive/Restricted/non-archived Customer counts separately, open plans, due eligible collection workload, outstanding Customer-facing tasks and unavailable-service flags.
- **Historical activity:** Customers registered, plans created, savings receipts recorded, Customer instruction requests initiated and operational annotations by immutable actor within period.
- **Collection outcomes:** schedule fulfillment for the Agent's effective service intervals and received activity recorded by the Agent, as separate measures.
- **Custody/reconciliation:** Agent receivable/remittance/variance/aged batch counts from Section 5.6.
- **Quality/exception indicators:** reversal requests linked to their original transactions, duplicate/conflict rates and aged unresolved work, without asserting wrongdoing.

Current assignment, effective historical assignment and recording actor are never interchangeable. An Agent sees only own/current-portfolio view and masked own settlement history; no peer names/rankings. Initial scope provides no composite score, leaderboard, quota, commission, automatic discipline or employment decision. Counts with small/private cohorts remain subject to privacy policy.

### 5.8 Plan and cycle report

One row per cycle: plan/Customer/current Agent, lifecycle state, start/scheduled end/timezone, agreed daily amount/N/expected gross, eligible/funded/partial/missed/pending/advance slots at cutoff, net posted contributions, attributed withdrawals/deductions/fees, cycle liability/reservation/available values, fee obligation state, completion/closure dates, predecessor/successor, interruptions, settlement blockers and post-closure exceptions.

Estimates/targets remain separate from posted actuals. Completion means all slots net funded, not settled/Closed. Full withdrawal or scheduled end does not imply Closed. Cancelled zero-activity cycles remain in lifecycle counts/history but contribute no fabricated money. Group by lifecycle, start/end/completion/closure date (chosen explicitly), fee model, current Agent or interruption category.

### 5.9 Exception summary

Role-scoped, safe queues/counts for: missing/unavailable owner projections; financial integrity incidents; aged/unknown payout attempts; pending withdrawals and requests with active holds; pending reversal requests/dependency blocks; unreconciled/supplemental/variance batches; outstanding/blocked fees/refund payables; liability below/near reservations; plan closure blockers/post-closure exceptions; Customer archival/offboarding blockers; failed export jobs and notification/delivery issues.

An exception row links to the owning screen only when currently authorized. It does not expose raw audit/evidence, approve/reject/resolve an item, release a reservation, write off variance, post adjustment, waive fee, close plan or change status. “No exceptions” requires all configured owner sources successfully queried at the stated cutoff; otherwise show Unavailable/Partial coverage.

## 6. Filters, grouping, totals, and drill-down

Common filters: inclusive date range/timezone, Customer name/public ID, current Agent, recording/original Agent, cycle/plan ID, Customer/Agent/plan/workflow status, transaction/event type, method, currency, amount range, correction state and owner-specific exception/category. Search normalization/privacy follows Module 04. Only applicable filters appear; selecting “Agent” requires an explicit Current service, Effective service, Recording actor or Custody responsibility basis.

Default date range is proposed current calendar month through now for activity reports and current cutoff for snapshot reports. Default sort is primary report date descending plus stable immutable ID; work queues use actionable age/deadline then ID. Proposed page size 25 with 25/50/100 options. Cursor binds viewer/scope, query, metric/schema version, cutoff and sort; changing any restarts pagination.

Totals and charts use the complete scoped filtered result at one cutoff, not loaded rows. Group subtotals must reconcile to the displayed grand total unless privacy suppression is explicitly labelled. “All” means all authorized rows within job/range limits. Do not sum balances across repeated transaction rows, add opening and closing, add gross and net, or average percentages. Each metric tooltip/dictionary exposes numerator, denominator, unit, date/status basis and exclusions.

Drill-down passes immutable source IDs, cutoff and filter context into the owning authorized view. The destination reauthorizes current scope and may show that a record is no longer accessible; it never trusts report visibility or exported IDs as capability. Returning preserves permitted filters without caching out-of-scope content.

## 7. Export formats and schemas

### 7.1 CSV

CSV is for tabular detail and group summaries. Proposed format: UTF-8 with BOM, RFC 4180 quoting, CRLF rows, stable documented column order, one header row, ISO 8601 timestamps with offset/UTC companion where relevant, `YYYY-MM-DD` local dates, uppercase NGN currency, money as exact decimal strings with two digits, and IDs as text. Never emit locale thousands separators in numeric cells.

Neutralize spreadsheet formula injection: any text beginning after whitespace with `=`, `+`, `-`, `@`, tab or carriage return is encoded/escaped according to the published CSV profile, while a separate safe display does not mutate authoritative source data. No formulas, macros, hidden sheets, HTML, active links, raw object-storage URLs or embedded credentials. Include a metadata preamble only if the approved schema supports it; otherwise provide a separate manifest tied by export ID/hash.

### 7.2 PDF report

PDF is for a human-readable summary plus bounded detail. It includes business/report title, “Report—not an issued Customer statement,” export ID, scope/filter/date/status basis, timezone, cutoff/watermarks, generation time, metric definitions/version, totals, page numbers and confidentiality label. Tables repeat headers and preserve readable rows; charts always have textual/table equivalents.

PDF must be tagged/accessibility-tested under the approved renderer, use embedded fonts as licensed, searchable text, correct NGN rendering, accessible reading order/metadata and no color-only meaning. It contains masked destination/reference fields and no raw evidence attachments. A hash verifies unchanged artifact bytes, not accounting finality.

### 7.3 Proposed limits

| Limit | Proposed initial value / outcome |
| --- | --- |
| Export date range | Maximum five years inclusive; lifetime allowed only if retained authoritative history fits all row/size limits. |
| CSV rows | 250,000 data rows; above limit fails before publication and asks for narrower filters. No silent truncation. |
| PDF detail rows/pages | 10,000 rows and 500 pages; above limit requires CSV or narrower scope. |
| Artifact size | 250 MB CSV; 100 MB PDF; renderer/storage lower safe limit may block with explicit profile version. |
| Concurrent active jobs per Admin | 2; additional request queued or rate-limited without losing idempotent result. |
| Daily jobs per Admin | Proposed 20 successful/new jobs per rolling 24 hours; same-key lookup does not consume another job. |
| Download link | Signed opaque link valid 15 minutes, single artifact; each request reauthorizes. |
| Artifact retention | Proposed artifact bytes/render fragments: 7 days. Detailed executable job specification and row manifest: 90 days. Canonical export evidence—job ID, requester, scope/filter hash, schema, cutoff, artifact hash, result and audit references—follows Module 14's financial/lifecycle/authorization evidence class, proposed seven years after the applicable closure or later linked settlement. All periods remain subject to approved financial/privacy retention policy and holds. |

Limits are server enforced before and during rendering. If a source estimate is wrong and the actual result exceeds a cap, fail with no partial downloadable file. Do not divide one request into undisclosed files or truncate rows/totals. A future multipart format requires an explicit manifest and complete-set semantics.

## 8. Export job lifecycle

### 8.1 Immutable job specification

At submission, bind job ID/attempt to requesting Admin, configured business, report/schema/metric version, output format, columns/redaction profile, normalized filters/group/sort, selected timezone and resolved UTC boundaries, resolved ledger/owner cutoff request, locale, maximum limits and current permission/scope version. The user previews estimated row count, included sensitive fields, cutoff/date basis and expiry policy before confirmation.

Proposed job states: **Queued, Running, Ready, Failed, Cancelled, Expired**. Ready means the entire artifact and manifest were generated, virus/content checked where applicable, integrity-hashed and published. It does not mean source periods are final. Failed has no downloadable partial artifact. Expired removes artifact access but preserves governed metadata; rerun is a new job unless explicitly reproducing the same retained cutoff/specification.

### 8.2 Execution

1. Validate active Admin/session and `reports.export`, spec/range/limits and owner availability at submission; persist Queued spec/audit/outbox atomically.
2. Worker rechecks active account, grant, business scope and current schema support before reading. Resolve one consistent ledger cutoff and compatible owner watermarks. If the immutable requested cutoff cannot be reproduced, fail rather than substitute “now.”
3. Query only authorized fields using cutoff-bound source data. Verify row counts, totals, opening/activity/closing controls and component invariants before rendering.
4. Render to isolated temporary storage; scan/validate, calculate hash/size/row count and publish atomically as Ready. Delete failed partials.
5. Recheck permission before Ready notification metadata and every artifact download. A download receives no public/permanent URL.

Projection lag may be allowed only when every included metric declares its older consistent cutoff; a report may not mix newer liability with older withdrawal/fee components and call the result current. Proposed default is fail the export if required owner cutoffs cannot be aligned. An explicitly nonfinancial operational report may label independent section watermarks if its metric definition permits; totals do not cross those sections.

### 8.3 Cancellation, expiry, and reproducibility

Requester may cancel Queued or safely interruptible Running work from the job center while still authorized; cancellation does not cancel financial records or owner workflows. Permission revocation triggers safe cancellation/deny. Ready cannot be “cancelled,” only expire under retention. An Admin cannot delete job/audit history manually.

Manifest includes export ID, job/spec hash, artifact hash, schema/metric versions, filters/scope/redaction, cutoff/watermarks, timezone boundaries, row/group counts, control totals, generation times, generator version and requester. Same sources/spec/cutoff/schema reproduce equivalent semantic rows/totals; artifact bytes may differ only for documented renderer metadata. Later corrections/late postings require a new cutoff/job and never replace an old artifact.

## 9. Privacy, redaction, retention, and download security

Default report identity fields are Customer/Agent names and public IDs only where operationally necessary. Phone/email/address/next-of-kin/photo/private notes, bank account, provider payload, attachments, authentication/security data and internal investigation text are excluded. An approved report-specific contact field must be individually documented, masked/minimized and authorized; no “all profile fields” option.

CSV/PDF omit raw remittance/payment evidence and detailed audit. Export rows may contain safe source references that reauthorize in-app access. Mask bank/destination data to approved last digits; do not export signed links. Exception exports use safe category/status/age, not allegations or confidential evidence. Agent performance files require privacy review because they are personnel-adjacent; no composite ranking.

Artifacts are encrypted in transit/at rest, stored outside public buckets, accessed by opaque short-lived signed tokens, bound to authenticated requester/business/artifact, rate limited and protected from referrer/cache leakage. Authorization occurs before token minting and download response. Record download result and safe client/session context. A copied/expired link gives no metadata or file.

Exact legal retention, residency, subject-access handling, deletion/anonymization and backups require approved retention/privacy policy. Initial proposed artifact expiry is seven days; expiry deletes artifact bytes and cached render fragments through governed cleanup, not source financial data, job/audit metadata or issued Module 10 statements. If retention policy is unavailable, production exports remain Blocked.

## 10. Failure, idempotency, concurrency, and integrity

- Bind submission idempotency key to actor, business, report/action and normalized immutable spec. Same key/spec returns the same job; changed spec conflicts. A timed-out submission resolves by the same key before creating another job.
- Worker leases and state versions prevent two workers from publishing two artifacts. Retry resumes/restarts safe read/render work against the same cutoff/spec and never mutates owner data.
- Permission/scope/schema/config changes during job cause revalidation. Never silently broaden/narrow and publish a file different from the confirmed spec; cancel/fail and require a new preview where semantics changed.
- Reassignment immediately removes Agent interactive Customer rows/drill-downs and invalidates prepared Agent artifacts; Module 12 creates no Agent files initially. Admin export rows preserve historical actor/current-assignment semantics at cutoff without using current assignment to rewrite history.
- Owner corrections posted before cutoff appear under net/gross rules; after-cutoff changes do not change the file. No report cache is used for balance-sensitive mutation authorization.
- Search/index/report cache failure cannot erase ledger money. If integrity controls, subsidiary/control totals or owner component identities disagree, mark affected report Unavailable/incident and block export rather than reconcile through presentation math.
- Notification, analytics telemetry or artifact-read audit-index failure after Ready does not regenerate or duplicate job. Required canonical job/download audit persistence failure blocks the affected submit/publication/download.
- Object-store timeout with unknown publication result resolves the same artifact key/hash before retry; never expose an unverified partial or two Ready files.
- Cleanup is idempotent and cannot delete source records, issued statements, canonical audit or another artifact. Expired artifacts cannot become downloadable by restoring an old token.

## 11. Screens and accessibility

### 11.1 Report center

Show report catalogue, description/metric basis, role scope, default range/timezone, last verified cutoff and export availability. Interactive reports display active filters, result cutoff/watermarks, stale/partial state, summary cards, accessible chart/table, grouped totals and drill-down. Preserve permitted query state in navigation without exposing it in shareable URLs where identifiers are sensitive.

Admin export action appears only with current `reports.export`; it opens format/columns/limits/privacy/cutoff preview. Export job center shows job ID, report/format, requester, submitted/running/ready/failure/expiry times, filters summary, row/size, cutoff, current permission status and download/cancel action. Do not expose another Admin's private client/session metadata in ordinary job views.

### 11.2 States and error recovery

Distinguish initial no data, no filter matches, loading, stale projection, partially unavailable section, owner unavailable, integrity blocked, invalid filters, too large, queued/running, generation failed, cancelled, expired, access revoked and download failed. Never replace unavailable/filtered/withheld values with zero. A read retry is safe; a job mutation retry resolves idempotency first.

Support keyboard navigation/focus, semantic tables/headings, accessible filter labels/errors, skip links, status announcements, non-color chart encoding, data-table alternatives, 200% zoom/reflow, locale-aware visual formatting without changing exported numeric semantics, and accessible PDF requirements in Section 7.2. Large tables use server pagination/virtualization without losing screen-reader headers or totals context.

## 12. Notifications and audit

### 12.1 Notifications

| Event | Recipient | Content/channel |
| --- | --- | --- |
| Export queued/running beyond threshold | Requesting Admin only | In-app job ID/report/state; no Customer data or attachment. |
| Export Ready | Requesting Admin if still authorized | In-app safe metadata and link to authenticated job center; no direct email attachment/signed URL. Optional minimal email only after policy approval. |
| Export Failed/Cancelled | Requesting Admin if eligible | Safe category, no raw query/provider/evidence, retry/refine next step. |
| Export nearing expiry/Expired | Requesting Admin if eligible | In-app expiry/time; rerun behavior. Proposed one reminder, not repeated alerts. |
| Integrity/source coverage problem | Authorized operational owner | Report/type/cutoff/category and investigation link under owner scope. |

Use durable outbox, event/recipient/channel deduplication, bounded retries and current authorization at dispatch/retrieval. Revocation suppresses Ready/reminder content. Notification delivery failure does not rerun the job, extend artifact retention, change cutoff or make a partial artifact valid.

### 12.2 Audit

Canonically audit interactive access to sensitive/business reports as policy requires and every export preview/submit, worker start/result, cancellation, failure, publication, token mint, download success/failure/denial, expiry/cleanup, idempotency/conflict, scope/permission denial, integrity mismatch and retention override by an eventual authorized owner.

Record event/job/export ID, actor/service/role/required permission, business, report/schema/metric version, normalized spec hash and safe filter/scope summary, cutoff/watermarks, format/redaction, row/size/control totals, artifact hash/storage reference, timestamps, result/reason and correlation IDs. Do not store artifact contents, full search input containing personal data, raw evidence, signed tokens, credentials or provider secrets in audit.

Detailed audit access requires `audit.view`; ordinary report/job history shows operational fields only. `reports.export` cannot export this audit trail. Audit is append-only; required job/download audit failure blocks the action. Audit-index failure may lag only after canonical durable capture.

## 13. Performance, reliability, and security

- Proposed p95 interactive target on a documented representative profile: first page plus full-result summary within 3 seconds for a 366-date query; drill-down within 2 seconds. Label longer work and offer narrower filters, not partial unlabelled totals.
- Proposed export objective: start Queued work within 60 seconds and complete a 250,000-row CSV within 10 minutes at p95 under the declared workload; PDF within approved row/page limits within 5 minutes at p95. These are proposed service objectives, not permission to skip checks.
- Pagination/grouping/totals remain deterministic under concurrent postings because the query cutoff is fixed. A live interactive refresh resolves a new cutoff and restarts pagination explicitly.
- Rate-limit search/export/download enumeration by account/business/device risk without exposing record existence. Protect against CSV injection, PDF active content, archive bombs, path traversal, object-key guessing and denial through oversized grouping cardinality.
- Separate read/export renderer/storage credentials from financial posting credentials. Export workers have no mutation endpoints. Use least-privilege service identities, encrypted queues/storage, dependency allowlists and secret rotation.
- Backups retain job specs/manifests/hashes/audit under approved policy; artifact recovery never extends access beyond retention. Disaster recovery tests verify no cross-business artifact exposure and semantic reproduction at sampled cutoffs.
- Observability measures query latency, cutoff lag, unavailable owner sections, row/size-limit rejection, queue age, render failure, duplicate suppression, download denial, expiry cleanup and integrity controls without logging personal contents.

## 14. Release and dependency gates

Release is blocked until the following are approved and contract-tested:

1. Module 10 canonical financial projection, cutoff/as-of query, component identity, statements boundary, control/subsidiary reconciliation and projection versioning.
2. Module 04 historical Customer status/assignment intervals and privacy-safe identity/search fields.
3. Modules 05–09 exact status/date/amount/actor semantics, including mixed receipts, G/P/F/D, compensation, reservation, custody and reconciliation histories.
4. Module 06/07 expected-versus-eligible slot algorithm, allocation state as-of cutoff and assignment/unavailability interval treatment.
5. Approved metric dictionary/schema versions and owner queries capable of consistent historical cutoff; unavailable current-only fields cannot pretend to be historical.
6. `reports.export` job/run/publication/download enforcement; no raw audit/evidence path; protected storage/token service.
7. CSV injection profile, PDF renderer/accessibility/security, limits, manifests/hashes and complete-file publication.
8. Retention/privacy/residency policy, Agent personnel-adjacent reporting review, evidence redaction and incident response.
9. Notification/audit durability, recovery objectives, representative dataset/workload and tested performance/accessibility profiles.

An unavailable source produces **Unavailable/Partial coverage** for interactive sections and **Blocked/Failed** for exports whose declared totals require it. It never becomes zero, an omitted unexplained row, or a broader permission. Reports remain read-only regardless of urgency.

## 15. Worked examples

### 15.1 Mixed receipt without double counting

Agent receives ₦6,500 cash: ₦6,000 savings and ₦500 registration fee. Contribution report shows ₦6,000 savings. Fee report shows ₦500 external fee settlement/recognition. Custody report shows one ₦6,500 Agent receipt split into those allocations. Business total cash receipt is ₦6,500, not ₦13,000; Customer liability increases ₦6,000 only.

### 15.2 Withdrawal components

Posted withdrawal has G ₦10,000, P ₦9,800, F ₦200, D ₦0. Customer report shows total withdrawals/liability debit ₦10,000. Cash payout report shows ₦9,800. Fee earnings report shows ₦200. A combined grand total must not add all three as ₦20,000.

### 15.3 Opening/activity/closing and late posting

August report exported September 2 at cutoff T1 has opening liability ₦20,000, net August activity +₦20,000 and closing ₦40,000. A valid ₦2,000 receipt with August 31 occurrence commits September 3. T1 export remains unchanged. A T2 run includes the late line in August activity and closes ₦42,000, with both occurrence and commit dates disclosed.

### 15.4 Collection fulfillment versus receipts

Ten eligible period slots × ₦2,000 = ₦20,000 expected. Eight target slots are funded by cutoff = ₦16,000, so fulfillment is 80.0%. During the period the Agent also receives ₦6,000 catch-up for earlier slots and ₦4,000 advance for later slots. Received activity is ₦26,000, but it does not produce 130% fulfillment; catch-up/advance appear in separate allocation buckets.

### 15.5 Reassignment dimensions

Agent A recorded ₦50,000 before Customer reassignment to Agent B. A current-portfolio report at cutoff lists the Customer under B. Historical received activity remains attributed to A; effective-service report splits by assignment intervals. Agent A's masked custody balance may remain, but Agent A cannot drill into the Customer after reassignment.

### 15.6 Export revocation

Admin submits a five-year CSV with `reports.export`; permission is revoked while Queued. Worker recheck cancels/fails the job and no artifact publishes. If revoked after Ready, every later token/download is denied immediately while job/hash/audit history remains.

## 16. Indexed functional requirements

| ID | Requirement | Detail |
| --- | --- | --- |
| RPT-FR-001 | Keep interactive reports/exports distinct from Module 10 immutable issued Customer statements. | Section 2.3 |
| RPT-FR-002 | Display report/schema/timezone/date/status/scope/cutoff/watermark metadata and reject ambiguous/unavailable semantics. | Section 3.1–3.2 |
| RPT-FR-003 | Apply exact correction/component counting rules and prevent parent/component, receipt/remittance, G/P/F/D and reservation/liability double counting. | Section 3.3 |
| RPT-FR-004 | Reconcile defined balance reports as opening plus signed activity equals closing at one cutoff. | Section 3.4 |
| RPT-FR-005 | Enforce Customer-own, current-Agent/own-masked, and Admin business-wide interactive resource scopes for rows through drill-down. | Section 4.1 |
| RPT-FR-006 | Require current `reports.export` at submit/run/publication/notification/download for every Module 12 file. | Section 4.2 |
| RPT-FR-007 | Keep `reports.export`, `audit.view`, evidence access, approval and financial mutation independently authorized. | Sections 2.2, 4, 12 |
| RPT-FR-008 | Provide an authoritative Customer financial summary with lifetime/period components, liability, reservation/availability and separate fee obligations. | Section 5.1 |
| RPT-FR-009 | Report contribution receipt components/allocations/methods/actors/corrections without treating fee split or slots as extra savings receipts. | Section 5.2 |
| RPT-FR-010 | Separate Posted withdrawal G/P/F/D activity from non-posted request workflow states/reservations. | Section 5.3 |
| RPT-FR-011 | Separate fee assessment/settlement/application/waiver/refund/earnings/draw and non-fee deductions by owner date/source. | Section 5.4 |
| RPT-FR-012 | Compute schedule fulfillment from eligible target slots and show received-date current/catch-up/advance activity separately. | Section 5.5 |
| RPT-FR-013 | Report gross custody, allocations, Agent receivable, remittance, business assets, reconciliation versions/variance without mixing liability/income. | Section 5.6 |
| RPT-FR-014 | Separate current portfolio, historical actors/effective service, collection, custody and exception Agent metrics without composite ranking. | Section 5.7 |
| RPT-FR-015 | Report plan terms/progress/actuals/lifecycle/settlement exceptions without converting estimates or statuses into money. | Section 5.8 |
| RPT-FR-016 | Provide scope-safe exception summaries whose zero state requires complete owner coverage and whose links cannot mutate. | Section 5.9 |
| RPT-FR-017 | Validate filters/search/grouping, use stable cutoff-bound pagination and calculate totals over the complete scoped result. | Section 6 |
| RPT-FR-018 | Drill into owning views using immutable context and fresh authorization, never report-derived access authority. | Section 6 |
| RPT-FR-019 | Generate safe schema-versioned CSV with exact machine-readable money/time values and formula-injection protection. | Section 7.1 |
| RPT-FR-020 | Generate accessible bounded PDF reports clearly labelled as reports, with metadata/totals and no raw evidence. | Section 7.2 |
| RPT-FR-021 | Enforce date/row/page/size/rate/download/retention limits without silent truncation or undisclosed splitting. | Section 7.3 |
| RPT-FR-022 | Persist immutable export job specs and use Queued/Running/Ready/Failed/Cancelled/Expired lifecycle. | Section 8.1 |
| RPT-FR-023 | Recheck authority, align owner cutoffs, verify controls, render/scan/hash and atomically publish only complete artifacts. | Section 8.2 |
| RPT-FR-024 | Preserve reproducible manifest/history across cancellation, expiry, late corrections and reruns without replacing files. | Section 8.3 |
| RPT-FR-025 | Minimize/redact sensitive fields and protect artifact storage/tokens/download authorization. | Section 9 |
| RPT-FR-026 | Apply approved retention/cleanup without deleting source finance, issued statements, canonical audit or job integrity metadata. | Section 9 |
| RPT-FR-027 | Make job submission/execution/publication/cleanup idempotent and resolve unknown outcomes without duplicate or partial artifacts. | Section 10 |
| RPT-FR-028 | Fail reports/exports safely on scope/schema/source/integrity/concurrency changes and never use caches for mutation authority. | Section 10 |
| RPT-FR-029 | Provide accessible report/job screens with explicit empty/stale/partial/unavailable/too-large/revoked states. | Section 11 |
| RPT-FR-030 | Notify only the currently authorized requester/owners with deduplicated safe job/integrity events and no attachments/public links. | Section 12.1 |
| RPT-FR-031 | Durably audit sensitive report/job/token/download/cleanup/integrity actions while excluding contents/secrets. | Section 12.2 |
| RPT-FR-032 | Meet reviewed performance, deterministic pagination, security, recovery and observability requirements. | Section 13 |
| RPT-FR-033 | Block dependent release on missing owner metrics, cutoff history, renderer/storage/privacy/audit or authorization contracts. | Section 14 |

## 17. Acceptance scenarios and traceability

These are future verification scenarios, not claims of implementation or completed testing. Each record includes scenario/requirement IDs, build/fixture, actor/account/grants/scope, report/spec versions, filters/timezone/UTC boundaries/cutoffs, expected and observed row/group/control totals, artifact/hash/job/audit/notification references, and Passed/Failed/Blocked. UI hiding alone is not server authorization evidence.

| ID | Requirements | Scenario and expected result |
| --- | --- | --- |
| RPT-AC-001 | RPT-FR-001 | Generate same Customer period as interactive report and Module 10 Issued statement; report uses report ID/live-cutoff semantics and cannot replace/supersede/call itself the statement. |
| RPT-AC-002 | RPT-FR-002 | Change timezone across midnight/DST-capable fixture and run same local range; resolved UTC bounds/date basis/cutoff display explicitly and historical saved job never shifts. |
| RPT-AC-003 | RPT-FR-002, RPT-FR-028 | Remove one owner watermark or return stale incompatible source; section shows Unavailable/Partial and export fails rather than zero/mixed-current total. |
| RPT-AC-004 | RPT-FR-003 | Mixed ₦6,500 tender split ₦6,000 savings/₦500 fee appears once in custody, with correct component reports and no duplicated cash/contribution. |
| RPT-AC-005 | RPT-FR-003, RPT-FR-010 | Withdrawal G ₦10,000/P ₦9,800/F ₦200 reports liability debit/cash/fee separately; combined totals never become ₦20,000. |
| RPT-AC-006 | RPT-FR-003 | Receipt/remittance/reversal/reservation fixtures produce net posted totals once; pending workflows and reservation never appear as posted activity. |
| RPT-AC-007 | RPT-FR-004 | Customer, cycle, Agent receivable and custody opening + signed period movements each equal closing exactly at cutoff. |
| RPT-AC-008 | RPT-FR-004, RPT-FR-024 | Late pre-/in-period posting changes later run's opening/activity appropriately while old exported cutoff remains reproducible. |
| RPT-AC-009 | RPT-FR-005 | Customer direct queries/counts/drill-down show own rows only; Agent shows current assigned rows and masked own historical custody only; Admin baseline sees business rows. |
| RPT-AC-010 | RPT-FR-005 | Reassign Customer during Agent page/session; former Agent rows/count/filter suggestion/drill-down disappear immediately without leaking identity. |
| RPT-AC-011 | RPT-FR-006 | Baseline Admin/Customer/Agent cannot submit Module 12 file; `reports.export` Admin passes submit, worker, publish and download rechecks. |
| RPT-AC-012 | RPT-FR-006 | Revoke grant while Queued/Running/Ready; safe cancellation or denied publication/download occurs and history remains. |
| RPT-AC-013 | RPT-FR-007 | `audit.view` alone cannot export; `reports.export` cannot read/export raw audit/evidence, approve, waive, reconcile, post or mutate. |
| RPT-AC-014 | RPT-FR-008 | Customer row lifetime/period components reconcile to ledger liability; live reservation reduces available only; external unpaid/paid fee does not silently debit savings. |
| RPT-AC-015 | RPT-FR-009 | One receipt across multiple slots counts once; savings/fee split, partial/advance/catch-up, original/current Agent and correction states match owners. |
| RPT-AC-016 | RPT-FR-010 | Posted-mode excludes Pending review, Approved — awaiting payout, Payout processing, Payment failed and Outcome unknown requests, including any with an active hold overlay; workflow-mode includes primary state, hold, reservation and age, and Reversed net changes only after compensation posts. |
| RPT-AC-017 | RPT-FR-011 | Assessment/acknowledgement is not income/cash; external settlement versus savings application, waiver/refund/correction/draw and other deduction classify separately. |
| RPT-AC-018 | RPT-FR-012 | ₦20,000 target/₦16,000 funded plus ₦6,000 catch-up/₦4,000 advance gives 80.0% fulfillment and ₦26,000 received, not 130%. |
| RPT-AC-019 | RPT-FR-012 | Pause/status/Agent-unavailability intervals preserve original versus eligible expectations and current status never deletes historical due/funded slots. |
| RPT-AC-020 | RPT-FR-013 | Agent receipt/remittance/shortage fixture reconciles receivable opening/additions/remittance/closing; liability and fee income remain separate and unchanged by remittance. |
| RPT-AC-021 | RPT-FR-013 | Reconciled original plus supplemental late batch retains both versions and variance state without raw evidence export or silent rewrite. |
| RPT-AC-022 | RPT-FR-014 | Reassignment splits current portfolio/effective service/recording actor correctly; no peer report to Agent, composite score or rewritten Agent cash attribution. |
| RPT-AC-023 | RPT-FR-015 | Active/Completed/Closed/Cancelled cycle shows correct target/actual/lifecycle/fees/reservations; withdrawal does not unpay slots or imply closure. |
| RPT-AC-024 | RPT-FR-016 | All owners healthy/no items yields zero exceptions; one owner outage yields Partial/Unavailable rather than false zero; links enforce owning permissions. |
| RPT-AC-025 | RPT-FR-017 | Filter/search/group/date/amount boundaries, 25/50/100 pages and stable cursors return scoped deterministic rows; full-result totals do not equal page-only sum. |
| RPT-AC-026 | RPT-FR-017 | Zero expected denominator displays Not applicable; grouped aggregate percentage uses summed exact numerator/denominator, not average percentages. |
| RPT-AC-027 | RPT-FR-018 | Drill using exported/source ID after scope loss; owner denies access and report context grants no capability or protected evidence. |
| RPT-AC-028 | RPT-FR-019 | CSV preserves exact NGN decimals/ISO dates/quoting and neutralizes leading formula/control payloads without macros, formulas or active URLs. |
| RPT-AC-029 | RPT-FR-020 | PDF title/metadata says report, not Issued statement; multipage tables, NGN, reading order, tags, table alternative, masking and hash verify. |
| RPT-AC-030 | RPT-FR-021 | Exercise exact range/row/page/size/rate limits and limit+1; oversized run fails with refinement guidance and no truncated/partial artifact. |
| RPT-AC-031 | RPT-FR-022 | Job follows Queued→Running→Ready, plus Failed/Cancelled/Expired paths; only Ready full checked artifact downloads. |
| RPT-AC-032 | RPT-FR-023 | Worker aligns ledger/owner cutoffs, validates counts/control totals, scans/hashes and publishes once; incompatible cutoff or control mismatch blocks publication. |
| RPT-AC-033 | RPT-FR-024 | Manifest/spec/hash reproduces semantic rows/totals at same cutoff; later run/correction makes a new file and never overwrites old export. |
| RPT-AC-034 | RPT-FR-025 | Artifact/token guessing, copied/expired link, wrong account/business, revoked permission and stale token reveal no file/metadata; permitted download is encrypted/audited. |
| RPT-AC-035 | RPT-FR-025 | Contact/private notes/full bank/raw evidence/security/audit fields are absent; approved masks/source references do not enable unauthorized retrieval. |
| RPT-AC-036 | RPT-FR-026 | Seven-day cleanup removes artifact/partials/tokens idempotently while source ledger, Issued statements, job manifest/hash and canonical audit survive policy. |
| RPT-AC-037 | RPT-FR-027 | Duplicate/lost submission, worker retry/restart and object-store unknown result resolve one job/artifact hash; changed spec conflicts and no partial Ready. |
| RPT-AC-038 | RPT-FR-028 | Scope/schema/config/integrity changes during run fail/cancel rather than publish silently different data; no report cache authorizes a financial action. |
| RPT-AC-039 | RPT-FR-029 | Desktop/mobile/keyboard/screen-reader at loading/empty/stale/partial/unavailable/too-large/revoked/job states remains understandable and clears sensitive stale rows. |
| RPT-AC-040 | RPT-FR-030 | Ready/fail/expiry/integrity notification retries deduplicate, recheck permission and include no attachment/public token/Customer data; delivery failure does not rerun/extend. |
| RPT-AC-041 | RPT-FR-031 | Query/job/token/download/denial/cleanup/control-failure events contain spec/hash/cutoff/result but no file contents, signed token, credentials or raw evidence; detailed audit requires `audit.view`. |
| RPT-AC-042 | RPT-FR-032 | Representative load meets or records misses against p95 goals; fixed cutoffs keep pagination/totals deterministic during concurrent posting, with no cross-business exposure. |
| RPT-AC-043 | RPT-FR-032 | Recovery reproduces sampled manifest rows/control totals and prevents artifact access past retention or scope; worker credentials cannot reach financial mutation endpoints. |
| RPT-AC-044 | RPT-FR-033 | Remove each metric/history/cutoff/renderer/storage/privacy/audit/authorization dependency; affected report/export is Blocked, never guessed zero, broad access or manual spreadsheet workaround. |

Required fixtures include at least two Customers and Agents with reassignment intervals; Active/Inactive/Restricted/Archived Customers; Agent unavailable/offboarding states; all cycle states; mixed/partial/advance/catch-up/reversed receipts; external/savings fee settlements, waivers/refunds/deductions/draws; withdrawal requests in every state and Posted G/P/F/D; live reservations; original/supplemental reconciliation batches and shortages; remitted/unremitted custody; late postings/corrections; issued/superseded statements; baseline/split-grant/revoked Admins; malicious CSV cells; oversized jobs; stale/incompatible owner cutoffs; storage unknown outcomes; and representative performance/accessibility/recovery conditions.

## 18. Proposed decisions summary

- Interactive activity defaults to current calendar month, maximum 366 dates; exports support up to five years within row/size limits and a fixed cutoff.
- Reporting uses business timezone by default, plan timezone for slot eligibility, owner occurrence dates for activity, and committed-at cutoff for reproducibility.
- Collection fulfillment measures funded eligible target slots; received current/catch-up/advance money is a separate measure.
- Current assignment, effective service, recording actor and original cash responsibility are separate Agent dimensions; no composite leaderboard/score.
- All Module 12 CSV/PDF files require `reports.export`; Customer/Agent file needs are served by Module 10 single-Customer statements, not report-export bypasses.
- CSV/PDF, limits, seven-day artifact retention, 15-minute links, two concurrent/20 daily jobs and performance targets require product/security/privacy approval.
- Export files are cutoff-bound report snapshots, not immutable Issued Customer statements or accounting finality claims.
