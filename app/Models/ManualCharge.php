<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

#[Fillable(['operation_reference', 'payload_hash', 'customer_profile_id', 'thrift_plan_id', 'charge_category_version_id', 'actor_user_id', 'amount_kobo', 'fee_obligation_id', 'ledger_posting_group_id', 'reason'])]
class ManualCharge extends Model
{
    protected $hidden = ['reason', 'payload_hash'];

    protected function casts(): array
    {
        return ['reason' => 'encrypted', 'amount_kobo' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Confirmed charges are immutable.');
        });
        static::deleting(function (): never {
            throw new RuntimeException('Confirmed charges cannot be deleted.');
        });
    }
}
