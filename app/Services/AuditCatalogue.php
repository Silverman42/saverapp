<?php

namespace App\Services;

use InvalidArgumentException;

class AuditCatalogue
{
    public const VERSION = 1;

    /** @var array<string, list<string>> */
    private const FAMILIES = [
        'cash_disbursement' => ['delivery_attempt', 'delivery_state_recorded', 'management_attempt', 'started', 'handoff_recorded', 'not_delivered', 'posted', 'recovery_recorded', 'recovery_confirmed'],
        'charge' => ['delivery_state_recorded', 'delivery_attempt', 'management_attempt', 'category_published', 'assessed'],
        'financial_artifact' => ['requested', 'ready', 'downloaded', 'expired', 'cancelled', 'failed', 'retried', 'hold_applied', 'hold_released'],
        'platform' => ['mode_changed', 'integrity_verified', 'projection_promoted', 'replay_plan', 'replay_approve', 'replay_execute', 'replay_stop', 'replay_resume'],
        'business_settings' => ['imported', 'draft_saved', 'draft_discarded', 'previewed', 'published', 'effective', 'cancelled', 'activation_failed', 'bootstrap', 'denied'],
        'invitation' => ['sent', 'delivery_failed', 'resent', 'email_corrected', 'cancelled', 'opened'],
        'customer' => ['delivery_state_recorded', 'delivery_attempt', 'management_attempt', 'reassigned', 'handover_denied', 'registered', 'activated', 'invitation_resent', 'invited_email_corrected', 'invitation_cancelled', 'status_changed', 'profile_updated', 'phone_changed', 'name_changed', 'name_corrected_pre_activation', 'name_correction_proposed', 'name_correction_replaced', 'name_correction_accepted', 'name_correction_rejected', 'name_correction_cancelled', 'name_correction_expired', 'name_correction_invalidated'],
        'agent' => ['delivery_state_recorded', 'delivery_attempt', 'management_attempt', 'registered', 'activated', 'status_changed', 'profile_updated', 'phone_changed', 'suspend', 'restore',
            'start_offboarding', 'transfer_owner', 'cancel_offboarding', 'complete_offboarding', 'return', 'lifecycle_denied'],
        'user' => ['email_changed'],
        'fee_rule' => ['management_attempt', 'delivery_attempt', 'published', 'retired'],
        'fee_application' => ['delivery_attempt', 'delivery_state_recorded'],
        'fee' => ['action.cancelled', 'delivery_attempt', 'delivery_state_recorded', 'management_attempt', 'refund_authorized', 'obligation.assessed', 'obligation.waived', 'assessment.corrected'],
        'thrift_plan' => ['delivery_attempt', 'management_attempt', 'created', 'renewed', 'terms_amended', 'details_corrected', 'pause', 'resume', 'cancel', 'complete', 'close', 'completion_corrected', 'early_termination_prepared', 'closed_exception_resolved', 'closed'],
        'collection' => ['receipt_attempt', 'delivery_attempt', 'method_published', 'evidence_submitted', 'evidence_reviewed', 'evidence_downloaded', 'settlement_confirmed', 'settlement_downloaded', 'receipt_posted', 'batch_frozen', 'remittance_confirmed', 'batch_reviewed', 'exception_opened', 'exception_progressed', 'exception_resolved', 'exception_reopened', 'slot_annotated'],
        'financial_period' => ['open', 'close', 'reopen'],
        'withdrawal' => ['cash_return_recorded', 'cash_return_confirmed', 'cash_started', 'cash_handoff_recorded', 'cash_posted', 'cash_not_delivered', 'submitted', 'approve', 'reject', 'cancel', 'revoke', 'expired', 'hold_applied', 'hold_lifted', 'hold_revalidation_required', 'bank_started', 'bank_submitted', 'bank_unknown', 'bank_failed', 'bank_posted', 'bank_settled', 'bank_returned', 'provider_conflict', 'destination_registered', 'destination_verified', 'destination_rejected', 'destination_revoked', 'decision_denied', 'submission_denied'],
        'reversal' => ['submitted', 'rejected', 'cancelled', 'approved_posted', 'approved_no_money', 'evidence_added', 'evidence_downloaded', 'archived_discovery'],
        'ledger' => ['collection_posted', 'fee_posted', 'integrity_incident', 'integrity_incident_resolved', 'projection_promoted', 'transactions_viewed', 'transaction_viewed', 'balance_viewed', 'statement_previewed'],
        'auth' => ['customer_recovery_requested', 'customer_recovery_verified', 'customer_recovery_approved', 'customer_recovery_reissued', 'customer_recovery_handover', 'customer_recovery_rejected', 'customer_recovery_cancelled', 'customer_recovery_completed', 'customer_recovery_expired', 'login_succeeded', 'password_failed', 'lock_created', 'manual_unlock', 'compromise_sessions_revoked', 'password_reset', 'password_changed', 'mfa_changed', 'session_revoked', 'recovery_codes_used', 'recovery_codes_regenerated'],
        'authorization' => ['permissions_changed', 'restriction_applied', 'restriction_cleared', 'restriction_expired', 'denied'],
        'audit' => ['detail_viewed', 'projection_rebuilt', 'identity_conflict', 'content_mismatch', 'event_correction'],
        'security' => ['case_created', 'case_assigned', 'case_state_changed', 'case_reopened', 'case_note_added', 'case_ownership_released', 'case_viewed'],
    ];

    /** @var array<string, list<string>> */
    private const FIELDS = [
        'cash_disbursement' => ['operation', 'category', 'kind', 'status', 'amount_kobo', 'customer_profile_id', 'execution_reference', 'returned_kobo', 'state', 'notification_reference', 'source_audit_event_id', 'source_event_id', 'channel', 'attempt', 'outcome'],
        'charge' => ['operation', 'category', 'kind', 'version', 'amount_kobo', 'customer_profile_id', 'manual_charge_id', 'notification_reference', 'channel', 'outcome', 'attempt', 'source_audit_event_id'],
        'financial_artifact' => ['kind', 'format', 'status', 'snapshot_hash', 'artifact_hash', 'held', 'reason'],
        'platform' => ['from_mode', 'to_mode', 'from_version', 'to_version', 'run_id', 'manifest_digest', 'outcome', 'scope', 'status', 'failed_domains', 'digest', 'projection', 'previous_version', 'active_version'],
        'business_settings' => ['version', 'base_version', 'changed_fields', 'outcome'],
        'invitation' => ['agent_id', 'generation', 'attempt'],
        'customer' => ['notification_reference', 'source_audit_event_id', 'channel', 'attempt', 'category', 'customer_id', 'operational_status', 'account_state', 'fee_version', 'fee_amount_kobo', 'fee_quote_source_type', 'fee_quote_source_id', 'invitation_id', 'generation', 'operation_id', 'changed_fields', 'from_version', 'to_version', 'outcome', 'correction_id', 'status', 'profile_version', 'from_status', 'to_status'],
        'agent' => ['notification_reference', 'source_audit_event_id', 'channel', 'attempt', 'category', 'operational_status', 'account_state', 'invitation_id', 'operation_id', 'changed_fields', 'from_version', 'to_version', 'outcome', 'from_status', 'to_status', 'from_account_state', 'case_id', 'case_version', 'owner_user_id', 'previous_owner_user_id'],
        'user' => ['operation_id', 'changed_fields', 'from_version', 'to_version', 'outcome'],
        'fee_rule' => ['operation', 'customer_profile_id', 'notification_reference', 'source_audit_event_id', 'fee_rule_event_id', 'channel', 'attempt', 'category', 'kind', 'rule_key', 'version', 'model', 'timing', 'basis', 'basis_points', 'amount_kobo', 'currency', 'effective_at', 'retired_at'],
        'fee_application' => ['notification_reference', 'source_audit_event_id', 'application_id', 'channel', 'attempt', 'category', 'customer_profile_id', 'outcome'],
        'fee' => ['fee_obligation_id', 'payload_hash', 'reason_code', 'operation', 'category', 'customer_profile_id', 'fee_snapshot_id', 'fee_rule_id', 'fee_rule_version', 'amount_kobo', 'currency', 'source_type', 'source_id', 'attempt_reference', 'entry_type', 'outstanding_before_kobo', 'outstanding_after_kobo', 'notification_reference', 'source_audit_event_id', 'source_event_id', 'channel', 'attempt', 'outcome'],
        'thrift_plan' => ['notification_reference', 'attempt', 'channel', 'lifecycle_event_id', 'category', 'template_version', 'rendered_hash', 'operation', 'customer_profile_id', 'terms_revision', 'fee_snapshot_id', 'business_version', 'assignment_version', 'predecessor_plan_id', 'agreement_attested', 'from_version', 'to_version', 'financial_terms_changed', 'from', 'to', 'version', 'gate_fingerprint'],
        'collection' => ['operation', 'customer_version', 'assignment_version', 'assignment_id', 'business_version', 'currency', 'timezone', 'method', 'posting_group_id', 'payment_evidence_id', 'evidence_review_id', 'notification_reference', 'source_audit_event_id', 'channel', 'attempt', 'category', 'template_version', 'rendered_hash', 'method_key', 'version', 'custody_account_code', 'mapping_version', 'method_version_id', 'file_count', 'review_id', 'customer_profile_id', 'plan_id', 'recording_agent_profile_id', 'agent_profile_id', 'received_date', 'revision', 'tender_kobo', 'savings_kobo', 'fee_kobo', 'batch_id', 'amount_kobo', 'outcome', 'outstanding_kobo', 'kind', 'slot_id', 'from_status', 'to_status', 'resolution_kind', 'supplement_id'],
        'financial_period' => ['from', 'to', 'version'],
        'withdrawal' => ['state', 'version', 'gross_kobo', 'fee_kobo', 'deduction_kobo', 'net_kobo', 'customer_profile_id', 'execution_reference', 'attempt_reference', 'destination_reference', 'destination_version', 'provider_reference', 'provider_outcome', 'failure_code', 'disposition', 'account_mask', 'name_match', 'status', 'amount_kobo', 'source', 'outcome'],
        'reversal' => ['customer_profile_id', 'original_posting_group_id', 'state', 'version', 'compensation_posting_group_id', 'file_count', 'file_id'],
        'ledger' => ['incident_reference', 'event_type', 'source_type', 'source_id', 'customer_profile_id', 'agent_profile_id', 'amount_kobo', 'posting_reference', 'currency', 'fee_obligation_id', 'line_count', 'category', 'projection_version', 'transaction_count', 'posting_group_count', 'status', 'result_count', 'customer_id', 'from', 'to', 'ledger_watermark'],
        'auth' => ['operation_id', 'to_version', 'outcome', 'category', 'lock_id', 'attempt_count', 'verification_method', 'restriction_ids', 'changed_fields', 'device_class'],
        'authorization' => ['batch_id', 'grants', 'revocations', 'from_version', 'to_version', 'permission_code', 'restriction_id', 'restriction_type'],
        'audit' => ['event_id', 'projection_version', 'event_count', 'legacy_id', 'changed_fields'],
        'security' => ['case_reference', 'version', 'episode', 'state', 'severity', 'owner_id', 'source_event_id', 'changed_fields'],
    ];

    /** @var list<string> */
    private const PROTECTED = ['operator', 'incident', 'name', 'email_normalized', 'phone_normalized', 'recipient_email_normalized', 'target_email_normalized', 'old_email_normalized', 'new_email_normalized', 'previous_email_normalized', 'corrected_email_normalized', 'reason', 'publication_reason', 'customer_description', 'diagnostic_summary', 'before_values', 'after_values'];

    /** @param array<string, mixed> $payload
     * @return array{safe: array<string, mixed>, protected: array<string, mixed>, category: string, retention: string}
     */
    public function describe(string $eventType, array $payload, bool $legacy = false): array
    {
        [$family, $code] = array_pad(explode('.', $eventType, 2), 2, '');
        if (! in_array($code, self::FAMILIES[$family] ?? [], true)) {
            throw new InvalidArgumentException('Unknown audit event schema.');
        }
        $safe = [];
        $protected = [];
        foreach ($payload as $key => $value) {
            if (in_array($key, self::FIELDS[$family], true)) {
                $this->validateValue($value);
                $safe[$key] = $value;
            } elseif (in_array($key, self::PROTECTED, true)) {
                $this->validateValue($value);
                if (! $legacy && $key !== 'diagnostic_summary') {
                    $protected[$key] = $value;
                }
            } elseif (! $legacy) {
                throw new InvalidArgumentException('Prohibited or unknown audit field.');
            }
        }
        if (isset($safe['changed_fields'])) {
            if (! is_array($safe['changed_fields']) || ! array_is_list($safe['changed_fields'])) {
                throw new InvalidArgumentException('Invalid changed-field list.');
            }
            foreach ($safe['changed_fields'] as $field) {
                if (! is_string($field) || ! preg_match('/\A[a-zA-Z][a-zA-Z0-9_.]{0,99}\z/', $field)) {
                    throw new InvalidArgumentException('Invalid changed-field name.');
                }
            }
        }
        ksort($safe);
        ksort($protected);

        return ['safe' => $safe, 'protected' => $protected, 'category' => $family,
            'retention' => in_array($family, ['auth', 'security'], true) ? 'security_evidence'
                : ($family === 'audit' || str_ends_with($eventType, '_viewed') ? 'protected_access' : 'business_evidence')];
    }

    private function validateValue(mixed $value, int $depth = 0): void
    {
        if ($depth > 3 || (is_string($value) && mb_strlen($value) > 2000)) {
            throw new InvalidArgumentException('Audit field exceeds schema limits.');
        }
        if (is_array($value)) {
            if (count($value) > 100) {
                throw new InvalidArgumentException('Audit field exceeds schema limits.');
            }
            foreach ($value as $key => $item) {
                if (is_string($key) && preg_match('/password|token|secret|cookie|recovery_code|request_body|card_number|cvv/i', $key)) {
                    throw new InvalidArgumentException('Prohibited audit field.');
                }
                $this->validateValue($item, $depth + 1);
            }
        } elseif (! is_null($value) && ! is_scalar($value)) {
            throw new InvalidArgumentException('Invalid audit value.');
        }
    }
}
