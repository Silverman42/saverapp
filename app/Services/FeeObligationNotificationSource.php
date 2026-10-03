<?php

namespace App\Services;

use App\Enums\FeeObligationEntryType;
use App\Models\FeeObligation;
use App\Support\MoneyFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

class FeeObligationNotificationSource
{
    /** @return array<string, mixed> */
    public function describe(stdClass $owner, stdClass $event): array
    {
        $entry = DB::table('fee_obligation_entries')->where('id', $event->fee_obligation_entry_id)->first();
        $fee = DB::table('fee_obligations')->where('id', $event->fee_obligation_id)->first();
        $customer = DB::table('customer_profiles')->where('id', $event->customer_profile_id)->first();
        $audit = DB::table('audit_events')->where('id', $event->audit_event_id)->first();
        $type = FeeObligationEntryType::tryFrom($event->entry_type);
        if ($entry === null || $fee === null || $customer === null || $audit === null
            || ! in_array($type, [FeeObligationEntryType::Waiver, FeeObligationEntryType::AssessmentCorrection, FeeObligationEntryType::AssessmentCorrectionIncrease], true)) {
            throw new InvalidArgumentException('Fee obligation notice original source is unavailable.');
        }
        $amount = $this->integer($event->amount_kobo);
        $before = $this->integer($event->outstanding_before_kobo);
        $after = $this->integer($event->outstanding_after_kobo);
        $isWaiver = $type === FeeObligationEntryType::Waiver;
        $sourceType = $isWaiver ? 'admin_waiver' : 'admin_assessment_correction';
        $auditType = $isWaiver ? 'fee.obligation.waived' : 'fee.assessment.corrected';
        $eventType = $isWaiver ? 'waived' : 'assessment_corrected';
        if ($this->integer($event->version) !== 1 || $event->event_type !== $eventType || $amount < 1
            || $this->integer($entry->fee_obligation_id) !== $this->integer($fee->id)
            || $this->integer($fee->customer_profile_id) !== $this->integer($customer->id)
            || $this->integer($entry->actor_user_id) !== $this->integer($event->actor_user_id)
            || $entry->entry_type !== $type->value || $entry->source_type !== $sourceType
            || $entry->source_id !== $event->operation_reference || $entry->idempotency_key !== 'fee-admin-'.$event->operation_reference
            || $entry->ledger_posting_reference !== null || $this->integer($entry->amount_kobo) !== $amount
            || $entry->currency !== $event->currency || $fee->currency !== $event->currency
            || $entry->customer_description !== $event->customer_description
            || $entry->created_at !== $event->effective_at
            || $this->integer($owner->fee_obligation_event_id) !== $this->integer($event->id)
            || $this->integer($owner->customer_profile_id) !== $this->integer($customer->id)
            || $owner->channel !== 'database') {
            throw new InvalidArgumentException('Fee obligation notice original source identity changed.');
        }
        if (($type === FeeObligationEntryType::AssessmentCorrectionIncrease
                && ($amount > PHP_INT_MAX - $before || $after !== $before + $amount))
            || ($type !== FeeObligationEntryType::AssessmentCorrectionIncrease && ($amount > $before || $after !== $before - $amount))) {
            throw new InvalidArgumentException('Fee obligation notice retained outcome is inconsistent.');
        }
        $payload = json_decode($audit->payload, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($payload) || $audit->event_type !== $auditType || $audit->target_type !== FeeObligation::class
            || $this->integer($audit->target_id) !== $this->integer($fee->id) || $audit->target_reference !== (string) $fee->id
            || $this->integer($audit->actor_id) !== $this->integer($event->actor_user_id)
            || ($payload['attempt_reference'] ?? null) !== $event->operation_reference
            || ($payload['entry_type'] ?? null) !== $type->value || ($payload['amount_kobo'] ?? null) !== $amount
            || ($payload['currency'] ?? null) !== $event->currency
            || ($payload['customer_profile_id'] ?? null) !== $this->integer($customer->id)
            || ($payload['outstanding_before_kobo'] ?? null) !== $before || ($payload['outstanding_after_kobo'] ?? null) !== $after) {
            throw new InvalidArgumentException('Fee obligation notice has no matching original outcome audit.');
        }
        $audience = $owner->audience_type;
        if ($audience === 'subject_customer') {
            if ($this->integer($owner->recipient_user_id) !== $this->integer($customer->user_id)
                || $owner->agent_profile_id !== null || $owner->assignment_id !== null) {
                throw new InvalidArgumentException('Fee obligation notice has no original Customer recipient.');
            }
        } elseif ($audience === 'current_agent') {
            if ($owner->assignment_id === null || $owner->agent_profile_id === null
                || $event->assignment_id === null || $event->agent_profile_id === null
                || $this->integer($owner->assignment_id) !== $this->integer($event->assignment_id)
                || $this->integer($owner->agent_profile_id) !== $this->integer($event->agent_profile_id)
                || ! DB::table('customer_assignments as assignment')->join('agent_profiles as agent', 'agent.id', '=', 'assignment.agent_profile_id')
                    ->where('assignment.id', $owner->assignment_id)->where('assignment.customer_profile_id', $customer->id)
                    ->where('agent.id', $owner->agent_profile_id)->where('agent.user_id', $owner->recipient_user_id)
                    ->where('assignment.effective_at', '<=', $event->effective_at)
                    ->where(fn ($query) => $query->whereNull('assignment.ended_at')->orWhere('assignment.ended_at', '>=', $event->effective_at))->exists()) {
                throw new InvalidArgumentException('Fee obligation notice has no original Agent assignment.');
            }
        } elseif ($audience === 'fee_manager') {
            if ($this->integer($owner->recipient_user_id) !== $this->integer($event->actor_user_id)
                || $owner->assignment_id !== null || $owner->agent_profile_id !== null) {
                throw new InvalidArgumentException('Fee obligation notice has no original fee operator.');
            }
        } else {
            throw new InvalidArgumentException('Fee obligation notice audience is unsupported.');
        }
        $title = $isWaiver ? 'Fee waived' : 'Fee assessment corrected';
        $movement = match ($type) {
            FeeObligationEntryType::Waiver => 'waived',
            FeeObligationEntryType::AssessmentCorrectionIncrease => 'added to the unpaid assessment',
            default => 'removed from the unpaid assessment',
        };

        return ['source_id' => $this->integer($event->id), 'source_version' => 1, 'event_type' => $eventType,
            'facts' => [], 'audit_event_id' => $this->integer($audit->id),
            'effective_at' => CarbonImmutable::parse($event->effective_at, 'UTC'), 'timezone' => $event->timezone,
            'operation_reference' => $event->operation_reference, 'actor_category' => $audit->actor_type,
            'customer_profile_id' => $this->integer($customer->id),
            'agent_profile_id' => $owner->agent_profile_id === null ? null : $this->integer($owner->agent_profile_id),
            'audience' => $audience, 'category' => 'financial', 'title' => $title,
            'summary' => $event->customer_description.' '.MoneyFormatter::formatNaira($amount).' was '.$movement
                .'. At this action: unpaid fee changed from '.MoneyFormatter::formatNaira($before).' to '.MoneyFormatter::formatNaira($after).'. No money was moved.',
            'reference' => $event->operation_reference, 'destination' => ['route' => 'customers.show', 'parameters' => [$customer->customer_id]],
            'action_required' => false, 'action_correction_id' => null];
    }

    private function integer(mixed $value): int
    {
        if ((! is_int($value) && ! is_string($value)) || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0) {
            throw new InvalidArgumentException('Fee obligation notice retained integer is invalid.');
        }

        return (int) $value;
    }
}
