<?php

namespace App\Actions\Fortify;

use App\Enums\AccountState;
use App\Enums\UserType;
use App\Models\User;
use App\Notifications\Auth\AdminPasswordResetNotification;
use App\Notifications\Auth\PasswordResetSuccessNotification;
use App\Services\AuthenticationAbuseService;
use App\Services\TwoFactorService;
use App\Support\IdentityNormalizer;
use App\Support\PasswordPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, mixed>  $input
     */
    public function reset(User $user, array $input): void
    {
        // Section 7.6 & AC 15: Password reset must not activate an invited account
        if ($user->account_state === AccountState::Invited) {
            throw ValidationException::withMessages([
                'email' => [__('Password reset cannot activate an invited account. Please use your invitation link.')],
            ]);
        }

        // Section 7.3 & 7.4 & AUTH-013 & AC 13: Agent or Admin with MFA must supply valid TOTP code or recovery code
        $requiresTwoFactor = in_array($user->user_type, [UserType::Agent, UserType::Admin], true)
            && $user->hasEnabledTwoFactorAuthentication();

        if ($requiresTwoFactor) {
            $this->validateTwoFactorProof($user, $input);
        }

        // Section 4.3: Validate password using role-specific policy (Customer: min 15; Agent/Admin: min 8)
        Validator::make($input, [
            'password' => ['required', 'string', PasswordPolicy::ruleForUser($user), 'confirmed'],
        ])->validate();

        // Update password (preserving user_type, account_state, permissions, attribution, and MFA config)
        $user->forceFill([
            'password' => $input['password'],
        ])->save();

        // Section 7.5 & AUTH-015 & AC 14: Post-reset revocation
        $this->performPostResetRevocation($user);

        // Section 7.5 & AUTH-016: Send security notifications
        $this->sendResetNotifications($user);
    }

    /**
     * Validate the two-factor authentication code or recovery code.
     *
     * @param  array<string, mixed>  $input
     */
    protected function validateTwoFactorProof(User $user, array $input): void
    {
        $code = isset($input['code']) ? trim((string) $input['code']) : '';
        $recoveryCode = isset($input['recovery_code']) ? trim((string) $input['recovery_code']) : '';

        $twoFactorService = app(TwoFactorService::class);

        if ($recoveryCode !== '') {
            $consumed = $twoFactorService->verifyAndConsumeRecoveryCode($user, $recoveryCode);

            if (! $consumed) {
                throw ValidationException::withMessages([
                    'recovery_code' => [__('The provided two-factor recovery code was invalid.')],
                ]);
            }

            return;
        }

        if ($code !== '') {
            $isValid = $twoFactorService->verifyTotp($user, $code);

            if (! $isValid) {
                throw ValidationException::withMessages([
                    'code' => [__('The provided two-factor authentication code was invalid.')],
                ]);
            }

            return;
        }

        throw ValidationException::withMessages([
            'code' => [__('A two-factor authentication code or recovery code is required to reset your password.')],
        ]);
    }

    /**
     * Perform post-reset revocation of active sessions and temporary locks.
     */
    protected function performPostResetRevocation(User $user): void
    {
        // Revoke active sessions and trusted devices (AUTH-015, AUTH-047)
        $sessionTable = config('session.table', 'sessions');
        DB::table($sessionTable)->where('user_id', $user->id)->delete();
        $user->revokeAllTrustedDevices();

        // Rotate remember token
        $user->setRememberToken(Str::random(60));
        $user->save();

        // Clear temporary password locks and failure counters (AUTH-061 & AC 75)
        if ($user->lock_category === 'password' || $user->isTemporarilyLocked('password')) {
            $user->unlock('password');
        }

        app(AuthenticationAbuseService::class)->clearPasswordFailures(
            IdentityNormalizer::normalizeEmail($user->email),
            $user
        );

        // Clear login rate limiter for this identity
        $email = IdentityNormalizer::normalizeEmail($user->email);
        RateLimiter::clear(Str::transliterate($email.'|*'));

        Log::info('Password reset completed and sessions revoked', [
            'user_id' => $user->id,
            'user_type' => $user->user_type->value,
            'account_state' => $user->account_state->value,
        ]);
    }

    /**
     * Send password reset notifications to the account owner and other active Admins if applicable.
     */
    protected function sendResetNotifications(User $user): void
    {
        // Notify the account owner
        $user->notify(new PasswordResetSuccessNotification);

        // Section 7.4 & AUTH-016: If Admin password was reset, notify other active Admins
        if ($user->user_type === UserType::Admin) {
            $otherAdmins = User::where('user_type', UserType::Admin)
                ->where('account_state', AccountState::Active)
                ->where('id', '!=', $user->id)
                ->get();

            if ($otherAdmins->isNotEmpty()) {
                Notification::send($otherAdmins, new AdminPasswordResetNotification($user));
            }
        }
    }
}
