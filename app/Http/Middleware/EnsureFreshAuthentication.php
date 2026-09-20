<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureFreshAuthentication
{
    /**
     * Non-extendable fresh authentication window: 10 minutes.
     */
    public const FRESH_WINDOW_SECONDS = 600;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return redirect()->route('login');
        }

        $session = $request->session();
        $freshUntil = $session->get('auth.fresh_until');

        // Check fallback to password_confirmed_at if within 10 minutes
        if (! $freshUntil) {
            $confirmedAt = $session->get('auth.password_confirmed_at');
            if ($confirmedAt && (Carbon::now()->timestamp - $confirmedAt) < self::FRESH_WINDOW_SECONDS) {
                $freshUntil = $confirmedAt + self::FRESH_WINDOW_SECONDS;
                $session->put('auth.fresh_until', $freshUntil);
            }
        }

        if (! $freshUntil || $freshUntil < Carbon::now()->timestamp) {
            if ($request->expectsJson()) {
                return response()->json(['message' => __('Fresh authentication required.')], 423);
            }

            return redirect()->guest(route('password.confirm'));
        }

        return $next($request);
    }
}
