<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class FeePostingIssueNotificationSource
{
    /** @return array<string, mixed>|null */
    public function facts(AuditEvent $failure, FeeObligation $fee, User $actor, string $reference): ?array
    {
        $failure = $failure->fresh();
        $fee = $fee->fresh();
        $actor = $actor->fresh();
        if ($failure === null || $fee === null || $actor === null || ! Str::isUuid($reference)
            || ! app(AuthorizationService::class)->allows($actor, AdminPermission::FeesManage)
            || ! app(ResourceScopeService::class)->forCustomers($actor)->whereKey($fee->customer_profile_id)->exists()
            || ! $this->verifiedFailure($failure, $fee, $actor->id, $reference)) {
            return null;
        }
        $identity = 'posting:fee_savings_application:'.hash('sha256', json_encode([$actor->id, $fee->id, $reference], JSON_THROW_ON_ERROR));
        $facts = ['issue_kind' => 'posting_issue', 'source_identity' => $identity,
            'operation_reference' => hash('sha256', $reference), 'source_family' => 'fee_savings_application',
            'source_owner_id' => $failure->id, 'fee_obligation_id' => $fee->id, 'fee_snapshot_id' => $fee->fee_snapshot_id,
            'thrift_plan_id' => null, 'customer_profile_id' => $fee->customer_profile_id, 'actor_user_id' => $actor->id,
            'audit_event_id' => $failure->id, 'state' => 'outcome_unconfirmed', 'category' => 'system_failed',
            'timezone' => BusinessProfile::current()->timezone, 'effective_at' => CarbonImmutable::parse($failure->created_at)->utc(),
            'operator_audience' => 'fee_manager', 'command_reference' => $reference];
        $facts['state_fingerprint'] = $this->fingerprint($facts);

        return $facts;
    }

    /** @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function describe(stdClass $owner, stdClass $issue, array $context): array
    {
        $this->validateOriginal($issue, $context);
        if ($owner->channel !== 'database' || $owner->audience_type !== 'fee_manager'
            || (int) $owner->recipient_user_id !== (int) $issue->actor_user_id
            || $owner->customer_profile_id !== $issue->customer_profile_id || $owner->agent_profile_id !== null || $owner->assignment_id !== null) {
            throw new InvalidArgumentException('The original posting issue operator is unavailable.');
        }
        $customer = CustomerProfile::query()->whereKey($issue->customer_profile_id)->firstOrFail();

        return ['source_id' => (int) $issue->id, 'source_version' => 1, 'event_type' => 'posting_issue', 'facts' => [],
            'audit_event_id' => (int) $issue->audit_event_id, 'effective_at' => CarbonImmutable::parse($issue->effective_at, 'UTC'),
            'timezone' => $issue->timezone, 'operation_reference' => $issue->operation_reference, 'actor_category' => UserType::Admin->value,
            'audience' => 'fee_manager', 'customer_profile_id' => $issue->customer_profile_id, 'agent_profile_id' => null,
            'category' => 'financial', 'action_required' => true, 'action_correction_id' => null,
            'title' => 'Fee posting outcome needs verification',
            'summary' => 'A savings fee application reported a system failure. Its posting outcome is unconfirmed. Check the saved result before repeating the operation.',
            'reference' => $issue->issue_reference, 'destination' => ['route' => 'customers.show', 'parameters' => [$customer->customer_id]]];
    }

    /** @param array<string, mixed> $context */
    public function currentFingerprint(stdClass $issue, array $context): ?string
    {
        $this->validateOriginal($issue, $context);
        $application = DB::table('fee_savings_applications')->where('operation_reference', $context['command_reference'])
            ->where('actor_user_id', $issue->actor_user_id)->where('fee_obligation_id', $issue->fee_obligation_id)
            ->where('customer_profile_id', $issue->customer_profile_id)->sharedLock()->first();
        if ($application !== null) {
            try {
                $group = app(FeeSavingsApplicationService::class)->assertPosted($context['command_reference'], current: true);
                if ($group->customer_profile_id === $issue->customer_profile_id) {
                    return null;
                }
            } catch (ConflictHttpException) {
                return $this->fingerprint($context);
            }
        }

        return $this->fingerprint($context);
    }

    /** @param array<string, mixed> $context */
    public function validateOriginal(stdClass $issue, array $context): void
    {
        $reference = $context['command_reference'] ?? null;
        $failure = AuditEvent::query()->whereKey($issue->audit_event_id)->firstOrFail();
        $fee = FeeObligation::query()->whereKey($issue->fee_obligation_id)->firstOrFail();
        if (! is_string($reference) || ! Str::isUuid($reference) || $issue->issue_kind !== 'posting_issue'
            || $issue->event_type !== 'posting_issue' || $issue->state !== 'outcome_unconfirmed' || $issue->category !== 'system_failed'
            || $issue->source_family !== 'fee_savings_application' || (int) $issue->source_owner_id !== $failure->id
            || $issue->thrift_plan_id !== null || $fee->fee_snapshot_id !== $issue->fee_snapshot_id
            || $fee->customer_profile_id !== $issue->customer_profile_id || ($context['operator_audience'] ?? null) !== 'fee_manager'
            || ! hash_equals($issue->operation_reference, hash('sha256', $reference))
            || ! hash_equals($issue->source_identity, 'posting:fee_savings_application:'.hash('sha256', json_encode([(int) $issue->actor_user_id, $fee->id, $reference], JSON_THROW_ON_ERROR)))
            || ! hash_equals($issue->state_fingerprint, $this->fingerprint($context))
            || ! $this->verifiedFailure($failure, $fee, (int) $issue->actor_user_id, $reference)) {
            throw new InvalidArgumentException('The original failed fee command is unavailable.');
        }
    }

    private function verifiedFailure(AuditEvent $failure, FeeObligation $fee, int $actorId, string $reference): bool
    {
        return $failure->event_type === 'fee.management_attempt' && $failure->target_type === FeeObligation::class
            && $failure->target_id === $fee->id && $failure->target_reference === (string) $fee->id && $failure->actor_id === $actorId
            && ($failure->payload['operation'] ?? null) === 'admin.fees.obligations.apply-savings'
            && ($failure->payload['category'] ?? null) === 'system_failed'
            && ($failure->payload['customer_profile_id'] ?? null) === $fee->customer_profile_id
            && DB::table('canonical_audit_events')->where('legacy_audit_event_id', $failure->id)
                ->where('event_type', 'fee.management_attempt')->where('actor_id', $actorId)->where('target_id', $fee->id)
                ->where('required_permission', AdminPermission::FeesManage->value)->where('outcome', 'Failed')
                ->where('correlation_reference', hash('sha256', $reference))->exists();
    }

    /** @param array<string, mixed> $facts */
    private function fingerprint(array $facts): string
    {
        return hash('sha256', json_encode([$facts['issue_kind'], $facts['source_identity'], $facts['state'], $facts['category']], JSON_THROW_ON_ERROR));
    }
}
