<?php

use App\Enums\AdminPermission;
use App\Models\AgentProfile;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\CollectionBatch;
use App\Models\CollectionException;
use App\Models\FinancialPeriod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function periodManager(): User
{
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FinancialPeriodsManage);

    return $admin;
}

function freshPeriodSession(): array
{
    return ['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp,
        'auth.mfa_confirmed_at' => now()->timestamp];
}

test('missing months remain closed and only the direct period grant exposes the workspace', function (): void {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    expect(FinancialPeriod::query()->count())->toBe(0);
    $this->actingAs($admin)->get(route('admin.financial-periods.index'))->assertForbidden();
    $this->withSession(freshPeriodSession())->post(route('admin.financial-periods.open'), [
        'month' => now('Africa/Lagos')->format('Y-m'), 'reason' => 'Open booking.',
    ])->assertForbidden();

    $admin->givePermissionTo(AdminPermission::FinancialPeriodsManage);
    $this->actingAs($admin->fresh())->get(route('admin.financial-periods.index'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/financial-periods/Index')
        ->where('periods.data', []));
});

test('opening creates one audited month without re-confirmation or granting anyone else', function (): void {
    $admin = periodManager();
    $month = now('Africa/Lagos')->format('Y-m');

    assertToast($this->actingAs($admin)->post(route('admin.financial-periods.open'), [
        'month' => $month, 'reason' => 'Prepare cash booking.',
    ])->assertRedirect(route('admin.financial-periods.index')), 'success', 'Period opened');
    expect(FinancialPeriod::query()->sole()->status)->toBe('open')
        ->and(FinancialPeriod::query()->sole()->version)->toBe(1)
        ->and(AuditEvent::query()->where('event_type', 'financial_period.open')->count())->toBe(1)
        ->and(DB::table('model_has_permissions')->count())->toBe(1);

    $this->post(route('admin.financial-periods.open'), [
        'month' => $month, 'reason' => 'Duplicate opening.',
    ])->assertStatus(409);
});

test('a month closes only after its end and settled cash batches then reopens with a new version', function (): void {
    $admin = periodManager();
    $month = CarbonImmutable::now('Africa/Lagos')->subMonth()->startOfMonth();
    $this->actingAs($admin)->withSession(freshPeriodSession())->post(route('admin.financial-periods.open'), [
        'month' => $month->format('Y-m'), 'reason' => 'Open prior month.',
    ])->assertRedirect();
    $period = FinancialPeriod::query()->sole();
    $agent = AgentProfile::factory()->active()->create();
    $batch = CollectionBatch::create([
        'business_version' => BusinessProfile::current()->version,
        'agent_profile_id' => $agent->id, 'received_date' => $month->toDateString(),
        'timezone' => 'Africa/Lagos', 'revision' => 1, 'status' => 'ready_for_review', 'version' => 1,
    ]);
    $close = ['version' => $period->version, 'reason' => 'Month completed.'];
    $this->post(route('admin.financial-periods.close', $month->format('Y-m')), $close)->assertStatus(409);
    $batch->update(['status' => 'reconciled']);
    $exception = CollectionException::create([
        'collection_batch_id' => $batch->id, 'opened_by_user_id' => $admin->id,
        'kind' => 'shortage', 'status' => 'open', 'amount_kobo' => 100,
        'reason' => 'Unresolved cash shortage.',
    ]);
    $this->post(route('admin.financial-periods.close', $month->format('Y-m')), $close)->assertStatus(409);
    $exception->update(['status' => 'resolved']);
    $this->post(route('admin.financial-periods.close', $month->format('Y-m')), $close)->assertRedirect();
    expect($period->fresh()->status)->toBe('closed')->and($period->fresh()->version)->toBe(2);
    $this->post(route('admin.financial-periods.reopen', $month->format('Y-m')), [
        'version' => 1, 'reason' => 'Stale reopen.',
    ])->assertStatus(409);
    $this->post(route('admin.financial-periods.reopen', $month->format('Y-m')), [
        'version' => 2, 'reason' => 'Verified late receipt.',
    ])->assertRedirect();
    expect($period->fresh()->status)->toBe('open')->and($period->fresh()->version)->toBe(3)
        ->and(AuditEvent::query()->where('event_type', 'like', 'financial_period.%')->count())->toBe(3);
});

test('current month cannot close even with no cash batches', function (): void {
    $admin = periodManager();
    $period = FinancialPeriod::factory()->create(['changed_by_user_id' => $admin->id]);
    $this->actingAs($admin)->withSession(freshPeriodSession())->post(
        route('admin.financial-periods.close', $period->month->format('Y-m')),
        ['version' => 1, 'reason' => 'Close too early.'],
    )->assertStatus(409);
    expect($period->fresh()->status)->toBe('open');
});
