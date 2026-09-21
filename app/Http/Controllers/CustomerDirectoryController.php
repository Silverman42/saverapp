<?php

namespace App\Http\Controllers;

use App\Enums\AccountState;
use App\Enums\CustomerStatus;
use App\Enums\UserType;
use App\Models\AgentProfile;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\AgentEligibilityService;
use App\Services\ResourceScopeService;
use App\Support\IdentityNormalizer;
use App\Support\InternalReferenceNormalizer;
use App\Support\PhoneNormalizer;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CustomerDirectoryController extends Controller
{
    /**
     * Display a paginated listing of scoped customers with search and filters.
     */
    public function index(
        Request $request,
        ResourceScopeService $resourceScopeService,
        AgentEligibilityService $agentEligibilityService,
    ): Response {
        Gate::authorize('viewAny', CustomerProfile::class);

        /** @var User $viewer */
        $viewer = $request->user();

        // 1. Validate query filters
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'operational_status' => ['nullable', 'string', 'in:active,inactive,restricted,archived,all'],
            'account_state' => ['nullable', 'string', 'in:active,invited,mfa_setup,suspended,deactivated,all'],
            'assigned_agent' => ['nullable', 'string', 'max:50'],
            'agent_eligibility' => ['nullable', 'string', 'in:eligible,ineligible,all'],
            'registered_from' => ['nullable', 'date_format:Y-m-d'],
            'registered_to' => ['nullable', 'date_format:Y-m-d'],
            'sort' => ['nullable', 'string', 'in:created_at,name,customer_id'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'in:25,50,100'],
        ]);

        if (filled($validated['registered_from'] ?? null) && filled($validated['registered_to'] ?? null)) {
            if ($validated['registered_from'] > $validated['registered_to']) {
                throw ValidationException::withMessages([
                    'registered_from' => 'The registered from date must not be after the registered to date.',
                ]);
            }
        }

        // 2. Base scoped query
        $query = $resourceScopeService->forCustomers($viewer)
            ->with(['user', 'currentAssignment.agentProfile.user']);

        // 3. Operational status filter (Default: exclude Archived)
        $operationalStatus = $validated['operational_status'] ?? null;
        if ($operationalStatus === 'all') {
            // Show all statuses including archived
        } elseif (filled($operationalStatus)) {
            $query->where('operational_status', $operationalStatus);
        } else {
            // Default presentation: non-archived customers
            $query->where('operational_status', '!=', CustomerStatus::Archived->value);
        }

        // 4. Account state filter
        $accountState = $validated['account_state'] ?? null;
        if (filled($accountState) && $accountState !== 'all') {
            $query->whereHas('user', function (Builder $userQuery) use ($accountState): void {
                $userQuery->where('account_state', $accountState);
            });
        }

        // 5. Admin-only filters: assigned agent & agent eligibility
        if ($viewer->user_type === UserType::Admin) {
            $assignedAgent = $validated['assigned_agent'] ?? null;
            if (filled($assignedAgent)) {
                $query->whereHas('currentAssignment.agentProfile', function (Builder $agentQuery) use ($assignedAgent): void {
                    $agentQuery->where('agent_id', $assignedAgent)
                        ->orWhere('id', $assignedAgent);
                });
            }

            $agentEligibility = $validated['agent_eligibility'] ?? null;
            if ($agentEligibility === 'eligible') {
                $query->whereHas('currentAssignment.agentProfile', function (Builder $agentQuery): void {
                    $agentQuery->where('operational_status', 'active')
                        ->whereHas('user', function (Builder $userQuery): void {
                            $userQuery->where('user_type', UserType::Agent)
                                ->where('account_state', AccountState::Active)
                                ->whereNotNull('two_factor_confirmed_at')
                                ->where(function (Builder $sub): void {
                                    $sub->whereNull('locked_until')
                                        ->orWhere('locked_until', '<=', Carbon::now());
                                });
                        });
                });
            } elseif ($agentEligibility === 'ineligible') {
                $query->where(function (Builder $sub): void {
                    $sub->whereDoesntHave('currentAssignment')
                        ->orWhereHas('currentAssignment.agentProfile', function (Builder $agentQuery): void {
                            $agentQuery->where('operational_status', '!=', 'active')
                                ->orWhereHas('user', function (Builder $userQuery): void {
                                    $userQuery->where('account_state', '!=', AccountState::Active)
                                        ->orWhereNull('two_factor_confirmed_at')
                                        ->orWhere(function (Builder $lockQuery): void {
                                            $lockQuery->whereNotNull('locked_until')
                                                ->where('locked_until', '>', Carbon::now());
                                        });
                                });
                        });
                });
            }
        }

        // 6. Registered date range filter in Africa/Lagos timezone
        if (filled($validated['registered_from'] ?? null)) {
            try {
                $fromUtc = Carbon::createFromFormat('Y-m-d', $validated['registered_from'], 'Africa/Lagos')
                    ->startOfDay()
                    ->setTimezone('UTC');
                $query->where('customer_profiles.created_at', '>=', $fromUtc);
            } catch (InvalidFormatException) {
                // Handled by validation
            }
        }

        if (filled($validated['registered_to'] ?? null)) {
            try {
                $toUtc = Carbon::createFromFormat('Y-m-d', $validated['registered_to'], 'Africa/Lagos')
                    ->endOfDay()
                    ->setTimezone('UTC');
                $query->where('customer_profiles.created_at', '<=', $toUtc);
            } catch (InvalidFormatException) {
                // Handled by validation
            }
        }

        // 7. Search filter
        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery->where('customer_profiles.customer_id', 'like', "%{$search}%");

                $searchQuery->orWhereHas('user', function (Builder $userQuery) use ($search): void {
                    $userQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");

                    $normalizedEmail = IdentityNormalizer::normalizeEmail($search);
                    if ($normalizedEmail !== null && IdentityNormalizer::isValidEmail($search)) {
                        $userQuery->orWhere('email', $normalizedEmail);
                    }
                });

                $searchQuery->orWhere('customer_profiles.internal_reference', 'like', "%{$search}%");
                $normalizedRef = InternalReferenceNormalizer::normalize($search);
                if ($normalizedRef !== null) {
                    $searchQuery->orWhere('customer_profiles.internal_reference_normalized', 'like', "%{$normalizedRef}%");
                }

                $searchQuery->orWhere('customer_profiles.phone', 'like', "%{$search}%");
                $normalizedPhone = PhoneNormalizer::normalize($search);
                if ($normalizedPhone !== null) {
                    $searchQuery->orWhere('customer_profiles.phone_normalized', $normalizedPhone);
                }

                $digitsOnly = preg_replace('/[^\d]/', '', $search);
                if ($digitsOnly !== null && strlen($digitsOnly) >= 3) {
                    $searchQuery->orWhere('customer_profiles.phone_normalized', 'like', "%{$digitsOnly}%");
                }
            });
        }

        // 8. Sorting with stable tie-breaker
        $sortField = $validated['sort'] ?? 'created_at';
        $sortDirection = strtolower((string) ($validated['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        if ($sortField === 'name') {
            $query->join('users', 'customer_profiles.user_id', '=', 'users.id')
                ->select('customer_profiles.*')
                ->orderBy('users.name', $sortDirection);
        } else {
            $query->orderBy("customer_profiles.{$sortField}", $sortDirection);
        }

        // Stable tie-breaker
        $query->orderBy('customer_profiles.customer_id', $sortDirection);

        // 9. Pagination
        $perPage = (int) ($validated['per_page'] ?? 25);
        $paginated = $query->paginate($perPage)->withQueryString();

        // 10. Transform items to explicit viewer-safe props
        $customers = $paginated->through(function (CustomerProfile $profile) use ($agentEligibilityService) {
            $user = $profile->user;
            $currentAssignment = $profile->currentAssignment;
            $assignedAgent = $currentAssignment?->agentProfile;
            $assignedAgentUser = $assignedAgent?->user;

            $assignedAgentData = null;
            if ($assignedAgent !== null) {
                $isEligible = $agentEligibilityService->canReceiveAssignment($assignedAgent);
                $assignedAgentData = [
                    'id' => $assignedAgent->agent_id,
                    'name' => $assignedAgentUser?->name ?? 'Unknown',
                    'is_eligible' => $isEligible,
                ];
            }

            return [
                'id' => $profile->customer_id,
                'name' => $user?->name ?? 'Unknown',
                'photo_url' => $profile->photo_path ? route('customers.photo', $profile->customer_id) : null,
                'phone' => $profile->phone,
                'email' => $user?->email,
                'assigned_agent' => $assignedAgentData,
                'operational_status' => $profile->operational_status->value,
                'operational_status_label' => ucfirst($profile->operational_status->value),
                'account_state' => $user?->account_state?->value,
                'account_state_label' => $user?->account_state ? ucfirst(str_replace('_', ' ', $user->account_state->value)) : 'Unknown',
                'current_plan' => [
                    'status' => 'unavailable',
                    'message' => 'Plan details unavailable until Module 06',
                ],
                'registered_at' => $profile->created_at?->timezone('Africa/Lagos')->format('Y-m-d H:i'),
                'registered_at_iso' => $profile->created_at?->timezone('Africa/Lagos')->toIso8601String(),
            ];
        });

        // Collect available agents for Admin filter dropdown
        $availableAgents = [];
        if ($viewer->user_type === UserType::Admin) {
            $availableAgents = AgentProfile::with('user')
                ->where('operational_status', 'active')
                ->get()
                ->map(fn (AgentProfile $agent) => [
                    'id' => $agent->agent_id,
                    'name' => $agent->user?->name ?? $agent->agent_id,
                ])
                ->all();
        }

        return Inertia::render('customers/Index', [
            'customers' => $customers,
            'filters' => [
                'search' => $validated['search'] ?? '',
                'operational_status' => $operationalStatus ?? '',
                'account_state' => $validated['account_state'] ?? '',
                'assigned_agent' => $validated['assigned_agent'] ?? '',
                'agent_eligibility' => $validated['agent_eligibility'] ?? '',
                'registered_from' => $validated['registered_from'] ?? '',
                'registered_to' => $validated['registered_to'] ?? '',
                'sort' => $sortField,
                'direction' => $sortDirection,
                'per_page' => $perPage,
            ],
            'available_agents' => $availableAgents,
            'viewer_type' => $viewer->user_type->value,
        ]);
    }
}
