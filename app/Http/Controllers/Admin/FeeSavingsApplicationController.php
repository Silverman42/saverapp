<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\PreviewFeeSavingsApplicationRequest;
use App\Http\Requests\StoreFeeSavingsApplicationRequest;
use App\Models\User;
use App\Services\FeeSavingsApplicationService;
use App\Support\MoneyFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeeSavingsApplicationController extends Controller
{
    public function sources(Request $request, int $obligation, FeeSavingsApplicationService $service): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['sources' => $service->sources($actor, $obligation)]);
    }

    public function preview(PreviewFeeSavingsApplicationRequest $request, int $obligation, FeeSavingsApplicationService $service): JsonResponse
    {
        $quote = $service->preview($request->user(), $obligation, $request->validated());
        $quote['display'] = ['liability' => MoneyFormatter::formatNaira($quote['position']['cycle_liability_kobo']),
            'available' => MoneyFormatter::formatNaira($quote['position']['cycle_available_kobo']),
            'reservations' => MoneyFormatter::formatNaira($quote['position']['cycle_reservations_kobo']), 'fee' => MoneyFormatter::formatNaira($quote['amount_kobo']),
            'remaining' => MoneyFormatter::formatNaira($quote['remaining_cycle_savings_kobo']),
            'available_after' => MoneyFormatter::formatNaira($quote['remaining_available_kobo'])];

        return response()->json($quote);
    }

    public function store(StoreFeeSavingsApplicationRequest $request, int $obligation, FeeSavingsApplicationService $service): JsonResponse
    {
        $data = $request->validated();
        $data['confirmed'] = $request->boolean('confirmed');
        $group = $service->apply($request->user(), $obligation, $data, $request);

        return response()->json(['status' => 'posted', 'posting_reference' => $group->posting_reference]);
    }

    public function status(Request $request, int $obligation, string $attemptReference, FeeSavingsApplicationService $service): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return response()->json($service->status($actor, $obligation, $attemptReference));
    }
}
