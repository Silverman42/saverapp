export type PlanPostedActivity = {
    status: string;
    message: string;
    as_of: string | null;
    source_version: string | null;
    metrics: Array<{
        code: string;
        title: string;
        display: string;
        definition: string;
    }>;
};

export type PlanPostingHistory = {
    status: string;
    as_of: string | null;
    source_version: string | null;
    history: null | {
        data: Array<{
            key: string;
            reference: string;
            component: string;
            title: string;
            amount: string;
            occurred_on: string | null;
            committed_at: string;
            timezone: string | null;
        }>;
        total: number;
        current_page: number;
        last_page: number;
        per_page: number;
    };
};
