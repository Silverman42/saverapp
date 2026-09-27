<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Jobs\MaterializeNotificationIntent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            $recipient = User::query()->where('id', $intent->recipient_user_id)->first();
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
        if ($attempts > (int) $intent->attempt_count) {
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
    }

    public function recipientScope(User $user, bool $requireAccess = true): Builder
    {
        $query = DB::table('notification_inbox_intents as i')->where('i.recipient_user_id', $user->id);
        if (($requireAccess && $user->account_state !== AccountState::Active)
            || $user->getRoleNames()->count() !== 1 || $user->getRoleNames()->first() !== $user->user_type->value) {
            return $query->whereRaw('1 = 0');
        }
        $canReadAssigned = $user->user_type === UserType::Agent && $this->eligibility->canReadAssignedCustomers($user);
        $canManageAgents = $user->user_type === UserType::Admin && $this->authorization->allows($user, AdminPermission::AgentsManage);
        $canManageSettings = $user->user_type === UserType::Admin && $this->authorization->allows($user, AdminPermission::BusinessSettingsManage);
        $canManageSecurity = $user->user_type === UserType::Admin && $this->authorization->allows($user, AdminPermission::SecurityOperationsManage);

        return $query->where(function (Builder $audiences) use ($user, $canReadAssigned, $canManageAgents, $canManageSecurity, $canManageSettings): void {
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
                                ->join('customer_profiles as cp', 'cp.id', '=', 'a.customer_profile_id')
                                ->whereColumn('a.customer_profile_id', 'i.customer_profile_id')->whereColumn('a.agent_profile_id', 'i.agent_profile_id')
                                ->where('a.is_current', 1)->where('ap.operational_status', 'inactive')->where('cp.operational_status', '!=', 'archived');
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
            if ($canManageAgents) {
                $audiences->orWhereJsonContains('i.audiences', 'managing_admin');
            }
            if ($canManageSettings) {
                $audiences->orWhereJsonContains('i.audiences', 'settings_manager');
            }
            if ($canManageSecurity) {
                $audiences->orWhereJsonContains('i.audiences', 'security_operations_admin');
            }
        });
    }

    /** @param array<string, mixed> $descriptor */
    private function assignmentId(stdClass $owner, array $descriptor): ?int
    {
        if ($descriptor['audience'] !== 'current_agent') {
            return null;
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
