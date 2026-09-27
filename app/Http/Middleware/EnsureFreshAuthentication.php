<?php

namespace App\Http\Middleware;

use App\Services\FreshAuthenticationService;
use Closure;
use Illuminate\Http\Request;
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

        $user = $request->user();
        if (! $user || ! app(FreshAuthenticationService::class)->isFresh($user, $request)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => __('Fresh authentication required.')], 423);
            }

            if ($request->routeIs('agents.lifecycle.*')) {
                $request->session()->put('url.intended', route('agents.lifecycle.show', $request->route('agent')));

                return redirect()->route('fresh-authentication');
            }

            return redirect()->guest(route('fresh-authentication'));
        }

        return $next($request);
    }
}
