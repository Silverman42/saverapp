<?php

namespace App\Services;

use App\Enums\FeeLedgerPostingType;
use App\Enums\LedgerAccountClass;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Enums\UserType;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Business-wide fee revenue for the Admin dashboard, read from the Fee Income ledger account.
 */
class RevenueReadService
{
    /** @var array<string, string> Channel codes and titles for money credited to Fee Income. */
    public const CHANNELS = [
        'revenue_collections' => 'Fees paid with collections',
        'revenue_held_funds' => 'Fees paid from held funds',
        'revenue_savings' => 'Fees taken from savings',
        'revenue_withdrawals' => 'Withdrawal fees',
        'revenue_other_channels' => 'Other fee income',
    ];

    /** @var array<string, string> Fee type codes and titles for money credited to Fee Income. */
    public const FEE_TYPES = [
        'revenue_registration_fees' => 'Registration fees',
        'revenue_plan_fees' => 'Plan fees',
        'revenue_manual_fees' => 'Manual fees',
        'revenue_withdrawal_fees' => 'Withdrawal fees',
        'revenue_other_fees' => 'Other fees',
    ];

    public function __construct(private MetricDefinitionService $definitions) {}

    /**
     * Read fee revenue posted with a business occurrence date inside the selected period.
     *
     * @param  array{from: string, to: string}  $filters
     * @return array{summary: array<string, mixed>, channels: array<string, mixed>, fee_types: array<string, mixed>}
     */
    public function read(User $viewer, array $filters, string $cutoff, int $watermark): array
    {
        if ($viewer->user_type !== UserType::Admin) {
            throw new RuntimeException('Business revenue requires Admin scope.');
        }
        $account = LedgerAccount::query()->where('code', LedgerAccountCode::FeeIncome->value)->first();
        if ($account === null || $account->mapping_status !== 'mapped' || $account->account_class !== LedgerAccountClass::FeeIncome
            || $account->normal_balance !== LedgerEntrySide::Credit || $account->currency !== 'NGN') {
            throw new RuntimeException('Fee income ledger mapping is unavailable.');
        }

        $rows = DB::table('ledger_entries as lines')
            ->join('ledger_posting_groups as groups', 'groups.id', '=', 'lines.ledger_posting_group_id')
            ->leftJoin('fee_obligations as obligations', 'obligations.id', '=', 'lines.fee_obligation_id')
            ->where('lines.ledger_account_id', $account->id)
            ->where('groups.id', '<=', $watermark)->where('groups.committed_at', '<=', $cutoff)->where('groups.currency', 'NGN')
            ->whereDate('groups.occurred_on', '>=', $filters['from'])->whereDate('groups.occurred_on', '<=', $filters['to'])
            ->select('groups.event_type', 'lines.side', 'obligations.kind')->selectRaw('SUM(lines.amount_kobo) AS amount')
            ->groupBy('groups.event_type', 'lines.side', 'obligations.kind')
            ->get();

        $channels = array_fill_keys(array_keys(self::CHANNELS), 0);
        $feeTypes = array_fill_keys(array_keys(self::FEE_TYPES), 0);
        $refunds = 0;
        $reversals = 0;
        foreach ($rows as $row) {
            $amount = filter_var($row->amount, FILTER_VALIDATE_INT);
            if ($amount === false || $amount < 0) {
                throw new RuntimeException('Fee revenue totals exceed the supported range.');
            }
            if ($row->side === LedgerEntrySide::Debit->value) {
                if (in_array($row->event_type, [FeeLedgerPostingType::SavingsFeeRefund->value, FeeLedgerPostingType::ExternalRefundEntitlement->value], true)) {
                    $refunds += $amount;
                } else {
                    $reversals += $amount;
                }

                continue;
            }
            $channels[$this->channelFor($row->event_type)] += $amount;
            $feeTypes[$this->feeTypeFor($row->kind, $row->event_type)] += $amount;
        }
        $gross = array_sum($channels);

        $periodNote = 'Business-wide fee income posted from '.$filters['from'].' to '.$filters['to'].'. Only the period filter applies.';

        return [
            'summary' => ['note' => $periodNote, 'metrics' => [
                $this->metric('revenue_gross', 'Gross fee income', $gross, 'All fees credited to the business in the period, before refunds and reversals.'),
                $this->metric('revenue_refunds', 'Fee refunds', $refunds, 'Approved fee concessions returned to savings or owed to Customers.'),
                $this->metric('revenue_reversals', 'Reversals and corrections', $reversals, 'Fee income undone by receipt, withdrawal or other ledger corrections.'),
                $this->metric('revenue_net', 'Net fee income', $gross - $refunds - $reversals, 'Gross fee income less refunds, reversals and corrections. A negative total means earlier income was undone in this period.'),
            ]],
            'channels' => ['note' => 'Where fee income came from. Channels add up to gross fee income.', 'metrics' => array_map(
                fn (string $code, string $title): array => $this->metric($code, $title, $channels[$code], $this->channelDefinition($code)),
                array_keys(self::CHANNELS), self::CHANNELS,
            )],
            'fee_types' => ['note' => 'Which fees earned the income, before refunds and reversals. Fee types add up to gross fee income.', 'metrics' => array_map(
                fn (string $code, string $title): array => $this->metric($code, $title, $feeTypes[$code], $title.' credited to fee income in the period.'),
                array_keys(self::FEE_TYPES), self::FEE_TYPES,
            )],
        ];
    }

    private function channelFor(string $eventType): string
    {
        return match (true) {
            $eventType === FeeLedgerPostingType::ExternalFeeReceipt->value => 'revenue_collections',
            $eventType === FeeLedgerPostingType::UnappliedFeeApplication->value => 'revenue_held_funds',
            $eventType === FeeLedgerPostingType::SavingsFeeApplication->value => 'revenue_savings',
            in_array($eventType, WithdrawalPayoutSource::EVENT_TYPES, true) => 'revenue_withdrawals',
            default => 'revenue_other_channels',
        };
    }

    private function feeTypeFor(?string $kind, string $eventType): string
    {
        return match (true) {
            $kind === 'registration' => 'revenue_registration_fees',
            $kind === 'plan' => 'revenue_plan_fees',
            $kind === 'manual' => 'revenue_manual_fees',
            $kind === null && in_array($eventType, WithdrawalPayoutSource::EVENT_TYPES, true) => 'revenue_withdrawal_fees',
            default => 'revenue_other_fees',
        };
    }

    private function channelDefinition(string $code): string
    {
        return match ($code) {
            'revenue_collections' => 'Fees paid as part of a recorded collection receipt.',
            'revenue_held_funds' => 'Fees paid from Customer money held as unapplied funds after a receipt correction.',
            'revenue_savings' => 'Fees taken directly from Customer savings.',
            'revenue_withdrawals' => 'Fees charged when a withdrawal was paid out.',
            default => 'Fee income from any other posting, such as plan settlement.',
        };
    }

    /** @return array<string, mixed> */
    private function metric(string $code, string $title, int $value, string $definition): array
    {
        $metric = $this->definitions->make($code, $title, abs($value), 'NGN', 'fee income ledger', 'business occurrence date', $definition);
        $metric['value'] = $value;
        if ($value < 0) {
            $metric['display'] = '-'.$metric['display'];
        }

        return $metric;
    }
}
