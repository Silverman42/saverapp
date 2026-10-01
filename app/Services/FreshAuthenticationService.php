<?php

namespace App\Services;

use App\Enums\UserType;
use App\Http\Middleware\EnsureFreshAuthentication;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class FreshAuthenticationService
{
    public function __construct(
        protected TwoFactorService $twoFactorService,
        protected AuthenticationAbuseService $abuseService,
    ) {}

    /**
     * Check whether the authenticated user has an unexpired, role-appropriate fresh authentication window.
     */
    public function isFresh(User $user, Request $request): bool
    {
        $session = $request->session();
        $freshUntil = (int) $session->get('auth.fresh_until', 0);
        $passwordConfirmedAt = (int) $session->get('auth.password_confirmed_at', 0);
        $now = Carbon::now()->getTimestamp();

        // Fallback to password_confirmed_at if fresh_until is not explicitly set
        if ($freshUntil === 0 && $passwordConfirmedAt > 0) {
            if ($now - $passwordConfirmedAt <= EnsureFreshAuthentication::FRESH_WINDOW_SECONDS) {
                $freshUntil = $passwordConfirmedAt + EnsureFreshAuthentication::FRESH_WINDOW_SECONDS;
                $session->put('auth.fresh_until', $freshUntil);
            }
        }

        if ($freshUntil < $now) {
            return false;
        }

        if ($passwordConfirmedAt > 0 && ($now - $passwordConfirmedAt > EnsureFreshAuthentication::FRESH_WINDOW_SECONDS)) {
            return false;
        }

        // Agents and Admins require MFA confirmation within the fresh window
        if (in_array($user->user_type, [UserType::Agent, UserType::Admin], true)) {
            $mfaConfirmedAt = (int) $session->get('auth.mfa_confirmed_at', 0);
            if ($mfaConfirmedAt === 0 || ($now - $mfaConfirmedAt > EnsureFreshAuthentication::FRESH_WINDOW_SECONDS)) {
                return false;
            }

            // Recovery code logins or replacement-required sessions do not satisfy fresh authentication
            if ($session->get('two_factor_replacement_required', false) || $session->get('recovery_code_used', false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Confirm fresh authentication credentials for the user.
     *
     * @throws ValidationException
     */
    public function confirm(User $user, Request $request, string $password, ?string $code = null): bool
    {
        $now = Carbon::now()->getTimestamp();

        // 1. Password abuse check
        if ($this->abuseService->isPasswordRestricted($user->email, $user, $request)) {
            throw ValidationException::withMessages([
                'password' => [__('Password authentication is temporarily restricted due to excessive failed attempts. Please try again later.')],
            ]);
        }

        // 2. Validate password
        if (! Hash::check($password, $user->password)) {
            $this->abuseService->recordPasswordFailure($user->email, $request, $user);

            throw ValidationException::withMessages([
                'password' => [__('The provided password does not match our records.')],
            ]);
        }

        // 3. For Agent and Admin, validate TOTP
        if (in_array($user->user_type, [UserType::Agent, UserType::Admin], true)) {
            if (! $user->hasEnabledTwoFactorAuthentication()) {
                throw ValidationException::withMessages([
                    'code' => [__('Two-factor authentication is required for this action.')],
                ]);
            }

            if ($this->abuseService->isTotpRestricted($user)) {
                throw ValidationException::withMessages([
                    'code' => [__('Two-factor verification is temporarily on cooldown. Please try again shortly.')],
                ]);
            }

            $totpCode = trim((string) $code);
            if ($totpCode === '' || strlen($totpCode) !== 6 || ! ctype_digit($totpCode)) {
                $attempts = (int) $request->session()->get('auth.fresh_totp_attempts', 0) + 1;
                $request->session()->put('auth.fresh_totp_attempts', $attempts);
                $this->abuseService->recordTotpFailure($user, $request, $attempts);

                throw ValidationException::withMessages([
                    'code' => [__('Please enter a valid 6-digit authentication code.')],
                ]);
            }

            // Verify TOTP with atomic replay prevention
            $isValidTotp = $this->twoFactorService->verifyTotp($user, $totpCode);
            if (! $isValidTotp) {
                $attempts = (int) $request->session()->get('auth.fresh_totp_attempts', 0) + 1;
                $request->session()->put('auth.fresh_totp_attempts', $attempts);
                $this->abuseService->recordTotpFailure($user, $request, $attempts);

                throw ValidationException::withMessages([
                    'code' => [__('The provided two-factor authentication code is invalid or has expired.')],
                ]);
            }

            // Successful TOTP clear session step-up attempt counter
            $request->session()->forget('auth.fresh_totp_attempts');
            $request->session()->put('auth.mfa_confirmed_at', $now);
        }

        // Record fresh timestamps (10-minute window)
        $request->session()->put('auth.fresh_until', $now + EnsureFreshAuthentication::FRESH_WINDOW_SECONDS);
        $request->session()->put('auth.password_confirmed_at', $now);

        return true;
    }
}
