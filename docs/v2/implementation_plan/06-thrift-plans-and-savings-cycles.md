# Module 06 — Thrift Plans and Savings Cycles

## Summary

Implement the daily thrift-plan core from [Module 06](../modules/06-thrift-plans-and-savings-cycles.md). The user approved the proposed daily-plan policies in that specification, selected `Africa/Lagos` as the persisted initial business timezone, and asked to gate actions that depend on the not-yet-implemented financial owners.

Agents create and manage plans for currently assigned Customers. Customers see their own plans; Admins have business-wide read access. The module persists agreed terms and expected contribution slots, but never records contributions, calculates balances from receipts, pays money, or treats estimates as actuals.

## Baseline and dependencies

- Laravel 13.32, MySQL, Inertia Laravel 3.3, Vue 3, Wayfinder 0.1.21, Tailwind CSS 4, and Pest 4.7 are installed.
- Module 05 supplies immutable fee rules, fee snapshots, fee obligation services, audit events, and notification-intent conventions. The plan-fee selection catalogue is implemented; cycle snapshots and actual trigger consumption are not.
- Module 04 supplies Customer status, assignment history, eligibility, authorization guards, and profile scoping. Module 07 collections/allocations/reconciliation and the withdrawal, reversal, reporting, and business-configuration owners are not implemented.
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
| TPC-T07 | Verify core behavior and record Module 06 acceptance evidence; keep financial-owner scenarios blocked until real integrations exist. | `TPC-FR-001`–`038`; `TPC-AC-001`–`051` | In progress; local MySQL schema migration now passes, while database behavior and concurrency scenarios remain unverified |

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
| `TPC-AC-020`–`029` | TPC-T04 and future Module 07/withdrawal/reversal integrations; blocked until available |
| `TPC-AC-030`–`035` | TPC-T03–T05; financial values remain gated |
| `TPC-AC-036`–`046` | Future Module 04/07–10 owner contracts and TPC-T04; blocked until authoritative gates exist |
| `TPC-AC-047`–`051` | TPC-T05–T07 with current permission, notification, and audit owners |

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

The core plan work is on branch `codex/module-06-thrift-plans-and-savings-cycles`. `npm run types:check`, `npm run build`, `vendor/bin/pint --dirty --format agent`, PHP syntax checks, and `php artisan route:list --path=plans --except-vendor` pass. No application migration was run against the shared database. Database-backed acceptance and concurrency scenarios still need to be exercised; collection, withdrawal, completion, closure, and settlement actions remain gated on their owning modules.
