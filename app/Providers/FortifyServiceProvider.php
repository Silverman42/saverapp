<?php

namespace App\Providers;

use App\Actions\Fortify\AttemptToAuthenticateWithDeviceLimit;
use App\Actions\Fortify\AuthenticateUser;
use App\Actions\Fortify\RedirectIfTwoFactorAuthenticatable;
use App\Actions\Fortify\ResetUserPassword;
use App\Auth\Passwords\PasswordBrokerManager;
use App\Enums\AccountState;
use App\Enums\UserType;
use App\Http\Responses\LoginResponse;
use App\Http\Responses\PasswordResetLinkResponse;
use App\Http\Responses\TwoFactorLoginResponse;
use App\Models\User;
use App\Support\IdentityNormalizer;
use App\Support\PasswordPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password as PasswordFacade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Actions\CanonicalizeUsername;
use Laravel\Fortify\Actions\EnsureLoginIsNotThrottled;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse as FailedPasswordResetLinkRequestResponseContract;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse as SuccessfulPasswordResetLinkRequestResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(TwoFactorLoginResponseContract::class, TwoFactorLoginResponse::class);
        $this->app->singleton(
            RedirectsIfTwoFactorAuthenticatable::class,
            RedirectIfTwoFactorAuthenticatable::class
        );
        $this->app->singleton(
            TwoFactorAuthenticatedSessionController::class,
            \App\Http\Controllers\Auth\TwoFactorAuthenticatedSessionController::class
        );
        $this->app->singleton(SuccessfulPasswordResetLinkRequestResponseContract::class, PasswordResetLinkResponse::class);
        $this->app->singleton(FailedPasswordResetLinkRequestResponseContract::class, PasswordResetLinkResponse::class);

        $this->app->extend('auth.password', function ($service, $app) {
            return new PasswordBrokerManager($app);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::authenticateUsing(fn (Request $request) => app(AuthenticateUser::class)($request));

        Fortify::authenticateThrough(function (Request $request) {
            return array_filter([
                config('fortify.limiters.login') ? null : EnsureLoginIsNotThrottled::class,
                config('fortify.lowercase_usernames') ? CanonicalizeUsername::class : null,
                Features::enabled(Features::twoFactorAuthentication()) ? RedirectIfTwoFactorAuthenticatable::class : null,
                AttemptToAuthenticateWithDeviceLimit::class,
                PrepareAuthenticatedSession::class,
            ]);
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(function (Request $request) {
            $email = (string) $request->query('email', $request->email ?? '');
            $token = (string) $request->route('token');

            $user = $email !== '' ? User::findByNormalizedEmail($email) : null;
            $isValidToken = false;
            $requiresTwoFactor = false;

            if ($user !== null && $user->account_state !== AccountState::Invited) {
                $isValidToken = PasswordFacade::broker()->tokenExists($user, $token);
                if ($isValidToken && in_array($user->user_type, [UserType::Agent, UserType::Admin], true) && $user->hasEnabledTwoFactorAuthentication()) {
                    $requiresTwoFactor = true;
                }
            }

            return Inertia::render('auth/ResetPassword', [
                'email' => $email,
                'token' => $token,
                'isValidToken' => $isValidToken,
                'requiresTwoFactor' => $requiresTwoFactor,
                'passwordRules' => PasswordPolicy::ruleForUser($user)->toPasswordRulesString(),
                'userType' => $user?->user_type?->value,
            ]);
        });

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::twoFactorChallengeView(function (Request $request) {
            $user = User::query()->whereKey($request->session()->get('login.id'))->first();

            return Inertia::render('auth/TwoFactorChallenge', [
                'isAgent' => $user?->user_type === UserType::Agent,
            ]);
        });

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('invitation-activation', function (Request $request) {
            return [
                Limit::perMinute(5)->by('token:'.hash('sha256', (string) $request->route('token'))),
                Limit::perMinute(20)->by('source:'.$request->ip()),
            ];
        });

        RateLimiter::for('login', function (Request $request) {
            $email = IdentityNormalizer::normalizeEmail((string) $request->input(Fortify::username()));
            $throttleKey = Str::transliterate($email.'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}
