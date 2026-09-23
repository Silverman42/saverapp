<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\CustomerNameCorrection;
use App\Models\CustomerProfile;
use App\Models\ProfileChangeHistory;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CustomerNameCorrectionService
{
    public function __construct(
        protected CustomerActionAuthorizationGuard $authorizationGuard,
        protected ProfileManagementService $profileManagementService,
        protected AgentEligibilityService $agentEligibilityService,
    ) {}

    public function proposeOrCorrectBeforeActivation(
        User $actor,
        CustomerProfile $profile,
        string $proposedName,
        string $reason,
        int $expectedVersion,
    ): ?CustomerNameCorrection {
        $proposedName = trim($proposedName);

        return DB::transaction(function () use ($actor, $profile, $proposedName, $reason, $expectedVersion): ?CustomerNameCorrection {
            $context = $this->authorizationGuard->lockAndAuthorize(
                actor: $actor,
                customerProfileId: $profile->id,
                ability: 'update',
                expectedCustomerVersion: $expectedVersion,
            );
            $lockedProfile = $context->customerProfile;
            $customer = User::query()->whereKey($lockedProfile->user_id)->lockForUpdate()->firstOrFail();

            if (mb_strlen($proposedName) < 1 || mb_strlen($proposedName) > 150) {
                throw ValidationException::withMessages(['name' => ['The name must be between 1 and 150 characters.']]);
            }

            if ($customer->name === $proposedName) {
                throw ValidationException::withMessages(['name' => ['Enter a name that differs from the current name.']]);
            }

            if ($customer->email_verified_at === null || $customer->account_state === AccountState::Invited) {
                $before = ['name' => $customer->name];
                $customer->name = $proposedName;
                $customer->save();
                $this->commitEffectiveNameChange($lockedProfile, $customer, $context->actor, $before, $proposedName, $reason, 'customer.name_corrected_pre_activation');

                return null;
            }

            $this->expirePendingCorrections($lockedProfile, $context->actor);

            $pending = CustomerNameCorrection::query()
                ->where('customer_profile_id', $lockedProfile->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get();

            foreach ($pending as $previous) {
                $previous->forceFill([
                    'status' => 'replaced',
                    'resolved_by_user_id' => $context->actor->id,
                    'resolved_at' => now(),
                ])->save();
                $this->recordProposalOutcome($lockedProfile, $context->actor, $previous, 'customer.name_correction_replaced');
            }

            $correction = CustomerNameCorrection::create([
                'customer_profile_id' => $lockedProfile->id,
                'requested_by_user_id' => $context->actor->id,
                'current_name' => $customer->name,
                'proposed_name' => $proposedName,
                'reason' => trim($reason),
                'profile_version' => $lockedProfile->version,
                'status' => 'pending',
                'expires_at' => now()->addDays(7),
            ]);

            $history = $this->profileManagementService->recordChange(
                eventType: 'customer.name_correction_proposed',
                targetType: CustomerProfile::class,
                targetId: $lockedProfile->id,
                subjectUser: $customer,
                actor: $context->actor,
                changedFields: ['name_proposed'],
                before: ['name' => $customer->name],
                after: ['name' => $proposedName],
                reason: $reason,
                fromVersion: $lockedProfile->version,
                toVersion: $lockedProfile->version,
                targetReference: $lockedProfile->customer_id,
            );

            $payload = [
                'title' => 'Review a proposed name correction',
                'message' => 'An authorized staff member proposed a name correction. Review it in your signed-in account before '.now()->addDays(7)->timezone('Africa/Lagos')->format('j M Y, H:i').'.',
                'fields' => ['name'],
                'url' => route('customers.show', $lockedProfile->customer_id),
            ];

            $this->profileManagementService->createNotificationIntent(
                history: $history,
                recipient: $customer,
                audienceType: 'subject_customer',
                channel: 'database',
                purpose: 'customer_name_correction_proposed',
                targetType: 'customer',
                targetId: $lockedProfile->id,
                payload: $payload,
            );
            $this->profileManagementService->createNotificationIntent(
                history: $history,
                recipient: $customer,
                audienceType: 'subject_customer',
                channel: 'mail',
                purpose: 'customer_name_correction_proposed',
                targetType: 'customer',
                targetId: $lockedProfile->id,
                payload: $payload,
            );

            return $correction;
        });
    }

    public function changeOwnName(User $customer, CustomerProfile $profile, string $newName, string $reason, int $expectedVersion): void
    {
        $newName = trim($newName);

        DB::transaction(function () use ($customer, $profile, $newName, $reason, $expectedVersion): void {
            $context = $this->authorizationGuard->lockAndAuthorize(
                actor: $customer,
                customerProfileId: $profile->id,
                ability: 'update',
                expectedCustomerVersion: $expectedVersion,
            );
            $lockedProfile = $context->customerProfile;
            if ($context->actor->user_type !== UserType::Customer || $context->actor->id !== $lockedProfile->user_id) {
                throw new AuthorizationException('Only the Customer can change their own name.');
            }

            $lockedCustomer = User::query()->whereKey($lockedProfile->user_id)->lockForUpdate()->firstOrFail();
            if ($lockedCustomer->account_state !== AccountState::Active || $lockedCustomer->email_verified_at === null) {
                throw new AuthorizationException('Customer name self-service is available only after activation.');
            }

            if (mb_strlen($newName) < 1 || mb_strlen($newName) > 150) {
                throw ValidationException::withMessages(['name' => ['The name must be between 1 and 150 characters.']]);
            }

            if ($lockedCustomer->name === $newName) {
                return;
            }

            $this->expirePendingCorrections($lockedProfile, $context->actor);
            $pending = CustomerNameCorrection::query()
                ->where('customer_profile_id', $lockedProfile->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get();

            foreach ($pending as $correction) {
                $correction->forceFill([
                    'status' => 'invalidated',
                    'resolved_by_user_id' => $context->actor->id,
                    'resolved_at' => now(),
                ])->save();
                $this->recordProposalOutcome($lockedProfile, $context->actor, $correction, 'customer.name_correction_invalidated');
            }

            $before = ['name' => $lockedCustomer->name];
            $lockedCustomer->name = $newName;
            $lockedCustomer->save();

            $fromVersion = $lockedProfile->version;
            $lockedProfile->version++;
            $lockedProfile->updated_by_user_id = $context->actor->id;
            $lockedProfile->save();

            $history = $this->profileManagementService->recordChange(
                eventType: 'customer.name_changed',
                targetType: CustomerProfile::class,
                targetId: $lockedProfile->id,
                subjectUser: $lockedCustomer,
                actor: $context->actor,
                changedFields: ['name'],
                before: $before,
                after: ['name' => $newName],
                reason: $reason,
                fromVersion: $fromVersion,
                toVersion: $lockedProfile->version,
                targetReference: $lockedProfile->customer_id,
            );

            $this->queueCurrentAgentNameNotice($history, $lockedProfile, $context->actor);
            $this->profileManagementService->createNotificationIntent(
                history: $history,
                recipient: $lockedCustomer,
                audienceType: 'subject_customer',
                channel: 'mail',
                purpose: 'customer_name_changed_security_notice',
                targetType: 'customer',
                targetId: $lockedProfile->id,
                payload: [
                    'title' => 'Your Customer profile name changed',
                    'message' => 'Your profile name was changed. If you did not make this change, use the account recovery options.',
                    'fields' => ['name'],
                    'url' => route('customers.show', $lockedProfile->customer_id),
                ],
            );
        });
    }

    public function resolve(
        User $actor,
        CustomerProfile $profile,
        int $correctionId,
        string $decision,
    ): CustomerNameCorrection {
        if (! in_array($decision, ['accepted', 'rejected'], true)) {
            throw new \InvalidArgumentException('Unsupported name correction decision.');
        }

        return DB::transaction(function () use ($actor, $profile, $correctionId, $decision): CustomerNameCorrection {
            $context = $this->authorizationGuard->lockAndAuthorize(
                actor: $actor,
                customerProfileId: $profile->id,
                ability: 'update',
            );
            $lockedProfile = $context->customerProfile;
            $correction = CustomerNameCorrection::query()
                ->whereKey($correctionId)
                ->where('customer_profile_id', $lockedProfile->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($correction->status !== 'pending') {
                throw new ConflictHttpException('This name correction is no longer available.');
            }

            if ($correction->expires_at->isPast()) {
                $correction->forceFill(['status' => 'expired', 'resolved_at' => now()])->save();
                $this->recordProposalOutcome($lockedProfile, $context->actor, $correction, 'customer.name_correction_expired');

                return $correction;
            }

            if ($context->actor->user_type !== UserType::Customer || $context->actor->id !== $lockedProfile->user_id) {
                throw new AuthorizationException('Only the Customer can review a proposed name correction.');
            }

            if (! $this->requesterCanStillManage($correction, $lockedProfile)) {
                $correction->forceFill(['status' => 'invalidated', 'resolved_at' => now()])->save();
                $this->recordProposalOutcome($lockedProfile, $context->actor, $correction, 'customer.name_correction_invalidated');

                return $correction;
            }

            $customer = User::query()->whereKey($lockedProfile->user_id)->lockForUpdate()->firstOrFail();
            if ($lockedProfile->version !== $correction->profile_version || $customer->name !== $correction->current_name) {
                $correction->forceFill(['status' => 'invalidated', 'resolved_at' => now()])->save();
                $this->recordProposalOutcome($lockedProfile, $context->actor, $correction, 'customer.name_correction_invalidated');

                return $correction;
            }

            if ($decision === 'rejected') {
                $correction->forceFill([
                    'status' => 'rejected',
                    'resolved_by_user_id' => $context->actor->id,
                    'resolved_at' => now(),
                ])->save();
                $this->recordProposalOutcome($lockedProfile, $context->actor, $correction, 'customer.name_correction_rejected');

                return $correction;
            }

            $before = ['name' => $customer->name];
            $customer->name = $correction->proposed_name;
            $customer->save();
            $fromVersion = $lockedProfile->version;
            $lockedProfile->version++;
            $lockedProfile->updated_by_user_id = $context->actor->id;
            $lockedProfile->save();

            $correction->forceFill([
                'status' => 'accepted',
                'resolved_by_user_id' => $context->actor->id,
                'resolved_at' => now(),
            ])->save();

            $history = $this->profileManagementService->recordChange(
                eventType: 'customer.name_correction_accepted',
                targetType: CustomerProfile::class,
                targetId: $lockedProfile->id,
                subjectUser: $customer,
                actor: $context->actor,
                changedFields: ['name'],
                before: $before,
                after: ['name' => $customer->name],
                reason: $correction->reason,
                fromVersion: $fromVersion,
                toVersion: $lockedProfile->version,
                targetReference: $lockedProfile->customer_id,
            );

            $this->queueCurrentAgentNameNotice($history, $lockedProfile, $context->actor);

            return $correction;
        });
    }

    public function cancel(User $actor, CustomerProfile $profile, int $correctionId): CustomerNameCorrection
    {
        return DB::transaction(function () use ($actor, $profile, $correctionId): CustomerNameCorrection {
            $context = $this->authorizationGuard->lockAndAuthorize(
                actor: $actor,
                customerProfileId: $profile->id,
                ability: 'update',
            );
            $correction = CustomerNameCorrection::query()
                ->whereKey($correctionId)
                ->where('customer_profile_id', $context->customerProfile->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($correction->status !== 'pending' || $correction->requested_by_user_id !== $context->actor->id) {
                throw new AuthorizationException('Only the requesting actor may cancel this pending proposal.');
            }

            if (! $this->actorCanUpdate($context->actor, $context->customerProfile)) {
                throw new AuthorizationException('The requester no longer has authority to manage this Customer.');
            }

            $correction->forceFill([
                'status' => 'cancelled',
                'resolved_by_user_id' => $context->actor->id,
                'resolved_at' => now(),
            ])->save();
            $this->recordProposalOutcome($context->customerProfile, $context->actor, $correction, 'customer.name_correction_cancelled');

            return $correction;
        });
    }

    public function visiblePending(CustomerProfile $profile, User $viewer): ?CustomerNameCorrection
    {
        return DB::transaction(function () use ($profile, $viewer): ?CustomerNameCorrection {
            $this->expirePendingCorrections($profile, $viewer);

            return CustomerNameCorrection::query()
                ->where('customer_profile_id', $profile->id)
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->when($viewer->id !== $profile->user_id, fn ($query) => $query->where('requested_by_user_id', $viewer->id))
                ->latest('id')
                ->first();
        });
    }

    protected function requesterCanStillManage(CustomerNameCorrection $correction, CustomerProfile $profile): bool
    {
        $requester = User::query()->find($correction->requested_by_user_id);

        return $requester !== null && $this->actorCanUpdate($requester, $profile);
    }

    protected function actorCanUpdate(User $actor, CustomerProfile $profile): bool
    {
        if ($actor->account_state !== AccountState::Active || Gate::forUser($actor)->denies('update', $profile)) {
            return false;
        }

        if ($actor->user_type === UserType::Agent) {
            $currentAgentId = $profile->currentAssignment?->agent_profile_id;

            return $actor->agentProfile?->id === $currentAgentId
                && $this->agentEligibilityService->canPerformAssignedCustomerWork($actor);
        }

        return $actor->user_type === UserType::Admin || $actor->id === $profile->user_id;
    }

    protected function expirePendingCorrections(CustomerProfile $profile, User $actor): void
    {
        $expired = CustomerNameCorrection::query()
            ->where('customer_profile_id', $profile->id)
            ->where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->lockForUpdate()
            ->get();

        foreach ($expired as $correction) {
            $correction->forceFill(['status' => 'expired', 'resolved_at' => now()])->save();
            $this->recordProposalOutcome($profile, $actor, $correction, 'customer.name_correction_expired');
        }
    }

    /** @param array<string, string> $before */
    protected function commitEffectiveNameChange(
        CustomerProfile $profile,
        User $customer,
        User $actor,
        array $before,
        string $newName,
        string $reason,
        string $eventType,
    ): void {
        $fromVersion = $profile->version;
        $profile->version++;
        $profile->updated_by_user_id = $actor->id;
        $profile->save();
        $this->profileManagementService->recordChange(
            eventType: $eventType,
            targetType: CustomerProfile::class,
            targetId: $profile->id,
            subjectUser: $customer,
            actor: $actor,
            changedFields: ['name'],
            before: $before,
            after: ['name' => $newName],
            reason: $reason,
            fromVersion: $fromVersion,
            toVersion: $profile->version,
            targetReference: $profile->customer_id,
        );
    }

    protected function recordProposalOutcome(
        CustomerProfile $profile,
        User $actor,
        CustomerNameCorrection $correction,
        string $eventType,
    ): void {
        AuditEvent::record(
            eventType: $eventType,
            targetType: CustomerProfile::class,
            targetId: $profile->id,
            targetReference: $profile->customer_id,
            payload: [
                'correction_id' => $correction->id,
                'status' => $correction->status,
                'profile_version' => $profile->version,
            ],
            actor: $actor,
        );
    }

    protected function queueCurrentAgentNameNotice(ProfileChangeHistory $history, CustomerProfile $profile, User $actor): void
    {
        $agent = $profile->currentAssignment?->agentProfile?->user;
        if ($agent === null || $agent->id === $actor->id || $agent->account_state !== AccountState::Active) {
            return;
        }

        $this->profileManagementService->createNotificationIntent(
            history: $history,
            recipient: $agent,
            audienceType: 'current_agent',
            channel: 'database',
            purpose: 'customer_name_changed',
            targetType: 'customer',
            targetId: $profile->id,
            payload: [
                'title' => 'An assigned Customer confirmed a name change',
                'message' => 'A Customer currently assigned to you confirmed a profile name correction.',
                'fields' => ['name'],
                'url' => route('customers.show', $profile->customer_id),
            ],
        );
    }
}
