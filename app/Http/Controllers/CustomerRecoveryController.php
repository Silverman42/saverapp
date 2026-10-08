<?php

namespace App\Http\Controllers;

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Http\Requests\CustomerRecoveryRequest;
use App\Models\CustomerProfile;
use App\Models\CustomerRecovery;
use App\Services\AgentEligibilityService;
use App\Services\AuthorizationService;
use App\Services\CustomerRecoveryService;
use App\Services\ResourceScopeService;
use App\Support\Toast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CustomerRecoveryController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization): Response
    {
        abort_unless($authorization->allows($request->user(), AdminPermission::SecurityOperationsManage), 403);
        $rows = CustomerRecovery::query()->with('customerProfile')->orderByDesc('id')->paginate(25)->through(fn (CustomerRecovery $row): array => [
            'reference' => $row->reference, 'customer_reference' => $row->customerProfile->customer_id,
            'state' => $row->state, 'version' => $row->version]);

        return Inertia::render('customers/RecoveryQueue', ['recoveries' => $rows]);
    }

    public function show(Request $request, string $customer, CustomerRecoveryService $service, AuthorizationService $authorization): Response
    {
        $profile = $this->resolve($request, $customer);
        $service->authorizeView($request->user(), $profile);
        $recovery = CustomerRecovery::query()->where('customer_profile_id', $profile->id)->latest('id')->first();
        $events = $recovery === null ? [] : DB::table('customer_handover_events')->where('customer_recovery_id', $recovery->id)->orderBy('id')->get()
            ->map(fn ($event): array => ['type' => $event->event_type, 'at' => $event->effective_at,
                'actor_id' => $event->actor_user_id, 'details' => json_decode(Crypt::decryptString($event->details), true, flags: JSON_THROW_ON_ERROR)])->all();
        $deliveries = $recovery === null ? [] : DB::table('customer_handover_notices as n')->join('customer_handover_events as e', 'e.id', '=', 'n.customer_handover_event_id')
            ->where('e.customer_recovery_id', $recovery->id)->orderByDesc('n.id')->limit(25)->get(['n.channel', 'n.purpose', 'n.status', 'n.updated_at', 'n.failure_reason'])->all();

        return Inertia::render('customers/Recovery', ['customer' => ['reference' => $profile->customer_id, 'name' => $profile->user->name,
            'version' => $profile->version, 'assignment_version' => $profile->currentAssignment?->version],
            'recovery' => $recovery === null ? null : ['reference' => $recovery->reference, 'version' => $recovery->version, 'state' => $recovery->state,
                'proposed_email' => $recovery->proposed_email, 'request_expires_at' => $recovery->request_expires_at->toIso8601String(),
                'activation_expires_at' => $recovery->activation_expires_at?->toIso8601String()],
            'can_review' => $authorization->allows($request->user(), AdminPermission::SecurityOperationsManage),
            'can_initiate' => $request->user()->user_type->value === 'agent' && app(AgentEligibilityService::class)->canPerformAssignedCustomerWork($request->user()) && $profile->user->account_state === AccountState::Active && $profile->user->email_verified_at !== null, 'events' => $events, 'deliveries' => $deliveries]);
    }

    public function store(CustomerRecoveryRequest $request, string $customer, CustomerRecoveryService $service): JsonResponse
    {
        return response()->json($service->execute($request->user(), $this->resolve($request, $customer), 'request', $request->validated()));
    }

    public function update(CustomerRecoveryRequest $request, string $customer, string $recovery, string $action, CustomerRecoveryService $service): JsonResponse
    {
        return response()->json($service->execute($request->user(), $this->resolve($request, $customer), $action, $request->validated(), $recovery));
    }

    public function operation(Request $request, string $customer, string $attempt_reference, CustomerRecoveryService $service): JsonResponse
    {
        return response()->json($service->lookup($request->user(), $this->resolve($request, $customer), $attempt_reference));
    }

    public function activation(string $recovery): Response
    {
        return Inertia::render('auth/CustomerRecoveryActivation', ['reference' => $recovery]);
    }

    public function activate(Request $request, string $recovery, CustomerRecoveryService $service): RedirectResponse
    {
        $service->activate($recovery, $request->only(['token', 'password', 'password_confirmation']));
        Toast::success('Recovery complete', 'Account recovery complete. Sign in with your new email and password.');

        return to_route('login');
    }

    private function resolve(Request $request, string $reference): CustomerProfile
    {
        return app(ResourceScopeService::class)->forCustomers($request->user())->where('customer_id', $reference)->firstOrFail();
    }
}
