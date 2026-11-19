# Module 18 — Customer Wallet (High-Level Design)

## Summary

Build the Customer wallet from [Module 18](../modules/18-customer-wallet.md) on the existing Module 05–10 financial foundations. The wallet is a **ledger subsidiary plus a reservation source**, not a new money store. It adds one liability account, a `source` on the existing withdrawal request, and one collection method. Everything is default-off behind the `wallet` and `collection_wallet` feature codes until finance signs the account mapping. Detailed schema, classes and signatures are in the [low-level design](./18-customer-wallet-lld.md).

## Design principles

1. **Reuse the withdrawal instrument.** `WithdrawalRequest` stays the single "money leaves a balance" record. A new `source` (`plan` | `wallet`) decides which balance is debited and which methods are allowed. Quote, fingerprint, attempt replay, reservation, review, expiry, holds, rails, notifications and audit are reused rather than rebuilt.
2. **Keep plan attribution exact.** Wallet money lives in its own account, `customer_wallet_liability_ngn`, so `WithdrawalBalanceService`'s per-plan sum check and every Module 06 closure gate keep their current meaning.
3. **One serialization point.** Every wallet debit takes the existing Customer row lock before reading the balance. This is the same lock that collections and withdrawals already use.
4. **Fail closed.** An unmapped account, a balance mismatch or a missing owner returns Unavailable or 503. It never shows zero and never guesses.
5. **Control where money leaves.** Admin review applies to wallet payouts, which send money outside the business. Plan→wallet transfers and wallet contributions are internal liability moves with fixed, agreed fees.

## Architecture

```text
                 ┌──────────────────────── Ledger (Module 10) ────────────────────────┐
                 │  customer_savings_liability_ngn (plan-dimensioned)                 │
                 │  customer_wallet_liability_ngn  (customer-dimensioned)   ← NEW     │
                 │  fee_income_ngn · other_deduction_destination_ngn                  │
                 │  business_cash_ngn · payout_clearing_ngn · business_bank_ngn       │
                 └────────────────────────────────────────────────────────────────────┘
                        ▲ post                    ▲ post                     ▲ post
  Plan completes ──(queued sweep)──┐
  Plan ──(Move to wallet)──► WalletTransferService ──► WalletPostingService ─┤
       Agent, posts at once     (source=plan, method=wallet)                  │
                                                                              │
  Wallet ──(Pay out)──► WithdrawalService (source=wallet) ──► Admin review ──► Cash / Bank / Offline rail
                         wallet reservation RW                                 └► WithdrawalPostingService (wallet debit)

  Wallet ──(Collection method "Wallet")──► CollectionService ──► CollectionLedgerService (wallet_contribution)
                                             wallet batch (no custody)

  Reads: WalletBalanceService ─► WalletController (scoped page), StatementPreviewService, ReportReadService,
         DashboardReadService, MetricDefinitionService
```

## Posting patterns

| Event                 | `event_type`                           | Entries                                                                                                            |
| --------------------- | -------------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| Plan→wallet transfer  | `plan_to_wallet_transfer`              | Dr savings liability G (plan) / Cr wallet liability P / Cr fee income F / Cr other deduction D, with G = P + F + D |
| Wallet payout         | `wallet_payout`                        | Dr wallet liability G / Cr rail payout account G (business cash, payout clearing or business bank)                 |
| Wallet contribution   | `wallet_contribution`                  | Dr wallet liability T / Cr savings liability S (plan) / Cr fee income Fc, with T = S + Fc                          |
| Transfer reversal     | `plan_to_wallet_transfer_compensation` | Mirror of the transfer, gated on AW ≥ P                                                                            |
| Contribution reversal | `wallet_contribution_compensation`     | Mirror of the contribution, plus allocation release                                                                |
| Wallet payout return  | existing return events                 | Existing bank-return and cash-recovery compensation, crediting the wallet instead of plan savings                  |

## Module integration map

| Module | Change                                                                                                                                                                                                                                                    |
| ------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 04     | Archival and offboarding status also check W = 0 and no live wallet reservation.                                                                                                                                                                          |
| 05     | No new fee policy. The existing withdrawal and completion fee quote applies to the transfer, and the collection fee components apply to the wallet contribution.                                                                                          |
| 06     | Completion records a sweep; `PlanWalletSweepService` moves the savings automatically. `PlanPageClosureService::afterWithdrawalPosted` runs for a Full transfer. Settlement gates are unchanged, because the plan's liability leaves through the transfer. |
| 07     | New method `wallet`: `CollectionReceiptMethod`, `CollectionMethodCatalogue`, `CollectionLedgerService`, `CollectionBatchPosition`, wallet batch identity.                                                                                                 |
| 08     | `source` on requests and reservations. Source-aware preview, submit and assert. `WithdrawalMethodRegistry` limits methods by source. One live wallet payout per Customer.                                                                                 |
| 09     | `ReversalCapabilityRegistry` routes the two new event types to new owners. Payout returns credit the wallet.                                                                                                                                              |
| 10     | New account and class. `CollectionReadService::position` counts plan reservations only. New projection types. Statement wallet section. Integrity check.                                                                                                  |
| 11/12  | Metrics `wallet_received` and `wallet_liability`. Wallet column in the contributions, collection-performance and reconciliation reports.                                                                                                                  |
| 13     | Queued notifications for transfer, contribution and reversal. Payout notices reuse the withdrawal intents.                                                                                                                                                |
| 15     | Feature codes `wallet` and `collection_wallet` with readiness and release evidence.                                                                                                                                                                       |

## Implementation tasks

| ID      | Task                                                                                                                                               | Requirements               | Status      |
| ------- | -------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------- | ----------- |
| WAL-T01 | Add the wallet ledger account and class, `WalletBalanceService`, and the integrity check. Exclude wallet reservations from plan availability.      | `WAL-FR-001`–`003`         | Not started |
| WAL-T02 | Add `source` to withdrawal requests and reservations, make the plan nullable for the wallet source, and add the one-live-wallet-payout constraint. | `WAL-FR-006`, `007`, `015` | Not started |
| WAL-T03 | Plan→wallet transfer: preview, submit, posting, fee settlement, and the Full-transfer closure hook.                                                | `WAL-FR-004`, `005`, `015` | Not started |
| WAL-T04 | Wallet payout: source-aware preview, submit and review; rails post a wallet debit; method restriction by source.                                   | `WAL-FR-006`, `007`        | Not started |
| WAL-T05 | Wallet collection method: preview, record, ledger, wallet batch and batch position.                                                                | `WAL-FR-008`, `009`        | Not started |
| WAL-T06 | Reversal owners for transfer and contribution; payout returns credit the wallet.                                                                   | `WAL-FR-011`               | Not started |
| WAL-T07 | Read models: projections, statement section, reports, metrics and dashboard.                                                                       | `WAL-FR-012`               | Not started |
| WAL-T08 | UI: wallet page, Move to wallet, wallet payout create, Wallet method on collection create, review badge.                                           | `WAL-FR-010`, `012`        | Not started |
| WAL-T09 | Notifications and audit families.                                                                                                                  | `WAL-FR-013`               | Not started |
| WAL-T11 | Automatic transfer on completion: sweep record, queued job, retry command, supersede and 24-hour alert.                                            | `WAL-FR-016`               | Not started |
| WAL-T10 | Feature codes, readiness, cutover behaviour, MySQL concurrency proof and acceptance evidence.                                                      | `WAL-FR-010`, `014`, `015` | Not started |

Order: T01 → T02 → (T03, T05 in parallel) → T11 → T04 → T06 → T07 → T08 → T09 → T10. T03 must land before T04 can be tested end to end, because the wallet has no other credit source.

## Interfaces and defaults

- **Automatic transfer:** `PlanWalletSweepService` reuses the End-of-cycle transfer path with a system executor. Retry every five minutes through `wallets:sweep-completed`; alert after 24 hours pending.
- **Transfer:** reuses `PreviewWithdrawalRequest` and `StoreWithdrawalRequest` fields (type, amount, attempt reference, fingerprint, versions) with the method fixed to `wallet`. The quote expires after `withdrawals.quote_minutes` (10).
- **Wallet payout:** the same request fields with `source=wallet`, no `thrift_plan_id` and no fee row. Review window `withdrawals.review_days` (7). Rails and limits are unchanged.
- **Wallet contribution:** the existing collection preview and store with `method=wallet`. No evidence, method version or settlement fields.
- **Flags:** `config/wallet.php` has `enabled` and `certified` (both false), following `config/withdrawals.php`. Business features `wallet` and `collection_wallet` follow `BusinessSettings::ensureFeature` and readiness rules.
- **Responses:** an unavailable owner returns 503. A stale version returns 409. A scope violation returns 403 or 404. Insufficient AW is a 422 field error on `amount`.

## Rollout and cutover

1. Ship the schema and code with every flag off. Seed the wallet account with `mapping_status = pending`.
2. Finance signs the mapping, and the account becomes `mapped`.
3. Publish `wallet`. Plan-source requests now allow only the Wallet method. Live external plan requests finish on their rail.
4. Publish `collection_wallet` once wallet balances exist and the reconciliation team has accepted the wallet batch view.
5. Rollback: unpublish the feature codes. Balances and history stay readable and live payouts finish. No data is deleted.

## Risks and mitigations

| Risk                                                                 | Mitigation                                                                                                                                   |
| -------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| Wallet reservations reduce plan availability by accident             | `CollectionReadService::position` and `WithdrawalBalanceService` filter `source = plan`. Tested in WAL-AC-001.                               |
| Race between a wallet payout and a wallet contribution               | Both take the Customer lock before reading AW. MySQL race test (WAL-AC-014).                                                                 |
| Transfer reversal after the wallet is spent                          | AW gate in the reversal owner. Blocked state, never negative (WAL-AC-010, 015).                                                              |
| Reports keep calling all tender "cash"                               | WAL-T07 adds explicit wallet columns and fixes the dashboard `cash_received` label for non-cash methods.                                     |
| Sweep blocks a receipt or double-posts                               | The sweep is recorded in the completion transaction but posts in its own job; one sweep per completion event, unique ledger key. WAL-AC-016. |
| Sweeps stuck behind a blocker                                        | Named blocker, five-minute retry, 24-hour Agent alert and Admin exceptions view. WAL-AC-017.                                                 |
| Code that assumes `withdrawal_requests.thrift_plan_id` is never null | LLD lists every reader. PHPStan and the focused suites cover them.                                                                           |

## Verification and release gates

- Focused Pest suites per task (listed in the LLD). PHPStan, Pint, `vue-tsc`, the production build and route inspection.
- Isolated MySQL concurrency tests on the `saverapp_audit_testing` database for WAL-AC-014 and 015.
- Release gates in Module 18 §15: finance mapping sign-off and per-rail gates unchanged. Transfer without Admin review was confirmed on 19 November 2026.
- After the feature tests pass, the user runs the complete suite with `php artisan test --compact`.

## Acceptance matrix

| Scenario   | Task(s)           | Status | Evidence | Open clause or blocker |
| ---------- | ----------------- | ------ | -------- | ---------------------- |
| WAL-AC-001 | WAL-T01           | Open   |          | Not implemented        |
| WAL-AC-002 | WAL-T01           | Open   |          | Not implemented        |
| WAL-AC-003 | WAL-T03           | Open   |          | Not implemented        |
| WAL-AC-004 | WAL-T03           | Open   |          | Not implemented        |
| WAL-AC-005 | WAL-T02, T04, T10 | Open   |          | Not implemented        |
| WAL-AC-006 | WAL-T04           | Open   |          | Not implemented        |
| WAL-AC-007 | WAL-T05           | Open   |          | Not implemented        |
| WAL-AC-008 | WAL-T05, T07      | Open   |          | Not implemented        |
| WAL-AC-009 | WAL-T08, T10      | Open   |          | Not implemented        |
| WAL-AC-010 | WAL-T06           | Open   |          | Not implemented        |
| WAL-AC-011 | WAL-T07, T08      | Open   |          | Not implemented        |
| WAL-AC-012 | WAL-T09           | Open   |          | Not implemented        |
| WAL-AC-013 | WAL-T10           | Open   |          | Not implemented        |
| WAL-AC-014 | WAL-T02, T10      | Open   |          | Not implemented        |
| WAL-AC-015 | WAL-T06, T10      | Open   |          | Not implemented        |
| WAL-AC-016 | WAL-T11, T10      | Open   |          | Not implemented        |
| WAL-AC-017 | WAL-T11           | Open   |          | Not implemented        |
