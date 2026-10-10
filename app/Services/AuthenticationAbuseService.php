<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Enums\LockNotificationStatus;
use App\Enums\UnlockVerificationMethod;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\AuthenticationLock;
use App\Models\User;
use App\Notifications\Auth\AccountUnlockedNotification;
use App\Notifications\Auth\CompromiseSessionRevocationNotification;
use App\Notifications\Auth\MfaCooldownNotification;
use App\Notifications\Auth\PasswordLockoutNotification;
use App\Notifications\Auth\RecoveryCodeCooldownNotification;
use App\Notifications\Auth\TwoFactorFailedAttemptsExceededNotification;
use App\Support\IdentityNormalizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthenticationAbuseService
{
    public function __construct(
        protected AuthorizationService $authorizationService,
    ) {}

    /**
     * Check if password authentication is currently restricted for this identity or IP.
     */
    public function isPasswordRestricted(string $rawEmail, ?User $user = null, ?Request $request = null): bool
    {
        $normalized = IdentityNormalizer::normalizeEmail($rawEmail);

        // Check if user model is temporarily locked for password
        if ($user && $user->isTemporarilyLocked('password')) {
            return true;
        }

        // Check cache cooldown or lock for this email
        $cooldownUntil = Cache::get("auth:password:cooldown:{$normalized}");
        if ($cooldownUntil !== null && (int) $cooldownUntil > Carbon::now()->timestamp) {
            return true;
        }

        // Check restrictions from distributed attacks that no single source reveals
        $distributedUntil = Cache::get("auth:distributed:restricted:{$normalized}");
        if ($distributedUntil !== null && (int) $distributedUntil > Carbon::now()->timestamp) {
            return true;
        }

        // Check source-level automated abuse
        if ($request && $this->isSourceAbusive($request)) {
            return true;
        }

        return false;
    }

    /**
     * Record a failed password attempt and apply progressive restrictions.
     */
    public function recordPasswordFailure(string $rawEmail, Request $request, ?User $user = null): void
    {
        $normalized = IdentityNormalizer::normalizeEmail($rawEmail);
        $now = Carbon::now();

        // 1. Record account-level failure timestamp in 24-hour sliding window
        $key = "auth:password:failures:{$normalized}";
        $timestamps = (array) Cache::get($key, []);
        $cutoff24h = $now->copy()->subHours(24)->timestamp;
        $timestamps = array_values(array_filter($timestamps, fn ($t) => (int) $t >= $cutoff24h));
        $timestamps[] = $now->timestamp;
        Cache::put($key, $timestamps, $now->copy()->addHours(24));

        // 2. Track source-level IP activity for distributed abuse / credential stuffing detection
        $ip = $request->ip() ?? 'unknown';
        $ipKey = "auth:ip:failures:{$ip}";
        $ipTimestamps = (array) Cache::get($ipKey, []);
        $cutoff15m = $now->copy()->subMinutes(15)->timestamp;
        $ipTimestamps = array_values(array_filter($ipTimestamps, fn ($t) => (int) $t >= $cutoff15m));
        $ipTimestamps[] = $now->timestamp;
        Cache::put($ipKey, $ipTimestamps, $now->copy()->addMinutes(15));

        // Track distinct targeted emails per IP
        $ipAccountsKey = "auth:ip:accounts:{$ip}";
        $ipAccounts = (array) Cache::get($ipAccountsKey, []);
        if (! in_array($normalized, $ipAccounts, true)) {
            $ipAccounts[] = $normalized;
        }
        Cache::put($ipAccountsKey, $ipAccounts, $now->copy()->addMinutes(15));

        $this->detectDistributedAttack($normalized, $ip, $now);

        // 3. Calculate failure counts
        $count15m = count(array_filter($timestamps, fn ($t) => (int) $t >= $cutoff15m));
        $cutoff1h = $now->copy()->subHours(1)->timestamp;
        $count1h = count(array_filter($timestamps, fn ($t) => (int) $t >= $cutoff1h));
        $count24h = count($timestamps);

        try {
            AuditEvent::record('auth.password_failed', User::class, null, null,
                ['attempt_count' => $count24h], null, ['executor' => self::class, 'outcome' => 'Denied', 'actor_category' => 'unknown']);
        } catch (\Throwable) {
            Log::warning('Authentication denial evidence unavailable.', ['event_code' => 'password_failed']);
        }

        // Check if account was already locked for password right before this attempt
        $wasAlreadyLocked = ($user && $user->isTemporarilyLocked('password'))
            || (Cache::has("auth:password:cooldown:{$normalized}") && (int) Cache::get("auth:password:cooldown:{$normalized}") > $now->timestamp);

        // Section 9.2: 20 failed attempts within 24 hours -> 1-hour lock + review flag
        if ($count24h >= 20) {
            $lockDurationMinutes = 60;
            $lockedUntil = $now->copy()->addMinutes($lockDurationMinutes);
            $reason = 'Excessive failed password attempts (20 within 24 hours)';

            Cache::put("auth:password:cooldown:{$normalized}", $lockedUntil->timestamp, $lockedUntil);

            $notifyOwner = $user && (! $wasAlreadyLocked || $count24h === 20);
            $lock = $this->captureLock([
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'user_id' => $user?->id,
                'email_normalized' => $normalized,
                'lock_category' => 'password',
                'reason' => $reason,
                'failed_attempts_count' => $count24h,
                'locked_at' => $now,
                'locked_until' => $lockedUntil,
                'requires_review' => true,
                'notification_status' => $notifyOwner ? LockNotificationStatus::Queued : LockNotificationStatus::NotSent,
            ], $user);

            Log::warning('Password lock (1 hour, review required) applied', [
                'failed_count' => $count24h,
            ]);

            if ($notifyOwner) {
                $user->notify((new PasswordLockoutNotification($lockDurationMinutes, $reason, $lock->id))->afterCommit());
            }

            return;
        }

        // Section 9.2: 10 failed attempts within 1 hour -> 15-minute lock + notification
        if ($count1h >= 10) {
            $lockDurationMinutes = 15;
            $lockedUntil = $now->copy()->addMinutes($lockDurationMinutes);
            $reason = 'Excessive failed password attempts (10 within 1 hour)';

            Cache::put("auth:password:cooldown:{$normalized}", $lockedUntil->timestamp, $lockedUntil);

            $notifyOwner = $user && (! $wasAlreadyLocked || $count1h === 10);
            $lock = $this->captureLock([
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'user_id' => $user?->id,
                'email_normalized' => $normalized,
                'lock_category' => 'password',
                'reason' => $reason,
                'failed_attempts_count' => $count1h,
                'locked_at' => $now,
                'locked_until' => $lockedUntil,
                'requires_review' => false,
                'notification_status' => $notifyOwner ? LockNotificationStatus::Queued : LockNotificationStatus::NotSent,
            ], $user);

            Log::warning('Password lock (15 minutes) applied', [
                'failed_count' => $count1h,
            ]);

            if ($notifyOwner) {
                $user->notify((new PasswordLockoutNotification($lockDurationMinutes, $reason, $lock->id))->afterCommit());
            }

            return;
        }

        // Section 9.2: 5–9 failed attempts within 15 minutes -> progressive cooldown delays
        // 5 failures: 1 min, 6: 2 min, 7: 3 min, 8: 4 min, 9: 5 min
        if ($count15m >= 5) {
            $delayMinutes = min(5, $count15m - 4);
            $cooldownUntil = $now->copy()->addMinutes($delayMinutes);
            Cache::put("auth:password:cooldown:{$normalized}", $cooldownUntil->timestamp, $cooldownUntil);

            Log::info('Password progressive cooldown delay applied', [
                'delay_minutes' => $delayMinutes,
                'failed_count' => $count15m,
            ]);
        }
    }

    /**
     * Record a successful complete password login.
     * Clears password failure counters and active password cooldown.
     */
    public function recordPasswordSuccess(User $user, Request $request): void
    {
        $normalized = IdentityNormalizer::normalizeEmail($user->email);
        $this->clearPasswordFailures($normalized, $user);
    }

    /**
     * Clear password failure counters and cooldowns for a given identity.
     */
    public function clearRecoveryActivationFailures(User $user, string $previousEmail): void
    {
        foreach (array_unique([IdentityNormalizer::normalizeEmail($previousEmail), $user->email_normalized]) as $email) {
            Cache::forget("auth:password:failures:{$email}");
            Cache::forget("auth:password:cooldown:{$email}");
        }
        foreach (['totp', 'recovery_code'] as $method) {
            Cache::forget("auth:{$method}:failures:{$user->id}");
            Cache::forget("auth:{$method}:cooldown:{$user->id}");
        }
    }

    public function clearPasswordFailures(string $emailNormalized, ?User $user = null): void
    {
        Cache::forget("auth:password:failures:{$emailNormalized}");
        Cache::forget("auth:password:cooldown:{$emailNormalized}");

        if ($user && ($user->lock_category === 'password' || $user->isTemporarilyLocked('password'))) {
            $user->unlock('password');
        }
    }

    /**
     * Check if TOTP verification is currently under a cooldown.
     */
    public function isTotpRestricted(User $user): bool
    {
        if ($user->isTemporarilyLocked('mfa')) {
            return true;
        }

        $cooldownUntil = Cache::get("auth:totp:cooldown:{$user->id}");
        if ($cooldownUntil !== null && (int) $cooldownUntil > Carbon::now()->timestamp) {
            return true;
        }

        return false;
    }

    /**
     * Record a failed TOTP attempt, enforcing session limits and sliding 1-hour cooldowns.
     */
    public function recordTotpFailure(User $user, Request $request, int $sessionAttempts): void
    {
        $now = Carbon::now();

        // 1. Record failure in 1-hour sliding window
        $key = "auth:totp:failures:{$user->id}";
        $timestamps = (array) Cache::get($key, []);
        $cutoff1h = $now->copy()->subHours(1)->timestamp;
        $timestamps = array_values(array_filter($timestamps, fn ($t) => (int) $t >= $cutoff1h));
        $timestamps[] = $now->timestamp;
        Cache::put($key, $timestamps, $now->copy()->addHour());

        $count1h = count($timestamps);
        $wasAlreadyLocked = $user->isTemporarilyLocked('mfa');

        try {
            AuditEvent::record('auth.mfa_failed', User::class, $user->id, null,
                ['category' => 'totp', 'attempt_count' => $count1h], null, ['executor' => self::class, 'outcome' => 'Denied', 'actor_category' => 'unknown']);
        } catch (\Throwable) {
            Log::warning('Authentication denial evidence unavailable.', ['event_code' => 'mfa_failed']);
        }

        // Section 9.3 & AUTH-058: 10 failed authenticator-code attempts within 1 hour trigger 15-minute MFA cooldown
        if ($count1h >= 10) {
            $lockDurationMinutes = 15;
            $lockedUntil = $now->copy()->addMinutes($lockDurationMinutes);
            $reason = 'Excessive invalid authenticator codes (10 within 1 hour)';

            Cache::put("auth:totp:cooldown:{$user->id}", $lockedUntil->timestamp, $lockedUntil);

            $notifyOwner = ! $wasAlreadyLocked || $count1h === 10;
            $lock = $this->captureLock([
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'user_id' => $user->id,
                'email_normalized' => IdentityNormalizer::normalizeEmail($user->email),
                'lock_category' => 'mfa',
                'reason' => $reason,
                'failed_attempts_count' => $count1h,
                'locked_at' => $now,
                'locked_until' => $lockedUntil,
                'requires_review' => false,
                'notification_status' => $notifyOwner ? LockNotificationStatus::Queued : LockNotificationStatus::NotSent,
            ], $user);

            Log::warning('MFA cooldown (15 minutes) applied', [
                'user_id' => $user->id,
                'failed_count' => $count1h,
            ]);

            if ($notifyOwner) {
                $user->notify((new MfaCooldownNotification($lockDurationMinutes, $reason, $lock->id))->afterCommit());
            }
        }

        // Section 9.3: 5 failures within one login attempt terminate the session
        if ($sessionAttempts >= 5) {
            if ($request->hasSession()) {
                $request->session()->forget(['login.id', 'login.remember', 'login.totp_attempts', 'login.recovery_attempts']);
            }
            $user->notify((new TwoFactorFailedAttemptsExceededNotification)->afterCommit());

            Log::warning('Login session terminated due to excessive invalid TOTP attempts', [
                'user_id' => $user->id,
            ]);

            throw ValidationException::withMessages([
                'code' => [__('Too many invalid attempts. The login session has ended for your security.')],
            ]);
        }
    }

    /**
     * Record successful TOTP verification.
     * Clears TOTP failure counter and completes password login counter reset.
     */
    public function recordTotpSuccess(User $user): void
    {
        Cache::forget("auth:totp:failures:{$user->id}");
        Cache::forget("auth:totp:cooldown:{$user->id}");
        $user->unlock('mfa');

        // Full login complete: also clear initial password failure counter
        $this->clearPasswordFailures(IdentityNormalizer::normalizeEmail($user->email), $user);
    }

    /**
     * Check if recovery code usage is currently under a cooldown.
     */
    public function isRecoveryCodeRestricted(User $user): bool
    {
        if ($user->isTemporarilyLocked('recovery_code')) {
            return true;
        }

        $cooldownUntil = Cache::get("auth:recovery_code:cooldown:{$user->id}");
        if ($cooldownUntil !== null && (int) $cooldownUntil > Carbon::now()->timestamp) {
            return true;
        }

        return false;
    }

    /**
     * Record a failed recovery code attempt, enforcing session limits and sliding 1-hour cooldowns.
     */
    public function recordRecoveryCodeFailure(User $user, Request $request, int $sessionAttempts): void
    {
        $now = Carbon::now();

        // 1. Record failure in 1-hour sliding window
        $key = "auth:recovery_code:failures:{$user->id}";
        $timestamps = (array) Cache::get($key, []);
        $cutoff1h = $now->copy()->subHours(1)->timestamp;
        $timestamps = array_values(array_filter($timestamps, fn ($t) => (int) $t >= $cutoff1h));
        $timestamps[] = $now->timestamp;
        Cache::put($key, $timestamps, $now->copy()->addHour());

        $count1h = count($timestamps);
        $wasAlreadyLocked = $user->isTemporarilyLocked('recovery_code');

        try {
            AuditEvent::record('auth.mfa_failed', User::class, $user->id, null,
                ['category' => 'recovery_code', 'attempt_count' => $count1h], null, ['executor' => self::class, 'outcome' => 'Denied', 'actor_category' => 'unknown']);
        } catch (\Throwable) {
            Log::warning('Authentication denial evidence unavailable.', ['event_code' => 'mfa_failed']);
        }

        // Section 9.4 & AUTH-059: 10 recovery-code failures within 1 hour trigger 1-hour cooldown
        if ($count1h >= 10) {
            $lockDurationMinutes = 60;
            $lockedUntil = $now->copy()->addMinutes($lockDurationMinutes);
            $reason = 'Excessive invalid recovery codes (10 within 1 hour)';

            Cache::put("auth:recovery_code:cooldown:{$user->id}", $lockedUntil->timestamp, $lockedUntil);

            $notifyOwner = ! $wasAlreadyLocked || $count1h === 10;
            $lock = $this->captureLock([
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'user_id' => $user->id,
                'email_normalized' => IdentityNormalizer::normalizeEmail($user->email),
                'lock_category' => 'recovery_code',
                'reason' => $reason,
                'failed_attempts_count' => $count1h,
                'locked_at' => $now,
                'locked_until' => $lockedUntil,
                'requires_review' => false,
                'notification_status' => $notifyOwner ? LockNotificationStatus::Queued : LockNotificationStatus::NotSent,
            ], $user);

            Log::warning('Recovery code cooldown (1 hour) applied', [
                'user_id' => $user->id,
                'failed_count' => $count1h,
            ]);

            if ($notifyOwner) {
                $user->notify((new RecoveryCodeCooldownNotification($lockDurationMinutes, $reason, $lock->id))->afterCommit());
            }
        }

        // Section 9.4: 5 failures terminate the recovery attempt
        if ($sessionAttempts >= 5) {
            if ($request->hasSession()) {
                $request->session()->forget(['login.id', 'login.remember', 'login.totp_attempts', 'login.recovery_attempts']);
            }
            $user->notify((new TwoFactorFailedAttemptsExceededNotification)->afterCommit());

            Log::warning('Recovery code login terminated due to excessive invalid attempts', [
                'user_id' => $user->id,
            ]);

            throw ValidationException::withMessages([
                'recovery_code' => [__('Too many invalid attempts. The login session has ended for your security.')],
            ]);
        }
    }

    /**
     * Record successful recovery code verification.
     */
    public function recordRecoveryCodeSuccess(User $user): void
    {
        Cache::forget("auth:recovery_code:failures:{$user->id}");
        Cache::forget("auth:recovery_code:cooldown:{$user->id}");
        $user->unlock('recovery_code');

        // Full login complete: also clear initial password failure counter
        $this->clearPasswordFailures(IdentityNormalizer::normalizeEmail($user->email), $user);
    }

    /**
     * Check if a request source exhibits automated distributed abuse (credential stuffing/spraying).
     */
    public function isSourceAbusive(Request $request): bool
    {
        $ip = $request->ip() ?? 'unknown';

        // Check if more than 5 distinct accounts were targeted within 15 minutes from this IP
        $targetedAccounts = (array) Cache::get("auth:ip:accounts:{$ip}", []);
        if (count($targetedAccounts) >= 5) {
            return true;
        }

        // Check if more than 20 failed login attempts occurred within 15 minutes from this IP
        $timestamps = (array) Cache::get("auth:ip:failures:{$ip}", []);
        $cutoff15m = Carbon::now()->subMinutes(15)->timestamp;
        $count = count(array_filter($timestamps, fn ($t) => (int) $t >= $cutoff15m));
        if ($count >= 20) {
            return true;
        }

        return false;
    }

    /**
     * Manually unlock a user's temporary locks by an authorized Administrator (AUTH-061, AUTHZ-019).
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function manualUnlock(
        User $targetUser,
        User $adminUser,
        string $category = 'password',
        UnlockVerificationMethod|string $verificationMethod = UnlockVerificationMethod::InPerson,
        string $reason = 'Identity verified following security protocol',
        ?string $restrictionToken = null,
    ): void {
        if (is_string($verificationMethod)) {
            $verificationMethod = UnlockVerificationMethod::from($verificationMethod);
        }
        $reason = trim($reason);

        DB::transaction(function () use ($targetUser, $adminUser, $category, $verificationMethod, $reason, $restrictionToken) {
            // 1. Gather involved user IDs and lock in deterministic order
            $idsToLock = collect([$adminUser->id, $targetUser->id])
                ->unique()
                ->sort()
                ->values()
                ->all();

            User::query()->whereIn('id', $idsToLock)->lockForUpdate()->get();

            /** @var User $lockedActor */
            $lockedActor = User::query()->findOrFail($adminUser->id);
            /** @var User $lockedTarget */
            $lockedTarget = User::query()->findOrFail($targetUser->id);

            // 2. Prohibit self-unlock (403)
            if ($lockedActor->id === $lockedTarget->id) {
                throw new AuthorizationException(__('Administrators cannot unlock their own accounts. Another administrator must verify and unlock this account.'));
            }

            // 3. Final active admin safeguard (403)
            if ($lockedTarget->user_type === UserType::Admin && $lockedTarget->isFinalActiveAdmin()) {
                throw new AuthorizationException(__('The final active administrator cannot be manually unlocked. The approved emergency recovery procedure is required.'));
            }

            // 4. Commit-time authorization check: re-run AuthorizationService for SecurityOperationsManage (403)
            if (! $this->authorizationService->allows($lockedActor, AdminPermission::SecurityOperationsManage)) {
                throw new AuthorizationException(__('You do not have authorization to manage security operations.'));
            }

            // 5. Lock matching active restriction rows in authentication_locks table
            $normalizedEmail = IdentityNormalizer::normalizeEmail($lockedTarget->email);

            $activeLocks = AuthenticationLock::query()
                ->where(function ($query) use ($lockedTarget, $normalizedEmail) {
                    $query->where('user_id', $lockedTarget->id)
                        ->orWhere('email_normalized', $normalizedEmail);
                })
                ->where('lock_category', $category)
                ->active()
                ->lockForUpdate()
                ->get();

            if ($restrictionToken === null) {
                throw ValidationException::withMessages(['restriction_token' => __('Refresh the restriction before unlocking.')]);
            }
            app(UnlockState::class)->verify($restrictionToken, $lockedTarget, $lockedActor, $category);
            $isUserLocked = $lockedTarget->isTemporarilyLocked($category);

            // Reject expired or missing matching restrictions with 422
            if ($activeLocks->isEmpty() && ! $isUserLocked) {
                throw ValidationException::withMessages([
                    'category' => [__('There is no active temporary restriction for this category.')],
                ]);
            }

            // 6. Clear only the requested restriction category and matching abuse counters
            // while preserving passwords, MFA, account state, roles, permissions, assignments, and unrelated restrictions.
            if ($lockedTarget->lock_category === $category || $lockedTarget->lock_category === null) {
                $lockedTarget->locked_until = null;
                $lockedTarget->lock_category = null;
                $lockedTarget->lock_reason = null;
                $lockedTarget->save();
            }

            $now = Carbon::now();
            foreach ($activeLocks as $lock) {
                $lock->update([
                    'unlocked_at' => $now,
                    'unlocked_by_user_id' => $lockedActor->id,
                    'unlock_reason' => $reason,
                    'unlock_verification_method' => $verificationMethod,
                ]);
            }

            // Clear matching abuse counters in cache
            if ($category === 'password') {
                Cache::forget("auth:password:failures:{$normalizedEmail}");
                Cache::forget("auth:password:cooldown:{$normalizedEmail}");
            } elseif ($category === 'mfa') {
                Cache::forget("auth:totp:failures:{$lockedTarget->id}");
                Cache::forget("auth:totp:cooldown:{$lockedTarget->id}");
            } elseif ($category === 'recovery_code') {
                Cache::forget("auth:recovery_code:failures:{$lockedTarget->id}");
                Cache::forget("auth:recovery_code:cooldown:{$lockedTarget->id}");
            }

            AuditEvent::record('auth.manual_unlock', User::class, $lockedTarget->id, null,
                ['category' => $category, 'verification_method' => $verificationMethod->value, 'restriction_ids' => $activeLocks->pluck('id')->all()], $lockedActor,
                ['required_permission' => AdminPermission::SecurityOperationsManage->value, 'executor' => self::class]);
            // 7. Queue the account-owner notification only after commit
            DB::afterCommit(function () use ($lockedTarget) {
                $lockedTarget->notify((new AccountUnlockedNotification('Administrator'))->afterCommit());
            });

            Log::info('Account manually unlocked by administrator', [
                'target_user_id' => $lockedTarget->id,
                'admin_user_id' => $lockedActor->id,
                'category' => $category,
                'verification_method' => $verificationMethod->value,
            ]);
        }, attempts: 3);
    }

    /**
     * Revoke active sessions and trusted devices when probable account compromise is detected (AUTH-060, AUTH-062).
     */
    public function revokeSessionsForSuspectedCompromise(User $user, string $reason): void
    {
        DB::transaction(function () use ($user, $reason) {
            (function () use ($user, $reason) {
                $sessionTable = config('session.table', 'sessions');
                DB::table($sessionTable)->where('user_id', $user->id)->delete();
                $user->revokeAllTrustedDevices();
                $user->forceFill(['remember_token' => Str::random(60), 'lifecycle_access_version' => (int) $user->lifecycle_access_version + 1])->save();

                $user->notify((new CompromiseSessionRevocationNotification($reason))->afterCommit());

                Log::warning('Sessions revoked for suspected compromise', [
                    'user_id' => $user->id,
                ]);

            })();
            AuditEvent::record('auth.compromise_sessions_revoked', User::class, $user->id, null,
                ['changed_fields' => ['revokeSessionsForSuspectedCompromise']], $user, ['executor' => self::class]);

        });
    }

    /** @param array<string, mixed> $attributes */
    private function captureLock(array $attributes, ?User $user): AuthenticationLock
    {
        return DB::transaction(function () use ($attributes, $user): AuthenticationLock {
            if ($user !== null) {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $user->forceFill(['locked_until' => $attributes['locked_until'], 'lock_category' => $attributes['lock_category'], 'lock_reason' => $attributes['reason']])->save();
            }
            $lock = AuthenticationLock::create($attributes);
            AuditEvent::record('auth.lock_created', User::class, $user?->id, null,
                ['category' => $attributes['lock_category'], 'lock_id' => $lock->id, 'attempt_count' => $attributes['failed_attempts_count']], null,
                ['executor' => self::class, 'operation_id' => 'lock:'.$lock->id, 'severity' => 'High']);

            return $lock;
        });
    }

    /**
     * Detect abuse that no single source reveals (Section 9.5): one account attacked from many sources,
     * and password spraying across many accounts. Either restricts the affected account for 15 minutes.
     */
    private function detectDistributedAttack(string $normalized, string $ip, Carbon $now): void
    {
        $cutoff = $now->copy()->subMinutes(15)->timestamp;
        $windowEnd = $now->copy()->addMinutes(15);

        $sourcesKey = "auth:account:sources:{$normalized}";
        $sources = array_filter((array) Cache::get($sourcesKey, []), fn ($seenAt) => (int) $seenAt >= $cutoff);
        $sources[$ip] = $now->timestamp;
        Cache::put($sourcesKey, $sources, $windowEnd);
        if (count($sources) >= 5) {
            $this->restrictForDistributedAttack('many_sources', $normalized, count($sources), $now);
        }

        $failures = array_values(array_filter((array) Cache::get('auth:global:failures', []), fn ($failure) => (int) $failure[0] >= $cutoff));
        $failures[] = [$now->timestamp, $normalized];
        Cache::put('auth:global:failures', $failures, $windowEnd);
        if (count($failures) >= 50 && count(array_unique(array_column($failures, 1))) >= 20) {
            $this->restrictForDistributedAttack('password_spray', $normalized, count($failures), $now);
        }
    }

    /**
     * Restrict the account for 15 minutes and audit the detected pattern once per window.
     */
    private function restrictForDistributedAttack(string $pattern, string $normalized, int $attemptCount, Carbon $now): void
    {
        $restrictedUntil = $now->copy()->addMinutes(15);
        Cache::put("auth:distributed:restricted:{$normalized}", $restrictedUntil->timestamp, $restrictedUntil);

        $detectionKey = "auth:distributed:detected:{$pattern}:".($pattern === 'many_sources' ? $normalized : 'all');
        if (! Cache::add($detectionKey, true, $restrictedUntil)) {
            return;
        }

        try {
            AuditEvent::record('auth.distributed_attack_detected', User::class, null, null,
                ['category' => $pattern, 'attempt_count' => $attemptCount], null,
                ['executor' => self::class, 'outcome' => 'Denied', 'actor_category' => 'unknown', 'severity' => 'High']);
        } catch (\Throwable) {
            Log::warning('Authentication denial evidence unavailable.', ['event_code' => 'distributed_attack_detected']);
        }
    }
}
