<?php

use App\Http\Middleware\EnforcePlatformMode;
use App\Http\Middleware\EnforceSessionLimits;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureCollectionsEnabled;
use App\Http\Middleware\EnsureFreshAuthentication;
use App\Http\Middleware\EnsureUserType;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RefreshPermissionVersionSession;
use App\Services\AuditCapture;
use App\Support\AuditIdentityConflict;
use App\Support\PlatformBlocked;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(EnforcePlatformMode::class);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'saver_resume_destination', 'agent_trusted_device']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            EnsureActiveAccount::class,
            EnforceSessionLimits::class,
            RefreshPermissionVersionSession::class,
        ]);

        $middleware->alias([
            'role' => EnsureUserType::class,
            'fresh' => EnsureFreshAuthentication::class,
            'collections.enabled' => EnsureCollectionsEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(fn (PlatformBlocked $exception, Request $request) => $exception->response($request));
        $exceptions->render(function (AuditIdentityConflict $exception, Request $request) {
            app(AuditCapture::class)->reportConflict($exception);

            return null;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
