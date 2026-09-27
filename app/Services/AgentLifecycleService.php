<?php

namespace App\Services;

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\AgentStatus;
use App\Enums\CustomerStatus;
use App\Enums\UserType;
use App\Http\Requests\AgentLifecycleRequest;
use App\Jobs\DeliverAgentLifecycleNotificationIntent;
use App\Models\AgentLifecycleHistory;
use App\Models\AgentLifecycleNotificationIntent;
use App\Models\AgentOffboardingCase;
use App\Models\AgentProfile;
use App\Models\AgentStatusHistory;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CustomerAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class AgentLifecycleService extends AgentStatusManagementService
{
    /** @var list<string> */
    public const ACTIONS = ['suspend', 'restore', 'start-offboarding', 'transfer-owner', 'cancel-offboarding', 'complete-offboarding', 'return'];

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function execute(User $actor, AgentProfile $agent, string $action, array $input, Request $request): array
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('Unknown Agent lifecycle action.');
        }
        foreach (['reason', 'agent_explanation'] as $field) {
            $input[$field] = is_string($input[$field] ?? null) ? trim($input[$field]) : ($input[$field] ?? null);
        }
        $input = Validator::make($input, (new AgentLifecycleRequest)->rules())->validate();
        $hash = hash('sha256', json_encode([$actor->id, $agent->id, $action, (int) $input['version'],
            $input['case_id'], $input['case_version'], $input['owner_user_id'] ?? null, $input['reason'], $input['agent_explanation']], JSON_THROW_ON_ERROR));
        try {
            return app(PlatformGuard::class)->transaction('mutation', function () use ($actor, $agent, $action, $input, $hash, $request): array {
                $lockedActor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
                if (! $this->authorizationService->allows($lockedActor, AdminPermission::AgentsManage)) {
                    throw new AuthorizationException('Current Agent management authority is required.');
                }
                $this->ensureFresh($lockedActor, $request);
                $user = User::query()->whereKey($agent->user_id)->lockForUpdate()->firstOrFail();
                $profile = AgentProfile::query()->whereKey($agent->id)->lockForUpdate()->firstOrFail();
                $profile->setRelation('user', $user);
                $operation = DB::table('agent_lifecycle_operations')->where('attempt_reference', $input['attempt_reference'])->lockForUpdate()->first();
                if ($operation !== null) {
                    if ((int) $operation->actor_user_id !== $actor->id || (int) $operation->agent_profile_id !== $agent->id
                        || $operation->action !== $action || ! hash_equals($operation->payload_hash, $hash)) {
                        throw new ConflictHttpException('This operation reference belongs to a different lifecycle request.');
                    }

                    return $this->orderedResult($operation->result);
                }
                $case = AgentOffboardingCase::query()->where('agent_profile_id', $profile->id)->latest('id')->lockForUpdate()->first();
                if ($profile->version !== (int) $input['version']
                    || $case?->id !== ($input['case_id'] === null ? null : (int) $input['case_id'])
                    || $case?->version !== ($input['case_version'] === null ? null : (int) $input['case_version'])) {
                    throw new ConflictHttpException('Agent or offboarding case changed. Refresh and review the action again.');
                }
                $beforeAccount = $user->account_state;
                $beforeStatus = $profile->operational_status;
                $beforeVersion = $profile->version;
                $open = $case !== null && (int) $case->is_open === 1;
                $changed = true;
                $facts = [];
                $access = app(AgentAccountAccessService::class);
                switch ($action) {
                    case 'suspend':
                        if ($user->account_state === AccountState::Deactivated) {
                            throw new ConflictHttpException('Deactivated Agents cannot be suspended.');
                        }
                        $changed = $user->account_state !== AccountState::Suspended;
                        if ($changed) {
                            $user->forceFill(['account_state' => AccountState::Suspended])->save();
                            $access->revoke($user);
                            $this->invalidatePendingNameProposals($user, $lockedActor);
                        }
                        break;
                    case 'restore':
                    case 'return':
                        $expected = $action === 'restore' ? AccountState::Suspended : AccountState::Deactivated;
                        if ($user->account_state !== $expected || $open) {
                            throw new ConflictHttpException('Access restoration is unavailable in this account or offboarding state.');
                        }
                        if ($action === 'return' && ($case === null || $case->status !== 'completed' || $case->completed_at === null)) {
                            throw new ConflictHttpException('Return requires retained completed offboarding evidence.');
                        }
                        $user->forceFill(['account_state' => $access->restorationState($user, true)])->save();
                        $access->revoke($user);
                        if ($action === 'return') {
                            $profile->operational_status = AgentStatus::Inactive;
                        }
                        break;
                    case 'start-offboarding':
                        if ($open || $user->account_state === AccountState::Deactivated) {
                            throw new ConflictHttpException('An open case or deactivated account prevents a new offboarding case.');
                        }
                        $case = new AgentOffboardingCase;
                        $case->forceFill(['agent_profile_id' => $profile->id, 'status' => 'in_progress', 'is_open' => 1, 'version' => 1,
                            'original_account_state' => $beforeAccount->value, 'original_operational_status' => $beforeStatus->value,
                            'initiated_by_user_id' => $lockedActor->id, 'owner_user_id' => $lockedActor->id, 'reason' => $input['reason'],
                            'agent_facing_explanation' => $input['agent_explanation'], 'started_at' => now()])->save();
                        $profile->operational_status = AgentStatus::Inactive;
                        if ($user->account_state !== AccountState::Suspended) {
                            $user->forceFill(['account_state' => AccountState::Suspended])->save();
                        }
                        $access->revoke($user);
                        $this->invalidatePendingNameProposals($user, $lockedActor);
                        break;
                    case 'transfer-owner':
                    case 'cancel-offboarding':
                    case 'complete-offboarding':
                        if (! $open || $case->status !== 'in_progress' || $user->account_state !== AccountState::Suspended || $profile->operational_status !== AgentStatus::Inactive) {
                            throw new ConflictHttpException('This action requires an open case with Suspended access and Inactive readiness.');
                        }
                        if ($action === 'transfer-owner') {
                            $owner = User::query()->whereKey($input['owner_user_id'] ?? 0)->lockForUpdate()->first();
                            if ($owner === null || ! $this->authorizationService->allows($owner, AdminPermission::AgentsManage)) {
                                throw ValidationException::withMessages(['owner_user_id' => ['Choose an active Admin with Agent management authority.']]);
                            }
                            $changed = (int) $case->owner_user_id !== $owner->id;
                            $facts = ['previous_owner_user_id' => $case->owner_user_id, 'owner_user_id' => $owner->id];
                            $case->forceFill(['owner_user_id' => $owner->id]);
                        } elseif ($action === 'cancel-offboarding') {
                            $case->forceFill(['status' => 'cancelled', 'is_open' => null, 'cancelled_at' => now()]);
                        } else {
                            $gates = app(AgentOffboardingEligibility::class)->preview($lockedActor, $profile, $case, true);
                            if (! $gates['eligible']) {
                                throw ValidationException::withMessages(['lifecycle' => ['Completion is blocked until every authoritative offboarding gate passes.']]);
                            }
                            $user->forceFill(['account_state' => AccountState::Deactivated])->save();
                            $access->revoke($user);
                            $case->forceFill(['status' => 'completed', 'is_open' => null, 'completed_at' => now()]);
                        }
                        if ($changed) {
                            $case->version++;
                            $case->save();
                        }
                        break;
                }
                $historyId = null;
                if ($changed) {
                    $profile->forceFill(['version' => $beforeVersion + 1, 'updated_by_user_id' => $lockedActor->id])->save();
                    $eventType = 'agent.'.str_replace('-', '_', $action);
                    $audit = AuditEvent::record($eventType, 'agent', $profile->id, $profile->agent_id,
                        ['from_account_state' => $beforeAccount->value, 'account_state' => $user->account_state->value,
                            'from_status' => $beforeStatus->value, 'to_status' => $profile->operational_status->value,
                            'from_version' => $beforeVersion, 'to_version' => $profile->version, 'case_id' => $case?->id,
                            'case_version' => $case?->version, 'operation_id' => $input['attempt_reference'], 'outcome' => 'succeeded',
                            'reason' => $input['reason'], ...$facts], $lockedActor,
                        ['executor' => self::class, 'required_permission' => 'agents.manage', 'fresh_authentication' => true]);
                    if ($beforeStatus !== $profile->operational_status) {
                        AgentStatusHistory::create([
                            'agent_profile_id' => $profile->id, 'from_status' => $beforeStatus->value,
                            'to_status' => $profile->operational_status->value, 'reason' => $input['reason'],
                            'agent_facing_explanation' => $input['agent_explanation'],
                            'changed_by_user_id' => $lockedActor->id, 'audit_event_id' => $audit->id,
                            'created_at' => now(),
                        ]);
                    }
                    $history = new AgentLifecycleHistory;
                    $history->forceFill(['agent_profile_id' => $profile->id, 'agent_offboarding_case_id' => $case?->id,
                        'actor_user_id' => $lockedActor->id, 'audit_event_id' => $audit->id, 'event_type' => $eventType,
                        'from_account_state' => $beforeAccount->value, 'to_account_state' => $user->account_state->value,
                        'from_operational_status' => $beforeStatus->value, 'to_operational_status' => $profile->operational_status->value,
                        'from_version' => $beforeVersion, 'to_version' => $profile->version, 'reason' => $input['reason'],
                        'agent_facing_explanation' => $input['agent_explanation'], 'facts' => $facts, 'created_at' => now()])->save();
                    $historyId = $history->id;
                    $this->lifecycleNotices($profile, $history, in_array($action, ['suspend', 'start-offboarding'], true));
                }
                $result = ['attempt_reference' => $input['attempt_reference'], 'action' => $action, 'version' => $profile->version,
                    'account_state' => $user->account_state->value, 'operational_status' => $profile->operational_status->value,
                    'case_id' => $case?->id, 'case_version' => $case?->version, 'case_status' => $case?->status,
                    'history_id' => $historyId, 'outcome' => $changed ? 'succeeded' : 'no_op'];
                ksort($result);
                DB::table('agent_lifecycle_operations')->insert(['attempt_reference' => $input['attempt_reference'], 'actor_user_id' => $lockedActor->id,
                    'agent_profile_id' => $profile->id, 'action' => $action, 'payload_hash' => $hash,
                    'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now()]);

                return $result;
            }, attempts: 3);
        } catch (AuthorizationException|ConflictHttpException|ValidationException $exception) {
            AuditEvent::record('agent.lifecycle_denied', 'agent', $agent->id, $agent->agent_id,
                ['operation_id' => $input['attempt_reference'], 'changed_fields' => [str_replace('-', '_', $action)], 'outcome' => 'denied'], $actor,
                ['executor' => self::class, 'required_permission' => 'agents.manage']);
            throw $exception;
        }
    }

    /** @return array<string, mixed>|null */
    public function lookup(User $actor, AgentProfile $agent, string $reference): ?array
    {
        Gate::forUser($actor)->authorize('manage', $agent);
        $operation = DB::table('agent_lifecycle_operations')->where('attempt_reference', $reference)
            ->where('agent_profile_id', $agent->id)->where('actor_user_id', $actor->id)->first();

        return $operation === null ? null : $this->orderedResult($operation->result);
    }

    /** @return array<string, mixed> */
    private function orderedResult(string $json): array
    {
        $result = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        ksort($result);

        return $result;
    }

    private function ensureFresh(User $actor, Request $request): void
    {
        $passwordAt = (int) $request->session()->get('auth.password_confirmed_at', 0);
        $mfaAt = (int) $request->session()->get('auth.mfa_confirmed_at', 0);
        if ($mfaAt < 1 || $mfaAt > now()->timestamp || $request->user()?->id !== $actor->id || $passwordAt < 1 || $passwordAt > now()->timestamp
            || ! $actor->hasConfirmedTwoFactor() || ! app(FreshAuthenticationService::class)->isFresh($actor, $request)) {
            throw ValidationException::withMessages(['lifecycle' => ['Fresh password and authenticator verification is required.']]);
        }
    }

    private function lifecycleNotices(AgentProfile $agent, AgentLifecycleHistory $history, bool $serviceInterrupted): void
    {
        $this->lifecycleIntent($agent, $history, $agent->user, 'subject_agent', 'mail', 'Your Agent account was updated',
            'Account access is '.$agent->user->account_state->value.'. '.$history->agent_facing_explanation);
        $this->lifecycleIntent($agent, $history, $agent->user, 'subject_agent', 'database', 'Agent account updated',
            'Account access and operational readiness were reviewed. '.$history->agent_facing_explanation);
        User::query()->where('user_type', UserType::Admin)->where('account_state', AccountState::Active)->orderBy('id')->each(function (User $admin) use ($agent, $history): void {
            if ($this->authorizationService->allows($admin, AdminPermission::AgentsManage)) {
                $this->lifecycleIntent($agent, $history, $admin, 'managing_admin', 'database', 'Agent lifecycle updated', 'An Agent lifecycle action was recorded. Review the management workspace.');
            }
        });
        if (! $serviceInterrupted) {
            return;
        }
        $business = BusinessProfile::current();
        $contact = $business->support_email ?: $business->support_phone;
        $message = 'Your assigned Agent is temporarily unavailable. Your Customer status and savings are unchanged. '.($contact ? 'For help, contact '.$contact.'.' : 'Contact the business office for help.');
        CustomerAssignment::query()->where('agent_profile_id', $agent->id)->where('is_current', 1)->with('customerProfile.user')->orderBy('id')
            ->each(function (CustomerAssignment $assignment) use ($agent, $history, $message): void {
                $customer = $assignment->customerProfile;
                if ($customer->operational_status === CustomerStatus::Archived) {
                    return;
                }
                foreach (['mail', 'database'] as $channel) {
                    $this->lifecycleIntent($agent, $history, $customer->user, 'assigned_customer', $channel, 'Service contact unavailable', $message, $customer->id);
                }
            });
    }

    private function lifecycleIntent(AgentProfile $agent, AgentLifecycleHistory $history, User $recipient, string $audience, string $channel, string $title, string $message, ?int $customerId = null): void
    {
        $intent = new AgentLifecycleNotificationIntent;
        $intent->forceFill(['notification_id' => (string) Str::uuid(), 'agent_lifecycle_history_id' => $history->id,
            'recipient_user_id' => $recipient->id, 'agent_profile_id' => $agent->id, 'customer_profile_id' => $customerId,
            'audience_type' => $audience, 'channel' => $channel, 'status' => 'pending',
            'payload' => ['title' => $title, 'message' => $message, 'status' => $agent->user->account_state->value,
                'effective_at' => $history->created_at->toIso8601String()]])->save();
        if ($channel === 'database') {
            app(NotificationPipeline::class)->capture('agent_lifecycle', $intent->id);
        } else {
            DB::afterCommit(static function () use ($intent): void {
                try {
                    DeliverAgentLifecycleNotificationIntent::dispatch($intent->id)->afterCommit();
                } catch (\Throwable) {
                    AgentLifecycleNotificationIntent::query()->whereKey($intent->id)->update(['status' => 'failed', 'failure_reason' => 'Queue dispatch unavailable.']);
                }
            });
        }
    }
}
