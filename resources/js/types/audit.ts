export interface AuditSummary {
    event_id: string;
    event_type: string;
    category: string;
    severity: string;
    outcome: string;
    actor_id: number | null;
    actor_type: string;
    target_reference: string | null;
    recorded_at: string;
    legacy_evidence: boolean;
}
export interface AuditResult {
    rows: AuditSummary[];
    next_cursor: string | null;
    scope: string;
    health: {
        status: string;
        watermark: number;
        version: number;
        pending: number;
        as_of: string | null;
        integrity: string;
        retention: string;
    };
    range: { from: string; to: string };
}
export interface AuditDetail {
    scope: string;
    summary: AuditSummary;
    related: AuditSummary[];
    owner_link: string | null;
    content_check: string;
    content: {
        actor_id: number | null;
        actor_type: string;
        approver_id: number | null;
        executor: string;
        source_module: string;
        source_version: number;
        authority: {
            required_permission: string | null;
            permission_version: number | null;
            fresh_authentication: boolean | null;
            evidence: string;
        };
        occurred_at: string;
        recorded_at: string;
        safe_changes: Record<string, unknown>;
        protected_fields: string[];
        retention_class: string;
        integrity: string;
    };
}
export interface SecurityCaseSummary {
    case_reference: string;
    state: string;
    severity: string;
    version: number;
    episode: number;
    owner_id: number | null;
    affected_account: string;
    created_at: string;
    updated_at: string;
}
export interface SecurityCaseTransition {
    version: number;
    event_type: string;
    actor_id: number | null;
    facts: { state: string; episode: number; owner_id: number | null };
    note: string | null;
    evidence_references: string[];
    created_at: string;
}
