<?php

namespace App\Services;

use App\Enums\LedgerAccountCode;
use App\Enums\UserType;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FinancialWorkflowReadService
{
    /**
     * @param  Builder<CustomerProfile>  $customers
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function activity(User $viewer, Builder $customers, array $filters, string $cutoff): array
    {
        $state = app(LedgerTransactionReadService::class)->state();
        if ($state['status'] !== 'ready' || DB::table('ledger_integrity_incidents')->where('status', 'open')->exists()) {
            throw new RuntimeException('Verified financial workflow history is unavailable.');
        }
        if (filled($filters['agent'] ?? null) && ($filters['agent_basis'] ?? '') === 'recording') {
            throw new RuntimeException('Financial workflow history requires Customer scope rather than mixed recording attribution.');
        }
        $query = DB::table('ledger_entries as lines')->join('ledger_posting_groups as groups', 'groups.id', '=', 'lines.ledger_posting_group_id')
            ->join('ledger_accounts as accounts', 'accounts.id', '=', 'lines.ledger_account_id')
            ->where('groups.id', '<=', $state['watermark'])->where('groups.committed_at', '<=', $cutoff)
            ->whereIn('groups.customer_profile_id', (clone $customers)->select('id'));
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if (filled($filters[$key] ?? null)) {
                $query->whereDate('groups.occurred_on', $operator, $filters[$key]);
            }
        }
        if (filled($filters['plan'] ?? null)) {
            $query->whereIn('groups.thrift_plan_id', DB::table('thrift_plans')->where('plan_id', $filters['plan'])->select('id'));
        }
        $patterns = [
            'gross_withdrawals' => ['Gross savings debits for paid withdrawals', 'cash_withdrawal', LedgerAccountCode::CustomerSavingsLiability, 'debit'],
            'net_cash_payouts' => ['Cash delivered for paid withdrawals', 'cash_withdrawal', LedgerAccountCode::BusinessCash, 'credit'],
            'withdrawal_fees' => ['Recognized withdrawal fees', 'cash_withdrawal', LedgerAccountCode::FeeIncome, 'credit'],
            'withdrawal_compensation' => ['Savings restored by full payout compensation', 'withdrawal_compensation', LedgerAccountCode::CustomerSavingsLiability, 'credit'],
            'savings_fee_applications' => ['Fees applied from savings', 'savings_fee_application', LedgerAccountCode::FeeIncome, 'credit'],
            'savings_fee_refunds' => ['Fee concessions restored to savings', 'savings_fee_refund', LedgerAccountCode::CustomerSavingsLiability, 'credit'],
            'external_refund_entitlements' => ['External fee refund entitlements', 'external_refund_entitlement', LedgerAccountCode::RefundPayable, 'credit'],
            'external_refund_payments' => ['Cash paid against refund entitlements', 'fee_refund', LedgerAccountCode::RefundPayable, 'debit'],
            'other_deductions' => ['Non-fee savings deductions', 'other_deduction', LedgerAccountCode::CustomerSavingsLiability, 'debit'],
            'deduction_compensation' => ['Savings restored by full deduction compensation', 'deduction_compensation', LedgerAccountCode::CustomerSavingsLiability, 'credit'],
            'controlled_unapplied_receipts' => ['Erroneous receipt tender reclassified to unapplied funds', 'receipt_reclassification', LedgerAccountCode::UnappliedFunds, 'credit'],
        ];
        $metrics = [];
        foreach ($patterns as $code => [$title, $event, $account, $side]) {
            $value = (clone $query)->where('groups.event_type', $event)->where('accounts.code', $account->value)->where('lines.side', $side)->sum('lines.amount_kobo');
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            if ($integer === false || $integer < 0) {
                throw new RuntimeException('Financial workflow control totals exceed the supported range.');
            }
            $metrics[] = app(MetricDefinitionService::class)->make($code, $title, $integer, 'NGN', 'verified immutable ledger owners',
                'business occurrence date through cutoff', $title.'; full authorized filtered scope, independent from pagination.');
        }

        return ['status' => 'Ready', 'reason' => 'Posted financial movements only. Original and compensation movements are shown separately; obligations, liability, reservations and custody are separate positions.',
            'metrics' => $metrics, 'rows' => [], 'columns' => [], 'groups' => [], 'total' => 0, 'next_cursor' => null];
    }

    /** @return array<string, mixed> */
    public function businessPosition(User $viewer): array
    {
        if ($viewer->user_type !== UserType::Admin) {
            throw new RuntimeException('Business-wide liquidity requires Admin scope.');
        }
        $position = app(FinancialCashPosition::class)->read();
        $metrics = [];
        foreach (['cash_kobo' => 'Business cash', 'pending_cash_kobo' => 'Unresolved cash attempt encumbrances',
            'available_cash_kobo' => 'Cash after unresolved attempts', 'undrawn_earnings_kobo' => 'Undrawn recognized earnings after pending draws',
            'free_cash_kobo' => 'Verified free cash after liabilities, payables and encumbrances', 'draw_limit_kobo' => 'Maximum cash-backed earnings draw'] as $key => $title) {
            $metrics[] = app(MetricDefinitionService::class)->make($key, $title, $position[$key], 'NGN', 'verified cash position', 'at cutoff', $title.'; amounts are separate positions and must not be added together.');
        }
        foreach ([[LedgerAccountCode::CustomerSavingsLiability, 'Customer savings liabilities'], [LedgerAccountCode::RefundPayable, 'Unpaid external refund payables'],
            [LedgerAccountCode::UnappliedFunds, 'Controlled unapplied Customer funds']] as [$account, $title]) {
            $metrics[] = app(MetricDefinitionService::class)->make($account->value, $title, app(FinancialCashPosition::class)->balance($account), 'NGN', 'verified account balance', 'at cutoff', $title.'; independent from unpaid fee obligations and earnings.');
        }

        return ['status' => 'Ready', 'reason' => 'Business cash, earnings and cash payment encumbrances remain distinct.', 'metrics' => $metrics,
            'rows' => [], 'columns' => [], 'groups' => [], 'total' => 0, 'next_cursor' => null];
    }
}
