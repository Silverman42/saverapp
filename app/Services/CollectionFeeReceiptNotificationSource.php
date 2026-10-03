<?php

namespace App\Services;

use App\Enums\FeeLedgerPostingType;
use App\Enums\FeeObligationEntryType;
use App\Enums\LedgerAccountCode;
use App\Models\AuditEvent;
use App\Models\CollectionReceipt;
use App\Models\FeeObligation;
use App\Support\MoneyFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

class CollectionFeeReceiptNotificationSource
{
    /** @return array<string, mixed> */
    public function captureContext(stdClass $receipt, AuditEvent $audit): array
    {
        $assignment = DB::table('customer_assignments')->where('id', $receipt->assignment_id)->firstOrFail();
        if ((int) $assignment->customer_profile_id !== (int) $receipt->customer_profile_id) {
            throw new InvalidArgumentException('The original fee receipt assignment is unavailable.');
        }
        $context = ['schema_version' => 1, 'collection_receipt_id' => (int) $receipt->id,
            'customer_profile_id' => (int) $receipt->customer_profile_id, 'receipt_reference' => $receipt->receipt_reference,
            'operation_reference' => $receipt->attempt_reference, 'audit_event_id' => $audit->id,
            'recorded_by_user_id' => (int) $receipt->recorded_by_user_id, 'assignment_id' => (int) $receipt->assignment_id,
            'agent_profile_id' => (int) $assignment->agent_profile_id, 'custodian_agent_profile_id' => (int) $receipt->recording_agent_profile_id,
            'timezone' => $receipt->timezone, 'received_date' => $receipt->received_date,
            'savings_amount_kobo' => (int) $receipt->savings_amount_kobo, 'fee_amount_kobo' => (int) $receipt->fee_amount_kobo,
            'tender_amount_kobo' => (int) $receipt->tender_amount_kobo,
            'replacement_reversal_id' => $receipt->replacement_reversal_id === null ? null : (int) $receipt->replacement_reversal_id,
            'fees' => []];
        foreach (DB::table('collection_fee_components')->where('collection_receipt_id', $receipt->id)->orderBy('id')->get() as $component) {
            $fee = FeeObligation::query()->whereKey($component->fee_obligation_id)->lockForUpdate()->firstOrFail();
            $fee->setRelation('entries', $fee->entries()->lockForUpdate()->get());
            $group = DB::table('ledger_posting_groups')->where('id', $component->ledger_posting_group_id)->firstOrFail();
            $settlements = $fee->entries->where('entry_type', FeeObligationEntryType::Settlement)
                ->where('ledger_posting_reference', $group->posting_reference);
            $settlement = $settlements->first();
            if ($settlement === null || $settlements->count() !== 1) {
                throw new InvalidArgumentException('The original fee receipt settlement is unavailable.');
            }
            $context['fees'][] = ['component_id' => (int) $component->id, 'fee_obligation_id' => $fee->id,
                'fee_snapshot_id' => $fee->fee_snapshot_id, 'ledger_posting_group_id' => (int) $component->ledger_posting_group_id,
                'settlement_entry_id' => $settlement->id, 'latest_entry_id' => $fee->entries->max('id'),
                'amount_kobo' => (int) $component->amount_kobo, 'remaining_kobo' => $fee->outstandingAmountKobo(),
                'customer_description' => $fee->customer_description];
        }
        $this->validateContext($receipt, $context);

        return $context;
    }

    /** @return array<string, mixed> */
    public function describe(stdClass $owner, stdClass $receipt): array
    {
        $context = $this->context($owner, $receipt);
        $facts = ['savings_amount_kobo' => (int) $receipt->savings_amount_kobo, 'fee_amount_kobo' => (int) $receipt->fee_amount_kobo,
            'tender_amount_kobo' => (int) $receipt->tender_amount_kobo];
        if (min($facts) < 0 || $facts['tender_amount_kobo'] < 1
            || $facts['tender_amount_kobo'] !== $facts['savings_amount_kobo'] + $facts['fee_amount_kobo']) {
            throw new InvalidArgumentException('The original receipt amounts are unavailable.');
        }
        $summary = 'Savings: '.MoneyFormatter::formatNaira($facts['savings_amount_kobo'])
            .'; external fee: '.MoneyFormatter::formatNaira($facts['fee_amount_kobo'])
            .'; total tender: '.MoneyFormatter::formatNaira($facts['tender_amount_kobo']).'.';
        foreach ($context['fees'] ?? [] as $fee) {
            $summary .= ' '.$fee['customer_description'].' Allocated fee '.MoneyFormatter::formatNaira($fee['amount_kobo'])
                .'; remaining obligation '.MoneyFormatter::formatNaira($fee['remaining_kobo']).'.';
        }

        return ['source_id' => (int) $receipt->id, 'source_version' => 1, 'event_type' => 'collection', 'facts' => $facts,
            'audit_event_id' => $context['audit_event_id'] ?? null, 'effective_at' => CarbonImmutable::parse($receipt->recorded_at, 'UTC'),
            'timezone' => $receipt->timezone, 'operation_reference' => $receipt->attempt_reference,
            'actor_category' => DB::table('users')->where('id', $receipt->recorded_by_user_id)->value('user_type') ?? 'system',
            'audience' => $owner->audience_type ?? 'subject_customer', 'customer_profile_id' => (int) $receipt->customer_profile_id,
            'agent_profile_id' => $owner->agent_profile_id ?? null, 'action_correction_id' => null, 'action_required' => false,
            'category' => 'financial', 'title' => 'Collection recorded', 'summary' => $summary,
            'reference' => $receipt->receipt_reference, 'destination' => ['route' => 'collections.show', 'parameters' => [$receipt->receipt_reference]]];
    }

    /** @return array<string, mixed>|null */
    public function mailContext(stdClass $owner, CollectionReceipt $receipt): ?array
    {
        if ($owner->channel !== 'mail' || ($owner->audience_type ?? 'subject_customer') !== 'subject_customer') {
            throw new InvalidArgumentException('Only the original Customer can receive receipt email.');
        }
        $source = DB::table('collection_receipts')->where('id', $receipt->id)->firstOrFail();

        return $this->context($owner, $source);
    }

    /** @return array<string, mixed>|null */
    private function context(stdClass $owner, stdClass $receipt): ?array
    {
        $customer = DB::table('customer_profiles')->where('id', $receipt->customer_profile_id)->firstOrFail();
        $audience = $owner->audience_type ?? 'subject_customer';
        if ((int) $owner->collection_receipt_id !== (int) $receipt->id
            || (($owner->customer_profile_id ?? null) !== null && (int) $owner->customer_profile_id !== (int) $receipt->customer_profile_id)) {
            throw new InvalidArgumentException('Receipt notice Customer source changed.');
        }
        if ($audience === 'subject_customer') {
            if ((int) $owner->recipient_user_id !== (int) $customer->user_id || ($owner->agent_profile_id ?? null) !== null || ($owner->assignment_id ?? null) !== null) {
                throw new InvalidArgumentException('Receipt notice original Customer changed.');
            }
        } elseif ($audience !== 'current_agent' || $owner->channel !== 'database') {
            throw new InvalidArgumentException('Receipt notice audience is unavailable.');
        }
        if (($owner->context_ciphertext ?? null) === null) {
            if ($audience !== 'subject_customer') {
                throw new InvalidArgumentException('Agent receipt notice has no captured source.');
            }

            return null;
        }
        $context = json_decode(Crypt::decryptString($owner->context_ciphertext), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($context)) {
            throw new InvalidArgumentException('Receipt notice original context is unavailable.');
        }
        $this->validateContext($receipt, $context);
        if ($audience === 'current_agent' && ((int) $owner->assignment_id !== $context['assignment_id']
            || (int) $owner->agent_profile_id !== $context['agent_profile_id']
            || ! DB::table('agent_profiles')->where('id', $context['agent_profile_id'])->where('user_id', $owner->recipient_user_id)->exists())) {
            throw new InvalidArgumentException('Receipt notice original Agent changed.');
        }

        return $context;
    }

    /** @param array<string, mixed> $context */
    private function validateContext(stdClass $receipt, array $context): void
    {
        if (($context['schema_version'] ?? null) !== 1 || ($context['collection_receipt_id'] ?? null) !== (int) $receipt->id
            || ($context['customer_profile_id'] ?? null) !== (int) $receipt->customer_profile_id
            || ($context['receipt_reference'] ?? null) !== $receipt->receipt_reference
            || ($context['operation_reference'] ?? null) !== $receipt->attempt_reference
            || ($context['recorded_by_user_id'] ?? null) !== (int) $receipt->recorded_by_user_id
            || ($context['custodian_agent_profile_id'] ?? null) !== (int) $receipt->recording_agent_profile_id
            || ($context['assignment_id'] ?? null) !== (int) $receipt->assignment_id
            || ($context['timezone'] ?? null) !== $receipt->timezone || ($context['received_date'] ?? null) !== $receipt->received_date
            || ($context['replacement_reversal_id'] ?? null) !== ($receipt->replacement_reversal_id === null ? null : (int) $receipt->replacement_reversal_id)
            || ($context['savings_amount_kobo'] ?? null) !== (int) $receipt->savings_amount_kobo
            || ($context['fee_amount_kobo'] ?? null) !== (int) $receipt->fee_amount_kobo
            || ($context['tender_amount_kobo'] ?? null) !== (int) $receipt->tender_amount_kobo
            || (int) $receipt->fee_amount_kobo < 1 || ! is_array($context['fees'] ?? null)) {
            throw new InvalidArgumentException('Receipt notice captured source changed.');
        }
        $assignment = DB::table('customer_assignments')->where('id', $receipt->assignment_id)->first();
        $agent = $assignment === null ? null : DB::table('agent_profiles')->where('id', $assignment->agent_profile_id)->first();
        $audit = AuditEvent::query()->whereKey($context['audit_event_id'] ?? null)->first();
        if ($assignment === null || $agent === null || (int) $assignment->customer_profile_id !== (int) $receipt->customer_profile_id
            || (int) $agent->id !== ($context['agent_profile_id'] ?? null) || (int) $agent->user_id !== (int) $receipt->recorded_by_user_id
            || $audit === null || $audit->event_type !== 'collection.receipt_posted' || $audit->target_type !== CollectionReceipt::class
            || $audit->target_id !== (int) $receipt->id || $audit->target_reference !== $receipt->receipt_reference
            || $audit->actor_id !== (int) $receipt->recorded_by_user_id
            || ($audit->payload['customer_profile_id'] ?? null) !== (int) $receipt->customer_profile_id
            || ($audit->payload['fee_kobo'] ?? null) !== (int) $receipt->fee_amount_kobo
            || ($audit->payload['savings_kobo'] ?? null) !== (int) $receipt->savings_amount_kobo
            || ($audit->payload['tender_kobo'] ?? null) !== (int) $receipt->tender_amount_kobo
            || ($audit->payload['assignment_id'] ?? null) !== (int) $receipt->assignment_id) {
            throw new InvalidArgumentException('Receipt notice original audit or Agent is unavailable.');
        }
        $components = DB::table('collection_fee_components')->where('collection_receipt_id', $receipt->id)->orderBy('id')->get();
        if ($components->count() !== count($context['fees']) || $components->sum('amount_kobo') !== (int) $receipt->fee_amount_kobo) {
            throw new InvalidArgumentException('Receipt notice original fee components are unavailable.');
        }
        foreach ($components as $index => $component) {
            $captured = $context['fees'][$index];
            if (! is_array($captured) || ($captured['component_id'] ?? null) !== (int) $component->id
                || ($captured['fee_obligation_id'] ?? null) !== (int) $component->fee_obligation_id
                || ($captured['ledger_posting_group_id'] ?? null) !== (int) $component->ledger_posting_group_id
                || ($captured['amount_kobo'] ?? null) !== (int) $component->amount_kobo || (int) $component->amount_kobo < 1) {
                throw new InvalidArgumentException('Receipt notice original fee allocation changed.');
            }
            $this->validateFee($receipt, $component, $captured);
        }
    }

    /** @param array<string, mixed> $captured */
    private function validateFee(stdClass $receipt, stdClass $component, array $captured): void
    {
        $fee = FeeObligation::query()->whereKey($component->fee_obligation_id)->firstOrFail();
        $snapshot = $fee->feeSnapshot;
        $fee->setRelation('entries', $fee->entries()->where('id', '<=', $captured['latest_entry_id'] ?? null)->get());
        $assessments = $fee->entries->where('entry_type', FeeObligationEntryType::Assessment);
        $assessment = $assessments->first();
        if ($assessment === null || $assessments->count() !== 1) {
            throw new InvalidArgumentException('Receipt notice original fee assessment history is unavailable.');
        }
        $settlement = $fee->entries->where('id', $captured['settlement_entry_id'] ?? null)->first();
        $group = DB::table('ledger_posting_groups')->where('id', $component->ledger_posting_group_id)->first();
        $expectedType = $receipt->replacement_reversal_id === null ? FeeLedgerPostingType::ExternalFeeReceipt : FeeLedgerPostingType::UnappliedFeeApplication;
        $sourceId = $receipt->id.'-'.$fee->id;
        $expectedPlanId = $receipt->thrift_plan_id ?? app(LedgerPostingService::class)->planForObligation($fee);
        if ($fee->customer_profile_id !== (int) $receipt->customer_profile_id || $snapshot->customer_profile_id !== $fee->customer_profile_id
            || $fee->fee_snapshot_id !== ($captured['fee_snapshot_id'] ?? null) || $fee->source_type !== $snapshot->source_type || $fee->source_id !== $snapshot->source_id
            || $fee->currency !== 'NGN' || $assessment->source_type !== $fee->source_type || $assessment->source_id !== $fee->source_id
            || $assessment->amount_kobo !== $fee->amount_kobo || $assessment->currency !== 'NGN'
            || $fee->entries->max('id') !== ($captured['latest_entry_id'] ?? null)
            || $fee->outstandingAmountKobo() !== ($captured['remaining_kobo'] ?? null)
            || $fee->customer_description !== ($captured['customer_description'] ?? null)
            || $group === null || $group->currency !== 'NGN' || $group->event_type !== $expectedType->value
            || $group->source_type !== 'collection_receipt' || $group->source_id !== $sourceId || $group->committed_at === null
            || (int) $group->customer_profile_id !== (int) $receipt->customer_profile_id || (int) $group->actor_user_id !== (int) $receipt->recorded_by_user_id
            || $group->thrift_plan_id !== $expectedPlanId || $group->business_timezone !== $receipt->timezone
            || $settlement === null || $settlement->entry_type !== FeeObligationEntryType::Settlement
            || $settlement->amount_kobo !== (int) $component->amount_kobo || $settlement->currency !== 'NGN'
            || $settlement->source_type !== 'collection_receipt' || $settlement->source_id !== $sourceId
            || $settlement->actor_user_id !== (int) $receipt->recorded_by_user_id || $settlement->ledger_posting_reference !== $group->posting_reference) {
            throw new InvalidArgumentException('Receipt notice original fee settlement history changed.');
        }
        $lines = DB::table('ledger_entries as line')->join('ledger_accounts as account', 'account.id', '=', 'line.ledger_account_id')
            ->where('line.ledger_posting_group_id', $group->id)->orderBy('line.line_number')->get(['line.*', 'account.code']);
        $debit = $receipt->replacement_reversal_id === null ? $receipt->custody_account_code : LedgerAccountCode::UnappliedFunds->value;
        if ($lines->count() !== 2 || $lines[0]->code !== $debit || $lines[0]->side !== 'debit'
            || $lines[1]->code !== LedgerAccountCode::FeeIncome->value || $lines[1]->side !== 'credit') {
            throw new InvalidArgumentException('Receipt notice original fee journal is unavailable.');
        }
        foreach ($lines as $index => $line) {
            $agentId = $index === 0 && $debit === LedgerAccountCode::AgentReceivable->value ? (int) $receipt->recording_agent_profile_id : null;
            if ((int) $line->amount_kobo !== (int) $component->amount_kobo || (int) $line->customer_profile_id !== (int) $receipt->customer_profile_id
                || (int) $line->fee_obligation_id !== $fee->id || $line->thrift_plan_id !== null || $line->agent_profile_id !== $agentId) {
                throw new InvalidArgumentException('Receipt notice original fee journal dimensions changed.');
            }
        }
    }
}
