<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $customer_profile_id
 * @property int $requested_by_user_id
 * @property string $status
 * @property int $profile_version
 * @property Carbon $expires_at
 * @property Carbon|null $resolved_at
 */
#[Fillable(['customer_profile_id', 'requested_by_user_id', 'current_name', 'proposed_name', 'reason', 'profile_version', 'status', 'expires_at', 'resolved_by_user_id', 'resolved_at'])]
class CustomerNameCorrection extends Model
{
    protected function casts(): array
    {
        return [
            'current_name' => 'encrypted',
            'proposed_name' => 'encrypted',
            'reason' => 'encrypted',
            'profile_version' => 'integer',
            'expires_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customerProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
