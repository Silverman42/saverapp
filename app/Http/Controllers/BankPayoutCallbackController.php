<?php

namespace App\Http\Controllers;

use App\Services\BankPayoutService;
use App\Support\InvalidPayoutCallback;
use App\Support\PayoutProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives signed provider callbacks. A callback only records evidence and triggers a same-key query;
 * it never finalizes an outcome by itself. Providers retry on any non-2xx answer.
 */
class BankPayoutCallbackController extends Controller
{
    public function __invoke(string $provider, Request $request, PayoutProvider $payoutProvider, BankPayoutService $service): JsonResponse
    {
        if ($provider !== $payoutProvider->key()) {
            return response()->json(['message' => 'Unknown provider.'], 404);
        }
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower((string) $name)] = (string) ($values[0] ?? '');
        }
        try {
            $callback = $payoutProvider->verifyCallback($request->getContent(), $headers);
        } catch (InvalidPayoutCallback) {
            return response()->json(['message' => 'The callback could not be verified.'], 400);
        }
        $disposition = $service->ingestCallback($callback);

        return response()->json(['disposition' => $disposition], $disposition === 'conflict' ? 409 : 200);
    }
}
