<?php

namespace App\Http\Middleware;

use App\Enums\AccountState;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
