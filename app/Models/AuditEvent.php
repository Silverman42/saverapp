<?php

namespace App\Models;

use App\Services\AuditCapture;
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
     * @param  array<string, mixed>  $context
     */
    public static function record(
        string $eventType,
        string $targetType,
        ?int $targetId,
        ?string $targetReference,
        array $payload,
        ?User $actor = null,
        array $context = [],
    ): self {
        $request = app('request');
        if ($actor !== null && $request->hasSession() && $request->user()?->id === $actor->id && ! array_key_exists('fresh_authentication', $context)) {
            $context['fresh_authentication'] = (int) $request->session()->get('auth.fresh_until', 0) >= now()->timestamp;
        }

        return app(AuditCapture::class)->record(
            $eventType, $targetType, $targetId, $targetReference, $payload, $actor, $context,
        );
    }
}
