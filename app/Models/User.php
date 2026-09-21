<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AccountState;
use App\Enums\AuthenticatorState;
use App\Enums\UserType;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Services\RoleSynchronizationService;
use App\Support\IdentityNormalizer;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\TwoFactorAuthenticatable;
use RuntimeException;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $email_normalized
 * @property UserType $user_type
 * @property AccountState $account_state
 * @property int $permission_version
 * @property AuthenticatorState $authenticator_state
 * @property Carbon|null $locked_until
 * @property string|null $lock_category
 * @property string|null $lock_reason
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property Carbon|null $two_factor_confirmed_at
 * @property int|null $two_factor_last_used_timestep
 * @property string|null $two_factor_pending_secret
 * @property string|null $two_factor_pending_purpose
 * @property Carbon|null $two_factor_pending_expires_at
 * @property int|null $two_factor_pending_last_used_timestep
 * @property Carbon|null $recovery_codes_acknowledged_at
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
    'permission_version',
    'authenticator_state',
    'locked_until',
    'lock_category',
    'lock_reason',
    'two_factor_secret',
    'two_factor_confirmed_at',
    'two_factor_last_used_timestep',
    'two_factor_pending_secret',
    'two_factor_pending_purpose',
    'two_factor_pending_expires_at',
    'two_factor_pending_last_used_timestep',
    'recovery_codes_acknowledged_at',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_pending_secret', 'remember_token', 'roles', 'permissions', 'authorizationRestrictions'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    /**
     * The guard name for Spatie permissions.
     */
    protected string $guard_name = 'web';

    /**
     * Flag used to simulate historical attribution presence prior to financial ledger wiring.
     */
    public bool $forceHasHistoricalAttribution = false;

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::created(function (User $user): void {
            app(RoleSynchronizationService::class)->synchronize($user);
        });

        static::saving(function (User $user): void {
            if (filled($user->email)) {
                $user->email_normalized = IdentityNormalizer::normalizeEmail($user->email);
            }
        });

        static::updating(function (User $user): void {
            if ($user->isDirty('user_type')) {
                throw new RuntimeException('Cannot change user_type on an existing user; roles are immutable.');
            }

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
        if ($this->locked_until !== null && $this->locked_until->isFuture()) {
            if ($category === null || $this->lock_category === $category) {
                return true;
            }
        }

        if ($this->exists) {
            $query = $this->activeLocks();
            if ($category !== null) {
                $query->where('lock_category', $category);
            }

            if ($query->exists()) {
                return true;
            }
        }

        return false;
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
    public function unlock(?string $category = null, ?User $unlockedBy = null, ?string $reason = null): void
    {
        if ($category === null || $this->lock_category === $category) {
            $this->locked_until = null;
            $this->lock_category = null;
            $this->lock_reason = null;
            $this->save();
        }

        if ($this->exists) {
            $locks = $this->activeLocks();
            if ($category !== null) {
                $locks->where('lock_category', $category);
            }
            $updateData = [
                'unlocked_at' => Carbon::now(),
            ];
            if ($unlockedBy !== null) {
                $updateData['unlocked_by_user_id'] = $unlockedBy->id;
                $updateData['unlock_reason'] = $reason ?? 'Manually unlocked by administrator after identity verification';
            }
            $locks->update($updateData);
        }
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
    public function canSignIn(?string $method = null, bool $allowSetupOnly = false): bool
    {
        $canSign = $this->account_state->canSignIn() || ($allowSetupOnly && $this->account_state->allowsSetupOnly());

        if (! $canSign) {
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

        if ($this->customerProfile()->exists() || $this->agentProfile()->exists()) {
            return true;
        }

        return false;
    }

    /**
     * Get the customer profile associated with this user.
     *
     * @return HasOne<CustomerProfile, $this>
     */
    public function customerProfile(): HasOne
    {
        return $this->hasOne(CustomerProfile::class, 'user_id');
    }

    /**
     * Get the agent profile associated with this user.
     *
     * @return HasOne<AgentProfile, $this>
     */
    public function agentProfile(): HasOne
    {
        return $this->hasOne(AgentProfile::class, 'user_id');
    }

    /**
     * Get the user's recovery codes.
     *
     * @return HasMany<UserRecoveryCode, $this>
     */
    public function recoveryCodes(): HasMany
    {
        return $this->hasMany(UserRecoveryCode::class);
    }

    /**
     * Get all authentication lock records for this user.
     *
     * @return HasMany<AuthenticationLock, $this>
     */
    public function authenticationLocks(): HasMany
    {
        return $this->hasMany(AuthenticationLock::class);
    }

    /**
     * Get permission grant history records for this user.
     *
     * @return HasMany<PermissionGrantHistory, $this>
     */
    public function permissionGrantHistories(): HasMany
    {
        return $this->hasMany(PermissionGrantHistory::class);
    }

    /**
     * Get active authentication locks for this user.
     *
     * @return HasMany<AuthenticationLock, $this>
     */
    public function activeLocks(): HasMany
    {
        return $this->hasMany(AuthenticationLock::class)
            ->whereNull('unlocked_at')
            ->where('locked_until', '>', Carbon::now());
    }

    /**
     * Determine if two-factor authentication has been enabled and confirmed.
     */
    public function hasEnabledTwoFactorAuthentication(): bool
    {
        return ! is_null($this->two_factor_secret) && ! is_null($this->two_factor_confirmed_at);
    }

    /**
     * Determine if two-factor authentication has been confirmed.
     */
    public function hasConfirmedTwoFactor(): bool
    {
        return $this->hasEnabledTwoFactorAuthentication();
    }

    /**
     * Get the count of unconsumed recovery codes.
     */
    public function unconsumedRecoveryCodesCount(): int
    {
        return $this->recoveryCodes()->whereNull('consumed_at')->count();
    }

    /**
     * Determine if recovery codes have been acknowledged.
     */
    public function hasAcknowledgedRecoveryCodes(): bool
    {
        return $this->recovery_codes_acknowledged_at !== null;
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
     * Get the trusted device records for the user.
     *
     * @return HasMany<AgentTrustedDevice, $this>
     */
    public function trustedDevices(): HasMany
    {
        return $this->hasMany(AgentTrustedDevice::class);
    }

    /**
     * Get maximum permitted concurrent devices for this user type.
     */
    public function maxConcurrentDevices(): int
    {
        return match ($this->user_type) {
            UserType::Customer => 5,
            UserType::Agent => 2,
            UserType::Admin => 1,
        };
    }

    /**
     * Get inactivity timeout in seconds for this user type.
     */
    public function inactivityTimeoutSeconds(): int
    {
        return match ($this->user_type) {
            UserType::Customer => 7 * 86400, // 7 days
            UserType::Agent => 3600,         // 1 hour
            UserType::Admin => 1800,         // 30 minutes
        };
    }

    /**
     * Get maximum session lifetime in seconds for this user type.
     */
    public function maximumSessionLifetimeSeconds(): int
    {
        return match ($this->user_type) {
            UserType::Customer => 30 * 86400, // 30 days
            UserType::Agent => 86400,         // 24 hours
            UserType::Admin => 86400,         // 24 hours
        };
    }

    /**
     * Revoke all database sessions for this user, optionally preserving one.
     */
    public function revokeAllSessions(?string $exceptSessionId = null): int
    {
        $table = config('session.table', 'sessions');

        return DB::table($table)
            ->where('user_id', $this->id)
            ->when($exceptSessionId, fn ($q) => $q->where('id', '!=', $exceptSessionId))
            ->delete();
    }

    /**
     * Revoke all trusted devices for this user.
     */
    public function revokeAllTrustedDevices(): int
    {
        return $this->trustedDevices()->delete();
    }

    /**
     * Get the authorization restrictions for the user.
     *
     * @return HasMany<AuthorizationRestriction, $this>
     */
    public function authorizationRestrictions(): HasMany
    {
        return $this->hasMany(AuthorizationRestriction::class);
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
            'two_factor_last_used_timestep' => 'integer',
            'two_factor_pending_expires_at' => 'datetime',
            'two_factor_pending_last_used_timestep' => 'integer',
            'recovery_codes_acknowledged_at' => 'datetime',
            'user_type' => UserType::class,
            'account_state' => AccountState::class,
            'permission_version' => 'integer',
            'authenticator_state' => AuthenticatorState::class,
            'locked_until' => 'datetime',
        ];
    }
}
