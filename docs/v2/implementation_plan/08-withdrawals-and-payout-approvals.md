# Module 08 — Withdrawals and Payout Approvals

## Summary

Build the request and review workflow from [Module 08](../modules/08-withdrawals-and-payout-approvals.md) on the existing Module 06–07 financial foundations. Payout execution and posting remain blocked. No method is enabled in production, so submission and reservation acquisition remain disabled until an approved method can actually pay.

## Implementation tasks

| ID      | Task                                                                                                                                                            | Requirements                            | Status                                                     |
| ------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------- | ---------------------------------------------------------- |
| WDL-T01 | Add cycle-attributed authoritative savings and a shared Customer lock for balance-sensitive writes; fail closed on unattributed entries.                        | `WDL-FR-004`, `006`, `021`              | Implemented; production-database concurrency proof pending |
| WDL-T02 | Persist immutable request terms, live gross reservations, attempt bindings, decisions, holds, deadlines, and event history.                                     | `WDL-FR-005`, `007`–`014`, `020`, `029` | Implemented for unexecuted requests                        |
| WDL-T03 | Quote from the cycle fee snapshot and implement scoped preview, submission, cancellation, one-Admin review, safe revocation, expiry, and restriction holds.     | `WDL-FR-001`–`014`, `020`–`021`         | Implemented behind method gate; broader acceptance pending |
| WDL-T04 | Add scoped Agent, Admin, and Customer request views, safe notifications, and append-only audit.                                                                 | `WDL-FR-022`–`024`, `026`               | Implemented; accessibility and delivery acceptance pending |
| WDL-T05 | Keep payout methods, execution, and posting gated until method authority, custody, evidence, finality, recovery, return, and accounting contracts are approved. | `WDL-FR-015`–`019`, `028`               | Blocked by owner contracts                                 |
| WDL-T06 | Verify amount, authority, concurrency, idempotency, lifecycle, reservation, notification, and unavailable-owner behavior; record release evidence.              | `WDL-AC-001`–`044`                      | In progress                                                |

## Interfaces and defaults

- Named routes provide scoped list/detail, create/preview/submit, attempt lookup, approve/reject/cancel/revoke. Server commit checks the preview fingerprint, stable operation UUID, current versions, attestation, and confirmation.
- One request consumes one cycle; gross debit `G` is reserved, fee `F` comes from the immutable plan snapshot, initial other deduction `D` is zero, and net payout `P = G - F` must be positive. One live request per cycle. Quotes expire after ten minutes.
- Pending review expires seven days after submission. Approved or definitively failed requests expire seven days after approval or the latest failed attempt. Restricted holds preserve the reservation and pause expiry, with at least 24 hours to review after lift.
- Current eligible assigned Agents submit/cancel Pending requests. One fresh-authenticated Admin with `withdrawals.review` approves/rejects and may revoke safely before execution. Customer reads own history only. Approval never means payment.
- The method registry is disabled by default. Without a complete enabled payout contract, preview explains unavailability and submission fails without creating a request or reservation. Test fixtures may enable an isolated method to verify the staged workflow.

## Verification and release gates

- Run focused Pest tests, static analysis, Pint, Vue type/build checks, and route inspection. Verify Module 06–07 database/concurrency prerequisites before enabling any financial write.
- Keep payout execution, posting, unknown-outcome recovery, bank/cash evidence, return/reversal, and dependent acceptance scenarios Blocked. No generic mark-paid action or guessed balance is permitted.

## Current checkpoint

The migration, staged request/review services, scoped Inertia pages, hold integration, expiry command, audit, and notification intents are written. The production method registry rejects every method. Focused withdrawal and collection tests pass in the isolated test database; Vue type checking, production build, route inspection, and scoped PHPStan pass. The Module 08 migration was applied to the local MySQL database as batch 18. Module 06–07 concurrency proof, Module 08 full acceptance matrix, and every payout-execution contract remain outstanding before any live submission can be enabled.
