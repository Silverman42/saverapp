# Module 07 — Collections, Digital Thrift Card, and Reconciliation

## Summary

Implement [Module 07](../modules/07-collections-thrift-card-and-reconciliation.md) in stages. The first release enables eligible Agents to record cash received for assigned Customers, fund plan slots, and show authoritative thrift cards and savings balances. The next stage adds cash batches, remittance, reconciliation, and exceptions. Bank transfer, POS, withdrawal-dependent actions, and reversal posting remain unavailable until their required owner contracts are verified.

Complete Module 06's pending database and concurrency verification before enabling collections. Its current plan, slot, assignment, fee-snapshot, and lifecycle work is the integration baseline. The local MySQL schema migrations now run successfully; concurrency and acceptance verification remain pending.

## Implementation tasks

| ID | Task | Requirements | Status |
| --- | --- | --- | --- |
| COL-T01 | Extend the controlled ledger for balanced cash savings, external fee tender, savings-fee application, and confirmed remittance postings. Map and version the reviewed NGN Agent receivable, Customer savings liability, fee income, and business cash accounts. Add authoritative reservation reads for future Module 08 writes. | `COL-015`–`018`, `COL-040` | Implemented, verification in progress |
| COL-T02 | Persist immutable receipts, tender components, allocation rows, operation attempts, captured dates/timezones, posting references, audit, and durable notification intents. Add server preview, exact validation, idempotent commit/result lookup, and atomic fee and plan-completion integration. | `COL-001`–`011`, `COL-018`–`021`, `COL-038`–`039` | Implemented, verification in progress |
| COL-T03 | Derive the digital thrift card and daily Agent workspace from current slots, live funding, annotations, eligibility, and dated activity. Keep plan coverage, received money, posting activity, fees, liability, reservations, and available savings distinct. | `COL-012`–`014`, `COL-022`–`023`, `COL-037` | In progress: card, blocked-history overlay and date-filtered paginated due-slot workspace implemented; aggregate/load and accessibility checks remain |
| COL-T04 | Create immutable Agent/date/method cash batches, midnight freezes and linked late supplements. Add Admin counted-handoff confirmation, partial remittance allocation, zero-variance reconciliation, and versioned exception history. | `COL-025`–`031`, `COL-036`, `COL-039` | In progress: cash batches, handoffs, zero-variance reviews, shortage resolution, overage investigation and reopening implemented; non-shortage financial resolution awaits its owning correction contract |
| COL-T05 | Add scoped receipt, card, workspace, batch, remittance, and reconciliation pages and routes through Wayfinder, including unknown-outcome lookup and unavailable states. | `COL-020`, `COL-022`–`023`, `COL-037` | Implemented, accessibility review pending |
| COL-T06 | Add reversal request and review records only when Module 09 provides atomic compensation and Module 08 supplies reservation checks. Do not expose initiation or approval while a request cannot be safely resolved. | `COL-032`–`036` | Blocked by Modules 08–09 and dependent fee/custody contracts |
| COL-T07 | Verify invariants and record each applicable Module 07 acceptance scenario as Passed, Failed, or Blocked in the task register. | `COL-AC-001`–`063` | In progress: scenario matrix below; focused cash and isolated MySQL races pass; authenticated review and several cash clauses remain open |

## Interfaces and ownership boundaries

- Use scoped named routes for collection preview/commit, original-attempt lookup, receipts, cards, daily work, attendance annotations, batches, remittances, and reconciliation. Vue calls backend routes through Wayfinder. Customers read only their own records; Agents act only for currently assigned eligible Customers; Admins may inspect collections but cannot record them.
- A client creates one stable operation key for each confirmed receipt. The server binds it to actor, Customer, plan, normalized tender and allocations. The same key and payload returns the original authorized result; changed-payload reuse conflicts. Lost responses resolve through the original key after current-scope checks. No offline financial success or locally paid card is shown.
- A cash receipt may split tender between savings and named external fee obligations. Every savings kobo funds an eligible residual slot in one plan. Collection-triggered fee assessment/application and plan completion commit with the receipt or the transaction fails; unaffordable full fee application remains an explicit outstanding obligation under Module 05. No preview estimate becomes posted money.
- The ledger accepts only closed owner commands, never client journal lines. Cash savings debits the original Agent receivable and credits Customer savings liability; external fee tender credits fee income through Module 05; confirmed remittance debits business cash and credits that same Agent receivable without changing Customer liability. Required mapping, fee, reservation, or audit outages block financial writes.
- The reservation read store is authoritative even before Module 08 creates reservations: an intact empty store yields zero live reservations, while a missing or inconsistent store yields unavailable. Module 07 cannot create, release, or consume withdrawal reservations. Available savings is posted Customer liability minus live gross reservations, read consistently at commit.
- Capture the business timezone/version on receipts and batches, initially `Africa/Lagos`; use each plan's immutable timezone for slot due-day and advance status. Accept today and the prior 30 local calendar dates, with a reason for past receipts. There is no period close/reopen workflow in this release. Keep actual received date distinct from current UTC commit time. Freeze cash batches at the next local midnight; late receipts create linked supplements rather than changing a frozen version.
- An authorized `reconciliation.manage` Admin confirms counted cash using original Agent, amount, receiving location, date, unique handoff reference, and attestation. Optional files are deferred until private storage and malware scanning are available. Confirmation posts custody transfer once; exception or batch status changes never change Customer credit or forgive Agent debt.
- Noncash methods remain disabled until their account mappings, actual-receipt verification, reference uniqueness, and protected evidence path are approved. Reversal approval remains disabled until Module 09 can compensate the complete receipt and dependent bundle safely, including fee, remittance, plan-correction, and reservation effects.

## Verification and acceptance

- Test role, session, assignment, Customer and plan eligibility at commit, including reassignment and suspension races. Verify exact NGN arithmetic, the 999,999,999,999-kobo single-tender cap, 30-day lookback, stale previews, capacity conflicts, and fee-component separation.
- Test partial, multiple-day, advance, catch-up, and explicit override allocations; plan completion only after all required slots are funded; card status and totals across pauses, timezones, pagination, and late posting.
- Test replay and lost-response recovery, concurrent slot funding, reservation reads, balanced postings, fee/audit failure rollback, and post-commit notification failure without repeat posting.
- Test midnight batch freeze, late supplements, partial cash remittance, original-Agent attribution after reassignment, zero-variance closure, and shortage/overage exceptions without Customer balance changes.
- Run the affected Pest tests, PHP formatting and static checks, Vue type/build checks, and route inspection. Record dependency-gated acceptance scenarios as Blocked rather than Passed; request the complete suite after the focused feature tests pass.

## Approved defaults and gates

- Adopt the Module 07 draft policies for exact integer-kobo NGN amounts, the single-tender cap, oldest-unfilled allocation suggestion with valid explicit override, no unallocated overpayment, zero unexplained reconciliation variance, and full-only reversal policy.
- Cash is the first enabled method. The minimum ledger and authoritative reservation-read contracts are built now. Counted structured handoff is the required initial remittance proof; scanned evidence is not required for cash confirmation.
- Module 06 verification and all first-release financial invariants must pass before cash collection is enabled. Withdrawal execution, noncash methods, reversal posting, and scenarios requiring their owner contracts remain gated.

## Current implementation checkpoint

Cash remains disabled locally. The default-off `COLLECTIONS_LOCAL_CERTIFIED` setting can make `collection_cash` and `collections` editable only in local/testing after certification; production readiness remains unavailable. Both published settings and `COLLECTIONS_ENABLED=true` are required for cash writes. Module 06 plan/lifecycle and competing Customer operations passed isolated MySQL checks, but full release acceptance and authenticated review remain open. Noncash evidence, withdrawal execution, non-shortage financial exception resolution, and reversal compensation remain behind their owner modules.

### COL-T07 acceptance record

A result is **Passed** only when every clause of that scenario has direct evidence. **Blocked** means a required case, owner contract, authenticated review, or release gate remains open; no scenario is recorded as Failed. Test references below are under `tests/Feature/CollectionTest.php`. **Recommendation: do not enable local cash yet.** Applicable cash clauses and authenticated UI/performance review remain unverified, so this record does not certify the release.

| Scenario | Result | Evidence or open gate |
| --- | --- | --- |
| COL-AC-001 | Passed | `CollectionTest.php`: Customer and Admin preview/commit calls are denied with no receipt, slot, or ledger effect. |
| COL-AC-002 | Passed | `CollectionTest.php`: assigned Agent posts; another Agent and former Agent after reassignment are denied, including a previously reviewed attempt. |
| COL-AC-003 | Passed | `CollectionTest.php`: Active Invited Customer accepts cash; Inactive, Restricted and Archived Customers are denied. |
| COL-AC-004 | Blocked | Agent MFA, lock, and lifecycle cases unverified |
| COL-AC-005 | Blocked | Full amount boundary and cumulative capacity cases unverified |
| COL-AC-006 | Blocked | Custody mapping promise cases unverified |
| COL-AC-007 | Blocked | Timezone change and cross-zone dates unverified |
| COL-AC-008 | Blocked | Closed period and original-Agent late-date cases unverified |
| COL-AC-009 | Passed | `CollectionTest.php` split-tender test: ₦2,000 savings and ₦500 fee stay separate. |
| COL-AC-010 | Blocked | Fee/slot policy mutation case unverified |
| COL-AC-011 | Passed | `CollectionTest.php`: ₦3,000 against a ₦5,000 slot leaves ₦2,000 residual; a second ₦2,000 receipt makes it Paid. |
| COL-AC-012 | Passed | `CollectionTest.php`: one ₦6,000 receipt funds three ₦2,000 slots; receipt count remains one. |
| COL-AC-013 | Passed | `CollectionTest.php`: one ₦10,000 receipt funds five future slots and appears once in today's received totals. |
| COL-AC-014 | Passed | `CollectionTest.php`: suggestion completes the oldest partial slot first; an explicit valid override funds the chosen future date. |
| COL-AC-015 | Blocked | Cross-Customer and negative allocation cases unverified |
| COL-AC-016 | Passed | `CollectionTest.php`: final required slot completes the plan in the posting transaction; partial receipts and elapsed final date alone do not. |
| COL-AC-017 | Blocked | Pause/resume and correction state unverified |
| COL-AC-018 | Blocked | Complete dated card matrix and accessibility unverified |
| COL-AC-019 | Passed | `CollectionTest.php` paused-interval test: card shows blocked with no allocation. |
| COL-AC-020 | Blocked | Versioned annotation has no funding effect; skip noncompletion remains unverified. |
| COL-AC-021 | Blocked | Paid/partial/future, unauthorized, and stale annotation denials unverified. |
| COL-AC-022 | Blocked | Catch-up annotation and receipt-date history unverified. |
| COL-AC-023 | Blocked | Posted payout contract unavailable |
| COL-AC-024 | Passed | `CollectionTest.php`: cash savings posts equal Agent-receivable debit and Customer-liability credit; split fee tender remains separate from savings. |
| COL-AC-025 | Blocked | Noncash method contract unavailable |
| COL-AC-026 | Blocked | Reservation consumption depends on Module 08 |
| COL-AC-027 | Blocked | Unaffordable fee application case unverified |
| COL-AC-028 | Passed | `CollectionTest.php`: injected audit and fee-assessment faults leave no receipt, allocation, ledger, fee entry, batch or notice. |
| COL-AC-029 | Blocked | Required dependency outage matrix unverified |
| COL-AC-030 | Blocked | Changed-key payload and reversal-key clauses unverified |
| COL-AC-031 | Blocked | Two independent receipts and noncash reference clauses unverified |
| COL-AC-032 | Passed | `CollectionTest.php`: original key resolves one posted receipt; ended assignment denies lookup without another posting. |
| COL-AC-033 | Blocked | Offline submission UI unverified |
| COL-AC-034 | Blocked | Isolated MySQL race proves capacity is not exceeded and both exact-fit attempts post after stale review is refreshed; simultaneous commits from one unchanged review remain unverified. |
| COL-AC-035 | Blocked | Assignment/hold/pause/archival race matrix unverified |
| COL-AC-036 | Blocked | Reservation/reversal execution depends on Modules 08–09 |
| COL-AC-037 | Blocked | Advance-covered scoped rows and denial unverified |
| COL-AC-038 | Blocked | Advance/catch-up simultaneous totals unverified |
| COL-AC-039 | Blocked | Late-date filtered totals and complete filter matrix unverified |
| COL-AC-040 | Blocked | File evidence and scanning contract unavailable |
| COL-AC-041 | Blocked | Evidence scope after reassignment unavailable |
| COL-AC-042 | Passed | `CollectionTest.php`: receipts enter actor/date/method batches; repeated midnight freeze preserves version and separate savings/fee totals. |
| COL-AC-043 | Passed | `CollectionTest.php`: Agent sees only own masked batch status and cannot confirm remittance or review reconciliation; no Agent evidence submission route is exposed. |
| COL-AC-044 | Blocked | Supplement links to frozen revision; prior reconciled evidence immutability unverified. |
| COL-AC-045 | Passed | `CollectionTest.php`: permitted Admin confirms structured handoff once; Agent and baseline Admin are denied; replay adds no second remittance and Customer liability is unchanged. |
| COL-AC-046 | Blocked | Original-Agent partial handoff after reassignment unverified |
| COL-AC-047 | Blocked | Closure failure gates unverified |
| COL-AC-048 | Blocked | Shortage retains Customer liability and opens exception; exact Agent debt assertion remains. |
| COL-AC-049 | Blocked | Missing-transfer investigation contract unavailable |
| COL-AC-050 | Blocked | Evidence-backed resolution contract incomplete |
| COL-AC-051 | Blocked | Reversal compensation owner contract unavailable |
| COL-AC-052 | Blocked | Reversal compensation owner contract unavailable |
| COL-AC-053 | Blocked | Reversal compensation owner contract unavailable |
| COL-AC-054 | Blocked | Reversal compensation owner contract unavailable |
| COL-AC-055 | Blocked | Reversal compensation owner contract unavailable |
| COL-AC-056 | Blocked | Reversal compensation owner contract unavailable |
| COL-AC-057 | Blocked | Offboarding reconciliation integration unverified |
| COL-AC-058 | Blocked | Pending correction archival contract unavailable |
| COL-AC-059 | Blocked | Dashboard, assigned Customer profile and card now expose the guarded cash form; authenticated Agent/Admin mobile, keyboard and screen-reader review remains unavailable. |
| COL-AC-060 | Blocked | Notification reassignment redaction unverified |
| COL-AC-061 | Blocked | Audit scope and immutability matrix unverified |
| COL-AC-062 | Blocked | Isolated MySQL load profile below meets server p95 targets at 10,000 Customers, 30 Agents, 20,000 plans and 2 million slots; restart/replay, authenticated accessibility, mobile network and concurrent-session profiles remain unverified. |
| COL-AC-063 | Blocked | Dependency outage matrix unverified |

Local server load measurement: isolated MySQL test `CollectionLoadProfileTest.php`, local PHP test client on macOS, in-process network, concurrency 1, 20 samples per operation. Dataset: 10,000 Customers, 30 Agents, 20,000 plans and 2 million slots. Workspace p95 **0.162 s** (target 3 s), search p95 **0.019 s** (target 1 s), durable receipt posting p95 **0.086 s** (target 2 s). All three server targets pass; browser device/network and concurrent-session targets were not measured. Raw result: `/private/tmp/saverapp-collection-load-profile.json`.

Current release evidence: focused collection, settings and plan-dependency feature tests **90 passed, 633 assertions**; isolated MySQL plan/collection races **5 passed, 29 assertions**; complete Pest suite with a 512 MB PHP limit **866 passed, 41 skipped, 6,048 assertions**; scoped PHPStan **0 errors**; Pint, Vue type check, build and collection route inspection **pass**. The guarded load-profile test passes separately on isolated MySQL and is skipped in the normal suite. The standard `php artisan test --compact` command previously exhausted its default 128 MB PHP limit during unrelated settings tests; `php -d memory_limit=512M vendor/bin/pest --compact` passed. Authenticated Agent/Admin mobile, keyboard and screen-reader review and the blocked cash scenarios above remain open. Keep `COLLECTIONS_ENABLED=false` and `COLLECTIONS_LOCAL_CERTIFIED=false` until every applicable local cash gate passes and an authorized Admin publishes both cash settings.
