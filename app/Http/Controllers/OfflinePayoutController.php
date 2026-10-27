<?php

namespace App\Http\Controllers;

use App\Models\OfflinePayout;
use App\Models\WithdrawalRequest;
use App\Services\OfflinePayoutService;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OfflinePayoutController extends Controller
{
    public function store(WithdrawalRequest $withdrawal, Request $request, OfflinePayoutService $service): RedirectResponse
    {
        $allowed = ['attempt_reference', 'version', 'channel', 'external_reference', 'paid_on', 'note', 'confirmed'];
        $unexpected = array_values(array_diff(array_keys($request->except('_token')), $allowed));
        if ($unexpected !== []) {
            throw ValidationException::withMessages([$unexpected[0] => ['Unknown offline payout field.']]);
        }
        $data = $request->validate([
            'attempt_reference' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1'],
            'channel' => ['required', Rule::in(OfflinePayout::CHANNELS)],
            'external_reference' => [Rule::requiredIf($request->input('channel') === 'bank_transfer'), 'nullable', 'string', 'max:120'],
            'paid_on' => ['required', 'date_format:Y-m-d'], 'note' => ['required', 'string', 'min:3', 'max:500'],
            'confirmed' => ['required', 'accepted'],
        ]);
        $data['version'] = (int) $data['version'];
        $service->record($request->user(), $withdrawal, $data, $request);

        Toast::success('Offline payout recorded', 'The withdrawal was posted as paid outside the platform.');

        return redirect()->route('withdrawals.show', $withdrawal);
    }
}
