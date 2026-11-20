<?php

use App\Models\CollectionBatch;
use App\Models\CollectionReceipt;
use App\Services\BusinessSettings;
use App\Services\BusinessSettingsCatalogue;
use App\Services\CollectionService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../CollectionFixtures.php';

function useCashBatchCutoff(?string $cutoff): void
{
    $settings = Mockery::mock(app(BusinessSettings::class))->makePartial();
    $settings->shouldReceive('cashBatchCutoff')->andReturn($cutoff);
    app()->instance(BusinessSettings::class, $settings);
}

function recordCashAt(string $lagosTime, array $fixture, string $amount = '1000.00'): CollectionReceipt
{
    [$agent, $customer, $assignment, $plan, $date] = $fixture;
    test()->travelTo(CarbonImmutable::parse($date.' '.$lagosTime, 'Africa/Lagos'));
    $payload = collectionPayload($customer->fresh(), $assignment->fresh(), $plan->fresh(), $date, $amount);
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer->fresh(), $payload)['preview_fingerprint'];

    return app(CollectionService::class)->record($agent, $customer->fresh(), $payload);
}

test('the cutoff accepts a business time or empty for midnight and rejects anything else', function (mixed $value, bool $valid): void {
    $catalogue = app(BusinessSettingsCatalogue::class);
    $validate = fn () => $catalogue->validatePatch(['cash_batch_cutoff' => $value], $catalogue->defaults());

    $valid ? expect($validate())->toHaveKey('cash_batch_cutoff') : expect($validate)->toThrow(ValidationException::class);
})->with([
    'evening' => ['18:00', true], 'empty' => ['', true], 'midnight' => ['00:00', false],
    'out of range' => ['25:00', false], 'words' => ['6pm', false],
]);

test('a cash batch closes at the cutoff and later cash that day goes into a late batch closing at midnight', function (): void {
    config()->set('collections.enabled', true);
    useCashBatchCutoff('18:00');
    $fixture = collectionFixture(2);
    $date = $fixture[4];

    $first = recordCashAt('10:00', $fixture)->batch;
    expect($first->closes_at->toIso8601String())->toBe(CarbonImmutable::parse($date.' 18:00', 'Africa/Lagos')->utc()->toIso8601String())
        ->and($first->openBatchNotice())->toContain('from 18:00 on');

    $this->travelTo(CarbonImmutable::parse($date.' 17:59', 'Africa/Lagos'));
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    expect($first->fresh()->status)->toBe('open');

    $this->travelTo(CarbonImmutable::parse($date.' 18:00', 'Africa/Lagos'));
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    expect($first->fresh()->status)->toBe('ready_for_review')->and($first->fresh()->openBatchNotice())->toBeNull();

    $late = recordCashAt('19:30', $fixture)->batch;
    expect($late->id)->not->toBe($first->id)
        ->and($late->revision)->toBe(2)->and($late->received_date)->toBe($date)
        ->and($late->closes_at->toIso8601String())->toBe(CarbonImmutable::parse($date, 'Africa/Lagos')->addDay()->utc()->toIso8601String())
        ->and($late->openBatchNotice())->toContain('midnight on');
});

test('without a cutoff a cash batch stays open until midnight', function (): void {
    config()->set('collections.enabled', true);
    useCashBatchCutoff(null);
    $fixture = collectionFixture(2);

    $batch = recordCashAt('10:00', $fixture)->batch;
    $this->travelTo(CarbonImmutable::parse($fixture[4].' 23:59', 'Africa/Lagos'));
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    expect($batch->fresh()->status)->toBe('open');

    $this->travelTo(CarbonImmutable::parse($fixture[4], 'Africa/Lagos')->addDay());
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    expect($batch->fresh()->status)->toBe('ready_for_review');
});

test('a batch created before close times were captured still closes at the end of its day', function (): void {
    config()->set('collections.enabled', true);
    $fixture = collectionFixture(2);
    $batch = recordCashAt('10:00', $fixture)->batch;
    CollectionBatch::query()->whereKey($batch->id)->update(['closes_at' => null]);

    $this->travelTo(CarbonImmutable::parse($fixture[4], 'Africa/Lagos')->addDay());
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    expect($batch->fresh()->status)->toBe('ready_for_review');
});
