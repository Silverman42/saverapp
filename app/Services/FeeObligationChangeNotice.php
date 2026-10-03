<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Enums\FeeObligationEntryType;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CustomerProfile;
use App\Models\FeeObligationEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class FeeObligationChangeNotice
{
    public function capture(FeeObligationEntry $entry, AuditEvent $audit, int $outstandingBefore, int $outstandingAfter): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Fee obligation notice capture requires its original financial transaction.');
        }
        $customer = CustomerProfile::query()->findOrFail($entry->obligation->customer_profile_id);
        $assignment = $customer->currentAssignment()->lockForUpdate()->first();
        $agent = $assignment?->agentProfile;
        $eventId = DB::table('fee_obligation_events')->insertGetId([
            'fee_obligation_entry_id' => $entry->id, 'fee_obligation_id' => $entry->fee_obligation_id,
            'customer_profile_id' => $customer->id, 'actor_user_id' => $entry->actor_user_id,
            'assignment_id' => $assignment?->id, 'agent_profile_id' => $agent?->id, 'audit_event_id' => $audit->id,
            'operation_reference' => $entry->source_id,
            'event_type' => $entry->entry_type === FeeObligationEntryType::Waiver ? 'waived' : 'assessment_corrected',
            'version' => 1, 'entry_type' => $entry->entry_type->value, 'amount_kobo' => $entry->amount_kobo,
            'currency' => $entry->currency, 'outstanding_before_kobo' => $outstandingBefore,
            'outstanding_after_kobo' => $outstandingAfter, 'customer_description' => $entry->customer_description,
            'timezone' => BusinessProfile::current()->timezone, 'effective_at' => $entry->created_at,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $recipients = [['user_id' => $customer->user_id, 'audience' => 'subject_customer', 'assignment_id' => null, 'agent_id' => null]];
        if ($agent !== null && app(AgentEligibilityService::class)->canReadAssignedCustomers($agent->user)) {
            $recipients[] = ['user_id' => $agent->user_id, 'audience' => 'current_agent', 'assignment_id' => $assignment->id, 'agent_id' => $agent->id];
        }
        $operator = User::query()->findOrFail($entry->actor_user_id);
        if ($operator->user_type === UserType::Admin && app(AuthorizationService::class)->allows($operator, AdminPermission::FeesManage)) {
            $recipients[] = ['user_id' => $operator->id, 'audience' => 'fee_manager', 'assignment_id' => null, 'agent_id' => null];
        }
        foreach ($recipients as $recipient) {
            $intentId = DB::table('fee_obligation_notification_intents')->insertGetId([
                'notification_id' => (string) Str::uuid(), 'fee_obligation_event_id' => $eventId,
                'customer_profile_id' => $customer->id, 'assignment_id' => $recipient['assignment_id'],
                'agent_profile_id' => $recipient['agent_id'], 'recipient_user_id' => $recipient['user_id'],
                'audience_type' => $recipient['audience'], 'channel' => 'database', 'payload' => '{}', 'status' => 'pending',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            app(NotificationPipeline::class)->capture('fee_obligation', $intentId);
        }
    }
}
