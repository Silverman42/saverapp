export type SavingsPosition = {
    status: string;
    message: string;
    liability: string | null;
    reserved: string | null;
    available: string | null;
};

export type PlanSavings = {
    as_of: string | null;
    source_version: string | null;
    customer: SavingsPosition;
    cycle: SavingsPosition;
};
