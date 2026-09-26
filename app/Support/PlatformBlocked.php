<?php

namespace App\Support;

use App\Enums\PlatformMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PlatformBlocked extends HttpException
{
    public readonly string $correlationReference;

    public function __construct(public readonly string $errorCode = 'platform_operation_paused', public readonly PlatformMode $mode = PlatformMode::Unavailable)
    {
        $this->correlationReference = Context::get('correlation_reference') ?? (string) Str::uuid();
        parent::__construct(503, $mode->message() ?: PlatformMode::Unavailable->message(), null, ['Cache-Control' => 'no-store']);
    }

    public function response(Request $request): Response
    {
        $props = ['message' => $this->getMessage(), 'error_code' => $this->errorCode, 'correlation_reference' => $this->correlationReference];
        $operation = $request->input('attempt_reference', $request->input('operation_id'));
        if (is_string($operation) && Str::isUuid($operation)) {
            $props['operation_reference'] = $operation;
        }
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($props, 503, ['Cache-Control' => 'no-store']);
        }
        Inertia::flushShared();
        $response = Inertia::render('PlatformUnavailable', $props)->toResponse($request)->setStatusCode(503);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
