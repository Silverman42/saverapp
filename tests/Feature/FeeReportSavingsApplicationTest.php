<?php

use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\LedgerTransactionProjectionService;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../CollectionFixtures.php';
require_once __DIR__.'/../FeeFixtures.php';

test('the fees report shows fee activity and collection fees paid from savings alongside an external fee on one receipt', function (): void {
    config()->set('collections.enabled', true);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(2, feeAmountKobo: 10000);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $registration = reportFeeObligation($agent, $customer);
    $data = [...collectionPayload($customer, $assignment, $plan, $date, '2000.00'),
        'fees' => [['obligation_id' => $registration->id, 'amount_ngn' => '500.00']]];
    $data['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $data)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $data);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)->get(route('reports.show', 'fees'))->assertInertia(fn (Assert $page) => $page
        ->where('report.sections.primary.status', fn (string $status) => $status !== 'Unavailable')
        ->where('report.sections.primary.total', 2)
        ->where('report.sections.external_receipts.total', 1)
        ->where('report.sections.external_receipts.rows.0.reference', $receipt->receipt_reference)
        ->where('report.sections.fee_applications.total', 1)
        ->where('report.sections.fee_applications.rows.0.reference', $receipt->receipt_reference)
        ->where('report.sections.fee_applications.rows.0.customer', $customer->customer_id)
        ->where('report.sections.fee_applications.metrics', fn ($metrics) => collect($metrics)->firstWhere('code', 'fee_activity_amount')['value'] === 10000));
});
