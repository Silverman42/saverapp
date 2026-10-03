<?php

namespace App\Services;

use App\Enums\AgentEligibilityCapability;
use App\Enums\CustomerAssignmentStatus;
use App\Http\Requests\CustomerReassignmentRequest;
use App\Models\AgentProfile;
use App\Models\CustomerAssignment;
use App\Models\CustomerNameCorrection;
use App\Models\CustomerProfile;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class CustomerReassignmentService
{
    public function __construct(private CustomerActionAuthorizationGuard $guard, private AgentEligibilityService $eligibility,
        private CustomerHandoverNotifications $notices, private CustomerRecoveryService $recovery) {}

    /** @return array<string, mixed> */
    public function preview(User $actor, CustomerProfile $customer, int $targetId): array
    {
        return DB::transaction(function () use ($actor, $customer, $targetId): array {
            $context = $this->guard->lockAndAuthorize($actor, $customer->id, 'reassign');
            if ($context->currentAssignment?->agent_profile_id !== $targetId) {
                $target = AgentProfile::query()->whereKey($targetId)->lockForUpdate()->first();
                $this->validateTarget($target);
            }
            $profile = $context->customerProfile;
            if ($context->currentAssignment === null) {
                throw new ConflictHttpException('The current assignment is unavailable.');
            }
            $snapshot = $this->ownerState($profile);
            $binding = [$context->actor->id, $context->actor->permission_version, $profile->id, $profile->version,
                $context->currentAssignment->version, $targetId, hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))];

            return ['version' => $profile->version, 'assignment_version' => $context->currentAssignment->version,
                'preview_token' => Crypt::encryptString(json_encode(['binding' => $binding, 'expires' => now()->addMinutes(10)->timestamp], JSON_THROW_ON_ERROR)),
                'pending_withdrawals' => count($snapshot['withdrawals']), 'pending_reversals' => count($snapshot['reversals']),
                'pending_recovery' => $snapshot['recoveries'] !== [], 'name_proposals' => count($snapshot['names']),
                'message' => 'Service responsibility transfers. Customer status, account access, savings, reservations, plan terms and original cash responsibility remain unchanged.'];
        });
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function execute(User $actor, CustomerProfile $customer, array $data): array
    {
        foreach (['reason', 'customer_explanation'] as $key) {
            $data[$key] = trim((string) ($data[$key] ?? ''));
        }
        $data = Validator::make($data, (new CustomerReassignmentRequest)->rules())->validate();
        $hash = hash('sha256', json_encode([$actor->id, $customer->id, $data], JSON_THROW_ON_ERROR));

        return app(PlatformGuard::class)->transaction('mutation', function () use ($actor, $customer, $data, $hash): array {
            $context = $this->guard->lockAndAuthorize($actor, $customer->id, 'reassign');
            $previous = DB::table('customer_handover_operations')->where('attempt_reference', $data['attempt_reference'])->lockForUpdate()->first();
            if ($previous !== null) {
                if ($previous->actor_user_id !== $actor->id || $previous->customer_profile_id !== $customer->id || ! hash_equals($previous->payload_hash, $hash)) {
                    throw new ConflictHttpException('This operation reference belongs to different input.');
                }

                $result = json_decode($previous->result, true, flags: JSON_THROW_ON_ERROR);
                ksort($result);

                return $result;
            }
            $context = $this->guard->lockAndAuthorize($actor, $customer->id, 'reassign', (int) $data['version'],
                (int) $data['assignment_version']);
            $target = AgentProfile::query()->whereKey($data['target_agent_id'])->lockForUpdate()->firstOrFail();
            if ($context->currentAssignment?->agent_profile_id !== $target->id) {
                $this->validateTarget($target);
            }
            $profile = $context->customerProfile;
            $old = $context->currentAssignment;
            $snapshot = $this->ownerState($profile);
            $binding = [$context->actor->id, $context->actor->permission_version, $profile->id, $profile->version,
                $old->version, $target->id, hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))];
            try {
                $preview = json_decode(Crypt::decryptString($data['preview_token']), true, flags: JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                throw new ConflictHttpException('The handover preview is invalid. Refresh and review again.');
            }
            if (($preview['expires'] ?? 0) < now()->timestamp || ($preview['binding'] ?? null) !== $binding) {
                throw new ConflictHttpException('The handover changed. Refresh and review again.');
            }
            if ($old->agent_profile_id === $target->id) {
                $result = ['status' => 'unchanged', 'version' => $profile->version, 'assignment_version' => $old->version,
                    'attempt_reference' => $data['attempt_reference']];
            } else {
                $effective = now();
                $old->forceFill(['status' => CustomerAssignmentStatus::Ended, 'is_current' => null, 'ended_at' => $effective])->save();
                $assignment = CustomerAssignment::create(['customer_profile_id' => $profile->id, 'agent_profile_id' => $target->id,
                    'assigned_by_user_id' => $actor->id, 'reason' => $data['reason'], 'status' => CustomerAssignmentStatus::Current,
                    'effective_at' => $effective, 'version' => $old->version + 1]);
                $profile->forceFill(['version' => $profile->version + 1, 'updated_by_user_id' => $actor->id])->save();
                $profile->setRelation('currentAssignment', $assignment);
                $cancelled = [];
                foreach (CustomerNameCorrection::query()->where('customer_profile_id', $profile->id)->where('status', 'pending')
                    ->where('requested_by_user_id', $context->currentAgentProfile->user_id)->lockForUpdate()->get() as $proposal) {
                    $proposal->forceFill(['status' => 'cancelled', 'resolved_by_user_id' => $actor->id, 'resolved_at' => $effective])->save();
                    $cancelled[] = $proposal->id;
                    app(CustomerNameCorrectionService::class)->recordProposalOutcome($profile, $context->actor, $proposal, 'customer.name_correction_cancelled', 'customers.reassign');
                }
                $this->recovery->handover($profile, $context->actor);
                $eventId = $this->notices->event($profile, $context->actor, 'customer.reassigned', $profile->version,
                    ['previous_assignment_id' => $old->id, 'assignment_id' => $assignment->id, 'source_agent_id' => $old->agent_profile_id,
                        'target_agent_id' => $target->id, 'reason' => $data['reason'], 'customer_explanation' => $data['customer_explanation'],
                        'cancelled_proposals' => $cancelled, 'owner_digest' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)), 'pending_withdrawals' => count($snapshot['withdrawals']), 'pending_reversals' => count($snapshot['reversals'])]);
                foreach (DB::table('invitation_delivery_issues as issue')->join('invitations as invitation', 'invitation.id', '=', 'issue.invitation_id')->where('invitation.user_id', $profile->user_id)->where('invitation.status', 'delivery_failed')->get(['issue.id', 'issue.invitation_id']) as $issue) {
                    app(InvitationDeliveryIssues::class)->route((int) $issue->id, Invitation::query()->whereKey($issue->invitation_id)->firstOrFail());
                }
                $message = $data['customer_explanation'].' Your service contact is '.$target->user->name.'. Customer status and savings are unchanged.';
                foreach (['database', 'mail'] as $channel) {
                    $this->notices->notice($eventId, $profile->user, 'subject_customer', $channel, 'reassigned', $profile,
                        ['title' => 'Your service contact changed', 'message' => $message]);
                }
                $this->notices->notice($eventId, $target->user, 'current_agent', 'database', 'reassigned', $profile,
                    ['title' => 'Customer assigned', 'message' => 'Review current service work through the Customer profile.']);
                foreach (['database', 'mail'] as $channel) {
                    $this->notices->notice($eventId, $context->currentAgentProfile->user, 'subject_agent', $channel, 'assignment_removed', null,
                        ['title' => 'Assignment access removed', 'message' => 'Assignment access was removed at '.$effective->toIso8601String().'. Event '.$eventId.'.'], $old->agent_profile_id);
                }
                $result = ['status' => 'committed', 'version' => $profile->version, 'assignment_version' => $assignment->version,
                    'event_id' => $eventId, 'attempt_reference' => $data['attempt_reference']];
            }
            ksort($result);
            DB::table('customer_handover_operations')->insert(['attempt_reference' => $data['attempt_reference'], 'actor_user_id' => $actor->id,
                'customer_profile_id' => $profile->id, 'action' => 'reassign', 'payload_hash' => $hash, 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $result;
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    public function lookup(User $actor, CustomerProfile $customer, string $reference): array
    {
        Gate::forUser($actor)->authorize('reassign', $customer);
        $operation = DB::table('customer_handover_operations')->where('attempt_reference', $reference)
            ->where('actor_user_id', $actor->id)->where('customer_profile_id', $customer->id)->where('action', 'reassign')->first();
        abort_if($operation === null, 404, 'Operation unavailable.');

        $result = json_decode($operation->result, true, flags: JSON_THROW_ON_ERROR);
        ksort($result);

        return $result;
    }

    /** @return array<string, mixed> */
    private function ownerState(CustomerProfile $customer): array
    {
        $tables = ['withdrawals' => ['withdrawal_requests', ['pending_review', 'approved']],
            'reversals' => ['reversal_requests', ['pending_review']],
            'recoveries' => ['customer_recoveries', ['verification_required', 'awaiting_approval', 'awaiting_activation', 'activation_expired']],
            'names' => ['customer_name_corrections', ['pending']]];
        $state = ['withdrawals' => [], 'reversals' => [], 'recoveries' => [], 'names' => [], 'reservations' => []];
        $allowed = ['withdrawals' => ['pending_review', 'approved', 'posted', 'rejected', 'cancelled', 'expired'],
            'reversals' => ['pending_review', 'approved_posted', 'approved_no_money', 'rejected', 'cancelled'],
            'recoveries' => ['verification_required', 'awaiting_approval', 'awaiting_activation', 'activation_expired', 'completed', 'rejected', 'cancelled', 'expired'],
            'names' => ['pending', 'accepted', 'rejected', 'cancelled', 'expired', 'invalidated', 'replaced']];
        foreach ($tables as $key => [$table, $states]) {
            $column = $key === 'names' ? 'status' : 'state';
            if (DB::table($table)->where('customer_profile_id', $customer->id)->whereNotIn($column, $allowed[$key])->exists()) {
                throw new ConflictHttpException('A required handover owner is unavailable.');
            }
            $state[$key] = DB::table($table)->where('customer_profile_id', $customer->id)->whereIn($column, $states)
                ->orderBy('id')->lockForUpdate()->get()->map(fn ($row): array => (array) $row)->all();
        }
        foreach ($state['withdrawals'] as $withdrawal) {
            $reservation = DB::table('withdrawal_reservations')->where('id', $withdrawal['withdrawal_reservation_id'])->lockForUpdate()->first();
            if ($reservation === null || $reservation->status !== 'live' || $reservation->owner_reference !== $withdrawal['withdrawal_id']
                || (int) $reservation->customer_profile_id !== $customer->id || (int) $reservation->gross_amount_kobo !== $withdrawal['gross_amount_kobo']) {
                throw new ConflictHttpException('A required withdrawal handover owner is unavailable.');
            }
            $state['reservations'][] = (array) $reservation;
        }
        $state['plans'] = DB::table('thrift_plans')->where('customer_profile_id', $customer->id)->orderBy('id')->lockForUpdate()->get(['id', 'version', 'status'])->all();
        $state['receipts'] = DB::table('collection_receipts')->where('customer_profile_id', $customer->id)->max('id');
        $state['invitations'] = DB::table('invitations')->where('user_id', $customer->user_id)->orderBy('id')->lockForUpdate()->get(['id', 'status', 'expires_at'])->all();

        return $state;
    }

    public function hasVerifiedHandover(CustomerProfile $customer, int $sourceAgentId, bool $forUpdate = false): bool
    {
        $assignment = CustomerAssignment::query()->where('customer_profile_id', $customer->id)->where('is_current', 1)
            ->when($forUpdate, fn ($q) => $q->lockForUpdate())->first();
        if ($assignment === null || $assignment->agent_profile_id === $sourceAgentId) {
            return false;
        }
        $event = DB::table('customer_handover_events')->where('customer_profile_id', $customer->id)->where('event_type', 'customer.reassigned')
            ->orderByDesc('id')->when($forUpdate, fn ($q) => $q->lockForUpdate())->first();
        if ($event === null) {
            return false;
        }
        $details = json_decode(Crypt::decryptString($event->details), true, flags: JSON_THROW_ON_ERROR);
        $agent = AgentProfile::query()->whereKey($assignment->agent_profile_id)->when($forUpdate, fn ($q) => $q->lockForUpdate())->first();

        return ($details['assignment_id'] ?? null) === $assignment->id && $agent !== null
            && $this->eligibility->canReceiveAssignment($agent->user);
    }

    private function validateTarget(?AgentProfile $target): void
    {
        if ($target === null) {
            throw new ConflictHttpException('Replacement Agent unavailable.');
        }
        $target->setRelation('user', User::query()->whereKey($target->user_id)->lockForUpdate()->firstOrFail());
        if (! $this->eligibility->evaluate($target, AgentEligibilityCapability::ReceiveAssignment)->isEligible()) {
            throw new ConflictHttpException('Replacement Agent is no longer eligible.');
        }
    }
}
