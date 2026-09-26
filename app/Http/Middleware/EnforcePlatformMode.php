<?php

namespace App\Http\Middleware;

use App\Services\PlatformCatalogue;
use App\Services\PlatformDiagnostics;
use App\Services\PlatformGuard;
use App\Support\PlatformBlocked;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnforcePlatformMode
{
    public function __construct(private PlatformGuard $guard, private PlatformCatalogue $catalogue, private PlatformDiagnostics $diagnostics) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('up')) {
            return $next($request);
        }
        $started = hrtime(true);
        $operation = 'unclassified';
        $outcome = 'failed';
        $correlation = (string) Str::uuid();
        Context::add('correlation_reference', $correlation);
        try {
            $operation = $this->catalogue->requestClass($request);
            if ($operation === 'read' && $request->isMethodSafe()) {
                $this->guard->assertAllowed('read');
                $response = $next($request);
            } else {
                $response = $this->guard->transaction($operation, fn (): Response => $next($request));
            }
            $outcome = $response->getStatusCode() < 400 ? 'succeeded' : ($response->getStatusCode() < 500 ? 'denied' : 'failed');

            return $response;
        } catch (PlatformBlocked $exception) {
            $outcome = 'paused';
            $correlation = $exception->correlationReference;

            return $exception->response($request);
        } finally {
            $this->diagnostics->record('request', $operation, $started, $outcome, $correlation);
        }
    }
}
