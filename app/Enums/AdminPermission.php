<?php

namespace App\Enums;

enum AdminPermission: string
{
    case AdminsManage = 'admins.manage';
    case AgentsManage = 'agents.manage';
    case CustomersManage = 'customers.manage';
    case CustomersReassign = 'customers.reassign';
    case WithdrawalsReview = 'withdrawals.review';
    case ReversalsReview = 'reversals.review';
    case FeesManage = 'fees.manage';
    case DeductionsManage = 'deductions.manage';
    case ReconciliationManage = 'reconciliation.manage';
    case BusinessSettingsManage = 'business.settings.manage';
    case SecurityOperationsManage = 'security.operations.manage';
    case AuditView = 'audit.view';
    case ReportsExport = 'reports.export';

    /**
     * Get the human-readable display name for this permission.
     */
    public function displayName(): string
    {
        return match ($this) {
            self::AdminsManage => 'Admin management',
            self::AgentsManage => 'Agent management',
            self::CustomersManage => 'Customer management',
            self::CustomersReassign => 'Customer reassignment',
            self::WithdrawalsReview => 'Withdrawal approval',
            self::ReversalsReview => 'Transaction-reversal approval',
            self::FeesManage => 'Fee management',
            self::DeductionsManage => 'Deduction management',
            self::ReconciliationManage => 'Reconciliation management',
            self::BusinessSettingsManage => 'Business configuration',
            self::SecurityOperationsManage => 'Security operations',
            self::AuditView => 'Audit-log access',
            self::ReportsExport => 'Report export',
        };
    }

    /**
     * Get the authoritative description of authority granted for this permission.
     */
    public function description(): string
    {
        return match ($this) {
            self::AdminsManage => "Invite Admins; assign initial Admin permissions; change another Admin's permissions; suspend, reactivate, or deactivate another Admin; manage Admin invitations; and perform the Admin-recovery actions assigned to this permission.",
            self::AgentsManage => 'Register, invite, update, suspend, reactivate, deactivate, and manage invitation actions for Agents. Agent assisted recovery remains a security operation.',
            self::CustomersManage => 'Update existing Customer profiles and statuses business-wide and manage existing Customer invitations. It does not allow an Admin to create a Customer.',
            self::CustomersReassign => 'Reassign a Customer from one Agent to another through the approved reassignment workflow.',
            self::WithdrawalsReview => 'Review, approve, or reject Customer withdrawal requests. It does not record a collection or bypass withdrawal validation.',
            self::ReversalsReview => 'Review, approve, or reject transaction-reversal requests. It does not silently edit the original transaction.',
            self::FeesManage => 'Configure fee rules and perform the Admin fee actions defined by the Fees module. It does not merge fee earnings with Customer liabilities.',
            self::DeductionsManage => 'Perform the Admin deduction actions defined by the Deductions module, subject to confirmation and audit rules.',
            self::ReconciliationManage => 'Review Agent collection submissions, record reconciliation outcomes, and resolve reconciliation exceptions through the approved workflow.',
            self::BusinessSettingsManage => 'Update general and operational business settings. Security-sensitive changes require fresh authentication.',
            self::SecurityOperationsManage => 'View non-secret security events, review Customer assisted recovery, manage permitted authentication locks, and perform the Agent security operations assigned to this permission.',
            self::AuditView => 'Search and view business audit events, subject to masking and export restrictions.',
            self::ReportsExport => 'Export business reports and statements containing business-wide or multi-Customer information.',
        };
    }

    /**
     * Get all permission values as an array of strings.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
