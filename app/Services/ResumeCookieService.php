<?php

namespace App\Services;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

class ResumeCookieService
{
    public const COOKIE_NAME = 'saver_resume_destination';

    /**
     * List of path prefixes that are never eligible as resume destinations.
     *
     * @var list<string>
     */
    protected const INELIGIBLE_PREFIXES = [
        '/login',
        '/logout',
        '/two-factor',
        '/forgot-password',
        '/reset-password',
        '/device-eviction',
        '/assisted-recovery',
        '/password',
        '/user',
        '/settings/security',
        '/sanctum',
        '/api',
    ];

    /**
     * Record a resume destination cookie for eligible Admin and Agent page visits.
     */
    public function recordResumeDestination(User $user, Request $request): ?SymfonyCookie
    {
        if (! in_array($user->user_type, [UserType::Admin, UserType::Agent], true)) {
            return null;
        }

        if (! $this->isEligibleRequest($request)) {
            return null;
        }

        $cleanPath = $this->extractCleanPath($request);
        if (! $cleanPath) {
            return null;
        }

        $payload = [
            'user_hash' => $this->hashUserId($user->id),
            'user_type' => $user->user_type->value,
            'path' => $cleanPath,
            'saved_at' => Carbon::now()->timestamp,
        ];

        $encrypted = Crypt::encrypt($payload);

        return cookie(
            name: self::COOKIE_NAME,
            value: $encrypted,
            minutes: 24 * 60, // 24 hours
            path: '/',
            secure: true,
            httpOnly: true,
            sameSite: 'lax',
        );
    }

    /**
     * Consume and validate the resume destination for a logged-in user.
     * Returns the validated internal path, or null if invalid/expired/unauthorized.
     */
    public function consumeResumeDestination(User $user, Request $request): ?string
    {
        if (! in_array($user->user_type, [UserType::Admin, UserType::Agent], true)) {
            return null;
        }

        $rawCookie = $request->cookie(self::COOKIE_NAME);
        if (! $rawCookie || ! is_string($rawCookie)) {
            return null;
        }

        try {
            /** @var array{user_hash?: string, user_type?: string, path?: string, saved_at?: int} $payload */
            $payload = Crypt::decrypt($rawCookie);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($payload)) {
            return null;
        }

        // Verify account binding
        $expectedHash = $this->hashUserId($user->id);
        if (! isset($payload['user_hash']) || ! hash_equals($expectedHash, $payload['user_hash'])) {
            return null;
        }

        // Verify user type
        if (! isset($payload['user_type']) || $payload['user_type'] !== $user->user_type->value) {
            return null;
        }

        // Verify 24-hour expiration
        if (! isset($payload['saved_at']) || $payload['saved_at'] < Carbon::now()->subHours(24)->timestamp) {
            return null;
        }

        $path = $payload['path'] ?? null;
        if (! $path || ! is_string($path) || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }

        // Ensure path routes to an existing GET route
        $route = $this->matchGetRoute($path);
        if (! $route) {
            return null;
        }

        // Check if role middleware on target route matches user type
        $middlewares = $route->gatherMiddleware();
        foreach ($middlewares as $mw) {
            if (is_string($mw) && str_starts_with($mw, 'role:')) {
                $requiredRole = substr($mw, 5);
                if ($requiredRole !== $user->user_type->value) {
                    return null;
                }
            }
        }

        return $path;
    }

    /**
     * Clear the resume destination cookie.
     */
    public function clearResumeCookie(): SymfonyCookie
    {
        return Cookie::forget(self::COOKIE_NAME);
    }

    /**
     * Determine if an incoming request is eligible to be saved as a resume destination.
     */
    protected function isEligibleRequest(Request $request): bool
    {
        if (! $request->isMethod('GET')) {
            return false;
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return false;
        }

        $path = '/'.ltrim($request->path(), '/');

        foreach (self::INELIGIBLE_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }

        // Disallow state-changing or token query parameters
        $disallowedQueryParams = ['token', 'secret', 'password', 'code', 'signature', 'key'];
        foreach ($disallowedQueryParams as $param) {
            if ($request->query->has($param)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Extract a clean relative path with only safe query parameters.
     */
    protected function extractCleanPath(Request $request): ?string
    {
        $path = '/'.ltrim($request->path(), '/');

        // Only allow safe alphanumeric query parameters (like page, sort, filter)
        $allowedQueries = [];
        foreach ($request->query->all() as $key => $val) {
            if (is_string($key) && is_string($val) && preg_match('/^[a-zA-Z0-9_-]+$/', $key) && strlen($val) < 100) {
                $allowedQueries[$key] = $val;
            }
        }

        if (! empty($allowedQueries)) {
            $path .= '?'.http_build_query($allowedQueries);
        }

        return $path;
    }

    /**
     * Find matching registered GET route for path.
     */
    protected function matchGetRoute(string $path): ?\Illuminate\Routing\Route
    {
        try {
            $pathWithoutQuery = parse_url($path, PHP_URL_PATH) ?: '/';
            $request = Request::create($pathWithoutQuery, 'GET');
            $routes = Route::getRoutes();

            return $routes->match($request);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Create an HMAC hash of the user ID bound to app key.
     */
    protected function hashUserId(int $userId): string
    {
        return hash_hmac('sha256', (string) $userId, (string) config('app.key'));
    }
}
