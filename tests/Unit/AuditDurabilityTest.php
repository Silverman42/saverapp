<?php

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\AuditProjection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();
});

test('dispatch outage after actual commit leaves canonical evidence recoverable', function () {
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue offline'));
    $event = AuditEvent::record('customer.status_changed', 'customer', 1, null, ['to_status' => 'inactive']);
    expect($event->exists)->toBeTrue();
    $this->assertDatabaseCount('canonical_audit_events', 1);
    expect(DB::table('audit_projection_work')->value('status'))->toBe('pending');
    expect(app(AuditProjection::class)->drain())->toBe(1);
    $this->assertDatabaseCount('audit_search_documents', 1);
});

test('database rejection of canonical insert rolls back owner and compatibility writes', function () {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite fault injection; MySQL trigger controls have separate evidence.');
    }
    $user = User::factory()->customer()->create();
    $original = $user->name;
    DB::unprepared("CREATE TRIGGER fail_canonical_insert BEFORE INSERT ON canonical_audit_events BEGIN SELECT RAISE(ABORT, 'capture unavailable'); END");
    expect(fn () => DB::transaction(function () use ($user): void {
        $user->update(['name' => 'Must roll back']);
        AuditEvent::record('customer.profile_updated', 'customer', $user->id, null, ['changed_fields' => ['name']], $user);
    }))->toThrow(QueryException::class);
    expect($user->fresh()->name)->toBe($original);
    $this->assertDatabaseCount('audit_events', 0);
    $this->assertDatabaseCount('canonical_audit_events', 0);
    $this->assertDatabaseCount('audit_projection_work', 0);
});
