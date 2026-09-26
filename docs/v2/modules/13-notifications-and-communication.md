# Notifications and Communication

**Product version:** 2.0  
**Module:** 13  
**Module status:** Detailed draft for review  
**Sources and dependencies:** [PRD](../../PRD.md), [User Types](./01-user-types.md), [Authentication](./02-authentication.md), [Roles and Permissions](./03-roles-and-permissions.md), [Customer and Agent Management](./04-customer-and-agent-management.md), [Fees and Deductions](./05-fees-and-deductions.md), [Thrift Plans and Savings Cycles](./06-thrift-plans-and-savings-cycles.md), [Collections, Digital Thrift Card, and Reconciliation](./07-collections-thrift-card-and-reconciliation.md), [Withdrawals and Payout Approvals](./08-withdrawals-and-payout-approvals.md), [Reversals and Financial Corrections](./09-reversals-and-financial-corrections.md), [Transaction Ledger, Balances, and Statements](./10-transaction-ledger-balances-and-statements.md), [Dashboard and Operational Analytics](./11-dashboard-and-operational-analytics.md), [Reports and Exports](./12-reports-and-exports.md)

## Approved staged implementation

The in-app foundation is implemented for seven existing source families. Approved release defaults are `en-NG`, configured business timezone, 25/50/100-row pagination, maximum 366-day date windows, verified historical import and 24-month inbox visibility. Existing email and Authentication delivery remain owner-controlled. This does not approve or certify the remaining draft provider, optional preference, full event catalogue, audit or recovery policies. See the [implementation checkpoint and acceptance evidence](../implementation_plan/13-notifications-and-communication.md).

## 1. Purpose and specification status

This module defines the common notification event, recipient, template, in-app inbox and channel-delivery system. It makes committed business outcomes visible without duplicating the underlying action, leaking private data, or treating message delivery as financial finality.

Modules 01–10 establish role/scope boundaries, business events, mandatory security and financial notices, current-assignment routing, post-commit delivery, and delivery independence from business state. Modules 11–12 supply operational alert and report/export-ready events but do not grant this module authority to invent analytics, generate reports or broaden their recipients. This module owns communication routing and delivery; each source module remains authoritative for event validity, state, amounts, approvals and permitted action.

Detailed channel, preference, template, retry, inbox, retention and service targets below are **proposed product decisions** unless already required upstream. They are intended requirements and future acceptance scenarios, not claims of implemented delivery or legal compliance.

## 2. Initial scope, channels, and exclusions

Initial scope provides an authenticated in-app inbox and explicitly allowlisted transactional email. The same durable source event may create different safe channel renderings. Channels are not enabled merely because contact data exists.

| Included initially                                                                                | Deferred or separately owned                                                        |
| ------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------- |
| In-app notifications for all defined eligible event families                                      | Chat, free-form direct messages, discussion threads, Customer-Agent messaging       |
| Transactional email only for events explicitly listed in Section 5 or Authentication              | Marketing email, newsletters, promotions and bulk campaigns                         |
| Per-recipient read/unread state, filters, search, cursor pagination and authorized deep links     | Reactions, replies, forwarding, custom folders and user-created rules               |
| Mandatory security, lifecycle and financial notices plus limited optional operational preferences | Digests, scheduled reminder campaigns, quiet hours and per-event delivery schedules |
| Delivery attempts, provider results, suppression, dead-letter visibility and safe retry           | Guaranteeing that a person read an email; email-open pixels/tracking                |
| One configured business, proposed `en-NG`, NGN and configured business timezone                   | User-authored localization, automated translation and per-message language editing  |

SMS, WhatsApp, browser/mobile push and voice are deferred unless a later reviewed channel contract defines verified destination/consent, templates, provider finality, cost/rate limits, opt-out, privacy, retry and audit. No system may silently fall back to one of those channels after an email failure.

Version 2 has no permission for arbitrary Admin broadcasts. No Admin, including one with `business.settings.manage`, `reports.export` or `audit.view`, can compose or send free-form messages to all users through this module. A future broadcast/campaign feature requires an explicit permission and consent/abuse policy.

## 3. Ownership and core invariants

### 3.1 Ownership boundary

| Owner                   | Responsibility                                                                                                                                                            |
| ----------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Source business module  | Decide whether an event occurred, its immutable ID/version, safe notification facts, audience purpose, mandatory/optional classification and deep-link resource.          |
| Authentication          | Own invitations, activation/verification/reset/email-change/MFA/recovery challenges, credential-sensitive templates, token lifetime and security retry/rate-limit policy. |
| Notifications module    | Validate event contract, resolve/revalidate recipients, render versioned safe content, create inbox records, dispatch allowlisted email and track delivery attempts.      |
| Authorization/Module 04 | Supply current role, account access, Admin permission, Customer assignment and lifecycle scope at dispatch and retrieval.                                                 |
| Reports/Statements      | Generate artifacts and declare ready/failed/superseded state; Notifications never generates, attaches or widens access to them.                                           |
| Audit                   | Retain canonical business/security audit under its own access and retention; inbox history is not the audit log.                                                          |

### 3.2 Invariants

1. A notification never creates, approves, retries, reverses or proves the underlying business or financial action.
2. The business mutation and durable notification intent commit together where the owning module requires a notice. External dispatch occurs afterward.
3. One logical notice is unique by source event, recipient and channel. Purpose/template is bound inside that record; redelivery does not create another business event or inbox item. When one person qualifies through multiple audience reasons, coalesce the permitted content rather than create duplicates.
4. Recipient authorization is evaluated at intent creation, dispatch, retry, inbox query and deep-link opening as applicable.
5. A link or notification ID grants no authority. The destination performs normal current server authorization and state/version checks.
6. Provider acceptance, delivery and human reading are distinct. Unknown provider outcome is never called Delivered or Read.
7. Email failure, suppression or dead-letter state never rolls back a committed mutation, reposts money, releases a reservation or rotates a token.
8. Secrets, raw evidence, internal allegations and cross-scope data never enter a general notification payload, template, URL or provider metadata.
9. User preferences can suppress only explicitly optional communication. Mandatory security, lifecycle and financial notices remain enabled on their defined channels.
10. Inbox data is a derived communication record. Source records and canonical audit remain authoritative after a notice expires or becomes inaccessible.

## 4. Event and notification model

### 4.1 Source event envelope

Every routable event supplies:

- Globally unique event ID, stable event type and schema version.
- Source module, source record ID/version and correlation/operation ID.
- UTC occurred/committed time plus configured business timezone reference where relevant.
- Subject account/Customer/Agent/business reference and current-resource routing key.
- Acting user or trusted system source, represented by safe actor category for recipients.
- Notification category, purpose and mandatory/optional classification.
- Audience descriptors, such as affected account, current assigned Agent, initiating actor, exact Admin permission queue, or report requester.
- Template-safe structured variables with classification labels; never prebuilt untrusted HTML.
- Deep-link route name and opaque resource identifiers, not credentials or bearer tokens.
- Owner policy/template family version, expiry/supersession condition and audit correlation.

The Notifications service rejects unknown event/schema/template combinations, unexpected variables, missing required classifications, invalid resource relationships and unsafe channel requests. It does not guess a recipient, amount, state or generic message. A source-event schema migration is versioned and backward-readable for outstanding intents.

### 4.2 Notification, recipient, and delivery records

One logical notification records notification ID, event/purpose, resolved recipient account, category, importance, mandatory status, rendered in-app template/version/locale, created/effective/expiry times, current visibility state and deep-link descriptor. Read state is separate per recipient and may change without altering the event/content.

Each channel intent records event, notification, recipient, channel, bound purpose(s), destination reference/version, template/version/locale, idempotency key, routing decision, scope decision, preference result, state, attempt count, next attempt and safe failure/suppression category. Each attempt appends provider request/idempotency reference, start/end time, safe response class and provider message reference where returned.

Rendered snapshot/hash is retained for each actual dispatch so later template changes do not rewrite what was sent. Raw provider payloads containing unnecessary personal data are not retained. Destination addresses are encrypted/masked in operational views; full email addresses remain in the owning identity profile, not copied into every log.

### 4.3 Categories and importance

| Category              | Examples                                                                                               | Preference class                                                   |
| --------------------- | ------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------ |
| Security              | Activation, password/MFA/recovery, sign-in/session, email/permission/access changes                    | Mandatory; Authentication policy controls channel                  |
| Account and lifecycle | Customer/Agent status, assignment, suspension, offboarding, unavailable service Agent                  | Mandatory lifecycle                                                |
| Financial             | Posted contribution, fee/deduction/refund, withdrawal state/posting, reversal, statement correction    | Mandatory financial                                                |
| Plan                  | Creation/terms, pause/resume/cancel/complete/close/shortfall                                           | Contractual lifecycle events mandatory; routine progress optional  |
| Operational task      | Approval/reconciliation queues, report readiness/failure, delivery issue, integrity/availability alert | Required for assigned task owner; optional informational summaries |
| Product reminder      | Contribution-due reminder, weekly summary, tips                                                        | Deferred/optional; no initial campaign engine                      |

Importance controls presentation/order, not permission. A source cannot label marketing as Security to bypass preference or consent.

## 5. Event, recipient, and channel catalogue

The matrix lists initial defaults. “Email” means explicitly allowlisted transactional email. In-app is created only for an account recipient and becomes retrievable only with current account access/scope.

### 5.1 Authentication, authorization, and account security

| Event                                                          | Recipients and initial channels                                                                                                   | Required content boundary                                                                                                      |
| -------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| Invitation/activation challenge, resend, expiry                | Invited account: Authentication email; authorized initiator/manager: in-app receipt/issue                                         | Authentication-owned purpose-bound token and immutable disclosed fee snapshot where applicable; no competing activation email. |
| Activation/MFA completion                                      | Account: email and in-app after access; authorized onboarding owner: in-app                                                       | Actual account/onboarding state and next step; activation never implies Agent operational readiness.                           |
| Password reset, email confirmation/change, MFA/recovery change | Account through Authentication-defined verified email/in-app paths                                                                | No password, authenticator secret/code, recovery code or token in general notification storage.                                |
| New/suspicious sign-in, session/trusted-device/security event  | Affected account: Authentication email and in-app where accessible; authorized security queue when specified                      | Safe device/time/location approximation and action link; no session identifier/secret or unsupported allegation.               |
| Account suspension/restoration/deactivation                    | Affected account: email; in-app only when access permits. Existing management/security recipients: in-app                         | Effective outcome and safe next step. Suspended link cannot preserve revoked session.                                          |
| Admin permission grant/revocation or Admin status change       | Target Admin: mandatory email and in-app where accessible; actor: in-app receipt; required management/security recipients: in-app | Permission names, effective time, safe actor/business contact. Internal reason and unrelated grants remain protected.          |
| High-risk authorization denial                                 | Affected actor only when owner policy says actionable; authorized security queue where Module 02/03 requires                      | No target-record existence disclosure or repeated denial spam.                                                                 |

Authentication owns dispatch timing, token redaction and its distinct retry/rate limits. Notifications may transport its approved content but cannot regenerate/inspect secrets or send a new token on generic delivery retry. Resend is a new authorized Authentication event.

### 5.2 Customer, Agent, and assignment lifecycle

| Event                                                          | Recipients and initial channels                                                                                                  | Required content boundary                                                                                        |
| -------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| Registration commit                                            | Creating Agent/Admin: in-app receipt; new account receives Authentication invitation email                                       | Registered, invited and delivery states remain distinct; no duplicate “welcome” email initially.                 |
| Routine profile change                                         | Affected activated user and current assigned Agent where applicable: in-app; security-sensitive identity change uses owner email | Changed field categories and safe actor type, never full old/new address, next-of-kin data or internal note.     |
| Staff name correction proposal/outcome                         | Customer: in-app and email for proposal; requesting/current authorized staff: in-app                                             | Authenticated review/expiry/outcome; email is not one-click approval. Former Agent loses proposal detail.        |
| Customer status/archive/restore                                | Customer: mandatory in-app where accessible and email; current Agent/actor: in-app                                               | Effective state/time, Customer-facing explanation/consequence and next step; omit internal reason.               |
| Customer reassignment                                          | Customer: mandatory in-app/email; replacement Agent: in-app; former Agent: minimal own-access removal receipt; actor: in-app     | Replacement business contact and effective time. Former notice has no Customer detail/link/financial data.       |
| Agent readiness/inactivity/suspension/offboarding/reactivation | Agent: mandatory email and in-app where accessible; Admins with `agents.manage`: in-app                                          | Separate account/operational state, effective time and safe next step; omit personnel/security/cash allegations. |
| Assigned Agent unavailable/service restored                    | Affected non-archived Customers: minimal mandatory email/in-app; eligible `agents.manage`/`customers.reassign` queues: in-app    | Service interruption/contact action only; no Agent private reason. Deduplicate until meaningful state change.    |
| Internal-note/reference/case-owner change                      | Actor and eligible new owner/management queue: in-app only                                                                       | No Customer/Agent self-service disclosure of private note contents.                                              |

### 5.3 Plans, collections, fees, and reconciliation

| Event                                                                                      | Recipients and initial channels                                                                                                                          | Required content boundary                                                                                                                   |
| ------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| Plan creation/renewal/material terms amendment                                             | Customer: mandatory in-app/email; current Agent/actor: in-app                                                                                            | Agreed amount, schedule/date, fee summary, state and reference; no claim that money was collected.                                          |
| Plan pause/resume/cancel/complete/close/early termination/correction shortfall             | Customer: mandatory in-app; email for lifecycle state change except routine progress; current Agent: in-app                                              | Effective state, safe reason/consequence and next action; no payout/fee-approval promise.                                                   |
| Contribution or mixed savings/fee receipt posted                                           | Customer: mandatory in-app/email; recording/current Agent: in-app receipt/work item as applicable                                                        | Receipt reference, received/posted date, gross savings, separate fee component, method mask and resulting savings as-of. No raw evidence.   |
| Missed/skipped/partial/advance card update                                                 | Customer/current Agent: in-app only when owner emits meaningful event; routine daily reminder deferred/optional                                          | Nonfinancial status and residual; never describe missed money as debt or reduce balance.                                                    |
| Fee assessment/application/external settlement/waiver/correction/refund or other deduction | Customer: mandatory in-app/email when obligation or savings/entitlement changes; actor/current Agent: in-app as owner permits                            | Fee/deduction type, amount, source, outstanding/settled effect and safe explanation. Keep external fee receipt separate from savings debit. |
| Fee-earnings business draw                                                                 | Acting/reviewing authorized staff: in-app receipt; no Customer                                                                                           | Business-only amount/reference; never presented as Customer withdrawal.                                                                     |
| Reconciliation batch ready/exception/remittance/result                                     | Original Agent: scoped in-app own status; Admins with `reconciliation.manage`: in-app task; Customer only if owning correction changes their transaction | Batch/amount/status within scope. No other Customer data, raw settlement evidence or allegation in Agent notice.                            |

### 5.4 Withdrawals, reversals, statements, dashboards, and reports

| Event                                                                 | Recipients and initial channels                                                                                                                      | Required content boundary                                                                                                                                                                                                                         |
| --------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Withdrawal submitted                                                  | Customer/current Agent: mandatory in-app; proposed Customer email; `withdrawals.review` queue: in-app                                                | G/F/D/P, plan, method mask, reservation/expiry and Pending review; no approval/payment promise.                                                                                                                                                   |
| Withdrawal approved/rejected/cancelled/expired or hold applied/lifted | Customer/current Agent: mandatory in-app/email; reviewer/queue receipt: in-app                                                                       | Actual primary state and hold status, safe reason, reservation consequence and next step; omit internal review reason.                                                                                                                            |
| Withdrawal processing/failed/outcome unknown                          | Customer/current Agent: mandatory in-app; email only for material action-required/final failure under Module 08; authorized operations queue: in-app | Distinguish attempted, unknown, failed and posted. Never call provider acceptance payment success.                                                                                                                                                |
| Withdrawal posted                                                     | Customer/current Agent: mandatory in-app/email; relevant actor/reviewer receipt: in-app                                                              | Receipt, G/F/D/P, method mask, occurrence/post date and resulting balances/as-of.                                                                                                                                                                 |
| Reversal submitted/rejected/cancelled/blocked                         | Requester/current Agent and review queue: in-app; Customer only when an earlier error notice needs safe progress                                     | Original/request reference, workflow state and safe next step. Original remains effective until posting.                                                                                                                                          |
| Reversal Approved and posted                                          | Customer/current Agent and reviewer: mandatory in-app; Customer email                                                                                | Original/correction references, full net effect, actual posting date and Customer-facing explanation; no physical-return claim without evidence.                                                                                                  |
| Statement generated/failed                                            | Requester: in-app only; Customer also when another authorized actor requested an issued statement                                                    | Period, statement reference, cutoff/status and authorized view link; no statement attachment.                                                                                                                                                     |
| Statement superseded/corrected                                        | Customer and requester: mandatory in-app; Customer email proposed for material financial correction                                                  | Old/new references and cutoff/correction explanation; never silently replace old artifact or claim Final.                                                                                                                                         |
| Dashboard operational alert                                           | Currently scoped affected user or exact permission queue: in-app only                                                                                | Metric/as-of and action link from Module 11. A notification cannot convert analytics into financial truth.                                                                                                                                        |
| Report/export queued/ready/failed/cancelled/nearing expiry/expired    | Requester only if still authorized: in-app                                                                                                           | Job/reference, filters/scope/cutoff, status and authorized job-centre route. At most one proposed approaching-expiry reminder. No file attachment, signed/public URL or recipient expansion; delivery never extends seven-day artifact retention. |

Report/statement emails are not enabled initially. A later “ready” email must be separately approved and still contain no attachment or bearer download URL.

## 6. Recipient resolution and changing scope

### 6.1 Audience rules

- **Affected account** resolves to that exact account only. A contact email shared as an allowed profile value never grants another account the notice.
- **Current assigned Agent** resolves using the effective Customer assignment when dispatch/retrieval occurs. Historical creator/collector attribution does not confer current Customer-notification access.
- **Initiator/actor receipt** resolves to the actor about their own committed action and contains only data they may retain after later scope loss.
- **Admin permission audience** requires an Active Admin who currently holds the exact permission, has usable access and is not blocked by an applicable restriction. Baseline Admin read access alone does not subscribe them to protected tasks/security details.
- **Shared work queue** may expose an event through the owning module to any current authorized reviewer without emailing every Admin. Individual inbox/email delivery requires an explicitly resolved recipient intent.
- **Customer self** retains their own financial/lifecycle notices subject to account access; Customer status does not transfer ownership to another Customer or next of kin.

Recipient lists are derived server-side. Event producers cannot provide arbitrary email addresses or account IDs outside their declared audience schema.

If the actor is also the current assigned Agent, their success receipt satisfies the same event's Agent notification. If an Admin qualifies through several permission/audience paths, create one channel item containing only the union of fields permitted by the selected safe template. Never use multiple audience matches to reveal a field that none permits individually.

### 6.2 Reassignment, permission, and account changes

Before each in-app query, email attempt/retry and deep-link open, re-check account state, exact permission and resource scope. When a Customer is reassigned before dispatch, suppress the former Agent's Customer-content intent and route unresolved current-service work to the replacement with a new recipient-bound intent under the same source event/purpose. The former Agent receives only the explicit minimal removal receipt.

Previously delivered email cannot be revoked; this is why Customer-linked Agent email is generally not allowlisted. Previously delivered in-app content becomes inaccessible when scope ends. Cached clients clear the item/body after authorization refresh. Notification history must not become an archive through which a former Agent can rediscover Customer data.

Permission revocation, suspension or deactivation suppresses future protected delivery and retrieval. A newly granted Admin does not receive old email retrospectively; relevant unresolved tasks remain discoverable in the owning authorized queue. Restored access may reveal the user's still-retained own notices, subject to expiry and current scope; it never revives a suppressed former-assignment notice.

### 6.3 Invited, suspended, and deactivated recipients

An Invited account has no in-app access. The system may create retained in-app intents for that account, available only after valid activation and current-scope checks, but must not imply the notice activated access. Explicit allowlisted email may reach its recorded address: Authentication invitation/security content, and safe Customer financial/lifecycle receipts required by Sections 5.2–5.4. It contains no statements, raw transaction history, evidence or privileged links.

Suspended/Deactivated accounts cannot retrieve the inbox. They receive only mandatory allowlisted email addressed to their own access/lifecycle/security event or an owner-required Customer financial record, subject to destination safety. No message restores a session. A permanently invalid/unsafe email produces a delivery issue for the authorized owner; the system does not send to next of kin or another role as fallback.

## 7. Templates, localization, and content safety

### 7.1 Template contract

Every template has immutable template ID/version, event/schema range, channel, category/purpose, mandatory class, locale, subject/title, required/optional typed variables, maximum rendered lengths, CTA route, retention class and approval state. Publishing a change creates a version and affects future renderings only. Previously rendered/sent content remains linked to its original version/hash.

Templates are code/configuration artifacts released through reviewed deployment in initial scope. There is no Admin template editor or free-form override. Unknown/missing template or variable classification fails delivery safely and creates an authorized issue; it never falls back to dumping the raw event.

Render server-side with context-appropriate escaping and safe allowlisted markup. Plain-text user-entered names/reasons/descriptions are length-limited and escaped. Do not permit script, remote tracking content, embedded forms, executable attachment, credential collection or a CTA that performs the protected mutation directly.

### 7.2 Locale, currency, dates, and names

Proposed initial locale is `en-NG`, currency display NGN/₦ with exact two-decimal formatting, and dates/times labelled in the configured business timezone where business dates matter. UTC/source timestamps remain retained. If a future account locale exists, resolve an approved exact template version; otherwise use the business default. Never machine-translate financial/security text implicitly.

Names use current permitted display value at render unless the event must preserve a historical identity snapshot. Financial values, transaction/request states and references come from event snapshots/versioned owner reads, never a dashboard cache. Template rendering cannot recalculate balances or fee arithmetic.

### 7.3 Privacy and prohibited content

Never include passwords/hashes, activation/reset/email-change token values in general records, MFA seeds/codes, recovery codes, session/cookie/bearer values, full bank/card/payment credentials, raw identity/recovery/payment evidence, private notes, internal review/security/personnel reasons, unrelated statement rows, another Customer's data, or unrestricted Agent/business totals.

Authentication may send a purpose-bound challenge using its dedicated secret-handling path; the general event/inbox stores only a redacted reference and status. Email subjects avoid money amounts, phone/email/address, security allegations and detailed identifiers. Financial email bodies may contain the recipient's safe amount, reference, state, masked method and as-of balance only where the catalogue allows it. Attachments are prohibited initially.

Customer-facing reasons are distinct from internal reasons. If no approved Customer explanation exists, use a neutral template and business contact route rather than copying an internal field.

## 8. Notification preferences

### 8.1 Mandatory notices

Users cannot opt out of the channels explicitly required for:

- Authentication/security challenges and material account-security changes under Module 02.
- Account suspension/deactivation/restoration, permission changes and Customer reassignment/status changes.
- Posted Customer contributions, savings-funded fees/deductions/refunds, posted withdrawals and posted reversals.
- Material plan agreement/lifecycle changes and statement correction/supersession.
- Action-required financial outcome unknown/failure where the owning module requires contact.

Mandatory does not mean every channel: it preserves the in-app and/or allowlisted email route in Section 5. An invalid address cannot be bypassed by pretending success; surface a delivery issue.

### 8.2 Optional operational notices

Initial optional settings may control only approved in-app informational notices: routine plan progress, non-actionable dashboard summaries and future due reminders when implemented. Proposed defaults are on for action-relevant operational in-app notices and off for deferred reminder campaigns. Email remains off unless the event is explicitly allowlisted; there is no “all emails” switch.

Preference changes apply prospectively, store user/category/channel/version/time, and are auditable as account settings without exposing notification content. They cannot suppress an already created mandatory notice, an owning work queue, source-record state or audit. A preference UI must explain mandatory categories and not present a disabled checkbox as user consent.

Digest frequency, quiet hours, temporary mute, marketing consent, SMS/WhatsApp/push consent and inherited organization preferences are deferred.

## 9. In-app inbox

### 9.1 List and unread state

The inbox shows the authenticated user's currently authorized notifications. Default order is newest effective/created time first with notification ID as stable tie-breaker; proposed page size 25 with 25/50/100 choices and opaque cursor pagination bound to account, filters, scope version and cutoff. Unread count and list are computed after current visibility/scope rules.

Rows show accessible category/importance, title, safe summary, event time, unread/read state and optional action-required/expired/superseded indicator. Status uses text/icons as well as color. Money and business references appear only when appropriate for that recipient.

Supported filters: All, Unread, category, action required, date range and current visible status. Search is limited to the recipient's rendered safe title/summary and permitted reference; it never queries raw event payloads or another user's data. Proposed interactive date range maximum is 366 days; older retained notices remain reachable by cursor/date windows subject to policy.

### 9.2 Read actions and immutability

Opening or explicitly marking a notice Read appends/updates only that recipient's read state/time. Provide Mark unread and Mark current filtered page read; proposed initial scope does not provide destructive delete, arbitrary archive folders or business-wide “mark all users read.” Repeated operations are idempotent and version-safe. Email delivery/open state never marks in-app Read automatically.

Notification content, source event, category, actor and recipient cannot be edited. A source correction/supersession creates a linked new notice and visibly marks the earlier one superseded when applicable. Read/unread does not acknowledge a legal agreement, approve an action or prove the user understood it.

### 9.3 Deep links and states

Deep links use approved internal route names and opaque resource IDs. They contain no secret or serialized permission. At open, the application authenticates, validates safe redirect/resume rules, rechecks current role/permission/assignment/status and loads authoritative current state. If the action is complete, show current read-only outcome. If access is lost, show generic unavailable and remove inaccessible content from the inbox/cache without confirming hidden record details.

Loading has no fake unread count; authoritative empty says No notifications. Distinguish no filter matches, stale synchronization, temporarily unavailable, expired action, superseded event and scope loss. Refreshing/retrying an inbox read never replays source work or sends email.

## 10. Delivery lifecycle, retries, and failure operations

### 10.1 Intent and attempt states

Proposed channel-intent states:

| State             | Meaning                                                                                                                    |
| ----------------- | -------------------------------------------------------------------------------------------------------------------------- |
| Pending           | Durable intent exists; not yet attempted or awaiting safe retry/reconciliation.                                            |
| Attempting        | One worker owns a leased attempt; lease expiry is recoverable and does not imply failure.                                  |
| Provider accepted | Provider accepted the request and returned a reference; not proof of inbox placement or human reading.                     |
| Delivered         | Reliable provider webhook confirms delivery under the approved provider contract. If unavailable, do not infer this state. |
| Failed            | Transient or permanent failure is known; transient may retry within policy.                                                |
| Suppressed        | Scope/preference/channel/destination/supersession rule intentionally prevented dispatch.                                   |
| Dead-letter       | Bounded attempts/reconciliation exhausted or contract error needs authorized investigation.                                |

In-app creation normally becomes Available after authorization and persistence; Read remains a separate recipient state. Email providers without reliable delivery webhooks stop at Provider accepted. A late webhook appends the actual result and cannot change the source business event.

### 10.2 Outbox, deduplication, and ordering

The source module commits one immutable event and durable outbox intent with the successful mutation. If mandatory local intent cannot be durably recorded, the owning operation follows its defined atomic failure rule; urgent security suspension may commit with its durable local event/outbox even while remote provider/search is down. A remote delivery outage after that commit never rolls back the action.

Workers claim with leases and enforce a database uniqueness key over event, resolved recipient and channel. Bound purpose/template cannot be changed to bypass that key; multiple eligible audience reasons coalesce before creation. Duplicate broker messages, restarts, overlapping workers and source retries return/update the same intent. Provider request idempotency is used where available. Ordering is guaranteed only per recipient and source aggregate where meaningful; stale events render their real effective state and may be suppressed when a newer event explicitly supersedes them.

Never rely solely on “send then mark sent.” Persist the attempt before the external request and reconcile uncertain outcomes by provider idempotency/reference/status where supported. If acceptance is unknown and safe reconciliation is impossible, do not blindly resend a mandatory financial/security email that could duplicate; leave Pending/Dead-letter with uncertainty visible to authorized staff.

### 10.3 Proposed retry policy

For non-Authentication operational email, propose one initial attempt plus at most two automatic transient retries, at approximately 5 and 15 minutes, within a 15-minute window. Apply jitter and provider rate limits. Authentication retains its own stricter challenge/rate-limit/expiry policy. A source owner may define fewer retries for an expiring/superseded event; it cannot define unbounded retry.

Permanent invalid address, policy rejection, scope loss, disabled channel and superseded recipient are not blindly retried. Provider throttling/network errors may retry. Authentication/security token expiry stops delivery of the expired challenge; an authorized resend creates a new Authentication event/token rather than resurrecting it.

A manual retry, where the owning module permits it, reuses the same logical intent/event/template snapshot after rechecking destination, current recipient scope and unresolved state. An authorized contact correction may append a new destination revision to that intent while preserving every old attempt/destination reference; it does not edit prior evidence or create a duplicate inbox notice. It never reruns the mutation, reissues a token, regenerates a statement/report or changes financial state. Materially changed template/content requires a separately authorized owner event rather than rewriting what the old event meant.

### 10.4 Failure and dead-letter visibility

Delivery issues appear in the owning authorized screen: `customers.manage` or current assigned Agent for permitted Customer invitation/contact delivery; `agents.manage` for Agent lifecycle; `customers.reassign` for assignment outcome; exact approval permission for its task notifications; `security.operations.manage` for permitted security context; report requester/`reports.export` for export readiness. This module creates no `notifications.manage` permission.

Views show safe event/reference, recipient mask, channel, current state, last/next attempt, count and safe error category. Raw provider request/response, token and full destination remain hidden. Authorized retry availability follows the owner, not baseline Admin access. Internal service observability may alert maintainers but is not an end-user broadcast/composition capability.

Dead-lettering creates no compensating business action. An owner resolves the underlying destination/scope through its authorized workflow, suppresses an obsolete intent, or triggers the specific allowed resend/new event. Financial/customer records continue to show their committed state and delivery issue separately.

## 11. Concurrency, idempotency, and failure handling

- Event ID and schema/source version are immutable. Same event ID with different source/type/payload is rejected and audited.
- Recipient resolution/rerouting uses current assignment/permission versions. Competing reassignment and dispatch serialize or re-check so the former Agent does not receive queued Customer content.
- Preference, permission, account or destination changes racing dispatch use a final pre-send check. If the external request already began, retain truthful uncertain/accepted result; do not claim suppression retroactively.
- Template publication and locale changes do not change an already attempted intent. A never-attempted intent uses the template version bound by owner policy; changing it requires deterministic migration/suppression evidence.
- Read/unread updates are per-recipient and cannot overwrite content/scope. Concurrent Mark read/unread resolves through expected version or latest explicit action with audit where required.
- Provider webhooks authenticate source, validate message reference, deduplicate, tolerate out-of-order status and never downgrade a confirmed Delivered state based on an older callback.
- A queue/index/cache outage leaves durable intents/inbox records recoverable. Rebuild from source events preserves notification IDs/dedupe/read state and never sends historical email unless a still-valid pending intent explicitly requires it.
- Failed, denied, stale and no-op business commands do not emit success notices. Security-relevant denied attempts may create their own distinct safe security event.

## 12. Privacy, retention, and user access

Only the addressed recipient may access their in-app notice, subject to current resource scope. Admin baseline read does not grant another user's inbox. Authorized staff see delivery issue metadata only through the owning workflow, not the recipient's full inbox/read behavior. `audit.view` permits canonical audit search under its own policy, not browsing private inbox content or changing delivery.

Proposed retention pending approved Data Retention policy: in-app notices, read state and rendered recipient-visible transactional content remain visible for 24 months or a longer applicable source-record requirement. Channel-intent and delivery-attempt diagnostic metadata expires one year after final resolution, matching Module 14, unless it is unresolved, under hold, or is material evidence that inherits a longer parent class. Short-lived report/download links expire according to Module 12 while the notice remains as a non-working historical status. Authentication token material follows its shorter owner lifetime and is never retained here.

Expiry hides/removes normal inbox availability but does not delete the source business transaction, issued statement metadata, canonical audit or required financial record. Email already delivered cannot be recalled. Data export/deletion requests must preserve required legal/financial/security history with approved masking; no user-facing “delete notification” exists initially.

Users can view their current preference values and own delivery status at a safe category/channel level. They cannot inspect provider internals, other recipients, hidden audience membership or another user's read state. Contact-data correction follows Modules 02/04 and does not rewrite historical destination evidence.

## 13. Screens and accessibility

| Surface                        | Requirements                                                                                                                             |
| ------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------- |
| Notification centre            | Inbox list/unread badge, Section 9 filters/search/pagination, category/state text, read controls and current authorized deep link.       |
| Notification detail            | Safe immutable event snapshot, time/channel status, current action availability and supersession link; no internal payload/debug fields. |
| Preferences                    | Mandatory categories explained/read-only; optional supported category/channel toggles, last update and immediate prospective effect.     |
| Source workflow delivery panel | Permission-scoped safe recipient mask/status/attempts and owner-authorized retry/suppress resolution; distinct from business outcome.    |
| Admin/task queues              | Current exact-permission work from owning module; notice opens the queue but does not approve from the inbox.                            |

Unread badge/list/detail must agree at a common cutoff or visibly refresh. Mobile layouts retain title/category/time/unread/action without essential horizontal scrolling. Use semantic headings/lists/buttons, keyboard-accessible filters/menus/dialogs, visible focus, descriptive links, live-region announcements for count/result/error, and text/icons beyond color. Do not auto-mark Read merely because a screen reader announced a list row; open/explicit action governs read state.

Email uses meaningful subject/preheader, logical headings, readable plain-text alternative, descriptive CTA, sufficient contrast and no information available only in an image. Content remains understandable without remote images. Localization and dynamic amounts/dates are screen-reader friendly.

## 14. Audit and observability

Audit event/intent creation, recipient-resolution outcome, template/version/locale, preference decision, dispatch attempts/results, uncertainty reconciliation, suppression/dead-letter/manual retry, webhook transitions, inbox access denial, preference changes and privileged delivery-issue access. Store actor/system, source event/reference, recipient account reference, channel, purpose, state transition, safe reason, timestamps, versions and correlation IDs. Do not place rendered secrets, full destination, raw provider payload, evidence or cross-scope content in audit.

Reading ordinary inbox items need not create a high-volume canonical audit event, but read-state changes and sensitive artifact access follow the owning policy. Detailed audit requires `audit.view`; a delivery operator view uses the underlying workflow permission and safe metadata only. Audit access does not send, suppress or retry.

Operational metrics include outbox age/backlog, time-to-first-attempt, intent states by channel/event, retry/dead-letter rates, provider latency/status, webhook lag/duplicates, scope suppression, template/schema rejection, inbox query latency and unread-count drift. Logs/metrics use safe category/reference hashes and no message bodies, amounts tied to identity, secrets or raw addresses. Alerts route to internal service operations; they do not create Admin broadcast authority.

## 15. Performance, availability, and recovery

Proposed service objectives on a documented representative dataset/device/network:

- Unread count within one second at p95 and first inbox page within two seconds at p95.
- In-app notification available within 10 seconds at p95 after the committed source event/outbox becomes dispatchable.
- Non-Authentication email first attempt within one minute at p95, excluding an owner-defined scheduled delay and provider outage.
- Inbox search/filter page within two seconds at p95 for the supported 24-month per-recipient volume.
- Delivery status webhook reflected within one minute at p95 after verified receipt.

These are proposed objectives, not permission to acknowledge a mutation before durability or call an email delivered early. Define dataset, concurrency, provider test mode, regions/network and measurement method before release.

Backups/recovery include events/outbox, recipient intents, read states, preferences, template versions/hashes, attempt/provider references, suppression/dead-letter states and audit correlations. Recovery verifies no lost mandatory intent, duplicate logical notice or unauthorized historical resend. Point-in-time restore reconciles source events and provider references before workers resume. Define/test recovery point and time objectives before production.

Use encrypted transport/storage, least-privilege service/provider credentials, credential rotation, authenticated webhooks, CSRF protection for preferences/read changes, rate limits for inbox/search/retry, malware-free no-attachment policy and masked logs/errors. Provider/configuration outages degrade delivery status but must not expose messages or broaden recipients.

## 16. Release and owner gates

Before release, approve and verify:

- Versioned event catalogue, payload classification and exact source ownership for Modules 02–12.
- Per-event mandatory/optional category, audience descriptor and explicit in-app/email allowlist.
- Authentication boundary for tokens, resend, retries, verified addresses and security messages.
- Current assignment/account/permission resolver and atomic handover/suppression contract.
- Template review/publishing, `en-NG` fallback, escaping, money/date formatting and prohibited-data scanning.
- Durable transactional outbox, event/recipient/channel/purpose uniqueness and provider idempotency/reconciliation.
- Approved email provider, destination policy, webhook authentication/finality, retry/rate limit and failure taxonomy.
- Preference storage and proof that mandatory messages cannot be disabled or marketing mislabeled.
- Inbox/read/search/pagination/deep-link authorization and cache invalidation on scope changes.
- Delivery-issue ownership using existing permissions, with no invented broadcast or general notification-admin grant.
- Module 11 operational-alert and Module 12 report/export-ready event schemas; notifications do not generate their data/artifacts.
- Privacy, legal retention/deletion/masking, provider data-location/subprocessor and incident-response policy.
- Accessibility, performance/load, backup/restore and recovery evidence.

Missing owner, event schema, template, recipient rule, verified destination, provider contract or retention/privacy approval leaves that event/channel **Blocked**. The system must not substitute a generic message, guessed recipient, raw payload, different channel or free-form Admin send.

## 17. Indexed functional requirements

| ID         | Requirement                                                                                                           | Detail         |
| ---------- | --------------------------------------------------------------------------------------------------------------------- | -------------- |
| NTF-FR-001 | Provide in-app notifications and only explicitly allowlisted transactional email initially.                           | 2, 5           |
| NTF-FR-002 | Defer SMS/WhatsApp/push and prohibit arbitrary Admin broadcasts without new authority/policy.                         | 2              |
| NTF-FR-003 | Keep notification routing/delivery separate from source business validity and mutation.                               | 1, 3           |
| NTF-FR-004 | Validate immutable versioned source event envelopes and safe audience/template data.                                  | 4.1            |
| NTF-FR-005 | Persist per-recipient logical notifications and channel intent/attempt history.                                       | 4.2            |
| NTF-FR-006 | Classify security, lifecycle, financial, plan and operational events without preference abuse.                        | 4.3            |
| NTF-FR-007 | Route Authentication/security events through Authentication-owned token/channel safeguards.                           | 5.1            |
| NTF-FR-008 | Route Customer/Agent lifecycle and assignment events with minimal private content.                                    | 5.2            |
| NTF-FR-009 | Route plan/collection/fee/reconciliation events with correct financial distinctions.                                  | 5.3            |
| NTF-FR-010 | Route withdrawal/reversal/statement/dashboard/report events without false finality or attachments.                    | 5.4            |
| NTF-FR-011 | Resolve affected-account/current-Agent/actor/exact-permission audiences server-side.                                  | 6.1            |
| NTF-FR-012 | Revalidate and reroute/suppress on reassignment, permission or account changes.                                       | 6.2            |
| NTF-FR-013 | Handle Invited/Suspended/Deactivated recipients without granting app access or unsafe fallback.                       | 6.3            |
| NTF-FR-014 | Use immutable reviewed channel/event/locale template versions and typed variables.                                    | 7.1            |
| NTF-FR-015 | Render safe escaped localized currency/date/name content without recalculating owner facts.                           | 7.1–7.2        |
| NTF-FR-016 | Exclude secrets, evidence, private reasons, credentials and cross-scope data from content/metadata.                   | 7.3            |
| NTF-FR-017 | Keep mandatory security/lifecycle/financial notices outside optional preference suppression.                          | 8.1            |
| NTF-FR-018 | Apply versioned prospective preferences only to approved optional operational notices.                                | 8.2            |
| NTF-FR-019 | Provide a scoped, cursor-paginated, searchable/filterable recipient inbox and unread count.                           | 9.1            |
| NTF-FR-020 | Maintain idempotent per-recipient read/unread state without changing source/content.                                  | 9.2            |
| NTF-FR-021 | Reauthorize every deep link and distinguish empty/stale/unavailable/expired/scope-loss states.                        | 9.3            |
| NTF-FR-022 | Track Pending/Attempting/Accepted/Delivered/Failed/Suppressed/Dead-letter truthfully.                                 | 10.1           |
| NTF-FR-023 | Commit durable intent with source operation and dispatch asynchronously without rollback.                             | 10.2           |
| NTF-FR-024 | Deduplicate by event/recipient/channel and coalesce multiple audience purposes across retries/workers/provider calls. | 3.2, 6.1, 10.2 |
| NTF-FR-025 | Reconcile uncertain provider acceptance and never infer Delivered/Read.                                               | 3.2, 10.1–10.2 |
| NTF-FR-026 | Apply bounded event-appropriate retry/backoff and avoid unsafe permanent/unknown retry.                               | 10.3           |
| NTF-FR-027 | Expose safe delivery/dead-letter operations through existing owning permissions only.                                 | 10.4           |
| NTF-FR-028 | Ensure delivery failure/status never repeats or changes the underlying business mutation.                             | 3.2, 10.4      |
| NTF-FR-029 | Handle assignment/preference/template/webhook/read races with current versions and truthful history.                  | 11             |
| NTF-FR-030 | Rebuild inbox/delivery projections without unauthorized historical resend or lost read state.                         | 11             |
| NTF-FR-031 | Enforce recipient-only inbox access and separate audit/delivery metadata authority.                                   | 12             |
| NTF-FR-032 | Retain/expire communication records under approved privacy policy without deleting sources/audit.                     | 12             |
| NTF-FR-033 | Provide accessible responsive inbox, detail, preferences and delivery panels.                                         | 13             |
| NTF-FR-034 | Keep inbox CTAs informational until the destination independently authorizes the action.                              | 3.2, 9.3, 13   |
| NTF-FR-035 | Audit event/routing/template/preference/delivery/suppression/retry/security outcomes safely.                          | 14             |
| NTF-FR-036 | Monitor delivery/inbox health without logging message bodies/secrets/personal financial data.                         | 14             |
| NTF-FR-037 | Meet reviewed inbox/delivery service objectives without weakening durability/finality.                                | 15             |
| NTF-FR-038 | Back up and recover events/intents/read/preferences/templates/attempts without duplicates or unsafe resend.           | 15             |
| NTF-FR-039 | Protect storage, provider credentials, webhooks, state changes and endpoints with least privilege.                    | 15             |
| NTF-FR-040 | Block an event/channel when owner, schema, audience, template, provider or policy gate is missing.                    | 16             |

## 18. Acceptance scenarios and release evidence

Use fixtures with Customer accounts in Invited/Active/Suspended/Deactivated access states and Active/Inactive/Restricted/Archived operational states; two Agents before/after reassignment and temporary lock/suspension; Admins with different exact grants; optional preferences; verified/invalid email; provider acceptance/delivery/failure/unknown callbacks; plan/contribution/fee/withdrawal/reversal/reconciliation/statement/report events; and stale/dead-letter/rebuilt delivery records. Evidence records requirement/scenario IDs, event/schema/template versions, recipient/scope/account/preference state, expected/observed content class/channel/status, attempt/provider references, source invariants and audit outcome.

| ID         | Requirement mapping    | Testable expected result                                                                                                                                             |
| ---------- | ---------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| NTF-AC-001 | NTF-FR-001, NTF-FR-002 | Defined event creates only catalogue in-app/email intents; SMS/WhatsApp/push/free-form Admin broadcast endpoints are absent/denied.                                  |
| NTF-AC-002 | NTF-FR-003             | Clicking/retrying/reading a notice cannot create, approve, pay, reverse, reassign or change the source event.                                                        |
| NTF-AC-003 | NTF-FR-004             | Valid versioned envelope is accepted once; unknown schema, unexpected variable or arbitrary recipient fails without raw-payload fallback.                            |
| NTF-AC-004 | NTF-FR-005             | Logical notification, recipient read state, email intent and append-only attempts retain stable links/versions independently.                                        |
| NTF-AC-005 | NTF-FR-006, NTF-FR-017 | Security/mandatory financial event cannot be relabelled optional by producer/preference; approved routine progress can be optional.                                  |
| NTF-AC-006 | NTF-FR-007             | Invitation/password/MFA/recovery event uses Authentication template/token path; generic retry cannot reveal/regenerate token or send duplicate challenge.            |
| NTF-AC-007 | NTF-FR-007             | Permission/security change reaches target through required safe channels and contains no credential/session/secret.                                                  |
| NTF-AC-008 | NTF-FR-008             | Customer reassignment sends Customer/replacement notices and minimal former-Agent receipt with no Customer link/data.                                                |
| NTF-AC-009 | NTF-FR-008             | Status/suspension/offboarding/service-interruption notices expose safe consequence, not internal reason/allegation/private case details.                             |
| NTF-AC-010 | NTF-FR-009             | Mixed NGN 6,000 savings/500 fee receipt shows distinct components once and no evidence/false 6,500 savings.                                                          |
| NTF-AC-011 | NTF-FR-009             | Missed/partial/advance and reconciliation messages preserve nonfinancial/custody distinctions and never change Customer balance.                                     |
| NTF-AC-012 | NTF-FR-010             | Withdrawal Submitted/Unknown/Failed/Posted messages state actual phase and G/F/D/P; Provider accepted is never called paid.                                          |
| NTF-AC-013 | NTF-FR-010             | Reversal pending leaves original effective; Approved-and-posted notice links full compensation and makes no unsupported cash-return claim.                           |
| NTF-AC-014 | NTF-FR-010             | Statement/report ready notice contains authorized route/reference/cutoff but no artifact attachment/bearer URL; notification does not generate artifact.             |
| NTF-AC-015 | NTF-FR-011             | Affected account/current Agent/initiator/exact-permission recipients resolve correctly; baseline Admin and unrelated users are excluded.                             |
| NTF-AC-016 | NTF-FR-012             | Reassignment before dispatch suppresses former-Agent content and creates one replacement intent; after delivery former inbox access ends.                            |
| NTF-AC-017 | NTF-FR-012             | Permission revocation/suspension before retry blocks protected delivery/retrieval; newly granted Admin gets queue access but no retrospective email.                 |
| NTF-AC-018 | NTF-FR-013             | Invited Customer receives only allowlisted safe email/retained inaccessible inbox; link does not activate or reveal history.                                         |
| NTF-AC-019 | NTF-FR-013             | Suspended/Deactivated user cannot open inbox; lifecycle/security email grants no session and invalid address triggers owner issue, not next-of-kin fallback.         |
| NTF-AC-020 | NTF-FR-014             | Template update creates a new version for future render; prior sent snapshot/hash/content remains reproducible.                                                      |
| NTF-AC-021 | NTF-FR-015             | `en-NG` amount/date/time and escaped Unicode name render consistently in app/plain/HTML without rounding or script execution.                                        |
| NTF-AC-022 | NTF-FR-015             | Notification uses owner-supplied exact amount/state/version and cannot recalculate from stale dashboard/cache.                                                       |
| NTF-AC-023 | NTF-FR-016             | Fuzz event variables with secrets, evidence, private reasons, credentials and other-Customer fields; validation/render/provider logs reject or redact them.          |
| NTF-AC-024 | NTF-FR-017             | User cannot disable required security/lifecycle/posted-financial channels; invalid destination remains visibly failed rather than “opted out.”                       |
| NTF-AC-025 | NTF-FR-018             | Optional progress preference applies only prospectively to that user/category/channel and never hides owning task/source/audit.                                      |
| NTF-AC-026 | NTF-FR-019             | Inbox search/filter/count/pagination operates after scope with stable cursors, no gaps/duplicates and no other-recipient suggestions/counts.                         |
| NTF-AC-027 | NTF-FR-019             | 25/50/100 page choices and 366-day validation produce full scoped results; unread badge agrees at stated cutoff.                                                     |
| NTF-AC-028 | NTF-FR-020             | Read/unread/page-read actions are idempotent per recipient and alter no content/event/email state or another user's state.                                           |
| NTF-AC-029 | NTF-FR-021, NTF-FR-034 | Deep link reauthenticates/rechecks current state; completed action is read-only, lost scope is generic unavailable, and CTA never performs direct approval.          |
| NTF-AC-030 | NTF-FR-021             | Loading, empty, no-match, stale, unavailable, expired, superseded and scope-loss states are distinct and retrying reads emits nothing.                               |
| NTF-AC-031 | NTF-FR-022             | Provider without delivery webhook stops at Provider accepted; verified callback yields Delivered; neither yields in-app Read.                                        |
| NTF-AC-032 | NTF-FR-022, NTF-FR-025 | Timeout after provider request remains uncertain Pending/Dead-letter until reconciliation; UI never asserts Failed/Delivered without evidence.                       |
| NTF-AC-033 | NTF-FR-023, NTF-FR-028 | Source commits with durable intent, then provider outage leaves mutation intact; delivery recovery cannot repeat transaction/reservation/status.                     |
| NTF-AC-034 | NTF-FR-023             | Failure before required durable local intent follows owner's atomic failure rule; urgent suspension can commit with recoverable local outbox during remote outage.   |
| NTF-AC-035 | NTF-FR-024             | Duplicate event/broker/workers/restart and multiple audience matches produce one coalesced logical item and one safe provider operation per event/recipient/channel. |
| NTF-AC-036 | NTF-FR-024, NTF-FR-025 | Same provider callback/reconciliation replay is idempotent and out-of-order older status cannot downgrade Delivered.                                                 |
| NTF-AC-037 | NTF-FR-026             | Transient operational email attempts initial+two retries near 5/15 minutes with jitter; permanent/scope-loss/superseded events do not retry.                         |
| NTF-AC-038 | NTF-FR-026             | Expired Authentication challenge cannot be generically retried; authorized resend creates distinct Authentication event under its limits.                            |
| NTF-AC-039 | NTF-FR-027             | Delivery issue visible/retryable only via exact owner permission/current Agent scope; no `notifications.manage` or baseline Admin override exists.                   |
| NTF-AC-040 | NTF-FR-027, NTF-FR-028 | Dead-letter resolution records safe status and never changes money/profile/access/report or claims recipient delivery.                                               |
| NTF-AC-041 | NTF-FR-029             | Race reassignment, permission/preference/destination/template change and worker lease; final outcome has truthful attempt and no unauthorized new send.              |
| NTF-AC-042 | NTF-FR-029             | Concurrent read/unread actions preserve content and recipient isolation; expected-version conflict cannot mark another item.                                         |
| NTF-AC-043 | NTF-FR-030             | Rebuild exactly restores IDs, intents, read/preference/suppression/status without emailing already delivered/expired historical events.                              |
| NTF-AC-044 | NTF-FR-031             | User cannot access another inbox; Admin/auditor/owner delivery panel reveals only its separately authorized metadata and not read/private body.                      |
| NTF-AC-045 | NTF-FR-032             | Proposed expiry removes normal inbox availability but preserves canonical business/audit/financial record; delivered email is not claimed recalled.                  |
| NTF-AC-046 | NTF-FR-032             | Data request applies approved retention/masking without breaking source references; historical destination changes are not rewritten.                                |
| NTF-AC-047 | NTF-FR-033             | Mobile, keyboard and screen reader users can navigate/filter/read/change permitted preferences with focus/status text and no color-only meaning.                     |
| NTF-AC-048 | NTF-FR-033             | Email has plain-text alternative/descriptive CTA/logical reading order and remains understandable without images.                                                    |
| NTF-AC-049 | NTF-FR-034             | Inbox approve/pay/download action always opens independently authorized current owner screen; stale notification grants nothing.                                     |
| NTF-AC-050 | NTF-FR-035             | Event/routing/template/preference/attempt/suppression/retry/access denial has safe durable audit linkage and no secret/raw provider payload.                         |
| NTF-AC-051 | NTF-FR-036             | Metrics/alerts show backlog/latency/state/error health without bodies, raw addresses, secrets or identifiable amount logs.                                           |
| NTF-AC-052 | NTF-FR-037             | Documented representative load meets/reports proposed inbox/count/dispatch/webhook targets without early business success or false delivery.                         |
| NTF-AC-053 | NTF-FR-038             | Backup/PITR reconciliation restores durable cutoff with zero lost mandatory intent/duplicate logical notice/unauthorized historical resend.                          |
| NTF-AC-054 | NTF-FR-039             | Forged webhook, CSRF state change, enumeration, provider-secret/log exposure and unauthorized retry/download are rejected and audited safely.                        |
| NTF-AC-055 | NTF-FR-040             | Remove each owner/schema/audience/template/destination/provider/privacy dependency: affected event/channel is Blocked without generic/raw/fallback send.             |

## 19. Worked examples

### 19.1 Customer reassignment before contribution email retry

A contribution posts for Customer C while Agent A is assigned. Customer C receives one mandatory financial in-app/email intent. Agent A receives an in-app recording receipt, not Customer email. Before an operational follow-up is dispatched, C moves to Agent B. The Customer intent remains theirs; A loses Customer-linked inbox access; unresolved current-service work routes to B under the same event/purpose with a new recipient-bound dedupe key. The contribution, balance and original collector attribution never change.

### 19.2 Withdrawal provider acceptance is not delivery

A withdrawal posts only after Module 08 receives definitive payout success. Its Customer email request times out after reaching the email provider. The financial withdrawal remains Posted. The email intent stays uncertain Pending until provider lookup resolves it; the interface does not mark Delivered, and a worker does not blindly submit a duplicate. Even if the email eventually fails, the reservation is not recreated and the payout is not retried.

### 19.3 Invited Customer contribution receipt

An eligible Agent posts NGN 2,000 savings for an operationally Active Customer whose account is still Invited. The system creates a mandatory Customer financial notice associated with that account and may send the allowlisted safe receipt email to the registered address. It omits statement history and any activation token; Authentication's separate invitation remains the only activation path. The in-app notice is inaccessible until valid activation and is rechecked then.

### 19.4 Mandatory versus optional preferences

A Customer disables optional plan-progress in-app notices. A routine “10 of 30 slots funded” event is suppressed if enabled as optional. A later NGN 5,000 withdrawal posting and account email change still generate their mandatory financial/security routes. The preference changes neither event, transaction, audit nor source-screen history.

## 20. Decisions for review

Review the initial in-app/email allowlist, mandatory/optional categories, proposed operational retry timing, shared-queue versus individual Admin delivery, retained Invited-account inbox behavior, `en-NG` locale, 25/50/100 pagination, 366-day inbox search range, 24-month proposed communication retention, no attachments, no open tracking and performance objectives.

Before implementation, finalize Modules 11–12 event schemas; every source event/audience/template; email provider/finality/data-processing terms; Authentication token transport boundary; recipient rerouting transaction; preference/legal-consent policy; Customer financial-email content; template publishing ownership; exact retention/deletion requirements; accessibility evidence; and recovery objectives. No undefined decision may be filled with a free-form Admin message, guessed audience, raw payload, unsafe retry, silent alternate channel or claim that delivery changed business state.
