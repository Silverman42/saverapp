<?php

use App\Enums\CustomerStatus;
use App\Enums\ExternalOutcome;
use App\Jobs\MaterializeNotificationIntent;
use App\Jobs\ProjectAuditEvent;
use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\CustomerStatusHistory;
use App\Models\CustomerStatusNotificationIntent;
use App\Models\User;
use App\Services\AuditProjection;
use App\Services\BackgroundRecovery;
use App\Services\NotificationPipeline;
use App\Services\PlatformDiagnostics;
use App\Services\PlatformState;
use App\Services\RecoveryReplay;
use App\Support\ExternalOutcomeLookup;
use App\Support\PlatformBlocked;
use App\Support\RecoveryConflict;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function recoveryAuditWork(): stdClass
{
    AuditEvent::record('customer.status_changed', 'customer', null, null, ['to_status' => 'inactive']);

    return DB::table('platform_recovery_work')->where('owner', 'audit_projection')->orderByDesc('id')->first();
}

/** @return array{0: CustomerStatusNotificationIntent, 1: int, 2: int} */
function recoveryInboxWork(): array
{
    $customer = CustomerProfile::factory()->create();
    $history = CustomerStatusHistory::create(['customer_profile_id' => $customer->id, 'from_status' => CustomerStatus::Active,
        'to_status' => CustomerStatus::Restricted, 'reason' => 'Private investigation', 'customer_facing_explanation' => 'A review is in progress.',
        'changed_by_user_id' => $customer->user_id, 'created_at' => now()]);
    $intent = CustomerStatusNotificationIntent::create(['notification_id' => (string) Str::uuid(), 'customer_status_history_id' => $history->id,
        'recipient_user_id' => $customer->user_id, 'audience_type' => 'subject_customer', 'channel' => 'database',
        'purpose' => 'customer_status_changed', 'customer_profile_id' => $customer->id,
        'payload' => ['title' => 'unsafe original', 'message' => 'private evidence'], 'status' => 'pending']);
    $sourceId = app(NotificationPipeline::class)->capture('customer_status', $intent->id, false);
    $workId = DB::table('platform_recovery_work')->where('owner', 'notification_inbox')->where('source_id', $sourceId)->value('id');

    return [$intent, (int) $sourceId, (int) $workId];
}

function recoveryFreeze(string $mode): void
{
    app(PlatformState::class)->transition(['mode' => $mode, 'expected_version' => DB::table('platform_state')->value('version'),
        'operation_id' => (string) Str::uuid(), 'operator' => 'ops-test', 'reason' => 'Contain test incident', 'incident' => 'TEST', 'expires_at' => null]);
}

test('canonical source and recovery intent commit together or neither', function () {
    Queue::fake([ProjectAuditEvent::class]);
    expect(fn () => DB::transaction(function (): void {
        recoveryAuditWork();
        throw new RuntimeException('Abort owner operation');
    }))->toThrow(RuntimeException::class);

    $this->assertDatabaseCount('platform_recovery_work', 0);
    $this->assertDatabaseCount('canonical_audit_events', 0);
    Queue::assertNothingPushed();
});

test('duplicate source registration and queue delivery produce one projection and one effect attempt', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $work = recoveryAuditWork();
    $recovery = app(BackgroundRecovery::class);
    expect($recovery->register('audit_projection', (int) $work->source_id))->toBe((int) $work->id);
    app()->call([new ProjectAuditEvent((int) $work->source_id), 'handle']);
    app()->call([new ProjectAuditEvent((int) $work->source_id), 'handle']);

    $this->assertDatabaseCount('platform_recovery_work', 1);
    $this->assertDatabaseCount('audit_search_documents', 1);
    $this->assertDatabaseCount('platform_recovery_attempts', 2);
    $this->assertDatabaseHas('platform_recovery_work', ['id' => $work->id, 'state' => 'succeeded', 'attempts' => 1]);
    Queue::assertPushed(ProjectAuditEvent::class, 1);
});

test('queued delivery and scheduled audit draining share one bounded retry budget', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $work = recoveryAuditWork();
    DB::statement("CREATE TRIGGER recovery_projection_outage BEFORE INSERT ON audit_search_documents BEGIN SELECT RAISE(ABORT, 'Projection offline'); END");
    $recovery = app(BackgroundRecovery::class);
    $recovery->run((int) $work->id);
    $this->artisan('audit:drain')->assertSuccessful();
    expect(DB::table('platform_recovery_work')->where('id', $work->id)->value('attempts'))->toBe(1);
    for ($attempt = 2; $attempt <= 3; $attempt++) {
        $this->travelTo(CarbonImmutable::parse(DB::table('platform_recovery_work')->where('id', $work->id)->value('available_at')));
        if ($attempt === 2) {
            app()->call([new ProjectAuditEvent((int) $work->source_id), 'handle']);
        } else {
            $this->artisan('audit:drain')->assertSuccessful();
        }
    }
    DB::statement('DROP TRIGGER recovery_projection_outage');
    $this->travel(1)->days();
    $this->artisan('audit:drain')->assertSuccessful();

    $this->assertDatabaseHas('platform_recovery_work', ['id' => $work->id, 'state' => 'dead_letter', 'attempts' => 3, 'failure_code' => 'retry_budget_exhausted']);
    $this->assertDatabaseHas('audit_projection_work', ['canonical_event_id' => $work->source_id, 'status' => 'dead_letter', 'attempts' => 3]);
    $this->assertDatabaseCount('audit_search_documents', 0);
    expect(DB::table('platform_recovery_attempts')->where('phase', 'finished')->count())->toBe(3);
    Queue::assertPushed(ProjectAuditEvent::class, 1);
});

test('overlapping lease holders cannot claim or commit the same effect', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $work = recoveryAuditWork();
    $recovery = app(BackgroundRecovery::class);
    $first = $recovery->claim((int) $work->id);
    expect($recovery->claim((int) $work->id))->toBeNull();
    $this->travel(61)->seconds();
    $second = $recovery->claim((int) $work->id);

    expect($second->token)->toBeGreaterThan($first->token);
    expect($recovery->execute($first))->toBe('stale_lease');
    expect($recovery->execute($second))->toBe('succeeded');
    $this->assertDatabaseCount('audit_search_documents', 1);
    $this->assertDatabaseHas('platform_recovery_work', ['id' => $work->id, 'attempts' => 2, 'state' => 'succeeded']);
    Queue::assertPushed(ProjectAuditEvent::class, 1);
});

test('expired leases detect the committed owner result without repeating it', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $work = recoveryAuditWork();
    $recovery = app(BackgroundRecovery::class);
    $lease = $recovery->claim((int) $work->id);
    app(AuditProjection::class)->projectOwned((int) $work->source_id);
    $this->travel(61)->seconds();

    expect($recovery->claim((int) $work->id))->toBeNull();
    expect($recovery->execute($lease))->toBe('stale_lease');
    $this->assertDatabaseCount('audit_search_documents', 1);
    $this->assertDatabaseHas('platform_recovery_work', ['id' => $work->id, 'state' => 'succeeded', 'attempts' => 1]);
    Queue::assertPushed(ProjectAuditEvent::class, 1);
});

test('a crash at the recovery checkpoint rolls back the local effect and leaves the lease recoverable', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $work = recoveryAuditWork();
    DB::statement("CREATE TRIGGER recovery_checkpoint_outage BEFORE UPDATE ON platform_recovery_work WHEN NEW.state = 'succeeded' BEGIN SELECT RAISE(ABORT, 'Checkpoint offline'); END");

    expect(app(BackgroundRecovery::class)->run((int) $work->id))->toBe('retry_scheduled');
    $this->assertDatabaseCount('audit_search_documents', 0);
    $this->assertDatabaseHas('audit_projection_work', ['canonical_event_id' => $work->source_id, 'status' => 'pending']);
    DB::statement('DROP TRIGGER recovery_checkpoint_outage');
    $this->travelTo(CarbonImmutable::parse(DB::table('platform_recovery_work')->where('id', $work->id)->value('available_at')));
    expect(app(BackgroundRecovery::class)->run((int) $work->id))->toBe('succeeded');
    $this->assertDatabaseCount('audit_search_documents', 1);
    Queue::assertPushed(ProjectAuditEvent::class, 1);
});

test('platform pauses between claim and execution preserve evidence without spending the retry budget', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $work = recoveryAuditWork();
    $recovery = app(BackgroundRecovery::class);
    $lease = $recovery->claim((int) $work->id);
    recoveryFreeze('read_only');

    expect(fn () => $recovery->execute($lease))->toThrow(PlatformBlocked::class);
    $this->assertDatabaseHas('platform_recovery_work', ['id' => $work->id, 'state' => 'queued', 'cycle_attempts' => 0]);
    $this->assertDatabaseHas('platform_recovery_attempts', ['work_id' => $work->id, 'phase' => 'finished', 'outcome' => 'paused']);
    recoveryFreeze('normal');
    expect($recovery->run((int) $work->id))->toBe('succeeded');
    Queue::assertPushed(ProjectAuditEvent::class, 3);
});

test('heartbeats extend the lease and stale heartbeats cannot regain leadership', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $work = recoveryAuditWork();
    $recovery = app(BackgroundRecovery::class);
    $lease = $recovery->claim((int) $work->id);
    $this->travel(30)->seconds();
    $recovery->heartbeat($lease);
    $this->travel(31)->seconds();
    expect($recovery->claim((int) $work->id))->toBeNull();
    $this->travel(30)->seconds();

    expect(fn () => $recovery->heartbeat($lease))->toThrow(RecoveryConflict::class, 'stale_lease');
    Queue::assertPushed(ProjectAuditEvent::class, 1);
});

test('notification processing records a single owner attempt across duplicate jobs', function () {
    Queue::fake([ProjectAuditEvent::class, MaterializeNotificationIntent::class]);
    [$owner, $source, $workId] = recoveryInboxWork();
    app()->call([new MaterializeNotificationIntent($source), 'handle']);
    app()->call([new MaterializeNotificationIntent($source), 'handle']);

    expect($owner->fresh()->status)->toBe('delivered');
    $this->assertDatabaseCount('notification_inbox_attempts', 1);
    $this->assertDatabaseCount('notifications', 1);
    $this->assertDatabaseHas('platform_recovery_work', ['id' => $workId, 'attempts' => 1, 'state' => 'succeeded']);
    Queue::assertNotPushed(MaterializeNotificationIntent::class);
});

test('corrupt notification contracts dead letter without leaking or materializing content', function () {
    Queue::fake([ProjectAuditEvent::class, MaterializeNotificationIntent::class]);
    [, $source, $workId] = recoveryInboxWork();
    DB::table('notification_inbox_intents')->where('id', $source)->update(['summary' => 'Untrusted provider token']);

    expect(app(BackgroundRecovery::class)->run($workId))->toBe('dead_letter');
    $this->assertDatabaseCount('notifications', 0);
    $this->assertDatabaseHas('platform_recovery_work', ['id' => $workId, 'failure_code' => 'unsupported_contract']);
    $this->assertDatabaseHas('notification_inbox_intents', ['id' => $source, 'status' => 'blocked']);
    Queue::assertNotPushed(MaterializeNotificationIntent::class);
});

test('canonical corruption dead letters the projection without advancing the watermark', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $work = recoveryAuditWork();
    DB::statement('DROP TRIGGER canonical_audit_events_no_update');
    DB::table('canonical_audit_events')->where('id', $work->source_id)->update(['content_hash' => str_repeat('0', 64)]);

    expect(app(BackgroundRecovery::class)->run((int) $work->id))->toBe('dead_letter');
    expect(DB::table('audit_projection_state')->value('watermark'))->toBe(0);
    $this->assertDatabaseCount('audit_search_documents', 0);
    expect(AuditEvent::query()->where('event_type', 'audit.content_mismatch')->exists())->toBeTrue();
    Queue::assertPushed(ProjectAuditEvent::class);
});

test('recovery identity and attempt evidence reject direct modification and deletion', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $work = recoveryAuditWork();
    app(BackgroundRecovery::class)->run((int) $work->id);

    expect(fn () => DB::table('platform_recovery_work')->where('id', $work->id)->update(['payload_hash' => str_repeat('0', 64)]))->toThrow(QueryException::class);
    expect(fn () => DB::table('platform_recovery_work')->where('id', $work->id)->delete())->toThrow(QueryException::class);
    expect(fn () => DB::table('platform_recovery_attempts')->update(['outcome' => 'forged']))->toThrow(QueryException::class);
    expect(fn () => DB::table('platform_recovery_attempts')->delete())->toThrow(QueryException::class);
    Queue::assertPushed(ProjectAuditEvent::class, 1);
});

test('bounded adoption retains terminal outcomes and historical attempts without guessing unknown history', function () {
    Queue::fake([ProjectAuditEvent::class]);
    DB::statement('DROP TRIGGER recovery_work_no_delete');
    $first = recoveryAuditWork();
    $second = recoveryAuditWork();
    DB::table('platform_recovery_work')->delete();
    DB::table('audit_projection_work')->where('canonical_event_id', $first->source_id)->update(['attempts' => 2]);
    $recovery = app(BackgroundRecovery::class);

    expect($recovery->adopt('audit_projection', 1))->toBe(1);
    $this->assertDatabaseHas('platform_recovery_work', ['source_id' => $first->source_id, 'attempts' => 2, 'state' => 'dead_letter', 'failure_code' => 'historical_outcome_unverified']);
    expect($recovery->adopt('audit_projection', 1))->toBe(1);
    expect($recovery->adopt('audit_projection', 1))->toBe(0);
    $this->assertDatabaseHas('platform_recovery_work', ['source_id' => $second->source_id, 'state' => 'queued']);
    Queue::assertPushed(ProjectAuditEvent::class, 2);
});

test('unknown external outcomes remain unresolved and cannot be claimed or replayed without a provider contract', function () {
    $id = DB::table('platform_recovery_work')->insertGetId(['owner' => 'external_email', 'source_id' => 1, 'source_version' => 1,
        'payload_hash' => hash('sha256', 'same immutable intent'), 'operation_key' => 'email:original',
        'correlation_reference' => (string) Str::uuid(), 'state' => 'outcome_unknown', 'created_at' => now(), 'updated_at' => now()]);

    expect(app(BackgroundRecovery::class)->reconcileExternal($id))->toBe(ExternalOutcome::Unknown);
    expect(app(BackgroundRecovery::class)->claim($id))->toBeNull();
    expect(app(RecoveryReplay::class)->classify(DB::table('platform_recovery_work')->where('id', $id)->first()))->toBe('blocked');
    $this->assertDatabaseHas('platform_recovery_work', ['id' => $id, 'state' => 'outcome_unknown']);
});

test('provider outcome evidence resolves the original reference without initiating another external effect', function (ExternalOutcome $outcome, string $state) {
    $id = DB::table('platform_recovery_work')->insertGetId(['owner' => 'external_payout', 'source_id' => 1, 'source_version' => 1,
        'payload_hash' => hash('sha256', 'same payout'), 'operation_key' => 'payout:original',
        'correlation_reference' => (string) Str::uuid(), 'state' => 'outcome_unknown', 'created_at' => now(), 'updated_at' => now()]);
    app()->instance(ExternalOutcomeLookup::class, new class($outcome) implements ExternalOutcomeLookup
    {
        public function __construct(private ExternalOutcome $outcome) {}

        public function lookup(string $owner, string $operationKey, string $payloadHash): ExternalOutcome
        {
            expect($operationKey)->toBe('payout:original');
            expect($payloadHash)->toBe(hash('sha256', 'same payout'));

            return $this->outcome;
        }
    });

    expect(app(BackgroundRecovery::class)->reconcileExternal($id))->toBe($outcome);
    $this->assertDatabaseHas('platform_recovery_work', ['id' => $id, 'state' => $state, 'attempts' => 0]);
    expect(app(BackgroundRecovery::class)->claim($id))->toBeNull();
})->with([[ExternalOutcome::ConfirmedSuccess, 'succeeded'], [ExternalOutcome::SafeToRetry, 'failed']]);

test('diagnostics and CLI expose safe recovery counts without authorizing readiness', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $work = recoveryAuditWork();
    $report = app(PlatformDiagnostics::class)->report();

    expect($report['checks']['recovery']['counts']['queued'])->toBe(1);
    expect($report['production_certified'])->toBeFalse();
    $this->artisan('platform:work --json')->expectsOutputToContain('correlation_reference')->doesntExpectOutputToContain('payload_hash')->assertSuccessful();
    $this->artisan('platform:work --limit=1001')->assertFailed();
    $this->artisan('platform:work --adopt')->assertFailed();
    Queue::assertPushed(ProjectAuditEvent::class, 1);
});

test('notification source and recovery registration roll back together when registration fails', function () {
    Queue::fake([ProjectAuditEvent::class, MaterializeNotificationIntent::class]);
    DB::statement("CREATE TRIGGER recovery_registration_outage BEFORE INSERT ON platform_recovery_work BEGIN SELECT RAISE(ABORT, 'Recovery store offline'); END");

    expect(fn () => recoveryInboxWork())->toThrow(QueryException::class);
    $this->assertDatabaseCount('notification_events', 0);
    $this->assertDatabaseCount('notification_inbox_intents', 0);
    $this->assertDatabaseCount('platform_recovery_work', 0);
    DB::statement('DROP TRIGGER recovery_registration_outage');
    Queue::assertNothingPushed();
});

test('owner scope and expiry are rechecked at execution and suppress an ineligible recipient', function (string $change) {
    Queue::fake([ProjectAuditEvent::class, MaterializeNotificationIntent::class]);
    [$owner, $source, $workId] = recoveryInboxWork();
    $lease = app(BackgroundRecovery::class)->claim($workId);
    if ($change === 'expiry') {
        $this->travelTo(CarbonImmutable::parse(DB::table('notification_inbox_intents')->where('id', $source)->value('expires_at'))->addSecond());
        // Expired work must be claimed afresh; the captured source expiry remains unchanged.
        $lease = app(BackgroundRecovery::class)->claim($workId);
    } else {
        DB::table('customer_profiles')->where('id', $owner->customer_profile_id)->update(['user_id' => User::factory()->customer()->create()->id]);
    }

    expect(app(BackgroundRecovery::class)->execute($lease))->toBe('cancelled');
    $this->assertDatabaseCount('notifications', 0);
    $this->assertDatabaseHas('notification_inbox_intents', ['id' => $source, 'status' => 'suppressed']);
    Queue::assertNotPushed(MaterializeNotificationIntent::class);
})->with(['scope', 'expiry']);

test('changed source identity conflicts with duplicate registration without overwriting immutable work', function () {
    Queue::fake([ProjectAuditEvent::class, MaterializeNotificationIntent::class]);
    [, $source, $id] = recoveryInboxWork();
    $before = DB::table('platform_recovery_work')->where('id', $id)->value('payload_hash');
    DB::table('notification_events')->update(['source_version' => 2]);

    expect(fn () => app(BackgroundRecovery::class)->register('notification_inbox', $source))->toThrow(RecoveryConflict::class, 'source_identity_conflict');
    expect(DB::table('platform_recovery_work')->where('id', $id)->value('payload_hash'))->toBe($before);
    Queue::assertNothingPushed();
});

test('unsafe queue timing is rejected before reservation or any durable attempt', function () {
    config(['queue.default' => 'database']);
    ProjectAuditEvent::dispatch(999);

    expect(fn () => app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(timeout: 85, sleep: 0)))->toThrow(LogicException::class);
    $this->assertDatabaseHas('jobs', ['attempts' => 0, 'reserved_at' => null]);
    $this->assertDatabaseCount('platform_recovery_attempts', 0);
});

test('a real database queue worker commits the lease separately and acknowledges one local effect', function () {
    $queueManager = app('queue');
    Queue::fake([ProjectAuditEvent::class]);
    $work = recoveryAuditWork();
    Queue::assertPushed(ProjectAuditEvent::class, 1);
    Queue::swap($queueManager);
    config(['queue.default' => 'database']);
    ProjectAuditEvent::dispatch((int) $work->source_id);

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 0);
    $this->assertDatabaseHas('platform_recovery_work', ['id' => $work->id, 'state' => 'succeeded', 'attempts' => 1]);
    $this->assertDatabaseCount('audit_search_documents', 1);
});

test('notification scheduled dispatch and direct jobs retain one shared durable retry budget', function () {
    Queue::fake([ProjectAuditEvent::class, MaterializeNotificationIntent::class]);
    [, $source, $workId] = recoveryInboxWork();
    DB::statement("CREATE TRIGGER recovery_inbox_outage BEFORE INSERT ON notifications BEGIN SELECT RAISE(ABORT, 'Inbox offline'); END");
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $this->artisan('notifications:drain')->assertSuccessful();
        app()->call([new MaterializeNotificationIntent($source), 'handle']);
        $this->travel(5)->minutes();
    }
    Queue::assertPushed(MaterializeNotificationIntent::class, 3);
    Queue::fake([MaterializeNotificationIntent::class]);
    $this->artisan('notifications:drain')->assertSuccessful();
    Queue::assertNothingPushed();
    $this->assertDatabaseHas('platform_recovery_work', ['id' => $workId, 'attempts' => 3, 'state' => 'dead_letter']);
    $this->assertDatabaseCount('notification_inbox_attempts', 3);
    DB::statement('DROP TRIGGER recovery_inbox_outage');
});

test('replay planning marks a recipient with lost scope ineligible before any effect', function () {
    Queue::fake([ProjectAuditEvent::class, MaterializeNotificationIntent::class]);
    [$owner, , $workId] = recoveryInboxWork();
    DB::table('customer_profiles')->where('id', $owner->customer_profile_id)->update(['user_id' => User::factory()->customer()->create()->id]);

    expect(app(RecoveryReplay::class)->classify(DB::table('platform_recovery_work')->where('id', $workId)->first()))->toBe('blocked');
    $this->assertDatabaseCount('notifications', 0);
    $this->assertDatabaseHas('platform_recovery_work', ['id' => $workId, 'attempts' => 0]);
    Queue::assertNothingPushed();
});

test('a paused notification claim does not inflate owner attempt counts on later failures', function () {
    Queue::fake([ProjectAuditEvent::class, MaterializeNotificationIntent::class]);
    [, $source, $workId] = recoveryInboxWork();
    $recovery = app(BackgroundRecovery::class);
    $lease = $recovery->claim($workId);
    recoveryFreeze('read_only');
    expect(fn () => $recovery->execute($lease))->toThrow(PlatformBlocked::class);
    recoveryFreeze('normal');
    DB::statement("CREATE TRIGGER paused_recovery_inbox_outage BEFORE INSERT ON notifications BEGIN SELECT RAISE(ABORT, 'Inbox offline'); END");
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $recovery->run($workId);
        $this->travel(5)->minutes();
    }

    $this->assertDatabaseHas('platform_recovery_work', ['id' => $workId, 'attempts' => 4, 'cycle_attempts' => 3, 'state' => 'dead_letter']);
    $this->assertDatabaseHas('notification_inbox_intents', ['id' => $source, 'attempt_count' => 3, 'status' => 'dead_letter']);
    expect(DB::table('notification_inbox_attempts')->where('intent_id', $source)->pluck('attempt_number')->all())->toBe([1, 2, 3]);
    DB::statement('DROP TRIGGER paused_recovery_inbox_outage');
    $this->assertDatabaseCount('notification_inbox_attempts', 3);
    $this->assertDatabaseCount('canonical_audit_events', 5);
    Queue::assertPushed(ProjectAuditEvent::class, 5);
    Queue::assertNotPushed(MaterializeNotificationIntent::class);
});
