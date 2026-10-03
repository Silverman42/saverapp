<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminPermission;
use App\Enums\FeeAssessmentCorrectionDirection;
use App\Enums\LedgerAccountClass;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Http\Controllers\Controller;
use App\Http\Requests\CorrectFeeObligationRequest;
use App\Http\Requests\FeeRegisterRequest;
use App\Http\Requests\WaiveFeeObligationRequest;
use App\Models\FeeObligation;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\FeeObligationService;
use App\Services\FeeRegisterReadService;
use App\Services\FeeSavingsApplicationService;
use App\Support\MoneyAmount;
use App\Support\MoneyFormatter;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FeeOverviewController extends Controller
{
    public function index(FeeRegisterRequest $request, AuthorizationService $authorizationService, FeeRegisterReadService $register): Response
    {
        /** @var User $viewer */
        $viewer = $request->user();
        abort_unless($authorizationService->allows($viewer, AdminPermission::FeesManage), 403, 'Unauthorized to view fee management.');

        $applicationsAvailable = app(FeeSavingsApplicationService::class)->available();
        $filters = $request->filters();
        $result = $register->read($filters, (int) ($request->validated()['page'] ?? 1), $request->url());
        $obligations = $result['obligations']
            ->through(fn (FeeObligation $obligation): array => $this->serializeObligation($obligation, $applicationsAvailable));

        $earnings = $this->earningsReport();
        $refundPayable = $this->refundPayableReport();

        return Inertia::render('admin/fees/Index', [
            'summary' => [
                ...$result['summary'],
                'earnings' => $earnings,
                'refund_payable' => $refundPayable,
                'as_of' => now()->timezone('Africa/Lagos')->format('Y-m-d H:i'),
            ],
            'obligations' => $obligations,
            'filters' => $filters,
            'filter_options' => $register->options(),
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

    public function actionStatus(Request $request, int $obligation, string $attemptReference, FeeObligationService $service): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return response()->json($service->administrativeActionStatus($actor, $obligation, $attemptReference));
    }

    /** @return array<string, mixed> */
    private function serializeObligation(FeeObligation $obligation, bool $applicationsAvailable): array
    {
        $available = app(FeeRegisterReadService::class)->available($obligation);

        return [
            'id' => $obligation->id,
            'customer_id' => $obligation->customerProfile->customer_id,
            'customer_name' => $obligation->customerProfile->user->name,
            'kind' => $obligation->kind,
            'rule_name' => $obligation->feeSnapshot->name ?? 'Agreement unavailable',
            'currency' => $obligation->currency,
            'model' => $available ? $obligation->feeSnapshot->model->value : null,
            'source' => $obligation->source_type,
            'amount_kobo' => $available ? $obligation->assessedAmountKobo() : null,
            'formatted_amount' => $available ? $obligation->formattedAmount() : null,
            'settled_amount_kobo' => $available ? $obligation->settledAmountKobo() : null,
            'formatted_settled_amount' => $available ? MoneyFormatter::formatNaira($obligation->settledAmountKobo()) : null,
            'waived_amount_kobo' => $available ? $obligation->waivedAmountKobo() : null,
            'formatted_waived_amount' => $available ? MoneyFormatter::formatNaira($obligation->waivedAmountKobo()) : null,
            'outstanding_amount_kobo' => $available ? $obligation->outstandingAmountKobo() : null,
            'formatted_outstanding_amount' => $available ? MoneyFormatter::formatNaira($obligation->outstandingAmountKobo()) : null,
            'status' => $available ? $obligation->status->value : 'unavailable',
            'status_label' => $available ? $obligation->status->displayName() : 'History unavailable',
            'can_apply_savings' => $available && $applicationsAvailable && $obligation->outstandingAmountKobo() > 0
                && ($obligation->kind !== 'plan' || ($obligation->feeSnapshot->settlement_source->value === 'savings_application'
                    && $obligation->feeSnapshot->timing->value !== 'withdrawal')),
            'can_waive' => $available && $obligation->outstandingAmountKobo() > 0,
            'can_correct' => $available && $obligation->outstandingAmountKobo() > 0
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

    private function netEarningsSince(int $accountId, CarbonInterface $from): int
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
