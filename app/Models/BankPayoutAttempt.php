<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * @property int $id
 * @property string $attempt_reference
 * @property int $withdrawal_request_id
 * @property int $attempt_number
 * @property int $executor_user_id
 * @property int $customer_payout_destination_id
 * @property int $destination_version
 * @property int $amount_kobo
 * @property string $currency
 * @property int $method_version
 * @property int $payout_mapping_version
 * @property int $funding_mapping_version
 * @property string $provider_key
 * @property string $idempotency_key
 * @property string $start_payload_hash
 * @property string $status
 * @property string|null $provider_outcome
 * @property string|null $provider_reference
 * @property array<string, mixed>|null $provider_evidence
 * @property string|null $failure_code
 * @property int $dispatch_count
 * @property CarbonImmutable|null $next_check_at
 * @property CarbonImmutable|null $last_checked_at
 * @property CarbonImmutable|null $initiated_at
 * @property CarbonImmutable|null $provider_occurred_at
 * @property CarbonImmutable|null $finalized_at
 * @property CarbonImmutable|null $settled_at
 * @property int|null $ledger_posting_group_id
 * @property int|null $settlement_posting_group_id
 */
#[Fillable(['attempt_reference', 'withdrawal_request_id', 'attempt_number', 'live_withdrawal_request_id', 'executor_user_id', 'customer_payout_destination_id', 'destination_version', 'amount_kobo', 'currency', 'method_version', 'payout_mapping_version', 'funding_mapping_version', 'provider_key', 'idempotency_key', 'start_payload_hash', 'status', 'provider_outcome', 'provider_reference', 'provider_evidence', 'failure_code', 'dispatch_count', 'next_check_at', 'last_checked_at', 'initiated_at', 'provider_occurred_at', 'finalized_at', 'settled_at', 'ledger_posting_group_id', 'settlement_posting_group_id'])]
class BankPayoutAttempt extends Model
{
    /** @var list<string> */
    protected $hidden = ['provider_evidence', 'start_payload_hash', 'idempotency_key'];

    protected function casts(): array
    {
        return ['attempt_number' => 'integer', 'destination_version' => 'integer', 'amount_kobo' => 'integer',
            'method_version' => 'integer', 'payout_mapping_version' => 'integer', 'funding_mapping_version' => 'integer',
            'dispatch_count' => 'integer', 'provider_evidence' => 'encrypted:array',
            'next_check_at' => 'immutable_datetime', 'last_checked_at' => 'immutable_datetime', 'initiated_at' => 'immutable_datetime',
            'provider_occurred_at' => 'immutable_datetime', 'finalized_at' => 'immutable_datetime', 'settled_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $attempt): void {
            if ($attempt->isDirty(['attempt_reference', 'withdrawal_request_id', 'attempt_number', 'executor_user_id', 'customer_payout_destination_id',
                'destination_version', 'amount_kobo', 'currency', 'method_version', 'payout_mapping_version', 'funding_mapping_version',
                'provider_key', 'idempotency_key', 'start_payload_hash'])
                || ($attempt->getOriginal('finalized_at') !== null && $attempt->isDirty(['status', 'provider_outcome', 'provider_reference', 'provider_evidence', 'failure_code', 'finalized_at']))) {
                throw new RuntimeException('Bank payout attempt identity and finalized evidence are immutable.');
            }
        });
        static::deleting(function (): never {
            throw new RuntimeException('Bank payout evidence cannot be deleted.');
        });
    }

    public function getRouteKeyName(): string
    {
        return 'attempt_reference';
    }

    /** @return BelongsTo<WithdrawalRequest, $this> */
    public function withdrawalRequest(): BelongsTo
    {
        return $this->belongsTo(WithdrawalRequest::class);
    }

    /** @return BelongsTo<LedgerPostingGroup, $this> */
    public function ledgerPostingGroup(): BelongsTo
    {
        return $this->belongsTo(LedgerPostingGroup::class, 'ledger_posting_group_id');
    }

    /** @return BelongsTo<CustomerPayoutDestination, $this> */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(CustomerPayoutDestination::class, 'customer_payout_destination_id');
    }
}
