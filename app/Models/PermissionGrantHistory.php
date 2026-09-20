<?php

namespace App\Models;

use App\Enums\AdminPermission;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $batch_id
 * @property int $user_id
 * @property AdminPermission|string $permission_code
 * @property string $action
 * @property string $source
 * @property int|null $actor_user_id
 * @property string|null $reason
 * @property int $permission_version
 * @property Carbon|null $created_at
 * @property User $user
 * @property User|null $actor
 */
#[Fillable([
    'batch_id',
    'user_id',
    'permission_code',
    'action',
    'source',
    'actor_user_id',
    'reason',
    'permission_version',
])]
class PermissionGrantHistory extends Model
{
    /**
     * Disable updated_at since permission grant history is append-only.
     */
    public const UPDATED_AT = null;

    /**
     * The target user (Administrator) of the permission grant.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The actor user who performed the grant or revocation (null for system_seed).
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'permission_code' => AdminPermission::class,
            'permission_version' => 'integer',
        ];
    }
}
