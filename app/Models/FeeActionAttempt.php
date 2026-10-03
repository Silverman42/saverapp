<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * @property CarbonImmutable|null $cancelled_at
 */
#[Fillable(['attempt_reference', 'actor_user_id', 'fee_obligation_id', 'operation', 'payload_hash', 'status', 'source_type', 'source_id', 'cancelled_at', 'cancellation_reason_code', 'cancellation_audit_event_id'])]
class FeeActionAttempt extends Model
{
    protected $hidden = ['payload_hash'];

    protected function casts(): array
    {
        return ['actor_user_id' => 'integer', 'fee_obligation_id' => 'integer', 'cancelled_at' => 'immutable_datetime', 'cancellation_audit_event_id' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $attempt): void {
            if ($attempt->isDirty(['attempt_reference', 'actor_user_id', 'fee_obligation_id', 'operation', 'payload_hash'])
                || $attempt->getRawOriginal('status') !== 'prepared'
                || ! in_array($attempt->status, ['recorded', 'cancelled'], true)) {
                throw new RuntimeException('Fee action attempt bindings and terminal outcomes are immutable.');
            }
        });
        static::deleting(function (): never {
            throw new RuntimeException('Fee action attempts cannot be deleted.');
        });
    }
}
