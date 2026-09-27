<?php

namespace App\Http\Middleware;

use App\Enums\AccountState;
use App\Services\AgentTrustedDeviceService;
use App\Services\ResumeCookieService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $accessRevoked = (int) $request->session()->get('auth.lifecycle_access_version', 0) !== (int) $user->lifecycle_access_version;
            if ($accessRevoked || in_array($user->account_state, [AccountState::Suspended, AccountState::Deactivated], true)) {
                $request->session()->forget(['two_factor_replacement_required', 'url.intended']);
                Cookie::queue(app(ResumeCookieService::class)->clearResumeCookie());
                Cookie::queue(Cookie::forget(AgentTrustedDeviceService::COOKIE_NAME));
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login');
            }
            if ($user->account_state === AccountState::MfaSetupRequired || $request->session()->get('two_factor_replacement_required', false)) {
                if ($request->routeIs('two-factor.*') || $request->routeIs('logout')) {
                    return $next($request);
                }

                return redirect()->route('two-factor.enrolment');
            }

            if ($user->account_state !== AccountState::Active) {
                Auth::guard('web')->logout();

                if ($request->hasSession()) {
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }

                return redirect()->route('login');
            }
        }

        return $next($request);
    }
}
