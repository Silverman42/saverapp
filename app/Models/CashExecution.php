<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * @property CarbonImmutable|null $handoff_at
 * @property CarbonImmutable|null $acknowledged_at
 * @property CarbonImmutable|null $resolved_at
 */
#[Fillable(['execution_reference', 'withdrawal_request_id', 'live_withdrawal_request_id', 'executor_user_id', 'recipient_user_id', 'amount_kobo', 'method_version', 'cash_mapping_version', 'status', 'start_payload_hash', 'custody_evidence', 'handoff_evidence', 'customer_acknowledgement', 'handoff_at', 'acknowledged_at', 'resolved_at', 'ledger_posting_group_id'])]
class CashExecution extends Model
{
    protected $hidden = ['custody_evidence', 'handoff_evidence', 'customer_acknowledgement', 'start_payload_hash'];

    protected function casts(): array
    {
        return ['amount_kobo' => 'integer', 'method_version' => 'integer', 'cash_mapping_version' => 'integer',
            'custody_evidence' => 'encrypted', 'handoff_evidence' => 'encrypted', 'customer_acknowledgement' => 'encrypted',
            'handoff_at' => 'immutable_datetime', 'acknowledged_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $execution): void {
            if ($execution->getOriginal('resolved_at') !== null || $execution->isDirty(['execution_reference', 'withdrawal_request_id', 'executor_user_id', 'recipient_user_id', 'amount_kobo', 'method_version', 'cash_mapping_version', 'start_payload_hash', 'custody_evidence'])
                || ($execution->getOriginal('handoff_at') !== null && $execution->isDirty(['handoff_at', 'handoff_evidence']))) {
                throw new RuntimeException('Cash execution identity and recorded evidence are immutable.');
            }
        });
        static::deleting(function (): never {
            throw new RuntimeException('Cash execution evidence cannot be deleted.');
        });
    }

    public function getRouteKeyName(): string
    {
        return 'execution_reference';
    }
}
