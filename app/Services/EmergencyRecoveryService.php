<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\User;
use App\Support\IdentityNormalizer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Single-use offline business emergency key for the final active Admin (Module 02 §7.8.5, AUTH-021).
 *
 * Only a hash of the key is stored. Using it requires the seeded Admin email, because the
 * recovery link is sent only there, and it is invalidated the moment it is accepted.
 */
class EmergencyRecoveryService
{
    public function __construct(private StaffRecoveryService $recoveries) {}

    /**
     * Issue a new key bound to the given Admin, replacing any previous one, and return it once.
     */
    public function issue(User $admin, string $reason): string
    {
        if ($admin->user_type !== UserType::Admin) {
            throw new ConflictHttpException('The emergency key can only be bound to an Administrator.');
        }

        $key = implode('-', str_split(Str::upper(Str::random(32)), 4));

        app(PlatformGuard::class)->transaction('mutation', function () use ($admin, $key, $reason): void {
            $profile = BusinessProfile::query()->lockForUpdate()->sole();
            $replaced = $profile->emergency_key_hash !== null;
            $profile->forceFill([
                'emergency_key_hash' => Hash::make($this->normalize($key)),
                'emergency_key_issued_at' => now(),
                'emergency_admin_user_id' => $admin->id,
            ])->saveQuietly();

            AuditEvent::record($replaced ? 'auth.emergency_key_replaced' : 'auth.emergency_key_issued', User::class, $admin->id, null,
                ['outcome' => $reason], null, ['executor' => self::class]);
        });

        return $key;
    }

    /**
     * Verify the key and seeded email; on success invalidate the key and send a recovery link to that email.
     *
     * Always returns without revealing whether the email, key or account state matched.
     */
    public function start(string $email, #[\SensitiveParameter] string $key, string $requestSource): void
    {
        $throttleKey = 'emergency-recovery:'.sha1(IdentityNormalizer::normalizeEmail($email).'|'.$requestSource);
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return;
        }
        RateLimiter::hit($throttleKey, 3600);

        app(PlatformGuard::class)->transaction('mutation', function () use ($email, $key): void {
            $profile = BusinessProfile::query()->lockForUpdate()->sole();
            $admin = $profile->emergency_admin_user_id === null ? null
                : User::query()->whereKey($profile->emergency_admin_user_id)->lockForUpdate()->first();

            $valid = $profile->emergency_key_hash !== null && $admin !== null
                && hash_equals($admin->email_normalized, IdentityNormalizer::normalizeEmail($email))
                && Hash::check($this->normalize($key), $profile->emergency_key_hash)
                && $admin->user_type === UserType::Admin
                && in_array($admin->account_state, [AccountState::Active, AccountState::MfaSetupRequired], true)
                && ! $this->otherActiveAdminExists($admin);

            if (! $valid) {
                AuditEvent::record('auth.emergency_recovery_failed', User::class, $admin?->id, null, ['outcome' => 'rejected'], null,
                    ['executor' => self::class]);

                return;
            }

            $profile->forceFill(['emergency_key_hash' => null])->saveQuietly();
            AuditEvent::record('auth.emergency_key_used', User::class, $admin->id, null, ['outcome' => 'accepted'], null,
                ['executor' => self::class]);
            $this->recoveries->startEmergency($admin);
        }, attempts: 3);
    }

    /**
     * Whether a usable key currently exists.
     */
    public function hasActiveKey(): bool
    {
        return BusinessProfile::current()->emergency_key_hash !== null;
    }

    private function otherActiveAdminExists(User $admin): bool
    {
        return User::query()->where('user_type', UserType::Admin->value)->where('account_state', AccountState::Active->value)
            ->where('id', '!=', $admin->id)->exists();
    }

    private function normalize(string $key): string
    {
        return Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $key) ?? '');
    }
}
