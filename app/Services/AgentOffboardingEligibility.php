<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\AgentEligibilityCapability;
use App\Enums\AgentStatus;
use App\Enums\CustomerStatus;
use App\Models\AgentOffboardingCase;
use App\Models\AgentProfile;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\CustomerRecovery;
use App\Models\StaffRecovery;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AgentOffboardingEligibility
{
    public function __construct(
        private CollectionReadService $collections,
        private WithdrawalService $withdrawals,
        private ReversalService $reversals,
        private SecurityCaseService $security,
        private AgentEligibilityService $eligibility,
        private AuthorizationService $authorization,
    ) {}

    /** @return array{eligible: bool, checks: list<array{key: string, label: string, status: string, message: string, url: ?string}>} */
    public function preview(User $actor, AgentProfile $agent, ?AgentOffboardingCase $case, bool $forUpdate = false): array
    {
        $owners = [
            'access' => ['Access and readiness', fn (): string => $case !== null && $case->status === 'in_progress'
                && (int) $case->is_open === 1 && $case->started_at !== null && $case->initiated_by_user_id !== null
                && ($owner = User::query()->whereKey($case->owner_user_id)->when($forUpdate, fn ($query) => $query->lockForUpdate())->first()) !== null
                && $this->authorization->allows($owner, AdminPermission::AgentsManage)
                && $agent->user->account_state === AccountState::Suspended && $agent->operational_status === AgentStatus::Inactive ? 'passed' : 'blocked',
                'An open, attributable case requires Suspended account access and Inactive readiness.', null],
            'customers' => ['Customer continuity', fn (): string => $this->customerContinuity($agent, $forUpdate),
                'Every non-archived Customer must have an eligible current replacement. Archived assignments may remain.', null],
            'cash' => ['Agent cash and reconciliation', fn (): string => $this->collections->agentOffboardingStatus($agent, $forUpdate),
                'Cash liabilities and reconciliation require verified owning-module settlement.', AdminPermission::ReconciliationManage],
            'financial_requests' => ['Pending financial responsibilities', fn (): string => $this->financialStatus($agent, $forUpdate),
                'Requests must be resolved or have an authoritative handover; unsupported transfers remain unavailable.', null],
            'recovery_invitations' => ['Customer recovery and invitations', fn (): string => $this->recoveryContinuity($agent, $forUpdate),
                'Recovery and invitation contact responsibility must follow a verified current assignment.', AdminPermission::SecurityOperationsManage],
            'obligations' => ['Agent security and business obligations', fn (): string => $this->obligationStatus($agent, $forUpdate),
                'Security cases and the Agent\'s own assisted recovery require authorized resolution. Money owed is settled through reconciliation, not here.', AdminPermission::SecurityOperationsManage],
            'queued_work' => ['Queued Customer work', fn (): string => $this->queuedWorkStatus($agent, $forUpdate),
                'Customer registrations and plan operations the Agent started must finish or fail before deactivation. Every other request rechecks current authority when it commits.', null],
        ];
        $checks = [];
        foreach ($owners as $key => [$label, $evaluate, $message, $permission]) {
            try {
                $status = $evaluate();
                if (! in_array($status, ['passed', 'blocked', 'unavailable'], true)) {
                    $status = 'unavailable';
                }
            } catch (RuntimeException $exception) {
                if ($exception instanceof QueryException) {
                    throw $exception;
                }
                $status = 'unavailable';
            }
            $url = null;
            if ($permission !== null && $this->authorization->allows($actor, $permission)) {
                $url = route($permission === AdminPermission::ReconciliationManage ? 'collection-batches.index' : 'admin.security.index');
            }
            $checks[] = ['key' => $key, 'label' => $label, 'status' => $status, 'message' => $message, 'url' => $url];
        }

        return ['eligible' => collect($checks)->every(fn (array $check): bool => $check['status'] === 'passed'), 'checks' => $checks];
    }

    /**
     * Recorded Agent responsibilities outside money: open security cases and an unfinished assisted recovery of the Agent's own account.
     */
    private function obligationStatus(AgentProfile $agent, bool $forUpdate): string
    {
        $security = $this->security->agentLifecycleStatus($agent->user, $forUpdate);
        if ($security !== 'passed') {
            return $security;
        }
        $recovery = StaffRecovery::query()->where('user_id', $agent->user_id)->whereNotNull('open_user_id');
        if ($forUpdate) {
            $recovery->lockForUpdate();
        }

        return $recovery->exists() ? 'blocked' : 'passed';
    }

    /**
     * Agent-authored attempts whose outcome is still undecided and could commit a Customer mutation later.
     */
    private function queuedWorkStatus(AgentProfile $agent, bool $forUpdate): string
    {
        foreach (['creation_attempts', 'plan_operation_attempts'] as $table) {
            $query = DB::table($table)->where('user_id', $agent->user_id)->where('status', 'in_progress');
            if ($forUpdate) {
                $query->lockForUpdate();
            }
            if ($query->exists()) {
                return 'blocked';
            }
        }

        return 'passed';
    }

    private function customerContinuity(AgentProfile $agent, bool $forUpdate): string
    {
        $ids = CustomerAssignment::query()->where('agent_profile_id', $agent->id)->distinct()->pluck('customer_profile_id');
        foreach ($ids->sort()->values() as $id) {
            $query = CustomerProfile::query()->whereKey($id);
            if ($forUpdate) {
                $query->lockForUpdate();
            }
            $customer = $query->first();
            if ($customer === null) {
                return 'unavailable';
            }
            if ($customer->operational_status === CustomerStatus::Archived) {
                continue;
            }
            $assignment = CustomerAssignment::query()->where('customer_profile_id', $id)->where('is_current', 1);
            if ($forUpdate) {
                $assignment->lockForUpdate();
            }
            $current = $assignment->first();
            if ($current === null || $current->agent_profile_id === $agent->id) {
                return 'blocked';
            }
            $replacement = AgentProfile::query()->whereKey($current->agent_profile_id);
            if ($forUpdate) {
                $replacement->lockForUpdate();
            }
            $profile = $replacement->first();
            if ($profile === null) {
                return 'unavailable';
            }
            $user = User::query()->whereKey($profile->user_id);
            if ($forUpdate) {
                $user->lockForUpdate();
            }
            $profile->setRelation('user', $user->first());
            if (! $this->eligibility->evaluate($profile, AgentEligibilityCapability::ReceiveAssignment)->isEligible()) {
                return 'blocked';
            }
        }

        return 'passed';
    }

    private function recoveryContinuity(AgentProfile $agent, bool $forUpdate): string
    {
        $ids = CustomerAssignment::query()->where('agent_profile_id', $agent->id)->distinct()->pluck('customer_profile_id');
        foreach ($ids as $id) {
            $customer = CustomerProfile::query()->whereKey($id)->when($forUpdate, fn ($q) => $q->lockForUpdate())->first();
            if ($customer === null) {
                return 'unavailable';
            }
            $pending = CustomerRecovery::query()->where('customer_profile_id', $id)->whereNotNull('open_customer_id')->exists()
                || DB::table('invitations')->where('user_id', $customer->user_id)->whereIn('status', ['pending_delivery', 'sent', 'opened', 'delivery_failed'])->exists();
            if ($pending && ! app(CustomerReassignmentService::class)->hasVerifiedHandover($customer, $agent->id, $forUpdate)) {
                return 'blocked';
            }
        }

        return 'passed';
    }

    private function financialStatus(AgentProfile $agent, bool $forUpdate): string
    {
        $statuses = [$this->withdrawals->agentOffboardingStatus($agent, $forUpdate), $this->reversals->agentOffboardingStatus($agent, $forUpdate)];

        return in_array('unavailable', $statuses, true) ? 'unavailable' : (in_array('blocked', $statuses, true) ? 'blocked' : 'passed');
    }
}
