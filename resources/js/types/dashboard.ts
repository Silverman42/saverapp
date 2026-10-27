export type DashboardFilters = {
    period: string;
    from: string;
    to: string;
    customer_status?: string;
    plan_status?: string;
    agent?: string;
    agent_basis?: string;
    page_size: number;
};

export type DashboardTab =
    | 'today'
    | 'overview'
    | 'collections'
    | 'requests'
    | 'finance';

export type DashboardMetric = {
    code: string;
    title: string;
    unit: string;
    display: string;
    source: string;
    date_basis: string;
    definition: string;
    drill_down: string | null;
    drill_down_reason: string;
};

export type DashboardRow = {
    reference?: string;
    type?: string;
    state?: string;
    name?: string;
    customer_id?: string;
    plan_id?: string;
    remaining?: string;
    amount?: string;
    occurred_on?: string;
    committed_at?: string;
    href: string;
    can_record_cash?: boolean;
};

export type DashboardManifest = {
    cutoff: string;
    generated_at: string;
    timezone: string;
    timezone_version: number;
    ledger_watermark: number;
    projection_version: number;
    scope_hash: string;
};

export type DashboardSection = DashboardManifest & {
    status: 'Current' | 'Stale' | 'Rebuilding' | 'Unavailable' | 'Partial';
    metrics: DashboardMetric[];
    rows?: DashboardRow[];
    reason?: string;
    note?: string;
    href?: string;
    link_note?: string;
    total?: number;
    trend?: { date: string; display: string }[];
    trend_from?: string;
    trend_to?: string;
};

export type Dashboard = {
    role: 'customer' | 'agent' | 'admin';
    manifest: DashboardManifest;
    sections: Record<string, DashboardSection>;
};
