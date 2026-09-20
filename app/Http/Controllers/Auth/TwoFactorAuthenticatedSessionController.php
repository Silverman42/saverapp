<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AgentTrustedDeviceService;
use App\Services\AuthenticationAbuseService;
use App\Services\SessionManagerService;
use App\Services\TwoFactorService;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;

class TwoFactorAuthenticatedSessionController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        protected StatefulGuard $guard,
        protected TwoFactorService $twoFactorService,
        protected SessionManagerService $sessionManager,
        protected AgentTrustedDeviceService $trustedDeviceService,
        protected AuthenticationAbuseService $abuseService,
    ) {}

    /**
     * Show the two factor authentication challenge view.
     */
    public function create(Request $request): Response
    {
        if (! $request->session()->has('login.id')) {
            throw new HttpResponseException(redirect()->route('login'));
        }

        return Inertia::render('auth/TwoFactorChallenge');
    }

    /**
     * Attempt to authenticate a new session using the two factor authentication code or recovery code.
     */
    public function store(Request $request)
    {
        if (! $request->session()->has('login.id')) {
            throw new HttpResponseException(redirect()->route('login'));
        }

        /** @var User $user */
        $user = User::findOrFail($request->session()->get('login.id'));

        $recoveryCode = $request->input('recovery_code');
        $code = $request->input('code');

        if ($recoveryCode) {
            // Section 9.4 & AUTH-059: Check recovery code cooldown
            if ($this->abuseService->isRecoveryCodeRestricted($user)) {
                throw ValidationException::withMessages([
                    'recovery_code' => [__('Recovery code authentication is temporarily restricted due to excessive invalid attempts. Please try again later.')],
                ]);
            }

            if ($this->twoFactorService->verifyAndConsumeRecoveryCode($user, (string) $recoveryCode)) {
                $this->abuseService->recordRecoveryCodeSuccess($user);

                return $this->handleSuccess($request, $user, usedRecoveryCode: true);
            }

            event(new TwoFactorAuthenticationFailed($user));

            $attempts = (int) $request->session()->get('login.recovery_attempts', 0) + 1;
            $request->session()->put('login.recovery_attempts', $attempts);

            // Record failure, sliding window cooldown, and terminate attempt on 5 failures
            $this->abuseService->recordRecoveryCodeFailure($user, $request, $attempts);

            throw ValidationException::withMessages([
                'recovery_code' => [__('The provided two-factor recovery code was invalid.')],
            ]);
        }

        if ($code) {
            // Section 9.3 & AUTH-058: Check TOTP cooldown
            if ($this->abuseService->isTotpRestricted($user)) {
                throw ValidationException::withMessages([
                    'code' => [__('Two-factor authenticator verification is temporarily restricted due to excessive invalid attempts. Please try again later.')],
                ]);
            }

            if ($this->twoFactorService->verifyTotp($user, (string) $code, usePending: false)) {
                $this->abuseService->recordTotpSuccess($user);

                return $this->handleSuccess($request, $user, usedRecoveryCode: false);
            }

            event(new TwoFactorAuthenticationFailed($user));

            $attempts = (int) $request->session()->get('login.totp_attempts', 0) + 1;
            $request->session()->put('login.totp_attempts', $attempts);

            // Record failure, sliding window cooldown, and terminate attempt on 5 failures
            $this->abuseService->recordTotpFailure($user, $request, $attempts);

            throw ValidationException::withMessages([
                'code' => [__('The provided two-factor authentication code was invalid or replayed.')],
            ]);
        }

        throw ValidationException::withMessages([
            'code' => [__('A two-factor authentication code or recovery code is required.')],
        ]);
    }

    /**
     * Handle successful two-factor verification.
     */
    protected function handleSuccess(Request $request, User $user, bool $usedRecoveryCode)
    {
        event(new ValidTwoFactorAuthenticationCodeProvided($user));

        $remember = (bool) $request->session()->pull('login.remember', false);
        $request->session()->forget(['login.id', 'login.totp_attempts', 'login.recovery_attempts', 'login.two_factor_attempts']);

        $trustDevice = ! $usedRecoveryCode && $user->user_type === UserType::Agent && $request->boolean('trust_device');

        // Check concurrent device limits (AUTH-046 & AUTH-048)
        $activeSessions = $this->sessionManager->checkDeviceLimits($user);
        if ($activeSessions !== null) {
            $request->session()->put('login.pending_eviction', [
                'user_id' => $user->id,
                'remember' => $remember,
                'replacement_required' => $usedRecoveryCode,
                'trust_device' => $trustDevice,
            ]);

            return redirect()->route('device-eviction');
        }

        if ($usedRecoveryCode) {
            // Section 5.10: Recovery-code login creates a restricted session requiring authenticator replacement
            $request->session()->put('two_factor_replacement_required', true);
        }

        $this->guard->login($user, $remember);
        $request->session()->regenerate();

        $now = Carbon::now()->timestamp;
        $request->session()->put('auth.login_at', $now);
        $request->session()->put('auth.last_active_at', $now);
        $request->session()->put('auth.fresh_until', $now + 600);
        $request->session()->put('auth.password_confirmed_at', $now);
        $request->session()->put('auth.mfa_confirmed_at', $now);

        $response = app(TwoFactorLoginResponseContract::class)->toResponse($request);

        if ($trustDevice) {
            $cookie = $this->trustedDeviceService->createTrustedDevice($user, $request);
            $response->withCookie($cookie);
        }

        return $response;
    }
}
