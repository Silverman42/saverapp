<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * @property int $id
 * @property string $posting_reference
 * @property string $idempotency_key
 * @property string $payload_hash
 * @property string $source_type
 * @property string $source_id
 * @property string $event_type
 * @property string $currency
 * @property int|null $actor_user_id
 * @property int|null $customer_profile_id
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $occurred_on
 * @property string|null $business_timezone
 * @property int $schema_version
 * @property string|null $correlation_id
 * @property int|null $thrift_plan_id
 * @property CarbonImmutable $committed_at
 * @property array<string, mixed>|null $metadata
 */
#[Fillable([
    'posting_reference',
    'idempotency_key',
    'payload_hash',
    'source_type',
    'source_id',
    'event_type',
    'currency',
    'actor_user_id',
    'customer_profile_id',
    'occurred_at',
    'occurred_on',
    'business_timezone',
    'schema_version',
    'correlation_id',
    'thrift_plan_id',
    'committed_at',
    'metadata',
])]
class LedgerPostingGroup extends Model
{
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'committed_at' => 'immutable_datetime',
            'occurred_on' => 'immutable_date',
            'schema_version' => 'integer',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Ledger posting groups are immutable.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('Ledger posting groups cannot be deleted.');
        });
    }

    /**
     * Get the immutable ledger lines in this posting group.
     *
     * @return HasMany<LedgerEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'ledger_posting_group_id')->orderBy('line_number');
    }

    /**
     * Get the user who submitted the owning workflow command.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * Get the Customer dimension attached to this posting group.
     *
     * @return BelongsTo<CustomerProfile, $this>
     */
    public function customerProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }
}
