<?php

namespace App\Services;

use App\Models\BusinessProfile;
use App\Models\FeeRule;
use App\Models\LedgerPostingGroup;
use App\Models\ManualCharge;
use App\Support\MoneyFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use JsonException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use ValueError;

class NotificationCatalogue
{
    public const VERSION = 1;

    /** @var array<string, list<string>> */
    private const EVENTS = [
        'fee_rule' => ['published', 'retired'],
        'invitation_issue' => ['invitation_delivery_issue'],
        'handover' => ['customer.reassigned', 'auth.customer_recovery_requested', 'auth.customer_recovery_verified', 'auth.customer_recovery_approved', 'auth.customer_recovery_reissued', 'auth.customer_recovery_handover', 'auth.customer_recovery_rejected', 'auth.customer_recovery_cancelled', 'auth.customer_recovery_completed', 'auth.customer_recovery_expired'],
        'business_settings' => ['published', 'effective', 'cancelled', 'activation_failed'],
        'security' => ['security.case_created', 'security.case_assigned', 'security.case_reopened'],
        'profile' => ['customer.profile_updated', 'agent.profile_updated', 'customer.phone_changed', 'agent.phone_changed',
            'customer.name_changed', 'customer.name_correction_proposed', 'customer.name_correction_accepted',
            'customer.name_correction_rejected', 'customer.name_correction_cancelled', 'customer.name_correction_expired',
            'customer.name_correction_invalidated', 'customer.name_correction_replaced', 'user.email_changed'],
        'customer_status' => ['customer_status'], 'agent_status' => ['agent_status'],
        'agent_lifecycle' => ['agent.suspend', 'agent.restore', 'agent.start_offboarding', 'agent.transfer_owner', 'agent.cancel_offboarding', 'agent.complete_offboarding', 'agent.return'],
        'plan' => ['created', 'renewed', 'terms_amended', 'details_corrected', 'pause', 'resume', 'cancel', 'complete', 'close', 'completion_corrected', 'closed', 'early_termination_prepared', 'closed_exception_resolved'],
        'collection' => ['collection'], 'withdrawal' => ['cash_return_recorded', 'cash_return_confirmed', 'cash_started', 'cash_handoff_recorded', 'cash_posted', 'cash_not_delivered', 'submitted', 'approve', 'reject', 'cancel', 'revoke', 'expired', 'hold_applied', 'hold_lifted', 'hold_revalidation_required', 'bank_started', 'bank_submitted', 'bank_unknown', 'bank_failed', 'bank_posted', 'bank_settled', 'bank_returned', 'provider_conflict'],
        'financial_cash' => ['recovery_recorded', 'recovery_confirmed', 'refund_authorized', 'started', 'handoff_recorded', 'not_delivered', 'posted'],
        'financial_artifact' => ['ready', 'failed'],
        'charge' => ['assessed'],
        'fee_application' => ['posted'],
        'fee_obligation' => ['waived', 'assessment_corrected'],
        'fee_issue' => ['trigger_unapplied', 'delivery_issue', 'posting_issue'],
        'reversal' => ['submitted', 'approved_posted', 'approved_no_money', 'rejected', 'cancelled'],
        'collection_exception' => ['opened', 'investigating', 'awaiting_action', 'resolved', 'reopened'],
        'account_security' => ['auth.password_changed', 'auth.password_reset', 'auth.mfa_changed', 'auth.session_revoked', 'auth.recovery_codes_regenerated', 'auth.lock_created', 'auth.manual_unlock', 'auth.compromise_sessions_revoked', 'auth.recovery_codes_used'],
        'authorization' => ['auth.staff_recovery_requested', 'auth.staff_recovery_approval_recorded', 'auth.staff_recovery_approved', 'auth.staff_recovery_rejected', 'auth.staff_recovery_cancelled', 'auth.staff_recovery_completed', 'authorization.permissions_changed', 'admin.suspended', 'admin.reactivated', 'admin.deactivated', 'authorization.restriction_applied', 'authorization.restriction_cleared', 'authorization.restriction_expired', 'user.email_changed'],
        'ledger_incident' => ['ledger.integrity_incident', 'ledger.integrity_incident_resolved'],
    ];

    /** @var array<string, array{table: string, source: string, key: string}> */
    public const OWNERS = [
        'fee_rule' => ['table' => 'fee_rule_notification_intents', 'source' => 'fee_rule_events', 'key' => 'fee_rule_event_id'],
        'invitation_issue' => ['table' => 'invitation_issue_notification_intents', 'source' => 'invitation_delivery_issues', 'key' => 'invitation_delivery_issue_id'],
        'handover' => ['table' => 'customer_handover_notices', 'source' => 'customer_handover_events', 'key' => 'customer_handover_event_id'],
        'business_settings' => ['table' => 'business_settings_notification_intents', 'source' => 'business_configuration_events', 'key' => 'business_configuration_event_id'],
        'security' => ['table' => 'security_notification_intents', 'source' => 'security_case_transitions', 'key' => 'security_case_transition_id'],
        'profile' => ['table' => 'profile_notification_intents', 'source' => 'profile_change_histories', 'key' => 'profile_change_history_id'],
        'customer_status' => ['table' => 'customer_status_notification_intents', 'source' => 'customer_status_histories', 'key' => 'customer_status_history_id'],
        'agent_status' => ['table' => 'agent_status_notification_intents', 'source' => 'agent_status_histories', 'key' => 'agent_status_history_id'],
        'agent_lifecycle' => ['table' => 'agent_lifecycle_notification_intents', 'source' => 'agent_lifecycle_histories', 'key' => 'agent_lifecycle_history_id'],
        'plan' => ['table' => 'plan_notification_intents', 'source' => 'plan_lifecycle_events', 'key' => 'plan_lifecycle_event_id'],
        'collection' => ['table' => 'collection_notification_intents', 'source' => 'collection_receipts', 'key' => 'collection_receipt_id'],
        'withdrawal' => ['table' => 'withdrawal_notification_intents', 'source' => 'withdrawal_events', 'key' => 'withdrawal_event_id'],
        'financial_cash' => ['table' => 'financial_cash_notification_intents', 'source' => 'financial_cash_events', 'key' => 'financial_cash_event_id'],
        'financial_artifact' => ['table' => 'financial_artifact_notification_intents', 'source' => 'financial_artifact_events', 'key' => 'financial_artifact_event_id'],
        'charge' => ['table' => 'manual_charge_notification_intents', 'source' => 'manual_charges', 'key' => 'manual_charge_id'],
        'fee_application' => ['table' => 'fee_application_notification_intents', 'source' => 'fee_savings_applications', 'key' => 'fee_savings_application_id'],
        'fee_obligation' => ['table' => 'fee_obligation_notification_intents', 'source' => 'fee_obligation_events', 'key' => 'fee_obligation_event_id'],
        'fee_issue' => ['table' => 'fee_issue_notification_intents', 'source' => 'fee_operational_issues', 'key' => 'fee_operational_issue_id'],
        'reversal' => ['table' => 'reversal_notification_intents', 'source' => 'reversal_events', 'key' => 'reversal_event_id'],
        'collection_exception' => ['table' => 'collection_exception_notification_intents', 'source' => 'collection_exception_events', 'key' => 'collection_exception_event_id'],
        'account_security' => ['table' => 'audit_notification_intents', 'source' => 'audit_events', 'key' => 'audit_event_id'],
        'authorization' => ['table' => 'audit_notification_intents', 'source' => 'audit_events', 'key' => 'audit_event_id'],
        'ledger_incident' => ['table' => 'audit_notification_intents', 'source' => 'audit_events', 'key' => 'audit_event_id'],
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
        $auditEventId = $source->audit_event_id ?? null;
        $sourceTimezone = $source->timezone ?? null;

        switch ($family) {
            case 'invitation_issue':
                $this->audience($audience, ['current_agent', 'customer_manager', 'managing_admin']);
                $invitation = DB::table('invitations')->where('id', $source->invitation_id)->firstOrFail();
                $subject = DB::table($customerId !== null ? 'customer_profiles' : 'agent_profiles')->where('id', $customerId ?? $agentId)->firstOrFail();
                $this->matchSubject((int) $invitation->user_id, (int) $subject->user_id);
                $eventType = 'invitation_delivery_issue';
                $sourceVersion = (int) $invitation->generation;
                $title = 'Invitation delivery needs attention';
                $summary = 'Invitation email acceptance is unconfirmed or dispatch is unavailable. Review the current invitation before using its authorized resend or correction workflow.';
                $actionRequired = true;
                break;
            case 'handover':
                $this->audience($audience, ['subject_customer', 'current_agent', 'subject_agent', 'security_operations_admin']);
                if (! in_array($eventType, self::EVENTS['handover'], true)) {
                    throw new InvalidArgumentException('Unknown handover notice.');
                }
                $payload = json_decode(Crypt::decryptString($owner->payload), true, flags: JSON_THROW_ON_ERROR);
                $title = $payload['title'];
                $summary = $payload['message'];
                $reference = $source->reference;
                $sourceVersion = $source->to_version;
                if ($audience === 'subject_agent') {
                    $customerId = null;
                    $agent = DB::table('agent_profiles')->where('id', $agentId)->firstOrFail();
                    $destination = ['route' => 'agents.show', 'parameters' => [$agent->agent_id]];
                } elseif ($eventType !== 'customer.reassigned') {
                    $subject = DB::table('customer_profiles')->where('id', $customerId)->firstOrFail();
                    $destination = ['route' => 'customers.recovery.show', 'parameters' => [$subject->customer_id]];
                }
                break;
            case 'fee_rule':
                $this->audience($audience, ['fee_manager']);
                if (! in_array($eventType, self::EVENTS['fee_rule'], true)) {
                    throw new InvalidArgumentException('Invalid fee rule notification event.');
                }
                $rule = DB::table('fee_rules')->where('id', $source->fee_rule_id)->first();
                if ($rule === null || (int) $rule->version !== (int) $source->version) {
                    throw new InvalidArgumentException('Fee rule notification source is unavailable.');
                }
                $sourceVersion = (int) $source->version;
                $title = 'Fee rule '.$eventType;
                $reference = strtoupper($rule->kind).'-v'.$sourceVersion;
                $summary = ucfirst($rule->kind).' fee rule version '.$sourceVersion.' was '.$eventType
                    .'. Effective time (UTC): '.CarbonImmutable::parse($source->effective_at, 'UTC')->toIso8601String()
                    .'. New agreements follow the applicable catalogue; existing agreements retain their original fee terms.';
                $destination = ['route' => 'admin.fees.registration.index', 'parameters' => []];
                break;
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
                    'customer.name_correction_replaced' => 'Name correction replaced', 'customer.name_correction_invalidated' => 'Name correction unavailable', 'user.email_changed' => 'Account email updated',
                ];
                $title = $titles[$eventType] ?? throw new InvalidArgumentException('Unknown profile event.');
                if (! in_array($audience, ['subject_customer', 'subject_agent', 'current_agent', 'customer_manager', 'security_operations_admin'], true)
                    || ! in_array($owner->subject_type, ['customer', 'agent'], true)) {
                    throw new InvalidArgumentException('Unknown profile audience.');
                }
                $subject = DB::table($owner->subject_type.'_profiles')->where('id', $owner->subject_id)->first();
                if ($subject === null || ($source->subject_user_id === null || (int) $source->subject_user_id !== (int) $subject->user_id)) {
                    throw new InvalidArgumentException('Invalid profile relationship.');
                }
                $customerId = $owner->subject_type === 'customer' ? $subject->id : null;
                $agentId = $owner->subject_type === 'agent' ? $subject->id : null;
                if (($customerId === null && in_array($audience, ['subject_customer', 'current_agent', 'customer_manager'], true))
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
                $this->audience($audience, ['subject_agent', 'managing_admin', 'assigned_customer', 'service_manager']);
                $status = $this->state($source->to_status, ['active', 'inactive']);
                $facts = ['status' => $status];
                $title = $audience === 'assigned_customer' ? 'Service contact unavailable' : 'Agent status updated';
                $summary = $audience === 'assigned_customer'
                    ? 'Your assigned Agent is temporarily unavailable. Your Customer status and savings are unchanged. Contact the business office for help.'
                    : 'Agent operational status changed to '.ucfirst($status).'. Account access and operational readiness remain separate.';
                if ($audience === 'subject_agent' && is_string($source->agent_facing_explanation) && $source->agent_facing_explanation !== '') {
                    $summary .= ' '.mb_substr($source->agent_facing_explanation, 0, 500);
                }
                if ($audience === 'assigned_customer' && $status === 'active') {
                    $title = 'Service contact restored';
                    $summary = 'Your service contact is available again. Your Customer status and savings are unchanged.';
                }
                if ($audience === 'assigned_customer' && $customerId === null) {
                    throw new InvalidArgumentException('Invalid service notice.');
                }
                if ($audience === 'service_manager') {
                    $title = 'Customer service interruption';
                    $summary = 'An assigned Agent is unavailable. Review affected Customers and reassignment options.';
                    $destination = ['route' => 'customers.index', 'parameters' => []];
                }
                break;
            case 'agent_lifecycle':
                $agentId = $source->agent_profile_id;
                $this->matchSubject($owner->agent_profile_id, $agentId);
                $this->audience($audience, ['subject_agent', 'managing_admin', 'assigned_customer', 'service_manager']);
                if (! in_array($eventType, self::EVENTS['agent_lifecycle'], true)) {
                    throw new InvalidArgumentException('Unknown Agent lifecycle notice.');
                }
                $facts = [];
                $title = 'Agent account updated';
                $summary = 'An Agent lifecycle action was recorded. Account access and operational readiness remain separate.';
                if ($audience === 'subject_agent') {
                    $summary .= ' '.mb_substr($source->agent_facing_explanation, 0, 500);
                }
                if ($audience === 'service_manager') {
                    if (! in_array($eventType, ['agent.suspend', 'agent.start_offboarding'], true)) {
                        throw new InvalidArgumentException('Invalid service interruption issue.');
                    }
                    $title = 'Customer service interruption';
                    $summary = 'An assigned Agent is unavailable. Review affected Customers and reassignment options.';
                    $destination = ['route' => 'customers.index', 'parameters' => []];
                }
                if ($audience === 'assigned_customer') {
                    if ($customerId === null || ! in_array($eventType, ['agent.suspend', 'agent.start_offboarding', 'agent.restore'], true)) {
                        throw new InvalidArgumentException('Invalid Agent lifecycle service notice.');
                    }
                    $title = 'Service contact unavailable';
                    $business = BusinessProfile::current();
                    $contact = $business->support_email ?: $business->support_phone;
                    $summary = 'Your assigned Agent is temporarily unavailable. Your Customer status and savings are unchanged. '.($contact ? 'For help, contact '.$contact.'.' : 'Contact the business office for help.');
                    if ($eventType === 'agent.restore') {
                        $title = 'Service contact restored';
                        $summary = 'Your service contact is available again. Your Customer status and savings are unchanged.';
                    }
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
                    'created' => 'A daily thrift plan was created.', 'renewed' => 'A new daily thrift plan was created from a settled predecessor.',
                    'terms_amended' => 'Your daily thrift plan terms were amended.', 'details_corrected' => 'Customer-visible plan details were corrected.',
                    'pause' => 'Your daily thrift plan was paused.', 'resume' => 'Your daily thrift plan was resumed.',
                    'cancel' => 'Your daily thrift plan was cancelled.', 'complete' => 'Your daily thrift plan was completed.',
                    'early_termination_prepared' => 'Early termination was prepared. Financial settlement and closure remain separate.', 'closed_exception_resolved' => 'A settled closed-cycle correction exception was resolved.', 'closed' => 'Your settled daily thrift plan was closed.', 'close' => 'Your daily thrift plan was closed.', 'completion_corrected' => 'Your plan completion was corrected after an authorized correction.',
                ];
                $summary = $messages[$eventType] ?? throw new InvalidArgumentException('Unknown plan event.');
                $title = 'Thrift plan updated';
                $category = 'plan';
                $reference = $plan->plan_id;
                $destination = ['route' => 'plans.show', 'parameters' => [$reference]];
                break;
            case 'collection':
                if (($owner->context_ciphertext ?? null) !== null) {
                    return app(CollectionFeeReceiptNotificationSource::class)->describe($owner, $source);
                }
                $customerId = $source->customer_profile_id;
                $this->audience($audience, ['subject_customer']);
                $this->matchSubject($owner->recipient_user_id, (int) DB::table('customer_profiles')->where('id', $customerId)->value('user_id'));
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
            case 'financial_artifact':
                $this->audience($audience, ['artifact_requester', 'subject_customer']);
                $artifact = DB::table('financial_artifacts')->where('id', $source->financial_artifact_id)->firstOrFail();
                if ($audience === 'subject_customer') {
                    if ($eventType !== 'ready' || $artifact->kind !== 'statement' || $artifact->supersedes_artifact_id === null) {
                        throw new InvalidArgumentException('Only a corrected statement notifies its Customer.');
                    }
                    $this->matchSubject((int) $owner->recipient_user_id, (int) DB::table('customer_profiles')->where('id', $artifact->customer_profile_id)->value('user_id'));
                    $title = 'Corrected statement issued';
                    $summary = 'A corrected statement replaced an earlier one for the same period. The earlier statement is kept unchanged.';
                } else {
                    $this->matchSubject($owner->recipient_user_id, $artifact->requester_user_id);
                    $title = $eventType === 'ready' ? 'Financial document ready' : 'Financial document needs attention';
                    $summary = $eventType === 'ready' ? 'Your requested document is ready. Access is checked when you open it.' : 'Your requested document could not be rendered. Review its status before retrying.';
                }
                $reference = $artifact->artifact_reference;
                $source->operation_reference = $reference;
                $category = 'financial';
                $destination = ['route' => 'financial-artifacts.show', 'parameters' => [$reference]];
                break;
            case 'financial_cash':
                return $this->financialNoticeDescriptor($source, $owner, app(FinancialCashNotificationSource::class)->describe($owner, $source));
            case 'fee_obligation':
                return $this->financialNoticeDescriptor($source, $owner, app(FeeObligationNotificationSource::class)->describe($owner, $source));
            case 'fee_issue':
                return app(FeeOperationalIssueNotificationSource::class)->describe($owner, $source);
            case 'fee_application':
                $this->audience($audience, ['subject_customer', 'current_agent']);
                $group = app(FeeSavingsApplicationService::class)->assertPosted($source->operation_reference, DB::transactionLevel() > 0);
                if ((int) $owner->customer_profile_id !== $group->customer_profile_id
                    || (int) $owner->thrift_plan_id !== $group->thrift_plan_id) {
                    throw new InvalidArgumentException('The fee application notice has mismatched source dimensions.');
                }
                $subject = DB::table('customer_profiles')->where('id', $group->customer_profile_id)->sole();
                if ($audience === 'subject_customer' && ((int) $owner->recipient_user_id !== (int) $subject->user_id || $agentId !== null)) {
                    throw new InvalidArgumentException('The fee application notice has no original Customer recipient.');
                }
                if ($audience === 'current_agent' && ! DB::table('customer_assignments as assignment')
                    ->join('agent_profiles as agent', 'agent.id', '=', 'assignment.agent_profile_id')
                    ->where('assignment.customer_profile_id', $group->customer_profile_id)->where('agent.id', $agentId)
                    ->where('agent.user_id', $owner->recipient_user_id)->where('assignment.effective_at', '<=', $owner->created_at)
                    ->where(fn ($query) => $query->whereNull('assignment.ended_at')->orWhere('assignment.ended_at', '>', $owner->created_at))->exists()) {
                    throw new InvalidArgumentException('The fee application notice has no original Agent assignment.');
                }
                $audit = DB::table('audit_events')->where('event_type', 'ledger.fee_posted')->where('target_type', LedgerPostingGroup::class)
                    ->where('target_id', $group->id)->where('target_reference', $group->posting_reference)->where('actor_id', $group->actor_user_id)->first();
                $auditPayload = $audit === null ? null : json_decode($audit->payload, true, flags: JSON_THROW_ON_ERROR);
                if ($audit === null || ! is_array($auditPayload) || ($auditPayload['source_type'] ?? null) !== 'fee_savings_application'
                    || ($auditPayload['source_id'] ?? null) !== $source->operation_reference
                    || ($auditPayload['fee_obligation_id'] ?? null) !== (int) $source->fee_obligation_id
                    || ($auditPayload['amount_kobo'] ?? null) !== (int) $source->amount_kobo) {
                    throw new InvalidArgumentException('The fee application notice has no verified posting audit.');
                }
                $auditEventId = $audit->id;
                $eventType = 'posted';
                $sourceVersion = 1;
                $sourceTimezone = $group->business_timezone;
                $title = 'Fee paid from savings';
                $summary = $source->customer_description.' Amount '.MoneyFormatter::formatNaira((int) $source->amount_kobo).' was applied from cycle savings.';
                $summary .= ' At posting: cycle savings '.MoneyFormatter::formatNaira((int) $source->remaining_cycle_savings_kobo)
                    .'; available cycle savings '.MoneyFormatter::formatNaira((int) $source->remaining_available_kobo)
                    .'; unpaid fee '.MoneyFormatter::formatNaira((int) $source->remaining_fee_kobo).'.';
                $category = 'financial';
                $reference = $group->posting_reference;
                $destination = ['route' => 'customers.show', 'parameters' => [$subject->customer_id]];
                break;
            case 'charge':
                $this->audience($audience, ['subject_customer', 'current_agent']);
                $chargeCategory = DB::table('charge_category_versions')->where('id', $source->charge_category_version_id)->firstOrFail();
                $subject = DB::table('customer_profiles')->where('id', $source->customer_profile_id)->firstOrFail();
                if ((int) $customerId !== (int) $subject->id) {
                    throw new InvalidArgumentException('Charge notification Customer dimensions changed.');
                }
                $eventType = 'assessed';
                $title = $chargeCategory->kind === 'manual_fee' ? 'Manual fee assessed' : 'Savings deduction posted';
                $chargeNotice = $this->chargeNotice($owner, $source, $chargeCategory, $subject);
                $summary = $chargeNotice['summary'];
                $auditEventId = $chargeNotice['audit_event_id'];
                $sourceTimezone = $chargeNotice['timezone'];
                $category = 'financial';
                $reference = $source->operation_reference;
                $destination = ['route' => 'customers.show', 'parameters' => [$subject->customer_id]];
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
                    'cash_return_recorded' => 'A cash return amount was recorded. Please confirm the exact amount returned.',
                    'cash_return_confirmed' => 'The Customer confirmed the recorded cash return amount. Any posted payout still requires reviewed correction.',
                    'cash_started' => 'Cash is reserved for an approved payment. Receipt is not yet confirmed.',
                    'cash_handoff_recorded' => 'The custodian recorded a handoff. The Customer must confirm receipt; the outcome remains unknown.',
                    'cash_posted' => 'The Customer confirmed cash receipt and the withdrawal was posted.',
                    'cash_not_delivered' => 'No cash was handed over. Savings remain reserved for review or retry.',
                    'submitted' => 'A withdrawal request was submitted for review. Savings are reserved; no payout has been made.',
                    'approve' => 'A withdrawal request was approved. No payout has been made yet.',
                    'reject' => 'A withdrawal request was rejected and its reservation released.',
                    'cancel' => 'A withdrawal request was cancelled and its reservation released.',
                    'revoke' => 'A withdrawal approval was revoked and its reservation released.',
                    'expired' => 'A withdrawal request expired and its reservation released.',
                    'hold_applied' => 'A withdrawal request is on hold. No payout can proceed.',
                    'hold_lifted' => 'A withdrawal hold was lifted. Normal approval and payout controls still apply.',
                    'hold_revalidation_required' => 'A withdrawal hold was lifted but the request must be revalidated before it can proceed. No payout can proceed yet.',
                    'bank_started' => 'A bank transfer was started for an approved withdrawal. Savings stay reserved until the provider confirms.',
                    'bank_submitted' => 'The bank transfer was accepted by the provider. The final result is not known yet.',
                    'bank_unknown' => 'The bank transfer result is not yet known. Savings stay reserved and no second payment will be sent.',
                    'bank_failed' => 'The bank transfer did not go through. Savings remain reserved.',
                    'bank_posted' => 'The bank transfer was confirmed and the withdrawal was posted.',
                    'bank_settled' => 'The provider settled the bank transfer. Your savings balance did not change again.',
                    'bank_returned' => 'The bank returned the transfer. It is being reviewed; your savings balance has not changed yet.',
                    'provider_conflict' => 'The provider reported conflicting results for a withdrawal. It is on hold for review.',
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
                if ($audience === 'subject_customer' && ! in_array($eventType, ['approved_posted', 'approved_no_money'], true)) {
                    throw new InvalidArgumentException('Customer correction notice is not enabled.');
                }
                $messages = [
                    'submitted' => 'A correction request was submitted for review. The original transaction remains effective.',
                    'approved_posted' => 'A reviewed correction was posted. Open the linked account record to review its effect.',
                    'approved_no_money' => 'A reviewed correction was completed. Existing fee refunds are preserved; no new money movement was required.',
                    'rejected' => 'A correction request was rejected. The original transaction remains effective.',
                    'cancelled' => 'A correction request was cancelled. The original transaction remains effective.',
                ];
                $summary = $messages[$eventType] ?? throw new InvalidArgumentException('Unknown correction event.');
                $title = 'Financial correction updated';
                $category = 'financial';
                $reference = $request->reversal_id;
                $destination = ['route' => 'reversals.show', 'parameters' => [$reference]];
                break;
            case 'collection_exception':
                $this->audience($audience, ['reconciliation_manager', 'subject_agent']);
                $exception = DB::table('collection_exceptions')->where('id', $source->collection_exception_id)->first();
                $batch = $exception === null ? null : DB::table('collection_batches')->where('id', $exception->collection_batch_id)->first();
                if ($batch === null || ! in_array($eventType, self::EVENTS['collection_exception'], true)) {
                    throw new InvalidArgumentException('Collection exception notification source is unavailable.');
                }
                if ($audience === 'subject_agent') {
                    $this->matchSubject((int) $batch->agent_profile_id, (int) $owner->agent_profile_id);
                    $agentId = (int) $batch->agent_profile_id;
                } elseif ($owner->agent_profile_id !== null) {
                    throw new InvalidArgumentException('Invalid collection exception audience.');
                }
                $sourceVersion = (int) $source->batch_version;
                $title = match ($eventType) {
                    'opened' => 'Reconciliation exception opened',
                    'resolved' => 'Reconciliation exception resolved',
                    'reopened' => 'Reconciliation exception reopened',
                    default => 'Reconciliation exception updated',
                };
                $summary = 'A cash batch reconciliation exception is now '.str_replace('_', ' ', $eventType === 'opened' ? 'open' : $eventType)
                    .'. Open the batch to review its current state. Exceptions never change Customer credit or forgive Agent responsibility.';
                $reference = 'BATCH-'.$batch->id;
                $destination = ['route' => 'collection-batches.show', 'parameters' => [(int) $batch->id]];
                $category = 'financial';
                $actionRequired = in_array($eventType, ['opened', 'reopened', 'awaiting_action'], true);
                break;
            case 'account_security':
            case 'authorization':
            case 'ledger_incident':
                if (($owner->family ?? null) !== $family || (AuditNoticeService::FAMILIES[$eventType] ?? null) !== $family) {
                    throw new InvalidArgumentException('Audit notice family changed.');
                }
                $auditEventId = (int) $source->id;
                $sourceVersion = 1;
                $category = 'account';
                if ($family === 'ledger_incident') {
                    $this->audience($audience, ['reconciliation_manager']);
                    $resolved = $eventType === 'ledger.integrity_incident_resolved';
                    $title = $resolved ? 'Ledger integrity incident resolved' : 'Ledger integrity incident detected';
                    $summary = $resolved
                        ? 'A ledger integrity incident was resolved after the ledger verified cleanly.'
                        : 'A ledger integrity incident was detected. Affected financial reads stay unavailable until it is recovered and resolved.';
                    $reference = $source->target_reference;
                    $destination = ['route' => 'transactions.index', 'parameters' => []];
                    $category = 'financial';
                    $actionRequired = ! $resolved;
                    break;
                }
                $subject = app(AuditNoticeService::class)->subjectUserId($eventType, $source->target_type, $source->target_id === null ? null : (int) $source->target_id);
                if ($subject === null) {
                    throw new InvalidArgumentException('Audit notice subject is unavailable.');
                }
                if ($family === 'account_security') {
                    $this->audience($audience, ['subject_user']);
                    $this->matchSubject($subject, (int) $owner->recipient_user_id);
                    $title = match ($eventType) {
                        'auth.password_changed', 'auth.password_reset' => 'Your password was changed',
                        'auth.mfa_changed' => 'Your two-factor authentication changed',
                        'auth.session_revoked' => 'A session was signed out',
                        'auth.recovery_codes_regenerated' => 'New recovery codes were created',
                        'auth.recovery_codes_used' => 'A recovery code was used',
                        'auth.manual_unlock' => 'Your sign-in lock was removed by an administrator',
                        'auth.compromise_sessions_revoked' => 'Your sessions were signed out for your protection',
                        default => 'Sign-in was temporarily locked',
                    };
                    $summary = 'This security change was recorded on your account. If you did not expect it, contact your administrator immediately.';
                    $destination = ['route' => 'security.edit', 'parameters' => []];
                    break;
                }
                $this->audience($audience, ['subject_user', 'admin_manager']);
                $title = match (true) {
                    str_starts_with($eventType, 'auth.staff_recovery_') => 'Account recovery updated',
                    $eventType === 'authorization.permissions_changed' => 'Access permissions changed',
                    str_starts_with($eventType, 'admin.') => 'Admin account status changed',
                    $eventType === 'user.email_changed' => 'Admin email address changed',
                    default => 'Access restriction updated',
                };
                if (str_starts_with($eventType, 'auth.staff_recovery_')) {
                    if ($audience === 'subject_user') {
                        $this->matchSubject($subject, (int) $owner->recipient_user_id);
                    }
                    $summary = $audience === 'subject_user'
                        ? 'An assisted recovery of your account changed state. Contact your Administrator if you did not expect it.'
                        : 'An Agent or Admin account recovery changed state. Open the recovery queue to review approvals.';
                    $destination = $audience === 'subject_user' ? ['route' => 'dashboard', 'parameters' => []] : ['route' => 'admin.staff-recoveries.index', 'parameters' => []];
                } elseif ($eventType === 'user.email_changed') {
                    $this->audience($audience, ['admin_manager']);
                    $summary = 'Another Admin changed their sign-in email address. If this was not expected, open their access record and start a staff recovery.';
                    $destination = ['route' => 'admin.access.show', 'parameters' => [$subject]];
                } elseif ($audience === 'subject_user') {
                    $this->matchSubject($subject, (int) $owner->recipient_user_id);
                    $summary = 'Your access was changed. Some actions may now be available or unavailable; sign in again if a page does not reflect it.';
                    $destination = ['route' => 'dashboard', 'parameters' => []];
                } else {
                    $summary = 'Another Admin\'s access was changed. Open their access record to review current permissions and restrictions.';
                    $destination = ['route' => 'admin.access.show', 'parameters' => [$subject]];
                }
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
            'event_type' => $eventType, 'facts' => $facts, 'audit_event_id' => $auditEventId,
            'effective_at' => $effectiveAt, 'customer_profile_id' => $customerId, 'agent_profile_id' => $agentId,
            'audience' => $audience, 'category' => $category, 'title' => $title, 'summary' => $summary,
            'reference' => $reference, 'destination' => $destination, 'action_required' => $actionRequired, 'action_correction_id' => $actionCorrectionId,
            'timezone' => $sourceTimezone ?? ($family === 'security' ? config('app.timezone') : BusinessProfile::current()->timezone),
            'operation_reference' => $source->operation_reference ?? (isset($source->operation_id) ? $source->operation_id : ($source->attempt_reference ?? null)),
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
        if (in_array($event->family, ['fee_application', 'charge', 'fee_obligation', 'financial_cash', 'fee_issue', 'collection'], true) && ! $this->validatesFinancialNoticeSource($event->family, $event, $intent)) {
            return false;
        }
        if ($event->family === 'fee_rule' && ! DB::table('fee_rule_notification_intents as owner')
            ->join('fee_rule_events as source', 'source.id', '=', 'owner.fee_rule_event_id')
            ->join('fee_rules as rule', 'rule.id', '=', 'source.fee_rule_id')
            ->join('audit_events as audit', 'audit.id', '=', 'source.audit_event_id')
            ->whereIn('owner.id', DB::table('notification_inbox_aliases')->where('intent_id', $intent->id)
                ->where('family', 'fee_rule')->select('owner_intent_id'))
            ->where('owner.notification_id', $intent->notification_id)->where('owner.recipient_user_id', $intent->recipient_user_id)
            ->where('owner.channel', 'database')->where('owner.audience_type', 'fee_manager')
            ->where('source.event_type', $event->event_type)->where('source.id', $event->source_id)
            ->where('source.version', $event->source_version)->whereColumn('source.version', 'rule.version')
            ->where('source.audit_event_id', $event->audit_event_id)->where('audit.target_type', FeeRule::class)
            ->whereColumn('audit.target_id', 'rule.id')->whereColumn('audit.actor_id', 'source.actor_user_id')
            ->where('audit.event_type', 'fee_rule.'.$event->event_type)->exists()) {
            return false;
        }
        if ($event->family === 'plan' && ! DB::table('plan_notification_intents as owner')
            ->join('plan_lifecycle_events as source', 'source.id', '=', 'owner.plan_lifecycle_event_id')
            ->join('thrift_plans as plan', 'plan.id', '=', 'source.thrift_plan_id')
            ->whereIn('owner.id', DB::table('notification_inbox_aliases')->where('intent_id', $intent->id)
                ->where('family', 'plan')->select('owner_intent_id'))
            ->where('owner.notification_id', $intent->notification_id)->where('owner.recipient_user_id', $intent->recipient_user_id)
            ->where('owner.channel', 'database')->where('source.event_type', $event->event_type)
            ->where('source.id', $event->source_id)->where('source.plan_version', $event->source_version)
            ->whereColumn('owner.thrift_plan_id', 'plan.id')->whereColumn('owner.customer_profile_id', 'plan.customer_profile_id')
            ->where('plan.customer_profile_id', $intent->customer_profile_id)->exists()) {
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
            'invitation_issue' => ['current_agent', 'customer_manager', 'managing_admin'],
            'handover' => ['subject_customer', 'current_agent', 'subject_agent', 'security_operations_admin'],
            'fee_rule' => ['fee_manager'],
            'business_settings' => ['settings_manager'],
            'security' => ['security_operations_admin'],
            'profile' => ['subject_customer', 'subject_agent', 'current_agent', 'customer_manager', 'security_operations_admin'],
            'agent_status', 'agent_lifecycle' => ['subject_agent', 'assigned_customer', 'managing_admin', 'service_manager'],
            'collection' => ['subject_customer', 'current_agent'],
            'financial_cash' => ['subject_customer', 'current_agent', 'fee_manager', 'refund_cash_operator', 'refund_correction_operator', 'cash_executor'],
            'fee_obligation' => ['subject_customer', 'current_agent', 'fee_manager'],
            'fee_issue' => ['current_agent', 'fee_manager', 'deduction_manager', 'refund_cash_operator', 'refund_correction_operator'],
            'financial_artifact' => ['artifact_requester', 'subject_customer'],
            'collection_exception' => ['reconciliation_manager', 'subject_agent'],
            'account_security' => ['subject_user'],
            'authorization' => ['subject_user', 'admin_manager'],
            'ledger_incident' => ['reconciliation_manager'],
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
        if (isset($facts['state']) && ! in_array($facts['state'], ['pending_review', 'approved', 'rejected', 'cancelled', 'expired', 'payout_processing', 'outcome_unknown', 'payment_failed', 'posted'], true)) {
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

    /** @param array<string, mixed> $verified
     * @return array<string, mixed>
     */
    private function financialNoticeDescriptor(stdClass $source, stdClass $owner, array $verified): array
    {
        return [
            'source_id' => (int) $source->id, 'source_version' => 1, 'event_type' => $source->event_type,
            'facts' => [], 'audit_event_id' => $source->audit_event_id ?? null,
            'effective_at' => CarbonImmutable::parse($source->effective_at ?? $source->created_at, 'UTC'),
            'customer_profile_id' => $owner->customer_profile_id ?? null, 'agent_profile_id' => $owner->agent_profile_id ?? null,
            'audience' => $owner->audience_type, 'category' => 'financial',
            'action_required' => false, 'action_correction_id' => null,
            'operation_reference' => $source->operation_reference,
            'actor_category' => DB::table('users')->where('id', $source->actor_user_id)->value('user_type') ?? 'system',
            ...$verified,
        ];
    }

    private function validatesFinancialNoticeSource(string $family, stdClass $event, stdClass $intent): bool
    {
        if (! Schema::hasTable(self::OWNERS[$family]['table']) || ! Schema::hasTable(self::OWNERS[$family]['source'])) {
            return false;
        }
        $owner = DB::table(self::OWNERS[$family]['table'])->whereIn('id', DB::table('notification_inbox_aliases')
            ->where('intent_id', $intent->id)->where('family', $family)->select('owner_intent_id'))
            ->where('notification_id', $intent->notification_id)->where('recipient_user_id', $intent->recipient_user_id)
            ->where('channel', 'database')->first();
        if ($owner === null) {
            return false;
        }
        try {
            $descriptor = $this->describe($family, $owner);

            return $descriptor['source_id'] === (int) $event->source_id && $descriptor['source_version'] === (int) $event->source_version
                && $descriptor['audit_event_id'] === ($event->audit_event_id === null ? null : (int) $event->audit_event_id)
                && $descriptor['timezone'] === $event->timezone
                && $descriptor['operation_reference'] === $event->operation_reference
                && $descriptor['effective_at']->equalTo(CarbonImmutable::parse($event->effective_at, 'UTC'))
                && (int) $descriptor['customer_profile_id'] === (int) $intent->customer_profile_id
                && (int) ($descriptor['agent_profile_id'] ?? 0) === (int) ($intent->agent_profile_id ?? 0)
                && (! property_exists($owner, 'assignment_id') || (int) ($owner->assignment_id ?? 0) === (int) ($intent->assignment_id ?? 0))
                && json_decode($intent->audiences, true, flags: JSON_THROW_ON_ERROR) === [$descriptor['audience']]
                && $descriptor['title'] === $intent->title && $descriptor['summary'] === $intent->summary
                && $descriptor['reference'] === $intent->reference
                && json_decode($intent->destination, true, flags: JSON_THROW_ON_ERROR) === $descriptor['destination'];
        } catch (DecryptException|InvalidArgumentException|ConflictHttpException|JsonException|ValueError) {
            return false;
        }
    }

    /** @return array{summary: string, audit_event_id: int, timezone: string|null} */
    private function chargeNotice(stdClass $owner, stdClass $charge, stdClass $category, stdClass $customer): array
    {
        $amount = (int) $charge->amount_kobo;
        $audit = DB::table('audit_events')->where('event_type', 'charge.assessed')->where('target_type', ManualCharge::class)
            ->where('target_id', $charge->id)->where('target_reference', $charge->operation_reference)->first();
        $auditPayload = $audit === null ? null : json_decode($audit->payload, true, flags: JSON_THROW_ON_ERROR);
        if (! in_array($category->kind, ['manual_fee', 'deduction'], true) || $amount < 1 || $amount !== (int) $category->amount_kobo
            || $audit === null || (int) $audit->actor_id !== (int) $charge->actor_user_id || ! is_array($auditPayload)
            || ($auditPayload['amount_kobo'] ?? null) !== $amount || ($auditPayload['customer_profile_id'] ?? null) !== (int) $customer->id
            || ($auditPayload['kind'] ?? null) !== $category->kind || ($auditPayload['version'] ?? null) !== (int) $category->version) {
            throw new InvalidArgumentException('Charge notification source is unavailable.');
        }
        if ($owner->audience_type === 'subject_customer') {
            $this->matchSubject((int) $customer->user_id, (int) $owner->recipient_user_id);
            if (($owner->agent_profile_id ?? null) !== null || ($owner->assignment_id ?? null) !== null) {
                throw new InvalidArgumentException('Charge Customer audience changed.');
            }
        } else {
            $assignment = DB::table('customer_assignments as assignment')->join('agent_profiles as agent', 'agent.id', '=', 'assignment.agent_profile_id')
                ->where('assignment.id', $owner->assignment_id ?? null)->where('assignment.customer_profile_id', $customer->id)
                ->where('agent.id', $owner->agent_profile_id ?? null)->where('agent.user_id', $owner->recipient_user_id)->first(['assignment.id']);
            if ($assignment === null || $owner->channel !== 'database') {
                throw new InvalidArgumentException('Charge Agent audience changed.');
            }
        }
        if ($category->kind === 'deduction') {
            $group = DB::table('ledger_posting_groups')->where('id', $charge->ledger_posting_group_id)->where('source_type', 'manual_charge')
                ->where('source_id', $charge->operation_reference)->where('event_type', 'other_deduction')->where('currency', 'NGN')
                ->where('customer_profile_id', $customer->id)->where('thrift_plan_id', $charge->thrift_plan_id)->whereNotNull('committed_at')->first();
            $lines = $group === null ? collect() : DB::table('ledger_entries as line')->join('ledger_accounts as account', 'account.id', '=', 'line.ledger_account_id')
                ->where('line.ledger_posting_group_id', $group->id)->orderBy('line.line_number')->get(['line.*', 'account.code']);
            if ($group === null || $charge->fee_obligation_id !== null || $lines->count() !== 2
                || $lines[0]->code !== 'customer_savings_liability_ngn' || $lines[0]->side !== 'debit'
                || $lines[1]->code !== 'other_deduction_destination_ngn' || $lines[1]->side !== 'credit'
                || (int) $lines[0]->amount_kobo !== $amount || (int) $lines[1]->amount_kobo !== $amount
                || (int) $lines[0]->customer_profile_id !== (int) $customer->id || (int) $lines[1]->customer_profile_id !== (int) $customer->id
                || (int) $lines[0]->thrift_plan_id !== (int) $charge->thrift_plan_id || $lines[1]->thrift_plan_id !== null) {
                throw new InvalidArgumentException('Charge deduction source changed.');
            }
        } elseif ($charge->ledger_posting_group_id !== null || ! DB::table('fee_obligations as obligation')
            ->join('fee_snapshots as snapshot', 'snapshot.id', '=', 'obligation.fee_snapshot_id')->where('obligation.id', $charge->fee_obligation_id)
            ->where('obligation.customer_profile_id', $customer->id)->where('snapshot.source_type', 'manual_charge')
            ->where('snapshot.source_id', $charge->operation_reference)->exists()) {
            throw new InvalidArgumentException('Charge assessment source changed.');
        }
        $summary = $category->customer_description.' Amount '.MoneyFormatter::formatNaira($amount).'.';
        $payload = json_decode($owner->payload, true, flags: JSON_THROW_ON_ERROR);
        if ($payload === []) {
            if ($owner->audience_type !== 'subject_customer' || $owner->channel !== 'database') {
                throw new InvalidArgumentException('Legacy charge audience changed.');
            }

            return ['summary' => $summary, 'audit_event_id' => (int) $audit->id, 'timezone' => null];
        }
        if (! is_array($payload) || array_keys($payload) !== ['context_ciphertext'] || ! is_string($payload['context_ciphertext'])) {
            throw new InvalidArgumentException('Charge notification snapshot is unavailable.');
        }
        $context = json_decode(Crypt::decryptString($payload['context_ciphertext']), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($context) || ($context['schema_version'] ?? null) !== 1 || ($context['manual_charge_id'] ?? null) !== (int) $charge->id
            || ($context['category_version_id'] ?? null) !== (int) $category->id || ($context['posting_group_id'] ?? null) !== ($charge->ledger_posting_group_id === null ? null : (int) $charge->ledger_posting_group_id)
            || ($context['audit_event_id'] ?? null) !== (int) $audit->id || ($context['amount_kobo'] ?? null) !== $amount
            || ! is_string($context['timezone'] ?? null) || ! in_array($context['timezone'], \DateTimeZone::listIdentifiers(), true)
            || ! is_int($context['outstanding_fee_kobo'] ?? null) || $context['outstanding_fee_kobo'] < 0) {
            throw new InvalidArgumentException('Charge notification snapshot changed.');
        }
        if ($owner->audience_type === 'current_agent' && (($context['assignment_id'] ?? null) !== (int) $owner->assignment_id
            || ($context['agent_profile_id'] ?? null) !== (int) $owner->agent_profile_id)) {
            throw new InvalidArgumentException('Charge notification assignment changed.');
        }
        if ($category->kind === 'deduction') {
            if (! is_int($context['cycle_liability_kobo'] ?? null) || ! is_int($context['cycle_available_kobo'] ?? null)
                || $context['cycle_available_kobo'] < 0 || $context['cycle_liability_kobo'] < $context['cycle_available_kobo']
                || $context['outstanding_fee_kobo'] !== 0) {
                throw new InvalidArgumentException('Charge deduction position changed.');
            }
            $summary .= ' Cycle savings '.MoneyFormatter::formatNaira($context['cycle_liability_kobo'])
                .'; available cycle savings '.MoneyFormatter::formatNaira($context['cycle_available_kobo']).'.';
        } else {
            if (($context['cycle_liability_kobo'] ?? null) !== null || ($context['cycle_available_kobo'] ?? null) !== null || $owner->channel === 'mail') {
                throw new InvalidArgumentException('Charge assessment channels changed.');
            }
            $summary .= ' Unpaid fee '.MoneyFormatter::formatNaira($context['outstanding_fee_kobo']).'.';
        }

        return ['summary' => $summary, 'audit_event_id' => (int) $audit->id, 'timezone' => $context['timezone']];
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
