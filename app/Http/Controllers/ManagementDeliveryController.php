<?php

namespace App\Http\Controllers;

use App\Services\ManagementDeliveryDiagnostics;
use App\Services\ResourceScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManagementDeliveryController extends Controller
{
    public function customer(Request $request, string $customer, ResourceScopeService $scope, ManagementDeliveryDiagnostics $diagnostics): JsonResponse
    {
        $profile = $scope->forCustomers($request->user())->where('customer_id', $customer)->firstOrFail();

        return response()->json($diagnostics->customer($request->user(), $profile, $this->pageSize($request)))
            ->header('Cache-Control', 'private, no-store');
    }

    public function agent(Request $request, string $agent, ResourceScopeService $scope, ManagementDeliveryDiagnostics $diagnostics): JsonResponse
    {
        $profile = $scope->forAgents($request->user())->where('agent_id', $agent)->firstOrFail();

        return response()->json($diagnostics->agent($request->user(), $profile, $this->pageSize($request)))
            ->header('Cache-Control', 'private, no-store');
    }

    private function pageSize(Request $request): int
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'per_page' => ['sometimes', 'integer', 'in:25,50,100']]);

        return (int) ($data['per_page'] ?? 25);
    }
}
