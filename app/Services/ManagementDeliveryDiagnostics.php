<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Models\AgentProfile;
use App\Models\CustomerProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

class ManagementDeliveryDiagnostics
{
    public function __construct(private AuthorizationService $authorization, private AgentEligibilityService $eligibility) {}

    /** @return array<string, mixed> */
    public function customer(User $viewer, CustomerProfile $customer, int $pageSize): array
    {
        $manage = $this->allows($viewer, AdminPermission::CustomersManage);
        $reassign = $this->allows($viewer, AdminPermission::CustomersReassign);
        $security = $this->allows($viewer, AdminPermission::SecurityOperationsManage);
        $assigned = $viewer->user_type === UserType::Agent && $this->eligibility->canPerformAssignedCustomerWork($viewer)
            && $customer->currentAssignment?->agentProfile?->user_id === $viewer->id;
        abort_unless($manage || $reassign || $security || $assigned, 403);
        $queries = [];
        if ($manage) {
            $queries[] = $this->owner('profile')->where('o.subject_type', 'customer')->where('o.subject_id', $customer->id);
            $queries[] = $this->owner('customer_status')->where('o.customer_profile_id', $customer->id);
            foreach (['agent_status', 'agent_lifecycle'] as $family) {
                $queries[] = $this->owner($family)->where('o.customer_profile_id', $customer->id);
            }
        }
        if ($reassign || $security) {
            $query = $this->owner('handover')->join('customer_handover_events as h', 'h.id', '=', 'o.customer_handover_event_id')
                ->where('o.customer_profile_id', $customer->id)->where(function (Builder $events) use ($reassign, $security): void {
                    $events->whereRaw('1 = 0');
                    if ($reassign) {
                        $events->orWhere('h.event_type', 'customer.reassigned');
                    }
                    if ($security) {
                        $events->orWhere('h.event_type', 'like', 'auth.customer_recovery_%');
                    }
                });
            $queries[] = $query;
        }
        if ($manage || $assigned) {
            $queries[] = $this->invitations($customer->user_id);
        }

        return $this->paginate($queries, $pageSize);
    }

    /** @return array<string, mixed> */
    public function agent(User $viewer, AgentProfile $agent, int $pageSize): array
    {
        abort_unless($this->allows($viewer, AdminPermission::AgentsManage), 403);
        $queries = [$this->invitations($agent->user_id),
            $this->owner('profile')->where('o.subject_type', 'agent')->where('o.subject_id', $agent->id)];
        foreach (['agent_status', 'agent_lifecycle'] as $family) {
            $queries[] = $this->owner($family)->where('o.agent_profile_id', $agent->id)->whereNull('o.customer_profile_id')->where('o.audience_type', '!=', 'service_manager');
        }

        return $this->paginate($queries, $pageSize);
    }

    private function allows(User $viewer, AdminPermission $permission): bool
    {
        return $viewer->user_type === UserType::Admin && $viewer->account_state === AccountState::Active
            && $this->authorization->allows($viewer, $permission);
    }

    private function owner(string $family): Builder
    {
        $definition = NotificationCatalogue::OWNERS[$family];
        $query = DB::table($definition['table'].' as o')->leftJoin('notification_inbox_aliases as alias', function ($join) use ($family): void {
            $join->on('alias.owner_intent_id', '=', 'o.id')->where('alias.family', $family);
        })->leftJoin('notification_inbox_intents as inbox', 'inbox.id', '=', 'alias.intent_id')->leftJoin('management_delivery_attempts as mail_attempt', function ($join) use ($family): void {
            $join->on('mail_attempt.owner_id', '=', 'o.id')->where('mail_attempt.owner_family', $family);
        });
        $purpose = in_array($family, ['profile', 'customer_status', 'agent_status', 'handover'], true) ? 'o.purpose' : 'o.audience_type';
        $attempts = 'CASE WHEN mail_attempt.id IS NOT NULL THEN 1 ELSE NULL END';

        return $query->selectRaw("o.notification_id as reference, o.channel, $purpose as purpose, COALESCE(inbox.status, o.status) as status, COALESCE(inbox.failure_category, mail_attempt.outcome) as category, o.created_at as effective_at, COALESCE(inbox.attempt_count, $attempts) as attempt_count, COALESCE((SELECT MAX(finished_at) FROM notification_inbox_attempts WHERE intent_id = inbox.id), mail_attempt.started_at) as last_attempt_at, mail_attempt.transport_kind");
    }

    private function invitations(int $userId): Builder
    {
        $reference = DB::getDriverName() === 'sqlite' ? "'INV-' || invitation.id || '-' || invitation.generation" : "CONCAT('INV-', invitation.id, '-', invitation.generation)";

        return DB::table('invitations as invitation')->leftJoin('invitation_delivery_issues as issue', 'issue.invitation_id', '=', 'invitation.id')
            ->where('invitation.user_id', $userId)->selectRaw("$reference as reference, 'mail' as channel, 'invitation' as purpose, CASE WHEN invitation.status IN ('cancelled', 'activated') THEN 'suppressed' ELSE invitation.delivery_status END as status, issue.category, invitation.created_at as effective_at, issue.attempt_count, issue.last_attempt_at, NULL as transport_kind");
    }

    /** @param list<Builder> $queries
     * @return array<string, mixed>
     */
    private function paginate(array $queries, int $pageSize): array
    {
        $query = array_shift($queries);
        abort_if($query === null, 403);
        foreach ($queries as $part) {
            $query->unionAll($part);
        }
        $page = DB::query()->fromSub($query, 'deliveries')->orderByDesc('effective_at')->orderByDesc('reference')->paginate($pageSize);

        return ['data' => $page->getCollection()->map(fn (stdClass $row): array => $this->safeRow($row))->all(),
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()];
    }

    /** @return array<string, mixed> */
    private function safeRow(stdClass $row): array
    {
        $local = $row->transport_kind === 'local';
        $status = match ($row->status) {
            'delivered', 'sent' => $row->channel === 'database' ? 'Delivered' : ($local ? 'Local verification only' : ($row->category === 'transport_accepted' ? 'Transport accepted' : 'Pending (uncertain)')),
            'unknown', 'uncertain', 'sending', 'attempting' => 'Pending (uncertain)',
            'failed', 'dead_letter' => $row->category === 'acceptance_unknown' ? 'Pending (uncertain)' : 'Failed',
            'suppressed' => 'Suppressed', 'blocked' => 'Blocked', default => 'Pending',
        };
        $category = in_array($row->category, ['queue_unavailable', 'acceptance_unknown', 'scope_or_expiry', 'unsupported_contract', 'invalid_contract', 'local_delivery_failure', 'retry_budget_exhausted', 'provider_outcome_unverified'], true) ? $row->category : null;

        return ['reference' => $row->reference, 'channel' => $row->channel === 'database' ? 'in-app' : 'email',
            'purpose' => $row->purpose, 'status' => $status, 'category' => $category,
            'effective_at' => CarbonImmutable::parse($row->effective_at)->toISOString(),
            'attempt_count' => $row->attempt_count === null ? null : (int) $row->attempt_count,
            'last_attempt_at' => $row->last_attempt_at === null ? null : CarbonImmutable::parse($row->last_attempt_at)->toISOString()];
    }
}
