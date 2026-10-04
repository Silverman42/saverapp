<?php

namespace App\Models;

use App\Enums\LedgerEntrySide;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * @property int $id
 * @property int $ledger_posting_group_id
 * @property int $line_number
 * @property int $ledger_account_id
 * @property LedgerEntrySide $side
 * @property int $amount_kobo
 * @property int|null $customer_profile_id
 * @property int|null $agent_profile_id
 * @property int|null $fee_obligation_id
 * @property int|null $thrift_plan_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'ledger_posting_group_id',
    'line_number',
    'ledger_account_id',
    'side',
    'amount_kobo',
    'customer_profile_id',
    'agent_profile_id',
    'fee_obligation_id',
    'thrift_plan_id',
])]
class LedgerEntry extends Model
{
    protected function casts(): array
    {
        return [
            'side' => LedgerEntrySide::class,
            'amount_kobo' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            $retiredAt = LedgerAccount::query()->whereKey($entry->ledger_account_id)->value('retired_at');
            if ($retiredAt !== null && CarbonImmutable::parse($retiredAt)->lessThanOrEqualTo(now())) {
                throw new ConflictHttpException('A retired ledger account cannot receive new postings.');
            }
        });

        static::updating(function (): never {
            throw new RuntimeException('Ledger entries are immutable.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('Ledger entries cannot be deleted.');
        });
    }

    /**
     * Get the posting group containing this line.
     *
     * @return BelongsTo<LedgerPostingGroup, $this>
     */
    public function postingGroup(): BelongsTo
    {
        return $this->belongsTo(LedgerPostingGroup::class, 'ledger_posting_group_id');
    }

    /**
     * Get the controlled account used by this line.
     *
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    /**
     * Get the Customer dimension for this line.
     *
     * @return BelongsTo<CustomerProfile, $this>
     */
    public function customerProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /**
     * Get the Agent dimension for this line.
     *
     * @return BelongsTo<AgentProfile, $this>
     */
    public function agentProfile(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class);
    }

    /**
     * Get the fee obligation dimension for this line.
     *
     * @return BelongsTo<FeeObligation, $this>
     */
    public function feeObligation(): BelongsTo
    {
        return $this->belongsTo(FeeObligation::class);
    }
}
