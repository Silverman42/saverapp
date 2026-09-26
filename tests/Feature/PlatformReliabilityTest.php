<?php

use App\Enums\CustomerStatus;
use App\Jobs\ProjectAuditEvent;
use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use App\Services\AuditCapture;
use App\Services\AuditProjection;
use App\Services\CollectionLedgerService;
use App\Services\CollectionService;
use App\Services\CustomerRegistrationService;
use App\Services\CustomerStatusManagementService;
use App\Services\FeeObligationService;
use App\Services\PlatformDiagnostics;
use App\Services\PlatformGuard;
use App\Services\PlatformState;
use App\Services\RegistrationFeeService;
use App\Services\ReversalService;
use App\Services\WithdrawalService;
use App\Support\PlatformBlocked;
use App\Support\PlatformJobMiddleware;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

function platformTransitionInput(string $mode, int $version = 1): array
{
    return ['mode' => $mode, 'expected_version' => $version, 'operation_id' => (string) Str::uuid(),
        'operator' => 'ops-service', 'reason' => 'Incident containment', 'incident' => 'INC-100', 'expires_at' => null];
}

function platformMode(string $mode): void
{
    app(PlatformState::class)->transition(platformTransitionInput($mode));
}

test('initialization retains normal mode without enabling uncertified features', function () {
    expect(app(PlatformState::class)->publicStatus()['mode'])->toBe('normal');
    expect(app(PlatformDiagnostics::class)->report()['owner_readiness']['payout_execution']['state'])->toBe('Unavailable');
    $this->assertDatabaseCount('platform_transitions', 1);
});

test('mode transitions commit one immutable audit linked result and resolve matching retries', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $input = platformTransitionInput('financial_freeze');
    $first = app(PlatformState::class)->transition($input);
    $second = app(PlatformState::class)->transition($input);

    expect($first)->toBe($second)->toMatchArray(['mode' => 'financial_freeze', 'version' => 2]);
    $this->assertDatabaseCount('platform_operations', 1);
    $this->assertDatabaseCount('platform_transitions', 2);
    $event = AuditEvent::query()->where('event_type', 'platform.mode_changed')->sole();
    expect($event->payload)->not->toHaveKeys(['operator', 'incident', 'reason']);
    $this->assertDatabaseHas('platform_transitions', ['version' => 2, 'audit_event_id' => $event->id]);
    expect(fn () => DB::table('platform_transitions')->where('version', 2)->update(['to_mode' => 'normal']))->toThrow(QueryException::class);
    Queue::assertPushed(ProjectAuditEvent::class, 1);
});

test('changed operation input and stale versions cannot change mode', function () {
    $input = platformTransitionInput('financial_freeze');
    app(PlatformState::class)->transition($input);

    expect(fn () => app(PlatformState::class)->transition([...$input, 'mode' => 'normal']))->toThrow(ConflictHttpException::class);
    expect(fn () => app(PlatformState::class)->transition(platformTransitionInput('normal')))->toThrow(ConflictHttpException::class);
    $this->assertDatabaseHas('platform_state', ['id' => 1, 'mode' => 'financial_freeze', 'version' => 2]);
    $this->assertDatabaseCount('platform_operations', 1);
});

test('a durable audit rejection rolls back mode transition and operation result', function () {
    $this->mock(AuditCapture::class, fn ($mock) => $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('Injected audit rejection')));

    expect(fn () => app(PlatformState::class)->transition(platformTransitionInput('read_only')))->toThrow(RuntimeException::class, 'Injected audit rejection');
    $this->assertDatabaseHas('platform_state', ['mode' => 'normal', 'version' => 1]);
    $this->assertDatabaseCount('platform_transitions', 1);
    $this->assertDatabaseCount('platform_operations', 0);
});

test('platform guards enforce each mode without changing existing data', function (string $mode, array $permitted) {
    platformMode($mode);
    foreach (['read', 'mutation', 'financial', 'derived', 'external'] as $operation) {
        if (in_array($operation, $permitted, true)) {
            expect(app(PlatformGuard::class)->transaction($operation, fn (): string => 'allowed'))->toBe('allowed');
        } else {
            expect(fn () => app(PlatformGuard::class)->transaction($operation, fn (): string => 'unexpected'))->toThrow(PlatformBlocked::class);
        }
    }
})->with([
    ['normal', ['read', 'mutation', 'financial', 'derived', 'external']],
    ['degraded', ['read', 'mutation', 'financial', 'derived', 'external']],
    ['financial_freeze', ['read', 'mutation', 'derived', 'external']],
    ['read_only', ['read']],
    ['unavailable', []],
]);

test('missing invalid and incompatible platform state blocks protected mutations', function (string $failure) {
    match ($failure) {
        'missing' => DB::table('platform_state')->delete(),
        'invalid' => DB::table('platform_state')->update(['mode' => 'invented']),
        'incompatible' => DB::table('platform_state')->update(['catalogue_version' => 999]),
    };

    expect(fn () => app(PlatformGuard::class)->transaction('financial', fn () => null))->toThrow(PlatformBlocked::class);
    $this->postJson('/customers')->assertServiceUnavailable()->assertJsonPath('error_code', 'platform_state_unavailable');
})->with(['missing', 'invalid', 'incompatible']);

test('expired restrictions never automatically reopen writes', function () {
    $this->freezeTime();
    app(PlatformState::class)->transition([...platformTransitionInput('financial_freeze'), 'expires_at' => now()->addMinute()->toIso8601String()]);
    $this->travel(2)->minutes();

    expect(fn () => app(PlatformGuard::class)->transaction('financial', fn () => null))->toThrow(PlatformBlocked::class);
    expect(app(PlatformState::class)->publicStatus()['mode'])->toBe('financial_freeze');
});

test('read only blocks direct nonfinancial services before authorization or writes', function () {
    $actor = User::factory()->admin()->create();
    platformMode('read_only');

    expect(fn () => app(RegistrationFeeService::class)->publishRule($actor, [], Request::create('/')))->toThrow(PlatformBlocked::class);
    $this->assertDatabaseCount('fee_rules', 0);
});

test('financial freeze blocks direct ledger posting and customer lifecycle hooks', function () {
    $actor = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();
    platformMode('financial_freeze');

    expect(fn () => DB::transaction(fn () => app(CollectionLedgerService::class)->postCashSavings(99, $customer->id, 99, 100, $actor)))->toThrow(PlatformBlocked::class);
    expect(fn () => app(CustomerStatusManagementService::class)->transition($actor, $customer, CustomerStatus::Restricted, 1, 'Incident', 'Temporary hold'))->toThrow(PlatformBlocked::class);
    $this->assertDatabaseCount('ledger_posting_groups', 0);
    expect($customer->fresh()->operational_status)->toBe($customer->operational_status);
});

test('read only defers projection work without counting failures or changing watermarks', function () {
    Queue::fake([ProjectAuditEvent::class]);
    AuditEvent::record('customer.status_changed', 'customer', null, null, ['to_status' => 'inactive']);
    platformMode('read_only');
    $before = DB::table('audit_projection_work')->first();

    expect(fn () => app(AuditProjection::class)->project((int) $before->canonical_event_id))->toThrow(PlatformBlocked::class);
    $this->assertDatabaseHas('audit_projection_work', ['canonical_event_id' => $before->canonical_event_id, 'status' => 'pending', 'attempts' => 0]);
    $this->artisan('audit:drain')->assertSuccessful();
    Queue::assertPushed(ProjectAuditEvent::class, 2);
});

test('paused workers do not reserve jobs exhaust attempts or mark failures', function () {
    config(['queue.default' => 'database']);
    ProjectAuditEvent::dispatch(99);
    platformMode('read_only');

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
    $this->assertDatabaseCount('jobs', 2);
    $this->assertDatabaseHas('jobs', ['attempts' => 0, 'reserved_at' => null]);
    $this->assertDatabaseCount('failed_jobs', 0);
});

test('unavailable responses retain liveness and have no infrastructure details', function () {
    platformMode('unavailable');

    $this->get('/up')->assertOk();
    $this->getJson('/customers')->assertServiceUnavailable()->assertJsonStructure(['message', 'error_code', 'correlation_reference'])->assertDontSee('INC-100');
    $this->get('/login', ['X-Inertia' => 'true'])->assertServiceUnavailable()->assertHeader('X-Inertia', 'true')->assertJsonPath('component', 'PlatformUnavailable')->assertJsonMissingPath('props.auth')->assertJsonMissingPath('props.platform');
});

test('read only leaves authorized reads and platform props available while denying changes', function () {
    platformMode('read_only');

    $this->get('/login')->assertInertia(fn (Assert $page) => $page->component('auth/Login')->where('platform.mode', 'read_only'));
    $this->postJson('/login', ['email' => 'private@example.com', 'password' => 'private'])->assertServiceUnavailable()->assertDontSee('private');
});

test('diagnostics distinguish missing fresh stale and future heartbeat evidence', function () {
    $this->freezeTime();
    $diagnostics = app(PlatformDiagnostics::class);
    expect($diagnostics->report()['checks']['worker']['state'])->toBe('Unknown');
    $diagnostics->heartbeat('worker');
    expect($diagnostics->report()['checks']['worker']['state'])->toBe('Ready');
    $this->travel(301)->seconds();
    expect($diagnostics->report()['checks']['worker']['state'])->toBe('Stale');
    DB::table('platform_heartbeats')->where('component', 'worker')->update(['observed_at' => now()->addMinute()]);
    $report = $diagnostics->report();

    expect($report['checks']['worker']['state'])->toBe('Unverified');
    expect($report['checks']['backup']['state'])->toBe('Unverified');
    expect($report['production_certified'])->toBeFalse();
    expect($report['checks']['database']['state'])->toBe('Ready');
});

test('request diagnostics exclude credentials request payloads and operator evidence', function () {
    platformMode('read_only');
    Log::spy();

    $this->postJson('/login', ['email' => 'private@example.com', 'password' => 'never-log'])->assertServiceUnavailable();
    Log::shouldHaveReceived('info')->once()->with('platform.operation', Mockery::on(fn (array $context): bool => array_keys($context) === ['kind', 'service_class', 'duration_ms', 'outcome', 'correlation_reference'] && $context['outcome'] === 'paused'));
});

test('cli transitions require complete references and print safe status only', function () {
    $this->artisan('platform:set-mode', ['mode' => 'financial_freeze'])->assertFailed();
    $this->artisan('platform:set-mode', ['mode' => 'financial_freeze', '--operation' => (string) Str::uuid(), '--expected-version' => 1,
        '--operator' => 'ops-service', '--reason' => 'Contain incident', '--incident' => 'INC-100', '--json' => true])->assertSuccessful();
    $this->artisan('platform:status', ['--json' => true])->expectsOutputToContain('financial_freeze')->assertSuccessful();
    $this->artisan('platform:check', ['--json' => true])->expectsOutputToContain('"production_certified":false')->assertSuccessful();
});

test('unreadable state returns safe unavailability instead of database details', function () {
    Schema::rename('platform_state', 'platform_state_offline');

    $this->postJson('/customers')->assertServiceUnavailable()->assertJsonPath('error_code', 'platform_state_unavailable')->assertDontSee('platform_state_offline');
    expect(app(PlatformState::class)->publicStatus()['version'])->toBeNull();
});

test('matching mode retries resolve after a later version and after their expiry', function () {
    $this->freezeTime();
    $input = [...platformTransitionInput('financial_freeze'), 'expires_at' => now()->addMinute()->toIso8601String()];
    $original = app(PlatformState::class)->transition($input);
    app(PlatformState::class)->transition(platformTransitionInput('normal', 2));
    $this->travel(2)->minutes();

    expect(app(PlatformState::class)->transition($input))->toBe($original);
    $this->assertDatabaseHas('platform_state', ['mode' => 'normal', 'version' => 3]);
    $this->assertDatabaseCount('platform_operations', 2);
});

test('unclassified registered writes fail closed without affecting disabled endpoint responses', function () {
    Route::post('unclassified-platform-write', fn () => response()->json(['unexpected' => true]));

    $this->postJson('/unclassified-platform-write')->assertServiceUnavailable()->assertJsonPath('error_code', 'platform_operation_unclassified');
    $this->postJson('/register')->assertNotFound();
});

test('a nested financial guard uses safe JSON even when the route is nonfinancial', function () {
    Route::post('agents/platform-guard-test', fn () => app(PlatformGuard::class)->transaction('financial', fn () => response()->json(['unexpected' => true])))->name('agents.store');
    platformMode('financial_freeze');

    $this->postJson('/agents/platform-guard-test')->assertServiceUnavailable()->assertJsonPath('error_code', 'platform_operation_paused')->assertJsonStructure(['correlation_reference'])->assertDontSee('stack');
});

test('resuming normal mode processes the existing queue without manufacturing failures', function () {
    config(['queue.default' => 'database']);
    ProjectAuditEvent::dispatch(99);
    platformMode('read_only');
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
    $before = DB::table('jobs')->count();
    app(PlatformState::class)->transition(platformTransitionInput('normal', 2));
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();

    expect(DB::table('jobs')->count())->toBe($before);
    $this->assertDatabaseCount('failed_jobs', 0);
    expect(app(PlatformDiagnostics::class)->report()['checks']['worker']['state'])->toBe('Ready');
});

test('owning financial services reject direct calls before changing source state', function (string $owner) {
    $actor = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();
    platformMode('financial_freeze');
    $operation = (string) Str::uuid();
    $invoke = match ($owner) {
        'collection' => fn () => app(CollectionService::class)->record($actor, $customer, ['attempt_reference' => $operation]),
        'withdrawal' => fn () => app(WithdrawalService::class)->submit($actor, $customer, ['attempt_reference' => $operation]),
        'reversal' => fn () => app(ReversalService::class)->submit($actor, (new LedgerPostingGroup)->forceFill(['id' => 1]), ['attempt_reference' => $operation]),
        'registration' => fn () => app(CustomerRegistrationService::class)->register($actor, $operation, []),
        'fee' => fn () => app(FeeObligationService::class)->waive($actor, 1, 100, 'Containment', 'Fee waiver', $operation, Request::create('/')),
    };

    expect($invoke)->toThrow(PlatformBlocked::class);
    $this->assertDatabaseCount('ledger_posting_groups', 0);
    $this->assertDatabaseCount('collection_receipts', 0);
    $this->assertDatabaseCount('withdrawal_requests', 0);
    $this->assertDatabaseCount('reversal_requests', 0);
})->with(['collection', 'withdrawal', 'reversal', 'registration', 'fee']);

test('read only prevents state changes made by an email verification GET', function () {
    $user = User::factory()->unverified()->create();
    platformMode('read_only');
    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);

    $this->actingAs($user)->get($url)->assertServiceUnavailable();
    expect($user->fresh()->email_verified_at)->toBeNull();
});

test('undeclared jobs fail closed before executing their handler', function () {
    $executed = false;
    $job = new stdClass;

    expect(fn () => app(PlatformJobMiddleware::class)->handle($job, function () use (&$executed) {
        $executed = true;
    }))->toThrow(PlatformBlocked::class);
    expect($executed)->toBeFalse();
});

test('one unavailable diagnostic dependency does not hide independent evidence', function () {
    Schema::rename('failed_jobs', 'failed_jobs_offline');
    $diagnostics = app(PlatformDiagnostics::class);
    $diagnostics->heartbeat('scheduler');
    $report = $diagnostics->report();

    expect($report['checks']['database']['state'])->toBe('Ready');
    expect($report['checks']['scheduler']['state'])->toBe('Ready');
    expect($report['checks']['pending_work']['state'])->toBe('Unavailable');
    expect($report['checks']['schema']['missing'])->toContain('failed_jobs');
    expect($report['owner_readiness']['payout_execution']['state'])->toBe('Unavailable');
    expect(json_encode($report))->not->toContain('failed_jobs_offline', 'SQLSTATE', 'password');
});

test('paused submissions preserve their original UUID without echoing financial payloads', function () {
    platformMode('financial_freeze');
    $reference = (string) Str::uuid();
    $data = ['attempt_reference' => $reference, 'destination_reference' => 'private-bank-detail', 'internal_notes' => 'private-notes'];

    $this->postJson('/customers/CUS-000001/withdrawals', $data)->assertServiceUnavailable()
        ->assertJsonPath('operation_reference', $reference)->assertDontSee('private-bank-detail')->assertDontSee('private-notes');
    $this->post('/customers/CUS-000001/withdrawals', $data, ['X-Inertia' => 'true'])->assertServiceUnavailable()
        ->assertJsonPath('props.operation_reference', $reference)->assertJsonMissingPath('props.auth');
    $this->postJson('/customers/CUS-000001/withdrawals', ['attempt_reference' => 'private-notes'])->assertServiceUnavailable()->assertJsonMissingPath('operation_reference');
});
