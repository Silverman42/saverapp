export type ReportDefinition = {
    code: string;
    title: string;
    basis: string;
    activity: boolean;
    groups: string[];
    filters: string[];
    reason: string;
    export_available: boolean;
    export_reason: string;
};
export type ReportFilters = {
    page_size: number;
    group: string;
    from?: string;
    to?: string;
    customer?: string;
    customer_status?: string;
    plan?: string;
    plan_status?: string;
    state?: string;
    agent?: string;
    agent_basis?: string;
    cursor?: string;
};
export type ReportMetric = {
    code: string;
    title: string;
    value: number | null;
    unit: string;
    display: string;
    definition: string;
    definition_version: number;
    date_basis: string;
    source: string;
    scope_note: string;
};
export type ReportSection = {
    status: 'Current' | 'Partial' | 'Unavailable' | 'Too large';
    reason: string;
    metrics: ReportMetric[];
    columns: Record<string, string>;
    rows: {
        key: string;
        href: string | null;
        [field: string]: string | number | boolean | null;
    }[];
    groups: { label: string; count: number; metrics: ReportMetric[] }[];
    total: number | null;
    next_cursor: string | null;
    source_version?: string;
};
export type ReportResult = {
    definition: ReportDefinition;
    manifest: {
        role: 'customer' | 'agent' | 'admin';
        scope_hash: string;
        schema_version: number;
        definition_version: number;
        timezone: string;
        timezone_version: number;
        currency: string;
        cutoff: string;
        generated_at: string;
        utc_start: string | null;
        utc_end_exclusive: string | null;
        owner_watermarks: Record<
            string,
            { status: string; version: number; watermark: number }
        >;
        drill_down_note: string;
        drill_down?: {
            metric: string;
            basis_watermark: number;
            report_watermark: number;
            reconciled: boolean;
        };
    };
    sections: Record<string, ReportSection>;
};
