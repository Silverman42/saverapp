<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $reference
 * @property int $customer_profile_id
 * @property int $requested_by_user_id
 * @property int|null $verified_assignment_id
 * @property int|null $approved_by_user_id
 * @property int|null $open_customer_id
 * @property string $state
 * @property int $version
 * @property string $previous_email
 * @property string $proposed_email
 * @property string|null $proposed_email_normalized
 * @property string|null $activation_token_hash
 * @property Carbon|null $activation_expires_at
 * @property Carbon $request_expires_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $completed_at
 * @property CustomerProfile $customerProfile
 */
class CustomerRecovery extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['previous_email', 'proposed_email', 'proposed_email_normalized', 'activation_token_hash'];

    protected function casts(): array
    {
        return ['previous_email' => 'encrypted', 'proposed_email' => 'encrypted', 'version' => 'integer',
            'request_expires_at' => 'datetime', 'activation_expires_at' => 'datetime', 'approved_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customerProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }
}
