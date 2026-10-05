<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Assisted recovery of an Agent or Admin account (Module 02 §7.8.3–7.8.4).
 *
 * @property int $id
 * @property string $reference
 * @property int $user_id
 * @property int $requested_by_user_id
 * @property int|null $open_user_id
 * @property string $state
 * @property int $version
 * @property int $required_approvals
 * @property string $previous_email
 * @property string $proposed_email
 * @property string|null $proposed_email_normalized
 * @property string $procedure_reference
 * @property string $verification_notes
 * @property Carbon $verified_at
 * @property string|null $activation_token_hash
 * @property Carbon|null $activation_expires_at
 * @property Carbon $request_expires_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $completed_at
 * @property string $kind
 * @property Carbon|null $created_at
 * @property User $user
 * @property User $requestedBy
 */
class StaffRecovery extends Model
{
    public const OPEN_STATES = ['awaiting_approval', 'awaiting_activation', 'activation_expired'];

    protected $guarded = ['id'];

    protected $hidden = ['previous_email', 'proposed_email', 'proposed_email_normalized', 'verification_notes', 'activation_token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_email' => 'encrypted',
            'proposed_email' => 'encrypted',
            'verification_notes' => 'encrypted',
            'version' => 'integer',
            'required_approvals' => 'integer',
            'verified_at' => 'datetime',
            'request_expires_at' => 'datetime',
            'activation_expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return HasMany<StaffRecoveryApproval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(StaffRecoveryApproval::class);
    }
}
