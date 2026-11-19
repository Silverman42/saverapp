<?php

namespace App\Http\Controllers;

use App\Enums\LedgerAccountCode;
use App\Models\CustomerProfile;
use App\Models\WithdrawalRequest;
use App\Services\WalletBalanceService;
use App\Services\WalletFeature;
use App\Support\MoneyFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WalletController extends Controller
{
    /** The scoped Customer wallet (Module 18 §8): balance, reservations, availability and wallet history. */
    public function show(string $customer, Request $request, WalletBalanceService $wallets, WalletFeature $feature): Response
    {
        $profile = CustomerProfile::query()->where('customer_id', $customer)->firstOrFail();
        Gate::authorize('view', $profile);
        try {
            $position = $wallets->position($profile);
        } catch (RuntimeException|HttpException) {
            $position = null;
        }
        $account = DB::table('ledger_accounts')->where('code', LedgerAccountCode::CustomerWalletLiability->value)->value('id');
        $history = DB::table('ledger_entries as entries')
            ->join('ledger_posting_groups as groups', 'groups.id', '=', 'entries.ledger_posting_group_id')
            ->where('entries.ledger_account_id', $account)->where('entries.customer_profile_id', $profile->id)
            ->orderByDesc('groups.committed_at')->orderByDesc('entries.id')
            ->select('entries.id', 'entries.side', 'entries.amount_kobo', 'groups.posting_reference', 'groups.event_type',
                'groups.occurred_on', 'groups.committed_at', 'groups.source_type', 'groups.source_id')
            ->paginate(25)->withQueryString()
            ->through(fn (object $row): array => [
                'id' => $row->id, 'reference' => $row->posting_reference, 'type' => $row->event_type,
                'occurred_on' => substr((string) $row->occurred_on, 0, 10),
                'amount_kobo' => $row->side === 'credit' ? (int) $row->amount_kobo : -(int) $row->amount_kobo,
                'amount' => MoneyFormatter::formatNaira((int) $row->amount_kobo),
                'withdrawal_id' => $row->source_type === 'withdrawal'
                    ? WithdrawalRequest::query()->whereKey($row->source_id)->value('withdrawal_id') : null,
            ]);
        $user = $request->user();

        return Inertia::render('wallets/Show', [
            'customer' => ['id' => $profile->customer_id, 'name' => $profile->user?->name],
            'wallet' => $position === null ? null : [
                'liability_kobo' => $position['liability_kobo'], 'reservations_kobo' => $position['reservations_kobo'],
                'available_kobo' => $position['available_kobo'], 'as_of' => $position['as_of'],
            ],
            'active' => $feature->active(),
            'live_payout_id' => WithdrawalRequest::query()->where('live_wallet_customer_profile_id', $profile->id)->value('withdrawal_id'),
            'history' => $history,
            'can_request_payout' => $feature->active() && Gate::forUser($user)->allows('initiateWithdrawal', $profile),
        ]);
    }
}
