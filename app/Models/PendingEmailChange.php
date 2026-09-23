<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property User $user
 * @property string $current_email
 * @property string $proposed_email
 * @property string $proposed_email_normalized
 * @property string $current_token_hash
 * @property string $proposed_token_hash
 * @property Carbon|null $current_confirmed_at
 * @property Carbon|null $proposed_confirmed_at
 * @property Carbon $expires_at
 */
#[Fillable(['user_id', 'current_email', 'proposed_email', 'proposed_email_normalized', 'current_token_hash', 'proposed_token_hash', 'current_confirmed_at', 'proposed_confirmed_at', 'expires_at'])]
class PendingEmailChange extends Model
{
    protected function casts(): array
    {
        return [
            'current_email' => 'encrypted',
            'proposed_email' => 'encrypted',
            'current_confirmed_at' => 'datetime',
            'proposed_confirmed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<PendingEmailChange>  $query
     * @return Builder<PendingEmailChange>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }
}
