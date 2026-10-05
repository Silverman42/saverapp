<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $business_id
 * @property string $display_name
 * @property string $timezone
 * @property string|null $legal_name
 * @property string|null $support_email
 * @property string|null $support_phone
 * @property string|null $address
 * @property string|null $invitation_sender_email
 * @property string|null $invitation_sender_name
 * @property bool $is_invitation_sender_verified
 * @property int $version
 * @property string|null $emergency_key_hash
 * @property Carbon|null $emergency_key_issued_at
 * @property int|null $emergency_admin_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'business_id',
    'display_name',
    'timezone',
    'legal_name',
    'support_email',
    'support_phone',
    'address',
    'invitation_sender_email',
    'invitation_sender_name',
    'is_invitation_sender_verified',
    'version',
])]
class BusinessProfile extends Model
{
    protected $hidden = ['emergency_key_hash'];

    protected static function booted(): void
    {
        static::updating(function (self $profile): void {
            if ($profile->isDirty('business_id') || ($profile->getOriginal('effective_configuration_id') !== null && $profile->isDirty(['display_name', 'legal_name', 'support_email', 'support_phone', 'address', 'timezone', 'version', 'effective_configuration_id']))) {
                throw new \LogicException('Publish a new configuration version instead of editing trusted business identity or effective settings.');
            }
        });
        static::deleting(function (): void {
            throw new \LogicException('The trusted business identity cannot be deleted.');
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_invitation_sender_verified' => 'boolean',
            'version' => 'integer',
            'emergency_key_issued_at' => 'datetime',
        ];
    }

    /**
     * Get the singleton trusted business profile for the platform.
     */
    public static function current(): self
    {
        return static::query()->sole();
    }
}
