<?php

namespace App\Services;

use App\Data\PostedPayout;
use App\Enums\LedgerAccountCode;
use App\Models\BankPayoutAttempt;
use App\Models\CashExecution;
use App\Models\WithdrawalRequest;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * The one reader of "which rail paid this withdrawal and where is its posting". Every consumer that verifies a posted payout
 * goes through here so a new rail cannot be silently skipped.
 */
class WithdrawalPayoutSource
{
    /** @var list<string> */
    public const EVENT_TYPES = ['cash_withdrawal', 'bank_withdrawal'];

    /**
     * @param  iterable<int>  $withdrawalIds
     * @return Collection<int, PostedPayout>
     */
    public function postedFor(iterable $withdrawalIds, bool $forUpdate = false): Collection
    {
        $ids = collect($withdrawalIds)->map(fn ($id): int => (int) $id)->values()->all();
        $payouts = new Collection;
        foreach (CashExecution::query()->whereIn('withdrawal_request_id', $ids)->where('status', 'posted')
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->get() as $execution) {
            if ($execution->ledger_posting_group_id === null) {
                throw new RuntimeException('A posted cash payout has no posting group.');
            }
            $payouts->push(new PostedPayout($execution->withdrawal_request_id, 'cash', $execution->id, $execution->ledger_posting_group_id,
                $execution->amount_kobo, LedgerAccountCode::BusinessCash, 'cash_withdrawal',
                $execution->acknowledged_at !== null && $execution->customer_acknowledgement !== null));
        }

        foreach (BankPayoutAttempt::query()->whereIn('withdrawal_request_id', $ids)->where('status', 'succeeded')->whereNotNull('ledger_posting_group_id')
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())->get() as $attempt) {
            $payouts->push(new PostedPayout($attempt->withdrawal_request_id, 'bank', $attempt->id, (int) $attempt->ledger_posting_group_id,
                $attempt->amount_kobo, LedgerAccountCode::PayoutClearing, 'bank_withdrawal',
                $attempt->provider_outcome === 'succeeded' && $attempt->finalized_at !== null && $attempt->provider_reference !== null));
        }

        return $payouts;
    }

    public function posted(WithdrawalRequest $withdrawal, bool $forUpdate = false): PostedPayout
    {
        $payouts = $this->postedFor([$withdrawal->id], $forUpdate);
        if ($payouts->count() !== 1) {
            throw new RuntimeException('A posted withdrawal needs exactly one posted payout.');
        }

        return $payouts->sole();
    }

    public function payoutAccount(string $rail): LedgerAccountCode
    {
        return match ($rail) {
            'cash' => LedgerAccountCode::BusinessCash,
            'bank' => LedgerAccountCode::PayoutClearing,
            default => throw new RuntimeException('Unsupported payout rail.'),
        };
    }
}
