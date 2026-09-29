# Module 06 — Thrift Plans and Savings Cycles

## Summary

Implement the daily thrift-plan core from [Module 06](../modules/06-thrift-plans-and-savings-cycles.md). The user approved the proposed daily-plan policies in that specification, selected `Africa/Lagos` as the persisted initial business timezone, and asked to gate actions that depend on the not-yet-implemented financial owners.

Agents create and manage plans for currently assigned Customers. Customers see their own plans; Admins have business-wide read access. The module persists agreed terms and expected contribution slots, but never records contributions, calculates balances from receipts, pays money, or treats estimates as actuals.

## Baseline and dependencies

- This checkpoint was written before the cash owner existed. The current committed baseline includes Module 07 cash collection, slot allocation, completion, and the later withdrawal, reporting, notification, and financial-period foundations. Noncash custody, payout execution, reversal compensation, and settlement/closure gates are still not accepted.
- Module 05 supplies immutable fee rules, fee snapshots, fee obligation services, audit events, and notification-intent conventions. The plan-fee selection catalogue is implemented; cycle snapshots and actual trigger consumption are not.
- Module 04 supplies Customer status, assignment history, eligibility, authorization guards, and profile scoping. The implemented cash collection path is usable for plan integration verification; it does not prove missing owner contracts or production readiness.
- Initial plans use daily slots, fixed NGN contribution amounts, one open cycle per Customer, and persisted `Africa/Lagos` timezone. No weekly/monthly/custom schedules, additional simultaneous cycles, or automatic renewal.

## Implementation tasks

| ID | Task | Requirements | Status |
| --- | --- | --- | --- |
| TPC-T01 | Add the business timezone and plan public-ID sequence; define the cycle, terms revision, contribution slot, lifecycle event, and operation contracts. | `TPC-FR-001`, `007`, `008`, `011`, `012` | Implemented |
| TPC-T02 | Add transactional cycle creation, immutable revision/slot history, atomic one-open-cycle enforcement, operation idempotency, and current assignment/Customer/Agent eligibility checks. | `TPC-FR-002`–`013`, `031` | Implemented |
| TPC-T03 | Implement safe pre-activity terms amendment, descriptive correction, pause/resume, zero-activity cancellation, and renewal from a verified Cancelled predecessor. | `TPC-FR-017`, `018`, `021`, `022`, `027`, `029`, `030` | Implemented |
| TPC-T04 | Integrate Module 05 fee options and immutable per-revision terms snapshots; define trigger-time basis assessment for future collection/withdrawal owners without posting from plan actions. | `TPC-FR-009`, `010`, `023`, `024`, `034`, `038` | Partially implemented; trigger-time assessment remains gated on its financial owner |
| TPC-T05 | Add scoped plan directories, details, schedule/terms previews, lifecycle confirmations, and responsive accessible Inertia pages. | `TPC-FR-002`, `032`, `033` | Implemented |
| TPC-T06 | Add append-only agreement/lifecycle audit and deduplicated post-commit notification intents using the current assignment and recipient scope. | `TPC-FR-036`, `037` | Implemented |
| TPC-T07 | Verify core behavior and record Module 06 acceptance evidence; keep financial-owner scenarios blocked until real integrations exist. | `TPC-FR-001`–`038`; `TPC-AC-001`–`051` | In progress; focused SQLite behavior, cash integration, and eight isolated MySQL races pass. The scenario record below identifies remaining verification and owner dependencies. |

## Interfaces and ownership boundaries

- Named routes support scoped list/detail, server preview, create, operation lookup, revise, pause, resume, cancel, and eligible renewal. Vue calls them through Wayfinder. Mutations include a stable operation reference and expected plan, terms, Customer, assignment, business-configuration, and fee-rule versions.
- Creation preview returns generated local-calendar dates, expected gross and fee estimates, current eligibility/capacity, fee source/version/basis/timing, and the attestation to reconfirm. Commit re-resolves all mutable inputs and returns a conflict if a version changed.
- Creation atomically stores the plan, active terms revision, N stable slots, fee snapshot linkage, audit evidence, operation result, and notification intents. The initial timezone is `Africa/Lagos`, persisted on Business and snapshotted per cycle. New cycles use current business configuration; existing schedules never move.
- A unique nullable open-Customer key plus a locked Customer row enforces one Active, Paused, or Completed cycle per Customer. Terminal cycles retain history and release capacity only after an authorized transition.
- Pre-activity financial/schedule amendments and cancellation require an authoritative never-used/zero-obligation gate. Missing owner status is unavailable and blocks the action. Any future financial writer must lock the cycle and recheck its current revision before posting.
- Slot progress, card states, actual contributions, available savings, completion, early/normal closure, settlement, post-closure correction, and renewal from Closed require Module 07 and the corresponding financial owners. Until they return complete versioned results, the UI displays unavailable states and hides or disables dependent actions; it never displays a guessed zero.
- Module 05 snapshots store immutable agreed fee terms. Percentage completion/withdrawal amounts are assessed only at their actual owner trigger using authoritative posted bases; preview estimates never become obligations or earnings.

## Acceptance mapping

| Acceptance scenarios | Primary tasks |
| --- | --- |
| `TPC-AC-001`–`019` | TPC-T01–T06 |
| `TPC-AC-020`–`029` | TPC-T04 and the implemented cash collection path; reversal and payout-dependent outcomes remain blocked |
| `TPC-AC-030`–`035` | TPC-T03–T05; financial values remain gated |
| `TPC-AC-036`–`046` | Module 04/07–10 owner contracts and TPC-T04; closure/settlement and reversal outcomes remain blocked |
| `TPC-AC-047`–`051` | TPC-T05–T07 with current permission, notification, and audit owners |

### Scenario-by-scenario verification record

Evidence at `c64e838` plus this verification branch: **P** = `tests/Feature/ThriftPlanCollectionDependencyTest.php` (7 tests / 62 assertions); **M** = `tests/Unit/ThriftPlanAndCollectionMySqlConcurrencyTest.php` against the guarded `saverapp_audit_testing` database (8 tests / 54 assertions); **C/N/R/L** = `CollectionTest.php`, `NotificationInboxTest.php`, `ReportTest.php`, and `CustomerLifecycleTest.php` respectively (combined 165 tests / 1,401 assertions). The branch-local Vite build passed and supplied the manifest for authenticated feature requests. These are automated contract checks, not an authenticated browser or production operations sign-off. **Verified** means the stated scenario is covered; **Partial** records a proven subset and its missing check; **Open** has no sufficient scenario exercise; **Blocked** requires an absent authoritative owner or policy. A Partial/Open/Blocked row is not release acceptance.

Current outcome count: **2 Verified, 31 Partial, 8 Open, 10 Blocked**. The 10 Blocked rows require missing owner contracts; the Partial and Open rows identify test and review work still needed before Module 06 acceptance.

| ID | Outcome | Evidence and remaining condition |
| --- | --- | --- |
| TPC-AC-001 | Partial | Request validation rejects unsupported fields; exercise the full unsupported-mode matrix through HTTP. |
| TPC-AC-002 | Partial | P proves direct Admin/Customer pause denial and authorized Agent success; add create, revise, and terminal HTTP calls. |
| TPC-AC-003 | Partial | L and N cover Customer scope in related views; prove plan search/count, ID, revision, and notice non-disclosure together. |
| TPC-AC-004 | Partial | C and M exercise Agent eligibility around cash; run the plan-specific onboarding, suspension, and temporary-lock matrix. |
| TPC-AC-005 | Open | Verify Invited and Active Customer plan creation without requiring Customer login activation. |
| TPC-AC-006 | Partial | L and C exercise status gates; verify every plan mutation against inactive/restricted/archived status and the settlement exception when its owner exists. |
| TPC-AC-007 | Partial | P and M establish one open plan; exercise Paused and Completed predecessors after the scheduled end and withdrawal. |
| TPC-AC-008 | Partial | M proves two concurrent create attempts commit at most one cycle; add distinct-Agent and renewal races. |
| TPC-AC-009 | Open | Exercise boundary, Unicode, precision, overflow, and mismatched relationship requests. |
| TPC-AC-010 | Verified | P proves 31 daily preview/stored slots from January 20 through February 19, with distinct local dates. |
| TPC-AC-011 | Partial | R preserves historical plan timezone; test leap/year/DST slot generation and later business timezone changes. |
| TPC-AC-012 | Open | Test backdated/too-far start and expected-date override rejection, plus future-start capacity. |
| TPC-AC-013 | Partial | P creates with an explicit no-fee rule; test absent fee, timezone, and unsupported rule combinations. |
| TPC-AC-014 | Partial | P proves a retired fee option conflicts after preview and needs a fresh selection; exercise business configuration changes too. |
| TPC-AC-015 | Partial | P creates with attestation; verify displayed terms and missing attestation through the request boundary. |
| TPC-AC-016 | Partial | P proves one plan, slots, one operation, and no receipt/ledger posting; assert fee/reservation/registration histories separately. |
| TPC-AC-017 | Partial | P injects a lifecycle-event write failure and proves rollback of plan, slots, terms, attempt, notice, and ledger; snapshot, slot, and audit fault points remain. |
| TPC-AC-018 | Verified | P proves same-key replay returns the original plan; changed-payload reuse conflicts without another plan. |
| TPC-AC-019 | Open | Reassign after commit, then request the old operation result as the former Agent. |
| TPC-AC-020 | Partial | C covers partial receipts and net funding; exercise ten-to-one and one-to-three slot counts explicitly. |
| TPC-AC-021 | Partial | C proves final net funding can complete a cycle; test final-date expiry with unpaid/partial slots and early advance funding. |
| TPC-AC-022 | Partial | C and M prove capacity and serial cash allocation; complete advance/catch-up and multi-day boundaries in the Module 07 acceptance matrix. |
| TPC-AC-023 | Partial | L and R preserve plan/history under status and timezone changes; verify dated blocked expectations without invented missed/paid slots. |
| TPC-AC-024 | Partial | M serializes Customer restriction with collection; test reassignment history and interruption classification. |
| TPC-AC-025 | Partial | P and M cover pause versioning and pause/receipt race; verify resume intervals, capacity, balance, and blocked Customer response. |
| TPC-AC-026 | Open | Resume after scheduled final date and verify original outstanding dates and eligible catch-up. |
| TPC-AC-027 | Partial | P rejects stale lifecycle action; exercise the full state/action matrix and Customer-side calls. |
| TPC-AC-028 | Partial | C verifies final-slot completion in the receipt transaction; fee event deduplication and payout absence need owner evidence. |
| TPC-AC-029 | Blocked | Approved reversal compensation and completion-shortfall contract are not available. |
| TPC-AC-030 | Partial | P verifies immutable old terms and superseded slots; run Paused-cycle amendment and reject funding against old slot IDs. |
| TPC-AC-031 | Partial | M proves competing revisions serialize; add receipt/reservation/fee-obligation versus revision races. |
| TPC-AC-032 | Partial | P proves posted cash retains activity, status, and superseded slots and rejects financial edits; reversal-history and descriptive-correction evidence remain. |
| TPC-AC-033 | Open | Verify exact ₦2,000 × 31 estimate labels and separate actual owner values in the authenticated UI. |
| TPC-AC-034 | Blocked | Complete trigger-time fee outcomes require approved fee and withdrawal bases; no estimate may substitute. |
| TPC-AC-035 | Partial | R fails safely when the ledger source is unavailable; verify plan detail and dependent confirmation under stale fee/ledger values. |
| TPC-AC-036 | Blocked | No complete cycle-attributed liability, reservation, fee, payout, correction, reconciliation, and attribution closure gate. |
| TPC-AC-037 | Blocked | Closure writer and versioned owner gates are absent, so the gate/closure race cannot be accepted. |
| TPC-AC-038 | Blocked | Fully settled closure and its no-posting guarantee require the missing settlement owner. |
| TPC-AC-039 | Blocked | Early termination needs the approved fee and settlement contracts. |
| TPC-AC-040 | Blocked | Early termination fee policy is unresolved; keep the action unavailable. |
| TPC-AC-041 | Partial | P proves unused cancellation releases capacity and allows a linked successor; prior reversed receipt and pending obligation cases remain. |
| TPC-AC-042 | Blocked | Restricted closure needs complete zero-financial owner gates; cancellation requires a separate restricted-status check. |
| TPC-AC-043 | Blocked | Approved closed-cycle correction and archive exception owner are absent. |
| TPC-AC-044 | Partial | P proves fresh successor ID/slots and retained predecessor; test fee-rule change and no registration/money carryover. |
| TPC-AC-045 | Partial | P permits only Cancelled predecessor and M protects open-cycle capacity; exercise all renewal denials and duplicate renewal race. |
| TPC-AC-046 | Partial | L and C cover assignment-sensitive operations; test plan form and notification job after reassignment. |
| TPC-AC-047 | Open | Run scoped search/filter/count/pagination and repeated-name lineage requests. |
| TPC-AC-048 | Open | Authenticated mobile, keyboard, assistive-technology, loading, empty, and error-state review is required. |
| TPC-AC-049 | Partial | N verifies immutable plan notice source and Invited ownership; induce delivery failure and retry under changed recipient scope. |
| TPC-AC-050 | Partial | P/M exercise durable lifecycle outcomes; verify audit masking, denial evidence, Customer redaction, and `audit.view` detail scope. |
| TPC-AC-051 | Blocked | Absent owner interfaces and unresolved authority/configuration policy must remain disabled; no new Admin grant is authorized. |

## Verification scenarios

- Deny unsupported frequencies/currency, invalid precision/ranges, backdated or over-365-day starts, protected fields, mismatched IDs, stale assignments, ineligible Agents, and non-Active Customer creation.
- Generate N local dates across month/year/leap boundaries; preserve dates after timezone changes, pause/resume, status changes, and reassignment.
- Race two creates/renewals for one Customer and verify at most one open cycle; replay matching operations and reject changed-payload reuse.
- Verify term revisions retain old snapshots and slots, post-activity financial edits fail, and cancellation fails after any activity or whenever an owner gate is unavailable.
- Verify all role scopes, safe unavailable states, no financial posting from preview/lifecycle actions, and queued notification failure does not repeat a mutation.
- Keep completion, settlement, correction, reconciliation, and closed-cycle renewal acceptance blocked until the authoritative owners exist.

## Assumptions and deferred decisions

- The Module 06 draft policies are approved as the implementation baseline: daily frequency, 1–366 slots, start date today through 365 days ahead, one open cycle, and financial-term locking after any activity.
- `Africa/Lagos` is the initial stored business timezone; Module 15 may later manage prospective changes.
- Zero-activity cancellation and renewal from a verified Cancelled predecessor are in this implementation. Settlement-dependent terminal actions are gated.
- No dependencies are added. Full financial acceptance remains blocked on Module 07 and the related financial owners, even after plan-core checks pass.

## Implementation status

The original core plan work is committed on `main`. This verification branch adds focused authorization, calendar, stale-fee, rollback, immutability, replay, and cancelled-cycle renewal assertions. It also fixes the plan detail eager-load callback type that made direct HTTP actions fail with 500, and makes a retired fee option conflict after preview rather than returning an ordinary field error. Its worktree-local Composer autoloader and Vite manifest were used for the recorded SQLite and isolated MySQL runs; the latter runs `migrate:fresh` only after checking for `saverapp_audit_testing`. No migration was run against the shared application database. Module 07 cash collection and completion have current automated integration evidence; closure, early termination, reversal compensation, and production cash readiness remain gated on their owning contracts and acceptance. TPC-T07 remains in progress while Partial/Open/Blocked scenarios above remain.
