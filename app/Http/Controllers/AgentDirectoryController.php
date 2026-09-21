<?php

namespace App\Http\Controllers;

use App\Enums\AccountState;
use App\Enums\AgentEligibilityCapability;
use App\Enums\AgentStatus;
use App\Enums\CustomerStatus;
use App\Enums\UserType;
use App\Models\AgentProfile;
use App\Models\User;
use App\Services\AgentEligibilityService;
use App\Services\ResourceScopeService;
use App\Support\IdentityNormalizer;
use App\Support\PhoneNormalizer;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AgentDirectoryController extends Controller
{
    /**
     * Display a listing of agents with search, status/eligibility filters, counts, and pagination.
     */
    public function index(
        Request $request,
        ResourceScopeService $resourceScopeService,
        AgentEligibilityService $agentEligibilityService,
    ): Response {
        Gate::authorize('viewAny', AgentProfile::class);

        /** @var User $viewer */
        $viewer = $request->user();

        // 1. Validate query filters
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'operational_status' => ['nullable', 'string', 'in:active,inactive,all'],
            'account_state' => ['nullable', 'string', 'in:active,invited,mfa_setup,suspended,deactivated,all'],
            'eligibility' => ['nullable', 'string', 'in:eligible,ineligible,all'],
            'min_customers' => ['nullable', 'integer', 'min:0'],
            'max_customers' => ['nullable', 'integer', 'min:0'],
            'registered_from' => ['nullable', 'date_format:Y-m-d'],
            'registered_to' => ['nullable', 'date_format:Y-m-d'],
            'sort' => ['nullable', 'string', 'in:created_at,name,agent_id'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'in:25,50,100'],
            'overview_period' => ['nullable', 'string', 'in:all,today,week,month'],
        ]);

        if (filled($validated['registered_from'] ?? null) && filled($validated['registered_to'] ?? null)) {
            if ($validated['registered_from'] > $validated['registered_to']) {
                throw ValidationException::withMessages([
                    'registered_from' => 'The registered from date must not be after the registered to date.',
                ]);
            }
        }

        if (filled($validated['min_customers'] ?? null) && filled($validated['max_customers'] ?? null)) {
            if ((int) $validated['min_customers'] > (int) $validated['max_customers']) {
                throw ValidationException::withMessages([
                    'min_customers' => 'Minimum assigned customers must not be greater than maximum.',
                ]);
            }
        }

        // 2. Module overview is intentionally independent from directory filters.
        $overviewPeriod = $validated['overview_period'] ?? 'all';
        [$overviewStart, $overviewEnd] = $this->overviewPeriodBounds($overviewPeriod);
        $overviewQuery = $resourceScopeService->forAgents($viewer);

        if ($overviewStart !== null && $overviewEnd !== null) {
            $overviewQuery->whereBetween('agent_profiles.created_at', [$overviewStart, $overviewEnd]);
        }

        $eligibleAgentIds = $resourceScopeService->getAuthoritativeEligibleRecipients()->pluck('id');
        $overview = [
            'total' => (clone $overviewQuery)->count(),
            'active' => (clone $overviewQuery)
                ->where('operational_status', AgentStatus::Active->value)
                ->count(),
            'eligible' => (clone $overviewQuery)->whereKey($eligibleAgentIds)->count(),
        ];

        // 3. Base scoped query
        $query = $resourceScopeService->forAgents($viewer)
            ->with(['user', 'user.roles'])
            ->withCount([
                'currentAssignments as current_customers_count' => function (Builder $subQuery): void {
                    $subQuery->whereHas('customerProfile', function (Builder $custQuery): void {
                        $custQuery->where('operational_status', '!=', CustomerStatus::Archived->value);
                    });
                },
                'currentAssignments as archived_customers_count' => function (Builder $subQuery): void {
                    $subQuery->whereHas('customerProfile', function (Builder $custQuery): void {
                        $custQuery->where('operational_status', CustomerStatus::Archived->value);
                    });
                },
            ]);

        // 3. Operational status filter
        $operationalStatus = $validated['operational_status'] ?? null;
        if (filled($operationalStatus) && $operationalStatus !== 'all') {
            $query->where('operational_status', $operationalStatus);
        }

        // 4. Account state filter (Default: exclude Deactivated accounts)
        $accountState = $validated['account_state'] ?? null;
        if ($accountState === 'all') {
            // Show all account states including deactivated
        } elseif (filled($accountState)) {
            $query->whereHas('user', function (Builder $userQuery) use ($accountState): void {
                $userQuery->where('account_state', $accountState);
            });
        } else {
            // Default presentation: non-deactivated accounts
            $query->whereHas('user', function (Builder $userQuery): void {
                $userQuery->where('account_state', '!=', AccountState::Deactivated->value);
            });
        }

        // 5. Assignment eligibility filter
        $eligibility = $validated['eligibility'] ?? null;
        if ($eligibility === 'eligible') {
            $query->where('operational_status', AgentStatus::Active)
                ->whereHas('user', function (Builder $userQuery): void {
                    $userQuery->where('user_type', UserType::Agent)
                        ->where('account_state', AccountState::Active)
                        ->whereNotNull('two_factor_confirmed_at')
                        ->where(function (Builder $sub): void {
                            $sub->whereNull('locked_until')
                                ->orWhere('locked_until', '<=', Carbon::now());
                        });
                });
        } elseif ($eligibility === 'ineligible') {
            $query->where(function (Builder $sub): void {
                $sub->where('operational_status', '!=', AgentStatus::Active)
                    ->orWhereHas('user', function (Builder $userQuery): void {
                        $userQuery->where('account_state', '!=', AccountState::Active)
                            ->orWhereNull('two_factor_confirmed_at')
                            ->orWhere(function (Builder $lockQuery): void {
                                $lockQuery->whereNotNull('locked_until')
                                    ->where('locked_until', '>', Carbon::now());
                            });
                    });
            });
        }

        // 6. Current assigned customers count range
        if (filled($validated['min_customers'] ?? null)) {
            $min = (int) $validated['min_customers'];
            $query->whereHas('currentAssignments', function (Builder $q): void {
                $q->whereHas('customerProfile', function (Builder $sub): void {
                    $sub->where('operational_status', '!=', CustomerStatus::Archived->value);
                });
            }, '>=', $min);
        }

        if (filled($validated['max_customers'] ?? null)) {
            $max = (int) $validated['max_customers'];
            $query->whereHas('currentAssignments', function (Builder $q): void {
                $q->whereHas('customerProfile', function (Builder $sub): void {
                    $sub->where('operational_status', '!=', CustomerStatus::Archived->value);
                });
            }, '<=', $max);
        }

        // 7. Registered date range in Africa/Lagos
        if (filled($validated['registered_from'] ?? null)) {
            try {
                $fromUtc = Carbon::createFromFormat('Y-m-d', $validated['registered_from'], 'Africa/Lagos')
                    ->startOfDay()
                    ->setTimezone('UTC');
                $query->where('agent_profiles.created_at', '>=', $fromUtc);
            } catch (InvalidFormatException) {
                // Handled by validation
            }
        }

        if (filled($validated['registered_to'] ?? null)) {
            try {
                $toUtc = Carbon::createFromFormat('Y-m-d', $validated['registered_to'], 'Africa/Lagos')
                    ->endOfDay()
                    ->setTimezone('UTC');
                $query->where('agent_profiles.created_at', '<=', $toUtc);
            } catch (InvalidFormatException) {
                // Handled by validation
            }
        }

        // 8. Search filter
        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery->where('agent_profiles.agent_id', 'like', "%{$search}%");

                $searchQuery->orWhereHas('user', function (Builder $userQuery) use ($search): void {
                    $userQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");

                    $normalizedEmail = IdentityNormalizer::normalizeEmail($search);
                    if ($normalizedEmail !== null && IdentityNormalizer::isValidEmail($search)) {
                        $userQuery->orWhere('email', $normalizedEmail);
                    }
                });

                $searchQuery->orWhere('agent_profiles.phone', 'like', "%{$search}%");
                $normalizedPhone = PhoneNormalizer::normalize($search);
                if ($normalizedPhone !== null) {
                    $searchQuery->orWhere('agent_profiles.phone_normalized', $normalizedPhone);
                }

                $digitsOnly = preg_replace('/[^\d]/', '', $search);
                if ($digitsOnly !== null && strlen($digitsOnly) >= 3) {
                    $searchQuery->orWhere('agent_profiles.phone_normalized', 'like', "%{$digitsOnly}%");
                }
            });
        }

        // 9. Sorting with stable tie-breaker
        $sortField = $validated['sort'] ?? 'created_at';
        $sortDirection = strtolower((string) ($validated['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        if ($sortField === 'name') {
            $query->join('users', 'agent_profiles.user_id', '=', 'users.id')
                ->select('agent_profiles.*')
                ->orderBy('users.name', $sortDirection);
        } else {
            $query->orderBy("agent_profiles.{$sortField}", $sortDirection);
        }

        // Stable tie-breaker
        $query->orderBy('agent_profiles.agent_id', $sortDirection);

        // 10. Pagination
        $perPage = (int) ($validated['per_page'] ?? 25);
        $paginated = $query->paginate($perPage)->withQueryString();

        // 11. Map items to props
        $agents = $paginated->through(function (AgentProfile $agent) use ($agentEligibilityService) {
            $user = $agent->user;
            $eligibilityResult = $agentEligibilityService->evaluate($agent, AgentEligibilityCapability::ReceiveAssignment);

            return [
                'id' => $agent->agent_id,
                'name' => $user?->name ?? 'Unknown',
                'photo_url' => $agent->profile_photo_path ? route('agents.photo', $agent->agent_id) : null,
                'email' => $user?->email,
                'phone' => $agent->phone,
                'operational_status' => $agent->operational_status->value,
                'operational_status_label' => ucfirst($agent->operational_status->value),
                'account_state' => $user?->account_state?->value,
                'account_state_label' => $user?->account_state ? ucfirst(str_replace('_', ' ', $user->account_state->value)) : 'Unknown',
                'eligibility' => [
                    'is_eligible' => $eligibilityResult->isEligible(),
                    'reason' => $eligibilityResult->reasonCode?->value,
                    'explanation' => $eligibilityResult->message,
                ],
                'current_customers_count' => (int) ($agent->current_customers_count ?? 0),
                'archived_customers_count' => (int) ($agent->archived_customers_count ?? 0),
                'registered_at' => $agent->created_at?->timezone('Africa/Lagos')->format('Y-m-d H:i'),
                'registered_at_iso' => $agent->created_at?->timezone('Africa/Lagos')->toIso8601String(),
            ];
        });

        return Inertia::render('agents/Index', [
            'agents' => $agents,
            'filters' => [
                'search' => $validated['search'] ?? '',
                'operational_status' => $operationalStatus ?? '',
                'account_state' => $validated['account_state'] ?? '',
                'eligibility' => $validated['eligibility'] ?? '',
                'min_customers' => $validated['min_customers'] ?? '',
                'max_customers' => $validated['max_customers'] ?? '',
                'registered_from' => $validated['registered_from'] ?? '',
                'registered_to' => $validated['registered_to'] ?? '',
                'sort' => $sortField,
                'direction' => $sortDirection,
                'per_page' => $perPage,
            ],
            'viewer_type' => $viewer->user_type->value,
            'overview' => $overview,
            'overview_period' => $overviewPeriod,
        ]);
    }

    /**
     * Resolve a Lagos-local registration period to its inclusive UTC bounds.
     *
     * @return array{0: Carbon|null, 1: Carbon|null}
     */
    private function overviewPeriodBounds(string $period): array
    {
        $now = now('Africa/Lagos');

        return match ($period) {
            'today' => [$now->copy()->startOfDay()->setTimezone('UTC'), $now->copy()->endOfDay()->setTimezone('UTC')],
            'week' => [$now->copy()->startOfWeek()->setTimezone('UTC'), $now->copy()->endOfWeek()->setTimezone('UTC')],
            'month' => [$now->copy()->startOfMonth()->setTimezone('UTC'), $now->copy()->endOfMonth()->setTimezone('UTC')],
            default => [null, null],
        };
    }
}
