<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $code_hash
 * @property Carbon|null $consumed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'code_hash',
    'consumed_at',
])]
class UserRecoveryCode extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'two_factor_recovery_codes';

    /**
     * Get the user that owns the recovery code.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Determine if this recovery code has been consumed.
     */
    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    /**
     * Mark this recovery code as consumed.
     */
    public function consume(?CarbonInterface $consumedAt = null): void
    {
        $this->update([
            'consumed_at' => $consumedAt ?? Carbon::now(),
        ]);
    }

    /**
     * Scope query to only include unconsumed recovery codes.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUnconsumed(Builder $query): Builder
    {
        return $query->whereNull('consumed_at');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'consumed_at' => 'datetime',
        ];
    }
}
