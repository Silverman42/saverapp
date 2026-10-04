<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CustomerPayoutDestinationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * @property int $id
 * @property string $destination_reference
 * @property int $customer_profile_id
 * @property int $version
 * @property string $status
 * @property string $provider_key
 * @property string $bank_code
 * @property string $bank_name
 * @property string $account_token
 * @property string $account_fingerprint
 * @property string $account_mask
 * @property string $verified_payee_name
 * @property string $name_match
 * @property int|null $verified_by_user_id
 * @property CarbonImmutable|null $verified_at
 */
#[Fillable(['destination_reference', 'registration_reference', 'customer_profile_id', 'version', 'active_customer_profile_id', 'status', 'provider_key', 'bank_code', 'bank_name', 'account_token', 'account_fingerprint', 'account_mask', 'verified_payee_name', 'name_match', 'registration_attestation', 'registered_by_user_id', 'verified_by_user_id', 'revoked_by_user_id', 'decision_reason', 'payload_hash', 'verified_at', 'superseded_at', 'revoked_at'])]
class CustomerPayoutDestination extends Model
{
    /** @use HasFactory<CustomerPayoutDestinationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $hidden = ['account_token', 'account_fingerprint', 'verified_payee_name', 'registration_attestation', 'decision_reason', 'payload_hash'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'account_token' => 'encrypted', 'verified_payee_name' => 'encrypted',
            'registration_attestation' => 'encrypted', 'decision_reason' => 'encrypted',
            'verified_at' => 'immutable_datetime', 'superseded_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $destination): void {
            if ($destination->isDirty(['destination_reference', 'customer_profile_id', 'version', 'provider_key', 'bank_code', 'bank_name',
                'account_token', 'account_fingerprint', 'account_mask', 'verified_payee_name', 'name_match', 'registration_attestation',
                'registered_by_user_id', 'payload_hash'])) {
                throw new RuntimeException('A payout destination identity is immutable; register a new version.');
            }
        });
        static::deleting(function (): never {
            throw new RuntimeException('Payout destinations are retained with payout history.');
        });
    }

    public function getRouteKeyName(): string
    {
        return 'destination_reference';
    }

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customerProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }
}
