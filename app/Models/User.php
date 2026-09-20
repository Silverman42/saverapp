<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AccountState;
use App\Enums\UserType;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Support\IdentityNormalizer;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;
use RuntimeException;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $email_normalized
 * @property UserType $user_type
 * @property AccountState $account_state
 * @property Carbon|null $locked_until
 * @property string|null $lock_category
 * @property string|null $lock_reason
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name',
    'email',
    'email_normalized',
    'password',
    'user_type',
    'account_state',
    'locked_until',
    'lock_category',
    'lock_reason',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Flag used to simulate historical attribution presence prior to financial ledger wiring.
     */
    public bool $forceHasHistoricalAttribution = false;

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->email !== null) {
                $user->email_normalized = IdentityNormalizer::normalizeEmail($user->email);
            }
        });

        static::updating(function (User $user): void {
            $originalUserType = $user->getOriginal('user_type');
            $originalAccountState = $user->getOriginal('account_state');

            $origUserTypeValue = $originalUserType instanceof UserType ? $originalUserType->value : $originalUserType;
            $origStateValue = $originalAccountState instanceof AccountState ? $originalAccountState->value : $originalAccountState;

            $isLosingActiveAdmin = ($user->isDirty('account_state') && in_array($user->account_state, [AccountState::Suspended, AccountState::Deactivated], true))
                || ($user->isDirty('user_type') && $user->user_type !== UserType::Admin);

            if ($origUserTypeValue === UserType::Admin->value && $origStateValue === AccountState::Active->value && $isLosingActiveAdmin && $user->isFinalActiveAdmin()) {
                throw new RuntimeException('Cannot suspend or deactivate the final active Administrator.');
            }
        });

        static::deleting(function (User $user): void {
            if ($user->hasHistoricalAttribution()) {
                throw new RuntimeException('Cannot delete user with historical attribution; deactivate the account instead.');
            }

            if ($user->isFinalActiveAdmin()) {
                throw new RuntimeException('Cannot delete the final active Administrator.');
            }
        });
    }

    /**
     * Scope query to find records by normalized email address.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeWhereNormalizedEmail(Builder $query, string $email): Builder
    {
        return $query->where('email_normalized', IdentityNormalizer::normalizeEmail($email));
    }

    /**
     * Find a user by their normalized email address.
     */
    public static function findByNormalizedEmail(string $email): ?self
    {
        return static::whereNormalizedEmail($email)->first();
    }

    /**
     * Determine whether the user is currently temporarily locked.
     */
    public function isTemporarilyLocked(?string $category = null): bool
    {
        if ($this->locked_until === null || ! $this->locked_until->isFuture()) {
            return false;
        }

        return $category === null || $this->lock_category === $category;
    }

    /**
     * Lock the user temporarily without altering their underlying account state.
     */
    public function lockTemporarily(CarbonInterface|int $durationOrUntil, ?string $category = null, ?string $reason = null): void
    {
        $this->locked_until = is_int($durationOrUntil)
            ? Carbon::now()->addMinutes($durationOrUntil)
            : Carbon::instance($durationOrUntil);
        $this->lock_category = $category;
        $this->lock_reason = $reason;
        $this->save();
    }

    /**
     * Clear any temporary lock on the account.
     */
    public function unlock(): void
    {
        $this->locked_until = null;
        $this->lock_category = null;
        $this->lock_reason = null;
        $this->save();
    }

    /**
     * Return the effective account state, presenting TemporarilyLocked if an active lock exists.
     */
    public function effectiveAccountState(): AccountState
    {
        if ($this->isTemporarilyLocked()) {
            return AccountState::TemporarilyLocked;
        }

        return $this->account_state;
    }

    /**
     * Determine if the user is allowed to sign in.
     */
    public function canSignIn(?string $method = null): bool
    {
        if (! $this->account_state->canSignIn()) {
            return false;
        }

        if ($this->isTemporarilyLocked($method)) {
            return false;
        }

        return true;
    }

    /**
     * Determine if this user is the only remaining active Administrator.
     */
    public function isFinalActiveAdmin(): bool
    {
        $userType = $this->getOriginal('user_type') ?? $this->user_type;
        $accountState = $this->getOriginal('account_state') ?? $this->account_state;

        $userTypeValue = $userType instanceof UserType ? $userType->value : $userType;
        $accountStateValue = $accountState instanceof AccountState ? $accountState->value : $accountState;

        if ($userTypeValue !== UserType::Admin->value || $accountStateValue !== AccountState::Active->value) {
            return false;
        }

        return static::where('user_type', UserType::Admin->value)
            ->where('account_state', AccountState::Active->value)
            ->where('id', '!=', $this->id)
            ->doesntExist();
    }

    /**
     * Check whether the user has associated historical actions or financial attribution.
     */
    public function hasHistoricalAttribution(): bool
    {
        if ($this->forceHasHistoricalAttribution) {
            return true;
        }

        return false;
    }

    /**
     * Send the password reset notification using a queued notification.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'user_type' => UserType::class,
            'account_state' => AccountState::class,
            'locked_until' => 'datetime',
        ];
    }
}
