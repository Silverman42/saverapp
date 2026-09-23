<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

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
        ];
    }

    /**
     * Get the singleton trusted business profile for the platform.
     */
    public static function current(): self
    {
        $profile = static::query()->first();

        if (! $profile) {
            throw new RuntimeException('The trusted business identity has not been initialized.');
        }

        return $profile;
    }
}
