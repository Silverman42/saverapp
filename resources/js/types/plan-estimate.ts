export type PlanEstimate = {
    status: string;
    expected_gross: string | null;
    estimated_fee: string | null;
    estimated_payout: string | null;
    settlement_source: string | null;
    message: string;
};
