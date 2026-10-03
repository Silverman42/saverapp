<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\FeeActionAttemptRequest;
use App\Models\User;
use App\Services\FeeActionAttemptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeeActionAttemptController extends Controller
{
    public function prepare(FeeActionAttemptRequest $request, int $obligation, FeeActionAttemptService $service): JsonResponse
    {
        return response()->json($service->prepare($request->user(), $obligation, $request->validated(), $request));
    }

    public function cancel(FeeActionAttemptRequest $request, int $obligation, FeeActionAttemptService $service): JsonResponse
    {
        return response()->json($service->cancel($request->user(), $obligation, $request->validated(), $request));
    }

    public function status(Request $request, int $obligation, string $attemptReference, FeeActionAttemptService $service): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return response()->json($service->status($actor, $obligation, $attemptReference));
    }
}
