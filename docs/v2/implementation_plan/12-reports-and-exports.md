# Module 12 — Reports and Exports

## Approved release and defaults

Implement the staged interactive release from [Module 12](../modules/12-reports-and-exports.md). The user approved the draft reporting defaults and selected interactive reports first. CSV/PDF jobs, artifact storage/downloads, historical snapshots and financial mutations are excluded from this release. No dependencies or application schema changes were added.

Activity defaults to the current calendar month through now; snapshot reports use the current cutoff. Use the configured business timezone, initially Africa/Lagos, while retaining receipt/plan local dates and their captured timezones. Interactive ranges allow at most 366 inclusive dates ending no later than today. Page sizes are 25/50/100. An implementation capacity guard limits grouped results to 1,000 groups; larger results require narrower filters or removal of grouping and return no partial totals.

The approved draft export defaults remain prospective. Export permission alone does not enable files: reproducible owner cutoffs, private storage, approved rendering, retention, authorization, canonical audit and recovery evidence must be ready first.

## Implementation checkpoint — 26 September 2026

| Task    | Delivered behavior                                                                                                                                                                                                                     | Status                                                           |
| ------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------- |
| RPT-T01 | Named authenticated GET report-center/detail routes; all nine families for Admins/Agents and seven permitted Customer families; scope-safe filters and current Agent eligibility.                                                      | Implemented                                                      |
| RPT-T02 | Shared dashboard/report metric definitions with version 1; TypeScript payload contracts; manifest with scope, schema, timezone/UTC boundaries, cutoff and ledger state/version/watermark. Dashboard arithmetic is unchanged.           | Implemented                                                      |
| RPT-T03 | Current Customer savings positions, verified receipt components, withdrawal workflow, plan terms/lifecycle, Agent current portfolios and separate masked original-actor totals, custody positions, pending withdrawal/reversal queues. | Implemented for supported owners                                 |
| RPT-T04 | Per-report partial/unavailable states and named dependency reasons; fee, payout/compensation, historical eligibility/settlement and export capability gates.                                                                           | Implemented                                                      |
| RPT-T05 | MySQL repeatable-read transaction; verified financial-source gates; complete filtered totals/groups; authenticated encrypted cursors binding viewer/scope/query/schema/definition/business versions, cutoff and source fingerprint.    | Implemented; production-engine concurrency evidence pending      |
| RPT-T06 | Responsive Inertia report center/detail, Wayfinder navigation, shared date picker/layout, accessible tables and metric definitions; explicit refresh; five-second scope polling and stale-content clearing.                            | Implemented; authenticated visual/accessibility evidence pending |
| RPT-T07 | Focused tests, owner/dashboard regressions, scoped static/frontend checks and acceptance mapping below.                                                                                                                                | Recorded; full Module 12 acceptance remains blocked              |

### Supported reads and limits

- Savings rows include permitted Customer identity/status/current Agent, verified current liability, live gross reservations and availability. Archived liabilities remain within authorized financial scope. Invalid reservations preserve verified liability while reservations/availability become unavailable. Historical opening/movement/closing reports remain gated.
- Contributions and collection-performance received-activity sections count one posted root receipt, independently of slot allocation count. Savings, external fee tender and gross cash remain separate. Recording Agent and current Agent are separate dimensions. Former Agents receive no former-Customer identity or receipt detail; their permitted aggregate recording/custody responsibilities remain separate.
- Withdrawals expose primary workflow state, hold overlay, submission/deadline and live reservations, without private reasons, destinations, bank data or posted-payout claims. Admin owner links require current review permission. Posted G/P/F/D remains gated.
- Plans expose explicit lifecycle, captured timezone, start/scheduled end, daily amount, required slots and agreed gross target. Targets are estimates, not contributions, liability, closure or settlement. Eligible funding/settlement breakdown remains gated.
- Agent operations separate current portfolio counts from original recording-actor activity within the selected received-date range. No peer detail for Agents, ranking, score or historical effective-service inference.
- Custody separates original-Agent responsibility and independently verified Admin business cash. It does not add either amount to Customer liability or label cash as profit. Opening/movement/closing and complete batch variance history remain gated.
- Exceptions cover current pending withdrawal/reversal queues only. They always disclose partial owner coverage, including when the supported count is zero. They expose no audit/evidence or mutation actions.

### Consistency, pagination and privacy

Rows, full-result totals and group totals come from one scoped transaction. Reads process bounded batches and retain only the requested page and grouped aggregates in application memory. Every continuation recomputes its source fingerprint; changed scope, grants, filters, schema/config, relevant rows or source watermarks require a new run. This is current-source pagination, not durable historical snapshot support. PDO buffering and repeated full-result scans remain production-capacity concerns to measure.

Owner links reauthorize at the owner's current cutoff and disclose that they are not historical aggregate drill-downs. Outputs allow only explicit safe columns and exclude contact information, private notes, destinations, raw evidence, credentials and audit contents. Telemetry records report code/status/schema only; canonical protected query/download audit is not claimed.

## Verification

- 39 focused Report tests pass, covering route/role boundaries, filters, date normalization, source gates, exact receipt/reservation totals, grouping, pagination, changed sources, reassignment, revoked permissions, inactive Agents, private-field exclusion and shared metric agreement.
- The final integration run across Report, Dashboard, DashboardAnalytics, Collection, LedgerTransaction and Withdrawal passes 107 tests with 873 assertions, including all 39 report cases.
- Scoped PHPStan passes for all changed backend classes. Pint, changed-file frontend formatting/lint, Vue type checking, production build, route inspection and `git diff --check` pass.
- Repository-wide `npm run check` remains blocked by pre-existing formatting issues in unrelated pages/components and Modules 05–07 plan documents. Unrelated files were not reformatted.
- Browser navigation to the Herd report center redirected to login. Authenticated desktop/mobile, keyboard, screen-reader and runtime scope-clearing checks are unverified. No credentials were changed, account created or authentication bypassed.
- MySQL concurrent-posting/restore, declared production load, privacy review and full-suite evidence remain unverified. Request the complete `php artisan test --compact` run after focused verification.

## Acceptance evidence

A Blocked scenario may have partial automated evidence; it is not promoted to Passed until every required owner and release condition is verified. All evidence here concerns the local implementation and fixtures, not production certification.

| Scenario   | Status  | Evidence or remaining blocker                                                                                                                             |
| ---------- | ------- | --------------------------------------------------------------------------------------------------------------------------------------------------------- |
| RPT-AC-001 | Blocked | Reports remain distinct read projections; issued-statement/export comparison is deferred.                                                                 |
| RPT-AC-002 | Blocked | Business-local month and UTC boundaries, timezone changes and preserved receipt/plan dates pass; immutable jobs and full DST matrix pending.              |
| RPT-AC-003 | Blocked | Missing ledger/projection and inconsistent financial-source gates pass; complete incompatible-owner watermark matrix pending.                             |
| RPT-AC-004 | Passed  | Mixed cash fixture separates 200,000 kobo savings, 50,000 external fee and 250,000 tender; one receipt and original-Agent responsibility reconcile.       |
| RPT-AC-005 | Blocked | Posted payout G/P/F/D owner execution unavailable.                                                                                                        |
| RPT-AC-006 | Blocked | Workflow and reservations remain separate; net compensation/remittance report matrix incomplete.                                                          |
| RPT-AC-007 | Blocked | Historical opening/movement/closing owner contracts unavailable.                                                                                          |
| RPT-AC-008 | Blocked | Late receipt occurrence dates pass; immutable export reproduction is deferred.                                                                            |
| RPT-AC-009 | Passed  | Own-Customer isolation, current Agent scope, masked original-actor/custody aggregates and Admin business reads pass.                                      |
| RPT-AC-010 | Blocked | Reassignment removes server rows/filters and invalidates cursors; authenticated session/poll clearing remains unverified.                                 |
| RPT-AC-011 | Blocked | Files unavailable even with reports.export; submit/worker/publication/download pipeline deferred.                                                         |
| RPT-AC-012 | Blocked | Interactive review-grant changes invalidate access/links; queued/running/ready jobs deferred.                                                             |
| RPT-AC-013 | Blocked | Read routes reject mutations and serialize no protected evidence; full export/audit grant matrix deferred.                                                |
| RPT-AC-014 | Blocked | Current liability, archived scope and reservation separation pass; period/lifetime reconciliation incomplete.                                             |
| RPT-AC-015 | Blocked | Multi-slot counting, mixed receipts, separate actor/current Agent and late-date fixtures pass; net correction and complete allocation reporting deferred. |
| RPT-AC-016 | Blocked | Pending workflow and hold rows are not posted payouts; complete posted/compensated payout matrix unavailable.                                             |
| RPT-AC-017 | Blocked | Full fee/deduction family contract unavailable; no assessed-as-income fallback.                                                                           |
| RPT-AC-018 | Blocked | Eligible schedule fulfillment is gated; received activity does not provide its denominator.                                                               |
| RPT-AC-019 | Blocked | Complete historical eligibility intervals unavailable.                                                                                                    |
| RPT-AC-020 | Blocked | Original-Agent current responsibility passes; opening/additions/remittances/closing report deferred.                                                      |
| RPT-AC-021 | Blocked | Complete versioned reconciliation/supplemental-batch report unavailable.                                                                                  |
| RPT-AC-022 | Blocked | Current portfolio and recording actor separation pass; historical effective-service reporting unavailable.                                                |
| RPT-AC-023 | Blocked | Five explicit lifecycle states and agreed targets pass; full funding/withdrawal/fees/settlement report unavailable.                                       |
| RPT-AC-024 | Blocked | Supported queue counts and safe links pass; complete exception-owner coverage unavailable.                                                                |
| RPT-AC-025 | Blocked | Allowed filters/ranges, stable pagination, grouping and full totals pass; full specification search/amount/filter matrix deferred.                        |
| RPT-AC-026 | Blocked | No eligible denominator or fulfillment percentage is enabled; exact ratio matrix deferred.                                                                |
| RPT-AC-027 | Blocked | Scoped owner links and former-Agent denial pass; exported-ID scenario deferred.                                                                           |
| RPT-AC-028 | Blocked | CSV schema/rendering deferred.                                                                                                                            |
| RPT-AC-029 | Blocked | PDF renderer/accessibility/hash workflow deferred.                                                                                                        |
| RPT-AC-030 | Blocked | Interactive 366-day and page-size validation implemented; export limits pipeline deferred.                                                                |
| RPT-AC-031 | Blocked | Export job lifecycle deferred.                                                                                                                            |
| RPT-AC-032 | Blocked | Export source alignment/control checks/publication deferred.                                                                                              |
| RPT-AC-033 | Blocked | Immutable manifest/artifact reproduction deferred.                                                                                                        |
| RPT-AC-034 | Blocked | Protected artifact/token/download infrastructure deferred.                                                                                                |
| RPT-AC-035 | Blocked | Interactive allowlisted columns exclude private fields; export minimization/privacy review pending.                                                       |
| RPT-AC-036 | Blocked | Artifact cleanup/retention infrastructure deferred.                                                                                                       |
| RPT-AC-037 | Blocked | Job/publication idempotency infrastructure deferred.                                                                                                      |
| RPT-AC-038 | Blocked | Scope/query/source/config cursor invalidation passes; job concurrency and publication controls deferred.                                                  |
| RPT-AC-039 | Blocked | Responsive table/label/status implementation and scope clearing present; authenticated device/accessibility evidence pending.                             |
| RPT-AC-040 | Blocked | Export notifications deferred.                                                                                                                            |
| RPT-AC-041 | Blocked | Privacy-safe report telemetry present; canonical protected audit/download events pending.                                                                 |
| RPT-AC-042 | Blocked | Source-change pagination tests pass; MySQL concurrency and declared load profile unverified.                                                              |
| RPT-AC-043 | Blocked | Production restoration/reproduction and worker-authority evidence pending.                                                                                |
| RPT-AC-044 | Blocked | Selected missing-source gates tested; exhaustive per-owner/export disable matrix pending.                                                                 |
