export type PlatformMode = 'normal' | 'degraded' | 'financial_freeze' | 'read_only' | 'unavailable';

export interface PlatformStatus {
    mode: PlatformMode;
    version: number | null;
    message: string;
    observed_at: string;
}
