<?php

namespace App\Http\Controllers;

use App\Http\Requests\RecordCashRecoveryRequest;
use App\Models\CashDisbursement;
use App\Models\CashExecution;
use App\Models\CashRecovery;
use App\Models\WithdrawalRequest;
use App\Services\CashRecoveryService;
use App\Services\CollectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CashRecoveryController extends Controller
{
    public function preview(string $kind, string $execution, Request $request, CashRecoveryService $service): JsonResponse
    {
        $source = $kind === 'withdrawal' ? CashExecution::query()->where('execution_reference', $execution)->firstOrFail() : CashDisbursement::query()->where('execution_reference', $execution)->firstOrFail();

        return response()->json($service->preview($request->user(), $source));
    }

    public function record(CashExecution $execution, RecordCashRecoveryRequest $request, CashRecoveryService $service): RedirectResponse
    {
        if (array_diff(array_keys($request->except('_token')), ['recovery_reference', 'evidence', 'confirmed', 'amount_ngn', 'event_type', 'preview_fingerprint']) !== []) {
            throw ValidationException::withMessages(['request' => 'Unexpected recovery instructions.']);
        }
        $data = $request->validated();
        $service->recordReturn($request->user(), $execution, $data['recovery_reference'], $data['evidence'], $request, filled($data['amount_ngn'] ?? null) ? app(CollectionService::class)->amountToKobo($data['amount_ngn']) : null, $data['event_type'] ?? 'return');

        return redirect()->route('withdrawals.show', WithdrawalRequest::findOrFail($execution->withdrawal_request_id));
    }

    public function disbursement(CashDisbursement $execution, RecordCashRecoveryRequest $request, CashRecoveryService $service): RedirectResponse
    {
        $data = $request->validated();
        $type = $data['event_type'] ?? 'return';
        $amount = $type === 'return' ? (filled($data['amount_ngn'] ?? null) ? app(CollectionService::class)->amountToKobo($data['amount_ngn']) : $execution->amount_kobo) : 0;
        $service->recordDisbursementReturn($request->user(), $execution, $data['recovery_reference'], $amount, $data['evidence'], $request, $type);

        return to_route('cash-disbursements.index');
    }

    public function acknowledge(CashRecovery $recovery, Request $request, CashRecoveryService $service): RedirectResponse
    {
        if (array_diff(array_keys($request->except('_token')), ['confirmed']) !== []) {
            throw ValidationException::withMessages(['request' => 'Unexpected recovery acknowledgement.']);
        }
        $request->validate(['confirmed' => ['required', 'accepted']]);
        if ($recovery->cash_disbursement_id !== null) {
            $service->acknowledgeDisbursementReturn($request->user(), $recovery, $request);

            return to_route('cash-disbursements.index');
        }
        $service->acknowledgeReturn($request->user(), $recovery);

        return redirect()->route('withdrawals.show', WithdrawalRequest::findOrFail(CashExecution::findOrFail($recovery->cash_execution_id)->withdrawal_request_id));
    }
}
