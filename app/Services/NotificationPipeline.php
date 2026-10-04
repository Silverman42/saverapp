<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Jobs\MaterializeNotificationIntent;
use App\Models\AuditEvent;
use App\Models\CollectionReceipt;
use App\Models\CustomerProfile;
use App\Models\FeeRule;
use App\Models\LedgerPostingGroup;
use App\Models\ManualCharge;
use App\Models\PlanLifecycleEvent;
use App\Models\PlanNotificationIntent;
use App\Models\ThriftPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;
use Throwable;

class NotificationPipeline
{
    public function __construct(private NotificationCatalogue $catalogue, private AgentEligibilityService $eligibility, private AuthorizationService $authorization) {}

    public function capture(string $family, int $ownerId, bool $dispatch = true): ?int
    {
        $definition = NotificationCatalogue::OWNERS[$family] ?? throw new InvalidArgumentException('Unknown notification family.');
        $owner = DB::table($definition['table'])->where('id', $ownerId)->first();
        if ($owner === null || ($owner->channel ?? 'database') !== 'database') {
            return null;
        }
        $descriptor = $this->catalogue->describe($family, $owner);

        return DB::transaction(function () use ($family, $owner, $descriptor, $dispatch): int {
            DB::table('notification_events')->insertOrIgnore([
                'event_id' => (string) Str::uuid(), 'family' => $family, 'source_id' => $descriptor['source_id'],
                'source_version' => $descriptor['source_version'], 'event_type' => $descriptor['event_type'], 'schema_version' => 1,
                'timezone' => $descriptor['timezone'], 'operation_reference' => $descriptor['operation_reference'], 'actor_category' => $descriptor['actor_category'],
                'facts' => json_encode($descriptor['facts'], JSON_THROW_ON_ERROR), 'audit_event_id' => $descriptor['audit_event_id'],
                'effective_at' => $descriptor['effective_at'], 'created_at' => now(),
            ]);
            $event = DB::table('notification_events')->where('family', $family)->where('source_id', $descriptor['source_id'])
                ->where('source_version', $descriptor['source_version'])->first();
            if ($event === null) {
                throw new InvalidArgumentException('Notification event could not be retained.');
            }
            $snapshot = ['title' => $descriptor['title'], 'summary' => $descriptor['summary'], 'reference' => $descriptor['reference'], 'destination' => $descriptor['destination']];
            DB::table('notification_inbox_intents')->insertOrIgnore([
                'event_id' => $event->id, 'recipient_user_id' => $owner->recipient_user_id, 'notification_id' => $owner->notification_id,
                'channel' => 'database', 'audiences' => json_encode([$descriptor['audience']], JSON_THROW_ON_ERROR),
                'customer_profile_id' => $descriptor['customer_profile_id'], 'agent_profile_id' => $descriptor['agent_profile_id'],
                'assignment_id' => $this->assignmentId($owner, $descriptor), 'action_correction_id' => $descriptor['action_correction_id'],
                'category' => $descriptor['category'], 'importance' => $descriptor['action_required'] ? 'high' : 'normal',
                'mandatory' => true, 'action_required' => $descriptor['action_required'],
                'template_id' => $family.'.'.$descriptor['event_type'], 'template_version' => 1, 'locale' => config('notifications.locale'),
                ...array_diff_key($snapshot, ['destination' => true]), 'destination' => json_encode($descriptor['destination'], JSON_THROW_ON_ERROR),
                'snapshot_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)), 'status' => 'pending',
                'effective_at' => $descriptor['effective_at'], 'expires_at' => $descriptor['effective_at']->addMonthsNoOverflow((int) config('notifications.retention_months')),
                'created_at' => $owner->created_at ?? now(), 'updated_at' => now(),
            ]);
            $intent = DB::table('notification_inbox_intents')->where('event_id', $event->id)
                ->where('recipient_user_id', $owner->recipient_user_id)->where('channel', 'database')->lockForUpdate()->first();
            if ($intent === null || (int) ($intent->customer_profile_id ?? 0) !== (int) ($descriptor['customer_profile_id'] ?? 0)
                || (int) ($intent->agent_profile_id ?? 0) !== (int) ($descriptor['agent_profile_id'] ?? 0)) {
                throw new InvalidArgumentException('Notification audience relationships conflict.');
            }
            $audiences = json_decode($intent->audiences, true, flags: JSON_THROW_ON_ERROR);
            if (! in_array($descriptor['audience'], $audiences, true)) {
                $audiences[] = $descriptor['audience'];
                DB::table('notification_inbox_intents')->where('id', $intent->id)->update(['audiences' => json_encode($audiences, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            }
            DB::table('notification_inbox_aliases')->insertOrIgnore([
                'intent_id' => $intent->id, 'family' => $family, 'owner_intent_id' => $owner->id, 'notification_id' => $owner->notification_id,
            ]);
            app(BackgroundRecovery::class)->register('notification_inbox', (int) $intent->id);
            if ($dispatch) {
                DB::afterCommit(static function () use ($intent): void {
                    try {
                        MaterializeNotificationIntent::dispatch((int) $intent->id)->afterCommit();
                    } catch (Throwable) {
                        // The committed intent remains recoverable by the scheduled drainer.
                    }
                });
            }

            return (int) $intent->id;
        }, attempts: 3);
    }

    /** @param callable(): mixed $dispatch */
    public function dispatchRecoverably(callable $dispatch): void
    {
        try {
            $dispatch();
        } catch (Throwable) {
            Log::warning('In-app notification queue dispatch unavailable.', ['channel' => 'database']);
        }
    }

    public function recoverLocalOwner(string $family, int $ownerId): bool
    {
        app(PlatformGuard::class)->assertAllowed('external');
        $definition = NotificationCatalogue::OWNERS[$family] ?? throw new InvalidArgumentException('Unknown notification family.');
        $owner = DB::table($definition['table'])->where('id', $ownerId)->first();
        if ($owner === null) {
            return true;
        }
        if (($owner->channel ?? 'database') !== 'database') {
            return false;
        }
        $this->deliverOwner($family, $ownerId);

        return true;
    }

    public function deliverOwner(string $family, int $ownerId): void
    {
        $intentId = app(PlatformGuard::class)->transaction('external', function () use ($family, $ownerId): ?int {
            $alias = DB::table('notification_inbox_aliases')->where('family', $family)->where('owner_intent_id', $ownerId)->first();

            return $alias === null ? $this->capture($family, $ownerId, false) : (int) $alias->intent_id;
        });
        if ($intentId !== null) {
            $this->materialize($intentId);
        }
    }

    public function isRecipientEligible(int $intentId): bool
    {
        $intent = DB::table('notification_inbox_intents')->where('id', $intentId)->first();
        if ($intent === null || CarbonImmutable::parse($intent->expires_at)->isPast()) {
            return false;
        }
        $recipient = User::query()->where('id', $intent->recipient_user_id)->first();

        return $recipient !== null && $this->recipientScope($recipient, false)->where('i.id', $intentId)->exists();
    }

    public function materialize(int $intentId): void
    {
        app(BackgroundRecovery::class)->runSource('notification_inbox', $intentId);
    }

    public function materializeOwned(int $intentId): void
    {
        $startedAt = now();
        app(PlatformGuard::class)->transaction('external', function () use ($intentId, $startedAt): void {
            $intent = DB::table('notification_inbox_intents')->where('id', $intentId)->lockForUpdate()->first();
            if ($intent === null || $intent->status !== 'pending' || ($intent->next_attempt_at !== null && CarbonImmutable::parse($intent->next_attempt_at)->isFuture())) {
                return;
            }
            $recipient = User::query()->where('id', $intent->recipient_user_id)->lockForUpdate()->first();
            if ($intent->customer_profile_id !== null) {
                CustomerProfile::query()->whereKey($intent->customer_profile_id)->lockForUpdate()->first();
                DB::table('customer_assignments')->where('customer_profile_id', $intent->customer_profile_id)->where('is_current', 1)->lockForUpdate()->first();
            }
            $status = 'delivered';
            $failureCategory = null;
            $event = DB::table('notification_events')->where('id', $intent->event_id)->first();
            if ($event === null || ! $this->catalogue->validatesStoredContract($event, $intent)) {
                $status = 'blocked';
                $failureCategory = 'unsupported_contract';
            } elseif (CarbonImmutable::parse($intent->expires_at)->isPast() || $recipient === null
                || ! $this->recipientScope($recipient, false)->where('i.id', $intentId)->exists()) {
                $status = 'suppressed';
                $failureCategory = 'scope_or_expiry';
            }
            $number = (int) $intent->attempt_count + 1;
            DB::table('notification_inbox_intents')->where('id', $intentId)->update(['status' => 'attempting']);
            if ($status === 'delivered') {
                $existing = DB::table('notifications')->where('id', $intent->notification_id)->first();
                if ($existing !== null && ((int) $existing->notifiable_id !== (int) $recipient->id || $existing->notifiable_type !== $recipient->getMorphClass())) {
                    throw new InvalidArgumentException('Conflicting notification recipient.');
                }
                $data = json_encode(['title' => $intent->title, 'message' => $intent->summary, 'reference' => $intent->reference, 'template_id' => $intent->template_id, 'template_version' => 1], JSON_THROW_ON_ERROR);
                if ($existing === null) {
                    DB::table('notifications')->insert([
                        'id' => $intent->notification_id, 'type' => 'shared-inbox-v1', 'notifiable_type' => $recipient->getMorphClass(),
                        'notifiable_id' => $recipient->id, 'data' => $data, 'read_at' => null,
                        'created_at' => $intent->created_at, 'updated_at' => now(), 'read_version' => 1,
                    ]);
                } else {
                    DB::table('notifications')->where('id', $intent->notification_id)->update(['data' => $data, 'type' => 'shared-inbox-v1']);
                }
            }
            if (in_array($event?->family, ['invitation_issue', 'profile', 'customer_status', 'agent_status', 'agent_lifecycle', 'handover'], true)) {
                $family = $intent->customer_profile_id !== null ? 'customer' : 'agent';
                AuditEvent::record($family.'.delivery_attempt', $family, $intent->customer_profile_id ?? $intent->agent_profile_id, null,
                    ['notification_reference' => $intent->notification_id, 'source_audit_event_id' => $event->audit_event_id,
                        'channel' => 'database', 'attempt' => $number, 'category' => $failureCategory], null,
                    ['executor' => self::class, 'outcome' => $status === 'delivered' ? 'Succeeded' : ($status === 'suppressed' ? 'Denied' : 'Failed'),
                        'operation_id' => 'inbox-attempt:'.$intentId.':'.$number]);
            } elseif ($event?->family === 'fee_rule') {
                $this->recordFeeRuleDeliveryAttempt($intent, $event, $number, $failureCategory,
                    $status === 'delivered' ? 'Succeeded' : ($status === 'suppressed' ? 'Denied' : 'Failed'));
            } elseif ($event?->family === 'fee_application') {
                $this->recordFeeApplicationDeliveryAttempt($intent, $event, $number, $failureCategory,
                    $status === 'delivered' ? 'Succeeded' : ($status === 'suppressed' ? 'Denied' : 'Failed'));
            } elseif ($event?->family === 'charge') {
                $this->recordChargeDeliveryAttempt($intent, $event, $number, $failureCategory,
                    $status === 'delivered' ? 'Succeeded' : ($status === 'suppressed' ? 'Denied' : 'Failed'));
            } elseif (in_array($event?->family, ['fee_obligation', 'financial_cash'], true)) {
                $this->recordFinancialSourceDeliveryAttempt($intent, $event, $number, $failureCategory,
                    $status === 'delivered' ? 'Succeeded' : ($status === 'suppressed' ? 'Denied' : 'Failed'));
            } elseif ($event?->family === 'collection') {
                $this->recordCollectionDeliveryAttempt($intent, $event, $number, $failureCategory,
                    $status === 'delivered' ? 'Succeeded' : ($status === 'suppressed' ? 'Denied' : 'Failed'));
            } elseif ($event?->family === 'plan') {
                $this->recordPlanDeliveryAttempt($intent, $event, $number, $failureCategory,
                    $status === 'delivered' ? 'Succeeded' : ($status === 'suppressed' ? 'Denied' : 'Failed'));
            }
            DB::table('notification_inbox_attempts')->insert([
                'intent_id' => $intentId, 'attempt_number' => $number, 'outcome' => $status,
                'failure_category' => $failureCategory, 'started_at' => $startedAt, 'finished_at' => now(),
            ]);
            DB::table('notification_inbox_intents')->where('id', $intentId)->update([
                'status' => $status, 'failure_category' => $failureCategory, 'attempt_count' => $number,
                'delivered_at' => $status === 'delivered' ? now() : null, 'next_attempt_at' => null, 'updated_at' => now(),
            ]);
            $this->syncOwners($intentId, $status);
            if ($status === 'suppressed') {
                $this->rerouteUnresolved($intent, $event);
            }
        }, attempts: 3);
    }

    public function recordRecoveryFailure(int $intentId, string $state, string $code, ?string $availableAt, bool $countAttempt = true): void
    {
        $intent = DB::table('notification_inbox_intents')->where('id', $intentId)->lockForUpdate()->first();
        if ($intent === null || in_array($intent->status, ['delivered', 'suppressed'], true)) {
            return;
        }
        $attempts = (int) $intent->attempt_count + ($countAttempt ? 1 : 0);
        $status = in_array($code, ['unsupported_contract', 'invalid_contract', 'source_identity_conflict', 'owner_state_conflict', 'owner_result_unverified'], true)
            ? 'blocked' : ($state === 'dead_letter' ? 'dead_letter' : 'pending');
        $deliveryAudit = null;
        if ($attempts > (int) $intent->attempt_count) {
            $event = DB::table('notification_events')->where('id', $intent->event_id)->first();
            if (in_array($event?->family, ['invitation_issue', 'profile', 'customer_status', 'agent_status', 'agent_lifecycle', 'handover'], true)) {
                $family = $intent->customer_profile_id !== null ? 'customer' : 'agent';
                AuditEvent::record($family.'.delivery_attempt', $family, $intent->customer_profile_id ?? $intent->agent_profile_id, null,
                    ['notification_reference' => $intent->notification_id, 'source_audit_event_id' => $event->audit_event_id,
                        'channel' => 'database', 'attempt' => $attempts, 'category' => $code], null,
                    ['executor' => self::class, 'outcome' => 'Failed', 'operation_id' => 'inbox-attempt:'.$intentId.':'.$attempts]);
            } elseif ($event?->family === 'fee_rule') {
                $deliveryAudit = $this->recordFeeRuleDeliveryAttempt($intent, $event, $attempts, $code, 'Failed');
            } elseif ($event?->family === 'fee_application') {
                $deliveryAudit = $this->recordFeeApplicationDeliveryAttempt($intent, $event, $attempts, $code, 'Failed');
            } elseif ($event?->family === 'charge') {
                $deliveryAudit = $this->recordChargeDeliveryAttempt($intent, $event, $attempts, $code, 'Failed');
            } elseif (in_array($event?->family, ['fee_obligation', 'financial_cash'], true)) {
                $deliveryAudit = $this->recordFinancialSourceDeliveryAttempt($intent, $event, $attempts, $code, 'Failed');
            } elseif ($event?->family === 'collection') {
                $deliveryAudit = $this->recordCollectionDeliveryAttempt($intent, $event, $attempts, $code, 'Failed');
            } elseif ($event?->family === 'plan') {
                $this->recordPlanDeliveryAttempt($intent, $event, $attempts, $code, 'Failed');
            }
            DB::table('notification_inbox_attempts')->insert([
                'intent_id' => $intentId, 'attempt_number' => $attempts, 'outcome' => $status,
                'failure_category' => $code, 'started_at' => now(), 'finished_at' => now(),
            ]);
        }
        DB::table('notification_inbox_intents')->where('id', $intentId)->update([
            'status' => $status, 'attempt_count' => $attempts, 'failure_category' => $code,
            'next_attempt_at' => $availableAt, 'updated_at' => now(),
        ]);
        $this->syncOwners($intentId, $status);
        if ($deliveryAudit !== null && $status !== 'blocked') {
            foreach (DB::table('notification_inbox_aliases')->where('intent_id', $intentId)->get() as $alias) {
                app(FeeOperationalIssues::class)->delivery($alias->family, (int) $alias->owner_intent_id, 'database',
                    $status === 'dead_letter' ? 'dead_letter' : 'local_failure', $code, $deliveryAudit->id);
            }
        }
    }

    private function recordCollectionDeliveryAttempt(stdClass $intent, stdClass $event, int $attempt, ?string $category, string $outcome): AuditEvent
    {
        $source = $this->catalogue->validatesStoredContract($event, $intent)
            ? DB::table('collection_receipts')->where('id', $event->source_id)->first() : null;

        return AuditEvent::record('collection.delivery_attempt', CollectionReceipt::class, $source === null ? null : (int) $source->id,
            $source?->receipt_reference, ['notification_reference' => $intent->notification_id, 'channel' => 'database',
                'attempt' => $attempt, 'category' => $category,
                'source_audit_event_id' => $source === null ? null : $event->audit_event_id], null,
            ['executor' => self::class, 'outcome' => $outcome, 'operation_id' => 'inbox-attempt:'.$intent->id.':'.$attempt]);
    }

    private function recordFinancialSourceDeliveryAttempt(stdClass $intent, stdClass $event, int $attempt, ?string $category, string $outcome): AuditEvent
    {
        $audit = $this->catalogue->validatesStoredContract($event, $intent)
            ? DB::table('audit_events')->where('id', $event->audit_event_id)->first() : null;
        $family = $event->family === 'fee_obligation' || $event->event_type === 'refund_authorized' ? 'fee' : 'cash_disbursement';

        return AuditEvent::record($family.'.delivery_attempt', $audit->target_type ?? $family, $audit?->target_id, $audit?->target_reference,
            ['notification_reference' => $intent->notification_id, 'channel' => 'database', 'attempt' => $attempt,
                'category' => $category, 'source_event_id' => $audit === null ? null : (int) $event->source_id,
                'customer_profile_id' => $audit === null ? null : $intent->customer_profile_id,
                'source_audit_event_id' => $audit === null ? null : (int) $event->audit_event_id], null,
            ['executor' => self::class, 'outcome' => $outcome, 'operation_id' => 'inbox-attempt:'.$intent->id.':'.$attempt]);
    }

    private function recordChargeDeliveryAttempt(stdClass $intent, stdClass $event, int $attempt, ?string $category, string $outcome): AuditEvent
    {
        $source = $this->catalogue->validatesStoredContract($event, $intent)
            ? DB::table('manual_charges')->where('id', $event->source_id)->first() : null;

        return AuditEvent::record('charge.delivery_attempt', ManualCharge::class, $source === null ? null : (int) $source->id,
            $source?->operation_reference, ['notification_reference' => $intent->notification_id, 'channel' => 'database',
                'attempt' => $attempt, 'category' => $category, 'manual_charge_id' => $source === null ? null : (int) $source->id,
                'customer_profile_id' => $source === null ? null : (int) $source->customer_profile_id,
                'source_audit_event_id' => $source === null ? null : (int) $event->audit_event_id], null,
            ['executor' => self::class, 'outcome' => $outcome, 'operation_id' => 'inbox-attempt:'.$intent->id.':'.$attempt]);
    }

    private function recordFeeApplicationDeliveryAttempt(stdClass $intent, stdClass $event, int $attempt, ?string $category, string $outcome): AuditEvent
    {
        $source = $this->catalogue->validatesStoredContract($event, $intent) ? DB::table('fee_savings_applications as application')
            ->join('ledger_posting_groups as posting', fn ($query) => $query->on('posting.source_id', '=', 'application.operation_reference')
                ->where('posting.source_type', 'fee_savings_application'))
            ->where('application.id', $event->source_id)->first(['application.id', 'application.customer_profile_id',
                'posting.id as posting_id', 'posting.posting_reference']) : null;

        return AuditEvent::record('fee_application.delivery_attempt', LedgerPostingGroup::class, $source === null ? null : (int) $source->posting_id,
            $source?->posting_reference, ['notification_reference' => $intent->notification_id, 'channel' => 'database',
                'attempt' => $attempt, 'category' => $category, 'application_id' => $source === null ? null : (int) $source->id,
                'customer_profile_id' => $source === null ? null : (int) $source->customer_profile_id,
                'source_audit_event_id' => $source === null ? null : (int) $event->audit_event_id], null,
            ['executor' => self::class, 'outcome' => $outcome, 'operation_id' => 'inbox-attempt:'.$intent->id.':'.$attempt]);
    }

    private function recordFeeRuleDeliveryAttempt(stdClass $intent, stdClass $event, int $attempt, ?string $category, string $outcome): AuditEvent
    {
        $source = DB::table('fee_rule_notification_intents as owner')
            ->join('fee_rule_events as source', 'source.id', '=', 'owner.fee_rule_event_id')
            ->join('fee_rules as rule', 'rule.id', '=', 'source.fee_rule_id')
            ->join('audit_events as audit', 'audit.id', '=', 'source.audit_event_id')
            ->whereIn('owner.id', DB::table('notification_inbox_aliases')->where('intent_id', $intent->id)
                ->where('family', 'fee_rule')->select('owner_intent_id'))
            ->where('owner.notification_id', $intent->notification_id)->where('owner.recipient_user_id', $intent->recipient_user_id)
            ->where('owner.channel', 'database')->where('owner.audience_type', 'fee_manager')
            ->where('source.id', $event->source_id)->where('source.event_type', $event->event_type)
            ->where('source.version', $event->source_version)->whereColumn('source.version', 'rule.version')
            ->where('source.audit_event_id', $event->audit_event_id)->where('audit.target_type', FeeRule::class)
            ->whereColumn('audit.target_id', 'rule.id')->whereColumn('audit.actor_id', 'source.actor_user_id')
            ->where('audit.event_type', 'fee_rule.'.$event->event_type)
            ->first(['source.id', 'source.audit_event_id', 'rule.id as rule_id', 'rule.kind', 'rule.rule_key', 'rule.version']);

        return AuditEvent::record('fee_rule.delivery_attempt', FeeRule::class, $source === null ? null : (int) $source->rule_id,
            $source === null ? null : "{$source->kind}:{$source->rule_key}:v{$source->version}",
            ['notification_reference' => $intent->notification_id, 'channel' => 'database', 'attempt' => $attempt,
                'category' => $category, 'fee_rule_event_id' => $source === null ? null : (int) $source->id,
                'source_audit_event_id' => $source === null ? null : (int) $source->audit_event_id,
                'version' => $source === null ? null : (int) $source->version], null,
            ['executor' => self::class, 'outcome' => $outcome, 'operation_id' => 'inbox-attempt:'.$intent->id.':'.$attempt,
                'source_version' => $source === null ? 1 : (int) $source->version]);
    }

    private function recordPlanDeliveryAttempt(stdClass $intent, stdClass $event, int $attempt, ?string $category, string $outcome): void
    {
        $source = PlanLifecycleEvent::query()->whereKey($event->source_id)->first();
        $owner = $source === null ? null : PlanNotificationIntent::query()
            ->whereIn('id', DB::table('notification_inbox_aliases')->where('intent_id', $intent->id)
                ->where('family', 'plan')->select('owner_intent_id'))
            ->where('plan_lifecycle_event_id', $source->id)->where('thrift_plan_id', $source->thrift_plan_id)
            ->where('customer_profile_id', $intent->customer_profile_id)->first();
        $plan = $owner === null || $source->plan_version !== (int) $event->source_version ? null
            : ThriftPlan::query()->whereKey($owner->thrift_plan_id)->where('customer_profile_id', $intent->customer_profile_id)->first();
        AuditEvent::record('thrift_plan.delivery_attempt', ThriftPlan::class, $plan?->id, $plan?->plan_id,
            ['notification_reference' => $intent->notification_id, 'channel' => 'database', 'attempt' => $attempt,
                'category' => $category, 'customer_profile_id' => $plan?->customer_profile_id,
                'lifecycle_event_id' => $plan !== null ? $source->id : null], null,
            ['executor' => self::class, 'outcome' => $outcome, 'operation_id' => 'inbox-attempt:'.$intent->id.':'.$attempt]);
    }

    public function recipientScope(User $user, bool $requireAccess = true): Builder
    {
        $query = DB::table('notification_inbox_intents as i')->where('i.recipient_user_id', $user->id);
        if (($requireAccess && $user->account_state !== AccountState::Active)
            || $user->getRoleNames()->count() !== 1 || $user->getRoleNames()->first() !== $user->user_type->value) {
            return $query->whereRaw('1 = 0');
        }
        $query->where(function (Builder $live): void {
            $live->where('i.template_id', '!=', 'invitation_issue.invitation_delivery_issue')->orWhereExists(function (Builder $issue): void {
                $issue->selectRaw('1')->from('notification_events as ne')->join('invitation_delivery_issues as di', 'di.id', '=', 'ne.source_id')
                    ->join('invitations as invitation', 'invitation.id', '=', 'di.invitation_id')->join('users as invited_user', 'invited_user.id', '=', 'invitation.user_id')
                    ->whereColumn('ne.id', 'i.event_id')->where('ne.family', 'invitation_issue')->where('invited_user.account_state', 'invited')
                    ->where('invitation.status', 'delivery_failed')->where('invitation.expires_at', '>', now());
            });
        });
        if (Schema::hasTable('fee_operational_issues')) {
            $issues = DB::table('notification_inbox_intents as candidate')
                ->join('notification_events as issue_event', 'issue_event.id', '=', 'candidate.event_id')
                ->where('candidate.recipient_user_id', $user->id)->where('issue_event.family', 'fee_issue')
                ->where('candidate.expires_at', '>', now())->get(['candidate.id', 'issue_event.source_id']);
            $canActAsAgent = $user->user_type !== UserType::Agent || $this->eligibility->canPerformAssignedCustomerWork($user);
            $unavailableIssues = $issues->filter(fn (stdClass $issue): bool => ! $canActAsAgent
                || ! app(FeeOperationalIssueNotificationSource::class)->isActionable((int) $issue->source_id))->pluck('id')->all();
            if ($unavailableIssues !== []) {
                $query->whereNotIn('i.id', $unavailableIssues);
            }
        }
        $canReadAssigned = $user->user_type === UserType::Agent && $this->eligibility->canReadAssignedCustomers($user);
        $canManageCustomers = $user->user_type === UserType::Admin && $this->authorization->allows($user, AdminPermission::CustomersManage);
        $canManageAgents = $user->user_type === UserType::Admin && $this->authorization->allows($user, AdminPermission::AgentsManage);
        $canReassignCustomers = $user->user_type === UserType::Admin && $this->authorization->allows($user, AdminPermission::CustomersReassign);
        $canManageSettings = $user->user_type === UserType::Admin && $this->authorization->allows($user, AdminPermission::BusinessSettingsManage);
        $canManageSecurity = $user->user_type === UserType::Admin && $this->authorization->allows($user, AdminPermission::SecurityOperationsManage);

        return $query->where(function (Builder $audiences) use ($user, $canReadAssigned, $canManageAgents, $canManageCustomers, $canReassignCustomers, $canManageSecurity, $canManageSettings): void {
            $audiences->whereRaw('1 = 0');
            if ($user->user_type === UserType::Customer) {
                $audiences->orWhere(function (Builder $own) use ($user): void {
                    $own->where(function (Builder $purposes): void {
                        $purposes->whereJsonContains('i.audiences', 'subject_customer')->orWhereJsonContains('i.audiences', 'assigned_customer');
                    })->whereExists(function (Builder $customer) use ($user): void {
                        $customer->selectRaw('1')->from('customer_profiles as c')->whereColumn('c.id', 'i.customer_profile_id')->where('c.user_id', $user->id);
                    })->where(function (Builder $service): void {
                        $service->whereJsonDoesntContain('i.audiences', 'assigned_customer')->orWhereExists(function (Builder $assignment): void {
                            $assignment->selectRaw('1')->from('customer_assignments as a')->join('agent_profiles as ap', 'ap.id', '=', 'a.agent_profile_id')
                                ->join('users as agent_user', 'agent_user.id', '=', 'ap.user_id')
                                ->join('customer_profiles as cp', 'cp.id', '=', 'a.customer_profile_id')
                                ->whereColumn('a.customer_profile_id', 'i.customer_profile_id')->whereColumn('a.agent_profile_id', 'i.agent_profile_id')
                                ->where('a.is_current', 1)->where('cp.operational_status', '!=', 'archived')
                                ->join('notification_events as service_event', 'service_event.id', '=', 'i.event_id')
                                ->where(function (Builder $state): void {
                                    $state->where(function (Builder $restored): void {
                                        $restored->where('ap.operational_status', 'active')->where('agent_user.account_state', 'active')
                                            ->where(function (Builder $event): void {
                                                $event->where('service_event.event_type', 'agent.restore')->orWhere('service_event.facts->status', 'active');
                                            });
                                    })->orWhere(function (Builder $unavailable): void {
                                        $unavailable->where(function (Builder $agent): void {
                                            $agent->where('ap.operational_status', 'inactive')->orWhereIn('agent_user.account_state', ['suspended', 'deactivated']);
                                        })->where('service_event.event_type', '!=', 'agent.restore')
                                            ->where(function (Builder $event): void {
                                                $event->whereNull('service_event.facts->status')->orWhere('service_event.facts->status', '!=', 'active');
                                            });
                                    });
                                });
                        });
                    });
                });
            }
            if ($user->user_type === UserType::Agent) {
                $audiences->orWhere(function (Builder $own) use ($user): void {
                    $own->whereJsonContains('i.audiences', 'subject_agent')->whereExists(function (Builder $agent) use ($user): void {
                        $agent->selectRaw('1')->from('agent_profiles as ap')->whereColumn('ap.id', 'i.agent_profile_id')->where('ap.user_id', $user->id);
                    });
                });
                if ($canReadAssigned) {
                    $audiences->orWhere(function (Builder $assigned) use ($user): void {
                        $assigned->whereJsonContains('i.audiences', 'current_agent')->whereExists(function (Builder $assignment) use ($user): void {
                            $assignment->selectRaw('1')->from('customer_assignments as a')->join('agent_profiles as ap', 'ap.id', '=', 'a.agent_profile_id')
                                ->whereColumn('a.customer_profile_id', 'i.customer_profile_id')->whereColumn('a.id', 'i.assignment_id')->where('a.is_current', 1)->where('ap.user_id', $user->id);
                        });
                    });
                }
            }
            $audiences->orWhere(function (Builder $artifacts) use ($user): void {
                $artifacts->whereJsonContains('i.audiences', 'artifact_requester')->whereExists(function (Builder $query) use ($user): void {
                    $query->selectRaw('1')->from('financial_artifacts as fa')->join('notification_events as ne', 'ne.operation_reference', '=', 'fa.artifact_reference')
                        ->whereColumn('ne.id', 'i.event_id')->where('fa.requester_user_id', $user->id)
                        ->where(function (Builder $scope) use ($user): void {
                            $scope->where(function (Builder $statement) use ($user): void {
                                $statement->where('fa.kind', 'statement')->whereIn('fa.customer_profile_id', app(ResourceScopeService::class)->forCustomers($user)->select('customer_profiles.id'));
                            });
                            if ($this->authorization->allows($user, AdminPermission::ReportsExport)) {
                                $scope->orWhere('fa.kind', 'report');
                            }
                        });
                });
            });
            if ($canManageCustomers) {
                $audiences->orWhereJsonContains('i.audiences', 'customer_manager');
            }
            if ($this->authorization->allows($user, AdminPermission::CashExecute) && $this->authorization->allows($user, AdminPermission::FeesManage)) {
                $audiences->orWhereJsonContains('i.audiences', 'cash_executor');
            }
            if ($user->user_type === UserType::Admin && $user->account_state === AccountState::Active
                && $this->authorization->allows($user, AdminPermission::CashExecute)) {
                $audiences->orWhereJsonContains('i.audiences', 'refund_cash_operator');
            }
            if ($user->user_type === UserType::Admin && $user->account_state === AccountState::Active
                && $this->authorization->allows($user, AdminPermission::ReversalsReview)) {
                $audiences->orWhereJsonContains('i.audiences', 'refund_correction_operator');
            }
            if ($canManageAgents) {
                $audiences->orWhereJsonContains('i.audiences', 'managing_admin');
            }
            if ($canReassignCustomers) {
                $audiences->orWhere(function (Builder $issue): void {
                    $issue->whereJsonContains('i.audiences', 'service_manager')->whereExists(function (Builder $agent): void {
                        $agent->selectRaw('1')->from('agent_profiles as ap')->join('users as au', 'au.id', '=', 'ap.user_id')
                            ->whereColumn('ap.id', 'i.agent_profile_id')
                            ->where(fn (Builder $unavailable) => $unavailable->where('ap.operational_status', '!=', 'active')->orWhere('au.account_state', '!=', 'active'))
                            ->whereExists(function (Builder $affected): void {
                                $affected->selectRaw('1')->from('customer_assignments as ca')->join('customer_profiles as cp', 'cp.id', '=', 'ca.customer_profile_id')
                                    ->whereColumn('ca.agent_profile_id', 'ap.id')->where('ca.is_current', 1)->where('cp.operational_status', '!=', 'archived');
                            });
                    });
                });
            }
            if ($user->user_type === UserType::Admin && $user->account_state === AccountState::Active
                && $this->authorization->allows($user, AdminPermission::FeesManage)) {
                $audiences->orWhereJsonContains('i.audiences', 'fee_manager');
            }
            if ($user->user_type === UserType::Admin && $user->account_state === AccountState::Active
                && $this->authorization->allows($user, AdminPermission::DeductionsManage)) {
                $audiences->orWhereJsonContains('i.audiences', 'deduction_manager');
            }
            if ($canManageSettings) {
                $audiences->orWhereJsonContains('i.audiences', 'settings_manager');
            }
            if ($canManageSecurity) {
                $audiences->orWhereJsonContains('i.audiences', 'security_operations_admin');
            }
            if ($user->user_type === UserType::Admin && $user->account_state === AccountState::Active
                && $this->authorization->allows($user, AdminPermission::ReconciliationManage)) {
                $audiences->orWhereJsonContains('i.audiences', 'reconciliation_manager');
            }
            $audiences->orWhereJsonContains('i.audiences', 'subject_user');
            if ($user->user_type === UserType::Admin && $user->account_state === AccountState::Active
                && $this->authorization->allows($user, AdminPermission::AdminsManage)) {
                $audiences->orWhereJsonContains('i.audiences', 'admin_manager');
            }
        });
    }

    /** @param array<string, mixed> $descriptor */
    private function assignmentId(stdClass $owner, array $descriptor): ?int
    {
        if ($descriptor['audience'] !== 'current_agent') {
            return null;
        }
        if (($owner->assignment_id ?? null) !== null) {
            return (int) $owner->assignment_id;
        }
        $assignment = DB::table('customer_assignments as a')->join('agent_profiles as ap', 'ap.id', '=', 'a.agent_profile_id')
            ->where('a.customer_profile_id', $descriptor['customer_profile_id'])->where('ap.user_id', $owner->recipient_user_id)
            ->where('a.effective_at', '<=', $owner->created_at)
            ->where(fn (Builder $query) => $query->whereNull('a.ended_at')->orWhere('a.ended_at', '>', $owner->created_at))
            ->orderByDesc('a.id')->first(['a.id']);

        return $assignment === null ? null : (int) $assignment->id;
    }

    private function rerouteUnresolved(stdClass $intent, ?stdClass $event): void
    {
        if ($event === null || ! in_array($event->family, ['withdrawal', 'reversal'], true)
            || ! in_array('current_agent', json_decode($intent->audiences, true, flags: JSON_THROW_ON_ERROR), true)
            || CarbonImmutable::parse($intent->expires_at)->isPast()) {
            return;
        }
        $family = $event->family;
        $definition = NotificationCatalogue::OWNERS[$family];
        $source = DB::table($definition['source'])->where('id', $event->source_id)->first();
        if ($source === null) {
            return;
        }
        $requestIdColumn = $family === 'withdrawal' ? 'withdrawal_request_id' : 'reversal_request_id';
        $request = DB::table($family === 'withdrawal' ? 'withdrawal_requests' : 'reversal_requests')->where('id', $source->{$requestIdColumn})->first();
        $latestId = DB::table($definition['source'])->where($requestIdColumn, $source->{$requestIdColumn})->max('id');
        if ($request === null || ! in_array($request->state, ['pending_review', 'approved'], true) || (int) $latestId !== (int) $source->id) {
            return;
        }
        $replacement = DB::table('customer_assignments as a')->join('agent_profiles as ap', 'ap.id', '=', 'a.agent_profile_id')
            ->where('a.customer_profile_id', $intent->customer_profile_id)->where('a.is_current', 1)->first(['ap.user_id']);
        $user = $replacement === null ? null : User::query()->whereKey($replacement->user_id)->first();
        if ($user === null || $user->id === (int) $intent->recipient_user_id || ! $this->eligibility->canReadAssignedCustomers($user)) {
            return;
        }
        if (DB::table('notification_inbox_intents')->where('event_id', $event->id)->where('recipient_user_id', $user->id)->exists()) {
            return;
        }
        $alias = DB::table('notification_inbox_aliases')->where('intent_id', $intent->id)->first();
        $original = $alias === null ? null : DB::table($definition['table'])->where('id', $alias->owner_intent_id)->first();
        if ($original === null) {
            return;
        }
        $values = (array) $original;
        unset($values['id']);
        $values = array_replace($values, ['notification_id' => (string) Str::uuid(), 'recipient_user_id' => $user->id,
            'status' => 'pending', 'delivered_at' => null, 'suppressed_at' => null, 'created_at' => now(), 'updated_at' => now()]);
        $ownerId = DB::table($definition['table'])->insertGetId($values);
        $this->capture($family, $ownerId);
    }

    private function syncOwners(int $intentId, string $status): void
    {
        foreach (DB::table('notification_inbox_aliases')->where('intent_id', $intentId)->get() as $alias) {
            $table = NotificationCatalogue::OWNERS[$alias->family]['table'];
            DB::table($table)->where('id', $alias->owner_intent_id)->update([
                'status' => match ($status) {
                    'delivered' => 'delivered', 'suppressed' => 'suppressed', 'blocked', 'dead_letter' => 'failed', default => 'pending'
                },
                'delivered_at' => $status === 'delivered' ? now() : null,
                'suppressed_at' => $status === 'suppressed' ? now() : null, 'updated_at' => now(),
            ]);
        }
    }
}
