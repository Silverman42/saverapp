<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\ThriftPlan;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class FeeOperationalIssues
{
    /** @param array{source_id: int, operation_reference: string, available_kobo: int, timezone: string} $context */
    public function trigger(FeeObligation $fee, ThriftPlan $plan, User $actor, AuditEvent $sourceAudit, array $context): ?int
    {
        $this->assertTransaction();
        $facts = app(FeeOperationalIssueNotificationSource::class)->triggerFacts($fee, $plan, $actor, $sourceAudit, $context);
        if ($facts === null) {
            return null;
        }

        return $this->retain($facts);
    }

    public function delivery(string $family, int $ownerId, string $channel, string $state, string $category, int $auditId): ?int
    {
        $this->assertTransaction();
        $facts = app(FeeOperationalIssueNotificationSource::class)->deliveryFacts($family, $ownerId, $channel, $state, $category, $auditId);

        return $facts === null ? null : $this->retain($facts);
    }

    public function posting(AuditEvent $failure, FeeObligation $fee, User $actor, string $reference): ?int
    {
        $this->assertTransaction();
        $facts = app(FeePostingIssueNotificationSource::class)->facts($failure, $fee, $actor, $reference);

        return $facts === null ? null : $this->retain($facts);
    }

    /** @param array<string, mixed> $facts */
    private function retain(array $facts): int
    {
        $source = DB::table('fee_operational_issues')->where('source_identity', $facts['source_identity'])
            ->where('state_fingerprint', $facts['state_fingerprint'])->first();
        if ($source !== null) {
            return (int) $source->id;
        }
        $customer = $facts['customer_profile_id'] === null ? null : CustomerProfile::query()->whereKey($facts['customer_profile_id'])->firstOrFail();
        $assignment = $customer?->currentAssignment()->lockForUpdate()->first();
        $agent = $assignment?->agentProfile;
        $issueReference = (string) Str::uuid();
        $context = [...$facts, 'schema_version' => 1, 'issue_reference' => $issueReference,
            'assignment_id' => $assignment?->id, 'agent_profile_id' => $agent?->id];
        $id = DB::table('fee_operational_issues')->insertGetId([
            ...array_intersect_key($facts, array_flip(['issue_kind', 'source_identity', 'state_fingerprint', 'source_family', 'source_owner_id',
                'fee_obligation_id', 'fee_snapshot_id', 'thrift_plan_id', 'customer_profile_id', 'actor_user_id', 'audit_event_id', 'state', 'category', 'timezone', 'operation_reference'])),
            'issue_reference' => $issueReference, 'event_type' => $facts['issue_kind'], 'version' => 1,
            'context_ciphertext' => Crypt::encryptString(json_encode($context, JSON_THROW_ON_ERROR)),
            'effective_at' => $facts['effective_at'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        $audience = $facts['operator_audience'];
        $permission = match ($audience) {
            'deduction_manager' => AdminPermission::DeductionsManage,
            'refund_cash_operator' => AdminPermission::CashExecute,
            'refund_correction_operator' => AdminPermission::ReversalsReview,
            default => AdminPermission::FeesManage,
        };
        $recipients = User::query()->where('user_type', UserType::Admin)
            ->when($facts['issue_kind'] !== 'trigger_unapplied', fn ($query) => $query->whereKey($facts['actor_user_id']))->get()
            ->filter(fn (User $user): bool => app(AuthorizationService::class)->allows($user, $permission))
            ->map(fn (User $user): array => ['user_id' => $user->id, 'audience' => $audience, 'agent_id' => null, 'assignment_id' => null])->all();
        if (($facts['issue_kind'] === 'trigger_unapplied' || ($facts['issue_kind'] === 'delivery_issue' && $audience === 'current_agent')) && $agent !== null
            && app(AgentEligibilityService::class)->canPerformAssignedCustomerWork($agent->user)) {
            $recipients[] = ['user_id' => $agent->user_id, 'audience' => 'current_agent', 'agent_id' => $agent->id, 'assignment_id' => $assignment->id];
        }
        foreach ($recipients as $recipient) {
            DB::table('fee_issue_notification_intents')->insertOrIgnore([
                'fee_operational_issue_id' => $id, 'notification_id' => (string) Str::uuid(),
                'customer_profile_id' => $facts['customer_profile_id'], 'recipient_user_id' => $recipient['user_id'],
                'agent_profile_id' => $recipient['agent_id'], 'assignment_id' => $recipient['assignment_id'],
                'audience_type' => $recipient['audience'], 'channel' => 'database', 'payload' => '{}', 'status' => 'pending',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $ownerId = DB::table('fee_issue_notification_intents')->where('fee_operational_issue_id', $id)
                ->where('recipient_user_id', $recipient['user_id'])->where('channel', 'database')->sole()->id;
            app(NotificationPipeline::class)->capture('fee_issue', (int) $ownerId);
        }

        return $id;
    }

    private function assertTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Fee issue capture requires its source outcome transaction.');
        }
    }
}
