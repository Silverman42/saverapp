<?php

use App\Enums\LedgerAccountCode;
use App\Enums\ThriftPlanStatus;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use App\Services\CollectionReplacementService;
use App\Services\CollectionService;
use App\Services\ControlledTenderWalletCreditService;
use App\Services\FinancialCashPosition;
use App\Services\LedgerTransactionProjectionService;
use App\Services\PlanSettlementService;
use App\Services\WalletBalanceService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

require_once __DIR__.'/../CollectionFixtures.php';
require_once __DIR__.'/../ReversalFixtures.php';
require_once __DIR__.'/../WalletFixtures.php';

/**
 * A ₦2,000.00 receipt corrected after collection, so its cash is held as unapplied funds, on a plan that is now waiting to close.
 *
 * @return array{0: mixed, 1: mixed, 2: mixed, 3: mixed, 4: string, 5: mixed}
 */
function closingPlanWithHeldCorrection(object $test): array
{
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(2);
    $payload = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $payload);
    LedgerAccount::query()->where('code', LedgerAccountCode::UnappliedFunds)->update(['mapping_status' => 'mapped']);
    $reversal = approveReceiptCorrection($test, $agent, $customer, $assignment, LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id))->fresh();
    enableWallet();
    $plan->fresh()->update(['status' => ThriftPlanStatus::Paused, 'closure_pending_at' => now()]);

    return [$agent, $customer, $assignment, $plan->fresh(), $date, $reversal];
}

function creditHeldPaymentToWallet(object $test, $agent, $reversal, ?string $attempt = null): TestResponse
{
    $preview = app(ControlledTenderWalletCreditService::class)->preview($agent, $reversal);

    return $test->actingAs($agent)->post(route('reversals.wallet-credit.store', $reversal), [
        'attempt_reference' => $attempt ?? (string) Str::uuid(), 'preview_fingerprint' => $preview['preview_fingerprint'], 'confirmed' => true,
    ]);
}

test('a held correction on a closing plan is credited to the wallet and stops blocking closure', function (): void {
    [$agent, $customer, , $plan, , $reversal] = closingPlanWithHeldCorrection($this);
    expect(app(PlanSettlementService::class)->evaluate($plan)['blockers'])->toContain('Controlled unapplied cycle tender remains.');

    creditHeldPaymentToWallet($this, $agent, $reversal)->assertRedirect(route('plans.show', $plan->plan_id))->assertSessionHasNoErrors();

    $group = LedgerPostingGroup::query()->where('source_type', ControlledTenderWalletCreditService::SOURCE_TYPE)->sole();
    expect(walletGroupLines($group->id))->toBe(['unapplied_funds_ngn:debit:200000', 'customer_wallet_liability_ngn:credit:200000'])
        ->and($group->thrift_plan_id)->toBeNull()
        ->and(app(WalletBalanceService::class)->position($customer->fresh())['liability_kobo'])->toBe(200000)
        ->and(app(FinancialCashPosition::class)->balance(LedgerAccountCode::UnappliedFunds))->toBe(0)
        ->and(app(PlanSettlementService::class)->evaluate($plan->fresh())['blockers'])->not->toContain('Controlled unapplied cycle tender remains.');
    expect(app(LedgerTransactionProjectionService::class)->rebuild())->toBeArray();
    $this->assertDatabaseHas('ledger_transaction_projections', ['type' => ControlledTenderWalletCreditService::EVENT_TYPE, 'customer_profile_id' => $customer->id]);
});

test('a held correction is consumed only once across wallet credits and replacement receipts', function (): void {
    [$agent, $customer, $assignment, $plan, $date, $reversal] = closingPlanWithHeldCorrection($this);
    $attempt = (string) Str::uuid();
    creditHeldPaymentToWallet($this, $agent, $reversal, $attempt)->assertRedirect();
    $preview = ['attempt_reference' => $attempt, 'preview_fingerprint' => str_repeat('0', 64)];

    expect(app(ControlledTenderWalletCreditService::class)->credit($agent, $reversal, $preview)->id)
        ->toBe(LedgerPostingGroup::query()->where('source_type', ControlledTenderWalletCreditService::SOURCE_TYPE)->sole()->id);
    expect(fn () => app(ControlledTenderWalletCreditService::class)->credit($agent, $reversal, [...$preview, 'attempt_reference' => (string) Str::uuid()]))
        ->toThrow(ConflictHttpException::class, 'already credited');
    $plan->update(['status' => ThriftPlanStatus::Active, 'closure_pending_at' => null]);
    expect(fn () => app(CollectionReplacementService::class)->preview($agent, $reversal, collectionPayload($customer, $assignment, $plan->fresh(), $date, '2000.00')))
        ->toThrow(ConflictHttpException::class, 'no unconsumed controlled receipt');
    expect(app(WalletBalanceService::class)->position($customer->fresh())['liability_kobo'])->toBe(200000);
});

test('a held correction on an active plan must be re-recorded on the plan, not credited to the wallet', function (): void {
    [$agent, , , $plan, , $reversal] = closingPlanWithHeldCorrection($this);
    $plan->update(['status' => ThriftPlanStatus::Active, 'closure_pending_at' => null]);

    expect(fn () => app(ControlledTenderWalletCreditService::class)->preview($agent, $reversal))
        ->toThrow(ConflictHttpException::class, 'still active');
    $this->actingAs($agent)->get(route('reversals.replacement', $reversal))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('collections/Create'));
});

test('staff are sent from the replacement page and the plan alert to the wallet credit when the plan is closing', function (): void {
    [$agent, , , $plan, , $reversal] = closingPlanWithHeldCorrection($this);

    $this->actingAs($agent)->get(route('reversals.replacement', $reversal))->assertRedirect(route('reversals.wallet-credit', $reversal));
    $this->get(route('reversals.wallet-credit', $reversal))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('reversals/WalletCredit')
            ->where('preview.amount_kobo', 200000)->where('preview.plan_id', $plan->plan_id));
    $this->get(route('plans.show', $plan->plan_id))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('closure_blockers', fn ($blockers): bool => collect($blockers)
            ->firstWhere('code', 'unapplied_tender')['actions'] === [['label' => 'Add held payment to wallet', 'href' => route('reversals.wallet-credit', $reversal)]]));
});

test('another agent cannot credit a held correction to the wallet', function (): void {
    [, , , , , $reversal] = closingPlanWithHeldCorrection($this);
    $otherAgent = User::factory()->agent()->create();

    $this->actingAs($otherAgent)->get(route('reversals.wallet-credit', $reversal))->assertForbidden();
    expect(LedgerPostingGroup::query()->where('source_type', ControlledTenderWalletCreditService::SOURCE_TYPE)->exists())->toBeFalse();
});
