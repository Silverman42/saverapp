<?php

namespace App\Http\Controllers;

use App\Enums\AdminPermission;
use App\Models\CashDisbursement;
use App\Models\CashRecovery;
use App\Models\FeeObligation;
use App\Models\FeeRefund;
use App\Services\AuthorizationService;
use App\Services\CashDisbursementService;
use App\Services\ResourceScopeService;
use App\Support\MoneyAmount;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CashDisbursementController extends Controller
{
    public function index(Request $request, ResourceScopeService $scope, AuthorizationService $authorization): Response
    {
        $actor = $request->user();
        $canExecute = $authorization->allows($actor, AdminPermission::CashExecute);
        $canFees = $authorization->allows($actor, AdminPermission::FeesManage);
        $query = CashDisbursement::query()->where(function ($query) use ($actor, $scope, $canExecute): void {
            $query->where('recipient_user_id', $actor->id);
            if ($canExecute) {
                $query->orWhereIn('customer_profile_id', $scope->forCustomers($actor)->select('id'));
            }
        });

        return Inertia::render('withdrawals/CashDisbursements', ['executions' => $query->latest('id')->limit(100)->get()->map(fn ($execution): array => [
            ...$execution->only(['execution_reference', 'kind', 'amount_kobo', 'status']),
            'recoveries' => CashRecovery::query()->where('cash_disbursement_id', $execution->id)->get(['recovery_reference', 'event_type', 'status', 'amount_kobo']), 'can_attest' => $canExecute && $execution->executor_user_id === $actor->id,
            'can_acknowledge' => $execution->recipient_user_id === $actor->id && ($execution->kind !== 'earnings_draw' || ($canExecute && $canFees)),
        ]), 'refunds' => ($canExecute || $canFees) ? FeeRefund::query()->where('kind', 'external')
            ->whereIn('customer_profile_id', $scope->forCustomers($actor)->select('id'))
            ->whereNotIn('id', CashDisbursement::query()->whereIn('status', ['processing', 'outcome_unknown', 'posted'])->whereNotNull('fee_refund_id')->whereNotExists(fn ($query) => $query->selectRaw('1')->from('ledger_posting_groups as recovered')->where('recovered.source_type', 'disbursement_recovery')->whereColumn('recovered.source_id', 'cash_disbursements.id'))->select('fee_refund_id'))
            ->latest('id')->limit(100)->get(['refund_reference', 'amount_kobo']) : [],
            'refund_enabled' => config('fees.refunds_enabled', false), 'can_refund' => $canFees, 'refundable_obligations' => $canFees ? FeeObligation::query()->whereIn('customer_profile_id', $scope->forCustomers($actor)->select('id'))->with('entries')->latest('id')->limit(100)->get()->filter(fn ($obligation): bool => $obligation->settledAmountKobo() > 0)->map(fn ($obligation): array => ['id' => $obligation->id, 'description' => $obligation->customer_description, 'settled_kobo' => $obligation->settledAmountKobo()])->values() : [],
            'can_execute' => $canExecute, 'can_draw' => $canExecute && $canFees, 'enabled' => config('fees.cash_disbursements_enabled', false)]);
    }

    public function refund(FeeRefund $refund, Request $request, CashDisbursementService $service): RedirectResponse
    {
        $data = $this->startData($request, false);
        $service->start($request->user(), $data['execution_reference'], $refund, $refund->amount_kobo, $data['evidence'], $request);

        Toast::success('Refund started', 'The fee refund is ready for cash handoff.');

        return redirect()->route('cash-disbursements.index');
    }

    public function draw(Request $request, CashDisbursementService $service): RedirectResponse
    {
        $data = $this->startData($request, true);
        try {
            $amount = MoneyAmount::parseNairaToKobo($data['amount_ngn']);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['amount_ngn' => $exception->getMessage()]);
        }
        $service->start($request->user(), $data['execution_reference'], null, $amount, $data['evidence'], $request);

        Toast::success('Draw started', 'The earnings draw is ready for cash handoff.');

        return redirect()->route('cash-disbursements.index');
    }

    public function handoff(CashDisbursement $execution, Request $request, CashDisbursementService $service): RedirectResponse
    {
        $this->strict($request, ['evidence', 'delivered', 'confirmed']);
        $data = $request->validate(['evidence' => ['required', 'string', 'max:1000'], 'delivered' => ['required', 'boolean'], 'confirmed' => ['required', 'accepted']]);
        $service->handoff($request->user(), $execution, (bool) $data['delivered'], $data['evidence'], $request);

        Toast::success('Cash handed off', 'The disbursement handoff was recorded.');

        return redirect()->route('cash-disbursements.index');
    }

    public function acknowledge(CashDisbursement $execution, Request $request, CashDisbursementService $service): RedirectResponse
    {
        $this->strict($request, ['confirmed']);
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $service->acknowledge($request->user(), $execution, $request);

        Toast::success('Receipt acknowledged', 'The cash disbursement was acknowledged.');

        return redirect()->route('cash-disbursements.index');
    }

    /** @return array<string, mixed> */
    private function startData(Request $request, bool $draw): array
    {
        $this->strict($request, $draw ? ['execution_reference', 'amount_ngn', 'evidence', 'confirmed'] : ['execution_reference', 'evidence', 'confirmed']);

        return $request->validate(['execution_reference' => ['required', 'uuid'], 'evidence' => ['required', 'string', 'max:1000'],
            'amount_ngn' => [$draw ? 'required' : 'prohibited', 'string', 'max:14'], 'confirmed' => ['required', 'accepted']]);
    }

    /** @param list<string> $allowed */
    private function strict(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->except('_token')), $allowed) !== []) {
            throw ValidationException::withMessages(['request' => 'Unexpected cash payment instructions.']);
        }
    }
}
