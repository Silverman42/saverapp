<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

#[Fillable(['reversal_request_id', 'actor_user_id', 'event_type', 'customer_explanation', 'metadata', 'effective_at'])]
class ReversalEvent extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Reversal events are immutable.');
        });
        static::deleting(function (): never {
            throw new RuntimeException('Reversal events cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return ['metadata' => 'array', 'effective_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ReversalRequest, $this> */
    public function reversalRequest(): BelongsTo
    {
        return $this->belongsTo(ReversalRequest::class);
    }
}
