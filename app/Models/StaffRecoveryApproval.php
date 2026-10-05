<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One distinct approver's decision on a staff assisted recovery.
 *
 * @property int $id
 * @property int $staff_recovery_id
 * @property int $approver_user_id
 * @property string $reason
 * @property Carbon $created_at
 */
class StaffRecoveryApproval extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $hidden = ['reason'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['reason' => 'encrypted', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }
}
