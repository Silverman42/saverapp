export type SettingValue = string | number | boolean | null;
export interface ConfigurationPreview {
    reference: string;
    effective_at: string | null;
    diff: Record<string, { before: SettingValue; after: SettingValue }>;
    effects: string[];
}
export interface SettingsWorkspace {
    business_reference: string;
    version: number;
    initialized: boolean;
    can_manage: boolean;
    values: Record<string, SettingValue>;
    definitions: Record<
        string,
        {
            label: string;
            group: string;
            default: SettingValue;
            editable: boolean;
            help: string;
        }
    >;
    readiness: Record<
        string,
        { state: string; owner: string; blocker: string; version: number }
    >;
    drafts: {
        id: number;
        revision: number;
        base_version: number;
        patch: Record<string, SettingValue>;
        preview: ConfigurationPreview | null;
    }[];
    history: {
        id: number;
        version: number;
        state: string;
        actor: string;
        requested_effective_at: string;
        effective_at: string | null;
        failure_code: string | null;
        changed_codes: string[];
    }[];
}
