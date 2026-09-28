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
| COL-AC-004 | Passed | `CollectionTest.php` covers MFA incomplete, temporarily locked Active session, Suspended/Deactivated accounts, Paused/Completed/Closed/Cancelled plans, and revoked-session preview/commit denial without posting. |
| COL-AC-005 | Blocked | Exact NGN parsing and endpoint rejection of zero, negative, extra decimals, cap+1, and unsupported currency pass; cumulative capacity above one tender cap remains unverified. |
| COL-AC-006 | Blocked | `CollectionTest.php` rejects transfer claims at the cash endpoint and missing custody mapping; complete valid custody evidence/reference contract remains unverified. |
| COL-AC-007 | Blocked | Timezone change and cross-zone dates unverified |
| COL-AC-008 | Blocked | Closed period and original-Agent late-date cases unverified |
| COL-AC-009 | Passed | `CollectionTest.php` split-tender test: ₦2,000 savings and ₦500 fee stay separate. |
| COL-AC-010 | Blocked | Fee/slot policy mutation case unverified |
| COL-AC-011 | Passed | `CollectionTest.php`: ₦3,000 against a ₦5,000 slot leaves ₦2,000 residual; a second ₦2,000 receipt makes it Paid. |
| COL-AC-012 | Passed | `CollectionTest.php`: one ₦6,000 receipt funds three ₦2,000 slots; receipt count remains one. |
| COL-AC-013 | Passed | `CollectionTest.php`: one ₦10,000 receipt funds five future slots and appears once in today's received totals. |
| COL-AC-014 | Passed | `CollectionTest.php`: suggestion completes the oldest partial slot first; an explicit valid override funds the chosen future date. |
| COL-AC-015 | Passed | `CollectionTest.php`: over-capacity, another Customer's slot, duplicate slot entries, and negative allocation fail without receipt, allocation, or posting. |
| COL-AC-016 | Passed | `CollectionTest.php`: final required slot completes the plan in the posting transaction; partial receipts and elapsed final date alone do not. |
| COL-AC-017 | Blocked | Pause/resume and correction state unverified |
| COL-AC-018 | Blocked | Complete dated card matrix and accessibility unverified |
| COL-AC-019 | Passed | `CollectionTest.php` paused-interval test: card shows blocked with no allocation. |
| COL-AC-020 | Passed | `CollectionTest.php`: reasoned versioned skip and miss append history and change card annotation only; no allocation/posting occurs and plan remains Active. |
| COL-AC-021 | Passed | `CollectionTest.php`: unauthorized, stale-version, future skip, Partial and Paid annotation attempts fail without another annotation or funding effect. |
| COL-AC-022 | Passed | `CollectionTest.php`: later cash makes a skipped slot Paid, while its original reason and the receipt's actual received date remain retained. |
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
| COL-AC-037 | Passed | `CollectionTest.php`: assigned Agent sees due and advance-covered rows; an unassigned Agent sees no rows and cannot open the Customer cash form. |
| COL-AC-038 | Passed | `CollectionTest.php`: yesterday's ₦2,000 advance covers today's due slot while today's ₦6,000 catch-up receipt is counted only in cash received; the two totals stay separate. |
| COL-AC-039 | Passed | `CollectionTest.php`: scoped 26-row pagination retains whole-filter totals; paid/pending/advance filters and Customer search preserve due totals, while a late-recorded receipt appears once on its received date and zero times in today's received cash. |
| COL-AC-040 | Blocked | File evidence and scanning contract unavailable |
| COL-AC-041 | Blocked | Evidence scope after reassignment unavailable |
| COL-AC-042 | Passed | `CollectionTest.php`: receipts enter actor/date/method batches; repeated midnight freeze preserves version and separate savings/fee totals. |
| COL-AC-043 | Passed | `CollectionTest.php`: Agent sees only own masked batch status and cannot confirm remittance or review reconciliation; no Agent evidence submission route is exposed. |
| COL-AC-044 | Passed | `CollectionTest.php`: a late same-date receipt after confirmed handoff and zero-variance reconciliation creates a linked open supplement while the original reconciled version, receipt and handoff counts stay unchanged. |
| COL-AC-045 | Passed | `CollectionTest.php`: permitted Admin confirms structured handoff once; Agent and baseline Admin are denied; replay adds no second remittance and Customer liability is unchanged. |
| COL-AC-046 | Blocked | Original-Agent partial handoff after reassignment unverified |
| COL-AC-047 | Blocked | Closure failure gates unverified |
| COL-AC-048 | Passed | `CollectionTest.php`: after a ₦2,000 receipt and ₦1,500 confirmed handoff, a ₦500 shortage opens an exception, retains ₦2,000 Customer liability and exactly ₦500 original Agent debt. |
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
| COL-AC-059 | Blocked | Dashboard, assigned Customer profile, daily list and card expose the guarded cash form. The form now announces preview readiness and keeps server errors visible after preview invalidation; authenticated Agent/Admin mobile, keyboard and screen-reader review remains unavailable. |
| COL-AC-060 | Blocked | Notification reassignment redaction unverified |
| COL-AC-061 | Blocked | Audit scope and immutability matrix unverified |
| COL-AC-062 | Blocked | Isolated MySQL load profile below meets server p95 targets at 10,000 Customers, 30 Agents, 20,000 plans and 2 million slots; restart/replay, authenticated accessibility, mobile network and concurrent-session profiles remain unverified. |
| COL-AC-063 | Blocked | Dependency outage matrix unverified |

Local server load measurement: isolated MySQL test `CollectionLoadProfileTest.php`, local PHP test client on macOS, in-process network, concurrency 1, 20 samples per operation. Dataset: 10,000 Customers, 30 Agents, 20,000 plans and 2 million slots. Workspace p95 **0.162 s** (target 3 s), search p95 **0.019 s** (target 1 s), durable receipt posting p95 **0.086 s** (target 2 s). All three server targets pass; browser device/network and concurrent-session targets were not measured. Raw result: `/private/tmp/saverapp-collection-load-profile.json`.

Current release evidence: affected collection, plan-dependency and Agent lifecycle feature tests **85 passed, 831 assertions** after the cash boundary changes; isolated MySQL plan/collection races **5 passed, 29 assertions**. The earlier wider run passed **866 tests, 41 skipped, 6,048 assertions**, but has not yet been repeated after these changes. Vue type check, build, Pint, scoped app-file PHPStan (**0 errors**), whitespace and route inspection pass. Repository-wide `npm run check` still reports formatting issues in 32 files, including existing documentation/page files; that is not release certification. The guarded load-profile test previously passed separately on isolated MySQL and is skipped in the normal suite; no collection query or posting path changed in this pass. The standard `php artisan test --compact` command previously exhausted its default 128 MB PHP limit during unrelated settings tests. Browser review reached the login page but had no authenticated Agent/Admin session; mobile, keyboard and screen-reader acceptance remains open. Keep `COLLECTIONS_ENABLED=false` and `COLLECTIONS_LOCAL_CERTIFIED=false` until every applicable local cash gate passes and an authorized Admin publishes both cash settings.
