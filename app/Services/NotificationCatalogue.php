<?php

namespace App\Services;

use App\Models\BusinessProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use stdClass;

class NotificationCatalogue
{
    public const VERSION = 1;

    /** @var array<string, list<string>> */
    private const EVENTS = [
        'business_settings' => ['published', 'effective', 'cancelled', 'activation_failed'],
        'security' => ['security.case_created', 'security.case_assigned', 'security.case_reopened'],
        'profile' => ['customer.profile_updated', 'agent.profile_updated', 'customer.phone_changed', 'agent.phone_changed',
            'customer.name_changed', 'customer.name_correction_proposed', 'customer.name_correction_accepted',
            'customer.name_correction_rejected', 'customer.name_correction_cancelled', 'customer.name_correction_expired',
            'customer.name_correction_invalidated', 'user.email_changed'],
        'customer_status' => ['customer_status'], 'agent_status' => ['agent_status'],
        'plan' => ['created', 'renewed', 'terms_amended', 'details_corrected', 'pause', 'resume', 'cancel', 'complete', 'close', 'completion_corrected'],
        'collection' => ['collection'], 'withdrawal' => ['submitted', 'approve', 'reject', 'cancel', 'revoke', 'expired', 'hold_applied', 'hold_lifted'],
        'reversal' => ['submitted', 'approved_posted', 'rejected', 'cancelled'],
    ];

    /** @var array<string, array{table: string, source: string, key: string}> */
    public const OWNERS = [
        'business_settings' => ['table' => 'business_settings_notification_intents', 'source' => 'business_configuration_events', 'key' => 'business_configuration_event_id'],
        'security' => ['table' => 'security_notification_intents', 'source' => 'security_case_transitions', 'key' => 'security_case_transition_id'],
        'profile' => ['table' => 'profile_notification_intents', 'source' => 'profile_change_histories', 'key' => 'profile_change_history_id'],
        'customer_status' => ['table' => 'customer_status_notification_intents', 'source' => 'customer_status_histories', 'key' => 'customer_status_history_id'],
        'agent_status' => ['table' => 'agent_status_notification_intents', 'source' => 'agent_status_histories', 'key' => 'agent_status_history_id'],
        'plan' => ['table' => 'plan_notification_intents', 'source' => 'plan_lifecycle_events', 'key' => 'plan_lifecycle_event_id'],
        'collection' => ['table' => 'collection_notification_intents', 'source' => 'collection_receipts', 'key' => 'collection_receipt_id'],
        'withdrawal' => ['table' => 'withdrawal_notification_intents', 'source' => 'withdrawal_events', 'key' => 'withdrawal_event_id'],
        'reversal' => ['table' => 'reversal_notification_intents', 'source' => 'reversal_events', 'key' => 'reversal_event_id'],
    ];

    /** @return array<string, mixed> */
    public function describe(string $family, stdClass $owner): array
    {
        $definition = self::OWNERS[$family] ?? throw new InvalidArgumentException('Unknown notification family.');
        $source = DB::table($definition['source'])->where('id', $owner->{$definition['key']})->first();
        if ($source === null) {
            throw new InvalidArgumentException('Notification source is unavailable.');
        }
        $audience = $owner->audience_type ?? 'subject_customer';
        $customerId = $owner->customer_profile_id ?? null;
        $agentId = $owner->agent_profile_id ?? null;
        $facts = [];
        $destination = null;
        $reference = null;
        $category = 'account';
        $actionRequired = false;
        $actionCorrectionId = null;
        $eventType = $source->event_type ?? $family;
        $sourceVersion = $source->request_version ?? $source->plan_version ?? $source->to_version ?? 1;

        switch ($family) {
            case 'business_settings':
                if ($audience !== 'settings_manager' || ! in_array($eventType, self::EVENTS['business_settings'], true)) {
                    throw new InvalidArgumentException('Invalid configuration notification contract.');
                }
                $sourceVersion = (int) $source->version;
                $title = 'Business configuration '.str_replace('_', ' ', $eventType);
                $summary = 'Configuration version '.$sourceVersion.' is '.str_replace('_', ' ', $eventType).'. Review the settings workspace for effective values and readiness.';
                $reference = 'CFG-'.$sourceVersion;
                $destination = ['route' => 'admin.business-settings.index', 'parameters' => []];
                $category = 'account';
                break;
            case 'security':
                $case = DB::table('security_cases')->where('id', $source->security_case_id)->first();
                if ($case === null || $audience !== 'security_operations_admin') {
                    throw new InvalidArgumentException('Invalid security notification source.');
                }
                $recordedFacts = json_decode($source->facts, true, flags: JSON_THROW_ON_ERROR);
                $sourceVersion = $source->version;
                $facts = ['severity' => $recordedFacts['severity']];
                $title = 'Security case requires review';
                $summary = 'A security case is available to authorized responders. Open the case to review the next step.';
                $reference = $case->case_reference;
                $destination = ['route' => 'admin.security.show', 'parameters' => [$reference]];
                $actionRequired = true;
                break;
            case 'profile':
                $titles = [
                    'customer.profile_updated' => 'Customer profile updated', 'agent.profile_updated' => 'Agent profile updated',
                    'customer.phone_changed' => 'Customer phone updated', 'agent.phone_changed' => 'Agent phone updated',
                    'customer.name_changed' => 'Customer name updated', 'customer.name_correction_proposed' => 'Name correction proposed',
                    'customer.name_correction_accepted' => 'Name correction accepted', 'customer.name_correction_rejected' => 'Name correction rejected',
                    'customer.name_correction_cancelled' => 'Name correction cancelled', 'customer.name_correction_expired' => 'Name correction expired',
                    'customer.name_correction_invalidated' => 'Name correction unavailable', 'user.email_changed' => 'Account email updated',
                ];
                $title = $titles[$eventType] ?? throw new InvalidArgumentException('Unknown profile event.');
                if (! in_array($audience, ['subject_customer', 'subject_agent', 'current_agent', 'security_operations_admin'], true)
                    || ! in_array($owner->subject_type, ['customer', 'agent'], true)) {
                    throw new InvalidArgumentException('Unknown profile audience.');
                }
                $subject = DB::table($owner->subject_type.'_profiles')->where('id', $owner->subject_id)->first();
                if ($subject === null || ($source->subject_user_id === null || (int) $source->subject_user_id !== (int) $subject->user_id)) {
                    throw new InvalidArgumentException('Invalid profile relationship.');
                }
                $customerId = $owner->subject_type === 'customer' ? $subject->id : null;
                $agentId = $owner->subject_type === 'agent' ? $subject->id : null;
                if (($customerId === null && in_array($audience, ['subject_customer', 'current_agent'], true))
                    || ($agentId === null && in_array($audience, ['subject_agent', 'security_operations_admin'], true))) {
                    throw new InvalidArgumentException('Invalid profile audience.');
                }
                $reference = $subject->{$owner->subject_type.'_id'};
                $summary = 'A permitted account profile change was recorded. Open the current profile to review available information.';
                $destination = ['route' => $customerId !== null ? 'customers.show' : 'agents.show', 'parameters' => [$reference]];
                if ($eventType === 'customer.name_correction_proposed') {
                    $correction = DB::table('customer_name_corrections')->where('profile_change_history_id', $source->id)->first();
                    $actionCorrectionId = $correction?->id;
                    $actionRequired = $correction !== null;
                }
                break;
            case 'customer_status':
                $customerId = $source->customer_profile_id;
                $this->matchSubject($owner->customer_profile_id, $customerId);
                $this->audience($audience, ['subject_customer', 'current_agent']);
                $status = $this->state($source->to_status, ['active', 'inactive', 'restricted', 'archived']);
                $facts = ['status' => $status];
                $title = 'Customer status updated';
                $summary = 'Customer operational status changed to '.ucfirst($status).'. '.match ($status) {
                    'active' => 'Eligible activity remains subject to account, Agent and financial controls.',
                    'inactive' => 'New plans and contributions are paused. Existing savings use approved settlement workflows.',
                    'restricted' => 'New savings activity and payouts are held. Authorized reads and corrective reviews remain available.',
                    'archived' => 'Operational activity is unavailable.',
                    default => throw new InvalidArgumentException('Unknown Customer status.'),
                };
                if (is_string($source->customer_facing_explanation) && $source->customer_facing_explanation !== '') {
                    $summary .= ' '.mb_substr($source->customer_facing_explanation, 0, 500);
                }
                break;
            case 'agent_status':
                $agentId = $source->agent_profile_id;
                $this->matchSubject($owner->agent_profile_id, $agentId);
                $this->audience($audience, ['subject_agent', 'managing_admin', 'assigned_customer']);
                $status = $this->state($source->to_status, ['active', 'inactive']);
                $facts = ['status' => $status];
                $title = $audience === 'assigned_customer' ? 'Service contact unavailable' : 'Agent status updated';
                $summary = $audience === 'assigned_customer'
                    ? 'Your assigned Agent is temporarily unavailable. Your Customer status and savings are unchanged. Contact the business office for help.'
                    : 'Agent operational status changed to '.ucfirst($status).'. Account access and operational readiness remain separate.';
                if ($audience === 'subject_agent' && is_string($source->agent_facing_explanation) && $source->agent_facing_explanation !== '') {
                    $summary .= ' '.mb_substr($source->agent_facing_explanation, 0, 500);
                }
                if ($audience === 'assigned_customer' && ($customerId === null || $status !== 'inactive')) {
                    throw new InvalidArgumentException('Invalid service notice.');
                }
                break;
            case 'plan':
                $plan = DB::table('thrift_plans')->where('id', $source->thrift_plan_id)->first();
                if ($plan === null || (int) $owner->thrift_plan_id !== (int) $plan->id) {
                    throw new InvalidArgumentException('Invalid plan relationship.');
                }
                $customerId = $plan->customer_profile_id;
                $this->matchSubject($owner->customer_profile_id, $customerId);
                $this->audience($audience, ['subject_customer', 'current_agent']);
                $messages = [
                    'created' => 'A daily thrift plan was created.', 'renewed' => 'A new daily thrift plan was created from a cancelled plan.',
                    'terms_amended' => 'Your daily thrift plan terms were amended.', 'details_corrected' => 'Customer-visible plan details were corrected.',
                    'pause' => 'Your daily thrift plan was paused.', 'resume' => 'Your daily thrift plan was resumed.',
                    'cancel' => 'Your daily thrift plan was cancelled.', 'complete' => 'Your daily thrift plan was completed.',
                    'close' => 'Your daily thrift plan was closed.', 'completion_corrected' => 'Your plan completion was corrected after an authorized correction.',
                ];
                $summary = $messages[$eventType] ?? throw new InvalidArgumentException('Unknown plan event.');
                $title = 'Thrift plan updated';
                $category = 'plan';
                $reference = $plan->plan_id;
                $destination = ['route' => 'plans.show', 'parameters' => [$reference]];
                break;
            case 'collection':
                $customerId = $source->customer_profile_id;
                $this->audience($audience, ['subject_customer']);
                foreach (['savings_amount_kobo', 'fee_amount_kobo', 'tender_amount_kobo'] as $key) {
                    if (! is_numeric($source->{$key}) || (int) $source->{$key} < 0) {
                        throw new InvalidArgumentException('Invalid receipt amount.');
                    }
                    $facts[$key] = (int) $source->{$key};
                }
                if ($facts['tender_amount_kobo'] !== $facts['savings_amount_kobo'] + $facts['fee_amount_kobo']) {
                    throw new InvalidArgumentException('Inconsistent receipt components.');
                }
                $title = 'Collection recorded';
                $summary = 'Savings: '.$this->money($facts['savings_amount_kobo']).'; external fee: '.$this->money($facts['fee_amount_kobo']).'; total tender: '.$this->money($facts['tender_amount_kobo']).'.';
                $category = 'financial';
                $reference = $source->receipt_reference;
                $destination = ['route' => 'collections.show', 'parameters' => [$reference]];
                break;
            case 'withdrawal':
                $request = DB::table('withdrawal_requests')->where('id', $source->withdrawal_request_id)->first();
                if ($request === null) {
                    throw new InvalidArgumentException('Missing withdrawal.');
                }
                $customerId = $request->customer_profile_id;
                $this->matchSubject($owner->customer_profile_id, $customerId);
                $this->audience($audience, ['subject_customer', 'current_agent']);
                $messages = [
                    'submitted' => 'A withdrawal request was submitted for review. Savings are reserved; no payout has been made.',
                    'approve' => 'A withdrawal request was approved. No payout has been made yet.',
                    'reject' => 'A withdrawal request was rejected and its reservation released.',
                    'cancel' => 'A withdrawal request was cancelled and its reservation released.',
                    'revoke' => 'A withdrawal approval was revoked and its reservation released.',
                    'expired' => 'A withdrawal request expired and its reservation released.',
                    'hold_applied' => 'A withdrawal request is on hold. No payout can proceed.',
                    'hold_lifted' => 'A withdrawal hold was lifted. Normal approval and payout controls still apply.',
                ];
                $summary = $messages[$eventType] ?? throw new InvalidArgumentException('Unknown withdrawal event.');
                $title = 'Withdrawal updated';
                $facts = ['state' => $source->to_state];
                $category = 'financial';
                $reference = $request->withdrawal_id;
                $destination = ['route' => 'withdrawals.show', 'parameters' => [$reference]];
                break;
            case 'reversal':
                $request = DB::table('reversal_requests')->where('id', $source->reversal_request_id)->first();
                if ($request === null) {
                    throw new InvalidArgumentException('Missing correction.');
                }
                $customerId = $request->customer_profile_id;
                $this->matchSubject($owner->customer_profile_id, $customerId);
                $this->audience($audience, ['subject_customer', 'current_agent']);
                if ($audience === 'subject_customer' && $eventType !== 'approved_posted') {
                    throw new InvalidArgumentException('Customer correction notice is not enabled.');
                }
                $messages = [
                    'submitted' => 'A correction request was submitted for review. The original transaction remains effective.',
                    'approved_posted' => 'A reviewed correction was posted. Open the linked account record to review its effect.',
                    'rejected' => 'A correction request was rejected. The original transaction remains effective.',
                    'cancelled' => 'A correction request was cancelled. The original transaction remains effective.',
                ];
                $summary = $messages[$eventType] ?? throw new InvalidArgumentException('Unknown correction event.');
                $title = 'Financial correction updated';
                $category = 'financial';
                $reference = $request->reversal_id;
                $destination = ['route' => 'reversals.show', 'parameters' => [$reference]];
                break;
            default:
                throw new InvalidArgumentException('Unknown notification family.');
        }
        if ($destination === null) {
            $subject = DB::table($customerId !== null ? 'customer_profiles' : 'agent_profiles')->where('id', $customerId ?? $agentId)->first();
            if ($subject === null) {
                throw new InvalidArgumentException('Missing notification subject.');
            }
            $reference = $subject->{$customerId !== null ? 'customer_id' : 'agent_id'};
            $destination = ['route' => $customerId !== null ? 'customers.show' : 'agents.show', 'parameters' => [$reference]];
        }
        $effectiveAt = CarbonImmutable::parse($source->effective_at ?? $source->recorded_at ?? $source->created_at, 'UTC');

        return [
            'source_id' => (int) $source->id, 'source_version' => (int) $sourceVersion,
            'event_type' => $eventType, 'facts' => $facts, 'audit_event_id' => $source->audit_event_id ?? null,
            'effective_at' => $effectiveAt, 'customer_profile_id' => $customerId, 'agent_profile_id' => $agentId,
            'audience' => $audience, 'category' => $category, 'title' => $title, 'summary' => $summary,
            'reference' => $reference, 'destination' => $destination, 'action_required' => $actionRequired, 'action_correction_id' => $actionCorrectionId,
            'timezone' => $source->timezone ?? ($family === 'security' ? config('app.timezone') : BusinessProfile::current()->timezone),
            'operation_reference' => isset($source->operation_id) ? $source->operation_id : ($source->attempt_reference ?? null),
            'actor_category' => DB::table('users')->where('id', $source->actor_user_id ?? $source->actor_id ?? $source->recorded_by_user_id ?? $source->changed_by_user_id ?? null)->value('user_type') ?? 'system',
        ];
    }

    public function validatesStoredContract(stdClass $event, stdClass $intent): bool
    {
        if ((int) $event->schema_version !== 1 || (int) $intent->template_version !== 1
            || ! in_array($event->event_type, self::EVENTS[$event->family] ?? [], true)
            || $intent->template_id !== $event->family.'.'.$event->event_type || ! $intent->mandatory || $intent->channel !== 'database'
            || $intent->locale !== 'en-NG' || (int) $event->source_version < 1) {
            return false;
        }
        try {
            $facts = json_decode($event->facts, true, flags: JSON_THROW_ON_ERROR);
            $audiences = json_decode($intent->audiences, true, flags: JSON_THROW_ON_ERROR);
            $destination = json_decode($intent->destination, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }
        $allowedAudiences = match ($event->family) {
            'business_settings' => ['settings_manager'],
            'security' => ['security_operations_admin'],
            'profile' => ['subject_customer', 'subject_agent', 'current_agent', 'security_operations_admin'],
            'agent_status' => ['subject_agent', 'assigned_customer', 'managing_admin'],
            'collection' => ['subject_customer'],
            default => ['subject_customer', 'current_agent'],
        };
        if (! is_array($audiences) || $audiences === [] || ! array_is_list($audiences) || array_diff($audiences, $allowedAudiences) !== []) {
            return false;
        }
        $expected = match ($event->family) {
            'security' => ['severity'],
            'customer_status', 'agent_status' => ['status'],
            'collection' => ['savings_amount_kobo', 'fee_amount_kobo', 'tender_amount_kobo'],
            'withdrawal' => ['state'],
            default => [],
        };
        if (! is_array($facts) || array_diff(array_keys($facts), $expected) !== [] || array_diff($expected, array_keys($facts)) !== []) {
            return false;
        }
        if (isset($facts['status']) && ! in_array($facts['status'], $event->family === 'agent_status' ? ['active', 'inactive'] : ['active', 'inactive', 'restricted', 'archived'], true)) {
            return false;
        }
        if (isset($facts['state']) && ! in_array($facts['state'], ['pending_review', 'approved', 'rejected', 'cancelled', 'expired'], true)) {
            return false;
        }
        if ($event->family === 'collection') {
            foreach ($facts as $amount) {
                if (! is_int($amount) || $amount < 0) {
                    return false;
                }
            }
            if ($facts['tender_amount_kobo'] !== $facts['savings_amount_kobo'] + $facts['fee_amount_kobo']) {
                return false;
            }
        }
        if ($event->family === 'security' && ! in_array($facts['severity'], ['Informational', 'Low', 'Medium', 'High', 'Critical'], true)) {
            return false;
        }
        $snapshot = ['title' => $intent->title, 'summary' => $intent->summary, 'reference' => $intent->reference,
            'destination' => $destination];

        return hash_equals($intent->snapshot_hash, hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)));
    }

    private function matchSubject(int $expected, int $actual): void
    {
        if ($expected !== $actual) {
            throw new InvalidArgumentException('Invalid notification relationship.');
        }
    }

    /** @param list<string> $allowed */
    private function audience(string $audience, array $allowed): void
    {
        if (! in_array($audience, $allowed, true)) {
            throw new InvalidArgumentException('Unknown notification audience.');
        }
    }

    /** @param list<string> $allowed */
    private function state(string $state, array $allowed): string
    {
        if (! in_array($state, $allowed, true)) {
            throw new InvalidArgumentException('Unknown notification state.');
        }

        return $state;
    }

    private function money(int $kobo): string
    {
        return '₦'.number_format(intdiv($kobo, 100), 0, '.', ',').'.'.str_pad((string) ($kobo % 100), 2, '0', STR_PAD_LEFT);
    }
}
