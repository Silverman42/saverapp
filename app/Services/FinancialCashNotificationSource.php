<?php

namespace App\Services;

use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CashDisbursement;
use App\Models\CashRecovery;
use App\Models\CustomerProfile;
use App\Models\FeeRefund;
use App\Models\LedgerPostingGroup;
use App\Support\MoneyFormatter;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

class FinancialCashNotificationSource
{
    /** @return array<string, mixed> */
    public function describe(stdClass $owner, stdClass $event): array
    {
        if (! in_array($event->event_type, ['refund_authorized', 'started', 'handoff_recorded', 'not_delivered', 'posted', 'recovery_recorded', 'recovery_confirmed'], true)
            || ! in_array($event->kind, ['fee_refund', 'earnings_draw'], true)
            || (int) $event->amount_kobo < 1 || $owner->customer_profile_id !== $event->customer_profile_id) {
            throw new InvalidArgumentException('Financial cash notification source is unavailable.');
        }
        $refund = $event->fee_refund_id === null ? null : FeeRefund::query()->whereKey($event->fee_refund_id)->first();
        $execution = $event->cash_disbursement_id === null ? null : CashDisbursement::query()->whereKey($event->cash_disbursement_id)->first();
        $customer = $event->customer_profile_id === null ? null : CustomerProfile::query()->whereKey($event->customer_profile_id)->first();
        $refundPosting = null;
        if ($event->kind === 'fee_refund') {
            if ($refund === null || $customer === null || $refund->customer_profile_id !== $customer->id) {
                throw new InvalidArgumentException('The original refund Customer is unavailable.');
            }
            $refundPosting = $this->refundPosting($refund);
        } elseif ($refund !== null || $customer !== null || $execution === null || $execution->kind !== 'earnings_draw') {
            throw new InvalidArgumentException('The original business draw is unavailable.');
        }
        $auditType = 'cash_disbursement.'.$event->event_type;
        $auditTarget = $execution;
        if ($event->event_type === 'refund_authorized') {
            if ($refund === null || $execution !== null || $event->operation_reference !== $refund->refund_reference
                || (int) $event->amount_kobo !== $refund->amount_kobo || (int) $event->actor_user_id !== $refund->actor_user_id) {
                throw new InvalidArgumentException('The original refund authorization is unavailable.');
            }
            $auditType = 'fee.refund_authorized';
            $auditTarget = $refund;
        } else {
            if ($execution === null || $execution->kind !== $event->kind || $execution->customer_profile_id !== $event->customer_profile_id
                || $execution->fee_refund_id !== $event->fee_refund_id || ($refund !== null && ($refund->kind !== 'external' || $execution->amount_kobo !== $refund->amount_kobo))) {
                throw new InvalidArgumentException('The original cash execution is unavailable.');
            }
            if (str_starts_with($event->event_type, 'recovery_')) {
                $recovery = CashRecovery::query()->where('recovery_reference', $event->operation_reference)->first();
                if ($recovery === null || $recovery->cash_disbursement_id !== $execution->id
                    || $recovery->amount_kobo !== (int) $event->amount_kobo || $recovery->recipient_user_id !== $execution->recipient_user_id
                    || $recovery->custodian_user_id !== $execution->executor_user_id) {
                    throw new InvalidArgumentException('The original cash recovery is unavailable.');
                }
                if ($event->event_type === 'recovery_confirmed') {
                    app(CashRecoveryLedger::class)->assertReturnPosting($execution, $recovery);
                }
                $auditTarget = $recovery;
            } elseif ($event->operation_reference !== $execution->execution_reference || (int) $event->amount_kobo !== $execution->amount_kobo) {
                throw new InvalidArgumentException('Cash notification identity changed.');
            }
            if ($event->event_type === 'posted') {
                $this->paymentPosting($execution);
            }
        }
        $audit = AuditEvent::query()->where('event_type', $auditType)->where('target_type', $auditTarget::class)
            ->where('target_id', $auditTarget->id)->where('target_reference', $event->operation_reference)
            ->where('actor_id', $event->actor_user_id)->first();
        if ($audit === null || (($event->audit_event_id ?? null) !== null && (int) $event->audit_event_id !== $audit->id)) {
            throw new InvalidArgumentException('The original financial cash audit is unavailable.');
        }
        $auditAmount = $audit->payload['amount_kobo'] ?? $audit->payload['returned_kobo'] ?? null;
        if ($auditAmount !== (int) $event->amount_kobo || ($audit->payload['customer_profile_id'] ?? null) !== $event->customer_profile_id) {
            throw new InvalidArgumentException('Financial cash audit dimensions changed.');
        }
        $audience = $owner->audience_type;
        $recipient = (int) $owner->recipient_user_id;
        $agentId = $owner->agent_profile_id ?? null;
        $assignmentId = $owner->assignment_id ?? null;
        $validAudience = match ($audience) {
            'subject_customer' => $customer !== null && $recipient === $customer->user_id && $agentId === null && $assignmentId === null,
            'fee_manager' => $refund !== null && $refund->compensation_posting_group_id === null && $recipient === $refund->actor_user_id && $agentId === null && $assignmentId === null,
            'refund_correction_operator' => $refund !== null && $refund->compensation_posting_group_id !== null
                && $recipient === $refund->actor_user_id && $agentId === null && $assignmentId === null
                && DB::table('canonical_audit_events as canonical')->join('audit_events as authorization', 'authorization.id', '=', 'canonical.legacy_audit_event_id')
                    ->where('authorization.event_type', 'fee.refund_authorized')->where('authorization.target_type', FeeRefund::class)
                    ->where('authorization.target_id', $refund->id)->where('authorization.actor_id', $refund->actor_user_id)
                    ->where('canonical.required_permission', 'reversals.review')->exists(),
            'refund_cash_operator' => $refund !== null && $execution !== null && $recipient === $execution->executor_user_id && $agentId === null && $assignmentId === null,
            'cash_executor' => $customer === null && $recipient === $execution->recipient_user_id && $agentId === null && $assignmentId === null,
            'current_agent' => $customer !== null && $agentId !== null && $assignmentId !== null
                && (($event->audit_event_id ?? null) === null || ((int) $agentId === (int) ($event->agent_profile_id ?? 0) && (int) $assignmentId === (int) ($event->assignment_id ?? 0)))
                && DB::table('customer_assignments as assignment')->join('agent_profiles as agent', 'agent.id', '=', 'assignment.agent_profile_id')
                    ->where('assignment.id', $assignmentId)->where('assignment.customer_profile_id', $customer->id)
                    ->where('agent.id', $agentId)->where('agent.user_id', $recipient)
                    ->where('assignment.effective_at', '<=', $event->created_at)
                    ->where(fn ($query) => $query->whereNull('assignment.ended_at')->orWhere('assignment.ended_at', '>=', $event->created_at))->exists(),
            default => false,
        };
        if (! $validAudience || ! in_array($owner->channel, ['database', 'mail'], true)
            || ($owner->channel === 'mail' && ($audience !== 'subject_customer' || ! in_array($event->event_type, ['refund_authorized', 'posted'], true)))) {
            throw new InvalidArgumentException('The original financial cash audience is unavailable.');
        }
        $summary = match ($event->event_type) {
            'refund_authorized' => $refund->kind === 'savings' ? 'The approved fee refund restored your savings.' : 'An external fee refund is payable. Cash has not yet been paid.',
            'started' => 'Cash was reserved for an evidenced payment attempt. Payment is not yet confirmed.',
            'handoff_recorded' => 'Cash handoff was recorded. Confirm the exact amount personally received; payment is not yet confirmed.',
            'not_delivered' => 'Definitive non-delivery was recorded. The payment attempt failed.',
            'posted' => $event->kind === 'fee_refund' ? 'Your external fee refund was paid after authenticated receipt confirmation.' : 'Authenticated receipt was confirmed and the business draw posted.',
            'recovery_recorded' => 'Cash recovery evidence was recorded. Confirm only the exact returned amount.',
            'recovery_confirmed' => 'Returned cash was confirmed. Unresolved amounts remain owned by the original attempt.',
        };
        $timezone = $event->timezone ?? $refundPosting->business_timezone ?? BusinessProfile::current()->timezone;
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException('Financial cash source timezone is unavailable.');
        }

        return ['title' => $event->kind === 'earnings_draw' ? 'Business earnings cash draw' : 'Fee refund updated',
            'summary' => $summary.' Amount '.MoneyFormatter::formatNaira((int) $event->amount_kobo).'.',
            'reference' => $event->operation_reference, 'actor_category' => $audit->actor_type, 'audit_event_id' => $audit->id, 'timezone' => $timezone,
            'customer_profile_id' => $event->customer_profile_id, 'agent_profile_id' => $agentId, 'audience' => $audience,
            'destination' => ['route' => $audience === 'subject_customer' || $audience === 'current_agent' ? 'customers.show' : 'cash-disbursements.index',
                'parameters' => $audience === 'subject_customer' || $audience === 'current_agent' ? [$customer->customer_id] : []]];
    }

    private function refundPosting(FeeRefund $refund): LedgerPostingGroup
    {
        $group = LedgerPostingGroup::query()->with('entries.account')->whereKey($refund->ledger_posting_group_id)->first();
        $destination = $refund->kind === 'savings' ? LedgerAccountCode::CustomerSavingsLiability : LedgerAccountCode::RefundPayable;
        if (! in_array($refund->kind, ['savings', 'external'], true) || $group === null
            || $group->source_type !== ($refund->kind === 'savings' ? 'fee_refund' : 'external_refund_entitlement')
            || $group->source_id !== $refund->refund_reference || $group->customer_profile_id !== $refund->customer_profile_id
            || $group->actor_user_id !== $refund->actor_user_id || $group->currency !== 'NGN' || $group->getRawOriginal('committed_at') === null
            || $group->entries->count() !== 2 || ! $group->entries->contains(fn ($line): bool => $line->account->code === LedgerAccountCode::FeeIncome && $line->side === LedgerEntrySide::Debit && $line->amount_kobo === $refund->amount_kobo)
            || ! $group->entries->contains(fn ($line): bool => $line->account->code === $destination && $line->side === LedgerEntrySide::Credit && $line->amount_kobo === $refund->amount_kobo)) {
            throw new InvalidArgumentException('The original refund posting is unavailable.');
        }

        return $group;
    }

    private function paymentPosting(CashDisbursement $execution): void
    {
        $group = LedgerPostingGroup::query()->with('entries.account')->whereKey($execution->ledger_posting_group_id)->first();
        $debit = $execution->kind === 'earnings_draw' ? LedgerAccountCode::BusinessDistributions : LedgerAccountCode::RefundPayable;
        if ($group === null || $group->source_type !== 'cash_disbursement' || $group->source_id !== (string) $execution->id
            || $group->currency !== 'NGN' || $group->getRawOriginal('committed_at') === null || $group->customer_profile_id !== $execution->customer_profile_id
            || ! hash_equals($group->payload_hash, $execution->payload_hash) || $group->entries->count() !== 2
            || ! $group->entries->contains(fn ($line): bool => $line->account->code === $debit && $line->side === LedgerEntrySide::Debit && $line->amount_kobo === $execution->amount_kobo)
            || ! $group->entries->contains(fn ($line): bool => $line->account->code === LedgerAccountCode::BusinessCash && $line->side === LedgerEntrySide::Credit && $line->amount_kobo === $execution->amount_kobo)) {
            throw new InvalidArgumentException('The original cash payment posting is unavailable.');
        }
    }
}
