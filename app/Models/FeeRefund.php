<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

#[Fillable(['compensation_posting_group_id', 'refund_reference', 'payload_hash', 'customer_profile_id', 'fee_obligation_id', 'actor_user_id', 'amount_kobo', 'kind', 'reason', 'ledger_posting_group_id'])]
class FeeRefund extends Model
{
    protected $hidden = ['payload_hash', 'reason'];

    protected function casts(): array
    {
        return ['amount_kobo' => 'integer', 'reason' => 'encrypted'];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Fee refund entitlements are immutable.');
        });
        static::deleting(function (): never {
            throw new RuntimeException('Fee refund entitlements cannot be deleted.');
        });
    }

    public function getRouteKeyName(): string
    {
        return 'refund_reference';
    }
}
