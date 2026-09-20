# Business Settings and Configuration

**Product version:** 2.0  
**Module:** 15  
**Module status:** Detailed draft for review  
**Sources:** [Version 1 PRD](../../PRD.md), [User Types](./01-user-types.md), [Authentication](./02-authentication.md), [Roles and Permissions](./03-roles-and-permissions.md), [Customer and Agent Management](./04-customer-and-agent-management.md), [Fees and Deductions](./05-fees-and-deductions.md), [Thrift Plans and Savings Cycles](./06-thrift-plans-and-savings-cycles.md), [Collections, Digital Thrift Card, and Reconciliation](./07-collections-thrift-card-and-reconciliation.md), [Withdrawals and Payout Approvals](./08-withdrawals-and-payout-approvals.md), [Reversals and Financial Corrections](./09-reversals-and-financial-corrections.md), [Transaction Ledger, Balances, and Statements](./10-transaction-ledger-balances-and-statements.md), [Dashboard and Operational Analytics](./11-dashboard-and-operational-analytics.md), [Reports and Exports](./12-reports-and-exports.md), [Notifications and Communication](./13-notifications-and-communication.md), [Audit, Security Operations, and Retention](./14-audit-security-operations-and-retention.md)  
**Related owners:** infrastructure/secrets; provider, document, evidence, storage and recovery services

## 1. Purpose and decision status

This module defines the single configured thrift business, its profile, operational defaults, payment-method mappings and controlled feature enablement. It specifies who may read or change configuration, how a change is drafted/reviewed/versioned/activated, and how dependent workflows preserve the settings that governed their historical records.

Version 2 has one seeded business and no tenant selector, public business registration or platform administrator. Active Admins have baseline access to view the configured business profile and safe current settings. Only an active Admin with `business.settings.manage` may publish a settings change. A setting never grants another permission or overrides fixed Customer/Agent/Admin capabilities.

Unless inherited from Modules 01–13, the catalogue, field limits, fixed day-boundary rule, activation timings, seed defaults, settings lifecycle, readiness model and performance targets below are **proposed for review**. A setting whose owning contract, account mapping, provider authority, migration or safety gate is undefined remains read-only/unavailable; `business.settings.manage` cannot fill the gap.

## 2. Initial scope and exclusions

### 2.1 Included

- One immutable business identity record with versioned display/contact/branding profile.
- NGN initial currency, IANA business timezone, operational day boundary, week start, locale and dashboard/report defaults.
- Versioned collection payment-method registry linked to approved existing custody/account mappings.
- Receipt amount/late-recording limits within product hard limits.
- Versioned withdrawal-method registry that can be enabled only after Module 08 executor/evidence/finality readiness passes.
- Notification-channel/template-policy integration without an Admin template composer or broadcast feature.
- Feature readiness and controlled enable/disable for a closed catalogue.
- Draft, preview, future-effective publication, immutable versions, reason/fresh authentication, conflict protection, prospective propagation, safe rollback-as-new-version, notifications and audit.

### 2.2 Excluded or separately owned

- Creating/deleting businesses, tenants, branches, subsidiaries or switching business context.
- Changing the immutable business ID or transferring business ownership.
- Fee rule/model/value/timing configuration, which requires `fees.manage` under Module 05.
- Admin/role/permission changes, which require `admins.manage` under Module 03.
- Password, MFA, invitation, session, recovery, abuse/rate-limit or authentication-security policy, owned by Authentication and `security.operations.manage` where specified.
- Customer/Agent profile/status/assignment, plan terms, financial approvals, reconciliation decisions, ledger postings, statement/report generation, notifications dispatch or audit decisions.
- Free-form chart-of-accounts/account creation, manual journals, account retirement, period close/reopen, arbitrary custody adjustments or secrets entry. Authority for account-catalogue creation is not established; the action remains gated.
- User-editable email/SMS templates, arbitrary recipients, marketing/broadcast campaigns, unsupported channels, notification-event rules, user preference overrides or security-message content.
- Multi-currency, exchange rates, tax/statutory settings, interest, commissions, targets, custom frequencies, custom roles and experimental hidden flags.
- Retrospective rewriting/migration of issued statements, posted transactions, plan slots, fee/invitation snapshots, receipts/batches, payout requests, notifications, audit or report artifacts.

## 3. Core configuration model and invariants

The configured business has one immutable internal business ID, one public business reference, a seeded creation source/time, and a sequence of immutable published configuration versions. A mutable draft is never runtime configuration. Runtime reads resolve the published version effective for the requested event time and feature.

Each setting definition has stable code, owner, value type/schema, sensitivity, default, allowed values/hard bounds, applicability, effective-time policy, snapshot/capture policy, dependencies, migration behavior, display/help text and version. Each published bundle stores bundle ID/version, prior version, typed values, actor, reason, preview/confirmation references, fresh-auth evidence reference, published/effective timestamps, dependency-readiness results and canonical audit event.

The following invariants apply:

1. Exactly one business exists; client-supplied business IDs never change scope.
2. No setting broadens role/resource permissions or bypasses current account/session/assignment/status checks.
3. Published versions are immutable. Correction or rollback publishes another version; no history overwrite.
4. Changes apply prospectively according to owner contracts. Historical events retain captured/snapshotted configuration and UTC timestamps.
5. Missing/invalid/unavailable configuration fails closed for dependent mutations. It is not equivalent to zero, disabled evidence, cash, NGN or a default account.
6. A feature flag cannot enable an unready dependency, unsupported method, unknown mapping or prohibited role action.
7. Configuration is not financial truth. It cannot post, reverse, waive, reconcile, approve, settle or edit ledger balances.
8. Secrets live in approved secret/provider stores. Settings contain opaque verified references and safe metadata, never credentials, API keys, OTPs or provider private payloads.
9. Every runtime financial/operational event records the effective configuration/mapping versions required to reproduce its handling.
10. Disabling new use never deletes history or abandons in-flight/unknown-outcome recovery.

## 4. Authorization and visibility

### 4.1 Access matrix

| Action | Customer | Agent | Admin baseline | Admin with `business.settings.manage` |
| --- | --- | --- | --- | --- |
| View public business identity/contact/branding | Relevant own experience | Relevant workspace | Yes | Yes |
| View safe effective operational defaults/readiness | No settings screen; owner workflow may display applicable value | Applicable value in owning workflow only | Yes, read-only | Yes |
| View protected mapping/provider metadata | No | No | Safe readiness/masked mapping only | Masked management view; no secrets/raw evidence |
| Create/edit/discard draft | No | No | No | Yes |
| Preview/publish/schedule/disable/rollback-as-new-version | No | No | No | Yes, subject to setting/dependency gates |
| Configure fee rules | No | No | No | No; `fees.manage` separately |
| Manage Admin grants/authentication/security policy | No | No | No | No; owning permissions/workflows |
| View detailed configuration audit | No | No | No | `audit.view` separately; management timeline is limited |
| Export business reports or raw settings backup | No | No | No | `reports.export` does not provide raw configuration backup; no user backup export initially |

All reads/mutations derive the one business from trusted account/system context. Public profile data does not make the settings endpoint public. A baseline Admin can inspect effective safe values, version/effective time and readiness so oversight does not require write permission; protected account identifiers, provider configuration and internal reasons remain minimized.

### 4.2 Authentication and separation

Draft editing requires a current active Admin session and `business.settings.manage`. Proposed policy: every publication, scheduled-change cancellation, feature disable, or rollback-as-new-version requires fresh password-and-MFA authentication under Authentication's existing 10-minute freshness window, explicit confirmation and reason. Draft saves/previews do not extend freshness and create no runtime effect.

Recheck active account/session, permission version, draft/base version, dependency versions and fresh authentication at publication commit. Revocation mid-request fails without publication. One authorized Admin may publish; no second approval is introduced initially. The publisher cannot use settings to assign themselves another grant, approve money or override security restrictions.

## 5. Setting catalogue and ownership

### 5.1 Catalogue summary

| Group | Setting examples | Write owner / key boundary |
| --- | --- | --- |
| Business identity and branding | Display/legal name, public contact, address, logo, colors | `business.settings.manage`; identity fields only, no account email/role change. |
| Locale and time | `en-NG`, IANA timezone, operational day boundary, week start | `business.settings.manage`; immutable event/plan snapshots remain. |
| Currency | NGN, 2 minor digits | Displayed configuration; no alternative currency initially; hard-lock after dependent records/history. |
| Collection methods/mappings | Cash/transfer/POS/approved Other, custody account, evidence/reference policy | `business.settings.manage` selects approved existing mapping; Module 07 enforces use. |
| Operational collection limits | Receipt max, late lookback, day/batch behavior | `business.settings.manage` within product caps; period close remains separately gated. |
| Withdrawal method registry | Cash/bank transfer readiness, executor/funding/destination/evidence/finality/limits | `business.settings.manage` may enable only owner-certified versions; no payout authority created. |
| Notifications | Business sender identity reference, supported global channels, locale/contact, optional policy defaults | Settings integrates; Module 13 owns events, templates, recipients, mandatory rules and delivery. |
| Dashboard/report defaults | Week/default ranges, page size, default export format | Presentation defaults only; Modules 11/12 own metrics, cutoffs, security limits and export permission. |
| Feature enablement | Closed feature code, readiness, activation/disable state | `business.settings.manage` within dependency and stranding gates; never role permission. |

### 5.2 Ownership prohibitions

- Fee settings screen links to Module 05 and requires `fees.manage`; no fee amount/rate/timing is stored or published here.
- Permissions screen links to Module 03 and requires `admins.manage`; no role/grant field is accepted in a configuration payload.
- Authentication/security settings are read-only links to Authentication/security operations; business configuration cannot reduce password/MFA/session/recovery protections.
- Account mappings reference an account/mapping version already approved by Module 10. Because no closed permission authorizes account creation/retirement, this module exposes no Create account or generic code field.
- Notification templates are deployed versioned artifacts under Module 13. Settings may preview active safe templates and choose allowed locale/channel defaults but cannot edit subject/body/variables/recipients.
- Dashboard/report defaults cannot change formulas, statuses, currency arithmetic, cutoff semantics, export hard limits, redaction or `reports.export` checks.

## 6. Business identity, contact, and branding

### 6.1 Fields and validation

| Field | Requirement |
| --- | --- |
| Display name | Required Unicode plain text, trimmed 1–150 characters. Used in current navigation/new communications and artifacts; no markup/control characters. |
| Legal name | Optional protected plain text, 1–200 characters; Admin views/approved artifacts only, never inferred from display name. |
| Public business reference | Server-generated immutable proposed `BUS-000001`; never reused and never grants access. |
| Public support email | Optional valid normalized email ≤254 characters, distinct from any Admin login identity; must complete provider/ownership verification before outbound use. |
| Public support phone | Optional normalized international phone; Nigeria +234 default only when country is selected. Format is not ownership verification. |
| Address | Optional plain text, maximum 500 characters. Separate public/private address policy must be approved before external display. |
| Website | Optional absolute HTTPS URL, maximum 500 characters; no credentials, IP-local/javascript/data scheme or unsafe redirect. |
| Logo | Optional JPEG/PNG/WebP ≤2 MB, dimensions 128×128–2,048×2,048; content-decode, malware scan and metadata removal; protected immutable asset version. |
| Brand colors | Optional 6-digit hex foreground/accent choices; preview and contrast checks. Financial/security meaning cannot rely on branding color. |
| Locale | `en-NG` only initially; future locale requires complete approved templates/formats, never automatic machine translation. |

At least display name is required. Public contact fields are independently optional; the product must not expose an Admin's personal login email/phone as fallback. A removed/replaced logo/contact remains in historical artifact/template snapshots where already rendered, subject to retention, and disappears prospectively after effective change.

Business-profile edits do not rename Customer/Agent identities, change sender domains, alter ledger account names, regenerate transaction IDs, rewrite issued statements or resend prior notices. New Module 10 statements/Module 12 exports snapshot the effective business identity under their own contracts.

## 7. Time, calendar, currency, and display defaults

### 7.1 Business timezone

Use a canonical supported IANA timezone name, proposed seed `Africa/Lagos`; reject abbreviations, raw offsets and unknown/deprecated aliases unless normalized by the approved timezone library. UTC storage remains authoritative. Preview shows current local time, next three day boundaries, UTC offsets and affected future jobs.

A timezone change is proposed to take effect only at a future old-timezone operational boundary at least 48 hours after publication. It affects new business-date grouping, new receipts/batches, dashboard/report default boundaries and new plans' timezone snapshot. Existing plans keep their plan timezone; receipts/batches/events keep captured timezone/business date; posted occurrence/commit times and prior reports/statements do not shift. No initial historical timezone migration exists.

### 7.2 Operational day boundary

Version 2 supports only `00:00` local as the operational day boundary. A business date therefore starts at local midnight and ends immediately before the next local midnight under the effective timezone. Preview supplies concrete UTC/local examples, including offset transitions when applicable. Non-midnight boundaries are deferred until the dashboard, reporting, receipt, batch and late-date owners define and certify one shared versioned contract.

The operational boundary controls new receipt business-date defaults, batch-freeze scheduling and operational daily dashboard/report grouping. It does **not** redefine Module 06 plan slot local calendar dates, Authentication expiry durations, immutable received dates, UTC posting order or earlier batch membership. A timezone change uses the minimum 48-hour future midnight boundary and snapshots the effective timezone/configuration version on new receipts and batches.

Modules 11–12 define Today and calendar weeks at local midnight. Configuration validation therefore rejects any non-midnight boundary in Version 2 rather than storing a preview-only value that cannot become effective. A future specification may expand the allowed values only after owner readiness proves dashboards, reports, batch scheduling and late-date rules agree.

A scheduler delayed across the boundary must freeze/recover each intended batch once by configuration version. Publishing a change cannot skip, duplicate or merge a business day. DST ambiguity/nonexistence must be resolved by an approved deterministic temporal-library policy and tested before enabling such a zone/boundary combination.

### 7.3 Week and report/dashboard defaults

- Proposed week start choices Monday (seed) or Sunday; calendar weeks use business timezone/boundary.
- Proposed dashboard default range choices Today, This week, This month; seed Today for collection work and This month for financial trends according to Module 11.
- Proposed report default activity range is This month; explicit query selection always overrides and remains subject to Module 12 limits.
- Default page size choices 25, 50, 100; seed 25. This is presentation only, not a query/export hard limit.
- Proposed default report export format CSV or PDF; seed CSV. It does not create export authority or bypass a report-specific format.
- Date display proposed `DD MMM YYYY`; storage/API remains ISO/UTC. Number locale `en-NG`; financial source remains integer kobo.

These settings cannot change metric formulas, historical grouping snapshots, report cutoffs, issued statement identity or accessible scope. Unsupported saved choice after a product retirement falls back only through a versioned migration preview; never silently reinterpret old exports.

### 7.4 Currency

Initial supported currency is NGN, ISO 4217 code `NGN`, two minor digits, amounts stored as integer kobo. No conversion/exchange-rate behavior exists. With only one supported value, the currency setting is displayed but not editable.

If another currency is introduced later, a change is allowed only during a reviewed empty bootstrap before any Customer invitation fee snapshot, plan, receipt, fee/deduction obligation, reservation, payout, ledger group, statement/report artifact or financial/custody history exists. After the first dependent record, currency is permanently locked for this business. It cannot be “rolled back” or migrated through ordinary `business.settings.manage`; multi-currency requires a new specification/data migration.

## 8. Collection methods, custody mappings, and limits

### 8.1 Collection payment-method registry

Each method version stores immutable method ID/code, display name, method type (Cash, Bank transfer, POS, approved Other), enabled-for-new-receipts state/effective interval, currency, custody/account mapping reference/version, custody owner classification, required reference/evidence policy ID, per-receipt minimum/maximum, late-date policy reference, reconciliation/batch grouping behavior and safe help text.

`business.settings.manage` may select only a currently active approved account/mapping supplied by Module 10 and method policy supplied by Module 07. It cannot type an account number/code or create an account. Mapping compatibility requires expected account class/currency/dimensions: Agent-cash receipt maps to original Agent receivable; direct bank/POS maps to the approved business asset/clearing owner. A mapping cannot turn a fee component into savings or make unremitted Agent cash business custody.

Proposed initial registry:

| Method | Proposed seed state | Required readiness before enablement |
| --- | --- | --- |
| Cash received by Agent | Disabled until Agent-receivable mapping, receipt/batch/reconciliation and cash evidence policy pass | Module 07 posting/reconciliation, account mapping and Agent custody attribution. |
| Bank transfer to business | Disabled until verified destination/mapping/reference/evidence/finality pass | Business bank/clearing mapping and actual-receipt evidence policy. |
| POS | Disabled until terminal/provider clearing mapping/reference/evidence/finality pass | Approved clearing/custody and settlement/reconciliation contract. |
| Other configured method | Unavailable initially | Named reviewed method type and exact mapping/evidence contract; no generic catch-all posting. |

At least one fully ready method must be enabled before collections feature activation. Disabling blocks new receipt previews/commits at effective time. Already Posted receipts retain the captured method/mapping. Draft/uncommitted receipts must refresh. Reconciliation, reversal and recovery for historical/in-flight method records stay available under their owning permissions even when method is disabled.

### 8.2 Receipt and late-recording limits

| Setting | Proposed seed / range | Boundary |
| --- | --- | --- |
| Receipt/tender maximum | 999,999,999,999 kobo; configurable lower positive integer-kobo limit | Cannot exceed Module 07/system hard cap or per-plan residual capacity. |
| Receipt minimum | 1 kobo hard minimum; proposed business minimum ₦1.00 | Cannot permit zero/negative/fractional-kobo values. |
| Late received-date lookback | 30 prior local calendar dates; configurable integer 0–365 | Still subject to authoritative open-period policy, current Agent/assignment and actual-receipt evidence. |
| Future received date | Always prohibited | Not configurable. Advance means allocation to future slot, not future receipt. |
| Unexplained reconciliation tolerance | Zero kobo initially | Not configurable until a reviewed variance/write-off authority exists. |
| Evidence file limits/types | Owner policy only | Not Admin-configurable initially; settings displays readiness/reference. |

Increasing a limit does not bypass plan capacity, fee outstanding amount, account mapping, evidence, period or role checks. Decreasing limits applies to new confirmations after effective time and does not split/reverse prior receipts. Open forms re-preview. A lookback change never restores a previous Agent's assignment or re-dates `recorded_at`.

Financial open/closed-period control is a release gate because no closed permission currently authorizes close/reopen. `business.settings.manage` cannot mark periods closed, reopen them or override Module 07/09 posting restrictions.

## 9. Withdrawal method registry

Each method version stores method ID/type (Cash or Bank transfer initially), enabled-for-new-requests/execution states, currency, verified destination policy, executor service/role contract reference, payout funding/custody account mapping, request/execution evidence policy, provider idempotency/finality/unknown-result/retry/return policy, min/max G/P, operational availability and effective interval.

Enabling requires Module 08 owner certification for every field and an approved existing Module 10 account mapping. A toggle cannot invent the executor or treat `withdrawals.review` as payment authority. Settings stores opaque provider/funding references and readiness results, never bank credentials/API keys. Missing or stale certification makes Enable unavailable.

Proposed seed: both Cash and Bank transfer disabled until their Section 11 gates in Module 08 pass. Disabling is allowed immediately for **new execution starts** when an operational/security risk requires it, with fresh authentication, required reason, impact preview and durable emergency-disable version. It blocks new requests using that method and new executions as defined by the owner. Payout processing, Outcome unknown and already irreversible execution attempts must resolve using their captured registry version; disabling cannot strand or falsify them. Approved — awaiting payout requests receive the applicable block/hold overlay for safe revocation or a new request under another enabled method; destination/method is never edited in place.

Raising/lowering limits applies to new request confirmations. Existing Pending review and Approved — awaiting payout requests preserve confirmed G/P/method but recheck whether owner policy permits execution after a safety change; a breaking change blocks/revokes under Module 08 rather than silently repricing. Posted payouts and their evidence remain immutable.

## 10. Notification, dashboard, and report integration

### 10.1 Notification settings

Module 13 owns event catalogue, required recipients/channels, mandatory versus optional classification, templates/variables/versioning, user preferences, rendering, retries, suppression and delivery states. Business configuration supplies only:

- effective display name/logo/support contact and `en-NG`/timezone formatting context;
- an opaque verified outbound sender/provider identity reference, if provider readiness is approved;
- global availability for supported optional channel categories; and
- safe links to currently active deployed template/channel readiness.

Business settings cannot edit template content, add variables/recipients, suppress mandatory security/lifecycle/financial notices, override a user's permitted preference, create a broadcast, attach reports/statements, enable deferred SMS/WhatsApp/push or fall back to an unapproved channel. Transactional email remains enabled per allowlisted Module 13 event only after verified sender/destination/provider controls. Disabling an optional channel affects future intents; committed mandatory intents follow their owner policy and cannot be discarded by a generic toggle.

Template publication remains deployment-controlled initially. Previous notifications retain rendered template/version/hash and business profile context; a name/logo/contact change does not rewrite/resend them. Sender/domain credential changes happen in the protected provider/secret workflow, and settings receives only a verified reference/state.

### 10.2 Dashboard/report defaults

Modules 11/12 own stable metric codes/formulas, date bases, cutoffs, watermarks, filters, limits, privacy/redaction, export formats and permissions. Settings may select only an allowed default period/week/page size/export format and branding context. It cannot add a metric, reinterpret “today,” hide liabilities, combine cash/earnings, increase export hard caps, lengthen protected-link validity, change artifact retention, bypass `reports.export` or relabel a report as an issued statement.

Default changes apply on a viewer's next new session/query unless the viewer has a permitted explicit selection. Saved URLs/jobs/artifacts retain their filter/timezone/config/version. A new business default never rewrites an existing report job, export manifest, dashboard snapshot or Issued statement.

## 11. Feature catalogue and dependency readiness

Feature entries use closed stable codes, description, owning module, available/enabled state, dependency/readiness checks with versions/timestamps, effective interval, disable behavior, stranding gates and rollout notes. Initial examples: Customer registration, plan creation, collections by method, reconciliation, withdrawal request/review, payout execution by method, reversal review/posting, statements/PDF, dashboard sections, report exports and transactional email.

Readiness states proposed: **Unavailable, Ready to enable, Enabled, Degraded, Disabled**. `Degraded` means the owner supplies read/recovery behavior with new mutations blocked or limited; settings does not guess this from error counts. The owner readiness result identifies mandatory dependencies and compatible versions.

Enablement workflow:

1. Load the owner-certified readiness result, compatibility/version, outstanding blockers and impact preview.
2. Validate enabling does not bypass role/permission/security/financial owner gates and all required settings/mappings exist.
3. Publish/schedule the feature version with fresh authentication, reason and confirmation.
4. Critical consumer services acknowledge the effective version before the feature accepts new mutations. Unknown propagation keeps it unavailable.
5. Owner endpoints still validate current readiness at each mutation; a cached Enabled flag is never sufficient.

Disabling blocks new operations prospectively or immediately under documented emergency policy. It preserves reads/history and permits required resolution/recovery of submitted/in-flight/unknown financial work. A feature with live work/obligations cannot be destructively disabled unless its owner supplies an explicit drain/hold/recovery plan. Disabling statements/reports removes new generation, not existing governed history/access. A feature flag cannot conceal a failed acceptance scenario and call the capability released.

## 12. Draft, preview, publication, and rollback workflow

### 12.1 Draft lifecycle

Proposed states: **Draft, Scheduled, Effective, Superseded, Cancelled**. Draft is mutable non-runtime work by authorized Admins. Publication creates immutable Scheduled or immediately Effective version. At most one non-cancelled scheduled version may affect the same setting code/effective instant; overlapping incompatible bundles conflict. Superseded remains readable. Cancelled Scheduled never became runtime state and retains history.

A draft stores ID/version, base published bundle/version, typed patch, creator/last editor, timestamps and preview results. A saved draft is not an entitlement or lock; another Admin may publish a competing version, making the draft stale. Drafts contain no secrets and expire/archive under policy without affecting runtime.

### 12.2 Preview and impact analysis

Preview validates every field/dependency and shows:

- current versus proposed values, sensitivity and effective-time rule;
- workflows/new records affected prospectively;
- immutable records/snapshots that remain unchanged;
- open drafts/forms/jobs requiring refresh/reconfirmation;
- methods/features enabled, disabled, blocked or awaiting propagation;
- schedule/day/timezone examples and potential batch/report boundary effects;
- in-flight/unknown work and recovery behavior;
- dependency readiness versions and missing owner contracts; and
- notifications to be emitted after publication/effectiveness.

Preview is bound to draft hash, base version, dependency versions and generated-at time. It does not alter caches/jobs or send notices. A changed draft/base/dependency invalidates confirmation and requires a new preview.

### 12.3 Publication

1. Admin enters required internal reason 1–500 plain-text characters and, where staff/users are affected, safe operational explanation 1–500 characters.
2. Choose an owner-permitted effective time. Identity/presentation settings may be immediate; timezone changes follow Section 7 and the day boundary remains fixed at midnight; normal financial method/limit changes activate at a future safe boundary; emergency disable follows owner rules.
3. Complete fresh password/MFA and review explicit confirmation.
4. At commit recheck account/session/grant/freshness, draft/base/config version, dependency/readiness/mapping versions, schedule conflicts and stranding gates.
5. Persist immutable version, publication event, effective schedule, canonical audit and notification/cache-invalidation intent atomically. No dependent financial record is created.
6. Activation coordinator applies at effective instant, verifies critical acknowledgements/readiness and marks effective state. A failed activation stays blocked/degraded with previous safe version where compatible; never half-enable a financial method.

Unknown publication outcome is resolved by the same attempt ID. UI cannot submit another version until authoritative result is known. Runtime reports the effective version, not merely “Saved.”

### 12.4 Rollback and migration

Rollback selects values from a prior version into a new draft, validates them against current schemas/dependencies/hard locks, previews and publishes a new higher version. It never reactivates invalid credentials/mappings, restores retired accounts, unlocks currency, undoes historical effects or deletes intervening versions.

Prospective changes do not migrate historical records. Any future migration of open plans, pending requests, account mappings, timezones/dates or stored artifacts needs a separate owner-defined migration with authority, preflight, per-record outcome, reversibility and audit. `business.settings.manage` by itself does not authorize a financial/history migration. Unsupported migration leaves old records on captured versions while new records use the new version.

## 13. Validation and safe seed/bootstrap

All validation runs server-side with client feedback. Trim text; reject control/executable markup, unexpected/protected fields, unknown codes, invalid Unicode/URL/file content, non-integer money/minutes, arithmetic overflow, incompatible currency/account class, invalid IANA zones/times, effective past times, overlapping schedules, dependency version mismatch and unsafe destination/provider reference. Reject the complete publication; never silently omit invalid fields.

Proposed seed/bootstrap:

| Setting | Seed |
| --- | --- |
| Business record / first Admin | Provisioned trusted data; no public setup route. First Admin still follows Authentication onboarding. |
| Display name | Required seed value reviewed before user-facing launch. |
| Currency | NGN, 2 minor digits, displayed/locked under Section 7.4. |
| Locale/timezone/day boundary/week | `en-NG`; `Africa/Lagos`; `00:00`; Monday. |
| Dashboard/report/page/export defaults | Owner defaults; current-period views; 25 rows; CSV. |
| Collection methods | Disabled until exact mappings/evidence/ledger/reconciliation readiness. |
| Withdrawal methods | Disabled until executor/destination/evidence/finality/account readiness. |
| Notification channels | In-app available after Module 13; transactional email only for allowlisted events after verified provider/sender. |
| Features | Read-only core profile first; each mutation feature disabled until dependency release checklist passes. |

There is no permissive fallback account/method, `UTC` fallback, zero fee, unlimited amount/lookback, all-feature switch, wildcard permission, default password or silent email sender. A seeded value is authoritative only when it passes its current schema/readiness. Bootstrap completion status lists blockers explicitly.

## 14. Propagation, caches, concurrency, and idempotency

- Runtime configuration reads return setting/bundle version, effective interval and source/readiness status. Critical mutations persist the version they used and recheck effectiveness immediately before commit.
- Publish an ordered durable configuration event/outbox. Consumers process idempotently by business/version, invalidate scoped caches and acknowledge critical financial/method versions. Events may be replayed without reapplying business actions.
- Cache keys include business, setting group/version and relevant effective time. Permission/scope checks precede cache return. Expired/stale critical financial/method configuration blocks mutations; safe profile display may temporarily show last verified version with explicit stale state.
- Use optimistic concurrency on drafts and published base version. Two Admin publications from the same base cannot both silently win; one conflicts/re-previews. Scheduled and immediate changes serialize by setting/effective interval.
- Bind draft save, publish, cancel-scheduled, emergency-disable and rollback attempts to actor/business/action/normalized payload. Same key/payload returns original result; changed payload conflicts. Timeout/unknown resolves existing attempt.
- Activation and scheduler leases ensure each effective version transitions once despite restart. A missed schedule catches up in order; it never applies a superseded older version after a newer effective one.
- Timezone changes coordinate with the fixed-midnight receipt/batch schedulers so every intended business date is frozen once. Method/feature changes coordinate with in-flight work version locks.
- A dependency becomes unready after enablement: owner marks feature Degraded/Unavailable, blocks new unsafe mutations, preserves recovery/history and emits an operational issue. Settings does not automatically choose another method/account.
- Projection/notification/indexing lag does not roll back an effective version. Required canonical audit/config persistence or critical activation acknowledgement failure prevents claiming successful publication/enablement.

## 15. Screens and user experience

### 15.1 Business settings center

Navigation groups: Business profile, Locale & calendar, Currency, Collection methods & limits, Withdrawal methods, Notifications, Dashboard & reports, Features & readiness, Scheduled changes and Version history. Admin baseline sees effective safe values/readiness as read-only. `business.settings.manage` sees draft/create/preview/publish/cancel/rollback controls. Links to Fees, Admin permissions, Authentication/security, ledger mapping owner and notification templates state their separate required permission/owner.

Each setting shows effective value/version/time, owner, sensitivity, dependency state, prospective/historical behavior and last safe publication summary. Protected identifiers are masked. Do not show provider secrets/account credentials or a generic JSON editor. Disabled controls explain the missing owner/mapping/readiness/hard lock and permitted next step.

Draft editor supports field-level validation, unsaved warning, current-versus-proposed diff, impact preview and explicit schedule. Confirmation highlights financial/method/feature effects and unchanged historical records. Currency lock and unsupported chart creation are read-only with clear rationale; no hidden API accepts them.

### 15.2 Version and readiness views

Limited management history shows version, group, actor name/role, published/effective time, outcome and safe reason summary. Full before/after protected audit requires `audit.view`. Version diff masks sensitive references and does not expose deleted asset bytes or secrets.

Readiness panel distinguishes Disabled by choice, Unavailable dependency, Ready to enable, Enabled, Degraded and propagation pending. It lists owner/version/check time/blockers and recovery impact. A green toggle alone is not acceptance evidence.

### 15.3 Accessibility and states

Support keyboard/focus/error-summary, semantic forms/tables, screen-reader change/diff/status announcements, non-color readiness, responsive reflow/zoom and accessible image crop/alt treatment. Distinguish loading, no draft, validation errors, stale base, dependency unavailable, scheduled, propagation pending, degraded, permission revoked, conflict and unknown publication outcome. Never show unavailable setting/readiness as default/Enabled.

## 16. Notifications and audit

### 16.1 Notifications

| Event | Recipient | Proposed content/channel |
| --- | --- | --- |
| Draft changed | Editor only in UI; no general notice | Draft/version/stale state; not a runtime change. |
| Change published/scheduled/cancelled | Acting Admin receipt; active Admins with `business.settings.manage` in-app | Group, safe before/after summary, actor/effective time/reason, affected features; no secret/mapping credential. |
| Change became Effective/failed/degraded | Settings managers and relevant owner queue | Actual version/state, blocker/recovery, safe impact; distinguish publication from activation. |
| Public identity/contact material change | Active Admins; affected users only if owner communication policy requires | Prospective public detail and effective time; no mass campaign. |
| Method/feature disabled | Settings managers and owning operational staff/queues | New-operation block, in-flight behavior and next step; Customers only through affected workflow events. |
| Currency/hard-lock attempt or high-risk conflict | Acting Admin; security/owner queue only when policy indicates abuse | Safe denial category, no hidden account/provider detail. |

Module 13 renders/routes messages after durable config events, deduplicates event/recipient/channel and rechecks permission. No attachments, raw diffs, credentials or public settings-management links. Delivery failure does not repeat/cancel a configuration commit, extend a schedule or bypass an unavailable feature.

### 16.2 Audit

Canonically audit settings read of protected data where policy requires; draft create/edit/discard; preview; fresh-auth publication attempt/result; schedule/cancel/effect/failure; emergency disable; rollback draft/publication; readiness result/acknowledgement; cache propagation issue; validation/dependency/conflict/idempotency denial; protected asset/reference access; backup/restore and unauthorized access.

Each event records event/config/draft/version IDs; actor/service/role/required permission; business; group/setting codes; safe before/after hashes and protected-value references; base/dependency/readiness versions; published/effective UTC/business time; action/result/reason; fresh-auth evidence reference (never credentials); attempt/correlation; and notification/propagation state.

Audit is append-only. Detailed before/after/access requires `audit.view`; settings management history is limited. `reports.export` cannot export raw configuration/audit/secrets. Never record passwords, MFA codes, tokens, API/bank credentials, full provider payloads, signed URLs or unnecessary personal data.

## 17. Backup, recovery, retention, and export boundaries

Backups include immutable business/config versions, schedules, drafts under policy, asset references/hashes, readiness acknowledgements, idempotency keys and canonical audit, while secret values remain in their approved separately backed-up secret store. Recovery preserves the single business ID, version order/effective intervals and captured historical references.

Recovery tests restore to a declared cutoff, validate schemas/hashes, ensure exactly one effective version per setting/time, reconcile consumer acknowledgements and keep unsafe features disabled until readiness re-verifies. Restoring configuration cannot replay receipts, resend payouts/notifications, republish exports or regenerate statements. If a referenced secret/account/provider is unavailable after restore, dependent feature is Unavailable/Degraded, never auto-remapped.

Exact draft/config/audit/asset retention, business-profile privacy, residency and post-retention deletion require the approved Audit/Data Governance policy. Published versions referenced by financial/issued artifacts cannot be destructively removed while those records must remain reproducible. Initial scope exposes no delete/purge configuration action.

`reports.export` covers defined business reports, not a raw settings/database/secret backup. No user-downloadable configuration backup exists initially. A future safe configuration export/import needs a distinct schema, permission, secret omission, business binding, preview, conflict and audit policy; importing JSON is not a workaround.

## 18. Non-functional and release requirements

- **Integrity:** effective-version lookup is deterministic by business/code/time; critical changes and audit/outbox commit atomically; no two conflicting effective values.
- **Availability:** proposed p95 safe settings read under 1 second and draft/preview under 2 seconds on reviewed profile; publication durable response under 3 seconds excluding fresh-auth/dependency latency. Never return success before durable publication.
- **Propagation:** proposed critical-consumer acknowledgement within 30 seconds; method/feature stays propagation-pending and mutation-disabled until complete. Display-only cache target may be 60 seconds with version/stale label.
- **Security:** least-privilege read/publish/activation services, encrypted data/assets, CSRF/replay protections, input/file scanning, safe URLs, no secret logs, rate limits and anomaly alerting for high-risk publications.
- **Reliability:** version/event replay, scheduler restart and rollback drills; no skipped/duplicate effective events or financial workflow replay.
- **Accessibility:** WCAG 2.2 AA target for settings/diff/preview/readiness, including keyboard, screen reader, contrast, reflow and error recovery evidence.
- **Observability:** monitor publish/conflict/unknown result, activation delay/failure, stale consumer versions, unready feature use, mapping mismatch, scheduler gaps, unauthorized attempts and backup/recovery integrity without logging sensitive values.

Release is blocked until: the setting catalogue/owners/schemas/hard limits are approved; one-business/bootstrap process is secured; Authentication fresh step-up and Module 03 authorization are enforced; Module 10 approved account catalogue/mapping lookup exists without free-form creation; Modules 07/08 method readiness/finality/recovery contracts pass; timezone/day-boundary libraries and batch transitions are tested; Module 13 channel/template/provider references are approved; Modules 11/12 defaults integrate without formula/security override; immutable audit/outbox, protected assets/secrets, retention/backup/recovery and critical propagation/rollback are contract-tested.

## 19. Worked examples

### 19.1 Timezone change

Admin schedules `Africa/Lagos` to another supported IANA zone at an old-zone boundary more than 48 hours away. Preview shows boundary/UTC examples. Existing plan slots and receipts retain captured zones/dates; new plans/receipts after effective time use the new snapshot. An open receipt form reloads. Prior August report/statement remains unchanged.

### 19.2 Collection mapping change

Bank transfer method currently maps to approved clearing account version A. Admin selects already-approved compatible version B effective next boundary. Receipts committed before use A; later receipts use B. Reconciliation preserves both mappings. If B is retired/unready before effect, activation fails/blocks and A remains safe where compatible; no receipt is posted to a generic fallback.

### 19.3 Receipt limit decrease

Business lowers receipt maximum from ₦1,000,000 to ₦500,000 prospectively. A draft form for ₦750,000 opened earlier must refresh and fails confirmation after effective time. A previously posted ₦750,000 receipt remains valid/history unchanged and is not split or reversed.

### 19.4 Withdrawal method emergency disable

Bank provider becomes unsafe. Authorized Admin fresh-authenticates and immediately disables new bank execution with reason. Pending review and Approved — awaiting payout requests cannot start bank payout and follow Module 08 hold/revocation/new-request rules. A request already in irreversible Payout processing or Outcome unknown resolves using its captured registry/idempotency version; it is never resent as cash or marked failed merely because the toggle changed.

### 19.5 Feature readiness

Collections has UI code deployed but no approved custody mapping. Readiness is Unavailable and Enable is blocked. The Admin cannot toggle it on or enter a generic account. After ledger mapping, evidence, reconciliation and posting contracts pass, owner returns Ready to enable; publication/consumer acknowledgement makes it Enabled.

### 19.6 Rollback

A new logo/default report period is undesirable. Admin selects prior values into a new draft and publishes version 8. Versions 6 and 7 remain; old emails/exports/statements keep their snapshots. Rolling back cannot restore a retired bank mapping or change locked currency.

## 20. Indexed functional requirements

| ID | Requirement | Detail |
| --- | --- | --- |
| CFG-FR-001 | Maintain exactly one trusted seeded business identity and immutable business/public references without public registration/tenant switching. | Sections 1–3 |
| CFG-FR-002 | Give active Admins safe baseline reads and require current `business.settings.manage` for every configuration mutation. | Section 4 |
| CFG-FR-003 | Require proposed fresh password/MFA, reason, confirmation and current permission/version/dependency checks for publication/cancel/disable/rollback. | Sections 4.2, 12 |
| CFG-FR-004 | Enforce settings ownership boundaries for fees, permissions, authentication/security, accounts, notifications, reports and financial workflows. | Sections 2.2, 5.2 |
| CFG-FR-005 | Validate/version business identity/contact/branding while preserving historical artifact snapshots and login identities. | Section 6 |
| CFG-FR-006 | Validate IANA timezone and apply future prospective change without moving historical event/plan dates. | Section 7.1 |
| CFG-FR-007 | Enforce the Version 2 midnight operational day boundary on receipts, batches, dashboards and reports without redefining plan slots/UTC/history. | Section 7.2 |
| CFG-FR-008 | Provide week/dashboard/report/display defaults that cannot alter formulas, cutoffs, permissions, hard limits or prior artifacts. | Sections 7.3, 10.2 |
| CFG-FR-009 | Support NGN integer kobo initially and permanently lock business currency after any dependent record/history. | Section 7.4 |
| CFG-FR-010 | Version collection methods and select only compatible approved existing custody/account mappings; prohibit free-form account creation. | Section 8.1 |
| CFG-FR-011 | Disable new method use prospectively while preserving posted history and owning recovery/reconciliation/correction access. | Sections 8.1, 9 |
| CFG-FR-012 | Enforce receipt amount/late-date settings within hard caps and preserve future-date/period/assignment/evidence restrictions. | Section 8.2 |
| CFG-FR-013 | Version withdrawal methods and enable only owner-certified executor/destination/evidence/finality/funding contracts. | Section 9 |
| CFG-FR-014 | Order emergency withdrawal-method disable against in-flight irreversible attempts without fallback/repricing/false result. | Section 9 |
| CFG-FR-015 | Integrate safe business context/channel readiness with Module 13 while prohibiting template/recipient/mandatory-notice/broadcast overrides. | Section 10.1 |
| CFG-FR-016 | Maintain a closed versioned feature/readiness catalogue and prohibit enablement before compatible dependencies/propagation. | Section 11 |
| CFG-FR-017 | Disable/degrade features without deleting history or stranding submitted/in-flight/unknown obligations and recovery. | Section 11 |
| CFG-FR-018 | Maintain Draft/Scheduled/Effective/Superseded/Cancelled lifecycle with immutable versions and conflict-safe schedules. | Section 12.1 |
| CFG-FR-019 | Bind impact preview to draft/base/dependency versions and explain prospective, immutable, in-flight and notification effects. | Section 12.2 |
| CFG-FR-020 | Publish configuration/audit/schedule/outbox atomically and claim effectiveness only after owner-safe activation/acknowledgement. | Section 12.3 |
| CFG-FR-021 | Implement rollback as validated new version and require separate owner-authorized migrations for historical/open records. | Section 12.4 |
| CFG-FR-022 | Enforce server-side typed validation and safe non-permissive seed/bootstrap defaults/readiness. | Section 13 |
| CFG-FR-023 | Propagate ordered version events, key/invalidate caches and fail critical stale settings closed. | Section 14 |
| CFG-FR-024 | Serialize concurrent drafts/publications/schedules and idempotently resolve retries/restarts/unknown outcomes. | Section 14 |
| CFG-FR-025 | Provide accessible role-sensitive settings/diff/readiness/history screens with masked protected references and explicit states. | Section 15 |
| CFG-FR-026 | Send deduplicated scope-checked configuration/readiness notices after commit without secrets or mutation replay. | Section 16.1 |
| CFG-FR-027 | Durably audit configuration lifecycle/access/propagation with protected values and separate `audit.view`. | Section 16.2 |
| CFG-FR-028 | Back up/recover versions and references without replaying business events; prohibit raw config/secret export and destructive purge. | Section 17 |
| CFG-FR-029 | Meet reviewed integrity, performance, propagation, security, reliability, accessibility and observability requirements. | Section 18 |
| CFG-FR-030 | Block release on missing owner/account/method/time/notification/audit/retention/recovery contracts rather than enabling unsafe defaults. | Sections 11, 18 |

## 21. Acceptance scenarios and traceability

These are future release scenarios, not claims of implementation or completed testing. Each evidence record includes scenario/requirement IDs, build/fixture, actor/account/grants/freshness, business/config/draft/base/dependency versions, typed before/proposed/effective values, effective timezone/time, expected/observed consumer/runtime/history states, audit/notification/propagation references, and Passed/Failed/Blocked. A visible toggle or successful draft save is not evidence a feature safely activated.

| ID | Requirements | Scenario and expected result |
| --- | --- | --- |
| CFG-AC-001 | CFG-FR-001 | Seed one business/first Admin; public second-business creation, tenant ID injection/switch and business ID edit/delete all fail without affecting scope. |
| CFG-AC-002 | CFG-FR-002 | Customer/Agent see only relevant public/applicable values; baseline Admin reads safe effective settings but cannot draft/publish; exact grant enables mutation. |
| CFG-AC-003 | CFG-FR-003 | Publish/cancel/disable/rollback with missing/expired freshness, reason, confirmation or revoked grant fails; valid one-Admin action commits once. |
| CFG-AC-004 | CFG-FR-004 | `business.settings.manage` actor attempts fee/permission/MFA/account creation/manual posting/template body/financial approval fields; every owner violation is rejected wholly. |
| CFG-AC-005 | CFG-FR-005 | Validate name/contact/HTTPS/logo/type/size/dimension/color boundaries; invalid asset never replaces current, and no Admin login contact is used as public fallback. |
| CFG-AC-006 | CFG-FR-005 | Change display name/logo/contact; new permitted surfaces use new version while old sent notice/issued statement/export retains its captured identity. |
| CFG-AC-007 | CFG-FR-006 | Schedule valid IANA timezone 48-hour boundary and test month/year/DST offsets; new events snapshot it, old plan/receipt/report dates never shift. |
| CFG-AC-008 | CFG-FR-006 | Reject raw offset/abbreviation/unknown zone/past or too-soon effect and unavailable temporal dependency without falling back to UTC/browser zone. |
| CFG-AC-009 | CFG-FR-007 | Keep `00:00` across a timezone change: scheduler freezes every old/new business date exactly once, new receipts/batches capture the version, and plan slots/UTC/history remain unchanged. |
| CFG-AC-010 | CFG-FR-007 | Reject every non-midnight boundary in Version 2, including otherwise well-formed 15-minute values; failed publication keeps the prior safe version. |
| CFG-AC-011 | CFG-FR-008 | Change week/default range/page/export choice; only new presentation defaults change, metric formula/query selection/export permission/hard cap/saved job stays. |
| CFG-AC-012 | CFG-FR-009 | NGN renders exact kobo. Attempt another currency before support and after invitation/plan/ledger history; both fail, latter permanently locked with no conversion. |
| CFG-AC-013 | CFG-FR-010 | Map Cash to compatible approved Agent receivable and transfer to approved business clearing; wrong class/currency/retired/free-form account blocks enablement. |
| CFG-AC-014 | CFG-FR-010 | Post before/after compatible mapping change; each receipt retains exact version, reconciliation handles both, no generic fallback/double posting. |
| CFG-AC-015 | CFG-FR-011 | Disable collection method; new forms/commits refresh/block, Posted records/history remain and permitted reconciliation/reversal recovery continues. |
| CFG-AC-016 | CFG-FR-012 | Test receipt min/max/max+1 and lower prospective business cap; invalid values fail, prior above-new-cap receipt is not split/reversed. |
| CFG-AC-017 | CFG-FR-012 | Test late lookback 0/30/365/beyond, future date and closed-period/assignment/evidence blocks; larger setting never bypasses owners. |
| CFG-AC-018 | CFG-FR-013 | Enable bank/cash payout with complete versus missing executor/destination/funding/evidence/finality/unknown/return contracts; only fully certified compatible version is Ready. |
| CFG-AC-019 | CFG-FR-013 | `withdrawals.review` or settings Admin cannot become executor/mark paid merely via registry; secrets/raw bank credentials cannot be stored. |
| CFG-AC-020 | CFG-FR-014 | Emergency-disable bank method racing execution: disable-first blocks start; durable irreversible-start-first resolves exact attempt using captured version, never retries/falls back cash. |
| CFG-AC-021 | CFG-FR-015 | Change branding/sender/channel defaults; previous rendered notices unchanged, mandatory/allowlisted rules/preferences remain, no template edit/broadcast/SMS fallback. |
| CFG-AC-022 | CFG-FR-016 | UI deployed but account/evidence/posting owner missing: feature stays Unavailable and API rejects enable; complete versioned readiness plus acknowledgements enables. |
| CFG-AC-023 | CFG-FR-016 | Cached Enabled flag with owner now unready cannot authorize mutation; owner check marks Degraded/Unavailable and emits issue without selecting substitute. |
| CFG-AC-024 | CFG-FR-017 | Disable feature with pending/processing/unknown/posted work; new action blocks while exact hold/recovery/read/history paths persist and no obligation disappears. |
| CFG-AC-025 | CFG-FR-018 | Save/edit Draft with no runtime effect; schedule then effect once; cancel before effect retains history; immutable Effective edit/delete fails. |
| CFG-AC-026 | CFG-FR-018, CFG-FR-024 | Two Admins publish same base/overlapping setting schedule; one valid ordering wins and stale actor re-previews, with no lost/half-applied value. |
| CFG-AC-027 | CFG-FR-019 | Preview binds diff/hash/base/dependencies and enumerates historical/open/in-flight effects; edit/dependency change invalidates confirmation. |
| CFG-AC-028 | CFG-FR-020 | Inject config/audit/schedule/outbox failure; publication commits none. Inject consumer ack failure; never claim feature Effective/accept mutation while propagation pending. |
| CFG-AC-029 | CFG-FR-020 | Lost publication/activation response resolves same attempt/version after restart without duplicate schedule/notification/effective event. |
| CFG-AC-030 | CFG-FR-021 | Roll back display default through new higher version; old versions retained. Prior retired mapping/locked currency fails current validation and no history rewrites. |
| CFG-AC-031 | CFG-FR-021 | Request timezone/account/open-plan historical migration through ordinary settings; remain Blocked pending separate owner authority/preflight, while records retain captured version. |
| CFG-AC-032 | CFG-FR-022 | Fuzz unknown/protected fields, control markup, invalid URL/file/money/minutes/code/effective times and schema overflow; whole publication fails with safe field errors. |
| CFG-AC-033 | CFG-FR-022 | Fresh seed starts NGN/Africa-Lagos/00:00/Monday and financial methods/features disabled until readiness; no zero/unlimited/fallback account/default password. |
| CFG-AC-034 | CFG-FR-023 | Publish ordered versions with cache lag/replay; critical mutation reads effective acknowledged version or fails closed, profile display labels last verified stale version. |
| CFG-AC-035 | CFG-FR-024 | Duplicate draft/publish/cancel/disable keys, timeout and scheduler restart yield one result/effective transition; changed payload conflicts. |
| CFG-AC-036 | CFG-FR-025 | Baseline/manager screens, direct URLs and masked diffs obey roles; keyboard/mobile/screen-reader handles Draft/Scheduled/pending/degraded/conflict/unknown states. |
| CFG-AC-037 | CFG-FR-026 | Publish/effect/fail/disable notice retries deduplicate/current-scope check and contain safe summary only; delivery failure never repeats/cancels change. |
| CFG-AC-038 | CFG-FR-027 | Draft/preview/publish/effect/rollback/access/denial/propagation events retain protected hashes/versions/results; only `audit.view` sees detail and no secrets appear. |
| CFG-AC-039 | CFG-FR-028 | Backup/restore preserves business/version/effective schedule/references exactly, does not replay money/notifications/exports, and unavailable restored secret leaves feature disabled. |
| CFG-AC-040 | CFG-FR-028 | `reports.export`, guessed backup route and settings manager cannot download raw config/secrets or purge published history; governed artifact cleanup preserves references. |
| CFG-AC-041 | CFG-FR-029 | Representative latency/propagation/load/accessibility/security tests record targets/misses while integrity/authorization remain enforced; no optimistic success. |
| CFG-AC-042 | CFG-FR-030 | Remove each owner/mapping/time/provider/notification/audit/retention/recovery dependency; affected setting/feature reports Blocked, never guessed/default-enabled. |

Required fixtures include baseline/read-only and `business.settings.manage` Admins with fresh/stale/revoked access; one seeded business; valid/invalid branding assets/contacts; timezone/fixed-midnight DST/month/year cases; empty versus currency-locked histories; active/retired/incompatible account mappings; all receipt limits/methods and late dates; withdrawal requests in Pending review, Approved — awaiting payout, Payout processing, Outcome unknown, Payment failed and Posted, with and without applicable hold overlays; notification mandatory/optional/provider/template states; feature readiness states; competing/scheduled/emergency changes; stale caches/consumer acknowledgements; provider/secret loss; backup/restore; malicious input; and representative accessibility/performance conditions.

## 22. Proposed decisions summary

- Seed `en-NG`, `Africa/Lagos`, `00:00`, Monday, NGN/kobo, 25 rows and CSV default; financial methods/features start disabled until readiness.
- Require fresh password/MFA for all publications/cancellations/disables/rollback versions; one authorized Admin is sufficient.
- Schedule timezone changes at a safe midnight boundary at least 48 hours ahead; Version 2 fixes the operational day boundary at 00:00 and defers non-midnight choices.
- Make all changes prospective; snapshots/history remain. Rollback is a new version and ordinary settings never run financial/history migrations.
- Select only pre-approved existing account mappings; account creation/retirement and financial period close/reopen remain gated for lack of established authority.
- Currency is NGN only initially and permanently locked after any dependent record; no conversion.
- Collection receipt maximum may be lowered within the 999,999,999,999-kobo system cap; late lookback defaults 30 and may range 0–365 dates; future receipts and unexplained tolerance remain prohibited/zero.
- Withdrawal methods are disabled until executor/destination/evidence/finality/funding contracts pass; emergency disable blocks new starts but preserves exact in-flight recovery.
- Module 13 owns event/template/recipient/mandatory policy; settings supplies safe verified context and cannot create broadcasts.
