# AUTH-T10 — Independent Abuse Counters, Progressive Restrictions, Cooldowns, Controlled Unlock, and Security Visibility

## Summary

Implement `AUTH-056`–`AUTH-063` and acceptance criteria 69–80 as defined in Module 02 Section 9. This covers:
1. Independent failure counters across authentication methods: password, TOTP authenticator, recovery codes, reset requests, invitations, and assisted recovery.
2. Progressive password failure restrictions (1–4 generic error, 5: 1m cooldown, 6–9: progressive 2–5m delays, 10 within 1h: 15m lock + notification, 20 within 24h: 1h lock + review flag + notification).
3. Authenticator code failure protection (5 failures end login attempt, 10 within 1h trigger 15m MFA cooldown + notification, replay rejection, authenticator preservation).
4. Recovery code failure protection (5 failures end attempt, 10 within 1h trigger 1h cooldown + notification, legitimate unused codes preserved).
5. Temporary-lock isolation (only affected authentication path blocked; active sessions and account states intact).
6. Controlled unlock (automatic expiry, password-reset clearing of password locks, authorized Admin manual unlock after verification without modifying credentials or permissions).
7. Queueable security notifications (`PasswordLockoutNotification`, `MfaCooldownNotification`, `RecoveryCodeCooldownNotification`, `AccountUnlockedNotification`, `CompromiseSessionRevocationNotification`).
8. Admin lockout visibility and audit interface (secret-safe lock details, masked IP, user agent, failed count, manual unlock action).
9. Source-level abuse / bot detection protection against credential stuffing and password spraying.

## Implementation Changes

### 1. Database & Persistence
- Migration `2026_09_20_210000_create_authentication_locks_table.php`:
  - `id`
  - `user_id` (foreignId to users, nullable, cascade on delete)
  - `email_normalized` (string, indexed)
  - `lock_category` (string: `password`, `mfa`, `recovery_code`)
  - `reason` (string)
  - `failed_attempts_count` (integer)
  - `ip_address` (string, nullable)
  - `user_agent` (string, nullable)
  - `locked_at` (timestamp)
  - `locked_until` (timestamp)
  - `requires_review` (boolean, default false)
  - `unlocked_at` (timestamp, nullable)
  - `unlocked_by_user_id` (foreignId to users, nullable)
  - `unlock_reason` (string, nullable)
  - `notification_sent` (boolean, default false)
  - `timestamps`
- Model `App\Models\AuthenticationLock`:
  - Scopes: `scopeActive`, `scopeRequiresReview`, `scopeByCategory`.
  - Relationship to `User` (both affected user and unlocking admin).
  - Helper methods: `isActive()`, `isExpired()`, `markUnlocked()`, `maskedIp()`.
- Updates to `App\Models\User`:
  - Relationship `authenticationLocks()`.
  - Relationship `activeLocks()`.
  - Refined `isTemporarilyLocked(?string $category = null)`.
  - Refined `lockTemporarily()`.
  - Refined `unlock(?string $category = null)`.

### 2. Abuse Protection Service
- `App\Services\AuthenticationAbuseService`:
  - Tracks failure timestamps in Cache sliding windows (15m, 1h, 24h) by normalized email, account ID, and client IP.
  - Methods:
    - `checkPasswordAttempt(string $email, Request $request): ?string` (returns null if allowed, or error message / cooldown status).
    - `recordPasswordFailure(string $email, Request $request, ?User $user): void` (increments failure count, calculates progressive cooldown or 15m/1h lock, dispatches notification).
    - `recordPasswordSuccess(User $user, Request $request): void` (clears password failure counter and active password cooldown for complete logins).
    - `recordTotpFailure(User $user, Request $request): void` (tracks failures, terminates session on 5, triggers 15m MFA cooldown on 10 in 1h, dispatches notification).
    - `recordTotpSuccess(User $user): void` (clears TOTP failure counter).
    - `recordRecoveryCodeFailure(User $user, Request $request): void` (tracks failures, terminates session on 5, triggers 1h cooldown on 10 in 1h, dispatches notification).
    - `recordRecoveryCodeSuccess(User $user): void` (clears recovery code failure counter).
    - `checkSourceAbuse(Request $request): bool` (detects spraying / credential stuffing from single IP across accounts).
    - `manualUnlock(User $targetUser, User $adminUser, ?string $category = null, ?string $reason = null): void` (verifies authorization, clears lock and counters, logs unlock, dispatches notification).

### 3. Pipeline & Controller Integration
- `App\Actions\Fortify\AuthenticateUser`:
  - Consults `AuthenticationAbuseService` before credential verification; maintains timeboxing.
  - On invalid credentials, calls `recordPasswordFailure`.
  - If Customer credentials succeed, calls `recordPasswordSuccess`.
- `App\Http\Controllers\Auth\TwoFactorAuthenticatedSessionController`:
  - Separates TOTP vs Recovery Code failure counts.
  - Integrates 15m MFA cooldown and 1h recovery code cooldown checks and recording.
  - On successful TOTP/recovery completion, clears the respective failure counter and clears the login password failure counter (completing the full login).
- `App\Actions\Fortify\ResetUserPassword`:
  - Successful password reset clears password failure counters and temporary password lock, leaving MFA/recovery cooldowns intact.

### 4. Queueable Notifications
All implement `ShouldQueue` and use `Queueable` per `.ai/rules/notifications.md`:
- `App\Notifications\Auth\PasswordLockoutNotification`
- `App\Notifications\Auth\MfaCooldownNotification`
- `App\Notifications\Auth\RecoveryCodeCooldownNotification`
- `App\Notifications\Auth\AccountUnlockedNotification`
- `App\Notifications\Auth\CompromiseSessionRevocationNotification`

### 5. Admin Security Lockouts UI & Controller
- `App\Http\Controllers\Admin\LockoutController`:
  - `index`: Lists active locks and recent security incidents with masked IPs, user agents, reasons, and unlock eligibility.
  - `unlock`: Validates that the acting user is an Admin, cannot unlock themselves, cannot unlock final active Admin, performs manual unlock, and returns with standard bottom-center toast.
- `resources/js/pages/admin/Lockouts.vue`:
  - Inertia view using Reka UI components, light/dark mode, masked IP addresses, and Vuelidate/Inertia form actions.
- Routes in `routes/web.php` under `middleware(['auth', 'role:admin'])`.

## Verification Plan

- `tests/Feature/Auth/AuthenticationAbuseProtectionTest.php`:
  - 1–4 failed password attempts return generic error without locking.
  - 5th failure triggers 1-minute cooldown; attempts within 1 minute fail generically.
  - Progressive delays at 6–9 attempts (2m, 3m, 4m, 5m).
  - 10th failure in 1h applies 15-minute lock and queues `PasswordLockoutNotification`.
  - 20th failure in 24h applies 1-hour lock, queues notification, and sets `requires_review`.
  - Successful login clears password failure counters and cooldowns.
  - Partial login does not clear password failure counters.
  - Password reset clears password failure locks but not MFA cooldowns.
  - 5 TOTP failures terminate session; 10 in 1h trigger 15m MFA cooldown and queue notification.
  - 5 recovery code failures terminate attempt; 10 in 1h trigger 1h recovery code cooldown and queue notification.
  - Legitimate recovery codes are preserved after failed attempts.
  - Lock isolation: password lock does not terminate active session; MFA lock does not block password check.
  - Suspended/deactivated accounts do not become active on lock expiry.
  - Admin visibility endpoint displays secret-free lock metadata and masked IPs.
  - Admin manual unlock clears lock and queues notification.
  - Admin cannot unlock their own account.
- Pint formatting: `vendor/bin/pint --format agent`.
- TypeScript check: `npm run types:check`.
- Production build: `npm run build`.
- Full Pest test suite: `php artisan test --compact`.
