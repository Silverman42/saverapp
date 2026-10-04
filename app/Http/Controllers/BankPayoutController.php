<?php

namespace App\Http\Controllers;

use App\Models\BankPayoutAttempt;
use App\Models\WithdrawalRequest;
use App\Services\BankPayoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BankPayoutController extends Controller
{
    public function start(WithdrawalRequest $withdrawal, Request $request, BankPayoutService $service): RedirectResponse
    {
        $this->rejectUnknown($request, ['attempt_reference', 'version', 'confirmed']);
        $data = $request->validate(['attempt_reference' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1'],
            'confirmed' => ['required', 'accepted']]);
        $service->start($request->user(), $withdrawal, $data['attempt_reference'], (int) $data['version'], $request);

        return redirect()->route('withdrawals.show', $withdrawal);
    }

    public function check(BankPayoutAttempt $attempt, Request $request, BankPayoutService $service): RedirectResponse
    {
        $this->rejectUnknown($request, []);
        $service->check($request->user(), $attempt);

        return redirect()->route('withdrawals.show', WithdrawalRequest::query()->findOrFail($attempt->withdrawal_request_id));
    }

    /** @param list<string> $allowed */
    private function rejectUnknown(Request $request, array $allowed): void
    {
        $unexpected = array_values(array_diff(array_keys($request->except('_token')), $allowed));
        if ($unexpected !== []) {
            throw ValidationException::withMessages([$unexpected[0] => ['Unknown bank payout field.']]);
        }
    }
}
