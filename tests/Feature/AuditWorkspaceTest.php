<?php

use App\Enums\AdminPermission;
use App\Jobs\ProjectAuditEvent;
use App\Models\AuditEvent;
use App\Models\CanonicalAuditEvent;
use App\Models\User;
use App\Services\AuditCapture;
use App\Services\AuditProjection;
use App\Services\AuditWorkspace;
use App\Support\AuditIdentityConflict;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

beforeEach(function () {
    config(['audit.enabled' => true]);
});

function auditViewer(): User
{
    $user = User::factory()->admin()->withTwoFactor()->create();
    $user->givePermissionTo(AdminPermission::AuditView);

    return $user;
}
function auditFixture(?User $actor = null, array $context = []): AuditEvent
{
    return AuditEvent::record('customer.status_changed', 'customer', 99, 'CUS-TEST',
        ['from_status' => 'active', 'to_status' => 'inactive', 'to_version' => 2], $actor, $context);
}
test('canonical audit and owner mutation roll back together', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $user = User::factory()->customer()->create();
    try {
        DB::transaction(function () use ($user): void {
            $user->update(['name' => 'Changed name']);
            auditFixture($user);
            throw new RuntimeException('Owner failure');
        });
    } catch (RuntimeException) {
    }
    expect($user->fresh()->name)->not->toBe('Changed name');
    $this->assertDatabaseCount('audit_events', 0);
    $this->assertDatabaseCount('canonical_audit_events', 0);
    $this->assertDatabaseCount('audit_projection_work', 0);
    Queue::assertNothingPushed();
});
test('required capture failure prevents protected mutation', function () {
    $user = User::factory()->customer()->create();
    expect(fn () => DB::transaction(function () use ($user): void {
        $user->update(['name' => 'Uncommitted']);
        AuditEvent::record('unregistered.event', 'customer', $user->id, null, [], $user);
    }))->toThrow(InvalidArgumentException::class);
    expect($user->fresh()->name)->not->toBe('Uncommitted');
});
test('sensitive values are encrypted separately and excluded from compatibility and projection', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $event = AuditEvent::record('agent.registered', 'agent', 1, 'AGT-TEST', ['name' => 'Private name', 'email_normalized' => 'private@example.test', 'account_state' => 'invited']);
    app(AuditProjection::class)->drain();
    $canonical = DB::table('canonical_audit_events')->sole();
    $protected = DB::table('audit_protected_payloads')->sole();
    expect($event->payload)->not->toHaveKey('name')->and($canonical->content)->not->toContain('private@example.test')->and($protected->ciphertext)->not->toContain('Private name');
    expect(Crypt::decryptString($protected->ciphertext))->toContain('Private name');
    expect(json_encode(DB::table('audit_search_documents')->sole()))->not->toContain('private@example.test');
});
test('prohibited fields and nested credentials never persist', function (array $payload) {
    expect(fn () => AuditEvent::record('customer.profile_updated', 'customer', 1, null, $payload))->toThrow(InvalidArgumentException::class);
    $this->assertDatabaseCount('audit_events', 0);
    $this->assertDatabaseCount('canonical_audit_events', 0);
})->with([[['password' => 'never']], [['before_values' => ['token' => 'never']]], [['cookie' => 'never']], [['unrecognized' => 'never']]]);
test('canonical evidence resists model and direct database updates and deletes', function () {
    Queue::fake([ProjectAuditEvent::class]);
    auditFixture();
    $event = CanonicalAuditEvent::query()->sole();
    expect(fn () => $event->forceFill(['outcome' => 'Failed'])->save())->toThrow(LogicException::class);
    expect(fn () => $event->delete())->toThrow(LogicException::class);
    expect(fn () => DB::table('canonical_audit_events')->update(['outcome' => 'Failed']))->toThrow(QueryException::class);
    expect(fn () => DB::table('canonical_audit_events')->delete())->toThrow(QueryException::class);
});
test('historical attribution survives subsequent actor changes', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $viewer = auditViewer();
    auditFixture($viewer);
    $before = DB::table('canonical_audit_events')->sole()->content;
    $viewer->update(['name' => 'Later name']);
    expect(DB::table('canonical_audit_events')->sole()->content)->toBe($before);
});
test('same operation is replayed without another canonical record', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $actor = User::factory()->customer()->create();
    $first = auditFixture($actor, ['operation_id' => 'stable-owner-result']);
    $second = auditFixture($actor, ['operation_id' => 'stable-owner-result']);
    expect($first->id)->toBe($second->id);
    $this->assertDatabaseCount('canonical_audit_events', 1);
});
test('conflicting operation raises a critical case and preserves original content', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $first = auditFixture(null, ['operation_id' => 'same-result']);
    try {
        AuditEvent::record('customer.status_changed', 'customer', 99, 'CUS-TEST', ['to_status' => 'archived'], null, ['operation_id' => 'same-result']);
    } catch (AuditIdentityConflict $conflict) {
        app(AuditCapture::class)->reportConflict($conflict);
    }
    expect(DB::table('security_cases')->value('severity'))->toBe('Critical');
    expect($first->fresh()->payload['to_status'])->toBe('inactive');
});
test('projection recovery and rebuild do not repeat owner effects', function () {
    Queue::fake([ProjectAuditEvent::class]);
    auditFixture();
    expect(DB::table('audit_projection_work')->value('status'))->toBe('pending');
    app(AuditProjection::class)->drain();
    app(AuditProjection::class)->drain();
    $first = app(AuditProjection::class)->rebuild();
    $second = app(AuditProjection::class)->rebuild();
    expect($second)->toBe($first + 1);
    expect(DB::table('audit_search_documents')->where('index_version', $second)->count())->toBe(1);
    $this->assertDatabaseCount('canonical_audit_events', 1);
});
test('legacy import is safe restartable and explicitly unverified', function () {
    Queue::fake([ProjectAuditEvent::class]);
    DB::table('audit_events')->insert(['event_type' => 'agent.registered', 'actor_type' => 'admin', 'target_type' => 'agent', 'target_id' => 1, 'payload' => json_encode(['name' => 'Old private name', 'password' => 'historical-secret', 'account_state' => 'invited']), 'created_at' => now()->subYear()]);
    $this->artisan('audit:import-history')->assertSuccessful();
    $this->artisan('audit:import-history')->assertSuccessful();
    $event = DB::table('canonical_audit_events')->sole();
    expect((bool) $event->legacy_evidence)->toBeTrue()->and($event->content)->not->toContain('Old private name')->not->toContain('historical-secret')->toContain('unavailable');
    $this->assertDatabaseCount('audit_events', 1);
    $this->assertDatabaseCount('security_cases', 0);
    $this->assertDatabaseCount('notification_events', 0);
    Queue::assertNothingPushed();
});
test('unsupported historical records are retained with safe diagnostics', function () {
    DB::table('audit_events')->insert(['event_type' => 'old.unknown', 'target_type' => 'old', 'payload' => '{}', 'created_at' => now()]);
    $this->artisan('audit:import-history')->assertSuccessful();
    $this->assertDatabaseCount('audit_events', 1);
    $this->assertDatabaseCount('canonical_audit_events', 0);
    expect(DB::table('audit_import_results')->value('diagnostic_code'))->toBe('unsupported_schema');
});
test('audit and security permissions do not imply one another or export', function () {
    $security = User::factory()->admin()->withTwoFactor()->create();
    $security->givePermissionTo(AdminPermission::SecurityOperationsManage);
    $this->actingAs($security)->get(route('admin.audit.index'))->assertForbidden();
    $viewer = auditViewer();
    $this->actingAs($viewer)->get(route('admin.security.index'))->assertForbidden();
    $export = User::factory()->admin()->withTwoFactor()->create();
    $export->givePermissionTo(AdminPermission::ReportsExport);
    $this->actingAs($export)->get(route('admin.audit.index'))->assertForbidden();
});
test('unprivileged roles cannot access audit workspace', function (string $role) {
    $user = User::factory()->{$role}()->create();
    $this->actingAs($user)->get(route('admin.audit.index'))->assertForbidden();
})->with(['customer', 'agent', 'admin']);
test('audit guest is redirected to login', function () {
    $this->get(route('admin.audit.index'))->assertRedirect(route('login'));
});
test('audit detail uses canonical lookup during lag and captures protected read', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $viewer = auditViewer();
    auditFixture();
    $id = DB::table('canonical_audit_events')->value('event_id');
    $this->actingAs($viewer)->get(route('admin.audit.show', $id))->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/audit/Show')->where('event.summary.event_id', $id)->where('event.content.integrity', 'Unverified'));
    expect(AuditEvent::query()->where('event_type', 'audit.detail_viewed')->count())->toBe(1);
});
test('partial index cannot claim complete absence', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $viewer = auditViewer();
    auditFixture();
    $result = app(AuditWorkspace::class)->search($viewer, []);
    expect($result['rows'])->toBe([])->and($result['health']['status'])->toBe('partial')->and($result['health']['pending'])->toBe(1);
});
test('cursor binds viewer filters and projection version', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $viewer = auditViewer();
    for ($i = 0; $i < 26; $i++) {
        auditFixture();
    }
    app(AuditProjection::class)->drain();
    $result = app(AuditWorkspace::class)->search($viewer, []);
    $cursor = $result['next_cursor'];
    expect($cursor)->not->toBeNull();
    expect(app(AuditWorkspace::class)->search($viewer, ['cursor' => $cursor])['rows'])->toHaveCount(1);
    expect(fn () => app(AuditWorkspace::class)->search(auditViewer(), ['cursor' => $cursor]))->toThrow(ConflictHttpException::class);
    expect(fn () => app(AuditWorkspace::class)->search($viewer, ['cursor' => $cursor, 'category' => 'agent']))->toThrow(ConflictHttpException::class);
    app(AuditProjection::class)->rebuild();
    expect(fn () => app(AuditWorkspace::class)->search($viewer, ['cursor' => $cursor]))->toThrow(ConflictHttpException::class);
});
test('tampered cursor and overlong ranges fail safely', function () {
    $viewer = auditViewer();
    $this->actingAs($viewer)->get(route('admin.audit.index', ['cursor' => 'invalid']))->assertConflict();
    $this->get(route('admin.audit.index', ['from' => '2020-01-01', 'to' => now()->format('Y-m-d')]))->assertSessionHasErrors('from');
});
test('revoking audit grant removes access immediately', function () {
    $viewer = auditViewer();
    $viewer->revokePermissionTo(AdminPermission::AuditView);
    $this->actingAs($viewer)->get(route('admin.audit.index'))->assertForbidden();
});

test('bounded rebuild resumes and preserves active projection until coverage passes', function () {
    Queue::fake([ProjectAuditEvent::class]);
    for ($i = 0; $i < 3; $i++) {
        auditFixture();
    }
    expect(app(AuditProjection::class)->rebuild(1))->toBeNull();
    expect(DB::table('audit_projection_state')->value('active_version'))->toBe(1);
    expect(app(AuditProjection::class)->rebuild(1))->toBeNull();
    expect(app(AuditProjection::class)->rebuild(1))->toBe(2);
    expect(DB::table('audit_search_documents')->where('index_version', 2)->count())->toBe(3);
    $this->assertDatabaseCount('canonical_audit_events', 3);
});

test('permission version and watermark changes invalidate continuation', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $viewer = auditViewer();
    for ($i = 0; $i < 26; $i++) {
        auditFixture();
    }
    app(AuditProjection::class)->drain();
    $cursor = app(AuditWorkspace::class)->search($viewer, [])['next_cursor'];
    $viewer->increment('permission_version');
    expect(fn () => app(AuditWorkspace::class)->search($viewer, ['cursor' => $cursor]))->toThrow(ConflictHttpException::class);
    $cursor = app(AuditWorkspace::class)->search($viewer, [])['next_cursor'];
    auditFixture();
    app(AuditProjection::class)->drain();
    expect(fn () => app(AuditWorkspace::class)->search($viewer, ['cursor' => $cursor]))->toThrow(ConflictHttpException::class);
});

test('replay survives authentication freshness changes without duplicate evidence', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $first = auditFixture(null, ['operation_id' => 'freshness-replay', 'fresh_authentication' => true]);
    $second = auditFixture(null, ['operation_id' => 'freshness-replay', 'fresh_authentication' => false]);
    expect($second->id)->toBe($first->id);
    $this->assertDatabaseCount('canonical_audit_events', 1);
});

test('rebuild refuses altered projection fields before promotion and retries from canonical', function () {
    Queue::fake([ProjectAuditEvent::class]);
    auditFixture();
    auditFixture();
    expect(app(AuditProjection::class)->rebuild(1))->toBeNull();
    DB::table('audit_search_documents')->where('index_version', 2)->update(['target_reference' => 'ALTERED']);
    expect(fn () => app(AuditProjection::class)->rebuild(1))->toThrow(RuntimeException::class);
    expect(DB::table('audit_projection_state')->value('active_version'))->toBe(1);
    expect(app(AuditProjection::class)->rebuild(100))->toBe(2);
    expect(DB::table('audit_search_documents')->where('index_version', 2)->where('target_reference', 'ALTERED')->exists())->toBeFalse();
    $this->assertDatabaseCount('canonical_audit_events', 2);
});

test('scope polling checks authority without accessing protected event detail again', function () {
    Queue::fake([ProjectAuditEvent::class]);
    $viewer = auditViewer();
    auditFixture();
    $id = DB::table('canonical_audit_events')->value('event_id');
    $response = $this->actingAs($viewer)->get(route('admin.audit.show', $id))->assertOk();
    $version = $response->viewData('page')['version'];
    $this->get(route('admin.audit.show', $id), ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'admin/audit/Show', 'X-Inertia-Partial-Data' => 'scope'])->assertOk()->assertJsonMissingPath('props.event');
    expect(AuditEvent::query()->where('event_type', 'audit.detail_viewed')->count())->toBe(1);
    $viewer->revokePermissionTo(AdminPermission::AuditView);
    $this->get(route('admin.audit.show', $id), ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'admin/audit/Show', 'X-Inertia-Partial-Data' => 'scope'])->assertForbidden();
});
