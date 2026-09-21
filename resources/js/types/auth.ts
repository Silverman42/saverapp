export type UserType = 'customer' | 'agent' | 'admin';

export type AdminPermission =
    | 'admins.manage'
    | 'agents.manage'
    | 'customers.manage'
    | 'customers.reassign'
    | 'withdrawals.review'
    | 'reversals.review'
    | 'fees.manage'
    | 'deductions.manage'
    | 'reconciliation.manage'
    | 'business.settings.manage'
    | 'security.operations.manage'
    | 'audit.view'
    | 'reports.export';

export type AccountState =
    | 'invited'
    | 'mfa_setup_required'
    | 'active'
    | 'temporarily_locked'
    | 'suspended'
    | 'deactivated';

export type User = {
    id: number;
    name: string;
    email: string;
    email_normalized: string;
    user_type: UserType;
    account_state: AccountState;
    permission_version: number;
    locked_until?: string | null;
    lock_category?: string | null;
    lock_reason?: string | null;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    permissions: AdminPermission[];
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
