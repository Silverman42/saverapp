<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

#[Fillable(['recovery_reference', 'cash_execution_id', 'custodian_user_id', 'recipient_user_id', 'amount_kobo', 'evidence', 'payload_hash', 'status', 'customer_acknowledgement', 'confirmed_at', 'consumed_at', 'ledger_posting_group_id'])]
class CashRecovery extends Model
{
    protected $hidden = ['evidence', 'customer_acknowledgement', 'payload_hash'];

    protected function casts(): array
    {
        return ['amount_kobo' => 'integer', 'evidence' => 'encrypted', 'customer_acknowledgement' => 'encrypted',
            'confirmed_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $recovery): void {
            if ($recovery->isDirty(['recovery_reference', 'cash_execution_id', 'custodian_user_id', 'recipient_user_id', 'amount_kobo', 'evidence', 'payload_hash'])
                || ($recovery->getOriginal('confirmed_at') !== null && $recovery->isDirty(['confirmed_at', 'customer_acknowledgement']))
                || $recovery->getOriginal('consumed_at') !== null) {
                throw new RuntimeException('Cash return identity and evidence are immutable.');
            }
        });
        static::deleting(function (): never {
            throw new RuntimeException('Cash return evidence cannot be deleted.');
        });
    }

    public function getRouteKeyName(): string
    {
        return 'recovery_reference';
    }
}
