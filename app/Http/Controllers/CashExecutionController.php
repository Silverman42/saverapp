<?php

namespace App\Http\Controllers;

use App\Models\CashExecution;
use App\Models\WithdrawalRequest;
use App\Services\CashExecutionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CashExecutionController extends Controller
{
    public function start(WithdrawalRequest $withdrawal, Request $request, CashExecutionService $service): RedirectResponse
    {
        $this->rejectUnknown($request, ['execution_reference', 'version', 'evidence', 'confirmed']);
        $data = $request->validate(['execution_reference' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1'],
            'evidence' => ['required', 'string', 'min:1', 'max:500'], 'confirmed' => ['required', 'accepted']]);
        $service->start($request->user(), $withdrawal, $data['execution_reference'], (int) $data['version'], $data['evidence'], $request);

        return redirect()->route('withdrawals.show', $withdrawal);
    }

    public function handoff(CashExecution $execution, Request $request, CashExecutionService $service): RedirectResponse
    {
        $evidence = $this->evidence($request);
        $service->recordHandoff($request->user(), $execution, $evidence, $request);

        return $this->show($execution);
    }

    public function notDelivered(CashExecution $execution, Request $request, CashExecutionService $service): RedirectResponse
    {
        $evidence = $this->evidence($request);
        $service->confirmNoHandoff($request->user(), $execution, $evidence, $request);

        return $this->show($execution);
    }

    public function acknowledge(CashExecution $execution, Request $request, CashExecutionService $service): RedirectResponse
    {
        $this->rejectUnknown($request, ['confirmed']);
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $service->confirmReceipt($request->user(), $execution);

        return $this->show($execution);
    }

    private function evidence(Request $request): string
    {
        $this->rejectUnknown($request, ['evidence', 'confirmed']);
        $data = $request->validate(['evidence' => ['required', 'string', 'min:1', 'max:500'], 'confirmed' => ['required', 'accepted']]);

        return $data['evidence'];
    }

    /** @param list<string> $allowed */
    private function rejectUnknown(Request $request, array $allowed): void
    {
        $unexpected = array_values(array_diff(array_keys($request->except('_token')), $allowed));
        if ($unexpected !== []) {
            throw ValidationException::withMessages([$unexpected[0] => ['Unknown cash execution field.']]);
        }
    }

    private function show(CashExecution $execution): RedirectResponse
    {
        return redirect()->route('withdrawals.show', WithdrawalRequest::query()->findOrFail($execution->withdrawal_request_id));
    }
}
