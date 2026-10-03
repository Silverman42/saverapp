export type PlanFundingSummary = {
    status: string;
    message: string;
    as_of: string | null;
    source_version: string | null;
    funded_principal: string | null;
    remaining_scheduled_target: string | null;
    required_slots: string | null;
    fully_funded_slots: string | null;
    partially_funded_slots: string | null;
};
