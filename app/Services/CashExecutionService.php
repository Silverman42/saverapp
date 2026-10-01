<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Enums\FeeObligationEntryType;
use App\Enums\FeeRuleModel;
use App\Enums\FeeRuleTiming;
use App\Enums\LedgerAccountClass;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CashExecution;
use App\Models\CashRecovery;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\FeeObligationEntry;
use App\Models\FeeSnapshot;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use App\Models\WithdrawalEvent;
use App\Models\WithdrawalRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CashExecutionService
{
    public function __construct(private AuthorizationService $authorization, private FreshAuthenticationService $freshAuthentication) {}

    public function start(User $actor, WithdrawalRequest $request, string $reference, int $version, string $evidence, Request $httpRequest): CashExecution
    {
        return app(PlatformGuard::class)->transaction('financial', function () use ($actor, $request, $reference, $version, $evidence, $httpRequest): CashExecution {
            $actor = $this->executor($actor, $httpRequest);
            $customer = CustomerProfile::query()->whereKey($request->customer_profile_id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('view', $customer);
            $withdrawal = WithdrawalRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', json_encode([$actor->id, $withdrawal->id, $version, $evidence], JSON_THROW_ON_ERROR));
            $existing = CashExecution::query()->where('execution_reference', $reference)->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals($existing->start_payload_hash, $hash)) {
                    throw new ConflictHttpException('This execution reference belongs to another instruction.');
                }

                return $existing;
            }
            if ($withdrawal->version !== $version || ! in_array($withdrawal->state, ['approved', 'payment_failed'], true)
                || $withdrawal->held || $withdrawal->deadline_at->isPast()
                || ! $customer->operational_status->allowsWithdrawalOfExistingFunds()) {
                throw new ConflictHttpException('This withdrawal cannot start a cash payment.');
            }
            $method = app(WithdrawalMethodRegistry::class)->resolve($customer->id, $withdrawal->method, $withdrawal->destination_reference);
            if ($method['version'] !== $withdrawal->method_version) {
                throw new ConflictHttpException('The approved cash method version changed.');
            }
            app(WithdrawalService::class)->assertReservationAndBalance($withdrawal, $customer);
            $timezone = BusinessProfile::current()->timezone;
            app(FinancialPeriodService::class)->assertOpen(now($timezone)->toDateString(), $timezone, true);
            $cash = $this->account(LedgerAccountCode::BusinessCash, LedgerAccountClass::Asset, LedgerEntrySide::Debit);
            $this->account(LedgerAccountCode::CustomerSavingsLiability, LedgerAccountClass::CustomerSavingsLiability, LedgerEntrySide::Credit);
            if ($withdrawal->fee_amount_kobo > 0) {
                $this->account(LedgerAccountCode::FeeIncome, LedgerAccountClass::FeeIncome, LedgerEntrySide::Credit);
            }
            $heldCash = app(FinancialCashPosition::class)->reservedCashKobo();
            if ($this->cashBalance($cash) - (int) $heldCash < $withdrawal->net_amount_kobo) {
                throw new ConflictHttpException('Verified business cash does not cover this payment.');
            }
            $recipient = User::query()->whereKey($customer->user_id)->lockForUpdate()->firstOrFail();
            if ($recipient->user_type !== UserType::Customer || $recipient->account_state->value !== 'active') {
                throw new ConflictHttpException('The Customer must have an active account to confirm receipt.');
            }
            $execution = CashExecution::create([
                'execution_reference' => $reference, 'withdrawal_request_id' => $withdrawal->id,
                'live_withdrawal_request_id' => $withdrawal->id, 'executor_user_id' => $actor->id,
                'recipient_user_id' => $recipient->id, 'amount_kobo' => $withdrawal->net_amount_kobo,
                'method_version' => $withdrawal->method_version, 'cash_mapping_version' => $cash->version,
                'status' => 'processing', 'start_payload_hash' => $hash, 'custody_evidence' => $evidence,
            ]);
            $this->transition($withdrawal, 'payout_processing', 'cash_started', $actor, $execution);

            return $execution;
        }, attempts: 3);
    }

    public function recordHandoff(User $actor, CashExecution $reference, string $evidence, Request $httpRequest): CashExecution
    {
        return app(PlatformGuard::class)->transaction('financial', function () use ($actor, $reference, $evidence, $httpRequest): CashExecution {
            $actor = $this->executor($actor, $httpRequest);
            [$withdrawal, $execution] = $this->lock($reference);
            Gate::forUser($actor)->authorize('view', $withdrawal->customerProfile);
            if ($execution->executor_user_id !== $actor->id) {
                throw new AuthorizationException('Only the bound custodian can attest this handoff.');
            }
            if ($execution->handoff_at !== null) {
                if ($execution->handoff_evidence !== $evidence) {
                    throw new ConflictHttpException('The recorded handoff evidence cannot be replaced.');
                }

                return $execution;
            }
            if ($execution->status !== 'processing' || $withdrawal->state !== 'payout_processing') {
                throw new ConflictHttpException('This attempt is no longer awaiting a handoff record.');
            }
            $execution->update(['status' => 'outcome_unknown', 'handoff_evidence' => $evidence, 'handoff_at' => now()]);
            $this->transition($withdrawal, 'outcome_unknown', 'cash_handoff_recorded', $actor, $execution);

            return $execution;
        }, attempts: 3);
    }

    public function confirmReceipt(User $actor, CashExecution $reference): CashExecution
    {
        return app(PlatformGuard::class)->transaction('financial', function () use ($actor, $reference): CashExecution {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            [$withdrawal, $execution] = $this->lock($reference);
            Gate::forUser($actor)->authorize('view', $withdrawal->customerProfile);
            if ($actor->user_type !== UserType::Customer || $execution->recipient_user_id !== $actor->id
                || $actor->account_state->value !== 'active') {
                throw new AuthorizationException('Only the bound Customer can acknowledge receipt.');
            }
            if ($execution->status === 'posted') {
                return $execution;
            }
            if ($execution->status !== 'outcome_unknown' || $execution->handoff_at === null || $withdrawal->state !== 'outcome_unknown') {
                throw new ConflictHttpException('The custodian has not recorded this handoff.');
            }
            if (CashRecovery::query()->where('cash_execution_id', $execution->id)->exists()) {
                throw new ConflictHttpException('Recovery evidence owns this attempt; resolve its full disposition before posting a payment.');
            }
            app(CashMethodCatalogue::class)->version('withdrawal', $execution->method_version);
            app(WithdrawalService::class)->assertReservationAndBalance($withdrawal, $withdrawal->customerProfile);
            $cash = $this->account(LedgerAccountCode::BusinessCash, LedgerAccountClass::Asset, LedgerEntrySide::Debit);
            if ($cash->version !== $execution->cash_mapping_version || $this->cashBalance($cash) < $execution->amount_kobo) {
                throw new ConflictHttpException('The execution cash mapping or backing requires recovery.');
            }
            $group = $this->postWithdrawal($withdrawal, $execution, $actor, $cash);
            $changed = DB::table('withdrawal_reservations')->where('id', $withdrawal->withdrawal_reservation_id)
                ->where('status', 'live')->where('owner_reference', $withdrawal->withdrawal_id)
                ->update(['status' => 'consumed', 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            if ($changed !== 1) {
                throw new ConflictHttpException('The original reservation could not be consumed.');
            }
            $execution->update(['status' => 'posted', 'customer_acknowledgement' => json_encode([
                'recipient_user_id' => $actor->id, 'execution_reference' => $execution->execution_reference,
                'amount_kobo' => $execution->amount_kobo, 'confirmed_at' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR), 'acknowledged_at' => now(), 'resolved_at' => now(),
                'live_withdrawal_request_id' => null, 'ledger_posting_group_id' => $group->id]);
            $withdrawal->live_thrift_plan_id = null;
            $withdrawal->terminal_at = now();
            $this->transition($withdrawal, 'posted', 'cash_posted', $actor, $execution);
            app(LedgerTransactionProjectionService::class)->projectWithdrawal($withdrawal);

            return $execution;
        }, attempts: 3);
    }

    public function confirmNoHandoff(User $actor, CashExecution $reference, string $evidence, Request $httpRequest): CashExecution
    {
        return app(PlatformGuard::class)->transaction('financial', function () use ($actor, $reference, $evidence, $httpRequest): CashExecution {
            $actor = $this->executor($actor, $httpRequest);
            [$withdrawal, $execution] = $this->lock($reference);
            Gate::forUser($actor)->authorize('view', $withdrawal->customerProfile);
            if ($execution->executor_user_id !== $actor->id) {
                throw new AuthorizationException('Only the bound custodian can confirm non-delivery.');
            }
            if ($execution->status === 'payment_failed' && $execution->handoff_evidence === $evidence) {
                return $execution;
            }
            if ($execution->status !== 'processing' || $execution->handoff_at !== null) {
                throw new ConflictHttpException('A claimed handoff cannot be treated as definitive non-delivery.');
            }
            $execution->update(['status' => 'payment_failed', 'handoff_evidence' => $evidence,
                'resolved_at' => now(), 'live_withdrawal_request_id' => null]);
            $this->transition($withdrawal, 'payment_failed', 'cash_not_delivered', $actor, $execution);

            return $execution;
        }, attempts: 3);
    }

    private function executor(User $actor, Request $request): User
    {
        $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
        if (! $this->authorization->allows($actor, AdminPermission::CashExecute)
            || ! $this->freshAuthentication->isFresh($actor, $request)) {
            throw new AuthorizationException('Fresh cash-execution authority is required.');
        }

        return $actor;
    }

    /** @return array{WithdrawalRequest, CashExecution} */
    private function lock(CashExecution $execution): array
    {
        $source = WithdrawalRequest::query()->findOrFail($execution->withdrawal_request_id);
        CustomerProfile::query()->whereKey($source->customer_profile_id)->lockForUpdate()->firstOrFail();
        $withdrawal = WithdrawalRequest::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();

        return [$withdrawal, CashExecution::query()->whereKey($execution->id)->lockForUpdate()->firstOrFail()];
    }

    private function account(LedgerAccountCode $code, LedgerAccountClass $class, LedgerEntrySide $side): LedgerAccount
    {
        $account = LedgerAccount::query()->where('code', $code->value)->lockForUpdate()->firstOrFail();
        if ($account->currency !== 'NGN' || $account->mapping_status !== 'mapped' || $account->account_class !== $class
            || $account->normal_balance !== $side || $account->version < 1) {
            throw new ConflictHttpException('The required cash accounting map is unavailable.');
        }

        return $account;
    }

    private function cashBalance(LedgerAccount $cash): int
    {
        $value = DB::table('ledger_entries')->where('ledger_account_id', $cash->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN side = 'debit' THEN amount_kobo ELSE -amount_kobo END), 0) AS balance")->value('balance');
        $amount = filter_var($value, FILTER_VALIDATE_INT);
        if ($amount === false || $amount < 0) {
            throw new ConflictHttpException('Business cash does not reconcile.');
        }

        return $amount;
    }

    private function postWithdrawal(WithdrawalRequest $withdrawal, CashExecution $execution, User $actor, LedgerAccount $cash): LedgerPostingGroup
    {
        $liability = $this->account(LedgerAccountCode::CustomerSavingsLiability, LedgerAccountClass::CustomerSavingsLiability, LedgerEntrySide::Credit);
        $fee = $withdrawal->fee_amount_kobo > 0 ? $this->account(LedgerAccountCode::FeeIncome, LedgerAccountClass::FeeIncome, LedgerEntrySide::Credit) : null;
        $timezone = BusinessProfile::current()->timezone;
        app(FinancialPeriodService::class)->assertOpen($execution->handoff_at->setTimezone($timezone)->toDateString(), $timezone, true);
        $obligation = $this->withdrawalFee($withdrawal, $actor);
        $group = LedgerPostingGroup::create([
            'posting_reference' => 'PAY-'.$execution->execution_reference, 'idempotency_key' => 'cash-withdrawal-'.$withdrawal->id,
            'payload_hash' => $execution->start_payload_hash, 'source_type' => 'withdrawal', 'source_id' => (string) $withdrawal->id,
            'event_type' => 'cash_withdrawal', 'currency' => 'NGN', 'actor_user_id' => $execution->executor_user_id,
            'customer_profile_id' => $withdrawal->customer_profile_id, 'thrift_plan_id' => $withdrawal->thrift_plan_id,
            'occurred_at' => $execution->handoff_at, 'occurred_on' => $execution->handoff_at->setTimezone($timezone)->toDateString(),
            'business_timezone' => $timezone, 'schema_version' => 1, 'correlation_id' => $execution->execution_reference,
            'committed_at' => now(), 'metadata' => ['execution_reference' => $execution->execution_reference,
                'cash_mapping_version' => $execution->cash_mapping_version, 'method_version' => $execution->method_version],
        ]);
        $lines = [[$liability, LedgerEntrySide::Debit, $withdrawal->gross_amount_kobo], [$cash, LedgerEntrySide::Credit, $withdrawal->net_amount_kobo]];
        if ($fee !== null) {
            $lines[] = [$fee, LedgerEntrySide::Credit, $withdrawal->fee_amount_kobo];
        }
        foreach ($lines as $index => [$account, $side, $amount]) {
            LedgerEntry::create(['ledger_posting_group_id' => $group->id, 'line_number' => $index + 1,
                'ledger_account_id' => $account->id, 'side' => $side, 'amount_kobo' => $amount,
                'customer_profile_id' => $withdrawal->customer_profile_id,
                'thrift_plan_id' => $account->id === $liability->id ? $withdrawal->thrift_plan_id : null,
                'fee_obligation_id' => $account->id === $fee?->id ? $obligation?->id : null]);
        }
        if ($obligation !== null) {
            FeeObligationEntry::create(['fee_obligation_id' => $obligation->id, 'entry_type' => FeeObligationEntryType::Settlement,
                'amount_kobo' => $withdrawal->fee_amount_kobo, 'currency' => 'NGN', 'source_type' => 'withdrawal',
                'source_id' => (string) $withdrawal->id, 'idempotency_key' => 'withdrawal-fee-'.$withdrawal->id,
                'actor_user_id' => $execution->executor_user_id, 'customer_description' => $obligation->customer_description,
                'ledger_posting_reference' => $group->posting_reference]);
        }

        return $group;
    }

    private function withdrawalFee(WithdrawalRequest $withdrawal, User $actor): ?FeeObligation
    {
        if ($withdrawal->fee_amount_kobo === 0) {
            return null;
        }
        $snapshot = FeeSnapshot::query()->findOrFail($withdrawal->fee_snapshot_id);
        if ($snapshot->timing === FeeRuleTiming::CycleCompletion) {
            $obligation = $snapshot->obligation;
            if ($obligation === null || $obligation->outstandingAmountKobo() !== $withdrawal->fee_amount_kobo) {
                throw new ConflictHttpException('The completion fee assessment changed.');
            }

            return $obligation;
        }
        $attributes = $snapshot->only(['customer_profile_id', 'fee_rule_id', 'fee_rule_version', 'name', 'kind', 'model', 'timing', 'basis', 'settlement_source', 'currency', 'basis_points', 'customer_description']);
        $basis = $snapshot->model === FeeRuleModel::OneDay ? $snapshot->basis_amount_kobo : $withdrawal->gross_amount_kobo;
        $paymentSnapshot = FeeSnapshot::create([...$attributes, 'source_type' => 'withdrawal', 'source_id' => (string) $withdrawal->id,
            'basis_amount_kobo' => $basis, 'amount_kobo' => $withdrawal->fee_amount_kobo, 'acknowledged_at' => $snapshot->acknowledged_at]);

        return app(FeeObligationService::class)->assessSnapshot($paymentSnapshot, $actor);
    }

    private function transition(WithdrawalRequest $withdrawal, string $state, string $eventType, User $actor, CashExecution $execution): void
    {
        $before = $withdrawal->state;
        $withdrawal->state = $state;
        $withdrawal->version++;
        $withdrawal->save();
        $event = WithdrawalEvent::create(['withdrawal_request_id' => $withdrawal->id, 'actor_user_id' => $actor->id,
            'event_type' => $eventType, 'from_state' => $before, 'to_state' => $state,
            'request_version' => $withdrawal->version, 'effective_at' => now()]);
        AuditEvent::record('withdrawal.'.$eventType, WithdrawalRequest::class, $withdrawal->id, $withdrawal->withdrawal_id,
            ['state' => $state, 'version' => $withdrawal->version, 'customer_profile_id' => $withdrawal->customer_profile_id,
                'execution_reference' => $execution->execution_reference, 'net_kobo' => $execution->amount_kobo], $actor,
            context: ['executor' => self::class, 'approver_id' => $withdrawal->reviewed_by_user_id,
                'required_permission' => $actor->user_type === UserType::Admin ? 'cash.execute' : null]);
        app(WithdrawalNoticeService::class)->queue($withdrawal, $event);
    }
}
