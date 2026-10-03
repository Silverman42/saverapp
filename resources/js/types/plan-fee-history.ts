export type PlanFeeHistory = {
    status: string;
    message: string;
    as_of: string | null;
    source_version: string | null;
    totals: {
        original_assessed: string;
        assessed: string;
        settled: string;
        waived: string;
        outstanding: string;
    } | null;
    history: {
        data: Array<{
            id: number;
            fee_name: string | null;
            kind_label: string;
            type: string;
            amount: string;
            description: string | null;
            reference: string | null;
            recorded_at: string | null;
        }>;
        total: number;
        current_page: number;
        last_page: number;
        per_page: number;
    } | null;
};
