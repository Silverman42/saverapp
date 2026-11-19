# Module 18 — Customer Wallet (Low-Level Design)

## Summary

Concrete schema, classes, method contracts, locking, idempotency and tests for the [Module 18 specification](../modules/18-customer-wallet.md) and its [high-level design](./18-customer-wallet.md). Every existing class and method named here was checked against the code on 19 November 2026. Re-check it before you start a task, because Modules 05–09 are still moving.

## 1. Schema changes

Create each migration with `php artisan make:migration --no-interaction`. Wallet data is append-only, like the existing financial tables: no updates or deletes on posted rows, and MySQL triggers where the sibling tables already have them.

### 1.1 Ledger account (WAL-T01)

Data migration that seeds one row in `ledger_accounts`:

| Column           | Value                             |
| ---------------- | --------------------------------- |
| `code`           | `customer_wallet_liability_ngn`   |
| `account_class`  | `customer_wallet_liability`       |
| `normal_balance` | `credit`                          |
| `currency`       | `NGN`                             |
| `mapping_status` | `pending` (finance sets `mapped`) |
| `version`        | 1                                 |

`ledger_entries.customer_profile_id` is already nullable and indexed with `ledger_account_id`. Wallet entries always set it and always leave `thrift_plan_id` null.

### 1.2 `withdrawal_requests` (WAL-T02)

| Change                                                                                                                        | Reason                                                                                                                 |
| ----------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| Add `source` string(16), not null, default `plan`, indexed with `customer_profile_id`                                         | Says which balance is debited.                                                                                         |
| Make `thrift_plan_id` nullable                                                                                                | A wallet payout has no plan.                                                                                           |
| Add `live_wallet_customer_profile_id` nullable, unique, FK `customer_profiles`                                                | One live wallet payout per Customer. Set on submit and cleared on any terminal state, mirroring `live_thrift_plan_id`. |
| Make `fee_snapshot_id` nullable (wallet source only)                                                                          | A wallet payout has no plan fee snapshot.                                                                              |
| Add `initiated_by` string(16), default `agent` (`agent` \| `system`); make `submitted_by_user_id` nullable only when `system` | The automatic transfer has no submitting user. `initiating_agent_profile_id` still records the assigned Agent.         |
| Add check (MySQL) `source = 'plan' AND thrift_plan_id IS NOT NULL OR source = 'wallet' AND thrift_plan_id IS NULL`            | Source integrity.                                                                                                      |

Existing rows backfill to `source = plan` through the column default.

### 1.3 `plan_wallet_sweeps` (new, WAL-T11)

| Column                  | Type                                                              |
| ----------------------- | ----------------------------------------------------------------- |
| `id`                    | id                                                                |
| `thrift_plan_id`        | FK `thrift_plans`                                                 |
| `customer_profile_id`   | FK `customer_profiles`                                            |
| `completion_event_id`   | FK `plan_lifecycle_events`, **unique** (one sweep per completion) |
| `status`                | `pending` \| `posted` \| `superseded`                             |
| `blocker`               | string(40), nullable                                              |
| `attempts`              | unsigned int, default 0                                           |
| `next_attempt_at`       | timestamp, indexed with `status`                                  |
| `alerted_at`            | timestamp, nullable                                               |
| `withdrawal_request_id` | FK `withdrawal_requests`, nullable, unique                        |
| `version`, timestamps   |                                                                   |

Rows are never deleted. Status moves only from `pending` to `posted` or `superseded`.

### 1.4 `withdrawal_reservations` (WAL-T02)

Add `source` string(16), not null, default `plan`, indexed with `(customer_profile_id, status)`. `thrift_plan_id` is already nullable. The amount and delete triggers are unchanged.

### 1.5 Collections (WAL-T05)

No column changes. A wallet receipt uses:

- `method = 'wallet'`, `method_label = 'Wallet'`
- `custody_account_code = 'customer_wallet_liability_ngn'`
- `collection_method_version_id`, `collection_payment_evidence_id`, `collection_evidence_review_id` and `method_reference` all null.

The batch has `method_identity = 'wallet'` and `custody_account_code = 'customer_wallet_liability_ngn'`. The unique key `(agent, date, timezone, method_identity, revision)` gives one wallet batch per Agent per day. `collection_receipts.collection_batch_id` stays non-nullable.

## 2. Enums and config

- `App\Enums\LedgerAccountCode`: add `CustomerWalletLiability = 'customer_wallet_liability_ngn'`.
- `App\Enums\LedgerAccountClass`: add `CustomerWalletLiability = 'customer_wallet_liability'`.
- New `App\Enums\WithdrawalSource`: `Plan = 'plan'`, `Wallet = 'wallet'`, with `debitAccount(): LedgerAccountCode`.
- `config/wallet.php`: `enabled` (false), `certified` (false), `collection_enabled` (false). Same pattern as `config/withdrawals.php`.
- `BusinessSettingsCatalogue`: feature codes `wallet` and `collection_wallet`. Add them to `BusinessSettingsReadiness` and to `FinancialReleaseEvidenceService::CAPABILITIES`.

## 3. Read side (WAL-T01)

### 3.1 `App\Services\WalletBalanceService` (new)

```php
/** @return array{liability_kobo: int, reservations_kobo: int, available_kobo: int, as_of: string} */
public function position(CustomerProfile $customer, bool $forUpdate = false): array;

/** @param list<int> $customerIds  @return array<int, array{liability_kobo: int, reservations_kobo: int, available_kobo: int}> */
public function positions(array $customerIds): array;

public function scopedLiability(Builder $customers): int;
```

- Mirrors `CollectionReadService::position()`. With `$forUpdate` it requires an open transaction, locks the Customer row, then the account row, then the entries.
- Throws `ServiceUnavailableHttpException` when the account is missing, not `mapped`, not NGN, or has the wrong class or normal balance.
- Throws `RuntimeException` (integrity) when W < 0, AW < 0, or a wallet entry has a non-null `thrift_plan_id`.
- Reservations: `withdrawal_reservations` where `source = 'wallet'` and `status = 'live'`.

### 3.2 Existing readers to change

| Class / method                                                                                              | Change                                                                                                          |
| ----------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| `CollectionReadService::position()` / `positions()` / `scopedPosition()`                                    | Add `where('source', 'plan')` to the reservation query. Otherwise wallet reservations reduce plan availability. |
| `WithdrawalBalanceService::position()` / `positions()`                                                      | Same reservation filter. Liability already reads only `customer_savings_liability_ngn`.                         |
| `CollectionReadService::archivalStatus()`, `archivalSavingsStatus()`, `WithdrawalService::archivalStatus()` | Also block on W ≠ 0 or a live wallet reservation.                                                               |
| `StatementPreviewService::preview()`                                                                        | Add a `wallet` section (opening W, credits, debits, closing W) and total liability = L + W.                     |
| `LedgerTransactionProjectionService`                                                                        | Project the three new event types as `wallet_transfer`, `wallet_payout` and `wallet_contribution`.              |
| `CollectionReadService` event allow-list (≈ line 655)                                                       | Add `wallet_contribution`.                                                                                      |
| `PlatformIntegrity`                                                                                         | New check `wallet_postings`: every wallet group balances and the per-Customer W is ≥ 0.                         |

## 4. Plan→wallet transfer (WAL-T03)

### 4.1 `App\Services\WalletTransferService` (new)

```php
/** @return array{type: string, gross_kobo: int, fee_kobo: int, deduction_kobo: int, wallet_credit_kobo: int, cycle_available_kobo: int, wallet: array, preview_fingerprint: string, expires_at: string} */
public function preview(User $actor, CustomerProfile $customer, array $data, bool $forUpdate = false): array;

public function submit(User $actor, CustomerProfile $customer, array $data): WithdrawalRequest;
```

`preview()` delegates to `WithdrawalService::preview()` with `method = 'wallet'`, so cycle availability, type rules, fee snapshot, deduction contract and fingerprint are reused. It adds `wallet_credit_kobo = net` and the current wallet position.

`submit()` runs inside `PlatformGuard::transaction('financial', ..., attempts: 3)`:

1. Replay by `attempt_reference` through `withdrawal_attempts`. If the payload hash matches, return the original record; if it differs, return 409.
2. `CustomerActionAuthorizationGuard::lockAndAuthorize()`, which locks actor, Customer and assignment.
3. Re-run `preview(..., forUpdate: true)` and compare the fingerprint and the Customer, assignment, plan and business versions.
4. Insert the reservation (`source = plan`, `status = live`) and the request (`source = plan`, `method = wallet`, `state = posted`, `submitted_at = approved_at = terminal_at = now`, `reviewed_by_user_id = null`).
5. `WalletPostingService::postTransfer()` (§6), which consumes the reservation with a guarded `UPDATE … WHERE status = 'live'`.
6. `LedgerTransactionProjectionService::projectWithdrawal()`.
7. `PlanPageClosureService::afterWithdrawalPosted($request, $actor)`. The existing Full rule closes the plan or sets it closure-pending.
8. Write `withdrawal_events` (`submitted`, `posted`), audit `wallet_transfer.posted`, and notification intents.

### 4.2 Changes to `WithdrawalService`

- `preview()`: accept `source`. For `plan`, when `config('wallet.enabled')` and the `wallet` feature are on, the method must be `wallet`. For `wallet`, see §5.
- `submit()`: refuse a plan-source `wallet` method (the transfer goes through `WalletTransferService`).
- `assertReservationAndBalance()`: for wallet source, check the reservation against `WalletBalanceService::position(forUpdate: true)` instead of the plan cycle.

### 4.3 `App\Services\PlanWalletSweepService` (new, WAL-T11)

```php
public function recordForCompletion(ThriftPlan $plan, PlanLifecycleEvent $completion): PlanWalletSweep;

public function run(PlanWalletSweep $sweep): PlanWalletSweep;

public function runDue(int $limit = 100): int;
```

- `recordForCompletion()` is called from `CollectionService::updatePlanCompletionAndFee()` right after it writes the `completed` lifecycle event, only when the `wallet` feature is active. It inserts a `pending` sweep (`next_attempt_at = now`) and dispatches `RunPlanWalletSweep` with `afterCommit()`. It never posts money, so it cannot fail the receipt.
- `run()` executes inside `PlatformGuard::transaction('financial', ..., attempts: 3)`:
    1. Lock the sweep row and return early when it is not `pending`.
    2. Lock the Customer and the plan. When the plan is not Completed or its latest `completed` event is not `completion_event_id`, set `superseded`.
    3. Check the gates in Module 18 §6.4 step 2. On failure, set `blocker`, increment `attempts` and set `next_attempt_at = now + 5 minutes`.
    4. When cycle available is 0 (an Agent already transferred), set `posted` with a null `withdrawal_request_id`.
    5. Otherwise call `WalletTransferService::submitSystem($plan, $sweep)`, which builds the End-of-cycle preview with `forUpdate`, creates the request with `initiated_by = system`, the current assignment's Agent and `submitted_by_user_id = null`, and posts it (§4.1 steps 4–8, with idempotency key `plan-wallet-sweep-{sweep_id}`). Then set `posted` and link the request.
- `runDue()` is called by the `wallets:sweep-completed` command, scheduled every five minutes with `withoutOverlapping()->onOneServer()` in `routes/console.php`, next to `plans:close-pending`. It also sends the 24-hour alert once per sweep (`alerted_at`).
- `App\Jobs\RunPlanWalletSweep`: `tries = 1`, `WithoutOverlapping('plan-wallet-sweep-{id}')`; it calls `run()`. A failure leaves the sweep pending for the command.
- Plan detail reads the latest sweep for its status badge.

## 5. Wallet payout (WAL-T04)

| Class                                                                             | Change                                                                                                                                                                         |
| --------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `WithdrawalMethodRegistry::availableMethods()`                                    | Take a `WithdrawalSource`. Plan → `['wallet']` when the wallet is enabled, otherwise today's methods. Wallet → today's enabled cash, bank and offline methods.                 |
| `WithdrawalService::preview()`                                                    | Wallet source: no plan and no type (always `partial`-shaped). G ≤ AW. F = D = 0, so P = G.                                                                                     |
| `WithdrawalService::submit()`                                                     | Wallet source: insert a `source = wallet` reservation, set `live_wallet_customer_profile_id`, state `pending_review`.                                                          |
| `WithdrawalService::decide()` / `expireDue()`                                     | Clear `live_wallet_customer_profile_id` with `live_thrift_plan_id` on every terminal transition.                                                                               |
| `WithdrawalPostingService::post()`                                                | Debit `$withdrawal->source->debitAccount()` instead of always savings liability. Skip the fee-obligation step for the wallet source. Leave `thrift_plan_id` null on the group. |
| `WithdrawalPayoutSource`                                                          | No change (it maps the rail to its payout account).                                                                                                                            |
| `CashExecutionService`, `BankPayoutService`, `OfflinePayoutService`               | No logic change. Their call to `PlanPageClosureService::afterWithdrawalPosted()` must no-op for the wallet source; add the guard inside that method.                           |
| `WithdrawalReversalOwner::compensate()`, `BankPayoutLedger`, `CashRecoveryLedger` | Credit `source->debitAccount()` when restoring the Customer on a return or reversal.                                                                                           |

Every reader that dereferences `withdrawal_requests.thrift_plan_id` must handle null for the wallet source. Check each of these: `WithdrawalController`, `PlanFeeHistoryReadService`, `PlanSettlementService`, `PlanWithdrawalFeePosition`, `FinancialWorkflowReadService`, `ReportReadService`, `DashboardReadService`, `NotificationPipeline`, `NotificationCatalogue`, `ReversalService`. Most of them filter by plan, so a null plan simply excludes wallet payouts, which is correct for plan views.

## 6. `App\Services\WalletPostingService` (new)

```php
public function postTransfer(WithdrawalRequest $transfer, User $actor): LedgerPostingGroup;

public function postContribution(CollectionReceipt $receipt, User $actor): LedgerPostingGroup;
```

- It is a sibling of `WithdrawalPostingService::post()` and reuses its `account()` lookup, `FinancialPeriodService::assertOpen()`, posting reference generation and fee-obligation settlement (transfer only).
- Group fields: `source_type` is `withdrawal` or `collection_receipt`, `source_id`, `event_type`, `customer_profile_id`, `thrift_plan_id` (plan side), and `actor_user_id`.
- Entries carry `customer_profile_id`. Only the plan-savings and fee lines carry `thrift_plan_id`.
- Before insert it asserts that debits equal credits and that every amount is a positive integer.

| Event                     | Idempotency key                                              | Source                           |
| ------------------------- | ------------------------------------------------------------ | -------------------------------- |
| `plan_to_wallet_transfer` | `plan-wallet-transfer-{withdrawal_id}`                       | `withdrawal`, request id         |
| `wallet_payout`           | Each rail's existing key, for example `bank-withdrawal-{id}` | `withdrawal`, request id         |
| `wallet_contribution`     | `wallet-contribution-{receipt_reference}`                    | `collection_receipt`, receipt id |

## 7. Wallet contribution (WAL-T05)

| Class / method                                       | Change                                                                                                                                                                                                              |
| ---------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `CollectionReceiptMethod::preview()`                 | New `wallet` branch: requires `collection_wallet` feature, Customer `canTransact()`, and `T ≤ WalletBalanceService::position()['available_kobo']`. It returns custody `CustomerWalletLiability` and no evidence.    |
| `CollectionReceiptMethod::assertReceipt()`           | Accepts `wallet` only with the wallet custody account and null evidence fields.                                                                                                                                     |
| `CollectionMethodCatalogue::available()`             | Lists `wallet` when it is enabled. `publish()` refuses `wallet` (it is not a versioned external method).                                                                                                            |
| `CollectionService::record()`                        | For `wallet`, re-reads the position with `forUpdate: true` after the Customer lock and before allocation. It posts through `WalletPostingService::postContribution()`. `updatePlanCompletionAndFee()` is unchanged. |
| `CollectionService::currentBatch()`                  | Method identity `wallet`.                                                                                                                                                                                           |
| `CollectionLedgerService::assertSavingsAccounts()`   | Allow the wallet account as the "custody" side for event `wallet_contribution` only.                                                                                                                                |
| `CollectionBatchPosition::read()` / `expectedKobo()` | New wallet branch: expected physical custody 0, `uncollected` 0, status reconciled-by-construction. Remittance and settlement actions are hidden.                                                                   |
| `CollectionReversalOwner` partly-collected path      | Stays cash-only (`supportsReceivedAmount()` already checks `method === 'cash'`).                                                                                                                                    |

Lock order for `record()`: actor → Customer (through the authorization guard) → plan → batch → wallet account → savings account → entries. This is the same order as today, with the wallet account inserted before the savings account.

## 8. Reversals (WAL-T06)

- `ReversalCapabilityRegistry::resolve()`: route `plan_to_wallet_transfer` to `WalletTransferReversalOwner` and `wallet_contribution` to `WalletContributionReversalOwner`, behind `config('wallet.enabled')`.
- `WalletTransferReversalOwner implements ReversalOwnerContract`:
    - `preview()` locks the Customer, reads the wallet with `forUpdate`, requires AW ≥ P, reads the plan state, and returns dependencies `{wallet, plan, fee_obligation}`.
    - `compensate()` posts the mirror group `plan_to_wallet_transfer_compensation` and restores the fee obligation through `PlanFeeCorrectionService`.
    - A closed plan uses the existing post-closure correction exception.
- `WalletContributionReversalOwner`: follows `CollectionReversalOwner::compensate()` for allocation release and completed→paused, but posts `Cr wallet` instead of `Cr unapplied_funds`. A replacement receipt is not offered, because the money is already back in the wallet.

## 9. HTTP, routes and pages (WAL-T08)

| Route (named)                                                           | Controller method                  | Notes                                                                                                |
| ----------------------------------------------------------------------- | ---------------------------------- | ---------------------------------------------------------------------------------------------------- |
| `wallets.show` `GET customers/{customer}/wallet`                        | `WalletController@show`            | Agent (assigned), Admin, and Customer (own) scope through `ResourceScopeService`. Paginated history. |
| `wallets.transfers.preview` `POST plans/{plan}/wallet-transfer/preview` | `WalletTransferController@preview` | `managePlan` ability; JSON quote.                                                                    |
| `wallets.transfers.store` `POST plans/{plan}/wallet-transfer`           | `WalletTransferController@store`   | Redirects to the plan with a flash message.                                                          |
| `withdrawals.create`, `.preview`, `.store`                              | Existing `WithdrawalController`    | Accept `source=wallet`. `create()` passes the wallet position instead of plan positions.             |

- Form requests: `PreviewWalletTransferRequest` and `StoreWalletTransferRequest`, mirroring the existing withdrawal requests. Create them with `php artisan make:request`.
- Vue (Inertia v3, Wayfinder imports from `@/actions` and `@/routes`):
    - `resources/js/pages/wallets/Show.vue`
    - a Move-to-wallet dialog on `plans/Show.vue`
    - `withdrawals/Create.vue` with a wallet source variant
    - a `wallet` option in `collections/Create.vue` (TS union) and `Methods.vue`
    - a source badge on `withdrawals/Index.vue`
- Follow `.ai/rules/pages.md` and add a sidebar entry under Customers.

## 10. Reports, metrics and dashboard (WAL-T07)

- `MetricDefinitionService`: add `wallet_received` (wallet contribution tender, drill-down `contributions`) and `wallet_liability` (W total). Bump `VERSION` by one (it is 12 in the current working tree). Correct the `cash_received` description and its use in `DashboardReadService::receipts()` so it counts `method = 'cash'` only.
- `ReportReadService`:
    - contributions and collection-performance query: add `SUM(CASE WHEN receipts.method = 'wallet' …)` and the money column;
    - `batchReconciliationSpec()`: wallet custody branch with zero expected;
    - new column on the Customer balances report: wallet.
- `DashboardReadService::custody()`: unchanged (the wallet is not custody). Add a wallet liability tile from `WalletBalanceService::scopedLiability()`.

## 11. Notifications and audit (WAL-T09)

- Notification intents `wallet_transfer_posted`, `wallet_contribution_posted` and `wallet_reversal_posted` in `NotificationCatalogue`. Delivery classes implement `ShouldQueue` and use `Queueable`.
- `DeliverCollectionNotificationIntent` label map: `wallet` → `Wallet`.
- Intent `wallet_sweep_pending` to the assigned Agent after 24 hours, and a row on the Admin exceptions view.
- `AuditCatalogue`: `wallet_transfer` family (with `initiated_by`). The existing `withdrawal` and `collection` families get a `source` or `method` attribute.

## 12. Concurrency matrix

| Race                                         | Serialization                                               | Expected                                                                   |
| -------------------------------------------- | ----------------------------------------------------------- | -------------------------------------------------------------------------- |
| Wallet payout submit vs wallet contribution  | Customer row lock, then wallet position `forUpdate`         | The second one sees the reduced AW; at most one passes when they overdraw. |
| Two wallet contributions                     | Same                                                        | Same.                                                                      |
| Transfer vs plan collection on the same plan | Existing Customer and plan locks                            | Unchanged Module 08 behaviour.                                             |
| Transfer reversal vs wallet payout           | Customer lock in both `preview(forUpdate)` paths            | AW never below zero.                                                       |
| Duplicate transfer submit (same attempt)     | `withdrawal_attempts` unique key + ledger idempotency key   | One request, one posting group.                                            |
| Sweep vs manual End-of-cycle transfer        | Customer then plan lock in both paths                       | One posts; the other sees cycle available 0 and posts nothing.             |
| Sweep vs receipt reversal un-completing plan | Plan lock; sweep re-checks the completion event             | The sweep is superseded, or the reversal waits for the transfer reversal.  |
| Duplicate sweep job                          | Sweep row lock + unique ledger key `plan-wallet-sweep-{id}` | One posting group.                                                         |
| Two live wallet payouts                      | `live_wallet_customer_profile_id` unique                    | The second gets 409.                                                       |

## 13. Tests (Pest)

Create each with `php artisan make:test --pest`. Use factories and existing states.

| File                                    | Covers                                                                                                                                                                                      |
| --------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `WalletBalanceTest`                     | WAL-AC-001, 002: position math, unmapped account returns 503, plan availability ignores wallet reservations.                                                                                |
| `WalletTransferTest`                    | WAL-AC-003, 004: partial with fee, full closure and closure-pending, end-of-cycle state rule, stale fingerprint, replay.                                                                    |
| `WalletPayoutTest`                      | WAL-AC-005, 006: method restriction by source, approve → cash, bank (fake provider) and offline posting, reject, cancel and expire release, second live payout refused.                     |
| `WalletCollectionTest`                  | WAL-AC-007, 008: AW limit, fee components, plan completion, wallet batch position.                                                                                                          |
| `WalletReversalTest`                    | WAL-AC-010: transfer reversal ok or blocked, contribution reversal releases allocations, bank return credits the wallet.                                                                    |
| `WalletAuthorizationTest`               | WAL-AC-009, 011: status matrix, archival gate, scope denial.                                                                                                                                |
| `WalletCutoverTest`                     | WAL-AC-013: in-flight plan payout finishes; flag off keeps balances readable.                                                                                                               |
| `WalletNotificationAuditTest`           | WAL-AC-012.                                                                                                                                                                                 |
| `ReportTest`, `DashboardTest` additions | WAL-AC-011 totals and the `cash_received` correction.                                                                                                                                       |
| `PlanWalletSweepTest`                   | WAL-AC-016, 017: completion records and posts one sweep, replayed job, blocked → pending → posted after lift, superseded after reversal, 24-hour alert, pre-activation completions skipped. |
| `WalletMySqlConcurrencyTest`            | WAL-AC-014, 015, 016 (sweep vs manual transfer), on the isolated `saverapp_audit_testing` database (skipped on SQLite).                                                                     |

Run the narrowest files first, then Pint (`vendor/bin/pint --dirty --format agent`), PHPStan, `vue-tsc` and the build. Then ask the user to run `php artisan test --compact`.
