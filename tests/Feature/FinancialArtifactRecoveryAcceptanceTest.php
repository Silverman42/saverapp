<?php

use App\Models\FinancialArtifact;
use App\Services\BackgroundRecovery;
use App\Services\FinancialArtifactService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\StatementPreviewService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../WithdrawalFixtures.php';

function queuedRecoveryArtifact(): FinancialArtifact
{
    Queue::fake();
    Storage::fake('local');
    [, $customer] = withdrawalFixture();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $from = now()->startOfMonth()->toDateString();
    $to = now()->toDateString();
    $quote = app(StatementPreviewService::class)->preview($customer->user, $customer, $from, $to, 'Africa/Lagos');

    return app(FinancialArtifactService::class)->issueStatement($customer->user, $customer, (string) Str::uuid(), $from, $to, $quote['preview_fingerprint']);
}

test('shared artifact recovery publishes verified bytes and one ready intent once', function (): void {
    $artifact = queuedRecoveryArtifact();
    $recovery = app(BackgroundRecovery::class);
    expect($recovery->runSource('financial_artifact', $artifact->id))->toBe('succeeded');
    $artifact->refresh();
    $path = $artifact->storage_path;
    $hash = $artifact->artifact_hash;
    expect($recovery->runSource('financial_artifact', $artifact->id))->toBe('succeeded');
    expect($artifact->fresh()->storage_path)->toBe($path)->and($artifact->fresh()->artifact_hash)->toBe($hash);
    $this->assertDatabaseCount('financial_artifact_events', 1);
    $this->assertDatabaseCount('financial_artifact_notification_intents', 1);
    expect(DB::table('platform_recovery_work')->where('owner', 'financial_artifact')->value('state'))->toBe('succeeded');
});

test('artifact lease takeover fences the previous renderer and permits the current owner to publish', function (): void {
    $artifact = queuedRecoveryArtifact();
    $recovery = app(BackgroundRecovery::class);
    $id = DB::table('platform_recovery_work')->where('owner', 'financial_artifact')->value('id');
    $old = $recovery->claim($id);
    $this->travel(151)->seconds();
    $current = $recovery->claim($id);
    expect($current->token)->toBeGreaterThan($old->token);
    expect($recovery->execute($old))->toBe('stale_lease');
    expect($artifact->fresh()->status)->toBe('queued');
    expect($recovery->execute($current))->toBe('succeeded');
    expect($artifact->fresh()->status)->toBe('ready');
    $this->assertDatabaseCount('financial_artifact_events', 1);
});

test('failed artifact publication discards unpublished generation files and recovers its retained snapshot', function (): void {
    $artifact = queuedRecoveryArtifact();
    expect(fn () => app(FinancialArtifactService::class)->render($artifact->id, publicationFence: function (): never {
        throw new RuntimeException('Injected publication failure.');
    }))->toThrow(RuntimeException::class, 'publication failure');
    expect($artifact->fresh()->status)->toBe('queued')->and(Storage::disk('local')->allFiles())->toBe([]);
    $this->assertDatabaseCount('financial_artifact_events', 0);
    expect(app(BackgroundRecovery::class)->runSource('financial_artifact', $artifact->id))->toBe('succeeded');
    $this->assertDatabaseCount('financial_artifact_events', 1);
});

test('artifact rendering beyond its execution budget cannot publish bytes or readiness notices', function (): void {
    $artifact = queuedRecoveryArtifact();
    $recovery = app(BackgroundRecovery::class);
    $lease = $recovery->claim(DB::table('platform_recovery_work')->where('owner', 'financial_artifact')->value('id'));
    $this->travel(121)->seconds();
    expect($recovery->execute($lease))->toBe('dead_letter');
    expect($artifact->fresh()->status)->not->toBe('ready')->and(Storage::disk('local')->allFiles())->toBe([]);
    expect(DB::table('financial_artifact_events')->where('event_type', 'ready')->exists())->toBeFalse();
});

test('artifact retention discards stale unpublished generations while preserving issued bytes', function (): void {
    $artifact = queuedRecoveryArtifact();
    app(BackgroundRecovery::class)->runSource('financial_artifact', $artifact->id);
    $issuedPath = $artifact->fresh()->storage_path;
    $orphanPath = 'financial-artifacts/'.$artifact->artifact_reference.'/0-orphan.encrypted';
    Storage::disk('local')->put($orphanPath, 'Unpublished generation from an interrupted worker.');
    touch(Storage::disk('local')->path($orphanPath), now()->subDays(2)->timestamp);
    touch(Storage::disk('local')->path($issuedPath), now()->subDays(2)->timestamp);
    expect(app(FinancialArtifactService::class)->discardOrphanGenerations())->toBe(1);
    expect(Storage::disk('local')->exists($issuedPath))->toBeTrue()->and(Storage::disk('local')->exists($orphanPath))->toBeFalse();
    expect(app(BackgroundRecovery::class)->runSource('financial_artifact', $artifact->id))->toBe('succeeded');
});
