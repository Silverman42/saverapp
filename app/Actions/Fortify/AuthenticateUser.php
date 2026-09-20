<?php

namespace App\Actions\Fortify;

use App\Enums\AccountState;
use App\Enums\UserType;
use App\Models\User;
use App\Services\AuthenticationAbuseService;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Timebox;
use Laravel\Fortify\Fortify;

class AuthenticateUser
{
    /**
     * Create a new authentication action instance.
     */
    public function __construct(
        protected StatefulGuard $guard,
        protected Timebox $timebox,
        protected AuthenticationAbuseService $abuseService,
    ) {}

    /**
     * Authenticate the incoming request.
     */
    public function __invoke(Request $request): ?User
    {
        $duration = (int) config('auth.timebox_duration', 200000);

        return $this->timebox->call(function (Timebox $timebox) use ($request): ?User {
            $rawEmail = (string) $request->input(Fortify::username());
            $password = (string) $request->input('password');

            if ($rawEmail === '' || $password === '') {
                return null;
            }

            $user = User::findByNormalizedEmail($rawEmail);

            // Check if password authentication is currently restricted (AUTH-057, AUTH-060)
            if ($this->abuseService->isPasswordRestricted($rawEmail, $user, $request)) {
                $this->abuseService->recordPasswordFailure($rawEmail, $request, $user);

                return null;
            }

            $provider = $this->guard->getProvider();

            if (! $user || ! $provider->validateCredentials($user, ['password' => $password])) {
                $this->abuseService->recordPasswordFailure($rawEmail, $request, $user);

                return null;
            }

            if (config('hashing.rehash_on_login', true) && method_exists($provider, 'rehashPasswordIfRequired')) {
                $provider->rehashPasswordIfRequired($user, ['password' => $password]);
            }

            if ($user->user_type !== UserType::Customer) {
                $hasConfirmedTotp = ! empty($user->two_factor_secret)
                    && ! is_null($user->two_factor_confirmed_at);

                if (! $hasConfirmedTotp && $user->account_state === AccountState::Active) {
                    $user->account_state = AccountState::MfaSetupRequired;
                    $user->save();
                }
            }

            if (! $user->canSignIn('password', allowSetupOnly: true)) {
                return null;
            }

            $timebox->returnEarly();

            return $user;
        }, $duration);
    }
}
