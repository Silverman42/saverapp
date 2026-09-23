<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminPermission;
use App\Enums\FeeAssessmentCorrectionDirection;
use App\Enums\LedgerAccountClass;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Http\Controllers\Controller;
use App\Http\Requests\CorrectFeeObligationRequest;
use App\Http\Requests\WaiveFeeObligationRequest;
use App\Models\FeeObligation;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\FeeObligationService;
use App\Support\MoneyAmount;
use App\Support\MoneyFormatter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FeeOverviewController extends Controller
{
    public function index(Request $request, AuthorizationService $authorizationService): Response
    {
        /** @var User $viewer */
        $viewer = $request->user();
        abort_unless($authorizationService->allows($viewer, AdminPermission::FeesManage), 403, 'Unauthorized to view fee management.');

        $obligations = FeeObligation::query()
            ->with(['customerProfile.user', 'feeSnapshot', 'entries'])
            ->latest('id')
            ->paginate(25)
            ->through(fn (FeeObligation $obligation): array => $this->serializeObligation($obligation));

        $outstandingTotalKobo = 0;
        $obligationCount = 0;
        $pendingCount = 0;
        FeeObligation::query()->with('entries')->chunkById(500, function ($batch) use (&$outstandingTotalKobo, &$obligationCount, &$pendingCount): void {
            foreach ($batch as $obligation) {
                $outstandingKobo = $obligation->outstandingAmountKobo();
                if ($outstandingKobo > PHP_INT_MAX - $outstandingTotalKobo) {
                    throw new \OverflowException('Business outstanding fee total exceeds the supported integer range.');
                }

                $obligationCount++;
                $outstandingTotalKobo += $outstandingKobo;
                if ($outstandingKobo > 0) {
                    $pendingCount++;
                }
            }
        });

        $earnings = $this->earningsReport();
        $refundPayable = $this->refundPayableReport();

        return Inertia::render('admin/fees/Index', [
            'summary' => [
                'obligation_count' => $obligationCount,
                'pending_count' => $pendingCount,
                'outstanding_amount_kobo' => $outstandingTotalKobo,
                'formatted_outstanding_amount' => MoneyFormatter::formatNaira($outstandingTotalKobo),
                'earnings' => $earnings,
                'refund_payable' => $refundPayable,
                'as_of' => now()->timezone('Africa/Lagos')->format('Y-m-d H:i'),
            ],
            'obligations' => $obligations,
        ]);
    }

    public function waive(
        WaiveFeeObligationRequest $request,
        int $obligation,
        FeeObligationService $feeObligationService,
    ): RedirectResponse {
        $validated = $request->validated();
        $viewer = $request->user();

        $feeObligationService->waive(
            actor: $viewer,
            obligationId: $obligation,
            amountKobo: $this->parseAmount((string) $validated['amount_ngn']),
            reason: $validated['reason'],
            customerDescription: $validated['customer_description'],
            attemptReference: $validated['attempt_reference'],
            request: $request,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Fee waiver recorded.']);

        return redirect()->route('admin.fees.index');
    }

    public function correct(
        CorrectFeeObligationRequest $request,
        int $obligation,
        FeeObligationService $feeObligationService,
    ): RedirectResponse {
        $validated = $request->validated();
        $viewer = $request->user();

        $feeObligationService->correctUnsettledAssessment(
            actor: $viewer,
            obligationId: $obligation,
            amountKobo: $this->parseAmount((string) $validated['amount_ngn']),
            direction: FeeAssessmentCorrectionDirection::from($validated['direction']),
            reason: $validated['reason'],
            customerDescription: $validated['customer_description'],
            attemptReference: $validated['attempt_reference'],
            request: $request,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Unsettled assessment correction recorded.']);

        return redirect()->route('admin.fees.index');
    }

    /** @return array<string, mixed> */
    private function serializeObligation(FeeObligation $obligation): array
    {
        return [
            'id' => $obligation->id,
            'customer_id' => $obligation->customerProfile->customer_id,
            'customer_name' => $obligation->customerProfile->user->name,
            'kind' => $obligation->kind,
            'rule_name' => $obligation->feeSnapshot->name,
            'amount_kobo' => $obligation->assessedAmountKobo(),
            'formatted_amount' => $obligation->formattedAmount(),
            'settled_amount_kobo' => $obligation->settledAmountKobo(),
            'outstanding_amount_kobo' => $obligation->outstandingAmountKobo(),
            'formatted_outstanding_amount' => MoneyFormatter::formatNaira($obligation->outstandingAmountKobo()),
            'status' => $obligation->status->value,
            'status_label' => $obligation->status->displayName(),
            'can_waive' => $obligation->outstandingAmountKobo() > 0,
            'can_correct' => $obligation->outstandingAmountKobo() > 0
                && $obligation->settledAmountKobo() === 0
                && $obligation->waivedAmountKobo() === 0,
        ];
    }

    /** @return array<string, mixed> */
    private function earningsReport(): array
    {
        $account = LedgerAccount::query()->where('code', LedgerAccountCode::FeeIncome->value)->first();
        if ($account === null
            || $account->mapping_status !== 'mapped'
            || $account->account_class !== LedgerAccountClass::FeeIncome
            || $account->normal_balance !== LedgerEntrySide::Credit
            || $account->currency !== 'NGN') {
            return ['status' => 'unavailable', 'message' => 'Fee income is unavailable until Module 10 maps the approved accounting destination.'];
        }

        $now = now();
        $totals = LedgerEntry::query()
            ->where('ledger_account_id', $account->id)
            ->selectRaw('side, SUM(amount_kobo) as amount_kobo')
            ->groupBy('side')
            ->pluck('amount_kobo', 'side');

        $credits = (int) $totals->get(LedgerEntrySide::Credit->value, 0);
        $debits = (int) $totals->get(LedgerEntrySide::Debit->value, 0);
        if ($debits > $credits) {
            return ['status' => 'unavailable', 'message' => 'Fee income ledger is inconsistent and requires reconciliation.'];
        }

        $todayNet = $this->netEarningsSince($account->id, $now->copy()->startOfDay());
        $monthNet = $this->netEarningsSince($account->id, $now->copy()->startOfMonth());

        return [
            'status' => 'available',
            'lifetime_gross_kobo' => $credits,
            'lifetime_refunds_kobo' => $debits,
            'lifetime_net_kobo' => $credits - $debits,
            'formatted_lifetime_gross' => MoneyFormatter::formatNaira($credits),
            'formatted_lifetime_refunds' => MoneyFormatter::formatNaira($debits),
            'formatted_lifetime_net' => MoneyFormatter::formatNaira($credits - $debits),
            'today_net_kobo' => $todayNet,
            'month_net_kobo' => $monthNet,
            'formatted_today_net' => $this->formatSignedNaira($todayNet),
            'formatted_month_net' => $this->formatSignedNaira($monthNet),
        ];
    }

    /** @return array<string, int|string> */
    private function refundPayableReport(): array
    {
        $account = LedgerAccount::query()->where('code', LedgerAccountCode::RefundPayable->value)->first();
        if ($account === null
            || $account->mapping_status !== 'mapped'
            || $account->account_class !== LedgerAccountClass::RefundPayable
            || $account->normal_balance !== LedgerEntrySide::Credit
            || $account->currency !== 'NGN') {
            return ['status' => 'unavailable', 'message' => 'Refund payable is unavailable until Module 10 maps the approved accounting destination.'];
        }

        $totals = LedgerEntry::query()
            ->where('ledger_account_id', $account->id)
            ->selectRaw('side, SUM(amount_kobo) as amount_kobo')
            ->groupBy('side')
            ->pluck('amount_kobo', 'side');
        $payableKobo = (int) $totals->get(LedgerEntrySide::Credit->value, 0)
            - (int) $totals->get(LedgerEntrySide::Debit->value, 0);
        if ($payableKobo < 0) {
            return ['status' => 'unavailable', 'message' => 'Refund payable ledger is inconsistent and requires reconciliation.'];
        }

        return [
            'status' => 'available',
            'amount_kobo' => $payableKobo,
            'formatted_amount' => MoneyFormatter::formatNaira($payableKobo),
        ];
    }

    private function netEarningsSince(int $accountId, Carbon $from): int
    {
        $amounts = LedgerEntry::query()
            ->where('ledger_account_id', $accountId)
            ->whereHas('postingGroup', fn ($query) => $query->where('committed_at', '>=', $from))
            ->selectRaw('side, SUM(amount_kobo) as amount_kobo')
            ->groupBy('side')
            ->pluck('amount_kobo', 'side');

        $net = (int) $amounts->get(LedgerEntrySide::Credit->value, 0)
            - (int) $amounts->get(LedgerEntrySide::Debit->value, 0);

        return $net;
    }

    private function formatSignedNaira(int $amountKobo): string
    {
        return $amountKobo < 0
            ? '-'.MoneyFormatter::formatNaira(abs($amountKobo))
            : MoneyFormatter::formatNaira($amountKobo);
    }

    private function parseAmount(string $amount): int
    {
        try {
            return MoneyAmount::parseNairaToKobo($amount);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['amount_ngn' => [$exception->getMessage()]]);
        }
    }
}
