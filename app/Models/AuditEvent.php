<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $event_type
 * @property int|null $actor_id
 * @property string|null $actor_type
 * @property string $target_type
 * @property int|null $target_id
 * @property string|null $target_reference
 * @property array<string, mixed> $payload
 * @property Carbon $created_at
 */
#[Fillable([
    'event_type',
    'actor_id',
    'actor_type',
    'target_type',
    'target_id',
    'target_reference',
    'payload',
])]
class AuditEvent extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Get the actor who performed the audited event.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Record a canonical append-only audit event.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function record(
        string $eventType,
        string $targetType,
        ?int $targetId,
        ?string $targetReference,
        array $payload,
        ?User $actor = null,
    ): self {
        return static::create([
            'event_type' => $eventType,
            'actor_id' => $actor?->id,
            'actor_type' => $actor?->user_type?->value,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'target_reference' => $targetReference,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }
}
