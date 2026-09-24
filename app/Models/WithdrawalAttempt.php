<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

#[Fillable(['attempt_reference', 'actor_user_id', 'withdrawal_request_id', 'operation', 'payload_hash'])]
class WithdrawalAttempt extends Model
{
    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Withdrawal attempts are immutable.');
        });
        static::deleting(function (): never {
            throw new RuntimeException('Withdrawal attempts cannot be deleted.');
        });
    }
}
