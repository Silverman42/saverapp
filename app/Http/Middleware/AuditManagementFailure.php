<?php

namespace App\Http\Middleware;

use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Services\ResourceScopeService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
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
        $managementControllers = ['CustomerRegistrationController', 'AgentRegistrationController', 'CustomerInvitationController', 'AgentInvitationController', 'ProfileManagementController', 'ProfileIdentityController', 'CustomerStatusController', 'AgentStatusController', 'CustomerLifecycleController', 'AgentLifecycleController', 'CustomerReassignmentController', 'CustomerRecoveryController'];
        if (! in_array($controller, $managementControllers, true) || $family === null || ! in_array($request->method(), ['POST', 'PATCH', 'PUT', 'DELETE'], true)) {
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
}
