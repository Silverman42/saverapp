<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeeObligation;
use App\Services\FeeRefundService;
use App\Support\MoneyAmount;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FeeRefundController extends Controller
{
    public function store(FeeObligation $obligation, Request $request, FeeRefundService $service): RedirectResponse
    {
        if (array_diff(array_keys($request->except('_token')), ['refund_reference', 'kind', 'amount_ngn', 'reason', 'confirmed']) !== []) {
            throw ValidationException::withMessages(['request' => 'Unexpected refund instructions.']);
        }
        $data = $request->validate(['refund_reference' => ['required', 'uuid'], 'kind' => ['required', 'in:savings,external'],
            'amount_ngn' => ['required', 'string', 'max:14'], 'reason' => ['required', 'string', 'max:500'], 'confirmed' => ['required', 'accepted']]);
        try {
            $amount = MoneyAmount::parseNairaToKobo($data['amount_ngn']);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['amount_ngn' => $exception->getMessage()]);
        }
        $service->authorizeRefund($request->user(), $obligation, $data['refund_reference'], $data['kind'], $amount, $data['reason'], $request);

        Toast::success('Refund authorized', 'The fee refund is ready for cash disbursement.');

        return redirect()->route('cash-disbursements.index');
    }
}
