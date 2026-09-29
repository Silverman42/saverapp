<?php

namespace App\Services;

use InvalidArgumentException;

class AuditCatalogue
{
    public const VERSION = 1;

    /** @var array<string, list<string>> */
    private const FAMILIES = [
        'platform' => ['mode_changed', 'replay_plan', 'replay_approve', 'replay_execute', 'replay_stop', 'replay_resume'],
        'business_settings' => ['imported', 'draft_saved', 'draft_discarded', 'previewed', 'published', 'effective', 'cancelled', 'activation_failed', 'bootstrap', 'denied'],
        'invitation' => ['sent', 'delivery_failed', 'resent', 'email_corrected', 'cancelled', 'opened'],
        'customer' => ['delivery_state_recorded', 'delivery_attempt', 'management_attempt', 'reassigned', 'handover_denied', 'registered', 'activated', 'invitation_resent', 'invited_email_corrected', 'invitation_cancelled', 'status_changed', 'profile_updated', 'phone_changed', 'name_changed', 'name_corrected_pre_activation', 'name_correction_proposed', 'name_correction_replaced', 'name_correction_accepted', 'name_correction_rejected', 'name_correction_cancelled', 'name_correction_expired', 'name_correction_invalidated'],
        'agent' => ['delivery_state_recorded', 'delivery_attempt', 'management_attempt', 'registered', 'activated', 'status_changed', 'profile_updated', 'phone_changed', 'suspend', 'restore',
            'start_offboarding', 'transfer_owner', 'cancel_offboarding', 'complete_offboarding', 'return', 'lifecycle_denied'],
        'user' => ['email_changed'],
        'fee_rule' => ['published', 'retired'],
        'fee' => ['obligation.assessed', 'obligation.waived', 'assessment.corrected'],
        'thrift_plan' => ['created', 'renewed', 'terms_amended', 'details_corrected', 'pause', 'resume', 'cancel', 'complete', 'close', 'completion_corrected'],
        'collection' => ['receipt_posted', 'batch_frozen', 'remittance_confirmed', 'batch_reviewed', 'exception_opened', 'exception_resolved', 'exception_reopened', 'slot_annotated'],
        'financial_period' => ['open', 'close', 'reopen'],
        'withdrawal' => ['submitted', 'approve', 'reject', 'cancel', 'revoke', 'expired', 'hold_applied', 'hold_lifted', 'hold_revalidation_required'],
        'reversal' => ['submitted', 'rejected', 'cancelled', 'approved_posted'],
        'ledger' => ['collection_posted', 'fee_posted', 'integrity_incident', 'projection_promoted', 'transactions_viewed', 'transaction_viewed', 'balance_viewed', 'statement_previewed'],
        'auth' => ['customer_recovery_requested', 'customer_recovery_verified', 'customer_recovery_approved', 'customer_recovery_reissued', 'customer_recovery_handover', 'customer_recovery_rejected', 'customer_recovery_cancelled', 'customer_recovery_completed', 'customer_recovery_expired', 'login_succeeded', 'password_failed', 'lock_created', 'manual_unlock', 'compromise_sessions_revoked', 'password_reset', 'password_changed', 'mfa_changed', 'session_revoked', 'recovery_codes_used', 'recovery_codes_regenerated'],
        'authorization' => ['permissions_changed', 'restriction_applied', 'restriction_cleared', 'restriction_expired', 'denied'],
        'audit' => ['detail_viewed', 'projection_rebuilt', 'identity_conflict', 'content_mismatch', 'event_correction'],
        'security' => ['case_created', 'case_assigned', 'case_state_changed', 'case_reopened', 'case_note_added', 'case_ownership_released', 'case_viewed'],
    ];

    /** @var array<string, list<string>> */
    private const FIELDS = [
        'platform' => ['from_mode', 'to_mode', 'from_version', 'to_version', 'run_id', 'manifest_digest', 'outcome'],
        'business_settings' => ['version', 'base_version', 'changed_fields', 'outcome'],
        'invitation' => ['agent_id', 'generation', 'attempt'],
        'customer' => ['notification_reference', 'source_audit_event_id', 'channel', 'attempt', 'category', 'customer_id', 'operational_status', 'account_state', 'fee_version', 'fee_amount_kobo', 'fee_quote_source_type', 'fee_quote_source_id', 'invitation_id', 'generation', 'operation_id', 'changed_fields', 'from_version', 'to_version', 'outcome', 'correction_id', 'status', 'profile_version', 'from_status', 'to_status'],
        'agent' => ['notification_reference', 'source_audit_event_id', 'channel', 'attempt', 'category', 'operational_status', 'account_state', 'invitation_id', 'operation_id', 'changed_fields', 'from_version', 'to_version', 'outcome', 'from_status', 'to_status', 'from_account_state', 'case_id', 'case_version', 'owner_user_id', 'previous_owner_user_id'],
        'user' => ['operation_id', 'changed_fields', 'from_version', 'to_version', 'outcome'],
        'fee_rule' => ['kind', 'rule_key', 'version', 'model', 'timing', 'basis', 'basis_points', 'amount_kobo', 'currency', 'effective_at', 'retired_at'],
        'fee' => ['customer_profile_id', 'fee_snapshot_id', 'fee_rule_id', 'fee_rule_version', 'amount_kobo', 'currency', 'source_type', 'source_id', 'attempt_reference', 'entry_type', 'outstanding_before_kobo', 'outstanding_after_kobo'],
        'thrift_plan' => ['customer_profile_id', 'terms_revision', 'fee_snapshot_id', 'business_version', 'assignment_version', 'predecessor_plan_id', 'agreement_attested', 'from_version', 'to_version', 'financial_terms_changed', 'from', 'to', 'version'],
        'collection' => ['customer_profile_id', 'plan_id', 'recording_agent_profile_id', 'agent_profile_id', 'received_date', 'revision', 'tender_kobo', 'savings_kobo', 'fee_kobo', 'batch_id', 'amount_kobo', 'outcome', 'outstanding_kobo', 'kind', 'slot_id'],
        'financial_period' => ['from', 'to', 'version'],
        'withdrawal' => ['state', 'version', 'gross_kobo', 'fee_kobo', 'net_kobo', 'customer_profile_id'],
        'reversal' => ['customer_profile_id', 'original_posting_group_id', 'state', 'version', 'compensation_posting_group_id'],
        'ledger' => ['event_type', 'source_type', 'source_id', 'customer_profile_id', 'agent_profile_id', 'amount_kobo', 'posting_reference', 'currency', 'fee_obligation_id', 'line_count', 'category', 'projection_version', 'transaction_count', 'posting_group_count', 'status', 'result_count', 'customer_id', 'from', 'to', 'ledger_watermark'],
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
