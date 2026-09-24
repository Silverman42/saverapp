<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

#[Fillable([
    'withdrawal_request_id', 'actor_user_id', 'event_type', 'from_state', 'to_state',
    'request_version', 'reason', 'customer_explanation', 'effective_at',
])]
class WithdrawalEvent extends Model
{
    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Withdrawal events are immutable.');
        });
        static::deleting(function (): never {
            throw new RuntimeException('Withdrawal events cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return ['effective_at' => 'immutable_datetime', 'request_version' => 'integer'];
    }
}
