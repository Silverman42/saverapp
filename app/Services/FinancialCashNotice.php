<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CashDisbursement;
use App\Models\CashRecovery;
use App\Models\CustomerProfile;
use App\Models\FeeRefund;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinancialCashNotice
{
    public function queue(User $actor, CashDisbursement|FeeRefund $source, string $eventType, ?string $eventReference = null, ?int $amountKobo = null): void
    {
        $disbursement = $source instanceof CashDisbursement;
        $reference = $eventReference ?? ($disbursement ? $source->execution_reference : $source->refund_reference);
        $customerId = $source->customer_profile_id;
        $customer = $customerId === null ? null : CustomerProfile::query()->whereKey($customerId)->lockForUpdate()->sole();
        $assignment = $customer?->currentAssignment()->with('agentProfile')->lockForUpdate()->first();
        $target = str_starts_with($eventType, 'recovery_') ? CashRecovery::query()->where('recovery_reference', $reference)->sole() : $source;
        $audit = AuditEvent::query()->where('event_type', ($disbursement ? 'cash_disbursement.' : 'fee.').$eventType)
            ->where('target_type', $target::class)->where('target_id', $target->id)->where('actor_id', $actor->id)->sole();
        $timezone = $source instanceof FeeRefund ? LedgerPostingGroup::query()->whereKey($source->ledger_posting_group_id)->sole()->business_timezone : BusinessProfile::current()->timezone;
        $eventId = DB::table('financial_cash_events')->insertGetId([
            'event_type' => $eventType, 'operation_reference' => $reference, 'customer_profile_id' => $customerId,
            'actor_user_id' => $actor->id, 'cash_disbursement_id' => $disbursement ? $source->id : null,
            'fee_refund_id' => $disbursement ? $source->fee_refund_id : $source->id, 'kind' => $disbursement ? $source->kind : 'fee_refund',
            'assignment_id' => $assignment?->id, 'agent_profile_id' => $assignment?->agent_profile_id,
            'amount_kobo' => $amountKobo ?? $source->amount_kobo, 'audit_event_id' => $audit->id, 'timezone' => $timezone,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($customer === null) {
            if (! $source instanceof CashDisbursement || $source->kind !== 'earnings_draw') {
                throw new \LogicException('A business cash notice requires an identified earnings draw recipient.');
            }
            $this->capture($eventId, null, $source->recipient_user_id, 'cash_executor');

            return;
        }
        $this->capture($eventId, $customerId, $customer->user_id, 'subject_customer');
        if ($assignment !== null) {
            $this->capture($eventId, $customerId, $assignment->agentProfile->user_id, 'current_agent', $assignment->agent_profile_id, $assignment->id);
        }
        $refund = $source instanceof FeeRefund ? $source : FeeRefund::query()->whereKey($source->fee_refund_id)->firstOrFail();
        $manager = User::query()->whereKey($refund->actor_user_id)->first();
        $authorization = app(AuthorizationService::class);
        $managerPermission = $refund->compensation_posting_group_id === null ? AdminPermission::FeesManage : AdminPermission::ReversalsReview;
        $managerAudience = $refund->compensation_posting_group_id === null ? 'fee_manager' : 'refund_correction_operator';
        if ($manager !== null && $authorization->allows($manager, $managerPermission)
            && (! $source instanceof CashDisbursement || $source->executor_user_id !== $manager->id)) {
            $this->capture($eventId, $customerId, $manager->id, $managerAudience);
        }
        if ($source instanceof CashDisbursement) {
            $executor = User::query()->whereKey($source->executor_user_id)->first();
            if ($executor !== null && $authorization->allows($executor, AdminPermission::CashExecute)) {
                $this->capture($eventId, $customerId, $executor->id, 'refund_cash_operator');
            }
        }
        if (in_array($eventType, ['refund_authorized', 'posted'], true)) {
            $this->capture($eventId, $customerId, $customer->user_id, 'subject_customer', channel: 'mail');
        }
    }

    private function capture(int $eventId, ?int $customerId, int $recipientId, string $audience, ?int $agentId = null, ?int $assignmentId = null, string $channel = 'database'): void
    {
        $intentId = DB::table('financial_cash_notification_intents')->insertGetId([
            'notification_id' => (string) Str::uuid(), 'financial_cash_event_id' => $eventId, 'customer_profile_id' => $customerId,
            'recipient_user_id' => $recipientId, 'audience_type' => $audience, 'agent_profile_id' => $agentId, 'assignment_id' => $assignmentId,
            'channel' => $channel, 'payload' => '{}', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($channel === 'mail') {
            app(ManagementMailDelivery::class)->register('financial_cash', $intentId);
        } else {
            app(NotificationPipeline::class)->capture('financial_cash', $intentId);
        }
    }
}
