<?php

namespace App\Models;

use App\Enums\CustomerStatus;
use App\Enums\Gender;
use App\Support\InternalReferenceNormalizer;
use App\Support\PhoneNormalizer;
use Database\Factories\CustomerProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;

/**
 * @property int $id
 * @property int $user_id
 * @property string $customer_id
 * @property string $phone
 * @property string $phone_normalized
 * @property string|null $address
 * @property Gender|null $gender
 * @property string|null $occupation
 * @property string|null $photo_path
 * @property string|null $notes
 * @property string|null $internal_reference
 * @property string|null $internal_reference_normalized
 * @property array<string, mixed>|null $next_of_kin
 * @property CustomerStatus $operational_status
 * @property int $version
 * @property int|null $created_by_user_id
 * @property int|null $updated_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'customer_id',
    'phone',
    'phone_normalized',
    'address',
    'gender',
    'occupation',
    'photo_path',
    'notes',
    'internal_reference',
    'internal_reference_normalized',
    'next_of_kin',
    'operational_status',
    'version',
    'created_by_user_id',
    'updated_by_user_id',
])]
class CustomerProfile extends Model
{
    /** @use HasFactory<CustomerProfileFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'operational_status' => CustomerStatus::class,
            'gender' => Gender::class,
            'next_of_kin' => 'array',
            'version' => 'integer',
        ];
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::saving(function (CustomerProfile $profile): void {
            if (filled($profile->phone)) {
                $normalized = PhoneNormalizer::normalize($profile->phone);
                if ($normalized === null) {
                    throw new InvalidArgumentException("Invalid phone number format [{$profile->phone}].");
                }
                $profile->phone_normalized = $normalized;
            }

            if (filled($profile->internal_reference)) {
                if (! InternalReferenceNormalizer::isValid($profile->internal_reference)) {
                    throw new InvalidArgumentException("Invalid internal reference format [{$profile->internal_reference}].");
                }
                $profile->internal_reference_normalized = InternalReferenceNormalizer::normalize($profile->internal_reference);
            } else {
                $profile->internal_reference = null;
                $profile->internal_reference_normalized = null;
            }

            if ($profile->next_of_kin !== null) {
                $profile->next_of_kin = static::sanitizeNextOfKin($profile->next_of_kin);
            }
        });

        static::updating(function (CustomerProfile $profile): void {
            if ($profile->isDirty('customer_id')) {
                throw new RuntimeException('Cannot change immutable customer_id.');
            }

            if ($profile->isDirty('user_id')) {
                throw new RuntimeException('Cannot change user_id on an existing customer profile.');
            }
        });
    }

    /**
     * Sanitize and validate next-of-kin structure.
     * Section 2.5: If every contact field is empty, save no contact.
     * If any contact field is supplied, validate all required contact fields together.
     *
     * @param  array<string, mixed>  $contact
     * @return array<string, mixed>|null
     */
    public static function sanitizeNextOfKin(array $contact): ?array
    {
        $fullName = trim((string) ($contact['full_name'] ?? ''));
        $relationship = trim((string) ($contact['relationship'] ?? ''));
        $phone = trim((string) ($contact['phone'] ?? ''));
        $address = trim((string) ($contact['address'] ?? ''));

        if ($fullName === '' && $relationship === '' && $phone === '' && $address === '') {
            return null;
        }

        if ($fullName === '' || $relationship === '' || $phone === '') {
            throw new InvalidArgumentException('Next of kin requires full name, relationship, and valid phone number when contact is provided.');
        }

        if (mb_strlen($fullName) > 150) {
            throw new InvalidArgumentException('Next of kin full name must not exceed 150 characters.');
        }

        if (mb_strlen($relationship) > 50) {
            throw new InvalidArgumentException('Next of kin relationship must not exceed 50 characters.');
        }

        if (mb_strlen($address) > 500) {
            throw new InvalidArgumentException('Next of kin address must not exceed 500 characters.');
        }

        $normalizedPhone = PhoneNormalizer::normalize($phone);
        if ($normalizedPhone === null) {
            throw new InvalidArgumentException('Next of kin phone number is invalid.');
        }

        return [
            'full_name' => $fullName,
            'relationship' => $relationship,
            'phone' => $phone,
            'phone_normalized' => $normalizedPhone,
            'address' => $address !== '' ? $address : null,
        ];
    }

    /**
     * Get the user authentication account linked to this customer profile.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the user who created this customer profile.
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Get the user who last updated this customer profile.
     *
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * Get all assignment history records for this customer profile.
     *
     * @return HasMany<CustomerAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(CustomerAssignment::class, 'customer_profile_id')->orderBy('version', 'desc');
    }

    /**
     * Get the current effective assignment for this customer profile.
     *
     * @return HasOne<CustomerAssignment, $this>
     */
    public function currentAssignment(): HasOne
    {
        return $this->hasOne(CustomerAssignment::class, 'customer_profile_id')->where('is_current', 1);
    }

    /**
     * Get this customer's original registration fee agreement.
     *
     * @return HasOne<FeeSnapshot, $this>
     */
    public function feeSnapshot(): HasOne
    {
        return $this->hasOne(FeeSnapshot::class, 'customer_profile_id')
            ->where('kind', 'registration')
            ->where('source_type', 'registration')
            ->whereHas('customerProfile', fn (Builder $query): Builder => $query
                ->where(fn (Builder $source): Builder => $source
                    ->whereColumn('fee_snapshots.source_id', 'customer_profiles.customer_id')
                    ->orWhereRaw('fee_snapshots.source_id = CAST(customer_profiles.id AS CHAR)')));
    }

    /**
     * Get every immutable fee snapshot attached to this customer.
     *
     * @return HasMany<FeeSnapshot, $this>
     */
    public function feeSnapshots(): HasMany
    {
        return $this->hasMany(FeeSnapshot::class, 'customer_profile_id')->orderBy('id');
    }

    /**
     * Get fee obligations for this customer profile.
     *
     * @return HasMany<FeeObligation, $this>
     */
    public function feeObligations(): HasMany
    {
        return $this->hasMany(FeeObligation::class, 'customer_profile_id')->orderBy('id');
    }

    /** @return HasMany<ThriftPlan, $this> */
    public function thriftPlans(): HasMany
    {
        return $this->hasMany(ThriftPlan::class, 'customer_profile_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Get the status histories for this customer profile.
     *
     * @return HasMany<CustomerStatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(CustomerStatusHistory::class, 'customer_profile_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Scope query to active customers.
     *
     * @param  Builder<CustomerProfile>  $query
     * @return Builder<CustomerProfile>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('operational_status', CustomerStatus::Active);
    }

    /**
     * Scope query to archived customers.
     *
     * @param  Builder<CustomerProfile>  $query
     * @return Builder<CustomerProfile>
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('operational_status', CustomerStatus::Archived);
    }

    /**
     * Find profile by normalized phone.
     *
     * @param  Builder<CustomerProfile>  $query
     * @return Builder<CustomerProfile>
     */
    public function scopeWhereNormalizedPhone(Builder $query, string $phone): Builder
    {
        $normalized = PhoneNormalizer::normalize($phone);

        return $query->where('phone_normalized', $normalized);
    }

    /**
     * Find profile by normalized internal reference.
     *
     * @param  Builder<CustomerProfile>  $query
     * @return Builder<CustomerProfile>
     */
    public function scopeWhereNormalizedReference(Builder $query, string $reference): Builder
    {
        $normalized = InternalReferenceNormalizer::normalize($reference);

        return $query->where('internal_reference_normalized', $normalized);
    }

    public function isActive(): bool
    {
        return $this->operational_status === CustomerStatus::Active;
    }

    public function isInactive(): bool
    {
        return $this->operational_status === CustomerStatus::Inactive;
    }

    public function isRestricted(): bool
    {
        return $this->operational_status === CustomerStatus::Restricted;
    }

    public function isArchived(): bool
    {
        return $this->operational_status === CustomerStatus::Archived;
    }
}
