<?php

namespace App\Services;

use App\Enums\LedgerAccountClass;
use App\Enums\LedgerAccountCode;
use App\Enums\UserType;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Support\MoneyFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
        $this->requireActivityMappings();
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
        $patterns = $this->patterns();
        $totals = array_fill_keys(array_keys($patterns), 0);
        foreach ($patterns as $code => [$title, $event, $account, $side]) {
            $value = (clone $query)->whereIn('groups.event_type', (array) $event)->where('accounts.code', $account->value)->where('lines.side', $side)->sum('lines.amount_kobo');
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            if ($integer === false || $integer < 0) {
                throw new RuntimeException('Financial workflow control totals exceed the supported range.');
            }
            $totals[$code] = $integer;
        }

        $withdrawalReturns = (clone $query)->where('groups.event_type', 'cash_return')->where('accounts.code', LedgerAccountCode::BusinessCash->value)->where('lines.side', 'debit')
            ->whereIn('groups.source_id', DB::table('cash_recoveries')->whereNotNull('cash_execution_id')->select('id'))->sum('lines.amount_kobo');
        $returned = filter_var($withdrawalReturns, FILTER_VALIDATE_INT);
        if ($returned === false || $returned < 0) {
            throw new RuntimeException('Returned withdrawal cash exceeds the supported range.');
        }

        return ['status' => 'Ready', 'reason' => 'Posted financial movements only. Original and compensation movements are shown separately; obligations, liability, reservations and custody are separate positions.',
            'metrics' => $this->metrics($totals, $returned), 'rows' => [], 'columns' => [], 'groups' => [], 'total' => 0, 'next_cursor' => null];
    }

    /**
     * @param  array<string, int>  $totals
     * @return list<array<string, mixed>>
     */
    private function metrics(array $totals, int $returned): array
    {
        $metrics = [];
        foreach ($this->patterns() as $code => [$title]) {
            $metrics[] = app(MetricDefinitionService::class)->make($code, $title, $totals[$code], 'NGN', 'verified immutable ledger owners',
                'business occurrence date through cutoff', $title.'; full authorized filtered scope, independent from pagination.');
        }
        foreach (['effective_withdrawal_debits' => ['Effective savings withdrawal debits', $totals['gross_withdrawals'] - $totals['withdrawal_compensation']],
            'withdrawal_cash_returns' => ['Confirmed withdrawal cash returns', $returned],
            'effective_withdrawal_cash_paid' => ['Effective withdrawal cash paid', $totals['net_cash_payouts'] - $returned],
            'effective_external_refund_payments' => ['Effective external refund payments', $totals['external_refund_payments'] - $totals['recovered_refund_payables']],
            'effective_savings_fee_applications' => ['Effective savings fee payments', $totals['savings_fee_applications'] - $totals['savings_fee_compensation']],
            'effective_deductions' => ['Effective savings deductions', $totals['other_deductions'] - $totals['deduction_compensation']]] as $code => [$title, $value]) {
            $metric = app(MetricDefinitionService::class)->make($code, $title, $value < 0 ? -$value : $value, 'NGN', 'verified immutable ledger owners',
                'business occurrence date through cutoff', $title.'; gross activity less the linked compensation or confirmed return in the selected scope and date window. A negative window total represents compensation of earlier activity. Partial cash recovery changes cash custody only, not savings.');
            $metric['value'] = $value;
            if ($value < 0) {
                $metric['display'] = '-'.$metric['display'];
            }
            $metrics[] = $metric;
        }

        return $metrics;
    }

    /** @return array<string, array{string, string|list<string>, LedgerAccountCode, string}> */
    private function patterns(): array
    {
        return [
            'gross_withdrawals' => ['Gross savings debits for paid withdrawals', WithdrawalPayoutSource::EVENT_TYPES, LedgerAccountCode::CustomerSavingsLiability, 'debit'],
            'net_cash_payouts' => ['Cash delivered for paid withdrawals', 'cash_withdrawal', LedgerAccountCode::BusinessCash, 'credit'],
            'net_bank_payouts' => ['Bank transfers sent for paid withdrawals', 'bank_withdrawal', LedgerAccountCode::PayoutClearing, 'credit'],
            'withdrawal_fees' => ['Fees in original withdrawal postings', WithdrawalPayoutSource::EVENT_TYPES, LedgerAccountCode::FeeIncome, 'credit'],
            'withdrawal_deductions' => ['Deductions in original withdrawal postings', WithdrawalPayoutSource::EVENT_TYPES, LedgerAccountCode::OtherDeductionDestination, 'credit'],
            'withdrawal_compensation' => ['Savings restored by full payout compensation', 'withdrawal_compensation', LedgerAccountCode::CustomerSavingsLiability, 'credit'],
            'savings_fee_applications' => ['Fees applied from savings', 'savings_fee_application', LedgerAccountCode::FeeIncome, 'credit'],
            'savings_fee_compensation' => ['Savings restored by fee payment compensation', 'fee_application_compensation', LedgerAccountCode::CustomerSavingsLiability, 'credit'],
            'savings_fee_refunds' => ['Fee concessions restored to savings', 'fee_refund', LedgerAccountCode::CustomerSavingsLiability, 'credit'],
            'external_refund_entitlements' => ['External fee refund entitlements', 'external_refund_entitlement', LedgerAccountCode::RefundPayable, 'credit'],
            'external_refund_payments' => ['Cash paid against refund entitlements', 'fee_refund', LedgerAccountCode::RefundPayable, 'debit'],
            'other_deductions' => ['Non-fee savings deductions', 'other_deduction', LedgerAccountCode::CustomerSavingsLiability, 'debit'],
            'deduction_compensation' => ['Savings restored by full deduction compensation', 'deduction_compensation', LedgerAccountCode::CustomerSavingsLiability, 'credit'],
            'confirmed_cash_returns' => ['Confirmed cash returned to controlled business custody', 'cash_return', LedgerAccountCode::BusinessCash, 'debit'],
            'recovered_refund_payables' => ['Original unpaid refund entitlements restored by full recovery', 'disbursement_compensation', LedgerAccountCode::RefundPayable, 'credit'],
            'replacement_allocations' => ['Controlled funds consumed by corrected receipt allocations', 'cash_savings', LedgerAccountCode::UnappliedFunds, 'debit'],
            'controlled_unapplied_receipts' => ['Erroneous receipt tender reclassified to unapplied funds', 'receipt_reclassification', LedgerAccountCode::UnappliedFunds, 'credit'],
        ];
    }

    /**
     * Read within the caller's snapshot with its authorized Customer scope.
     *
     * @param  Builder<CustomerProfile>  $customers
     * @param  list<int>  $planIds
     * @return array<int, list<array<string, mixed>>|null>
     */
    public function activityMany(Builder $customers, array $planIds, string $cutoff): array
    {
        if (count($planIds) > 100 || count(array_unique($planIds)) !== count($planIds)) {
            throw new \InvalidArgumentException('Read at most 100 distinct cycle activity summaries.');
        }
        if ($planIds === []) {
            return [];
        }
        $state = app(LedgerTransactionReadService::class)->state();
        if ($state['status'] !== 'ready' || DB::table('ledger_integrity_incidents')->where('status', 'open')->exists()) {
            throw new RuntimeException('Verified financial workflow history is unavailable.');
        }
        $this->requireActivityMappings();
        $query = DB::table('ledger_entries as lines')->join('ledger_posting_groups as groups', 'groups.id', '=', 'lines.ledger_posting_group_id')
            ->join('ledger_accounts as accounts', 'accounts.id', '=', 'lines.ledger_account_id')
            ->join('thrift_plans as activity_plans', 'activity_plans.id', '=', 'groups.thrift_plan_id')
            ->whereColumn('groups.customer_profile_id', 'activity_plans.customer_profile_id')
            ->where('groups.id', '<=', $state['watermark'])->where('groups.committed_at', '<=', $cutoff)
            ->whereIn('groups.customer_profile_id', (clone $customers)->select('id'))->whereIn('groups.thrift_plan_id', $planIds);
        $rows = (clone $query)->select('groups.thrift_plan_id', 'groups.event_type', 'accounts.code', 'lines.side')
            ->selectRaw('SUM(lines.amount_kobo) AS amount')
            ->groupBy('groups.thrift_plan_id', 'groups.event_type', 'accounts.code', 'lines.side')->get()->groupBy('thrift_plan_id');
        $returns = (clone $query)->where('groups.event_type', 'cash_return')->where('accounts.code', LedgerAccountCode::BusinessCash->value)->where('lines.side', 'debit')
            ->whereIn('groups.source_id', DB::table('cash_recoveries')->whereNotNull('cash_execution_id')->select('id'))
            ->select('groups.thrift_plan_id')->selectRaw('SUM(lines.amount_kobo) AS amount')->groupBy('groups.thrift_plan_id')->get()->keyBy('thrift_plan_id');
        $result = array_fill_keys($planIds, null);
        foreach ($planIds as $planId) {
            $totals = array_fill_keys(array_keys($this->patterns()), 0);
            $valid = true;
            foreach ($this->patterns() as $code => [$title, $event, $account, $side]) {
                $amount = 0;
                foreach ($rows->get($planId, collect())->filter(fn ($row): bool => in_array($row->event_type, (array) $event, true) && $row->code === $account->value && $row->side === $side) as $row) {
                    $part = filter_var($row->amount ?? 0, FILTER_VALIDATE_INT);
                    if ($part === false || $part < 0) {
                        $valid = false;
                        break 2;
                    }
                    $amount += $part;
                }
                $totals[$code] = $amount;
            }
            $returned = filter_var($returns->get($planId)->amount ?? 0, FILTER_VALIDATE_INT);
            if ($valid && $returned !== false && $returned >= 0) {
                $result[$planId] = $this->metrics($totals, $returned);
            }
        }

        return $result;
    }

    /**
     * Read posting components, not additive transaction totals, within the caller's snapshot.
     *
     * @param  Builder<CustomerProfile>  $customers
     * @return array<string, mixed>
     */
    public function postingHistory(Builder $customers, int $planId, string $cutoff, int $page = 1, int $perPage = 25): array
    {
        if ($page < 1 || $page > 1000000 || ! in_array($perPage, [25, 50, 100], true)) {
            throw new \InvalidArgumentException('Unsupported posting history pagination.');
        }
        if (($this->activityMany($customers, [$planId], $cutoff)[$planId] ?? null) === null) {
            throw new RuntimeException('Verified cycle posting history is unavailable.');
        }
        $state = app(LedgerTransactionReadService::class)->state();
        $patterns = array_intersect_key($this->patterns(), array_flip([
            'gross_withdrawals', 'net_cash_payouts', 'net_bank_payouts', 'withdrawal_fees', 'withdrawal_deductions', 'withdrawal_compensation',
            'other_deductions', 'deduction_compensation', 'confirmed_cash_returns',
        ]));
        $query = DB::table('ledger_entries as lines')
            ->join('ledger_posting_groups as groups', 'groups.id', '=', 'lines.ledger_posting_group_id')
            ->join('ledger_accounts as accounts', 'accounts.id', '=', 'lines.ledger_account_id')
            ->join('thrift_plans as plans', 'plans.id', '=', 'groups.thrift_plan_id')
            ->whereColumn('groups.customer_profile_id', 'plans.customer_profile_id')
            ->whereIn('groups.customer_profile_id', (clone $customers)->select('id'))
            ->where('groups.thrift_plan_id', $planId)->where('groups.id', '<=', $state['watermark'])
            ->where('groups.committed_at', '<=', $cutoff)->where('groups.currency', 'NGN')
            ->where(function (QueryBuilder $query) use ($patterns): void {
                foreach ($patterns as [$title, $event, $account, $side]) {
                    $query->orWhere(function (QueryBuilder $query) use ($event, $account, $side): void {
                        $query->whereIn('groups.event_type', (array) $event)->where('accounts.code', $account->value)->where('lines.side', $side);
                    });
                }
            });
        $history = $query->orderByDesc('groups.committed_at')->orderByDesc('lines.id')
            ->paginate($perPage, ['groups.posting_reference', 'groups.event_type', 'groups.occurred_on', 'groups.committed_at',
                'groups.business_timezone', 'accounts.code', 'lines.side', 'lines.line_number', 'lines.amount_kobo'], 'activity_page', $page);
        $data = [];
        foreach ($history->items() as $row) {
            $amount = filter_var($row->amount_kobo, FILTER_VALIDATE_INT);
            if ($amount === false || $amount < 0) {
                throw new RuntimeException('Verified cycle posting amount is unavailable.');
            }
            foreach ($patterns as $code => [$title, $event, $account, $side]) {
                if (in_array($row->event_type, (array) $event, true) && $row->code === $account->value && $row->side === $side) {
                    $data[] = ['key' => $row->posting_reference.':'.$row->line_number, 'reference' => $row->posting_reference,
                        'component' => $code, 'title' => $title, 'amount' => MoneyFormatter::formatNaira($amount),
                        'occurred_on' => $row->occurred_on, 'committed_at' => $row->committed_at, 'timezone' => $row->business_timezone];
                    break;
                }
            }
        }

        return ['data' => $data, 'total' => $history->total(), 'current_page' => $history->currentPage(),
            'last_page' => $history->lastPage(), 'per_page' => $history->perPage()];
    }

    private function requireActivityMappings(): void
    {
        $definitions = [
            LedgerAccountCode::CustomerSavingsLiability->value => [LedgerAccountClass::CustomerSavingsLiability->value, 'credit'],
            LedgerAccountCode::BusinessCash->value => [LedgerAccountClass::Asset->value, 'debit'],
            LedgerAccountCode::FeeIncome->value => [LedgerAccountClass::FeeIncome->value, 'credit'],
            LedgerAccountCode::RefundPayable->value => [LedgerAccountClass::RefundPayable->value, 'credit'],
            LedgerAccountCode::UnappliedFunds->value => [LedgerAccountClass::UnappliedFunds->value, 'credit'],
        ];
        $accounts = DB::table('ledger_accounts')->whereIn('code', array_keys($definitions))->get()->keyBy('code');
        foreach ($definitions as $code => [$class, $side]) {
            $account = $accounts->get($code);
            if ($account === null || $account->mapping_status !== 'mapped' || $account->currency !== 'NGN'
                || $account->account_class !== $class || $account->normal_balance !== $side || $account->version < 1) {
                throw new RuntimeException('Financial movement account mappings are unavailable.');
            }
        }
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
            [LedgerAccountCode::CashRecoveryClearing, 'Returned cash awaiting full compensation'], [LedgerAccountCode::UnappliedFunds, 'Controlled unapplied Customer funds']] as [$account, $title]) {
            $metrics[] = app(MetricDefinitionService::class)->make($account->value, $title, app(FinancialCashPosition::class)->balance($account), 'NGN', 'verified account balance', 'at cutoff', $title.'; independent from unpaid fee obligations and earnings.');
        }

        return ['status' => 'Ready', 'reason' => 'Business cash, earnings and cash payment encumbrances remain distinct.', 'metrics' => $metrics,
            'rows' => [], 'columns' => [], 'groups' => [], 'total' => 0, 'next_cursor' => null];
    }
}
