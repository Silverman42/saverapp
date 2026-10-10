<?php

namespace App\Http\Middleware;

use App\Models\AuditEvent;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class RefreshPermissionVersionSession
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ?? (Auth::check() ? Auth::user() : null);
        if ($user === null) {
            return $next($request);
        }

        $session = $request->session();
        $sessionVersion = $session->get('auth.permission_version');

        if ($sessionVersion === null) {
            // Initialize auth.permission_version without rotating a newly established session
            $session->put('auth.permission_version', $user->permission_version);
        } elseif ($sessionVersion !== $user->permission_version) {
            // Version mismatch:
            // 1. Destroy old session ID and rotate to new ID
            $session->migrate(destroy: true);

            // 2. Regenerate CSRF token
            $session->regenerateToken();

            // 3. Clear authorization-derived session state
            $session->forget([
                'auth.permissions',
                'auth.effective_permissions',
                'auth.abilities',
            ]);

            // 4. Reload Spatie role and permission relations
            app(PermissionRegistrar::class)->clearPermissionsCollection();
            $user->unsetRelation('roles');
            $user->unsetRelation('permissions');
            $user->load(['roles', 'permissions']);

            // 5. Store current permission version
            $session->put('auth.permission_version', $user->permission_version);

            try {
                AuditEvent::record('authorization.session_refreshed', User::class, $user->id, null,
                    ['from_version' => $sessionVersion, 'to_version' => $user->permission_version], null,
                    ['executor' => self::class, 'outcome' => 'Succeeded', 'actor_category' => 'system']);
            } catch (\Throwable) {
                Log::warning('Authorization refresh evidence unavailable.', ['event_code' => 'session_refreshed']);
            }
        }

        return $next($request);
    }
}
