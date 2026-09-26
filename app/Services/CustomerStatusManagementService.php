<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\AgentEligibilityCapability;
use App\Enums\CustomerStatus;
use App\Enums\UserType;
use App\Jobs\DeliverCustomerStatusNotificationIntent;
use App\Models\AgentProfile;
use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\CustomerStatusHistory;
use App\Models\CustomerStatusNotificationIntent;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerStatusManagementService
{
    public function __construct(
        protected CustomerActionAuthorizationGuard $actionAuthorizationGuard,
        protected AgentEligibilityService $agentEligibilityService,
        protected WithdrawalService $withdrawals,
    ) {}

    public function transition(
        User $actor,
        CustomerProfile $customer,
        CustomerStatus $targetStatus,
        int $expectedVersion,
        string $reason,
        string $customerExplanation,
    ): CustomerProfile {
        return DB::transaction(function () use ($actor, $customer, $targetStatus, $expectedVersion, $reason, $customerExplanation): CustomerProfile {
            $context = $this->actionAuthorizationGuard->lockAndAuthorize(
                actor: $actor,
                customerProfileId: $customer->id,
                ability: 'manageOperationalStatus',
                expectedCustomerVersion: $expectedVersion,
            );

            $lockedCustomer = $context->customerProfile;
            $currentStatus = $lockedCustomer->operational_status;
            if ($currentStatus === $targetStatus) {
                return $lockedCustomer;
            }

            if (! $this->transitionIsAllowed($currentStatus, $targetStatus)) {
                throw ValidationException::withMessages([
                    'target_status' => ['That Customer status transition is not allowed.'],
                ]);
            }

            if ($targetStatus === CustomerStatus::Active) {
                $this->ensureCurrentAgentIsEligible($context->currentAgentProfile);
            }

            $fromVersion = $lockedCustomer->version;
            $lockedCustomer->forceFill([
                'operational_status' => $targetStatus,
                'version' => $fromVersion + 1,
                'updated_by_user_id' => $context->actor->id,
            ])->save();

            $effectiveAt = now()->utc();
            $history = CustomerStatusHistory::create([
                'customer_profile_id' => $lockedCustomer->id,
                'from_status' => $currentStatus->value,
                'to_status' => $targetStatus->value,
                'reason' => trim($reason),
                'customer_facing_explanation' => trim($customerExplanation),
                'changed_by_user_id' => $context->actor->id,
                'created_at' => $effectiveAt,
            ]);

            $auditEvent = AuditEvent::record(
                eventType: 'customer.status_changed',
                targetType: 'customer',
                targetId: $lockedCustomer->id,
                targetReference: $lockedCustomer->customer_id,
                payload: [
                    'from_status' => $currentStatus->value,
                    'to_status' => $targetStatus->value,
                    'from_version' => $fromVersion,
                    'to_version' => $lockedCustomer->version,
                    'outcome' => 'succeeded',
                ],
                actor: $context->actor,

                context: ['executor' => self::class, 'required_permission' => $context->actor?->user_type === UserType::Admin ? 'customers.manage' : null]
            );
            $history->forceFill(['audit_event_id' => $auditEvent->id])->save();

            $this->withdrawals->applyCustomerStatus($lockedCustomer, $targetStatus);

            $this->createNotificationIntents($lockedCustomer, $history, $currentStatus, $targetStatus, $effectiveAt);

            return $lockedCustomer;
        }, attempts: 3);
    }

    protected function transitionIsAllowed(CustomerStatus $from, CustomerStatus $to): bool
    {
        return match ($from) {
            CustomerStatus::Active => in_array($to, [CustomerStatus::Inactive, CustomerStatus::Restricted], true),
            CustomerStatus::Inactive => in_array($to, [CustomerStatus::Active, CustomerStatus::Restricted], true),
            CustomerStatus::Restricted => in_array($to, [CustomerStatus::Active, CustomerStatus::Inactive], true),
            CustomerStatus::Archived => false,
        };
    }

    protected function ensureCurrentAgentIsEligible(?AgentProfile $agentProfile): void
    {
        if ($agentProfile === null) {
            throw ValidationException::withMessages([
                'target_status' => ['An eligible current Agent is required before activating this Customer.'],
            ]);
        }

        $lockedAgentUser = User::query()->whereKey($agentProfile->user_id)->lockForUpdate()->first();
        if ($lockedAgentUser === null) {
            throw new ModelNotFoundException('The assigned Agent account could not be found.');
        }

        $agentProfile->setRelation('user', $lockedAgentUser);
        $eligibility = $this->agentEligibilityService->evaluate(
            $agentProfile,
            AgentEligibilityCapability::PerformAssignedCustomerWork,
        );

        if (! $eligibility->isEligible()) {
            throw ValidationException::withMessages([
                'target_status' => ['An eligible current Agent is required: '.$eligibility->message],
            ]);
        }
    }

    protected function createNotificationIntents(
        CustomerProfile $customer,
        CustomerStatusHistory $history,
        CustomerStatus $from,
        CustomerStatus $to,
        CarbonInterface $effectiveAt,
    ): void {
        $customerUser = $customer->user()->first();
        if ($customerUser !== null) {
            $this->createNotificationIntent(
                history: $history,
                recipient: $customerUser,
                audienceType: 'subject_customer',
                channel: 'mail',
                customer: $customer,
                from: $from,
                to: $to,
                effectiveAt: $effectiveAt,
                includeUrl: false,
            );

            $this->createNotificationIntent(
                history: $history,
                recipient: $customerUser,
                audienceType: 'subject_customer',
                channel: 'database',
                customer: $customer,
                from: $from,
                to: $to,
                effectiveAt: $effectiveAt,
                includeUrl: true,
            );
        }

        $agentUser = $customer->currentAssignment?->agentProfile?->user;
        if ($agentUser !== null && $agentUser->account_state === AccountState::Active) {
            $this->createNotificationIntent(
                history: $history,
                recipient: $agentUser,
                audienceType: 'current_agent',
                channel: 'database',
                customer: $customer,
                from: $from,
                to: $to,
                effectiveAt: $effectiveAt,
                includeUrl: true,
            );
        }
    }

    protected function createNotificationIntent(
        CustomerStatusHistory $history,
        User $recipient,
        string $audienceType,
        string $channel,
        CustomerProfile $customer,
        CustomerStatus $from,
        CustomerStatus $to,
        CarbonInterface $effectiveAt,
        bool $includeUrl,
    ): void {
        $explanation = (string) $history->customer_facing_explanation;
        $intent = CustomerStatusNotificationIntent::create([
            'notification_id' => (string) Str::uuid(),
            'customer_status_history_id' => $history->id,
            'recipient_user_id' => $recipient->id,
            'audience_type' => $audienceType,
            'channel' => $channel,
            'purpose' => 'customer_status_changed',
            'customer_profile_id' => $customer->id,
            'payload' => [
                'title' => $audienceType === 'subject_customer' ? 'Your Customer status was updated' : 'An assigned Customer status was updated',
                'message' => sprintf(
                    'Customer status changed from %s to %s. %s %s',
                    $from->displayName(),
                    $to->displayName(),
                    $explanation,
                    $this->consequence($to),
                ),
                'status' => $to->displayName(),
                'effective_at' => $effectiveAt->toIso8601String(),
                'customer_id' => $customer->customer_id,
                ...($includeUrl ? ['url' => route('customers.show', $customer->customer_id)] : []),
            ],
            'status' => 'pending',
        ]);

        if ($channel === 'database') {
            app(NotificationPipeline::class)->capture('customer_status', $intent->id, false);
        }

        DB::afterCommit(static function () use ($intent): void {
            if ($intent->channel === 'database') {
                app(NotificationPipeline::class)->dispatchRecoverably(static fn () => DeliverCustomerStatusNotificationIntent::dispatch($intent->id)->afterCommit());
            } else {
                DeliverCustomerStatusNotificationIntent::dispatch($intent->id)->afterCommit();
            }
        });
    }

    protected function consequence(CustomerStatus $status): string
    {
        return match ($status) {
            CustomerStatus::Active => 'Eligible thrift activity may proceed under the applicable account, Agent, and financial controls.',
            CustomerStatus::Inactive => 'New plans and contributions are paused. Existing savings may be settled through approved workflows.',
            CustomerStatus::Restricted => 'New savings activity and payouts are held. Read access and approved corrective reviews remain available.',
            CustomerStatus::Archived => 'Operational activity is unavailable.',
        };
    }
}
