<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** @property CarbonImmutable|null $handoff_at */
#[Fillable(['execution_reference', 'payload_hash', 'fee_refund_id', 'live_fee_refund_id', 'customer_profile_id', 'executor_user_id', 'recipient_user_id', 'kind', 'amount_kobo', 'cash_mapping_version', 'debit_mapping_version', 'method_version', 'status', 'custody_evidence', 'handoff_evidence', 'acknowledgement', 'handoff_at', 'resolved_at', 'ledger_posting_group_id'])]
class CashDisbursement extends Model
{
    protected $hidden = ['custody_evidence', 'handoff_evidence', 'acknowledgement', 'payload_hash'];

    protected function casts(): array
    {
        return ['amount_kobo' => 'integer', 'cash_mapping_version' => 'integer', 'debit_mapping_version' => 'integer', 'method_version' => 'integer', 'custody_evidence' => 'encrypted',
            'handoff_evidence' => 'encrypted', 'acknowledgement' => 'encrypted', 'handoff_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $execution): void {
            if ($execution->getOriginal('resolved_at') !== null || $execution->isDirty(['execution_reference', 'payload_hash', 'fee_refund_id',
                'customer_profile_id', 'executor_user_id', 'recipient_user_id', 'kind', 'amount_kobo', 'cash_mapping_version', 'debit_mapping_version', 'method_version', 'custody_evidence'])
                || ($execution->getOriginal('handoff_at') !== null && $execution->isDirty(['handoff_at', 'handoff_evidence']))) {
                throw new RuntimeException('Cash disbursement identity and recorded evidence are immutable.');
            }
        });
        static::deleting(function (): never {
            throw new RuntimeException('Cash disbursement evidence cannot be deleted.');
        });
    }

    public function getRouteKeyName(): string
    {
        return 'execution_reference';
    }
}
