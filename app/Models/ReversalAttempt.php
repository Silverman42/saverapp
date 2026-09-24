<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

#[Fillable(['attempt_reference', 'reversal_request_id', 'actor_user_id', 'operation', 'payload_hash'])]
class ReversalAttempt extends Model
{
    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Reversal attempts are immutable.');
        });
        static::deleting(function (): never {
            throw new RuntimeException('Reversal attempts cannot be deleted.');
        });
    }

    /** @return BelongsTo<ReversalRequest, $this> */
    public function reversalRequest(): BelongsTo
    {
        return $this->belongsTo(ReversalRequest::class);
    }
}
