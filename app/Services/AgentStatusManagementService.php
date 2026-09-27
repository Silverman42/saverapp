<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\AgentStatus;
use App\Enums\CustomerStatus;
use App\Enums\UserType;
use App\Jobs\DeliverAgentStatusNotificationIntent;
use App\Models\AgentOffboardingCase;
use App\Models\AgentProfile;
use App\Models\AgentStatusHistory;
use App\Models\AgentStatusNotificationIntent;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CustomerAssignment;
use App\Models\CustomerNameCorrection;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AgentStatusManagementService
{
    public function __construct(protected AuthorizationService $authorizationService) {}

    public function transition(
        User $actor,
        AgentProfile $agent,
        AgentStatus $targetStatus,
        int $expectedVersion,
        string $reason,
        string $agentExplanation,
    ): AgentProfile {
        return app(PlatformGuard::class)->transaction('mutation', function () use ($actor, $agent, $targetStatus, $expectedVersion, $reason, $agentExplanation): AgentProfile {
            $lockedActor = User::query()->whereKey($actor->id)->lockForUpdate()->first();
            if ($lockedActor === null || ! $this->authorizationService->allows($lockedActor, AdminPermission::AgentsManage)) {
                throw new AuthorizationException('Current authority to manage Agents is required.');
            }

            $lockedAgentUser = User::query()->whereKey($agent->user_id)->lockForUpdate()->first();
            $lockedAgent = AgentProfile::query()->whereKey($agent->id)->lockForUpdate()->first();
            if ($lockedAgentUser === null || $lockedAgent === null || $lockedAgent->user_id !== $lockedAgentUser->id) {
                throw new AuthorizationException('Agent record is unavailable.');
            }

            if ($lockedAgent->version !== $expectedVersion) {
                throw ValidationException::withMessages([
                    'version' => ['Agent profile has been modified by another process. Please refresh and retry.'],
                ]);
            }

            if ($lockedAgent->operational_status === $targetStatus) {
                return $lockedAgent;
            }

            if ($targetStatus === AgentStatus::Active) {
                $this->ensureReadyForActivation($lockedAgent, $lockedAgentUser);
            }

            $fromStatus = $lockedAgent->operational_status;
            $fromVersion = $lockedAgent->version;
            $lockedAgent->forceFill([
                'operational_status' => $targetStatus,
                'version' => $fromVersion + 1,
                'updated_by_user_id' => $lockedActor->id,
            ])->save();

            $effectiveAt = now()->utc();
            $history = AgentStatusHistory::create([
                'agent_profile_id' => $lockedAgent->id,
                'from_status' => $fromStatus->value,
                'to_status' => $targetStatus->value,
                'reason' => trim($reason),
                'agent_facing_explanation' => trim($agentExplanation),
                'changed_by_user_id' => $lockedActor->id,
                'created_at' => $effectiveAt,
            ]);

            $auditEvent = AuditEvent::record(
                eventType: 'agent.status_changed',
                targetType: 'agent',
                targetId: $lockedAgent->id,
                targetReference: $lockedAgent->agent_id,
                payload: [
                    'from_status' => $fromStatus->value,
                    'to_status' => $targetStatus->value,
                    'from_version' => $fromVersion,
                    'to_version' => $lockedAgent->version,
                    'outcome' => 'succeeded',
                ],
                actor: $lockedActor,

                context: ['executor' => self::class, 'required_permission' => $lockedActor->user_type === UserType::Admin ? 'agents.manage' : null]
            );
            $history->forceFill(['audit_event_id' => $auditEvent->id])->save();

            if ($targetStatus === AgentStatus::Inactive) {
                $this->invalidatePendingNameProposals($lockedAgentUser, $lockedActor);
            }

            $this->createNotificationIntents($lockedAgent, $lockedAgentUser, $history, $targetStatus, $effectiveAt->toIso8601String());

            return $lockedAgent;
        }, attempts: 3);
    }

    protected function ensureReadyForActivation(AgentProfile $agent, User $user): void
    {
        if ($user->user_type !== UserType::Agent
            || $user->getRoleNames()->count() !== 1
            || $user->getRoleNames()->first() !== UserType::Agent->value
            || $user->account_state !== AccountState::Active
            || $user->email_verified_at === null
            || ! $user->hasConfirmedTwoFactor()) {
            throw ValidationException::withMessages([
                'target_status' => ['The Agent must complete account activation, email verification, and MFA before operational activation.'],
            ]);
        }

        if (AgentOffboardingCase::query()->where('agent_profile_id', $agent->id)->where('is_open', 1)->lockForUpdate()->exists()) {
            throw ValidationException::withMessages([
                'target_status' => ['An open offboarding case prevents operational activation.'],
            ]);
        }
    }

    protected function invalidatePendingNameProposals(User $agentUser, User $actor): void
    {
        $corrections = CustomerNameCorrection::query()
            ->where('requested_by_user_id', $agentUser->id)
            ->where('status', 'pending')
            ->lockForUpdate()
            ->get();

        foreach ($corrections as $correction) {
            $correction->forceFill([
                'status' => 'invalidated',
                'resolved_by_user_id' => $actor->id,
                'resolved_at' => now(),
            ])->save();

            $customer = CustomerProfile::query()->findOrFail($correction->customer_profile_id);
            app(CustomerNameCorrectionService::class)->recordProposalOutcome($customer, $actor, $correction, 'customer.name_correction_invalidated', 'agents.manage');
        }
    }

    protected function createNotificationIntents(
        AgentProfile $agent,
        User $agentUser,
        AgentStatusHistory $history,
        AgentStatus $targetStatus,
        string $effectiveAt,
    ): void {
        $statusLabel = $targetStatus->displayName();
        $agentPayload = [
            'title' => 'Your Agent status was updated',
            'message' => 'Your operational status is now '.$statusLabel.'. '.$history->agent_facing_explanation,
            'status' => $statusLabel,
            'effective_at' => $effectiveAt,
            'url' => route('agents.show', $agent->agent_id),
        ];
        $this->createNotificationIntent($history, $agent, $agentUser, 'subject_agent', 'mail', 'agent_status_changed', $agentPayload);
        $this->createNotificationIntent($history, $agent, $agentUser, 'subject_agent', 'database', 'agent_status_changed', $agentPayload);

        User::query()->where('user_type', UserType::Admin)->where('account_state', AccountState::Active)
            ->orderBy('id')->each(function (User $admin) use ($agent, $history, $statusLabel, $effectiveAt): void {
                if ($admin->id !== $history->changed_by_user_id && $this->authorizationService->allows($admin, AdminPermission::AgentsManage)) {
                    $this->createNotificationIntent($history, $agent, $admin, 'managing_admin', 'database', 'agent_status_changed', [
                        'title' => 'Agent operational status changed',
                        'message' => 'An Agent operational status changed to '.$statusLabel.'.',
                        'status' => $statusLabel,
                        'effective_at' => $effectiveAt,
                        'url' => route('agents.status.edit', $agent->agent_id),
                    ]);
                }
            });

        if ($agentUser->account_state !== AccountState::Active) {
            return;
        }

        if ($targetStatus === AgentStatus::Inactive && CustomerAssignment::query()->where('agent_profile_id', $agent->id)->where('is_current', 1)
            ->whereHas('customerProfile', fn ($query) => $query->where('operational_status', '!=', CustomerStatus::Archived->value))->exists()) {
            User::query()->where('user_type', UserType::Admin)->where('account_state', AccountState::Active)->orderBy('id')
                ->each(function (User $admin) use ($agent, $history, $effectiveAt): void {
                    if ($admin->id !== $history->changed_by_user_id && $this->authorizationService->allows($admin, AdminPermission::CustomersReassign)) {
                        $this->createNotificationIntent($history, $agent, $admin, 'service_manager', 'database', 'service_interruption', [
                            'title' => 'Customer service interruption', 'message' => 'An assigned Agent is unavailable.',
                            'effective_at' => $effectiveAt, 'url' => route('customers.index'),
                        ]);
                    }
                });
        }

        $business = BusinessProfile::query()->first();
        $contact = $business?->support_email ?: $business?->support_phone;
        $restored = $targetStatus === AgentStatus::Active;
        $nextStep = $contact ? 'For help, contact '.$contact.'.' : 'For help, contact the business office.';
        CustomerAssignment::query()->where('agent_profile_id', $agent->id)->where('is_current', 1)
            ->with('customerProfile.user')->orderBy('id')
            ->each(function (CustomerAssignment $assignment) use ($agent, $history, $effectiveAt, $nextStep, $restored): void {
                $customer = $assignment->customerProfile;
                $recipient = $customer?->user;
                if ($customer === null || $customer->operational_status === CustomerStatus::Archived || $recipient === null) {
                    return;
                }

                $payload = [
                    'title' => $restored ? 'Your service contact is available again' : 'Your Customer service contact is temporarily unavailable',
                    'message' => $restored ? 'Your service contact is available again. Your Customer status and savings are unchanged.' : 'Your assigned Agent is temporarily unavailable. Your Customer status and existing savings are unchanged. '.$nextStep,
                    'status' => 'Agent unavailable',
                    'effective_at' => $effectiveAt,
                    'url' => route('customers.show', $customer->customer_id),
                ];
                $this->createNotificationIntent($history, $agent, $recipient, 'assigned_customer', 'mail', $restored ? 'agent_available' : 'agent_unavailable', $payload, $customer);
                $this->createNotificationIntent($history, $agent, $recipient, 'assigned_customer', 'database', $restored ? 'agent_available' : 'agent_unavailable', $payload, $customer);
            });
    }

    /** @param array<string, string> $payload */
    protected function createNotificationIntent(
        AgentStatusHistory $history,
        AgentProfile $agent,
        User $recipient,
        string $audienceType,
        string $channel,
        string $purpose,
        array $payload,
        ?CustomerProfile $customer = null,
    ): void {
        $intent = AgentStatusNotificationIntent::create([
            'notification_id' => (string) Str::uuid(),
            'agent_status_history_id' => $history->id,
            'recipient_user_id' => $recipient->id,
            'agent_profile_id' => $agent->id,
            'customer_profile_id' => $customer?->id,
            'audience_type' => $audienceType,
            'channel' => $channel,
            'purpose' => $purpose,
            'payload' => $payload,
            'status' => 'pending',
        ]);

        if ($channel === 'database') {
            app(NotificationPipeline::class)->capture('agent_status', $intent->id, false);
        } else {
            app(ManagementMailDelivery::class)->register('agent_status', $intent->id);
        }

        DB::afterCommit(static function () use ($intent): void {
            if ($intent->channel === 'database') {
                app(NotificationPipeline::class)->dispatchRecoverably(static fn () => DeliverAgentStatusNotificationIntent::dispatch($intent->id)->afterCommit());
            } else {
                app(NotificationPipeline::class)->dispatchRecoverably(static fn () => DeliverAgentStatusNotificationIntent::dispatch($intent->id)->afterCommit());
            }
        });
    }
}
