<?php

namespace App\Services;

use App\Jobs\DeliverChargeNotificationIntent;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\ManualCharge;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ManualChargeNotice
{
    /** @param array<string, int>|null $originalPosition */
    public function capture(ManualCharge $charge, ?array $originalPosition = null): void
    {
        $customer = CustomerProfile::query()->with('currentAssignment.agentProfile.user')->findOrFail($charge->customer_profile_id);
        $audit = AuditEvent::query()->where('event_type', 'charge.assessed')->where('target_type', ManualCharge::class)
            ->where('target_id', $charge->id)->where('target_reference', $charge->operation_reference)->sole();
        $outstanding = $charge->fee_obligation_id === null ? 0 : FeeObligation::query()->findOrFail($charge->fee_obligation_id)->outstandingAmountKobo();
        $assignment = $customer->currentAssignment;
        $agent = $assignment?->agentProfile;
        $context = ['assignment_id' => $assignment?->id, 'agent_profile_id' => $agent?->id, 'schema_version' => 1, 'timezone' => BusinessProfile::current()->timezone, 'manual_charge_id' => $charge->id, 'category_version_id' => $charge->charge_category_version_id,
            'posting_group_id' => $charge->ledger_posting_group_id, 'audit_event_id' => $audit->id, 'amount_kobo' => $charge->amount_kobo,
            'cycle_liability_kobo' => $originalPosition === null ? null : $originalPosition['cycle_liability_kobo'] - $charge->amount_kobo,
            'cycle_available_kobo' => $originalPosition === null ? null : $originalPosition['cycle_available_kobo'] - $charge->amount_kobo,
            'outstanding_fee_kobo' => $outstanding];
        $payload = json_encode(['context_ciphertext' => Crypt::encryptString(json_encode($context, JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR);
        $recipients = [['user_id' => $customer->user_id, 'audience' => 'subject_customer', 'agent_id' => null, 'assignment_id' => null]];
        if ($agent !== null && app(AgentEligibilityService::class)->canReadAssignedCustomers($agent->user)) {
            $recipients[] = ['user_id' => $agent->user_id, 'audience' => 'current_agent', 'agent_id' => $agent->id, 'assignment_id' => $assignment->id];
        }
        foreach ($recipients as $recipient) {
            $id = $this->retain($charge, $recipient, 'database', $payload);
            app(NotificationPipeline::class)->capture('charge', $id);
        }
        if ($charge->ledger_posting_group_id !== null && $originalPosition !== null) {
            $mailId = $this->retain($charge, $recipients[0], 'mail', $payload);
            app(ManagementMailDelivery::class)->register('charge', $mailId);
            DB::afterCommit(static fn () => app(NotificationPipeline::class)->dispatchRecoverably(
                static fn () => DeliverChargeNotificationIntent::dispatch($mailId)->afterCommit()));
        }
    }

    /** @param array{user_id: int, audience: string, agent_id: int|null, assignment_id: int|null} $recipient */
    private function retain(ManualCharge $charge, array $recipient, string $channel, string $payload): int
    {
        DB::table('manual_charge_notification_intents')->insertOrIgnore([
            'notification_id' => (string) Str::uuid(), 'manual_charge_id' => $charge->id,
            'customer_profile_id' => $charge->customer_profile_id, 'recipient_user_id' => $recipient['user_id'],
            'agent_profile_id' => $recipient['agent_id'], 'assignment_id' => $recipient['assignment_id'],
            'audience_type' => $recipient['audience'], 'channel' => $channel, 'payload' => $payload,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('manual_charge_notification_intents')->where('manual_charge_id', $charge->id)
            ->where('recipient_user_id', $recipient['user_id'])->where('channel', $channel)->sole()->id;
    }
}
