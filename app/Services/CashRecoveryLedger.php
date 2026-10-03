<?php

namespace App\Services;

use App\Enums\LedgerAccountClass;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Models\BusinessProfile;
use App\Models\CashDisbursement;
use App\Models\CashExecution;
use App\Models\CashRecovery;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CashRecoveryLedger
{
    public function record(CashRecovery $recovery, User $actor, ?int $customerId, ?int $planId): LedgerPostingGroup
    {
        $accounts = LedgerAccount::query()->whereIn('code', [LedgerAccountCode::BusinessCash, LedgerAccountCode::CashRecoveryClearing])->orderBy('id')->lockForUpdate()->get()->keyBy(fn ($account): string => $account->code->value);
        foreach ([[LedgerAccountCode::BusinessCash, LedgerAccountClass::Asset, LedgerEntrySide::Debit], [LedgerAccountCode::CashRecoveryClearing, LedgerAccountClass::CashRecoveryClearing, LedgerEntrySide::Credit]] as [$code, $class, $side]) {
            $account = $accounts->get($code->value);
            if ($account === null || $account->mapping_status !== 'mapped' || $account->account_class !== $class || $account->normal_balance !== $side || $account->currency !== 'NGN') {
                throw new ConflictHttpException('Approved cash recovery clearing mapping is required.');
            }
        }
        $business = BusinessProfile::current();
        $date = now($business->timezone)->toDateString();
        app(FinancialPeriodService::class)->assertOpen($date, $business->timezone, true);
        $group = LedgerPostingGroup::create(['posting_reference' => 'RETURN-'.Str::uuid(), 'idempotency_key' => 'cash-return-'.$recovery->recovery_reference,
            'payload_hash' => $recovery->payload_hash, 'source_type' => 'cash_recovery', 'source_id' => (string) $recovery->id,
            'event_type' => 'cash_return', 'currency' => 'NGN', 'actor_user_id' => $actor->id, 'customer_profile_id' => $customerId,
            'thrift_plan_id' => $planId, 'occurred_at' => now(), 'occurred_on' => $date, 'business_timezone' => $business->timezone,
            'schema_version' => 1, 'committed_at' => now(), 'metadata' => ['execution_id' => $recovery->cash_execution_id, 'disbursement_id' => $recovery->cash_disbursement_id]]);
        foreach ([[LedgerAccountCode::BusinessCash, LedgerEntrySide::Debit], [LedgerAccountCode::CashRecoveryClearing, LedgerEntrySide::Credit]] as $index => [$code, $side]) {
            LedgerEntry::create(['ledger_posting_group_id' => $group->id, 'line_number' => $index + 1, 'ledger_account_id' => $accounts[$code->value]->id,
                'side' => $side, 'amount_kobo' => $recovery->amount_kobo, 'customer_profile_id' => $customerId, 'thrift_plan_id' => $planId]);
        }
        $recovery->update(['return_posting_group_id' => $group->id]);

        return $group;
    }

    /** @param Collection<int, CashRecovery> $returns */
    public function assertConfirmedReturns(CashExecution|CashDisbursement $execution, Collection $returns): void
    {
        $total = 0;
        foreach ($returns as $returned) {
            if ($returned->status !== 'confirmed') {
                throw new ConflictHttpException('The original execution requires verified linked cash return postings.');
            }
            $this->assertReturnPosting($execution, $returned, DB::transactionLevel() > 0);
            $total += $returned->amount_kobo;
        }
        if ($total !== $execution->amount_kobo || app(FinancialCashPosition::class)->balance(LedgerAccountCode::CashRecoveryClearing) < $total) {
            throw new ConflictHttpException('Full verified returned cash is required before compensation.');
        }
    }

    public function assertReturnPosting(CashExecution|CashDisbursement $execution, CashRecovery $returned, bool $forUpdate = false): void
    {
        $matches = $execution instanceof CashExecution ? $returned->cash_execution_id === $execution->id : $returned->cash_disbursement_id === $execution->id;
        $posting = LedgerPostingGroup::query()->whereKey($returned->return_posting_group_id)
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->first();
        if (! $matches || $returned->recipient_user_id !== $execution->recipient_user_id
            || $returned->custodian_user_id !== $execution->executor_user_id || $returned->confirmed_at === null
            || $posting === null || $posting->source_type !== 'cash_recovery' || $posting->source_id !== (string) $returned->id
            || $posting->event_type !== 'cash_return' || ! hash_equals($posting->payload_hash, $returned->payload_hash)) {
            throw new ConflictHttpException('The original execution requires verified linked cash return postings.');
        }
        $lines = $posting->entries()->with(['account' => fn ($query) => $query->when($forUpdate, fn ($query) => $query->lockForUpdate())])
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->get();
        if ($lines->count() !== 2 || ! $lines->contains(fn ($line): bool => $line->account?->code === LedgerAccountCode::BusinessCash && $line->side === LedgerEntrySide::Debit && $line->amount_kobo === $returned->amount_kobo)
            || ! $lines->contains(fn ($line): bool => $line->account?->code === LedgerAccountCode::CashRecoveryClearing && $line->side === LedgerEntrySide::Credit && $line->amount_kobo === $returned->amount_kobo)) {
            throw new ConflictHttpException('The recovery clearing source is not authoritative.');
        }
    }

    public function compensateDisbursement(CashDisbursement $execution, User $actor): LedgerPostingGroup
    {
        $this->assertConfirmedReturns($execution, CashRecovery::query()->where('cash_disbursement_id', $execution->id)->where('event_type', 'return')->where('status', 'confirmed')->lockForUpdate()->get());
        $original = LedgerPostingGroup::query()->findOrFail($execution->ledger_posting_group_id)->load('entries.account');
        $clearing = LedgerAccount::query()->where('code', LedgerAccountCode::CashRecoveryClearing)->lockForUpdate()->sole();
        $business = BusinessProfile::current();
        $date = now($business->timezone)->toDateString();
        app(FinancialPeriodService::class)->assertOpen($date, $business->timezone, true);
        $group = LedgerPostingGroup::create(['posting_reference' => 'RECOVER-'.Str::uuid(), 'idempotency_key' => 'disbursement-return-'.$execution->id,
            'payload_hash' => $execution->payload_hash, 'source_type' => 'disbursement_recovery', 'source_id' => (string) $execution->id,
            'event_type' => 'disbursement_compensation', 'currency' => 'NGN', 'actor_user_id' => $actor->id,
            'customer_profile_id' => $execution->customer_profile_id, 'occurred_at' => now(), 'occurred_on' => $date,
            'business_timezone' => $business->timezone, 'schema_version' => 1, 'committed_at' => now(), 'metadata' => ['original_posting_group_id' => $original->id]]);
        foreach ($original->entries as $index => $line) {
            LedgerEntry::create(['ledger_posting_group_id' => $group->id, 'line_number' => $index + 1,
                'ledger_account_id' => $line->account->code === LedgerAccountCode::BusinessCash ? $clearing->id : $line->ledger_account_id,
                'side' => $line->side === LedgerEntrySide::Debit ? LedgerEntrySide::Credit : LedgerEntrySide::Debit,
                'amount_kobo' => $line->amount_kobo, 'customer_profile_id' => $line->customer_profile_id, 'fee_obligation_id' => $line->fee_obligation_id]);
        }
        DB::table('cash_disbursements')->where('id', $execution->id)->update(['live_fee_refund_id' => null]);

        return $group;
    }
}
