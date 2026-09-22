<?php

namespace App\Models;

use App\Enums\CreationAttemptStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $attempt_reference
 * @property int $user_id
 * @property string $business_id
 * @property string $operation_type
 * @property string $payload_fingerprint
 * @property CreationAttemptStatus $status
 * @property string|null $record_type
 * @property int|null $record_id
 * @property array<string, mixed>|null $result_summary
 * @property string|null $error_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'attempt_reference',
    'user_id',
    'business_id',
    'operation_type',
    'payload_fingerprint',
    'status',
    'record_type',
    'record_id',
    'result_summary',
    'error_message',
])]
class CreationAttempt extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CreationAttemptStatus::class,
            'result_summary' => 'array',
        ];
    }

    /**
     * Get the initiating actor for this attempt.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the created business record associated with this attempt.
     *
     * @return MorphTo<Model, $this>
     */
    public function record(): MorphTo
    {
        return $this->morphTo('record');
    }

    public function isCommitted(): bool
    {
        return $this->status === CreationAttemptStatus::Committed;
    }

    public function isInProgress(): bool
    {
        return $this->status === CreationAttemptStatus::InProgress;
    }

    public function hasConflictWith(string $fingerprint): bool
    {
        return $this->payload_fingerprint !== $fingerprint;
    }
}
