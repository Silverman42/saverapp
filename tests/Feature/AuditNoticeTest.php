<?php

use App\Enums\AdminPermission;
use App\Models\AuditEvent;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function auditNoticeTitles(object $test, User $user): array
{
    $titles = [];
    $test->actingAs($user)->get(route('notifications.index'))->assertInertia(function (Assert $page) use (&$titles): void {
        $titles = collect($page->toArray()['props']['inbox']['items'])->pluck('title')->all();
    });

    return $titles;
}

test('a password change notifies only the account holder without repeating secrets', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user)->from(route('security.edit'))->put(route('user-password.update'), [
        'current_password' => 'password', 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password',
    ])->assertSessionHasNoErrors();

    expect(auditNoticeTitles($this, $user))->toBe(['Your password was changed'])
        ->and(auditNoticeTitles($this, $other))->toBe([])
        ->and(DB::table('notifications')->where('notifiable_id', $user->id)->value('data'))->not->toContain('new-secure-password', 'current_password');
});

test('permission changes notify the affected Admin and other Admin managers but not the actor', function (): void {
    $actor = User::factory()->admin()->withTwoFactor()->create();
    $actor->givePermissionTo(AdminPermission::AdminsManage->value);
    $peer = User::factory()->admin()->create();
    $peer->givePermissionTo(AdminPermission::AdminsManage->value);
    $target = User::factory()->admin()->withTwoFactor()->create();
    $outsider = User::factory()->admin()->create();

    $this->actingAs($actor)->withSession(['auth.fresh_until' => Carbon::now()->timestamp + 600,
        'auth.password_confirmed_at' => Carbon::now()->timestamp, 'auth.mfa_confirmed_at' => Carbon::now()->timestamp])
        ->put(route('admin.access.permissions.update', $target->id), [
            'permissions' => [AdminPermission::AuditView->value], 'reason' => 'Audit coverage for the quarter.',
            'expected_permission_version' => $target->permission_version, 'confirmed' => true,
        ])->assertSessionHasNoErrors();

    expect(auditNoticeTitles($this, $target))->toBe(['Access permissions changed'])
        ->and(auditNoticeTitles($this, $peer))->toBe(['Access permissions changed'])
        ->and(auditNoticeTitles($this, $actor))->toBe([])
        ->and(auditNoticeTitles($this, $outsider))->toBe([]);
    $this->actingAs($peer)->get(route('notifications.index'))->assertInertia(fn (Assert $page) => $page
        ->where('inbox.items.0.summary', fn (string $summary) => ! str_contains($summary, 'Audit coverage')));

    $peer->revokePermissionTo(AdminPermission::AdminsManage->value);
    expect(auditNoticeTitles($this, $peer->fresh()))->toBe([]);
});

test('ledger integrity incidents notify reconciliation managers while their permission lasts', function (): void {
    $manager = User::factory()->admin()->create();
    $manager->givePermissionTo(AdminPermission::ReconciliationManage);
    $reader = User::factory()->admin()->create();
    $customer = User::factory()->create();

    AuditEvent::record('ledger.integrity_incident', LedgerPostingGroup::class, null, (string) Str::uuid(),
        ['category' => 'projection_rebuild', 'projection_version' => 1], context: ['executor' => self::class]);

    expect(auditNoticeTitles($this, $manager))->toBe(['Ledger integrity incident detected'])
        ->and(auditNoticeTitles($this, $reader))->toBe([])
        ->and(auditNoticeTitles($this, $customer))->toBe([]);
    $manager->revokePermissionTo(AdminPermission::ReconciliationManage);
    expect(auditNoticeTitles($this, $manager->fresh()))->toBe([]);
});

test('a notice failure never blocks the audited security action', function (): void {
    $user = User::factory()->create();
    DB::statement('DROP TABLE audit_notification_intents');

    $this->actingAs($user)->from(route('security.edit'))->put(route('user-password.update'), [
        'current_password' => 'password', 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password',
    ])->assertSessionHasNoErrors();

    expect(AuditEvent::query()->where('event_type', 'auth.password_changed')->where('target_id', $user->id)->exists())->toBeTrue();
});
