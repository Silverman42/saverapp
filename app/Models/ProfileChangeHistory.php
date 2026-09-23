<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

#[Fillable([
    'operation_id', 'event_type', 'target_type', 'target_id', 'subject_user_id', 'actor_id', 'actor_type',
    'audit_event_id', 'changed_fields', 'before_values', 'after_values', 'reason', 'from_version', 'to_version', 'created_at',
])]
class ProfileChangeHistory extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'changed_fields' => 'array',
            'before_values' => 'encrypted:array',
            'after_values' => 'encrypted:array',
            'reason' => 'encrypted',
            'created_at' => 'datetime',
            'from_version' => 'integer',
            'to_version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new RuntimeException('Profile change history is append-only.');
        });

        static::deleting(static function (): never {
            throw new RuntimeException('Profile change history is append-only.');
        });
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }
}
