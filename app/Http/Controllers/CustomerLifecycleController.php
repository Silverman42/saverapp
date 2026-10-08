<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerLifecycleRequest;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\CustomerLifecycleEligibility;
use App\Services\CustomerLifecycleService;
use App\Services\ResourceScopeService;
use App\Support\Toast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CustomerLifecycleController extends Controller
{
    public function preview(Request $request, string $customer, CustomerLifecycleEligibility $eligibility): JsonResponse
    {
        $profile = $this->resolve($request, $customer);
        Gate::authorize('manageLifecycle', $profile);

        return response()->json(DB::transaction(fn (): array => $eligibility->preview($request->user(), $profile)));
    }

    public function archive(CustomerLifecycleRequest $request, string $customer, CustomerLifecycleService $lifecycle): RedirectResponse|JsonResponse
    {
        return $this->commit($request, $customer, 'archive', $lifecycle);
    }

    public function restore(CustomerLifecycleRequest $request, string $customer, CustomerLifecycleService $lifecycle): RedirectResponse|JsonResponse
    {
        return $this->commit($request, $customer, 'restore', $lifecycle);
    }

    public function operation(Request $request, string $customer, string $attempt_reference, CustomerLifecycleService $lifecycle): JsonResponse
    {
        $result = $lifecycle->lookup($request->user(), $this->resolve($request, $customer), $attempt_reference);
        abort_if($result === null, 404, 'Operation unavailable.');

        return response()->json($result);
    }

    private function commit(CustomerLifecycleRequest $request, string $customer, string $action, CustomerLifecycleService $lifecycle): RedirectResponse|JsonResponse
    {
        $result = $lifecycle->execute($request->user(), $this->resolve($request, $customer), $action, $request->validated());
        if ($request->expectsJson()) {
            return response()->json($result);
        }
        Toast::success('Customer updated', $action === 'archive' ? 'Customer archived.' : 'Customer restored to Inactive.');

        return to_route('customers.status.edit', $customer);
    }

    private function resolve(Request $request, string $customer): CustomerProfile
    {
        /** @var User $actor */
        $actor = $request->user();

        return app(ResourceScopeService::class)->forCustomers($actor)->where('customer_id', $customer)->firstOrFail();
    }
}
