# Dashboard and Operational Analytics

**Product version:** 2.0  
**Module:** 11  
**Module status:** Detailed draft for review  
**Sources:** [Version 1 PRD](../../PRD.md), [User Types](./01-user-types.md), [Authentication](./02-authentication.md), [Roles and Permissions](./03-roles-and-permissions.md), [Customer and Agent Management](./04-customer-and-agent-management.md), [Fees and Deductions](./05-fees-and-deductions.md), [Thrift Plans and Savings Cycles](./06-thrift-plans-and-savings-cycles.md), [Collections, Digital Thrift Card, and Reconciliation](./07-collections-thrift-card-and-reconciliation.md), [Reversals and Financial Corrections](./09-reversals-and-financial-corrections.md), [Transaction Ledger, Balances, and Statements](./10-transaction-ledger-balances-and-statements.md), and Module 08 when finalized

## 1. Purpose and decision status

This module defines role-specific dashboards and operational analytics for Customers, Agents and Admins. It gives each user a reliable summary of the records they may access, with precise metric meaning, period and status rules, authoritative-source links, freshness, and safe drill-downs.

The dashboard is a read projection. It does not post money, calculate an alternative balance, alter a plan, approve work, grant authority, settle a fee, release a reservation or reconcile cash. Every financial figure comes from the authoritative ledger or owning workflow. Customer savings liabilities, business fee earnings, business cash/custody, Agent receivables and outstanding obligations remain distinct.

The PRD establishes dashboard totals for Customers, plans, contributions, withdrawals, fees, expected/actual daily collections, liabilities and daily activity. Modules 01–10 refine role scope and accounting. Unless inherited, metric definitions, default periods, layouts, caching, service target and analytics choices below are **proposed for review**. Numbered requirements state intended behaviour rather than completed implementation.

## 2. Initial scope and exclusions

### 2.1 Included

- One configured business and NGN integer-kobo display.
- Customer home dashboard for own savings, plan, recent activity and requests.
- Agent dashboard for current assigned portfolio, today's collection work, own recorded activity, custody/reconciliation and pending tasks.
- Admin dashboard for business-wide Customers, plans, Customer liabilities, collection activity, payouts, fees, custody/reconciliation and exception queues.
- Today, this week, this month and approved custom-date filters with explicit date basis/timezone.
- Current summaries, reproducible as-of metadata, stable drill-down links, projection freshness, partial-failure states and scope-aware caching.
- Responsive/accessibility requirements, instrumentation and a proposed measured performance profile.

### 2.2 Excluded or separately owned

- Financial forecasts, investment returns, credit scores, agent ranking/bonuses, geographic maps, predictive delinquency, anomaly auto-decisions and cross-business benchmarking.
- User-designed dashboards, arbitrary formulas, custom report builders, emailed/scheduled dashboard exports and raw ledger downloads.
- Statutory accounting statements, profit-and-loss claims, tax reports and claims that custody assets are available profit.
- Financial mutations, approval decisions or bulk actions inside metric cards/charts. Links may open an owning workflow where the user independently has authority.
- Offline analytics, multi-currency conversion and near-real-time external bank confirmation.
- Permanently frozen daily/monthly regulatory reports; governed reproducible exports belong to Reporting.

A missing owner contract or projection never becomes zero, estimated actual money or permission to calculate from incomplete page rows.

## 3. Principles and ownership

1. **Authoritative sources:** posted amounts and balances use Module 10; reservations/payout states use Module 08; plan expectations use Module 06; receipt allocations and custody/reconciliation use Module 07; fee obligations/earnings use Module 05; Customer/Agent/assignment/status uses Module 04; reversal work uses Module 09.
2. **One meaning per metric:** every card has stable code, title, definition, unit, source, date basis, cutoff/as-of time, filters and drill-down contract.
3. **No aggregation across unlike balances:** Customer liability is owed to Customers; fee income belongs to the business; Agent receivable is custody responsibility; business asset balances describe custody. Never present their sum as “total balance.”
4. **Scope before aggregation:** apply authorization and effective assignment before rows, counts, totals, cache lookup, search suggestions and links.
5. **Effective financial values:** totals include posted entries and effective linked compensations exactly once. Pending workflows are shown separately, not in posted totals.
6. **Gross and net are labelled:** contribution, payout, withdrawal gross debit, fee, deduction, reversal and remittance components must not be collapsed ambiguously.
7. **Unavailable differs from zero:** a source-backed zero is displayed only after a complete successful query at a known watermark.
8. **Dashboard is not an approval source:** balance-sensitive actions re-read owners at commit; dashboard values or cache versions never authorize them.

## 4. Scope and access model

| Surface | Customer | Agent | Admin |
| --- | --- | --- | --- |
| Customer dashboard | Own account only | No impersonation; open assigned Customer profile separately | No impersonation; business oversight separately |
| Agent dashboard | No | Own role, current assignments and original-actor activity described below | May inspect an Agent's scoped operational summary business-wide |
| Admin dashboard | No | No | Active Admin business-wide baseline read |
| Protected task detail | Own permitted request status only | Current assignment/task scope | Owning permission for protected evidence/actions |
| Business/multi-Customer export | No | No | `reports.export` |
| Detailed audit event | No | No | `audit.view` |

Customer account/operational status does not remove own permitted historical read access. Agent dashboard access requires an Authentication-permitted session. An operationally Inactive Agent with usable account access may retain permitted read-only own/assigned records under Module 04, but sees no collection/plan/customer mutation actions. Suspended/Deactivated accounts have no application access. Preserve Authentication's valid-session temporary-lock exception.

Admin baseline read provides business summaries but not protected evidence or mutation controls. A card linking to fee, reconciliation, withdrawal, reversal, Customer or Agent management opens only the authorized owning page; missing `fees.manage`, `reconciliation.manage`, `withdrawals.review`, `reversals.review`, `customers.manage` or `agents.manage` hides its protected action while preserving permitted summary read.

### 4.1 Assignment semantics

- **Current portfolio** includes Customers whose effective assignment points to the viewed Agent at query cutoff. Reassignment immediately removes/adds those Customer rows and portfolio totals.
- **Recorded-by-Agent activity** attributes receipts to their immutable recording Agent, regardless of current assignment.
- **Current-portfolio activity** aggregates eligible records for Customers currently assigned at cutoff, regardless of historical actor, and must be labelled separately.
- **Custody/Agent receivable** stays with the original recording Agent until authoritative remittance/compensation; reassignment never transfers it.
- Historic “portfolio as of date” requires interval-aware assignment queries. If unavailable, do not reconstruct it from current assignment; offer recorded-actor history or mark the view unavailable.
- Admin Agent filters explicitly choose **Recording Agent**, **Current Agent**, or **Assigned at event time** where supported. Never silently mix them.

## 5. Time, cutoff and filter semantics

### 5.1 Business time

The business day uses the configured IANA business timezone, proposed initial `Africa/Lagos`. “Today” is local midnight through the current query cutoff; a completed historical day is its full local calendar day. Week is proposed Monday 00:00 through Sunday 23:59:59.999…, and month is the local calendar month. Date ranges are inclusive local dates, converted to precise instants by the source.

Each response records query cutoff in UTC, business timezone/version, ledger watermark, source watermarks and generation time. Configuration changes apply prospectively to new operational grouping; historical source events retain captured business date/timezone. Existing plan slot due dates use the plan's immutable timezone snapshot, even if the business timezone later changes. Reports disclose mixed timezone bases rather than shifting stored slot dates.

### 5.2 Date bases

| Metric family | Default period basis |
| --- | --- |
| Contributions/receipts | `received_date` for operational money-received totals; `committed_at` separately for posting activity. Late records update the historical received date and appear in posting-today diagnostics. |
| Scheduled/covered collection slots | Module 06 slot local due date/timezone; allocations may have different receipt dates. |
| Customer liability, availability, custody and earnings balance | Point-in-time ledger/reservation cutoff, not sum of period activity. |
| Withdrawals/payouts | Successful payout/effective date from Module 08; requested/approved counts separately labelled by their own dates. |
| Fees | Recognition/settlement effective date for earned amounts; assessment date for obligation activity; business draw date for draws. |
| Reversals | Compensation committed/effective date for posting activity; original occurrence remains unchanged and linked. |
| Customer/Agent/plan counts | State effective at cutoff; historical counts require complete effective intervals. |
| Requests/tasks/exceptions | Current state at cutoff; age from original creation/request time to cutoff. |

“Today” never means the viewer device's timezone. Future-dated events are excluded. Invalid/reversed ranges fail validation. Proposed interactive custom range maximum is 366 calendar days; longer analysis uses a governed report/export. Changing date, Agent or status filters resets pagination and updates every dependent metric to one consistent cutoff or clearly shows independently versioned sections.

## 6. Shared metric catalogue

All money is NGN integer kobo formatted as currency. Counts use distinct stable identifiers. Aggregate calculations use authoritative full result sets, not visible pages or rounded display values.

### 6.1 Customer and plan metrics

| Code / label | Definition |
| --- | --- |
| `customers_total` | Distinct Customer records in selected status scope at cutoff. Default Admin/Agent operational scope excludes Archived but shows status breakdown; lifetime registered includes Archived only when explicitly selected. |
| `customers_active/inactive/restricted/archived` | Distinct Customers whose Module 04 operational status is that value at cutoff. Account/invitation state is separate. |
| `current_assigned_customers` | Distinct non-Archived Customers currently assigned to scoped Agent at cutoff, with status breakdown. Historical assignment does not count. |
| `open_plans` | Distinct plans in Active, Paused or Completed state under Module 06 at cutoff. Completed remains open until Closed. |
| `active_collectible_plans` | Active plans whose Customer and current assigned Agent are eligible for collection at cutoff; future-start plans may be Active but not due today. |
| `plans_paused/completed/closed/cancelled` | Distinct plans by exact lifecycle at cutoff. Never infer state from elapsed final date or zero balance. |
| `funded_slots` | Count of plan slots fully net funded from Module 07 allocations after reversals. Skipped/partial/elapsed slots do not count. |
| `remaining_slots` | Required plan slot count minus fully funded slots; partial shortfall shown separately in money. Not days until scheduled end. |

### 6.2 Collection metrics

For selected slot date D and scope:

- `scheduled_target(D) = Σ target amount of all slots due on D`, preserving original schedule even when blocked.
- `eligible_target(D, cutoff) = Σ targets due on D that are collectible under plan, Customer and service eligibility intervals at the relevant evaluation cutoff`.
- `covered_due(D, cutoff) = Σ net live allocations to those eligible dated slots`, capped per slot, including earlier advance and later catch-up allocations posted by cutoff.
- `outstanding_due(D, cutoff) = max(eligible_target − covered_due, 0)` calculated per slot then summed. Skipped remains unfunded; blocked targets are disclosed separately.
- `received_savings(period) = Σ posted receipt savings components with received date in period − effective receipt compensations`, independent of which slot dates they fund.
- `received_fees(period) = Σ posted external fee tender components by received date − effective compensations`; never included in savings received.
- `cash_received`, `transfer_received`, `pos_received`, `other_received` split actual receipt tender by method and reconcile to total tender, not to scheduled target.
- `posted_receipt_count` counts distinct posted receipt IDs; funded-slot count and Customer count are separate. Reversed originals may be shown gross with reversed count, while net totals apply compensation once.
- `paid/partial/missed/skipped/blocked slot counts` use Module 07's projection. Blocked is not missed; skipped is not funded or waived.

Actual collection against today's expectations is `covered_due`, not money received today: an advance received earlier can cover today, and money received today may fund past/future slots. Display `received_savings_today` and `covered_today_slots` side by side, never subtract one from the other as cash shortfall. `outstanding_due_today` is slot obligation, not Customer debt or negative balance.

### 6.3 Ledger, payout and fee metrics

| Code / label | Authoritative formula and exclusions |
| --- | --- |
| `net_contributions` | Net posted savings contribution credits after linked compensations for selected occurrence/received scope. Excludes fees, remittance and estimates. Gross contributions and reversals may be separately disclosed. |
| `customer_liability` | Headline business total is the sum of every Customer savings-liability subsidiary balance at cutoff, including Inactive, Restricted and Archived Customers with balances; operational status filters never alter or replace it. An optional explicitly labelled scoped-liability analysis may apply Customer filters, but must display scope and reconcile as a subset. |
| `live_payout_reservations` | Sum of live gross reservation amounts G from Module 08 at reservation cutoff. Not a ledger debit. |
| `available_savings` | Customer liability minus live gross payout reservations, per Customer then aggregated. Unknown reservation data makes availability unavailable, not equal to liability. |
| `successful_net_payouts` | Sum of net cash paid P for successfully posted Customer payouts in period, after effective compensation. |
| `withdrawal_gross_debits` | Sum G debited from Customer liability for posted withdrawals, where G = net payout P + included fee F + approved deductions D. Never present as cash paid. |
| `pending_withdrawal_count/value` | Distinct current requests and their live gross reservation value by Module 08 state. Kept outside posted payout totals. |
| `fee_assessed` | Module 05 obligations assessed in period, including unpaid amount; not earnings or cash. |
| `fee_received_external` | Posted external fee settlements by receipt date; physical tender and recognized amount shown according to Module 05/07. |
| `gross_fee_recognized` | Posted credits to approved fee-income accounts before refunds/compensations in period. |
| `net_fee_earnings` | Effective fee-income recognition minus linked fee refunds/corrections in period. Excludes unpaid obligations and other-deduction income. |
| `book_fee_balance` | Lifetime net recognized fee earnings minus posted business fee-earnings draws at cutoff. Not necessarily drawable cash. |
| `drawable_fee_balance` | Module 05 authoritative conservative result after cash backing, Customer liabilities, payables and separate encumbrances. Never locally derived from book income. |
| `outstanding_fee_obligations` | Module 05 assessed minus effective settlement/waiver, never below zero; separate from Customer savings liability. |
| `other_deductions` | Net posted savings deductions to approved non-fee destinations in period; excluded from fee earnings. |

### 6.4 Custody and reconciliation metrics

| Code / label | Definition |
| --- | --- |
| `agent_receivable` | Module 10 debit balance by original recording Agent for entrusted money, less posted remittance/eligible compensation. It is neither Agent income nor additional Customer debt. |
| `business_custody` | Balances of approved cash/bank/POS/clearing assets by account at cutoff. Not labelled profit or Customer balance. |
| `recorded_tender` | Posted physical receipt components assigned to the selected original Agent/period, including savings and external fees; excludes savings-funded fees. |
| `confirmed_remittance` | Posted amount moving custody from original Agent receivable to business custody in period; not contribution/earnings. |
| `unreconciled_amount` | Authoritative Module 07 amount awaiting reconciliation by batch/version, not a locally computed residual from rounded cards. |
| `open_reconciliation_exceptions` | Distinct current exception IDs by severity/age/recording Agent. A shortage does not change Customer liability. |
| `reconciled_batches` | Distinct batch revisions in authoritative reconciled state; supplements count separately or as revisions according to Module 07, never both. |

## 7. Role dashboards

### 7.1 Customer dashboard

Header shows Customer name/ID, operational and account state separately, assigned Agent's permitted business contact and balance as-of status. Primary cards:

- Current Customer savings liability.
- Live payout reservations.
- Available savings.
- Lifetime net contributions, successful net payouts, net savings-funded deductions and total settled/retained Customer fees, each with clear definition.
- Current/open plan summary: amount, funded/N, partial shortfall, next/current due slot, lifecycle and interruption explanation.

Sections show digital thrift card, recent 10 posted transactions with reversals/refunds, outstanding fee obligations, current withdrawal/request statuses, and notifications. Values apply only to the authenticated Customer. An Invited/Inactive/Restricted/Archived Customer sees permitted history and exact limitations; no status is interpreted as zero. The Customer gains no plan, collection, withdrawal initiation, reversal or approval action through dashboard cards. Statement access follows Module 10 single-Customer scope.

### 7.2 Agent dashboard

Header separates account state, operational status, current assignment count and service interruptions. Default operational date is today. Primary cards:

- Current assigned Customers by Active/Inactive/Restricted and open/collectible plans.
- Scheduled, eligible, covered and outstanding target today.
- Savings money recorded by this Agent today, receipt count and tender-method split.
- Current Agent receivable, confirmed remittance today and own reconciliation status.
- Pending assigned follow-up tasks and exceptions.

Today's collection list comes from Module 07 and distinguishes current due, earlier catch-up, advance-covered, Partial, Missed, Skipped and Blocked. **My recorded activity** uses immutable recording Agent. **Current portfolio activity** uses Customers currently assigned at cutoff. Reassignment does not rewrite the former Agent's actual totals or cash responsibility.

Task panel may link to eligible Customer invitations, service interruptions, plans, collections, withdrawal/reversal follow-up and reconciliation explanations. Mutation controls remain in owning modules and require current Agent eligibility. An Inactive Agent view is read-only; do not offer new plan/collection/request actions.

### 7.3 Admin dashboard

Primary cards and sections:

- Customers by operational/account state; current assignments and unavailable-Agent service cases.
- Plans by Active/Paused/Completed/Closed/Cancelled and currently collectible count.
- Total Customer liability, live reservations and aggregate available savings, always separate.
- Net contributions/received savings, net payouts and gross withdrawal debits for selected period.
- Scheduled/eligible/covered/outstanding collection targets for selected slot date and savings tender actually received in selected received-date period.
- Gross/net fee recognition, fee assessment/outstanding obligations, book fee balance and authoritative drawable balance separately.
- Agent receivables, business custody by account, remittances, unreconciled amount and reconciliation exceptions.
- Pending withdrawal/reversal review counts, aged items, plan/closure/archive blockers, ledger integrity incidents and delivery/source failures where the current Admin may see them.

Charts show explicit unit/date basis: contribution and receipt trends, scheduled vs covered slot targets, successful net payouts, net fee recognition and custody/reconciliation. No stacked chart combines liability, income and assets into a fake total. Admin can filter by Customer status, plan state, recording/current/historical Agent basis, method and date. Admin cannot record a collection, create/manage a plan, initiate Agent-side withdrawal/reversal or mutate finances from the dashboard.

## 8. Tasks, exceptions and drill-down

A task card is a pointer to an owning workflow state, not a new task state. Required fields: owning module/type, stable record ID, Customer/Agent within permitted scope, current state/version, created/due/age time, severity where owner-defined, safe summary and authorized route. Counts are distinct by owner record and exclude terminal work. Do not double-count one root incident through each dependent event.

Suggested categories: delivery/service interruptions, due collection work, pending withdrawal/reversal reviews, reconciliation exceptions, plan completion/closure blockers, outstanding fee/refund obligations, offboarding handovers, stale projections and ledger integrity incidents. The current viewer sees only categories/fields permitted by role and grant; an Admin without review permission may see an aggregate pending count only where baseline read allows it, but no protected evidence or action.

Every metric supplies a stable deep link carrying metric code, normalized filter/date basis, scope mode and as-of/cutoff where supported. The destination reauthorizes and reruns its query; the URL is not a bearer credential. If current results legitimately differ because new events posted, show current results plus the originating dashboard cutoff. A reproducible snapshot link is offered only when the source can honor the stored cutoff/projection version.

Drill-down totals must reconcile to the card under identical cutoff/filters. Parent/component rows, compensation entries, batch supplements and request states have explicit aggregation rules to prevent double counting. If exact reconciliation is temporarily unavailable, say so and identify differing watermarks rather than forcing equal display totals.

## 9. Filters, comparisons and stable presentation

Global filters include preset period, custom date range, Customer operational status, plan state and—on Admin views—Agent basis/value. Filters are server-validated, scope-bound and encoded in a stable shareable route without personal data in query strings. A Customer has no cross-Customer filter; an Agent cannot choose another Agent or out-of-scope Customer.

Default monetary-activity comparison is the immediately preceding equal-length local-calendar period. Point-in-time balances compare only when a complete historical as-of snapshot exists; otherwise no percentage arrow is shown. Divide-by-zero yields **No comparable prior value**, not infinite growth. Incomplete current day is labelled **through [cutoff time]** and is not compared to a completed full day without an explicit like-for-like cutoff.

Default ordering: urgent actionable tasks by owner severity then oldest creation and stable ID; collection work actionable outstanding first then Customer name/ID; recent activity newest `committed_at` then ID. Lists use cursor pagination, default 25 and optional 25/50/100. Metric totals cover the full scoped filter, never only the visible page.

Currency uses symbol and ISO code in accessible detail; negative/correction presentation includes sign and text, not color alone. Large numbers may use compact visual labels only when the exact value is available on focus/tap. Rounding occurs for display after exact aggregation and never feeds another metric.

## 10. Projection, caching and freshness

A dashboard response is a manifest of independently sourced sections. Each section includes status (`Current`, `Stale`, `Rebuilding`, `Unavailable`, or `Partial`), generated-at, authoritative cutoff/watermark, source/projection version and applied scope/filter hash. **Current** means it meets the approved freshness objective and has no known integrity/source gap; it does not mean no event can arrive immediately afterward.

Proposed freshness objectives under the Section 14 profile:

- Posted financial balances, receipt activity and request/task state visible within 60 seconds of successful commit.
- Assignment, permission and account-access scope changes enforced immediately for authorization and within 5 seconds for refreshed visible portfolio/controls.
- Slot/card and reconciliation projections visible within 60 seconds; integrity incidents invalidate affected claims immediately when detected.

A cache key includes role/account, current permission/scope version, assignment/status version where relevant, normalized filters, timezone/version, section/projection version and cutoff strategy. Do not share Customer/Agent-specific payloads across principals. Permission/reassignment/suspension invalidates access immediately even if cached content remains physically stored; delivery reauthorizes before return.

Caches are derived and rebuildable. Event-driven invalidation plus bounded time expiry is proposed. A cache cannot post or approve money. Balance-sensitive owning workflows query authoritative data. During rebuild, show the last verified result with clear **as of** time if still permitted; never merge new counts with old denominators into an unlabeled metric.

### 10.1 Partial and unavailable behaviour

- Render independently valid sections when another owner fails. Do not fail the whole page if scope-safe navigation remains useful.
- A failed ledger section shows **Financial totals unavailable**, not ₦0. A failed reservation source makes available savings unavailable even if liability is known; show liability and reservation failure separately.
- A failed fee source does not remove Customer liability/collection data. A failed assignment source blocks Agent portfolio content because scope cannot be established.
- Partial data states name omitted source/metric families, last verified time and retry. Totals based on incomplete shards are not displayed as complete.
- A source version mismatch, projection lag beyond objective or integrity incident suppresses comparisons/derived ratios that would mix cutoffs.
- Retrying a read cannot replay a financial mutation, approval or export. Repeated refreshes use bounded backoff and avoid operational alert storms.

## 11. Concurrency, consistency and privacy

One dashboard render should use a common query cutoff where sources support it. When distributed sources cannot share a transaction, record each cutoff and permit derived cross-source formulas only if the contract defines compatible watermarks. Available savings requires an authoritative consistent liability/reservation view, not independent eventually consistent cards subtracted in the browser.

Events committed after cutoff appear on the next refresh. Cursor/drill-down queries bind cutoff and projection version so pagination does not duplicate/omit rows as new events arrive. If retention cannot serve the bound version, expire the cursor and reload rather than silently changing the result set.

Every request rechecks Authentication state, role, Admin permission where needed, current Customer assignment and resource relationships. Search, charts, counts, error messages, cache keys, deep links, downloads and telemetry obey the same scope. Remove former-Agent access immediately and clear unauthorized client state on reassignment/suspension. Historical attribution does not grant access.

Do not place Customer names, phones, emails, exact amounts, tokens, internal reasons or evidence in analytics telemetry, URLs, browser titles or shared cache identifiers. Charts/tooltips return only authorized row fields. Use minimum necessary identifiers in server logs with access/retention controls. Suppress small-cohort comparative ranking in initial scope rather than risk indirect Customer disclosure.

## 12. Export and audit boundaries

A screenshot/print of the currently visible scoped dashboard is a UI function only if platform privacy controls permit it; it is not an authoritative statement. Customer statement download follows Module 10. Agent receives no business export authority. Business/multi-Customer dashboard export requires `reports.export`, reruns the complete scoped query at a recorded cutoff, uses a governed asynchronous job, rechecks permission before execution/download and provides expiry/access logging. It excludes protected audit/evidence unless a separate owner explicitly permits it.

Ordinary dashboard reads need not create one permanent business audit record per refresh, which would produce noise. Security/access telemetry records authenticated viewer, role, scope/permission version, route/metric families, result status, cutoff and time without financial payload. Persist audit events for business exports, protected evidence/detail access, repeated high-risk cross-scope attempts, use of privileged drill-down, integrity-incident acknowledgement and configuration/projection changes. Detailed audit viewing requires `audit.view`.

Metrics and exports must preserve immutable source actor and current/historical Agent meaning. Download filenames avoid Customer personal data. Authorized export artifacts are encrypted, time-limited and revoked on scope/permission loss where supported; final format, retention and deletion are Reporting release gates.

## 13. Responsive, mobile and accessibility requirements

- Prioritize balance/status/task summary and today's work on small screens; cards wrap without horizontal page scrolling and tables provide accessible compact rows/detail.
- Every chart has a text title, definition, timeframe, as-of status, exact-value table or equivalent accessible summary, keyboard navigation and non-color series distinction.
- Status, increase/decrease, error, blocked and stale states use text/icon semantics in addition to color. Meet product-approved contrast and focus standards.
- Filters have labels, announced result/loading changes, valid range guidance, clear reset and focus preservation. Refresh does not unexpectedly move focus or erase chosen filters.
- Skeletons do not resemble authoritative zeros. Screen readers receive section loading/error states and exclude decorative chart duplication.
- Touch targets, number formatting and dense Agent collection rows remain usable on supported mobile widths. Respect reduced motion; animations are not required to understand change.
- The product accessibility conformance target and supported browser/device matrix must be approved and tested before release; absence blocks a conformance claim.

## 14. Performance, reliability and observability

### 14.1 Proposed measured profile

The initial service-level target is **p95 ≤ 3.0 seconds** from authenticated dashboard request to all above-the-fold non-failed sections rendered, measured server-to-browser on a representative production-like profile:

- Up to 10,000 Customers, 30 Agents, 10 Admins, 20,000 plan cycles, 2,000,000 ledger entries, 2,000,000 slots and 250 concurrent authenticated dashboard sessions.
- Warm derived projections/cache, indexed default queries, 4G-class 10 Mbps/100 ms network, supported mid-tier mobile device and 30-day default charts.
- Excludes first-time export generation, cold disaster rebuild and explicitly unavailable third-party services; these show progressive states rather than block the page.

Also propose p95 ≤ 1.0 second for authorized filter interactions served from current indexed projections and first meaningful dashboard shell ≤ 1.5 seconds under the same profile. These targets require load-test approval; do not claim compliance from local fixtures.

### 14.2 Reliability and observability

Instrument per-section latency, cache hit/age, source/watermark lag, error/partial rate, authorization denial, cursor expiry, metric reconciliation mismatch, export queue age and client render failures. Metrics use codes without personal labels. Alert on freshness/integrity thresholds with deduplicated incidents; alerts never auto-post money or widen access.

Projection rebuild is deterministic from authoritative sources and promotes only after counts/checksums/control totals match. A failed deployment/rebuild retains last verified data with state, or Unavailable if unsafe. Disaster restoration proves scope versions, cutoff manifests, cache invalidation and drill-down reconciliation. Feature flags cannot expose incomplete metrics as zero or bypass permissions.

## 15. Functional requirements

| ID | Requirement | Sections |
| --- | --- | --- |
| DSH-FR-001 | Provide distinct Customer, Agent and Admin dashboards with server-enforced role/resource scope. | 4, 7 |
| DSH-FR-002 | Keep dashboards read-only projections and prohibit financial/plan/approval/permission mutation authority. | 1–3 |
| DSH-FR-003 | Source each metric from its authoritative module with stable code, definition, unit, cutoff, date basis and drill-down. | 3, 5–8 |
| DSH-FR-004 | Keep Customer liability, reservations, fee earnings, custody, Agent receivable and obligations separately named and calculated. | 3, 6 |
| DSH-FR-005 | Apply business/plan timezone, period and event-date semantics consistently and expose UTC/source cutoffs. | 5 |
| DSH-FR-006 | Distinguish current assignment, event-time assignment and immutable recording-Agent attribution. | 4.1 |
| DSH-FR-007 | Calculate Customer/status/plan/slot counts from effective owner states without inference from dates/balances. | 6.1 |
| DSH-FR-008 | Calculate scheduled, eligible, covered, outstanding and received collections independently without double counting. | 6.2 |
| DSH-FR-009 | Derive liability/available savings, contribution, payout, withdrawal and deduction metrics from ledger/reservation owners. | 6.3 |
| DSH-FR-010 | Present fee assessment, obligation, receipt, recognition, book and drawable metrics with distinct meanings. | 6.3 |
| DSH-FR-011 | Present custody, Agent receivable, remittance and reconciliation metrics without altering Customer liability. | 6.4 |
| DSH-FR-012 | Provide Customer own balances/plan/activity/requests while preserving status limits and no mutation authority. | 7.1 |
| DSH-FR-013 | Provide Agent current portfolio, daily work, immutable own activity/custody and scoped tasks. | 7.2 |
| DSH-FR-014 | Provide Admin business operations/financial summaries without combining unlike balances or enabling Agent-only actions. | 7.3 |
| DSH-FR-015 | Project current owner tasks/exceptions once with stable authorized links and no new workflow state. | 8 |
| DSH-FR-016 | Make every drill-down reconcile under identical scope/filter/cutoff or disclose watermark differences. | 8 |
| DSH-FR-017 | Validate scope-bound filters, comparison semantics, stable ordering and full-result totals. | 9 |
| DSH-FR-018 | Return per-section freshness/status/source metadata and meet proposed propagation objectives. | 10 |
| DSH-FR-019 | Key/invalidate caches by principal, permission, assignment, status, filters, timezone and projection versions. | 10 |
| DSH-FR-020 | Render safe partial/unavailable states and never substitute zero or mix incompatible cutoffs. | 10.1 |
| DSH-FR-021 | Bind distributed reads/cursors to compatible cutoffs and reauthorize every page, link and refresh. | 11 |
| DSH-FR-022 | Protect private data in payloads, caches, URLs, logs, telemetry, charts and small cohorts. | 11 |
| DSH-FR-023 | Require `reports.export` for business/multi-Customer exports and preserve separate statement/audit permissions. | 12 |
| DSH-FR-024 | Audit protected views/exports/high-risk denials while avoiding noisy permanent logs for routine refreshes. | 12 |
| DSH-FR-025 | Meet responsive/mobile/keyboard/screen-reader/chart/state accessibility requirements. | 13 |
| DSH-FR-026 | Measure the proposed p95 targets under the declared capacity/network/device profile. | 14.1 |
| DSH-FR-027 | Monitor/rebuild projections safely and retain last verified or unavailable states through failure/recovery. | 14.2 |
| DSH-FR-028 | Block release of metrics whose owner, formula, scope, cutoff, accounting or privacy contract is unresolved. | 2, 16, 18 |

## 16. Acceptance scenarios and traceability

A scenario is **Blocked**, not Passed, when an owner integration/policy is unavailable. Evidence records build, fixture, user/scope/version, normalized filters, cutoff/watermarks, expected formula, owner totals, UI/API result, latency and accessibility result where applicable.

| ID | Requirements | Scenario and expected result |
| --- | --- | --- |
| DSH-AC-001 | DSH-FR-001 | Customer sees only own dashboard; Agent sees current assigned scope; Admin sees business summary; direct cross-role/cross-Customer calls leak nothing. |
| DSH-AC-002 | DSH-FR-002 | Invoke dashboard/card/drill-down APIs with mutation payloads or attempt collection/plan/approval actions as Admin/Customer; no business effect or authority. |
| DSH-AC-003 | DSH-FR-003 | For every visible card inspect definition/source/date/cutoff/link and reconcile to owner fixture; undocumented metric remains unavailable. |
| DSH-AC-004 | DSH-FR-004 | Fixture has liability ₦100,000, reservation ₦20,000, fee income ₦5,000, Agent receivable ₦30,000 and custody ₦70,000; display separately, never sum as balance/profit. |
| DSH-AC-005 | DSH-FR-005 | Test local midnight/week/month/year boundary and timezone change; receipt periods use captured business date and existing slots preserve plan timezone. |
| DSH-AC-006 | DSH-FR-006 | Reassign Customer A→B; A immediately loses portfolio but retains recorded-activity/custody attribution, B gains portfolio without inheriting old receipts/receivable. |
| DSH-AC-007 | DSH-FR-007 | Active/Paused/Completed/Closed plans, elapsed incomplete cycle and Archived Customer produce exact status counts; elapsed date/zero balance does not infer state. |
| DSH-AC-008 | DSH-FR-008 | Advance yesterday covers today, catch-up today funds prior slot, today receipt funds future slot; scheduled/eligible/covered/outstanding/received metrics remain independently correct. |
| DSH-AC-009 | DSH-FR-008 | Partial, skipped, missed and blocked slots count by owner rules; skipped remains outstanding and blocked never becomes missed. |
| DSH-AC-010 | DSH-FR-009 | Contributions 100,000, gross withdrawal G 30,000=P27,000+F2,000+D1,000, reservation 10,000 yields liability 70,000 and availability 60,000 without subtracting components twice. |
| DSH-AC-011 | DSH-FR-009 | Post and compensate transactions; net totals change once while gross/original/reversal drill-down preserves both entries. |
| DSH-AC-012 | DSH-FR-010 | Assessed unpaid, external paid, savings-applied, waived/refunded and drawn fees appear in correct obligation/recognition/book/drawable cards without counting assessment as earnings. |
| DSH-AC-013 | DSH-FR-011 | Agent receipt/remittance/shortage changes receivable/custody/reconciliation only; Customer liability stays at posted contribution amount. |
| DSH-AC-014 | DSH-FR-012 | Active/Inactive/Restricted/Archived and Invited Customer fixtures retain permitted own history with exact restrictions; unknown source never appears zero. |
| DSH-AC-015 | DSH-FR-013 | Operationally Active Agent sees today's actionable list; Inactive usable account is read-only; suspended account has no dashboard access. |
| DSH-AC-016 | DSH-FR-014 | Admin without granular permissions reads permitted summary but cannot see protected evidence/actions or record collections/manage plans; independent grant reveals only its owning route. |
| DSH-AC-017 | DSH-FR-015 | One root exception with dependent events appears once in task count and links to the owner; terminal work is excluded. |
| DSH-AC-018 | DSH-FR-016 | Card and drill-down use identical filter/cutoff and reconcile exactly; new event after cutoff appears only after refresh or with disclosed new watermark. |
| DSH-AC-019 | DSH-FR-017 | Test invalid/366-day boundary, stable cursor ordering, page-size choices, full totals and previous-period zero/incomplete-day comparisons. |
| DSH-AC-020 | DSH-FR-018 | Post contribution/reassignment/status event; observe financial/task propagation within 60s and scope/control refresh within 5s under profile, with timestamps. |
| DSH-AC-021 | DSH-FR-019, DSH-FR-021 | Revoke permission/reassign/suspend while cached page/cursor/link exists; next access denies/refreshes immediately and clears unauthorized client data. |
| DSH-AC-022 | DSH-FR-020 | Independently fail ledger, reservation, fee, assignment and reconciliation sources; preserve safe independent sections and show correct unavailable dependency effects, never zero. |
| DSH-AC-023 | DSH-FR-020, DSH-FR-021 | Mix incompatible old/new watermarks; derived cross-source metric/comparison is suppressed rather than calculated in browser. |
| DSH-AC-024 | DSH-FR-022 | Inspect URL/cache/log/telemetry/chart payloads and small scopes; no forbidden PII, financial payload or cross-scope metadata. |
| DSH-AC-025 | DSH-FR-023 | Customer gets own statement route; Agent business export fails; Admin export requires `reports.export`, rechecks before job/download and expires safely. |
| DSH-AC-026 | DSH-FR-024 | Routine refresh produces bounded telemetry, while protected evidence view, export and repeated cross-scope denial produce required access/audit evidence. |
| DSH-AC-027 | DSH-FR-025 | Verify supported mobile widths, zoom/reflow, keyboard/focus/screen reader/reduced motion, chart table alternative and non-color statuses. |
| DSH-AC-028 | DSH-FR-026 | Load declared dataset/concurrency/network/device profile; above-fold p95 ≤3s, cached filter p95 ≤1s and shell ≤1.5s, or release target is not claimed. |
| DSH-AC-029 | DSH-FR-027 | Corrupt/rebuild projection and fail promotion; mismatch alerts, affected metrics become stale/unavailable, last verified remains labelled and rebuilt totals reconcile before promotion. |
| DSH-AC-030 | DSH-FR-028 | Disable each owner/contract or present undefined Module 08 state; affected metric/scenario is Blocked with no invented formula/status/permission. |

## 17. Worked metric examples

### 17.1 Today's collection activity

Three ₦2,000 slots are due today: one was paid yesterday in advance, one receives ₦2,000 today, and one is unfunded. Scheduled and eligible target are ₦6,000; covered due is ₦4,000; outstanding due is ₦2,000. Savings received today is ₦2,000. The dashboard does not report ₦4,000 collected today because half of today's coverage was received yesterday.

Later today the Agent receives ₦6,000 allocated to yesterday's missed slot and two future slots. Savings received today becomes ₦8,000, while covered today's slots remains ₦4,000. Cash activity and schedule coverage answer different questions.

### 17.2 Customer liability and withdrawal

A Customer has net contributions ₦100,000. A posted withdrawal debits gross G=₦30,000: net cash payout P=₦27,000, fee F=₦2,000 and deduction D=₦1,000. Current liability is ₦70,000. With a different live gross reservation of ₦10,000, available savings is ₦60,000. Dashboard shows successful net payouts ₦27,000 and gross savings debits ₦30,000; it never subtracts F and D from the already gross-debited liability again.

### 17.3 Fees, earnings and custody

An Agent receives ₦11,000 cash: ₦10,000 savings and ₦1,000 external fee. Customer liability rises ₦10,000, fee income/settlement rises ₦1,000, and Agent receivable rises ₦11,000. A later ₦11,000 remittance moves custody to business cash, creates no new contribution/fee earnings and leaves Customer liability unchanged. An unpaid ₦500 fee assessment increases outstanding obligations, not earnings, cash or Customer liability.

### 17.4 Reassignment

Agent A recorded ₦40,000 and still owes ₦10,000 remittance when the Customer moves to Agent B. B's current portfolio includes the Customer and their future due work. A's period recorded contribution remains ₦40,000 and Agent receivable remains ₦10,000. B's recorded-activity total does not inherit either value. Admin filters identify Current Agent B and Recording Agent A without rewriting history.

## 18. Proposed choices and release gates

| Decision | Draft recommendation / consequence |
| --- | --- |
| Business periods | Africa/Lagos initial setting; Monday-start week; inclusive local calendar dates; plan slots retain snapshot timezone. Approve with Business Configuration. |
| Activity basis | Received date for receipt operations, payout effective date for successful payout, recognition date for fees, committed-at separately for posting diagnostics. |
| Contribution/withdrawal labels | Net contributions; net cash payouts P and gross liability debits G shown separately. Avoid ambiguous PRD “total withdrawals.” |
| Collection performance | Show schedule coverage and received money side-by-side; never derive cash shortfall by subtracting unlike bases. |
| Customer scope default | Non-Archived count plus explicit status breakdown; all statuses remain in liability totals whenever balance exists. |
| Assignment analytics | Current portfolio and immutable recording-Agent activity are separate; historic assignment metrics require interval owner. |
| Comparison | Prior equal-length period, like-for-like incomplete cutoff; no infinity/no unsupported historical balance comparison. |
| Freshness | 60 seconds financial/task projections; immediate authorization and 5-second visible assignment/control refresh under declared profile. |
| Performance | Above-fold p95 ≤3s, indexed cached filter p95 ≤1s, shell ≤1.5s under Section 14 profile; validate before claim. |
| Export | Governed async business export requires `reports.export`; format/retention/deletion remain Reporting gates. |
| Module 08 | Final request states, reservation values, payout effective date and review queues must replace generic contracts before release. |
| Privacy/audit | No personal telemetry/URL data; routine reads use bounded access telemetry, protected access/export/high-risk denial gets durable audit. Final retention policy required. |

## 19. Related modules

Module 11 consumes authoritative state and postings from Modules 01–10 and provides read-only summaries and stable routes. Reporting may reuse its reviewed metric catalogue but must define export snapshots, retention and broader analysis separately. Business Configuration owns timezone and approved dashboard settings. No dashboard projection becomes an accounting ledger, plan scheduler, authorization grant or workflow decision source.
