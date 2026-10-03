<?php

namespace App\Services;

use App\Enums\FeeObligationEntryType;
use App\Enums\FeeRuleTiming;
use App\Enums\FeeSettlementSource;
use App\Models\AuditEvent;
use App\Models\CollectionReceipt;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Support\MoneyFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;
use ValueError;

class FeeOperationalIssueNotificationSource
{
    /** @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    public function triggerFacts(FeeObligation $fee, ThriftPlan $plan, User $actor, AuditEvent $audit, array $context): ?array
    {
        $audit = AuditEvent::query()->whereKey($audit->id)->firstOrFail();
        $fee->setRelation('entries', $fee->entries()->lockForUpdate()->get());
        $snapshot = $fee->feeSnapshot;
        $terms = $plan->currentTermsRevision();
        if ($terms === null || ! app(PlanFeeSnapshotBinding::class)->isValid($plan, $terms, $snapshot)
            || $fee->customer_profile_id !== $plan->customer_profile_id || $fee->source_type !== $snapshot->source_type
            || $fee->source_id !== $snapshot->source_id || $fee->currency !== 'NGN') {
            throw new InvalidArgumentException('The original triggered fee source is unavailable.');
        }
        if ($snapshot->settlement_source !== FeeSettlementSource::SavingsApplication
            || ! in_array($snapshot->timing, [FeeRuleTiming::FirstContribution, FeeRuleTiming::CycleCompletion], true)) {
            return null;
        }
        $outstanding = $fee->outstandingAmountKobo();
        if ($outstanding === 0) {
            return null;
        }
        $receipt = CollectionReceipt::query()->whereKey($context['source_id'])->firstOrFail();
        if ($receipt->customer_profile_id !== $fee->customer_profile_id || $receipt->thrift_plan_id !== $plan->id
            || $receipt->attempt_reference !== $context['operation_reference'] || $audit->event_type !== 'collection.receipt_posted'
            || $audit->target_type !== CollectionReceipt::class || $audit->target_id !== $receipt->id
            || $audit->target_reference !== $receipt->receipt_reference || $audit->actor_id !== $actor->id
            || ($audit->payload['customer_profile_id'] ?? null) !== $fee->customer_profile_id
            || ! is_int($context['available_kobo']) || $context['available_kobo'] < 0 || $context['timezone'] !== $receipt->timezone) {
            throw new InvalidArgumentException('The original triggered collection is unavailable.');
        }
        $assessment = $fee->entries->where('entry_type', FeeObligationEntryType::Assessment)->sole();
        $assignment = $plan->customerProfile->currentAssignment()->lockForUpdate()->first();
        $state = $outstanding > $context['available_kobo'] ? 'insufficient_funds' : 'reviewed_application_required';
        $facts = ['issue_kind' => 'trigger_unapplied', 'source_identity' => 'trigger:'.$fee->id.':'.$snapshot->id,
            'source_family' => null, 'source_owner_id' => null, 'fee_obligation_id' => $fee->id, 'fee_snapshot_id' => $snapshot->id,
            'thrift_plan_id' => $plan->id, 'customer_profile_id' => $fee->customer_profile_id, 'actor_user_id' => $actor->id,
            'audit_event_id' => $audit->id, 'state' => $state, 'category' => $state, 'timezone' => $receipt->timezone,
            'operation_reference' => $receipt->attempt_reference, 'effective_at' => $audit->created_at,
            'source_id' => $receipt->id, 'posting_group_id' => $receipt->savings_posting_group_id,
            'assessment_entry_id' => $assessment->id, 'latest_entry_id' => $fee->entries->max('id'),
            'outstanding_kobo' => $outstanding, 'available_kobo' => $context['available_kobo'], 'operator_audience' => 'fee_manager',
            'assignment_id' => $assignment?->id, 'agent_profile_id' => $assignment?->agent_profile_id];
        $facts['state_fingerprint'] = $this->fingerprint($facts);

        return $facts;
    }

    /** @return array<string, mixed>|null */
    public function deliveryFacts(string $family, int $ownerId, string $channel, string $state, string $category, int $auditId): ?array
    {
        try {
            return $this->verifiedDeliveryFacts($family, $ownerId, $channel, $state, $category, $auditId);
        } catch (DecryptException|ModelNotFoundException|InvalidArgumentException|JsonException|ConflictHttpException|ValueError) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function verifiedDeliveryFacts(string $family, int $ownerId, string $channel, string $state, string $category, int $auditId): ?array
    {
        if (! in_array($family, ['fee_rule', 'fee_application', 'charge', 'fee_obligation', 'financial_cash', 'collection'], true)
            || ! in_array($channel, ['database', 'mail'], true) || ! in_array($state, ['local_failure', 'dead_letter', 'acceptance_unknown'], true)) {
            return null;
        }
        $definition = NotificationCatalogue::OWNERS[$family];
        $owner = DB::table($definition['table'])->where('id', $ownerId)
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->first();
        if ($owner === null || $owner->channel !== $channel) {
            return null;
        }
        $descriptor = app(NotificationCatalogue::class)->describe($family, $owner);
        $source = DB::table($definition['source'])->where('id', $descriptor['source_id'])->first();
        if ($source === null || ($family === 'financial_cash' && $source->kind !== 'fee_refund')
            || ($family === 'collection' && ! DB::table('collection_fee_components')->where('collection_receipt_id', $source->id)->exists())) {
            return null;
        }
        $audit = AuditEvent::query()->whereKey($auditId)->firstOrFail();
        $collectionMail = $family === 'collection' && $channel === 'mail';
        if (($audit->payload['notification_reference'] ?? null) !== $owner->notification_id
            || ($audit->payload['channel'] ?? null) !== $channel
            || ! str_ends_with($audit->event_type, $channel === 'mail' && ! $collectionMail ? '.delivery_state_recorded' : '.delivery_attempt')
            || ($family !== 'collection' && ($audit->payload['source_audit_event_id'] ?? null) !== $descriptor['audit_event_id'])) {
            return null;
        }
        if ($collectionMail) {
            if (($audit->payload['category'] ?? null) !== $category
                || ($audit->payload['attempt'] ?? null) !== (int) $owner->attempt_count
                || ! (($state === 'acceptance_unknown' && $owner->status === 'uncertain'
                        && in_array($category, ['acceptance_unconfirmed', 'delivery_uncertain', 'retry_budget_exhausted'], true)
                        && (int) $owner->attempt_count > 0 && is_string($owner->rendered_snapshot) && is_string($owner->rendered_hash)
                        && hash_equals($owner->rendered_hash, hash('sha256', Crypt::decryptString($owner->rendered_snapshot))))
                    || ($state === 'dead_letter' && $owner->status === 'failed' && $category === 'retry_budget_exhausted'))) {
                return null;
            }
        } elseif ($channel === 'mail') {
            if ($state !== 'acceptance_unknown' || $category !== 'acceptance_unknown' || $owner->status !== 'unknown'
                || ($audit->payload['outcome'] ?? null) !== 'acceptance_unknown'
                || ! DB::table('management_delivery_attempts')->where('owner_family', $family)->where('owner_id', $ownerId)
                    ->where('outcome', 'acceptance_unknown')->exists()) {
                return null;
            }
        } else {
            $alias = DB::table('notification_inbox_aliases')->where('family', $family)->where('owner_intent_id', $ownerId)->first();
            $attempt = $alias === null ? null : DB::table('notification_inbox_attempts')->where('intent_id', $alias->intent_id)
                ->where('attempt_number', $audit->payload['attempt'] ?? null)->first();
            if ($attempt === null || $attempt->failure_category === null || $attempt->failure_category !== $category
                || ($audit->payload['category'] ?? null) !== $category
                || ($audit->payload['attempt'] ?? null) !== (int) $attempt->attempt_number
                || ($state === 'dead_letter' ? $attempt->outcome !== 'dead_letter' : $attempt->outcome !== 'pending')) {
                return null;
            }
        }
        $sourceAudit = AuditEvent::query()->whereKey($descriptor['audit_event_id'])->firstOrFail();
        $audience = 'fee_manager';
        $actorId = $sourceAudit->actor_id;
        if ($family === 'collection' && DB::table('users')->where('id', $actorId)->value('user_type') === 'agent') {
            $audience = 'current_agent';
        } elseif ($family === 'charge') {
            $categorySource = DB::table('charge_category_versions')->where('id', $source->charge_category_version_id)->first();
            $audience = $categorySource?->kind === 'deduction' ? 'deduction_manager' : 'fee_manager';
        } elseif ($family === 'financial_cash') {
            $refund = DB::table('fee_refunds')->where('id', $source->fee_refund_id)->first();
            if ($source->event_type !== 'refund_authorized') {
                $execution = DB::table('cash_disbursements')->where('id', $source->cash_disbursement_id)->first();
                $actorId = $execution?->executor_user_id;
                $audience = 'refund_cash_operator';
            } elseif ($refund?->compensation_posting_group_id !== null) {
                $actorId = $refund->actor_user_id;
                $audience = 'refund_correction_operator';
            }
        }
        $facts = ['issue_kind' => 'delivery_issue', 'source_identity' => 'delivery:'.$family.':'.$ownerId.':'.$channel,
            'source_family' => $family, 'source_owner_id' => $ownerId, 'fee_obligation_id' => null, 'fee_snapshot_id' => null,
            'thrift_plan_id' => null, 'customer_profile_id' => $descriptor['customer_profile_id'], 'actor_user_id' => $actorId,
            'audit_event_id' => $audit->id, 'state' => $state, 'category' => $category, 'timezone' => $descriptor['timezone'],
            'operation_reference' => $owner->notification_id, 'effective_at' => $audit->created_at, 'channel' => $channel,
            'original_source_id' => $descriptor['source_id'], 'original_source_audit_event_id' => $descriptor['audit_event_id'],
            'operator_audience' => $audience];
        $facts['state_fingerprint'] = $this->fingerprint($facts);

        return $facts;
    }

    /** @return array<string, mixed> */
    public function describe(stdClass $owner, stdClass $issue): array
    {
        $context = $this->context($issue);
        if ($issue->issue_kind === 'posting_issue') {
            return app(FeePostingIssueNotificationSource::class)->describe($owner, $issue, $context);
        }
        $this->validateOriginal($issue, $context);
        if ($owner->channel !== 'database' || (int) $owner->fee_operational_issue_id !== (int) $issue->id
            || $owner->customer_profile_id !== $issue->customer_profile_id) {
            throw new InvalidArgumentException('Fee issue notification owner changed.');
        }
        if ($owner->audience_type === 'current_agent') {
            if (! ($issue->issue_kind === 'trigger_unapplied' || ($issue->issue_kind === 'delivery_issue' && $issue->source_family === 'collection'))
                || $owner->assignment_id !== $context['assignment_id']
                || $owner->agent_profile_id !== $context['agent_profile_id']
                || ! DB::table('customer_assignments as assignment')->join('agent_profiles as agent', 'agent.id', '=', 'assignment.agent_profile_id')
                    ->where('assignment.id', $owner->assignment_id)->where('assignment.customer_profile_id', $issue->customer_profile_id)
                    ->where('agent.id', $owner->agent_profile_id)->where('agent.user_id', $owner->recipient_user_id)->exists()) {
                throw new InvalidArgumentException('Fee issue original Agent changed.');
            }
        } elseif ($owner->audience_type !== $context['operator_audience'] || $owner->agent_profile_id !== null || $owner->assignment_id !== null
            || ($issue->issue_kind === 'delivery_issue' && (int) $owner->recipient_user_id !== (int) $issue->actor_user_id)) {
            throw new InvalidArgumentException('Fee issue operator audience changed.');
        }
        if ($issue->issue_kind === 'delivery_issue' && (int) $owner->recipient_user_id !== $context['actor_user_id']) {
            throw new InvalidArgumentException('Fee issue original operator changed.');
        }
        $trigger = $issue->issue_kind === 'trigger_unapplied';
        $summary = $trigger
            ? 'Agreed fee remains unpaid. Outstanding '.MoneyFormatter::formatNaira($context['outstanding_kobo'])
                .'. Unreserved savings '.MoneyFormatter::formatNaira($context['available_kobo']).'. '
                .($issue->state === 'insufficient_funds' ? 'Insufficient savings for the full agreed fee.' : 'A reviewed application is required before settling this fee.')
            : ($issue->state === 'acceptance_unknown'
                ? 'Fee notification email acceptance is unconfirmed. Review its recorded delivery evidence before any further action. Do not repeat the financial operation or resend an uncertain email.'
                : 'A fee notification could not be delivered locally. Review its recorded delivery evidence and bounded recovery state. The original financial operation remains unchanged.');
        $customer = $issue->customer_profile_id === null ? null : CustomerProfile::query()->whereKey($issue->customer_profile_id)->firstOrFail();

        return ['source_id' => (int) $issue->id, 'source_version' => 1, 'event_type' => $issue->event_type,
            'facts' => [], 'audit_event_id' => (int) $issue->audit_event_id, 'effective_at' => CarbonImmutable::parse($issue->effective_at, 'UTC'),
            'timezone' => $issue->timezone, 'operation_reference' => $issue->operation_reference,
            'actor_category' => $issue->actor_user_id === null ? 'system' : 'authenticated', 'audience' => $owner->audience_type,
            'customer_profile_id' => $issue->customer_profile_id, 'agent_profile_id' => $owner->agent_profile_id,
            'action_correction_id' => null, 'category' => 'financial', 'action_required' => true,
            'title' => $trigger ? 'Agreed fee needs settlement' : 'Fee notification needs attention', 'summary' => $summary,
            'reference' => $issue->issue_reference,
            'destination' => $customer === null ? ['route' => 'admin.fees.registration.index', 'parameters' => []]
                : ['route' => 'customers.show', 'parameters' => [$customer->customer_id]]];
    }

    public function isActionable(int $issueId): bool
    {
        try {
            $issue = DB::table('fee_operational_issues')->where('id', $issueId)->first();
            $current = $this->currentFingerprint($issueId);

            return $issue !== null && $current !== null && hash_equals($issue->state_fingerprint, $current);
        } catch (Throwable) {
            return false;
        }
    }

    public function currentFingerprint(int $issueId): ?string
    {
        try {
            return DB::transaction(function () use ($issueId): ?string {
                $issue = DB::table('fee_operational_issues')->where('id', $issueId)->first();
                if ($issue === null) {
                    return null;
                }
                $context = $this->context($issue);
                if ($issue->customer_profile_id !== null) {
                    CustomerProfile::query()->whereKey($issue->customer_profile_id)->lockForUpdate()->firstOrFail();
                }
                if ($issue->issue_kind === 'posting_issue') {
                    return app(FeePostingIssueNotificationSource::class)->currentFingerprint($issue, $context);
                }
                if ($issue->thrift_plan_id !== null) {
                    ThriftPlan::query()->whereKey($issue->thrift_plan_id)->lockForUpdate()->firstOrFail();
                }
                $this->validateOriginal($issue, $context);
                if ($issue->issue_kind === 'trigger_unapplied') {
                    $plan = ThriftPlan::query()->whereKey($issue->thrift_plan_id)->lockForUpdate()->firstOrFail();
                    $fee = FeeObligation::query()->whereKey($issue->fee_obligation_id)->lockForUpdate()->firstOrFail();
                    $fee->setRelation('entries', $fee->entries()->lockForUpdate()->get());
                    $outstanding = $fee->outstandingAmountKobo();
                    if ($outstanding === 0 || $plan->currentTermsRevision()?->fee_snapshot_id !== $issue->fee_snapshot_id) {
                        return null;
                    }
                    $available = app(CollectionReadService::class)->position($plan->customerProfile, true)['available_kobo'];
                    $state = $outstanding > $available ? 'insufficient_funds' : 'reviewed_application_required';

                    return $this->fingerprint([...$context, 'latest_entry_id' => $fee->entries->max('id'),
                        'outstanding_kobo' => $outstanding, 'available_kobo' => $available, 'state' => $state, 'category' => $state]);
                }
                $definition = NotificationCatalogue::OWNERS[$issue->source_family] ?? null;
                $sourceOwner = $definition === null ? null : DB::table($definition['table'])->where('id', $issue->source_owner_id)->lockForUpdate()->first();
                if ($sourceOwner === null) {
                    return null;
                }
                if ($context['channel'] === 'database') {
                    $alias = DB::table('notification_inbox_aliases')->where('family', $issue->source_family)->where('owner_intent_id', $issue->source_owner_id)->first();
                    $inbox = $alias === null ? null : DB::table('notification_inbox_intents')->where('id', $alias->intent_id)->lockForUpdate()->first();
                    $latest = $inbox === null ? null : DB::table('notification_inbox_attempts')->where('intent_id', $inbox->id)->orderByDesc('attempt_number')->first();
                    $expected = $issue->state === 'dead_letter' ? 'dead_letter' : 'pending';
                    if ($inbox === null || $inbox->status !== $expected || $latest === null
                        || $latest->outcome !== $expected || $latest->failure_category !== $issue->category) {
                        return null;
                    }
                } elseif ($issue->source_family === 'collection') {
                    if ($sourceOwner->status !== ($issue->state === 'acceptance_unknown' ? 'uncertain' : 'failed')) {
                        return null;
                    }
                } elseif ($sourceOwner->status !== 'unknown') {
                    return null;
                }
                $facts = $this->deliveryFacts($issue->source_family, (int) $issue->source_owner_id, $context['channel'],
                    $issue->state, $issue->category, (int) $issue->audit_event_id);

                return $facts['state_fingerprint'] ?? null;
            });
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    public function context(stdClass $issue): array
    {
        $context = json_decode(Crypt::decryptString($issue->context_ciphertext), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($context) || ($context['schema_version'] ?? null) !== 1 || (int) $issue->version !== 1
            || $issue->event_type !== $issue->issue_kind || ! in_array($issue->issue_kind, ['trigger_unapplied', 'delivery_issue', 'posting_issue'], true)) {
            throw new InvalidArgumentException('Fee issue captured context is unavailable.');
        }
        foreach (['issue_reference', 'issue_kind', 'source_identity', 'state_fingerprint', 'source_family', 'source_owner_id',
            'fee_obligation_id', 'fee_snapshot_id', 'thrift_plan_id', 'customer_profile_id', 'actor_user_id', 'audit_event_id',
            'state', 'category', 'timezone', 'operation_reference'] as $key) {
            if (! array_key_exists($key, $context) || $context[$key] !== $issue->{$key}) {
                throw new InvalidArgumentException('Fee issue original context changed.');
            }
        }
        if (! hash_equals($issue->state_fingerprint, $this->fingerprint($context))) {
            throw new InvalidArgumentException('Fee issue captured state changed.');
        }

        return $context;
    }

    /** @param array<string, mixed> $context */
    private function validateOriginal(stdClass $issue, array $context): void
    {
        $audit = AuditEvent::query()->whereKey($issue->audit_event_id)->firstOrFail();
        if ($issue->issue_kind === 'trigger_unapplied') {
            $receipt = CollectionReceipt::query()->whereKey($context['source_id'])->firstOrFail();
            $fee = FeeObligation::query()->whereKey($issue->fee_obligation_id)->firstOrFail();
            $plan = ThriftPlan::query()->whereKey($issue->thrift_plan_id)->firstOrFail();
            $terms = $plan->termsRevisions()->where('fee_snapshot_id', $issue->fee_snapshot_id)->orderBy('revision')->first();
            $entry = $fee->entries()->whereKey($context['assessment_entry_id'])->first();
            if ($terms === null || ! app(PlanFeeSnapshotBinding::class)->isValid($plan, $terms, $fee->feeSnapshot)
                || $fee->fee_snapshot_id !== $issue->fee_snapshot_id || $fee->customer_profile_id !== $issue->customer_profile_id
                || $fee->source_type !== $fee->feeSnapshot->source_type || $fee->source_id !== $fee->feeSnapshot->source_id
                || $entry === null || $entry->entry_type !== FeeObligationEntryType::Assessment || $entry->source_type !== $fee->source_type
                || $entry->source_id !== $fee->source_id || $entry->amount_kobo !== $fee->amount_kobo
                || $receipt->customer_profile_id !== $issue->customer_profile_id || $receipt->thrift_plan_id !== $plan->id
                || $receipt->attempt_reference !== $issue->operation_reference || $receipt->savings_posting_group_id !== $context['posting_group_id']
                || $receipt->timezone !== $issue->timezone || $audit->event_type !== 'collection.receipt_posted'
                || $audit->target_type !== CollectionReceipt::class || $audit->target_id !== $receipt->id
                || $audit->target_reference !== $receipt->receipt_reference || $audit->actor_id !== $issue->actor_user_id
                || ($audit->payload['customer_profile_id'] ?? null) !== $issue->customer_profile_id) {
                throw new InvalidArgumentException('Fee issue original trigger source changed.');
            }
            $prior = clone $fee;
            $prior->setRelation('entries', $fee->entries()->where('id', '<=', $context['latest_entry_id'])->get());
            if ($prior->entries->max('id') !== $context['latest_entry_id'] || $prior->outstandingAmountKobo() !== $context['outstanding_kobo']) {
                throw new InvalidArgumentException('Fee issue original assessment history changed.');
            }
        } else {
            $definition = NotificationCatalogue::OWNERS[$issue->source_family] ?? null;
            $owner = $definition === null ? null : DB::table($definition['table'])->where('id', $issue->source_owner_id)->first();
            if ($owner === null || $owner->notification_id !== $issue->operation_reference || $owner->channel !== $context['channel']) {
                throw new InvalidArgumentException('Fee issue original delivery owner changed.');
            }
            $descriptor = app(NotificationCatalogue::class)->describe($issue->source_family, $owner);
            if ($descriptor['source_id'] !== $context['original_source_id'] || $descriptor['audit_event_id'] !== $context['original_source_audit_event_id']
                || $descriptor['customer_profile_id'] !== $issue->customer_profile_id
                || ($issue->source_family !== 'collection' && ($audit->payload['source_audit_event_id'] ?? null) !== $context['original_source_audit_event_id'])
                || ($audit->payload['notification_reference'] ?? null) !== $owner->notification_id
                || ($audit->payload['channel'] ?? null) !== $context['channel']
                || ($context['channel'] === 'mail' && $issue->source_family !== 'collection' ? ($audit->payload['outcome'] ?? null) !== 'acceptance_unknown'
                    : ($audit->payload['category'] ?? null) !== $issue->category)) {
                throw new InvalidArgumentException('Fee issue original delivery evidence changed.');
            }
        }
    }

    /** @param array<string, mixed> $facts */
    private function fingerprint(array $facts): string
    {
        $identity = [$facts['issue_kind'], $facts['source_identity'], $facts['state'], $facts['category']];
        if ($facts['issue_kind'] === 'trigger_unapplied') {
            $identity = [...$identity, $facts['latest_entry_id'], $facts['outstanding_kobo'], $facts['available_kobo'], $facts['assignment_id'], $facts['agent_profile_id']];
        }

        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }
}
