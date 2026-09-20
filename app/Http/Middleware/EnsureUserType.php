<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserType
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        if (! $user || $user->user_type->value !== $role) {
            abort(403);
        }

        $roles = $user->getRoleNames();

        if ($roles->count() !== 1 || $roles->first() !== $role) {
            abort(403);
        }

        return $next($request);
    }
}
