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
| COL-T07 | Verify invariants and record each applicable Module 07 acceptance scenario as Passed, Failed, or Blocked in the task register. | `COL-AC-001`–`063` | In progress: focused Pest coverage added; full matrix and Module 06 concurrency verification remain |

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

The cash routes and batch freezer are guarded by `config/collections.php`, defaulting to disabled. Enable `COLLECTIONS_ENABLED` only after the outstanding Module 06 concurrency verification and Module 07 acceptance matrix pass. The local MySQL database was migrated successfully after repairing partial MySQL DDL; focused tests use the isolated test database. The verification evidence to date is 19 passing focused tests with 144 assertions in `tests/Feature/CollectionTest.php`, scoped PHPStan on new collection classes with zero errors, Vue type checking, the production asset build, Pint, and route generation. Noncash evidence, withdrawal execution, non-shortage financial exception resolution, and reversal compensation remain behind their owner modules.
