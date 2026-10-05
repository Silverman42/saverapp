<?php

namespace App\Http\Middleware;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\ResumeCookieService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnforceSessionLimits
{
    public function __construct(
        protected ResumeCookieService $resumeCookieService,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return $next($request);
        }

        $user = $request->user();
        $session = $request->session();
        $now = Carbon::now()->timestamp;

        // Retrieve or initialize session timestamps
        $loginAt = $session->get('auth.login_at');
        if (! $loginAt) {
            $loginAt = $now;
            $session->put('auth.login_at', $loginAt);

            // Backfill created_at in sessions table if null
            $table = config('session.table', 'sessions');
            DB::table($table)
                ->where('id', $session->getId())
                ->whereNull('created_at')
                ->update(['created_at' => $loginAt]);
        }

        $lastActiveAt = $session->get('auth.last_active_at', $loginAt);

        // 1. Check maximum session lifetime (non-extendable)
        $maxLifetime = $user->maximumSessionLifetimeSeconds();
        if (($now - $loginAt) > $maxLifetime) {
            $this->recordExpiry($request, 'max_lifetime');
            Auth::guard('web')->logout();
            $session->invalidate();
            $session->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['email' => __('Your session has reached its maximum lifetime limit. Please sign in again.')]);
        }

        // 2. Check inactivity timeout
        $inactivityTimeout = $user->inactivityTimeoutSeconds();
        if (($now - $lastActiveAt) > $inactivityTimeout) {
            $this->recordExpiry($request, 'inactivity');
            Auth::guard('web')->logout();
            $session->invalidate();
            $session->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['email' => __('Your session has expired due to inactivity. Please sign in again.')]);
        }

        // 3. Update last activity timestamp on intentional user activity
        if (! $this->isPassiveBackgroundRequest($request)) {
            $session->put('auth.last_active_at', $now);
        }

        $response = $next($request);

        // 4. Update Admin/Agent resume destination cookie on eligible GET requests
        $resumeCookie = $this->resumeCookieService->recordResumeDestination($user, $request);
        if ($resumeCookie) {
            $response->headers->setCookie($resumeCookie);
        }

        return $response;
    }

    /**
     * Audit a session expiry and mark the request as a forced logout so it is not also recorded as a sign-out.
     */
    protected function recordExpiry(Request $request, string $reason): void
    {
        $request->attributes->set('auth.forced_logout', true);
        AuditEvent::record('auth.session_expired', User::class, $request->user()->id, null,
            ['outcome' => $reason], null, ['executor' => self::class]);
    }

    /**
     * Determine if request is a passive background check or polling request.
     */
    protected function isPassiveBackgroundRequest(Request $request): bool
    {
        return $request->header('X-Passive-Polling') === 'true'
            || $request->routeIs('notifications.*')
            || $request->is('broadcasting/auth');
    }
}
