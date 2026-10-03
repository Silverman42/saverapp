<?php

use App\Enums\AdminPermission;
use App\Jobs\DeliverCollectionNotificationIntent;
use App\Models\AuditEvent;
use App\Models\FeeObligation;
use App\Models\FeeRule;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\AuditCapture;
use App\Services\CollectionReadService;
use App\Services\FeeObligationService;
use App\Services\PlatformState;
use App\Services\RegistrationFeeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\CreatesLifecycleCustomers;

require_once __DIR__.'/../CollectionFixtures.php';
require_once __DIR__.'/../WithdrawalFixtures.php';

uses(CreatesLifecycleCustomers::class);

/** @return array<string, int> */
function retirementSession(): array
{
    return ['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp];
}

/** @return array{User, FeeRule} */
function retirementFixture(string $kind = 'registration'): array
{
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $request = Request::create('/admin/fees/registration', 'POST');
    $request->setLaravelSession(app('session')->driver());
    $request->session()->put(retirementSession());
    $publicationOwner = app(RegistrationFeeService::class);
    $publicationData = [
        'kind' => $kind, 'rule_key' => $kind === 'plan' ? 'retirement_option' : 'registration',
        'model' => 'fixed', 'name' => 'Published agreed terms', 'amount_kobo' => 10001,
        'timing' => $kind === 'plan' ? 'cycle_completion' : 'registration',
        'basis' => 'none', 'settlement_source' => $kind === 'plan' ? 'savings_application' : 'external_receipt',
        'customer_description' => 'Your agreed one hundred naira and one kobo fee.',
        'publication_reason' => 'TEST FIXTURE reviewed prospective fee.',
    ];
    $publicationData['confirmed'] = true;
    $publicationData['preview_fingerprint'] = $publicationOwner->previewPublication($admin, $publicationData)['preview_fingerprint'];
    $rule = $publicationOwner->publishRule($admin, $publicationData, $request);

    return [$admin, $rule];
}

/** @return array<string, array<int, array<string, mixed>>> */
function retirementOwnerRows(): array
{
    $rows = [];
    foreach (['fee_rules', 'fee_snapshots', 'fee_obligations', 'fee_obligation_entries', 'ledger_posting_groups', 'ledger_entries',
        'thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'collection_receipts', 'withdrawal_reservations'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    }

    return $rows;
}

test('reviewed confirmed retirement stops new selection and preserves published pricing', function (string $kind): void {
    $this->freezeTime();
    [$admin, $rule] = retirementFixture($kind);
    $terms = $rule->fresh()->getAttributes();
    $baseline = retirementOwnerRows();
    $preview = $this->actingAs($admin)->postJson(route('admin.fees.registration.retirement-preview', $rule), ['reason' => 'Retire for new agreements.'])
        ->assertOk()->assertJsonPath('rule.version', 1)->assertJsonPath('rule.amount_kobo', 10001)
        ->assertJsonPath('reason', 'Retire for new agreements.')->json('preview_fingerprint');
    expect(retirementOwnerRows())->toBe($baseline);

    $this->withSession(retirementSession())->postJson(route('admin.fees.registration.retire', $rule), [
        'reason' => 'Retire for new agreements.', 'preview_fingerprint' => $preview, 'confirmed' => true,
    ])->assertRedirect(route('admin.fees.registration.index'));

    $retired = $rule->fresh();
    expect($retired->retired_at->timestamp)->toBe(now()->timestamp);
    expect(collect($retired->getAttributes())->except(['retired_at', 'updated_at'])->all())
        ->toBe(collect($terms)->except(['retired_at', 'updated_at'])->all());
    if ($kind === 'registration') {
        expect(app(RegistrationFeeService::class)->getCurrentRule())->toBeNull();
    } else {
        expect(app(RegistrationFeeService::class)->getCurrentPlanOptions())->toHaveCount(0);
    }
    $audit = AuditEvent::query()->where('event_type', 'fee_rule.retired')->sole();
    expect($audit->target_id)->toBe($rule->id);
    expect($audit->actor_id)->toBe($admin->id);
    $after = retirementOwnerRows();
    unset($baseline['fee_rules'], $after['fee_rules']);
    expect($after)->toBe($baseline);
})->with(['registration', 'plan']);

test('retirement preserves an issued registration agreement and assessed obligation', function (): void {
    $this->freezeTime();
    [$admin, $customer, $agent, $snapshot] = $this->createLifecycleFixture(10001);
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $obligation = app(FeeObligationService::class)->assessRegistrationSnapshot($snapshot, $admin);
    $rule = FeeRule::query()->findOrFail($snapshot->fee_rule_id);
    $baseline = retirementOwnerRows();
    $preview = $this->actingAs($admin)->postJson(route('admin.fees.registration.retirement-preview', $rule), ['reason' => 'End new onboarding selection.'])->assertOk()->json('preview_fingerprint');

    $this->withSession(retirementSession())->postJson(route('admin.fees.registration.retire', $rule), [
        'reason' => 'End new onboarding selection.', 'preview_fingerprint' => $preview, 'confirmed' => true,
    ])->assertRedirect(route('admin.fees.registration.index'));

    $after = retirementOwnerRows();
    unset($baseline['fee_rules'], $after['fee_rules']);
    expect($after)->toBe($baseline);
    expect(app(FeeObligationService::class)->assessRegistrationSnapshot($snapshot, $admin)->id)->toBe($obligation->id);
    expect($snapshot->fresh()->amount_kobo)->toBe(10001);
    expect(app(RegistrationFeeService::class)->previewFee()['available'])->toBeFalse();
});

test('retirement requires explicit confirmation and a valid review token without owner writes', function (array $changes, string $field): void {
    $this->freezeTime();
    [$admin, $rule] = retirementFixture();
    $preview = app(RegistrationFeeService::class)->previewRetirement($admin, $rule->id, 'End new selection.');
    $baseline = retirementOwnerRows();

    $this->actingAs($admin)->withSession(retirementSession())->postJson(route('admin.fees.registration.retire', $rule), array_replace([
        'reason' => 'End new selection.', 'preview_fingerprint' => $preview['preview_fingerprint'], 'confirmed' => true,
    ], $changes))->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(retirementOwnerRows())->toBe($baseline);
    expect(AuditEvent::query()->where('event_type', 'fee_rule.retired')->exists())->toBeFalse();
})->with([
    'missing confirmation' => [['confirmed' => null], 'confirmed'],
    'declined confirmation' => [['confirmed' => false], 'confirmed'],
    'missing review' => [['preview_fingerprint' => null], 'preview_fingerprint'],
    'malformed review' => [['preview_fingerprint' => 'invalid'], 'preview_fingerprint'],
    'empty reason' => [['reason' => ' '], 'reason'],
    'oversized reason' => [['reason' => str_repeat('a', 501)], 'reason'],
]);

test('retirement rejects a changed reason or forged review with 409 and no owner writes', function (array $changes): void {
    $this->freezeTime();
    [$admin, $rule] = retirementFixture();
    $preview = app(RegistrationFeeService::class)->previewRetirement($admin, $rule->id, 'End new selection.');
    $baseline = retirementOwnerRows();

    $this->actingAs($admin)->withSession(retirementSession())->postJson(route('admin.fees.registration.retire', $rule), array_replace([
        'reason' => 'End new selection.', 'preview_fingerprint' => $preview['preview_fingerprint'], 'confirmed' => true,
    ], $changes))->assertConflict();

    expect(retirementOwnerRows())->toBe($baseline);
    expect(AuditEvent::query()->where('event_type', 'fee_rule.retired')->exists())->toBeFalse();
})->with([
    'changed reason' => [['reason' => 'A different unreviewed instruction.']],
    'forged review' => [['preview_fingerprint' => str_repeat('0', 64)]],
]);

test('another authorized Admin cannot use the original actors retirement review', function (): void {
    $this->freezeTime();
    [$admin, $rule] = retirementFixture();
    $other = User::factory()->admin()->withTwoFactor()->create();
    $other->givePermissionTo(AdminPermission::FeesManage);
    $preview = app(RegistrationFeeService::class)->previewRetirement($admin, $rule->id, 'End new selection.');
    $baseline = retirementOwnerRows();

    $this->actingAs($other)->withSession(retirementSession())->postJson(route('admin.fees.registration.retire', $rule), [
        'reason' => 'End new selection.', 'preview_fingerprint' => $preview['preview_fingerprint'], 'confirmed' => true,
    ])->assertConflict();

    expect(retirementOwnerRows())->toBe($baseline);
});

test('retirement challenges an expired or recovery authentication session without owner writes', function (array $session): void {
    $this->freezeTime();
    [$admin, $rule] = retirementFixture();
    $preview = app(RegistrationFeeService::class)->previewRetirement($admin, $rule->id, 'End new selection.');
    $baseline = retirementOwnerRows();

    $this->actingAs($admin)->withSession(array_replace(retirementSession(), $session))->postJson(route('admin.fees.registration.retire', $rule), [
        'reason' => 'End new selection.', 'preview_fingerprint' => $preview['preview_fingerprint'], 'confirmed' => true,
    ])->assertStatus(423);

    expect(retirementOwnerRows())->toBe($baseline);
})->with([
    'expired password' => [['auth.password_confirmed_at' => 1]],
    'expired MFA' => [['auth.mfa_confirmed_at' => 1]],
    'recovery authentication' => [['recovery_code_used' => true]],
]);

test('retirement rejects a matching grant revoked after review with 403', function (): void {
    $this->freezeTime();
    [$admin, $rule] = retirementFixture();
    $preview = app(RegistrationFeeService::class)->previewRetirement($admin, $rule->id, 'End new selection.');
    $admin->revokePermissionTo(AdminPermission::FeesManage);
    $baseline = retirementOwnerRows();

    $this->actingAs($admin)->withSession(retirementSession())->postJson(route('admin.fees.registration.retire', $rule), [
        'reason' => 'End new selection.', 'preview_fingerprint' => $preview['preview_fingerprint'], 'confirmed' => true,
    ])->assertForbidden();

    expect(retirementOwnerRows())->toBe($baseline);
});

test('direct retirement enforces freshness reason and confirmation inside the owner', function (string $failure): void {
    $this->freezeTime();
    [$admin, $rule] = retirementFixture();
    $owner = app(RegistrationFeeService::class);
    $preview = $owner->previewRetirement($admin, $rule->id, 'End new selection.');
    $request = Request::create('/admin/fees/registration', 'POST');
    $request->setLaravelSession(app('session')->driver());
    $request->session()->flush();
    $request->session()->put($failure === 'freshness' ? [] : retirementSession());
    $baseline = retirementOwnerRows();

    expect(fn () => $owner->retireRule($admin, $rule->id, $failure === 'reason' ? ' ' : 'End new selection.', $request,
        $preview['preview_fingerprint'], $failure !== 'confirmation'))
        ->toThrow($failure === 'freshness' ? ConflictHttpException::class : ValidationException::class);

    expect(retirementOwnerRows())->toBe($baseline);
})->with(['freshness', 'reason', 'confirmation']);

test('retirement rejects an already ended reviewed rule without changing original retirement evidence', function (): void {
    $this->freezeTime();
    [$admin, $rule] = retirementFixture();
    $preview = app(RegistrationFeeService::class)->previewRetirement($admin, $rule->id, 'End new selection.');
    $payload = ['reason' => 'End new selection.', 'preview_fingerprint' => $preview['preview_fingerprint'], 'confirmed' => true];
    $this->actingAs($admin)->withSession(retirementSession())->postJson(route('admin.fees.registration.retire', $rule), $payload)->assertRedirect();
    $baseline = retirementOwnerRows();

    $this->postJson(route('admin.fees.registration.retire', $rule), $payload)->assertConflict();

    expect(retirementOwnerRows())->toBe($baseline);
    expect(AuditEvent::query()->where('event_type', 'fee_rule.retired')->count())->toBe(1);
});

test('future rules and manual charge rules are unavailable for catalogue retirement', function (string $kind): void {
    $this->freezeTime();
    [$admin, $rule] = retirementFixture();
    if ($kind === 'future') {
        $request = Request::create('/admin/fees/registration', 'POST');
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put(retirementSession());
        $publicationOwner = app(RegistrationFeeService::class);
        $publicationData = [
            'model' => 'fixed', 'name' => 'Future onboarding terms', 'amount_kobo' => 20000,
            'customer_description' => 'A prospective onboarding fee.', 'publication_reason' => 'TEST FIXTURE future terms.',
            'effective_at' => now()->addHour()->toIso8601String(),
        ];
        $publicationData['confirmed'] = true;
        $publicationData['preview_fingerprint'] = $publicationOwner->previewPublication($admin, $publicationData)['preview_fingerprint'];
        $rule = $publicationOwner->publishRule($admin, $publicationData, $request);
    } else {
        $rule = FeeRule::create(['version' => 1, 'kind' => 'manual', 'rule_key' => 'manual-separate-owner',
            'model' => 'fixed', 'name' => 'Separate manual charge terms', 'amount_kobo' => 10001,
            'timing' => 'manual', 'basis' => 'none', 'settlement_source' => 'external_receipt', 'currency' => 'NGN',
            'customer_description' => 'A separately controlled charge.', 'publication_reason' => 'TEST FIXTURE separate owner.',
            'effective_at' => now()->subHour(), 'published_by_user_id' => $admin->id]);
    }
    $baseline = retirementOwnerRows();

    $response = $this->actingAs($admin)->postJson(route('admin.fees.registration.retirement-preview', $rule), ['reason' => 'End new selection.']);
    $kind === 'future' ? $response->assertConflict() : $response->assertNotFound();
    $response = $this->withSession(retirementSession())->postJson(route('admin.fees.registration.retire', $rule), [
        'reason' => 'End new selection.', 'preview_fingerprint' => str_repeat('0', 64), 'confirmed' => true,
    ]);
    $kind === 'future' ? $response->assertConflict() : $response->assertNotFound();

    expect(retirementOwnerRows())->toBe($baseline);
})->with(['future', 'manual']);

test('catalogue retirement denies an Admin without the fee management grant', function (): void {
    $this->freezeTime();
    [$admin, $rule] = retirementFixture();
    $unprivileged = User::factory()->admin()->withTwoFactor()->create();
    $baseline = retirementOwnerRows();

    $this->actingAs($unprivileged)->postJson(route('admin.fees.registration.retirement-preview', $rule), ['reason' => 'End new selection.'])->assertForbidden();
    $this->withSession(retirementSession())->postJson(route('admin.fees.registration.retire', $rule), [
        'reason' => 'End new selection.', 'preview_fingerprint' => str_repeat('0', 64), 'confirmed' => true,
    ])->assertForbidden();

    expect(retirementOwnerRows())->toBe($baseline);
});

test('a successor published after retirement review requires a fresh review of the original interval', function (): void {
    $this->freezeTime();
    [$admin, $rule] = retirementFixture();
    $owner = app(RegistrationFeeService::class);
    $preview = $owner->previewRetirement($admin, $rule->id, 'End new selection.');
    $request = Request::create('/admin/fees/registration', 'POST');
    $request->setLaravelSession(app('session')->driver());
    $request->session()->put(retirementSession());
    $publicationOwner = $owner;
    $publicationData = [
        'name' => 'Scheduled successor', 'model' => 'fixed', 'amount_kobo' => 20000,
        'customer_description' => 'Future agreed onboarding terms.', 'publication_reason' => 'TEST FIXTURE subsequent publication.',
        'effective_at' => now()->addHour()->toIso8601String(),
    ];
    $publicationData['confirmed'] = true;
    $publicationData['preview_fingerprint'] = $publicationOwner->previewPublication($admin, $publicationData)['preview_fingerprint'];
    $publicationOwner->publishRule($admin, $publicationData, $request);
    $baseline = retirementOwnerRows();

    $this->actingAs($admin)->withSession(retirementSession())->postJson(route('admin.fees.registration.retire', $rule), [
        'reason' => 'End new selection.', 'preview_fingerprint' => $preview['preview_fingerprint'], 'confirmed' => true,
    ])->assertConflict();

    expect(retirementOwnerRows())->toBe($baseline);
    $fresh = $this->postJson(route('admin.fees.registration.retirement-preview', $rule), ['reason' => 'End new selection.'])->assertOk()->json('preview_fingerprint');
    $this->postJson(route('admin.fees.registration.retire', $rule), [
        'reason' => 'End new selection.', 'preview_fingerprint' => $fresh, 'confirmed' => true,
    ])->assertRedirect();
    expect($rule->fresh()->retired_at->timestamp)->toBe(now()->timestamp);
});

test('read-only platform mode permits retirement review and blocks confirmation', function (): void {
    $this->freezeTime();
    [$admin, $rule] = retirementFixture();
    app(PlatformState::class)->transition([
        'mode' => 'read_only', 'expected_version' => 1, 'operation_id' => (string) Str::uuid(),
        'operator' => 'test-operator', 'reason' => 'TEST FIXTURE read-only containment.', 'incident' => 'TEST-RETIREMENT', 'expires_at' => null,
    ]);
    $baseline = retirementOwnerRows();
    $preview = $this->actingAs($admin)->postJson(route('admin.fees.registration.retirement-preview', $rule), ['reason' => 'End new selection.'])->assertOk()->json('preview_fingerprint');

    $this->withSession(retirementSession())->postJson(route('admin.fees.registration.retire', $rule), [
        'reason' => 'End new selection.', 'preview_fingerprint' => $preview, 'confirmed' => true,
    ])->assertServiceUnavailable();

    expect(retirementOwnerRows())->toBe($baseline);
});

test('late retirement audit failure rolls back the applicability change', function (): void {
    $this->freezeTime();
    [$admin, $rule] = retirementFixture();
    $preview = app(RegistrationFeeService::class)->previewRetirement($admin, $rule->id, 'End new selection.');
    $baseline = retirementOwnerRows();
    $this->partialMock(AuditCapture::class, function (MockInterface $mock): void {
        $mock->shouldReceive('record')->once()->withArgs(fn (string $eventType): bool => $eventType === 'fee_rule.retired')
            ->andThrow(new RuntimeException('Injected retirement audit persistence failure.'));
    });

    $this->actingAs($admin)->withSession(retirementSession())->postJson(route('admin.fees.registration.retire', $rule), [
        'reason' => 'End new selection.', 'preview_fingerprint' => $preview['preview_fingerprint'], 'confirmed' => true,
    ])->assertInternalServerError();

    expect(retirementOwnerRows())->toBe($baseline);
    expect(AuditEvent::query()->where('event_type', 'fee_rule.retired')->exists())->toBeFalse();
});

test('fee rules are active for review at their exact effective second', function (): void {
    $this->freezeSecond();
    [$admin, $rule] = retirementFixture();

    expect(app(RegistrationFeeService::class)->serializeRule($rule)['is_active'])->toBeTrue();
    $this->actingAs($admin)->postJson(route('admin.fees.registration.retirement-preview', $rule), ['reason' => 'End new selection.'])->assertOk();
});

test('retiring a plan rule preserves its agreed fee at a later contribution trigger', function (): void {
    $this->freezeTime();
    config()->set('collections.enabled', true);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    enableFixtureMethod();
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture(feeAmountKobo: 10001);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $snapshot = $plan->currentTermsRevision()->feeSnapshot;
    $rule = FeeRule::query()->findOrFail($snapshot->fee_rule_id);
    $termsBefore = $plan->currentTermsRevision()->getAttributes();
    $snapshotBefore = $snapshot->getAttributes();
    $baseline = retirementOwnerRows();
    $preview = $this->actingAs($admin)->postJson(route('admin.fees.registration.retirement-preview', $rule), ['reason' => 'Stop new plan selection.'])->assertOk()->json('preview_fingerprint');
    $this->withSession(retirementSession())->postJson(route('admin.fees.registration.retire', $rule), [
        'reason' => 'Stop new plan selection.', 'preview_fingerprint' => $preview, 'confirmed' => true,
    ])->assertRedirect();
    $after = retirementOwnerRows();
    unset($baseline['fee_rules'], $after['fee_rules']);
    expect($after)->toBe($baseline);
    Queue::fake([DeliverCollectionNotificationIntent::class]);
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $review = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertOk()->json('preview_fingerprint');
    $payload['preview_fingerprint'] = $review;

    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();

    expect($plan->currentTermsRevision()->getAttributes())->toBe($termsBefore);
    expect($snapshot->fresh()->getAttributes())->toBe($snapshotBefore);
    $this->assertDatabaseCount('collection_receipts', 1);
    $obligation = FeeObligation::query()->where('source_type', 'plan')->where('source_id', $plan->plan_id)->sole();
    expect($obligation->amount_kobo)->toBe(10001);
    expect($obligation->fee_snapshot_id)->toBe($snapshot->id);
    expect($obligation->outstandingAmountKobo())->toBe(0);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(189999);
    Queue::assertPushed(DeliverCollectionNotificationIntent::class);
});
