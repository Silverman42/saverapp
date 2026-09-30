<?php

namespace App\Http\Controllers;

use App\Models\CashExecution;
use App\Models\CashRecovery;
use App\Models\WithdrawalRequest;
use App\Services\CashRecoveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CashRecoveryController extends Controller
{
    public function record(CashExecution $execution, Request $request, CashRecoveryService $service): RedirectResponse
    {
        if (array_diff(array_keys($request->except('_token')), ['recovery_reference', 'evidence', 'confirmed']) !== []) {
            throw ValidationException::withMessages(['request' => 'Unexpected recovery instructions.']);
        }
        $data = $request->validate(['recovery_reference' => ['required', 'uuid'], 'evidence' => ['required', 'string', 'max:1000'], 'confirmed' => ['required', 'accepted']]);
        $service->recordReturn($request->user(), $execution, $data['recovery_reference'], $data['evidence'], $request);

        return redirect()->route('withdrawals.show', WithdrawalRequest::findOrFail($execution->withdrawal_request_id));
    }

    public function acknowledge(CashRecovery $recovery, Request $request, CashRecoveryService $service): RedirectResponse
    {
        if (array_diff(array_keys($request->except('_token')), ['confirmed']) !== []) {
            throw ValidationException::withMessages(['request' => 'Unexpected recovery acknowledgement.']);
        }
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $service->acknowledgeReturn($request->user(), $recovery);

        return redirect()->route('withdrawals.show', WithdrawalRequest::findOrFail(CashExecution::findOrFail($recovery->cash_execution_id)->withdrawal_request_id));
    }
}
