<?php

use App\Models\BusinessProfile;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\ReportCatalogue;
use App\Services\ReportReadService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../CollectionFixtures.php';

beforeEach(function (): void {
    config()->set('collections.enabled', true);
    $this->travelTo(CarbonImmutable::parse('2026-09-16 12:00:00', 'Africa/Lagos'));
});

/** @param array<int, mixed> $fixture */
function rptClosureReceipt(array $fixture, string $amount): void
{
    [$agent, $customer, $assignment, $plan, $today] = $fixture;
    $payload = collectionPayload($customer->fresh(), $assignment->fresh(), $plan->fresh(), $today, $amount);
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer->fresh(), $payload)['preview_fingerprint'];
    app(CollectionService::class)->record($agent, $customer->fresh(), $payload);
}

/** @return array<string, array<string, array<string, mixed>>> */
function rptClosureAllSections(User $viewer): array
{
    $today = CarbonImmutable::now(BusinessProfile::current()->timezone);
    $reports = [];
    foreach (app(ReportCatalogue::class)->forViewer($viewer) as $report) {
        $filters = ['page_size' => 25, 'group' => ''];
        if ($report['activity']) {
            $filters += ['from' => $today->startOfMonth()->toDateString(), 'to' => $today->toDateString()];
        }
        $reports[$report['code']] = app(ReportReadService::class)->read($viewer, $report['code'], $filters)['sections'];
    }

    return $reports;
}

/** @param array<string, mixed> $section */
function rptClosureUsesFinancialSource(array $section): bool
{
    return collect($section['metrics'])->contains(fn (array $metric): bool => str_contains($metric['source'], 'ledger') || str_contains($metric['source'], 'verified'));
}

test('RPT-AC-003: a missing or incident-blocked ledger watermark makes every financial section Unavailable and never zero', function (Closure $damage): void {
    $fixture = collectionFixture(3);
    rptClosureReceipt($fixture, '2000.00');
    app(LedgerTransactionProjectionService::class)->rebuild();
    $admin = User::factory()->admin()->create();
    $healthy = rptClosureAllSections($admin);
    $damage();

    $damaged = rptClosureAllSections($admin);

    $financial = 0;
    foreach ($healthy as $report => $sections) {
        foreach ($sections as $code => $section) {
            $after = $damaged[$report][$code];
            if (rptClosureUsesFinancialSource($section)) {
                $financial++;
                expect($after['status'])->toBe('Unavailable', "{$report}.{$code} must fail closed")
                    ->and($after['metrics'])->toBe([], "{$report}.{$code} must not publish a value");
            } elseif ($section['metrics'] !== [] && collect($section['metrics'])->every(fn (array $metric): bool => in_array($metric['source'], ['authoritative owner', 'withdrawals'], true))) {
                expect($after['status'])->not->toBe('Unavailable', "{$report}.{$code} is independent of the ledger")
                    ->and(collect($after['metrics'])->pluck('value')->all())->toBe(collect($section['metrics'])->pluck('value')->all());
            }
        }
    }
    expect($financial)->toBeGreaterThan(10);
})->with([
    'projection unavailable' => [fn () => DB::table('ledger_projection_state')->where('id', 1)->update(['status' => 'unavailable'])],
    'open integrity incident' => [fn () => DB::table('ledger_integrity_incidents')->insert(['incident_reference' => (string) Str::uuid(),
        'category' => 'projection_rebuild', 'status' => 'open', 'summary' => 'Test.', 'projection_version' => 1, 'ledger_group_watermark' => 1,
        'detected_at' => now(), 'created_at' => now(), 'updated_at' => now()])],
]);

test('RPT-AC-003: a posting after the first read is reflected in every receipt family at one watermark rather than a mixed total', function (): void {
    $fixture = collectionFixture(3);
    rptClosureReceipt($fixture, '2000.00');
    app(LedgerTransactionProjectionService::class)->rebuild();
    rptClosureReceipt($fixture, '1000.00');
    $admin = User::factory()->admin()->create();

    $reports = rptClosureAllSections($admin);
    $value = fn (string $report, string $section, string $metric): ?int => collect($reports[$report][$section]['metrics'])->firstWhere('code', $metric)['value'] ?? null;

    expect([
        $value('customer-summary', 'primary', 'customer_liability'),
        $value('contributions', 'primary', 'received_savings'),
        $value('collection-performance', 'primary', 'received_savings'),
        $value('reconciliation', 'primary', 'agent_receivable'),
        $value('reconciliation', 'batch_reconciliation', 'gross_tender'),
        $value('agent-performance', 'recorded_activity', 'received_savings'),
        $value('plans', 'funding_progress', 'funded_principal'),
        $value('exceptions', 'custody_batches', 'unremitted'),
    ])->each->toBe(300000);
});

test('RPT-AC-003: a Customer and Agent see the same financial gates for their own scope', function (string $role): void {
    $fixture = collectionFixture(3);
    rptClosureReceipt($fixture, '2000.00');
    app(LedgerTransactionProjectionService::class)->rebuild();
    $viewer = $role === 'agent' ? $fixture[0] : CustomerProfile::query()->sole()->user;
    DB::table('ledger_projection_state')->where('id', 1)->update(['status' => 'unavailable']);

    foreach (rptClosureAllSections($viewer) as $report => $sections) {
        foreach ($sections as $code => $section) {
            expect(rptClosureUsesFinancialSource($section))->toBeFalse("{$report}.{$code} published a financial value");
        }
    }
})->with(['agent', 'customer']);
