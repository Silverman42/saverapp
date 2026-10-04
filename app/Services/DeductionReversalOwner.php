<?php

namespace App\Services;

use App\Enums\LedgerAccountClass;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Models\BusinessProfile;
use App\Models\ChargeCategoryVersion;
use App\Models\CustomerProfile;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerPostingGroup;
use App\Models\ManualCharge;
use App\Models\ReversalRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class DeductionReversalOwner implements ReversalOwnerContract
{
    public function preview(LedgerPostingGroup $original, CustomerProfile $customer, bool $forUpdate): array
    {
        $charge = ManualCharge::query()->where('ledger_posting_group_id', $original->id)->when($forUpdate, fn ($query) => $query->lockForUpdate())->firstOrFail();
        $category = ChargeCategoryVersion::query()->findOrFail($charge->charge_category_version_id);
        $original->load('entries.account');
        if ($charge->customer_profile_id !== $customer->id || $original->source_type !== 'manual_charge' || $original->source_id !== $charge->operation_reference
            || $original->event_type !== 'other_deduction' || $category->kind !== 'deduction' || $original->entries->count() !== 2) {
            throw new ConflictHttpException('The exact approved deduction owner is unavailable.');
        }
        foreach ($original->entries as $line) {
            $code = $line->side === LedgerEntrySide::Debit ? LedgerAccountCode::CustomerSavingsLiability : LedgerAccountCode::OtherDeductionDestination;
            $class = $code === LedgerAccountCode::CustomerSavingsLiability ? LedgerAccountClass::CustomerSavingsLiability : LedgerAccountClass::OtherDeductionDestination;
            if ($line->account->code !== $code || $line->account->account_class !== $class || $line->account->normal_balance !== LedgerEntrySide::Credit
                || $line->account->mapping_status !== 'mapped' || $line->amount_kobo !== $charge->amount_kobo || $line->customer_profile_id !== $customer->id
                || ($line->side === LedgerEntrySide::Debit && $line->thrift_plan_id !== $charge->thrift_plan_id)) {
                throw new ConflictHttpException('Deduction amount or account dimensions do not reconcile.');
            }
        }
        $destination = LedgerAccount::query()->where('code', $category->destination_code)->when($forUpdate, fn ($query) => $query->lockForUpdate())->sole();
        if ($destination->version !== $category->destination_mapping_version) {
            throw new ConflictHttpException('The approved deduction destination changed and requires an owned correction.');
        }
        $retained = DB::table('ledger_entries')->where('ledger_account_id', $destination->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN side = 'credit' THEN amount_kobo ELSE -CAST(amount_kobo AS SIGNED) END), 0) AS balance")->value('balance');
        $amount = filter_var($retained, FILTER_VALIDATE_INT);
        if ($amount === false || $amount < $charge->amount_kobo) {
            throw new ConflictHttpException('The original deduction destination no longer retains the full amount.');
        }
        $summary = ['charge_id' => $charge->id, 'category_version_id' => $category->id, 'destination_mapping_version' => $destination->version,
            'disposition' => 'reverse_erroneous_non_cash_deduction'];
        $dependencies = [['kind' => 'deduction_destination', 'classification' => 'compensable', 'retained_kobo' => $amount]];

        return ['gross_kobo' => $charge->amount_kobo, 'summary' => $summary, 'dependencies' => $dependencies,
            'fingerprint' => hash('sha256', json_encode([$original->payload_hash, $summary, $dependencies, app(ReversalWatermark::class)->forCustomer($original->customer_profile_id)], JSON_THROW_ON_ERROR))];
    }

    public function compensate(ReversalRequest $request, array $preview, User $reviewer): LedgerPostingGroup
    {
        $original = $request->originalPostingGroup;
        $business = BusinessProfile::current();
        $date = now($business->timezone)->toDateString();
        app(FinancialPeriodService::class)->assertOpen($date, $business->timezone, true);
        $group = LedgerPostingGroup::create(['posting_reference' => 'REV-'.Str::uuid(), 'idempotency_key' => 'deduction-compensation-'.$request->id,
            'payload_hash' => $preview['fingerprint'], 'source_type' => 'reversal_request', 'source_id' => (string) $request->id,
            'event_type' => 'deduction_compensation', 'currency' => 'NGN', 'actor_user_id' => $reviewer->id, 'customer_profile_id' => $request->customer_profile_id,
            'thrift_plan_id' => $original->thrift_plan_id, 'occurred_at' => now(), 'occurred_on' => $date, 'business_timezone' => $business->timezone,
            'schema_version' => 1, 'committed_at' => now(), 'metadata' => ['original_posting_group_id' => $original->id, 'charge_id' => $preview['summary']['charge_id']]]);
        foreach ($original->entries()->orderBy('line_number')->get() as $line) {
            LedgerEntry::create(['ledger_posting_group_id' => $group->id, 'line_number' => $line->line_number, 'ledger_account_id' => $line->ledger_account_id,
                'side' => $line->side === LedgerEntrySide::Debit ? LedgerEntrySide::Credit : LedgerEntrySide::Debit,
                'amount_kobo' => $line->amount_kobo, 'customer_profile_id' => $line->customer_profile_id, 'thrift_plan_id' => $line->thrift_plan_id]);
        }

        return $group;
    }
}
