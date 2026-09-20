<?php

namespace App\Actions\Fortify;

use App\Enums\AccountState;
use App\Models\User;
use App\Services\AuthenticationAbuseService;
use App\Services\SessionManagerService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\LoginRateLimiter;

class AttemptToAuthenticateWithDeviceLimit
{
    public function __construct(
        protected StatefulGuard $guard,
        protected LoginRateLimiter $limiter,
        protected SessionManagerService $sessionManager,
    ) {}

    /**
     * Handle the incoming request.
     *
     * @param  Request  $request
     * @param  callable  $next
     * @return mixed
     */
    public function handle($request, $next)
    {
        $user = null;

        if (Fortify::$authenticateUsingCallback) {
            $user = call_user_func(Fortify::$authenticateUsingCallback, $request);
        } else {
            $provider = $this->guard->getProvider();
            $retrievedUser = $provider->retrieveByCredentials($request->only(Fortify::username(), 'password'));

            if ($retrievedUser && $provider->validateCredentials($retrievedUser, ['password' => $request->password])) {
                $user = $retrievedUser;
            }
        }

        if (! $user) {
            $this->fireFailedEvent($request);
            $this->limiter->increment($request);

            throw ValidationException::withMessages([
                Fortify::username() => [trans('auth.failed')],
            ]);
        }

        // Check concurrent device limit
        $activeSessions = $this->sessionManager->checkDeviceLimits($user);
        if ($activeSessions !== null) {
            // User has reached maximum concurrent devices.
            // Save pending login state and redirect to device eviction screen.
            $request->session()->put('login.pending_eviction', [
                'user_id' => $user->id,
                'remember' => $request->boolean('remember'),
            ]);

            throw new HttpResponseException(redirect()->route('device-eviction'));
        }

        // Establish authenticated session
        $this->guard->login($user, $request->boolean('remember'));

        // Clear password failure counters if user is active (Customer or Agent with trusted device bypass)
        if ($user->account_state === AccountState::Active) {
            app(AuthenticationAbuseService::class)->recordPasswordSuccess($user, $request);
        }

        // Initialize session timestamps
        $now = Carbon::now()->timestamp;
        $request->session()->put('auth.login_at', $now);
        $request->session()->put('auth.last_active_at', $now);
        $request->session()->put('auth.fresh_until', $now + 600);
        $request->session()->put('auth.password_confirmed_at', $now);

        return $next($request);
    }

    /**
     * Fire the failed authentication attempt event.
     */
    protected function fireFailedEvent(Request $request): void
    {
        event(new Failed($this->guard?->name ?? config('fortify.guard'), null, [
            Fortify::username() => $request->{Fortify::username()},
            'password' => $request->password,
        ]));
    }
}
