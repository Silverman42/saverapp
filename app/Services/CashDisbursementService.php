<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Enums\CustomerActivity;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CashDisbursement;
use App\Models\CustomerProfile;
use App\Models\FeeRefund;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CashDisbursementService
{
    public function start(User $actor, string $reference, ?FeeRefund $refund, int $amountKobo, string $evidence, Request $request): CashDisbursement
    {
        return app(PlatformGuard::class)->transaction('financial', function () use ($actor, $reference, $refund, $amountKobo, $evidence, $request): CashDisbursement {
            $kind = $refund === null ? 'earnings_draw' : 'fee_refund';
            $actor = $this->executor($actor, $kind, $request);
            $customer = null;
            if ($refund !== null) {
                $customer = CustomerProfile::query()->whereKey($refund->customer_profile_id)->lockForUpdate()->firstOrFail();
                abort_unless(app(ResourceScopeService::class)->forCustomers($actor)->whereKey($customer->id)->exists(), 404);
                $refund = FeeRefund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
                $amountKobo = $refund->amount_kobo;
            }
            $hash = hash('sha256', json_encode([$reference, $actor->id, $kind, $refund?->id, $amountKobo, trim($evidence)], JSON_THROW_ON_ERROR));
            $existing = CashDisbursement::query()->where('execution_reference', $reference)->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw new ConflictHttpException('The cash execution identity belongs to a different payment.');
                }

                return $existing;
            }
            abort_unless(config('fees.cash_disbursements_enabled', false), 503, 'Refund and earnings handoff acceptance is not certified.');
            app(BusinessSettings::class)->ensureFeature('cash_disbursements');
            if ($amountKobo < 1 || $amountKobo > 999999999999 || trim($evidence) === '') {
                throw new ConflictHttpException('A positive evidenced cash amount is required.');
            }
            if ($customer !== null) {
                app(CustomerActivityGate::class)->assertAllowed($customer, CustomerActivity::PostPayout);
                if ($refund->kind !== 'external' || CashDisbursement::query()->where('fee_refund_id', $refund->id)
                    ->whereIn('status', ['processing', 'outcome_unknown', 'posted'])->exists()) {
                    throw new ConflictHttpException('This refund has no unpaid entitlement or is already owned by an attempt.');
                }
                $this->assertPayable($refund);
                abort_unless($customer->user->account_state->value === 'active', 409, 'The cash recipient must have an active authenticated account.');
            }
            $position = app(FinancialCashPosition::class)->read();
            $limit = $kind === 'earnings_draw' ? $position['draw_limit_kobo'] : $position['available_cash_kobo'];
            if ($amountKobo > $limit) {
                throw new ConflictHttpException('The payment exceeds verified cash or undrawn cash-backed earnings.');
            }
            $business = BusinessProfile::current();
            app(FinancialPeriodService::class)->assertOpen(now($business->timezone)->toDateString(), $business->timezone, true);
            $cash = LedgerAccount::query()->where('code', LedgerAccountCode::BusinessCash->value)->sole();
            $debit = LedgerAccount::query()->where('code', $kind === 'earnings_draw' ? LedgerAccountCode::BusinessDistributions->value : LedgerAccountCode::RefundPayable->value)->sole();
            $execution = CashDisbursement::create(['execution_reference' => $reference, 'payload_hash' => $hash,
                'fee_refund_id' => $refund?->id, 'live_fee_refund_id' => $refund?->id, 'customer_profile_id' => $customer?->id,
                'executor_user_id' => $actor->id, 'recipient_user_id' => $customer->user_id ?? $actor->id, 'kind' => $kind,
                'amount_kobo' => $amountKobo, 'cash_mapping_version' => $cash->version, 'debit_mapping_version' => $debit->version,
                'method_version' => app(CashMethodCatalogue::class)->version($kind), 'status' => 'processing', 'custody_evidence' => trim($evidence)]);
            $this->event($execution, $actor, 'started');

            return $execution;
        }, attempts: 3);
    }

    public function handoff(User $actor, CashDisbursement $reference, bool $delivered, string $evidence, Request $request): CashDisbursement
    {
        return app(PlatformGuard::class)->transaction('financial', function () use ($actor, $reference, $delivered, $evidence, $request): CashDisbursement {
            $actor = $this->executor($actor, $reference->kind, $request);
            $execution = $this->lock($reference);
            abort_unless($execution->executor_user_id === $actor->id, 403);
            if ($execution->handoff_evidence !== null) {
                if ($execution->handoff_evidence !== trim($evidence) || ($delivered ? $execution->handoff_at === null : $execution->status !== 'payment_failed')) {
                    throw new ConflictHttpException('This handoff result is already recorded differently.');
                }

                return $execution;
            }
            if ($execution->status !== 'processing' || trim($evidence) === '') {
                throw new ConflictHttpException('Only an unrecorded handoff can be resolved here.');
            }
            $execution->update(['status' => $delivered ? 'outcome_unknown' : 'payment_failed', 'handoff_evidence' => trim($evidence),
                'handoff_at' => $delivered ? now() : null, 'resolved_at' => $delivered ? null : now(),
                'live_fee_refund_id' => $delivered ? $execution->fee_refund_id : null]);
            $this->event($execution, $actor, $delivered ? 'handoff_recorded' : 'not_delivered');

            return $execution;
        }, attempts: 3);
    }

    public function acknowledge(User $actor, CashDisbursement $reference, Request $request): CashDisbursement
    {
        return app(PlatformGuard::class)->transaction('financial', function () use ($actor, $reference, $request): CashDisbursement {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $execution = $this->lock($reference);
            if ($execution->kind === 'earnings_draw') {
                $actor = $this->executor($actor, $execution->kind, $request);
            }
            abort_unless($execution->recipient_user_id === $actor->id && $actor->account_state->value === 'active'
                && ($execution->kind === 'earnings_draw' || $actor->user_type === UserType::Customer), 403);
            if ($execution->status === 'posted') {
                return $execution;
            }
            if ($execution->status !== 'outcome_unknown' || $execution->handoff_at === null) {
                throw new ConflictHttpException('A recorded handoff is required before receipt acknowledgement.');
            }
            app(CashMethodCatalogue::class)->version($execution->kind, $execution->method_version);
            if ($execution->kind === 'earnings_draw' && $execution->amount_kobo > app(FinancialCashPosition::class)->read($execution->id)['draw_limit_kobo']) {
                throw new ConflictHttpException('Cash-backed earnings changed; the handed-over draw remains an owned exception.');
            }
            $cash = LedgerAccount::query()->where('code', LedgerAccountCode::BusinessCash->value)->sole();
            if ($cash->version !== $execution->cash_mapping_version || app(FinancialCashPosition::class)->balance(LedgerAccountCode::BusinessCash) < $execution->amount_kobo) {
                throw new ConflictHttpException('The original execution cash requires recovery.');
            }
            $refund = $execution->fee_refund_id === null ? null : FeeRefund::query()->whereKey($execution->fee_refund_id)->lockForUpdate()->firstOrFail();
            if ($refund !== null) {
                $this->assertPayable($refund);
            }
            $code = $refund === null ? LedgerAccountCode::BusinessDistributions : LedgerAccountCode::RefundPayable;
            $debit = LedgerAccount::query()->where('code', $code->value)->sole();
            if ($debit->version !== $execution->debit_mapping_version) {
                throw new ConflictHttpException('The approved execution destination changed and requires recovery.');
            }
            app(FinancialCashPosition::class)->balance($code);
            $business = BusinessProfile::current();
            $date = $execution->handoff_at->setTimezone($business->timezone)->toDateString();
            app(FinancialPeriodService::class)->assertOpen($date, $business->timezone, true);
            $group = LedgerPostingGroup::create(['posting_reference' => 'CASH-'.Str::uuid(), 'idempotency_key' => 'disbursement-'.$execution->execution_reference,
                'payload_hash' => $execution->payload_hash, 'source_type' => 'cash_disbursement', 'source_id' => (string) $execution->id,
                'event_type' => $execution->kind, 'currency' => 'NGN', 'actor_user_id' => $execution->executor_user_id,
                'customer_profile_id' => $execution->customer_profile_id, 'occurred_at' => $execution->handoff_at,
                'occurred_on' => $date, 'business_timezone' => $business->timezone, 'schema_version' => 1,
                'committed_at' => now(), 'metadata' => ['execution_reference' => $execution->execution_reference, 'fee_refund_id' => $refund?->id]]);
            foreach ([[$debit, LedgerEntrySide::Debit], [$cash, LedgerEntrySide::Credit]] as $index => [$account, $side]) {
                LedgerEntry::create(['ledger_posting_group_id' => $group->id, 'line_number' => $index + 1,
                    'ledger_account_id' => $account->id, 'side' => $side, 'amount_kobo' => $execution->amount_kobo,
                    'customer_profile_id' => $execution->customer_profile_id, 'fee_obligation_id' => $refund?->fee_obligation_id]);
            }
            $execution->update(['status' => 'posted', 'resolved_at' => now(), 'live_fee_refund_id' => null,
                'ledger_posting_group_id' => $group->id, 'acknowledgement' => json_encode(['execution_reference' => $execution->execution_reference,
                    'recipient_user_id' => $actor->id, 'amount_kobo' => $execution->amount_kobo, 'confirmed_at' => now()->toIso8601String()], JSON_THROW_ON_ERROR)]);
            $this->event($execution, $actor, 'posted');
            app(LedgerTransactionProjectionService::class)->projectDisbursement($execution);

            return $execution;
        }, attempts: 3);
    }

    private function assertPayable(FeeRefund $refund): void
    {
        $group = LedgerPostingGroup::query()->whereKey($refund->ledger_posting_group_id)->with('entries.account')->firstOrFail();
        $payable = $group->entries->first(fn ($entry): bool => $entry->account->code === LedgerAccountCode::RefundPayable && $entry->side === LedgerEntrySide::Credit);
        if ($refund->kind !== 'external' || $payable === null || $payable->amount_kobo !== $refund->amount_kobo
            || $payable->customer_profile_id !== $refund->customer_profile_id || $payable->fee_obligation_id !== $refund->fee_obligation_id) {
            throw new ConflictHttpException('The exact external refund payable is unavailable.');
        }
    }

    private function executor(User $actor, string $kind, Request $request): User
    {
        $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
        $authorization = app(AuthorizationService::class);
        abort_unless($authorization->allows($actor, AdminPermission::CashExecute) && app(FreshAuthenticationService::class)->isFresh($actor, $request)
            && ($kind !== 'earnings_draw' || $authorization->allows($actor, AdminPermission::FeesManage)), 403);

        return $actor;
    }

    private function lock(CashDisbursement $execution): CashDisbursement
    {
        if ($execution->customer_profile_id !== null) {
            CustomerProfile::query()->whereKey($execution->customer_profile_id)->lockForUpdate()->firstOrFail();
        }

        return CashDisbursement::query()->whereKey($execution->id)->lockForUpdate()->firstOrFail();
    }

    private function event(CashDisbursement $execution, User $actor, string $event): void
    {
        AuditEvent::record('cash_disbursement.'.$event, CashDisbursement::class, $execution->id, $execution->execution_reference,
            ['kind' => $execution->kind, 'status' => $execution->status, 'amount_kobo' => $execution->amount_kobo, 'customer_profile_id' => $execution->customer_profile_id], $actor,
            context: ['executor' => self::class, 'required_permission' => $actor->user_type === UserType::Admin ? 'cash.execute' : null]);
        app(FinancialCashNotice::class)->queue($actor, $execution, $event);
    }
}
