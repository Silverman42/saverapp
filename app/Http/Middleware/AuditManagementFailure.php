<?php

namespace App\Http\Middleware;

use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\CashDisbursement;
use App\Models\ChargeCategoryVersion;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\FeeRefund;
use App\Models\FeeRule;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\FeeOperationalIssues;
use App\Services\ResourceScopeService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AuditManagementFailure
{
    public function handle(Request $request, Closure $next): Response
    {
        $name = $request->route()?->getName();
        $family = $name !== null && str_starts_with($name, 'customers.') ? 'customer'
            : ($name !== null && str_starts_with($name, 'agents.') ? 'agent' : null);
        $controller = class_basename($request->route()?->getControllerClass() ?? '');
        if ($controller === 'ThriftPlanController' || ($controller === 'FinancialWorkflowController' && $name === 'plans.settlement.confirm')) {
            $family = 'thrift_plan';
        }
        if ($controller === 'CollectionController' && in_array($name, ['customers.collections.store', 'customers.collections.preview'], true)) {
            $family = 'collection';
        }
        $feeFamily = $this->feeFamily($name);
        if ($feeFamily !== null) {
            $family = $feeFamily;
        }
        $managementControllers = ['ThriftPlanController', 'CustomerRegistrationController', 'AgentRegistrationController', 'CustomerInvitationController', 'AgentInvitationController', 'ProfileManagementController', 'ProfileIdentityController', 'CustomerStatusController', 'AgentStatusController', 'CustomerLifecycleController', 'AgentLifecycleController', 'CustomerReassignmentController', 'CustomerRecoveryController'];
        if ((! in_array($controller, $managementControllers, true) && ! in_array($family, ['thrift_plan', 'collection'], true) && $feeFamily === null) || $family === null || ! in_array($request->method(), ['POST', 'PATCH', 'PUT', 'DELETE'], true)) {
            return $next($request);
        }
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $status = match (true) {
                $exception instanceof ValidationException => 422,
                $exception instanceof AuthorizationException => $exception->status() ?? 403,
                $exception instanceof AuthenticationException => 401,
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                $exception instanceof ModelNotFoundException => 404,
                default => 500,
            };
            $this->record($request, $family, $status);
            throw $exception;
        }
        if ($response->getStatusCode() >= 400) {
            $this->record($request, $family, $response->getStatusCode());
        }

        return $response;
    }

    private function record(Request $request, string $family, int $status): void
    {
        $outcome = match (true) {
            $status === 409 => 'Conflict', in_array($status, [401, 403, 404], true) => 'Denied', default => 'Failed'
        };
        $category = match (true) {
            $status === 422 => 'validation_failed', $status === 409 => 'state_conflict', $status >= 500 => 'system_failed', default => 'authority_or_scope_denied'
        };
        try {
            if ($this->feeFamily($request->route()?->getName()) !== null) {
                $this->recordFeeFailure($request, $family, $category, $outcome);

                return;
            }
            if ($family === 'collection') {
                $this->recordCollectionFailure($request, $category, $outcome);

                return;
            }
            if ($family === 'thrift_plan') {
                $this->recordPlanFailure($request, $category, $outcome);

                return;
            }
            $viewer = $request->user();
            $subject = null;
            if ($viewer !== null) {
                $scope = app(ResourceScopeService::class);
                $subject = $family === 'customer'
                    ? $scope->forCustomers($viewer)->where('customer_id', $request->route('customer'))->first()
                    : $scope->forAgents($viewer)->where('agent_id', $request->route('agent'))->first();
            }
            AuditEvent::record($family.'.management_attempt', $family, $subject?->id,
                $subject === null ? null : ($subject instanceof CustomerProfile ? $subject->customer_id : $subject->agent_id),
                ['category' => $category], $viewer, ['executor' => self::class, 'outcome' => $outcome, 'required_permission' => $viewer?->user_type === UserType::Admin ? (str_contains((string) $request->route()?->getName(), '.reassignment.') ? 'customers.reassign' : (str_contains((string) $request->route()?->getName(), '.recovery.') ? 'security.operations.manage' : ($family === 'agent' ? 'agents.manage' : 'customers.manage'))) : null]);
        } catch (Throwable) {
            Log::warning('Management failure audit unavailable.', ['category' => $category]);
        }
    }

    private function feeFamily(?string $name): ?string
    {
        return match ($name) {
            'admin.fees.registration.preview', 'admin.fees.registration.store',
            'admin.fees.registration.retirement-preview', 'admin.fees.registration.retire' => 'fee_rule',
            'admin.charges.publish', 'admin.charges.preview', 'admin.charges.assess' => 'charge',
            'admin.fees.obligations.waive', 'admin.fees.obligations.correct',
            'admin.fees.obligations.savings-preview', 'admin.fees.obligations.apply-savings',
            'admin.fees.refunds.store' => 'fee',
            'fee-refunds.cash', 'earnings-draws.start', 'cash-disbursements.handoff',
            'cash-disbursements.acknowledge' => 'cash_disbursement',
            default => null,
        };
    }

    private function recordFeeFailure(Request $request, string $family, string $category, string $outcome): void
    {
        DB::transaction(function () use ($request, $family, $category, $outcome): void {
            $viewer = $request->user()?->fresh();
            $operation = $request->route()?->getName();
            $subject = null;
            $customer = null;
            $permission = $family === 'cash_disbursement' ? AdminPermission::CashExecute : AdminPermission::FeesManage;
            $chargeCategory = null;
            if ($family === 'charge' && in_array($operation, ['admin.charges.preview', 'admin.charges.assess'], true)) {
                $categoryId = $request->input('category_id');
                if (is_int($categoryId) || (is_string($categoryId) && ctype_digit($categoryId))) {
                    $chargeCategory = ChargeCategoryVersion::query()->find($categoryId);
                }
                if ($chargeCategory?->kind === 'deduction') {
                    $permission = AdminPermission::DeductionsManage;
                }
            }
            $authorized = $viewer !== null && app(AuthorizationService::class)->allows($viewer, $permission);
            if ($operation === 'earnings-draws.start') {
                $authorized = $authorized && app(AuthorizationService::class)->allows($viewer, AdminPermission::FeesManage);
            }
            if ($category !== 'authority_or_scope_denied' && $viewer !== null) {
                $scope = app(ResourceScopeService::class)->forCustomers($viewer);
                if ($authorized && $family === 'fee') {
                    $routeFee = $request->route('obligation');
                    $feeId = $routeFee instanceof FeeObligation ? $routeFee->id : $routeFee;
                    if (is_int($feeId) || (is_string($feeId) && ctype_digit($feeId))) {
                        $subject = FeeObligation::query()->whereKey($feeId)
                            ->whereIn('customer_profile_id', $scope->select('customer_profiles.id'))->first();
                        $customer = $subject === null ? null : CustomerProfile::query()->find($subject->customer_profile_id);
                    }
                } elseif ($authorized && $family === 'charge' && $chargeCategory !== null) {
                    $customerReference = $request->input('customer_id');
                    $planReference = $request->input('plan_id');
                    if (is_string($customerReference) && is_string($planReference)) {
                        $customer = $scope->where('customer_id', $customerReference)->first();
                        if ($customer !== null && ThriftPlan::query()->where('customer_profile_id', $customer->id)->where('plan_id', $planReference)->exists()) {
                            $subject = $customer;
                        } else {
                            $customer = null;
                        }
                    }
                } elseif ($authorized && $family === 'fee_rule' && $request->route('feeRule') instanceof FeeRule) {
                    $subject = FeeRule::query()->find($request->route('feeRule')->id);
                } elseif ($family === 'cash_disbursement') {
                    $routeExecution = $request->route('execution');
                    $routeRefund = $request->route('refund');
                    if ($routeExecution instanceof CashDisbursement) {
                        $execution = CashDisbursement::query()->find($routeExecution->id);
                        $recipient = $execution?->recipient_user_id === $viewer->id;
                        if ($execution !== null && $operation === 'cash-disbursements.acknowledge' && $recipient) {
                            $authorized = $execution->kind !== 'earnings_draw'
                                || ($authorized && app(AuthorizationService::class)->allows($viewer, AdminPermission::FeesManage));
                        }
                        if ($authorized && $execution !== null
                            && ($execution->customer_profile_id === null ? $execution->executor_user_id === $viewer->id
                                : $scope->whereKey($execution->customer_profile_id)->exists())) {
                            $subject = $execution;
                            $customer = $execution->customer_profile_id === null ? null : CustomerProfile::query()->find($execution->customer_profile_id);
                        }
                    } elseif ($authorized && $routeRefund instanceof FeeRefund) {
                        $subject = FeeRefund::query()->whereKey($routeRefund->id)
                            ->whereIn('customer_profile_id', $scope->select('customer_profiles.id'))->first();
                        $customer = $subject === null ? null : CustomerProfile::query()->find($subject->customer_profile_id);
                    }
                }
            }
            $reference = match (true) {
                $subject instanceof CustomerProfile => $subject->customer_id,
                $subject instanceof CashDisbursement => $subject->execution_reference,
                $subject instanceof FeeRefund => $subject->refund_reference,
                $subject !== null => (string) $subject->id,
                default => null,
            };
            $correlation = null;
            $commandReference = null;
            foreach (['operation_reference', 'attempt_reference', 'publication_reference', 'refund_reference', 'execution_reference'] as $key) {
                $candidate = $request->input($key);
                if (is_string($candidate) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $candidate)) {
                    $correlation = hash('sha256', $candidate);
                    $commandReference = $candidate;
                    break;
                }
            }
            $failure = AuditEvent::record($family.'.management_attempt', $subject !== null ? $subject::class : $family,
                $subject?->id, $reference, ['category' => $category, 'operation' => $operation,
                    'customer_profile_id' => $customer?->id], $viewer,
                ['executor' => self::class, 'outcome' => $outcome, 'correlation_reference' => $correlation,
                    'source_version' => $subject instanceof CustomerProfile || $subject instanceof FeeRule ? $subject->version : 1,
                    'required_permission' => $viewer?->user_type === UserType::Admin ? $permission->value : null]);
            if ($operation === 'admin.fees.obligations.apply-savings' && $subject instanceof FeeObligation
                && $viewer instanceof User && $commandReference !== null) {
                app(FeeOperationalIssues::class)->posting($failure, $subject, $viewer, $commandReference);
            }
        });
    }

    private function recordCollectionFailure(Request $request, string $category, string $outcome): void
    {
        DB::transaction(function () use ($request, $category, $outcome): void {
            $viewer = $request->user();
            $reference = $request->route('customer');
            $customer = $viewer !== null && is_string($reference)
                ? app(ResourceScopeService::class)->forCustomers($viewer)->where('customer_id', $reference)->lockForUpdate()->first()
                : null;
            $assignment = $customer?->currentAssignment()->lockForUpdate()->first();
            $attemptReference = $request->input('attempt_reference');
            $correlation = is_string($attemptReference)
                && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $attemptReference)
                ? hash('sha256', $attemptReference) : null;
            AuditEvent::record('collection.receipt_attempt', $customer !== null ? CustomerProfile::class : 'collection',
                $customer?->id, $customer?->customer_id, [
                    'category' => $category, 'operation' => $request->route()?->getName(),
                    'customer_profile_id' => $customer?->id, 'customer_version' => $customer?->version,
                    'assignment_id' => $assignment?->id, 'assignment_version' => $assignment?->version,
                ], $viewer, ['executor' => self::class, 'outcome' => $outcome,
                    'source_version' => $customer !== null ? $customer->version : 1, 'correlation_reference' => $correlation]);
        });
    }

    private function recordPlanFailure(Request $request, string $category, string $outcome): void
    {
        $viewer = $request->user();
        $plan = null;
        $customer = null;
        if ($viewer !== null) {
            $scope = app(ResourceScopeService::class)->forCustomers($viewer);
            $routePlan = $request->route('plan');
            $planReference = $routePlan instanceof ThriftPlan ? $routePlan->plan_id : $routePlan;
            if (is_string($planReference)) {
                $plan = ThriftPlan::query()->where('plan_id', $planReference)
                    ->whereIn('customer_profile_id', $scope->select('customer_profiles.id'))->first();
            } elseif (is_string($request->route('customer'))) {
                $customer = $scope->where('customer_id', $request->route('customer'))->first();
            }
        }
        AuditEvent::record('thrift_plan.management_attempt', $plan !== null ? ThriftPlan::class : 'thrift_plan',
            $plan?->id, $plan?->plan_id, [
                'category' => $category,
                'operation' => $request->route()?->getName(),
                'customer_profile_id' => $plan !== null ? $plan->customer_profile_id : $customer?->id,
                'version' => $plan?->version,
                'terms_revision' => $plan?->current_terms_revision,
            ], $viewer, ['executor' => self::class, 'outcome' => $outcome,
                'required_permission' => null]);
    }
}
