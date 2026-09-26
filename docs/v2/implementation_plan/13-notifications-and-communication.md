# Module 13 — Shared Notification Pipeline and Inbox

## Approved staged release

Implement the in-app foundation from [Module 13](../modules/13-notifications-and-communication.md). The user approved source-linked historical import, `en-NG`, NGN, configured business timezone, 25/50/100 rows, maximum 366-day date windows and 24-month inbox visibility. Existing email and Authentication delivery remain with their owners. No dependencies were added.

The initial catalogue adapts existing profile, Customer status, Agent status, plan, collection, withdrawal and reversal intents. It does not declare the complete Module 13 event catalogue delivered. Optional preferences, Authentication challenges, new email/provider handling, fee notices without a durable adapter, report/export alerts, broadcasts and additional channels remain deferred.

## Implementation checkpoint — 26 September 2026

| Task    | Delivered behavior                                                                                                                                                                                                                                         | Status                                                           |
| ------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------- |
| NTF-T01 | Closed source/schema/template catalogue; immutable source identity/version, safe facts, timezone, actor category, available audit correlation and rendered snapshot hash. Unknown contracts and changed snapshots fail closed.                             | Implemented for supported sources                                |
| NTF-T02 | Shared event, recipient-intent, alias and append-only attempt storage; uniqueness by event/recipient/channel; source-transaction capture; existing database jobs delegate to atomic materialization.                                                       | Implemented                                                      |
| NTF-T03 | Current recipient/role/permission/assignment checks, exact assignment binding, account-owner retention, scoped read predicates and safe internal owner links. Unresolved latest withdrawal/reversal work may reroute; completed historical notices do not. | Implemented for supported sources                                |
| NTF-T04 | Scoped Inertia inbox/detail, JSON synchronization, versioned read/unread and signed page-read, filters/search, encrypted pagination, navigation/bell and five-second foreground polling with stale-content clearing.                                       | Implemented; authenticated visual/accessibility evidence pending |
| NTF-T05 | Bounded attempts, safe failure categories, scheduled pending-intent drainer, dry-run/chunked/idempotent import, legacy pending recovery, preserved IDs/read state/timestamps, aliases and visibility expiry.                                               | Implemented; production concurrency/PITR evidence pending        |
| NTF-T06 | Full owner audiences/events, optional preferences, external delivery/provider finality, delivery panels, complete canonical Module 14 audit and retention/deletion policy.                                                                                 | Blocked/deferred                                                 |
| NTF-T07 | Focused inbox and affected owner tests, scoped static/frontend verification and acceptance mapping below.                                                                                                                                                  | Recorded; full-module acceptance remains blocked                 |

### Interfaces and behavior

- Authenticated named routes: `notifications.index`, `notifications.show`, `notifications.sync`, `notifications.read` (PATCH), `notifications.page-read` (POST), and `notifications.open` (GET redirect). UUIDs and signed tokens confer no access.
- `InboxNotice`, `InboxSync`, `InboxResult` and `InboxFilters` TypeScript contracts expose only safe rendered fields, read versions, visibility and synchronization/pagination metadata. No source payload, private history, destinations or delivery metadata is serialized.
- Visibility predicates run before SQL search, pagination and unread count. Suspended/Invited/MFA-setup accounts retain eligible own notices without gaining inbox access. Customer operational status does not transfer ownership. Former Agent notices remain bound to their original assignment; returned assignment access does not expose the earlier assignment's notices.
- Collection templates separate savings, external fees and total tender using integer kobo. Withdrawal notices retain reservation/approval distinctions; no unavailable payout execution is reported as paid. Templates use owner facts and escaped Vue text, not legacy payload HTML/URLs.
- Proposal notices reference an authoritative history-linked correction. Expired and superseded actions remain distinguishable during retention; resolved actions become informational. Legacy proposals without that authoritative link import as informational rather than claiming a still-actionable proposal.
- Read state changes only the recipient's `notifications` row. Replays are idempotent; conflicting changes return 409. A signed, expiring page token bounds bulk updates to the displayed IDs/versions, and any denied/conflicting item rolls the bulk transaction back.
- Cursors bind account, filters, scope, catalogue version and creation cutoff. New arrivals require refresh. In-app delivery is Pending → Attempting → Delivered/Suppressed/Blocked/Dead-letter; local insertion and successful completion commit together. Attempting occurs inside the local transaction, so an interrupted transaction returns to recoverable Pending rather than leaving an external-delivery lease.
- Transient failures retain safe attempt history and schedule eligibility after 30/120 seconds, up to three attempts. The minute drainer recovers due intents; permanent contract failures do not retry. No external provider or human-read finality is inferred.
- Owner intents and compatibility jobs remain in place. In-app dispatch failures leave committed shared intents recoverable. Existing mail branches retain their original behavior and are not certified by this release.

## Local rollout and verification

1. Apply the additive migration before deploying the new producers/workers.
2. Run `php artisan notifications:import --dry-run`, then `php artisan notifications:import`. Unknown/unlinked records stay outside the inbox. Verified delivered notices preserve IDs/read state/timestamps; verified orphaned pending owner intents become recoverable shared intents without immediate historical dispatch. Neither command sends email.
3. Enable `NOTIFICATION_INBOX_ENABLED=true` after verification; the committed default is false. Run the existing queue worker and scheduler. `notifications:drain --limit=100` runs every minute without overlap and accepts a limit of 1–1000.
4. Disabling the inbox flag hides navigation and prevents reads/read-state changes while retaining durable event/intent records. Visibility expiry does not delete business, financial or audit sources, and no purge policy is enabled.

The additive migration was applied successfully to the local MySQL database. Dry-run and actual import each reported zero eligible notices and zero rejected notices; fixture tests supply historical-data evidence. The inbox was enabled in the local untracked `.env` after those checks.

- 39 focused inbox tests pass, including rollback, atomic failure/retry limits, queue recovery, isolation, assignment loss, current grants/MFA, retained accounts, source/schema/template gates, corrupted facts/snapshots, read conflicts, pagination/cutoffs, signed bulk actions, historical aliases/read preservation, proposal lifecycle and plan event snapshots.
- The final combined affected regression run passed 288 tests / 2,174 assertions across inbox, Collection, Withdrawal, Reversal, profile/contact changes, Customer/Agent status, CustomerAndAgent, Report and Dashboard, including the plan-adapter test.
- Pending withdrawal rerouting is verified once per replacement recipient without changing the request or original actor. The mixed-receipt regression verifies ₦2,000 savings, ₦500 external fees and ₦2,500 tender.
- Scoped PHPStan passes for new services and affected integrations outside the existing ThriftPlanService type issues. An expanded check identified 21 existing ThriftPlanService errors; analysis of the committed HEAD source reproduced the same 21 errors. No suppression/baseline entries were added.
- Pint, changed-file frontend formatting/lint, Vue type checking, production build, six-route inspection and `git diff --check` pass. Repository-wide `npm run check` remains blocked by formatting in 26 unrelated files; those files were not reformatted.
- Browser navigation to the local inbox redirected to login. No authenticated browser session was available, so desktop/mobile visual, keyboard/screen-reader and runtime scope-clearing evidence remain unverified. No credentials or account permissions were changed to bypass that limitation.
- MySQL migration is verified; concurrent worker/reassignment races, declared production load, database restoration/PITR, full-module privacy review and complete-suite evidence are not certified. Request `php artisan test --compact` after focused verification.

## Acceptance evidence

Passed applies only where the complete scenario is covered by this in-app release. A Blocked row can contain passing partial evidence without certifying its missing channels, source owners or production conditions.

| Scenario   | Status  | Evidence or remaining blocker                                                                                          |
| ---------- | ------- | ---------------------------------------------------------------------------------------------------------------------- |
| NTF-AC-001 | Blocked | Closed in-app catalogue present; full initial email catalogue deferred.                                                |
| NTF-AC-002 | Passed  | Read, retry, import and link operations preserve source business events/state.                                         |
| NTF-AC-003 | Blocked | Adapter/schema/audience/facts gates pass; full cross-owner envelope catalogue incomplete.                              |
| NTF-AC-004 | Blocked | Stable event/intent/read/attempt links pass; shared external email intents deferred.                                   |
| NTF-AC-005 | Blocked | Supported in-app notices are mandatory; optional event/preference catalogue deferred.                                  |
| NTF-AC-006 | Blocked | Authentication challenge transport remains owner-controlled and outside this release.                                  |
| NTF-AC-007 | Blocked | Permission/security event adapters and common email delivery deferred.                                                 |
| NTF-AC-008 | Blocked | Scope loss tested; authoritative reassignment/removal-receipt event owner deferred.                                    |
| NTF-AC-009 | Blocked | Customer/Agent status safe rendering passes; full suspension/offboarding catalogue deferred.                           |
| NTF-AC-010 | Passed  | Mixed-receipt test separates savings/fee/tender without raw evidence.                                                  |
| NTF-AC-011 | Blocked | Slot/custody/reconciliation notice owners incomplete.                                                                  |
| NTF-AC-012 | Blocked | Submission/approval/reservation notices pass; posted payout contract unavailable.                                      |
| NTF-AC-013 | Blocked | Existing correction notices adapted; complete posted compensation evidence pending.                                    |
| NTF-AC-014 | Blocked | Statement/report artifact events deferred.                                                                             |
| NTF-AC-015 | Blocked | Supported exact audiences/grants pass; complete source catalogue incomplete.                                           |
| NTF-AC-016 | Passed  | Former scope disappears; latest unresolved withdrawal work routes once to replacement.                                 |
| NTF-AC-017 | Blocked | Revocation removes protected inbox access; full permission-event/queue/email scenario deferred.                        |
| NTF-AC-018 | Blocked | Invited own inbox retention passes; financial email allowlist remains owner work.                                      |
| NTF-AC-019 | Blocked | Inaccessible account inbox is denied; complete safe external lifecycle delivery deferred.                              |
| NTF-AC-020 | Blocked | v1 snapshot immutability/hash and unknown-version gates pass; template-version upgrade matrix pending.                 |
| NTF-AC-021 | Blocked | Exact integer NGN and timezone display implemented; email/Unicode/browser accessibility evidence pending.              |
| NTF-AC-022 | Passed  | Collection and plan source snapshot tests prove no dashboard recalculation.                                            |
| NTF-AC-023 | Blocked | Raw legacy payloads/unknown facts/snapshot corruption excluded; full catalogue fuzz/privacy review pending.            |
| NTF-AC-024 | Blocked | Mandatory in-app classification retained; destination/email/preferences matrix deferred.                               |
| NTF-AC-025 | Blocked | Optional preferences deferred.                                                                                         |
| NTF-AC-026 | Passed  | Scoped search/count/filter/pagination and other-recipient isolation pass.                                              |
| NTF-AC-027 | Passed  | Allowed pagination, bounded paired dates and cutoff-count behavior pass.                                               |
| NTF-AC-028 | Passed  | Versioned idempotent read/unread and signed current-page isolation pass.                                               |
| NTF-AC-029 | Passed  | Scoped internal links use current owner authorization; notice endpoints never approve.                                 |
| NTF-AC-030 | Blocked | Empty/no-match and proposal expired/superseded states implemented; authenticated stale-client behavior unverified.     |
| NTF-AC-031 | Blocked | Email provider/webhook finality deferred.                                                                              |
| NTF-AC-032 | Blocked | Unknown external provider outcome/reconciliation deferred.                                                             |
| NTF-AC-033 | Passed  | Source rollback, durable queue outage recovery and atomic in-app delivery failure tests pass.                          |
| NTF-AC-034 | Blocked | Durable rollback foundation passes; urgent suspension/outbox owner contract incomplete.                                |
| NTF-AC-035 | Blocked | Local duplicate capture/materialization/alias coalescing pass; external provider idempotency deferred.                 |
| NTF-AC-036 | Blocked | Provider callbacks deferred.                                                                                           |
| NTF-AC-037 | Blocked | Local 30/120-second eligibility tested; operational email jitter/retry policy deferred.                                |
| NTF-AC-038 | Blocked | Authentication retries remain owner-controlled.                                                                        |
| NTF-AC-039 | Blocked | Delivery-operation panels deferred; no new notification-management permission.                                         |
| NTF-AC-040 | Blocked | Local dead-letter attempts preserve source state; authorized resolution UI deferred.                                   |
| NTF-AC-041 | Blocked | Assignment/version gates pass; production race and external destination/preference matrix pending.                     |
| NTF-AC-042 | Passed  | Conflicting read versions and atomic page rollback preserve recipient isolation/content.                               |
| NTF-AC-043 | Blocked | Idempotent safe import/read preservation pass; full reconstruction/recovery contract pending.                          |
| NTF-AC-044 | Blocked | Recipient-only inbox enforced; separate operational/audit panels deferred.                                             |
| NTF-AC-045 | Passed  | Approved visibility expiry hides the notice and preserves source and notification records.                             |
| NTF-AC-046 | Blocked | Data-request deletion/masking policy deferred.                                                                         |
| NTF-AC-047 | Blocked | Responsive and labeled UI implemented; authenticated device/keyboard/screen-reader evidence pending.                   |
| NTF-AC-048 | Blocked | Shared email renderer deferred.                                                                                        |
| NTF-AC-049 | Passed  | Notice CTA opens current independently authorized owner screen; no direct mutation.                                    |
| NTF-AC-050 | Blocked | Available source audit references retained; complete canonical Module 14 integration pending.                          |
| NTF-AC-051 | Blocked | Safe dispatch failure category logging present; complete operational metrics/alerts deferred.                          |
| NTF-AC-052 | Blocked | Representative production load/SLO evidence pending.                                                                   |
| NTF-AC-053 | Blocked | Backup/PITR and restoration evidence pending.                                                                          |
| NTF-AC-054 | Blocked | Recipient/token/version boundaries pass; complete CSRF/webhook/provider-secret security evidence pending.              |
| NTF-AC-055 | Blocked | Unknown schema/template/source and changed snapshot gates pass; exhaustive owner/provider/privacy gate matrix pending. |
