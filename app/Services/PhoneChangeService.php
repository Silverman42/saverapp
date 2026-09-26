<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Models\AgentProfile;
use App\Models\CustomerProfile;
use App\Models\ProfileChangeHistory;
use App\Models\User;
use App\Support\PhoneNormalizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PhoneChangeService
{
    public function __construct(
        protected ProfileManagementService $historyService,
        protected CustomerActionAuthorizationGuard $customerAuthorizationGuard,
        protected AuthorizationService $authorizationService,
    ) {}

    public function changeCustomerPhone(User $actor, CustomerProfile $profile, string $phone, string $reason, int $expectedVersion): void
    {
        $normalizedPhone = PhoneNormalizer::normalize($phone);
        if ($normalizedPhone === null) {
            throw ValidationException::withMessages(['phone' => ['Enter a valid phone number.']]);
        }

        app(PlatformGuard::class)->transaction('mutation', function () use ($actor, $profile, $phone, $normalizedPhone, $reason, $expectedVersion): void {
            $context = $this->customerAuthorizationGuard->lockAndAuthorize(
                actor: $actor,
                customerProfileId: $profile->id,
                ability: 'update',
                expectedCustomerVersion: $expectedVersion,
            );
            $lockedActor = $context->actor;
            $lockedProfile = $context->customerProfile;
            $customer = User::query()->whereKey($lockedProfile->user_id)->lockForUpdate()->firstOrFail();
            $isSelf = $lockedActor->user_type === UserType::Customer && $lockedActor->id === $customer->id;
            $isPreActivation = $customer->email_verified_at === null || $customer->account_state === AccountState::Invited;

            Gate::forUser($lockedActor)->authorize('update', $lockedProfile);
            if (! $isSelf && ! $isPreActivation) {
                throw new AuthorizationException('Staff may correct a Customer phone number only before activation.');
            }
            if ($isSelf && ($customer->account_state !== AccountState::Active || $customer->email_verified_at === null)) {
                throw new AuthorizationException('Customer phone self-service is available only after activation.');
            }
            if (! $isSelf && blank($reason)) {
                throw ValidationException::withMessages(['reason' => ['A reason is required for a pre-activation phone correction.']]);
            }
            if ($lockedProfile->phone_normalized === $normalizedPhone) {
                return;
            }
            if (CustomerProfile::query()->where('phone_normalized', $normalizedPhone)->where('id', '!=', $lockedProfile->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['phone' => ['The phone number is already in use by a Customer.']]);
            }

            $before = ['phone' => $lockedProfile->phone];
            $lockedProfile->phone = trim($phone);
            $fromVersion = $lockedProfile->version;
            $lockedProfile->version++;
            $lockedProfile->updated_by_user_id = $lockedActor->id;
            $lockedProfile->save();
            $history = $this->historyService->recordChange(
                eventType: 'customer.phone_changed',
                targetType: CustomerProfile::class,
                targetId: $lockedProfile->id,
                subjectUser: $customer,
                actor: $lockedActor,
                changedFields: ['phone'],
                before: $before,
                after: ['phone' => $lockedProfile->phone],
                reason: $isSelf ? null : trim($reason),
                fromVersion: $fromVersion,
                toVersion: $lockedProfile->version,
                targetReference: $lockedProfile->customer_id,
            );
            if ($isSelf) {
                $this->queueSecurityNotice(
                    $history,
                    $customer,
                    'subject_customer',
                    'customer',
                    $lockedProfile->id,
                    route('customers.show', $lockedProfile->customer_id),
                    'Your Customer phone number changed',
                    'Your profile phone number changed. If you did not make this change, use the account recovery options.',
                );
                $this->queueSecurityNotice(
                    $history,
                    $customer,
                    'subject_customer',
                    'customer',
                    $lockedProfile->id,
                    route('customers.show', $lockedProfile->customer_id),
                    'Your Customer phone number changed',
                    'Your profile phone number changed. If you did not make this change, use the account recovery options.',
                    'mail',
                );

                $currentAgent = $context->currentAgentProfile?->user;
                if ($currentAgent !== null && $currentAgent->account_state === AccountState::Active) {
                    $this->historyService->createNotificationIntent(
                        history: $history,
                        recipient: $currentAgent,
                        audienceType: 'current_agent',
                        channel: 'database',
                        purpose: 'customer_phone_changed',
                        targetType: 'customer',
                        targetId: $lockedProfile->id,
                        payload: [
                            'title' => 'An assigned Customer changed their phone number',
                            'message' => 'A Customer currently assigned to you changed their contact phone number.',
                            'fields' => ['phone'],
                            'url' => route('customers.show', $lockedProfile->customer_id),
                        ],
                    );
                }
            }
        });
    }

    public function changeAgentPhone(User $actor, AgentProfile $profile, string $phone, string $reason, int $expectedVersion): void
    {
        $normalizedPhone = PhoneNormalizer::normalize($phone);
        if ($normalizedPhone === null) {
            throw ValidationException::withMessages(['phone' => ['Enter a valid phone number.']]);
        }

        app(PlatformGuard::class)->transaction('mutation', function () use ($actor, $profile, $phone, $normalizedPhone, $reason, $expectedVersion): void {
            $lockedActor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $lockedProfile = AgentProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $agent = User::query()->whereKey($lockedProfile->user_id)->lockForUpdate()->firstOrFail();
            $isSelf = $lockedActor->user_type === UserType::Agent && $lockedActor->id === $agent->id;
            $isPreActivation = $agent->email_verified_at === null || $agent->account_state === AccountState::Invited;

            Gate::forUser($lockedActor)->authorize('update', $lockedProfile);
            if ($lockedProfile->version !== $expectedVersion) {
                throw new ConflictHttpException('The Agent profile changed while you were editing it. Refresh and try again.');
            }
            if (! $isSelf && ! $isPreActivation) {
                throw new AuthorizationException('Staff may correct an Agent phone number only before activation.');
            }
            if ($isSelf && ($agent->account_state !== AccountState::Active || $agent->email_verified_at === null)) {
                throw new AuthorizationException('Agent phone self-service is available only after activation.');
            }
            if (! $isSelf && blank($reason)) {
                throw ValidationException::withMessages(['reason' => ['A reason is required for a pre-activation phone correction.']]);
            }
            if ($lockedProfile->phone_normalized === $normalizedPhone) {
                return;
            }
            if (AgentProfile::query()->where('phone_normalized', $normalizedPhone)->where('id', '!=', $lockedProfile->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['phone' => ['The phone number is already in use by an Agent.']]);
            }

            $before = ['phone' => $lockedProfile->phone];
            $lockedProfile->phone = trim($phone);
            $fromVersion = $lockedProfile->version;
            $lockedProfile->version++;
            $lockedProfile->updated_by_user_id = $lockedActor->id;
            $lockedProfile->save();
            $history = $this->historyService->recordChange(
                eventType: 'agent.phone_changed',
                targetType: AgentProfile::class,
                targetId: $lockedProfile->id,
                subjectUser: $agent,
                actor: $lockedActor,
                changedFields: ['phone'],
                before: $before,
                after: ['phone' => $lockedProfile->phone],
                reason: $isSelf ? null : trim($reason),
                fromVersion: $fromVersion,
                toVersion: $lockedProfile->version,
                targetReference: $lockedProfile->agent_id,
            );
            if ($isSelf) {
                $this->queueSecurityNotice(
                    $history,
                    $agent,
                    'subject_agent',
                    'agent',
                    $lockedProfile->id,
                    route('agents.show', $lockedProfile->agent_id),
                    'Your Agent phone number changed',
                    'Your profile phone number changed. If you did not make this change, use the account recovery options.',
                );
                $this->queueSecurityNotice(
                    $history,
                    $agent,
                    'subject_agent',
                    'agent',
                    $lockedProfile->id,
                    route('agents.show', $lockedProfile->agent_id),
                    'Your Agent phone number changed',
                    'Your profile phone number changed. If you did not make this change, use the account recovery options.',
                    'mail',
                );

                User::query()
                    ->where('user_type', UserType::Admin)
                    ->where('account_state', AccountState::Active)
                    ->get()
                    ->each(function (User $admin) use ($history, $lockedProfile): void {
                        if (! $this->authorizationService->allows($admin, AdminPermission::SecurityOperationsManage)) {
                            return;
                        }

                        $this->historyService->createNotificationIntent(
                            history: $history,
                            recipient: $admin,
                            audienceType: 'security_operations_admin',
                            channel: 'database',
                            purpose: 'agent_phone_changed_security_notice',
                            targetType: 'agent',
                            targetId: $lockedProfile->id,
                            payload: [
                                'title' => 'An Agent changed their phone number',
                                'message' => 'An Agent completed a contact phone change. Review the profile if follow-up is needed.',
                                'fields' => ['phone'],
                                'url' => route('agents.show', $lockedProfile->agent_id),
                            ],
                        );
                    });
            }
        });
    }

    protected function queueSecurityNotice(
        ProfileChangeHistory $history,
        User $user,
        string $audienceType,
        string $targetType,
        int $targetId,
        string $url,
        string $title,
        string $message,
        string $channel = 'database',
    ): void {
        $this->historyService->createNotificationIntent(
            history: $history,
            recipient: $user,
            audienceType: $audienceType,
            channel: $channel,
            purpose: 'phone_changed_security_notice',
            targetType: $targetType,
            targetId: $targetId,
            payload: ['title' => $title, 'message' => $message, 'fields' => ['phone'], 'url' => $url],
        );
    }
}
