# Audit, Security Operations, and Retention

**Product version:** 2.0  
**Module:** 14  
**Module status:** Detailed draft for review  
**Sources:** [Version 1 PRD](../../PRD.md), [User Types](./01-user-types.md), [Authentication](./02-authentication.md), [Roles and Permissions](./03-roles-and-permissions.md), [Customer and Agent Management](./04-customer-and-agent-management.md), [Fees and Deductions](./05-fees-and-deductions.md), [Thrift Plans and Savings Cycles](./06-thrift-plans-and-savings-cycles.md), [Collections and Reconciliation](./07-collections-thrift-card-and-reconciliation.md), [Withdrawals and Payout Approvals](./08-withdrawals-and-payout-approvals.md), [Reversals and Financial Corrections](./09-reversals-and-financial-corrections.md), [Transaction Ledger, Balances, and Statements](./10-transaction-ledger-balances-and-statements.md), [Dashboard and Operational Analytics](./11-dashboard-and-operational-analytics.md), and Modules 12–13 when finalized

## 1. Purpose and specification status

This module defines the canonical append-only audit trail, its protected change payloads and searchable projections; the narrow operational tools available to authorized security staff; security-event triage; and controlled retention, archival, integrity verification and restore.

The audit trail answers who or what acted, on which record, under which authority, when, with what safe change/result and correlation. It is evidence and observability, not an authorization source, financial ledger, mutable activity note or substitute for the owning business record.

Modules 01–10 establish immutable financial history, server authorization, separate `audit.view`, `security.operations.manage` and `reports.export` grants, and required durable audit capture. Detailed event schemas, cases, retention durations, tamper evidence, search and release choices below are **proposed for review** unless inherited. Retention defaults are product proposals, not a statement of legal compliance; the business must approve applicable Nigerian and other legal/regulatory obligations before production.

## 2. Initial scope and exclusions

### 2.1 Included

- One configured business and a canonical append-only event store.
- Protected before/after change payload references separated from safe event summaries.
- Asynchronous searchable audit index, deterministic replay and index-health states.
- Authentication, authorization, identity/lifecycle, financial workflow, export, protected-read and security-operation events.
- Admin `audit.view` search/detail with field masking and access logging.
- Admin `security.operations.manage` security-event/case views and only the lock/recovery actions already authorized by Modules 02–03.
- Suspicious-event triage cases, assignment, notes/evidence references, state history and notifications without financial authority.
- Retention classes, legal/business holds, controlled system expiry, tamper evidence, backup/restore and verification.

### 2.2 Excluded or separately owned

- Arbitrary application/database logs as canonical audit; raw infrastructure logs remain separate observability data.
- A generic “act as user,” credential/MFA reveal, session-token inspection, password reset, financial correction, manual journal, plan/customer mutation or permission override.
- A detailed audit CSV/PDF export. `reports.export` does not authorize it, and `audit.view` is read-only search/detail.
- Manual purge, event editing, backdating, deletion by archival/offboarding, user-created retention rules and silent legal-hold release.
- SIEM/SOC integrations, automated account suspension, machine-learning risk scores and cross-business threat intelligence until separately approved.
- Legal discovery, regulator production, data-subject fulfillment and breach-notification execution; this module defines evidence boundaries and decision gates, not legal conclusions.
- Raw password, MFA, recovery, payment credential or full uploaded-evidence storage in audit.

Missing retention/legal/export policy leaves the dependent operation unavailable; it never grants an Admin an erase or reveal action.

## 3. Architecture and sources of truth

### 3.1 Canonical event versus search index

| Component                   | Role                                                                                     | Authority                                                                                        |
| --------------------------- | ---------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| Canonical audit event store | Durable ordered append-only evidence of material event and protected-payload references  | Source of truth for audit existence/content; cannot be edited through application interfaces.    |
| Protected change store      | Encrypted sensitive before/after values or evidence references needed for accountability | Separately authorized and masked; not copied into ordinary index/logs.                           |
| Search index                | Derived fields optimized for authorized filter/search                                    | Rebuildable and non-authoritative; missing/stale index cannot erase or alter canonical evidence. |
| Security case store         | Mutable-by-transition operational workflow whose every change emits canonical audit      | Source for current triage state, not a replacement for source security events.                   |
| Application/infra telemetry | Health/debug/security signals with independent retention                                 | May create audit/security events after classification; never used to rewrite canonical records.  |

Canonical events receive an immutable server-generated ID and committed time. Search documents retain canonical event ID, indexed-at, canonical watermark, schema/index version and masking class. Search results display index freshness. Opening detail reads canonical permitted fields or a cryptographically verified projection; an index snippet alone is never treated as complete evidence.

### 3.2 Durable capture boundary

A material successful business mutation must persist its canonical audit event or same-database transactional outbox intent in the same commit boundary as the mutation. If durable capture cannot be guaranteed, the mutation fails and no success notification is sent. Cross-service consumers use immutable event IDs and idempotent delivery.

A remote search/index outage does not block a mutation when the canonical event/outbox is durable. Urgent suspension remains available on that basis. Indexing retries later with the same event ID. A canonical-store/outbox failure blocks successful protected mutation; never downgrade to an ordinary log line.

Denied/failed attempts have no business mutation and may be captured through a durable security-event pipeline outside that transaction. Failure of that pipeline must not turn a denial into an allow. High-risk or repeated denial telemetry is buffered/retried and raises monitoring; publicly observable error responses remain generic.

## 4. Canonical event and payload model

### 4.1 Required event fields

- Event ID (sortable immutable identifier), schema version, event type/code, category and severity supplied by reviewed catalogue.
- Business scope, service/source module, environment and source record/type/version.
- Actor type: user, trusted service, scheduled job or unauthenticated/unknown source; actor account/public reference when known.
- Effective role and required permission/capability at action time; authorization and fresh-auth outcome/reference without credentials.
- Target type/ID and scoped related references such as Customer, Agent, plan, request, transaction, posting group, export or security case.
- Action, outcome (`Succeeded`, `Denied`, `Failed`, `Conflict`, `Expired`, or safe owner-specific result), reason/category and error class/code without raw stack/request content.
- Immutable server `occurred_at`/`committed_at` UTC timestamps; owner effective business date/timezone where materially different. Client time is contextual only.
- Correlation/root event, operation/idempotency, request/trace, session-reference hash and parent/caused-by event references as applicable.
- Safe changed-field list, before/after protected-payload references, source evidence/ledger references and data-classification/masking level.
- Permitted source context: channel, device class, browser/app version, coarse location only if approved, network-context references and risk signals.
- Retention class, hold status reference, integrity partition/sequence/hash/checkpoint reference and index status metadata outside the signed content where appropriate.

Events describe the actual result. A failed request does not claim a before/after mutation; a successful multi-record operation references its committed boundary and affected record IDs without copying entire records.

### 4.2 Actor and service identity

Record the authenticated human initiator, distinct approving/reviewing actor and system executor separately. A service event includes workload identity/version and initiating user/correlation when acting on a user command. “System” alone is insufficient for a privileged mutation when a human triggered it. Reassignment, deactivation and later permission revocation never rewrite historical actors or the authority evidence at event time.

Unauthenticated attempts use no guessed account identity. Where public responses conceal account existence, internal matching may reference an account only in protected fields and only when policy permits. Default search summaries must not expose whether an attempted email matched an account.

### 4.3 Before/after and payload minimization

Safe summaries store changed field names and status/reference transitions. Sensitive actual values reside only when necessary in encrypted protected change records linked to the event. Examples:

- Email/phone are masked in ordinary audit; protected history may preserve prior/new normalized values for authorized investigation.
- Address, next-of-kin, notes and Customer-facing/internal reasons are summarized by field; protected value access requires masking policy and business need.
- Financial events reference immutable posting groups/entries, amounts/currency safe for authorized audit detail, and owner decisions; do not duplicate the full ledger.
- Files/photos/evidence store asset/checksum references, classification and action, never bytes in the event.
- Permission changes retain explicit before/after grant codes; authentication state changes retain state/restriction, not secret material.

Never record password values/hashes, MFA seeds/codes, recovery codes, invitation/reset/email-confirmation/session/bearer tokens, cookies, API/provider secrets, encryption keys, CVV/PIN/full card or bank-login credentials, full request/response bodies, arbitrary exception dumps or raw uploaded content. Redaction occurs before durable write and before telemetry; post-write scrubbing is not an acceptable primary control.

### 4.4 IP and device minimization

Proposed security context:

- Store device/session reference as a keyed non-reversible identifier, device class and approved client metadata; no hardware fingerprint beyond Authentication's approved trusted-device model.
- Use a keyed IP correlation hash and coarse network prefix/country where approved for routine abuse correlation. If exact source IP is operationally necessary, encrypt it in the highest security payload class with a proposed 90-day default and restricted security-only reveal; do not place it in the ordinary search index.
- Rotate correlation keys under key-management policy while retaining bounded investigation continuity. Hashes from different rotation epochs need not be globally linkable.
- User agent is parsed/minimized to browser/OS/app family/version; raw strings remain short-lived observability data unless tied to an active case.

Final IP/location lawful basis, notice, exact retention and reveal policy are release gates. Absence of approval means omit exact IP rather than retain indefinitely.

## 5. Event catalogue

| Category                   | Required material events                                                                                                                                                                                                                                                                                                                                                               |
| -------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Authentication             | Invitation issue/resend/correction/cancel/expire/accept; activation; login success/failure categories; password reset/change; email change; MFA enrol/replace/remove through approved recovery; recovery-code use/regeneration; session/trusted-device create/revoke/expire; locks/cooldowns/unlocks; suspected compromise; assisted recovery lifecycle; security notification result. |
| Authorization/admin        | Admin invitation; grant/revoke; permission/version/restriction change; final-Admin safeguard; fresh-auth result; high-risk deny; self-management/separation-of-duty denial; catalogue migration.                                                                                                                                                                                       |
| Customer/Agent lifecycle   | Registration, field/status/assignment change, archive/restore, Agent readiness/inactivity/suspension/reactivation/offboarding/deactivation, task handover and gate failure.                                                                                                                                                                                                            |
| Plans and fees             | Plan create/revise/pause/resume/complete/close/cancel/renew and gate failures; fee rule publish/retire/snapshot; assessment/application/receipt/waiver/refund/draw/deduction and failed protected action.                                                                                                                                                                              |
| Collections/reconciliation | Receipt/post/allocation/annotation; batch/freeze/supplement; remittance/evidence/review/exception/resolution; duplicate/idempotency/conflict and unavailable owner.                                                                                                                                                                                                                    |
| Withdrawals/reversals      | Request/reservation/review/hold/reject/cancel/payout/failure; reversal initiation/evidence/decision/compensation; dependency/physical-money/closed-plan exception.                                                                                                                                                                                                                     |
| Ledger/statements          | Posting/compensation group, mapping/schema change, integrity mismatch/freeze/rebuild/promotion; statement generate/download; protected transaction detail where required.                                                                                                                                                                                                              |
| Reports/exports            | Export request/authorization/start/complete/fail/expire/download/deny; report definition/configuration change. Ordinary dashboard refresh is telemetry unless protected.                                                                                                                                                                                                               |
| Protected reads            | Audit detail/payload reveal, security-event/case/evidence access, recovery evidence, private management/offboarding notes and other catalogue-designated high-risk reads. Do not emit one event per ordinary list row.                                                                                                                                                                 |
| Security operations        | Manual unlock, session revocation for compromise, recovery approval/rejection, Agent security action, security case create/assign/state/escalate/close/reopen and attempted unauthorized action.                                                                                                                                                                                       |
| Audit/retention            | Index/replay/rebuild, integrity checkpoint/verification failure, retention transition/expiry, legal/business hold place/release, restoration verification and audit configuration change.                                                                                                                                                                                              |

Equivalent repeated low-risk denials may aggregate into one security event with first/last times, exact count, dimensions and severity, but distinct successful/protected mutations, targets or materially different sources must not be collapsed. Aggregation must retain evidence needed for thresholds and never disclose account existence publicly.

## 6. Access and authority boundaries

### 6.1 `audit.view`

Only an active Admin with `audit.view` may search and open detailed business audit events. It is read-only and grants no mutation, security response, unmask/reveal, evidence download, export, retention change, hold management or financial action. Baseline Admin access, `reports.export` and `security.operations.manage` do not grant general audit search.

Customers and Agents see only owning-module receipts/timelines permitted to them; these are curated projections, not audit access. An actor does not gain audit access merely because an event names them.

### 6.2 `security.operations.manage`

This grant authorizes only Modules 02–03's defined operations:

- View non-secret privileged authentication-abuse/lockout/security events required for response.
- Approve/reject Customer assisted recovery after assigned-Agent initiation; initiating Agent cannot approve.
- Control Agent assisted recovery as specified by Authentication.
- Perform permitted manual unlock for Customer/Agent and another Admin after required identity verification/reason; no Admin self-unlock.
- Perform the Agent security operations explicitly assigned by Authentication and receive active security notifications.

It does not authorize Admin assisted recovery where `admins.manage` and its distinct-approver rules apply; status suspension/reactivation owned by `agents.manage`/`admins.manage`; audit-wide access; permission changes; password/MFA/secret viewing or setting; account activation; financial mutations; or bypass of final-Admin/recovery restrictions.

### 6.3 Protected reveal and export

Initial scope has no unmask/reveal button for exact IP or protected before/after values beyond fields expressly approved for masked audit detail. If future investigation requires reveal, define a separate approved authority, purpose, fresh authentication, case link, field-level policy, access audit and notification/non-notification rule; do not infer it from `audit.view`.

No detailed audit export exists initially. `reports.export` exports governed business reports, not canonical audit records or security evidence. Combining `audit.view` plus `reports.export` still does not create audit-export authority. A future export requires an explicit permission-catalogue change or tightly specified existing-authority decision, format/masking, watermark, encryption, expiry/download logging and legal approval.

## 7. Audit search and screens

### 7.1 Audit search

With `audit.view`, filter by inclusive UTC/business-date range (clearly labelled), event ID/type/category/outcome/severity, actor/service, target type/reference, source module, required permission, correlation/operation/request reference and retention/hold class. Personal-identifier search uses Module 04 normalization and returns only authorized masked results. No secret, note body, evidence content, raw IP or unrestricted free-form payload query.

Proposed default: last 24 hours, newest canonical committed time first, stable event-ID tie-breaker, 25 rows with 25/50/100 options. Proposed interactive maximum range 366 days; wider investigation requires a future governed query/export, not browser scraping. Cursor binds actor, permission version, filters, index version and canonical watermark. A scope/grant change invalidates it.

Each row shows time, event code/summary, actor/service, masked target, outcome/severity and index/canonical state. Detail shows allowed fields, correlation chain, safe before/after, owner references and integrity verification result. Links reauthorize in the owning module; an audit reference is not access authority.

### 7.2 Index health and gaps

Display index watermark, lag, rebuild state and known gap. A result count from a stale/partial index is labelled incomplete and must not be reported as proof that no event exists. Exact event-ID lookup may fall back to canonical retrieval when authorized and operationally safe. Do not run unrestricted canonical scans from the UI.

Index rebuild consumes canonical events by partition/sequence, verifies schema/hash, writes a new version and atomically promotes only after completeness/control checks. Old verified index may remain read-only with as-of label. Retry/replay uses event ID and cannot duplicate canonical events or domain actions.

### 7.3 Security operations workspace

Admin with `security.operations.manage` sees current non-secret security events/cases, affected account reference where permitted, restriction/lock state, safe risk reasons, related attempts and Authentication-owned actions. It does not expose credential values, recovery answers, full IP by default or unrelated audit history.

Manual unlock/recovery controls call Authentication workflows, show exact limited effect, require identity-verification reference, reason, fresh authentication where Authentication requires it, expected versions and explicit confirmation. Unlock clears only the intended eligible restriction; it cannot reset credentials/MFA, activate an invitation, restore Suspended/Deactivated status/permissions/assignments, or dismiss a separate security case automatically.

## 8. Suspicious-event triage and cases

A security case groups related security signals for investigation; it does not change financial or account state by itself.

### 8.1 Proposed case fields and states

Fields: immutable case ID, source rule/event references, safe title/category/severity, affected account(s) where permitted, created/updated UTC times, current owner Admin, state/version, protected notes/evidence references, correlation dimensions, required next step, linked Authentication action/result and closure reason.

States: **Open → Investigating → Resolved or Closed—no action**. Proposed **Reopened** is represented as Investigating with a linked reopen event and incremented episode, preserving prior closure. No deletion or resetting history. Severity: Informational, Low, Medium, High, Critical; it prioritizes work but never grants or automates authority.

An active Admin with `security.operations.manage` may assign/reassign a case to another active holder, add protected notes/evidence references, move states and link a separately authorized Authentication action. They cannot close an unresolved mandatory Authentication restriction by changing the case state. Lost permission/suspension removes case access/ownership eligibility; case is returned to eligible queue without erasing the actor.

### 8.2 Detection and response

Sources may include Authentication's account/source/distributed abuse signals, unusual permission/access denials, session compromise, repeated protected-data access, export abuse, audit integrity failure and owner-supplied security events. Detection rules are versioned/configured by trusted deployment policy initially; no UI rule editor is introduced.

Automated detection may create/deduplicate a case, raise severity and notify eligible responders. It may apply only Authentication's already-defined automatic cooldown/lock/session-revocation policies. It cannot suspend/deactivate users, alter permissions, approve recovery, reverse money, cancel payouts, freeze arbitrary accounts or disclose evidence without the separately authorized workflow.

Deduplication uses stable rule/account/source/time-window key and appends occurrences/counts. A distinct affected account, successful privileged event or material severity escalation is retained distinctly. Unknown account attempts remain source-pattern cases without confirming account existence.

## 9. Retention, archival and holds

### 9.1 Proposed retention classes

| Class                                      | Examples                                                                                                                        | Proposed default                                                                                                                                                       |
| ------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Financial/lifecycle/authorization evidence | Posted financial actions/corrections, plan/customer/Agent lifecycle, permissions, approvals, reconciliation, statements/exports | Seven years after business record/cycle closure or later linked settlement, subject to approved law/business policy; canonical event and essential payload references. |
| Authentication/security material           | Login/lock/session/recovery/security operations and cases                                                                       | Two years from event/case closure; exact IP, raw user agent and high-granularity network context proposed 90 days unless active hold/case requires approved extension. |
| Protected read/export access               | Audit/security/evidence access, export/download events                                                                          | Two years from access or longer when linked to active case/financial evidence.                                                                                         |
| Delivery/operational diagnostics           | Notification attempts, index delivery/replay and non-security failures                                                          | One year; canonical material result may inherit its parent evidence class.                                                                                             |
| Routine access telemetry                   | Ordinary dashboard/page access not designated protected                                                                         | 90 days proposed, aggregated thereafter only if de-identified under approved policy.                                                                                   |
| Integrity checkpoints/retention actions    | Chain checkpoints, verification, hold/expiry/restore events                                                                     | At least as long as the latest covered retained event plus seven years for expiry evidence, subject to policy.                                                         |

These periods require legal/privacy/business approval and configuration migration rules before release. Choose the longer applicable class when one event supports multiple retained records. Retention begins from the documented trigger, not arbitrary last-view time; access must not perpetually reset it.

### 9.2 Archival and controlled expiry

Canonical hot-to-archive transition preserves event ID, bytes/logical canonical representation, encryption, integrity chain/checkpoint, index locator, classification and authorized retrieval. Archived is storage tier, not Customer `Archived` status and not deletion. Search shows archived availability/retrieval state without false absence.

On retention expiry, a trusted scheduled policy service may remove eligible canonical payload/event material only after checking configured policy/version, holds, linked longer-lived evidence, backup-expiry coordination and integrity manifest. It emits an immutable retention-action event and count/range/checkpoint evidence without retaining the expired sensitive content. There is no Admin manual purge endpoint.

If deletion of a chained event would break verification, retain signed checkpoint/manifest and documented cryptographic tombstone sufficient to prove authorized expiry without reconstructing content. Exact cryptographic design must be independently reviewed. Failed/partial expiry remains retryable and must not create a false completed event.

### 9.3 Legal/business holds

A hold prevents expiry/backup deletion for specified event IDs, subjects, cases, date ranges or source records while preserving ordinary access controls. Initial scope proposes hold placement/release only through a trusted legal/business policy administrator outside the application until an explicit permission and approval workflow exists. Application Admins cannot use `audit.view`, `security.operations.manage` or `reports.export` to manage holds.

Hold records require authority source, scope/query resolution, reason, placed/released actors, approval/evidence reference, timestamps and immutable audit. Release never deletes immediately; the next policy run reevaluates normal eligibility. Overbroad/indefinite holds require periodic external review but are not auto-released.

## 10. Integrity and append-only corrections

Partition canonical events by a reviewed business/time scheme with monotonically ordered sequence where supported. Each event hashes canonicalized protected-safe event content plus previous hash/partition context. Produce periodic signed checkpoints stored separately in write-protected storage. Key identifiers/algorithms and rotation evidence are recorded; keys are not in events.

Hash chaining is tamper evidence, not authorization or encryption. Verify continuously and during archive/retrieval/backup restore/reindex. Missing/reordered/modified event, checkpoint/signature failure or sequence gap creates a Critical security case, marks affected audit range Unverified, blocks claims of completeness and preserves evidence. It does not auto-repair/delete or halt unrelated financial reads; affected protected mutations follow their owner fail-safe policy.

Canonical events are never edited. If an event contains an incorrect non-secret description/reference, append an `audit.event_correction` that identifies the original, corrected safe field/value, reason and authority while preserving both. If prohibited secret data enters audit, quarantine access immediately, create a security/privacy incident and execute an approved cryptographic erasure/redaction remediation that preserves tamper-evident incident/tombstone evidence. This exceptional incident procedure is not a general edit tool and must be finalized before production.

Schema evolution is additive/versioned or performed through verified projection migration. Readers preserve original semantics. Retried producer calls with same event ID/idempotency/source result do not append another success; legitimately distinct attempts receive distinct IDs and correlate to the same root.

## 11. Privacy and data-subject boundaries

Audit data is access-controlled personal/business data, not public history. Collect only necessary fields, assign purpose/class, mask by default and separate security/financial/change payloads. An account's deletion request, Customer archival or employee departure does not automatically erase evidence needed for financial integrity, security, legal claims or other people's rights.

A data-subject access or deletion request uses a separately approved privacy workflow. It must verify requester identity, find applicable records, exclude other persons' data/internal security techniques/legal restrictions, and produce a curated response—not give the subject `audit.view` or raw audit export. Where policy permits deletion/anonymization after obligations expire, apply controlled retention processing with evidence rather than mutable UI edits.

Masked identifiers may remain to preserve event relationships. Pseudonymization is not deletion when re-identification keys exist. Key access/retirement must follow approved policy. Never anonymize ledger/audit actor attribution in a way that makes active financial or security accountability false.

## 12. Notifications and escalation

Notify affected user and eligible responders according to owning Authentication/lifecycle policies for locks, manual unlock, recovery, password/email/MFA/security-session changes and suspected compromise. Security case notifications go only to active `security.operations.manage` holders or assigned eligible owner and contain minimal reference/severity/next step—no credential, exact IP, evidence attachment or detailed Customer finance.

Critical audit integrity/canonical-capture/restore failures notify designated security/operations recipients through a durable deduplicated channel. Notification delivery failure does not undo a committed security action/event; it creates retryable delivery status and may escalate operationally. Recipient permission/account/scope is rechecked before dispatch/retrieval. Former owners lose case detail immediately.

Do not notify attackers whether an account exists. Repeated events/cases use incident-level deduplication and material-change updates rather than alerting every refresh/attempt. Notification read/delivery tracking is separate from event occurrence and case resolution.

## 13. Concurrency, idempotency, backup and restore

- Producers bind event ID to source operation/result/schema. Same ID/same canonical content is idempotent; same ID/different content is a critical conflict.
- Domain version/permission checks and audit capture share the operation boundary. An audit consumer cannot change the domain outcome after commit.
- Search replay, archive, hold and expiry jobs use leases/checkpoints/idempotent partitions; concurrent attempts cannot duplicate, omit or prematurely expire events.
- Case changes use expected version; owner/state/note races append one accepted transition and reject/reload stale input.
- Security actions recheck account/restriction/case/permission/fresh-auth versions at Authentication commit. Case state never substitutes for that check.
- Unknown operation outcomes resolve by event/domain operation lookup before retrying. Do not infer failure from absent index result.

Backups encrypt canonical events, protected payloads, case records, holds, integrity manifests/keys under separated key-management policy and indexes only where useful. Backup retention matches approved class/hold; an expired primary event is not silently recoverable forever from unmanaged backups. Restore occurs into isolated verification, validates counts/hashes/checkpoints/references and owner control totals, then promotes atomically. Search indexes rebuild from verified canonical data.

Restore tests cover point-in-time consistency with domain records, events committed during backup boundaries, held/expired data, key rotation, archive retrieval, cases and access controls. A restored event does not replay the original financial/security mutation or notification. Any gap produces an incident and Unverified range.

## 14. Screens and accessibility

### 14.1 Audit workspace

Show search/index health, filters, stable paginated results, event detail, correlation timeline and masking legend. Protected fields are omitted or labelled masked/unavailable rather than blank ambiguously. Clearly distinguish actor, approver, executor, current owner and historical assignment. Provide Copy event reference, not Copy raw payload. No Edit/Delete/Export buttons.

### 14.2 Security workspace

Show non-secret signal/case queue, severity/state/age, current eligible owner, affected account safely, linked Authentication restriction and available authorized actions. Detail separates event facts, analyst notes, automated detection and Authentication results. A disabled action explains missing permission, identity verification, self-action rule, stale version or owning-module prerequisite without revealing secrets.

Proposed list defaults: Audit newest-first; security cases Critical/High then oldest open; stable ID tie-breaker; 25 rows with 25/50/100 options. Mobile layouts use compact rows/detail; desktop may use tables. Filters retain state on return.

Both workspaces support keyboard-only operation, labelled fields/buttons, focus/error announcements, accessible status/severity beyond color, responsive zoom/reflow and screen-reader-friendly timelines/tables. Loading skeletons do not imply no events. Empty, index-stale, archived-retrieval, partial, access-lost, integrity-failed and retryable error states are distinct.

## 15. Performance, monitoring and release gates

Proposed profile: 10,000 users, 2,000,000 financial entries, 20,000,000 canonical audit events, 250 concurrent application sessions and 20 concurrent authorized audit/security users. With a current index, p95 audit search ≤3 seconds for a 31-day filtered query and first 25 rows; exact event-ID detail p95 ≤2 seconds excluding cold archive retrieval. Cold archive shows a retrieval job/state; no false “not found.” These require production-like load verification before any claim.

Canonical capture prioritizes durability over index latency. Proposed p95 searchable availability ≤60 seconds after commit, with security Critical/High case/event projection ≤10 seconds; authorization/security enforcement itself is immediate in the owner and never waits for index. Monitor capture/outbox age, sequence gaps, index lag/replay failures, checkpoint verification, protected access, case age, retention/hold jobs, archive retrieval, backup/restore tests, denied cross-scope queries and notification failures without logging protected payloads.

Release requires approved event catalogue/schema registry, producer conformance, transactional capture, protected-payload encryption/masking, key management, canonical storage immutability, independently reviewed tamper-evidence scheme, search scope tests, Authentication security-operation integration, legal/privacy retention and hold decisions, incident/secret-remediation procedure, backup expiry and disaster-restore evidence. Missing requirements are **Blocked**, not satisfied by application logs or hidden controls.

## 16. Functional requirements

| ID         | Requirement                                                                                                                     | Sections |
| ---------- | ------------------------------------------------------------------------------------------------------------------------------- | -------- |
| AUD-FR-001 | Maintain canonical append-only events separate from rebuildable searchable indexes and mutable case projections.                | 3.1      |
| AUD-FR-002 | Persist required audit event/outbox in the same durable boundary as every material successful mutation.                         | 3.2      |
| AUD-FR-003 | Permit domain mutation during index outage only with canonical durability; block when canonical capture fails.                  | 3.2      |
| AUD-FR-004 | Store immutable schema/actor/service/target/result/authority/time/correlation/classification/integrity fields.                  | 4.1–4.2  |
| AUD-FR-005 | Store minimized safe before/after summaries and separately encrypted protected payload references.                              | 4.3      |
| AUD-FR-006 | Exclude credentials, secrets, tokens, raw evidence/full requests and payment-authentication data before write.                  | 4.3      |
| AUD-FR-007 | Minimize/device/IP context and restrict exact network data retention/reveal.                                                    | 4.4      |
| AUD-FR-008 | Capture the defined authentication, authorization, lifecycle, financial, export, protected-read, security and retention events. | 5        |
| AUD-FR-009 | Aggregate only equivalent low-risk denials while preserving distinct material/successful events and counts.                     | 5        |
| AUD-FR-010 | Restrict detailed audit search/detail to active Admins with `audit.view`, read-only and masked.                                 | 6.1      |
| AUD-FR-011 | Restrict `security.operations.manage` to defined non-secret security events, recovery, lock and Agent security operations.      | 6.2      |
| AUD-FR-012 | Keep audit view, security operations, Admin management, reports export and protected reveal/export independent.                 | 6        |
| AUD-FR-013 | Provide scoped versioned audit search/detail with stable pagination, masking and explicit index completeness.                   | 7.1–7.2  |
| AUD-FR-014 | Rebuild/replay indexes deterministically without duplicating canonical events/domain effects.                                   | 7.2      |
| AUD-FR-015 | Provide security workspace actions only through current Authentication authority/version/identity checks.                       | 7.3      |
| AUD-FR-016 | Maintain versioned triage cases/states/ownership/evidence whose changes produce canonical events.                               | 8.1      |
| AUD-FR-017 | Prevent detection/case state from inventing financial, suspension, permission, recovery or disclosure authority.                | 8.2      |
| AUD-FR-018 | Apply approved retention classes/triggers and longest applicable policy without access-reset retention.                         | 9.1      |
| AUD-FR-019 | Preserve integrity/access during archive and expire only via trusted policy with immutable expiry evidence.                     | 9.2      |
| AUD-FR-020 | Enforce external approved holds separately from application grants and audit every placement/release.                           | 9.3      |
| AUD-FR-021 | Provide independently reviewed partition hash/checkpoint tamper evidence and verify gaps/modification.                          | 10       |
| AUD-FR-022 | Correct descriptions by linked append-only event and handle accidental-secret incidents without general edit/purge.             | 10       |
| AUD-FR-023 | Enforce privacy minimization and separate curated data-subject workflows without raw audit access.                              | 11       |
| AUD-FR-024 | Send minimal scoped deduplicated security/integrity notifications and preserve outcomes independently.                          | 12       |
| AUD-FR-025 | Make producers, replay, cases, retention and security actions idempotent/version-safe under concurrency.                        | 13       |
| AUD-FR-026 | Back up/restore events/payloads/cases/holds/integrity metadata consistently without replaying business actions.                 | 13       |
| AUD-FR-027 | Provide accessible responsive audit/security screens and clear stale/archive/integrity states.                                  | 14       |
| AUD-FR-028 | Meet proposed indexed-search/detail/searchability performance under declared profile and monitor health safely.                 | 15       |
| AUD-FR-029 | Block release until storage, masking, cryptography, retention/legal, Authentication and restore contracts pass.                 | 15       |

## 17. Acceptance scenarios and traceability

Scenarios require production-equivalent storage/index/security-owner contract tests. A missing policy or integration is **Blocked**, not Passed. Record build/schema/key/index versions, actors/grants, operation/event/case IDs, canonical/index checkpoints, expected/result and evidence without exposing secrets.

| ID         | Requirements | Scenario and expected result                                                                                                                                                  |
| ---------- | ------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| AUD-AC-001 | AUD-FR-001   | Compare canonical event, protected payload, index and case records; index/case change cannot modify canonical bytes or domain history.                                        |
| AUD-AC-002 | AUD-FR-002   | Fault domain mutation before/after canonical/outbox write; exactly one mutation and matching event commit together or neither commits.                                        |
| AUD-AC-003 | AUD-FR-003   | Stop search index then suspend account/post financial action; durable canonical event permits owner action and indexes later once; stop canonical capture and mutation fails. |
| AUD-AC-004 | AUD-FR-004   | Verify human initiator, approver, executor, authority, target, result, times/correlation and versions for multi-actor financial/security events.                              |
| AUD-AC-005 | AUD-FR-005   | Edit identity/notes/permissions; safe event lists field/status changes while protected prior/new values stay encrypted/masked by policy.                                      |
| AUD-AC-006 | AUD-FR-006   | Submit credentials/tokens/card data/raw files/error dumps; scanners/schema prevent them entering canonical/index/telemetry.                                                   |
| AUD-AC-007 | AUD-FR-007   | Verify keyed device/IP correlation, masked search, exact-IP protected storage/90-day proposal and key-rotation bounded linkage.                                               |
| AUD-AC-008 | AUD-FR-008   | Exercise at least one event from every catalogue category; each has correct class/owner/result and no omitted successful protected action.                                    |
| AUD-AC-009 | AUD-FR-009   | Burst equivalent failed logins and distinct privileged successes/targets; aggregate failures with counts but retain every material distinct event.                            |
| AUD-AC-010 | AUD-FR-010   | Baseline Admin/Agent/Customer and Admin without `audit.view` cannot search/detail; authorized Admin sees masked permitted business events only.                               |
| AUD-AC-011 | AUD-FR-011   | Security Admin reviews lock/Customer recovery/manual unlock but cannot see secrets, self-unlock, change status/permission or perform finances.                                |
| AUD-AC-012 | AUD-FR-012   | Test every combination of `audit.view`, `security.operations.manage`, `reports.export` and management grants; none implies another or audit export/reveal.                    |
| AUD-AC-013 | AUD-FR-013   | Search/filter/cursor under stable watermark then revoke grant/change index; old cursor/link denies or expires without leaking counts.                                         |
| AUD-AC-014 | AUD-FR-013   | Index lags/has gap; UI labels incomplete/as-of and exact authorized canonical lookup does not claim missing events never occurred.                                            |
| AUD-AC-015 | AUD-FR-014   | Rebuild from canonical events twice; counts/hashes/results match and no domain action or duplicate canonical event occurs.                                                    |
| AUD-AC-016 | AUD-FR-015   | Race lock expiry/account suspension/permission revocation with unlock/recovery action; Authentication current version wins and stale case action fails.                       |
| AUD-AC-017 | AUD-FR-016   | Create/assign/investigate/resolve/reopen case concurrently; one versioned transition wins and every episode/note/owner change is audited.                                     |
| AUD-AC-018 | AUD-FR-017   | Critical case/detection tries automatic suspension, permission change, reversal or recovery approval; deny until separate owner authority performs it.                        |
| AUD-AC-019 | AUD-FR-018   | Events with multiple classes retain to longest trigger; viewing does not reset expiry; proposed durations apply only after approved policy.                                   |
| AUD-AC-020 | AUD-FR-019   | Archive/retrieve/expire eligible partition; IDs/hashes/access persist, expiry emits manifest/tombstone and no Admin manual purge exists.                                      |
| AUD-AC-021 | AUD-FR-020   | Place hold through approved external authority; expiry skips held data, application grants cannot release it, release is audited and next policy run reevaluates.             |
| AUD-AC-022 | AUD-FR-021   | Modify/remove/reorder event or checkpoint during verification/restore; mark range Unverified, open Critical case and block completeness claim without silent repair.          |
| AUD-AC-023 | AUD-FR-022   | Correct wrong description via linked event; original remains. Inject prohibited secret; quarantine/incident procedure works without exposing general edit tool.               |
| AUD-AC-024 | AUD-FR-023   | Archive/deactivate/data-subject request; retain necessary evidence, curate/mask third-party/security content and never grant raw audit access.                                |
| AUD-AC-025 | AUD-FR-024   | Notify eligible responders/user, then revoke permission/fail delivery/retry; current scope applies, duplicates suppressed and source action unchanged.                        |
| AUD-AC-026 | AUD-FR-025   | Retry producers/replay/retention and reuse same ID with same/different content; same is idempotent, conflict raises incident, no duplicate expiry/action.                     |
| AUD-AC-027 | AUD-FR-026   | Restore backup in isolation across key rotation/hold/expiry/event boundary; verify hashes/domain references and promote without replaying money/security/notifications.       |
| AUD-AC-028 | AUD-FR-027   | Verify mobile/zoom/keyboard/screen-reader/status/error/archive flows; no color-only or blank ambiguous protected value.                                                       |
| AUD-AC-029 | AUD-FR-028   | Load declared 20M-event profile; 31-day search p95≤3s, detail≤2s and searchability targets pass or claims remain unmet.                                                       |
| AUD-AC-030 | AUD-FR-029   | Remove each legal/cryptographic/storage/Auth/restore contract; dependent release stays Blocked and ordinary logs/hidden controls do not count as implementation.              |

## 18. Worked examples

### 18.1 Permission change

Admin A with `admins.manage` grants `reconciliation.manage` to Admin B after required fresh authentication. The domain grant and canonical event commit together. The event identifies A, B, permission code, prior/new explicit grants, authorization/fresh-auth result, permission versions, reason and time. It stores no password/MFA value. If search is down, the grant may commit only with durable canonical/outbox evidence; the event appears after replay.

### 18.2 Failed authentication burst

One source produces 100 invalid password attempts against several identifiers. Public responses remain generic. Security processing records aggregated source/rule event with first/last/count and separate account-scoped protected references only where permitted, creates one case by deduplication policy, and retains distinct successful login/session-revocation events. The ordinary audit index does not reveal attempted raw emails or exact IP.

### 18.3 Financial reversal

An Agent initiates and an Admin with `reversals.review` approves a full contribution reversal. Canonical events distinguish initiation, Admin review/fresh-auth result and atomically posted compensation group, with correlation references to original/ledger/plan/fee/custody effects. Audit never duplicates ledger entries or states cash returned absent Module 09 proof. `audit.view` cannot post another reversal.

### 18.4 Retention and hold

A routine delivery diagnostic reaches one-year proposed expiry, but it is linked as evidence to an open security case under a two-year class and active hold. The policy selects the longer class/hold and retains it. After externally authorized hold release and case-policy expiry, a later job removes eligible content, preserves checkpoint/tombstone evidence and emits a retention event. Viewing it did not restart retention.

## 19. Proposed choices and release decisions

| Decision            | Draft recommendation / implication                                                                                                                                            |
| ------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Canonical capture   | Same-transaction event/outbox for successful material mutation; index outage tolerated, canonical failure blocks.                                                             |
| Search              | `audit.view`, masked, 24-hour default/366-day interactive max, no audit export.                                                                                               |
| Security operations | Only exact Module 02/03 recovery/lock/Agent-security actions; separate from audit and management permissions.                                                                 |
| Cases               | Open/Investigating/Resolved/Closed—no action with linked reopen episodes; no automatic broad response authority.                                                              |
| Device/IP           | Keyed minimized identifiers; exact IP encrypted security-only with proposed 90 days if legally approved.                                                                      |
| Retention           | Proposed seven years financial/lifecycle, two years security/protected access, one year diagnostics, 90 days routine telemetry; approve law/business triggers before release. |
| Holds               | External trusted policy authority initially; no application Admin hold controls or manual purge.                                                                              |
| Tamper evidence     | Partition hash chain plus separately stored signed checkpoints and continuous/restore verification; independent cryptographic review required.                                |
| Accidental secrets  | Prevent before write; quarantine and approved incident/tombstone remediation if prevention fails, never general edit.                                                         |
| Performance         | 20M-event profile, search p95≤3s/detail≤2s; Critical security projection≤10s, general search≤60s after commit.                                                                |
| Privacy requests    | Curated separate workflow with masking/exclusions; no raw audit access or automatic archival erasure.                                                                         |
| Audit export        | Not in initial scope; neither `reports.export` nor combined current grants imply it. Permission/policy decision required.                                                     |

## 20. Related modules

Authentication owns credentials, locks, sessions and recovery mechanics. Authorization owns the closed permission catalogue. Business modules own action eligibility and durable records; their successful material mutations require canonical evidence. Module 10 remains the financial source of truth. Dashboards/Reports may show safe operational counts but do not gain audit payload access. Notifications deliver scoped outcomes. This module owns canonical audit evidence, search projection, security case workflow and retention enforcement without becoming an alternate authorization, ledger or business workflow engine.
