<?php

use App\Enums\AdminPermission;
use App\Models\AuditEvent;
use App\Models\FeeRule;
use App\Models\User;
use App\Services\AuditCapture;
use App\Services\PlatformState;
use App\Services\RegistrationFeeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

function publicationReviewActor(): User
{
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);

    return $admin;
}

/** @return array<string, mixed> */
function publicationReviewPayload(): array
{
    return ['kind' => 'registration', 'name' => 'Reviewed onboarding fee', 'model' => 'fixed', 'amount_ngn' => '100.01',
        'customer_description' => 'An agreed onboarding charge.', 'publication_reason' => 'Approved prospective fee terms.'];
}

/** @return array<string, int> */
function publicationReviewSession(): array
{
    return ['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp];
}

/** @return array<string, array<int, array<string, mixed>>> */
function publicationReviewRows(): array
{
    $rows = [];
    foreach (['fee_rules', 'fee_snapshots', 'fee_obligations', 'fee_obligation_entries', 'ledger_posting_groups', 'ledger_entries',
        'thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'collection_receipts', 'withdrawal_reservations'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    }

    return $rows;
}

test('publication reviews validate and price supported models without owner writes before confirmation', function (array $changes, string $example, int $amountKobo): void {
    $this->freezeTime();
    $admin = publicationReviewActor();
    $payload = array_replace(publicationReviewPayload(), $changes);
    $baseline = publicationReviewRows();
    $review = $this->actingAs($admin)->postJson(route('admin.fees.registration.preview'), $payload)
        ->assertOk()->assertJsonPath('terms.current_catalogue_version', 0)->assertJsonPath('terms.next_version', 1)
        ->assertJsonPath('terms.currency', 'NGN')->assertJsonPath('example.formatted_fee', $example)->json();
    expect(publicationReviewRows())->toBe($baseline);
    expect(AuditEvent::query()->where('event_type', 'fee_rule.published')->exists())->toBeFalse();

    $this->withSession(publicationReviewSession())->postJson(route('admin.fees.registration.store'), [
        ...$payload, 'confirmed' => true, 'preview_fingerprint' => $review['preview_fingerprint'],
    ])->assertRedirect(route('admin.fees.registration.index'));

    $rule = FeeRule::query()->sole();
    expect($rule->amount_kobo)->toBe($amountKobo);
    expect($rule->version)->toBe(1);
    expect($rule->published_by_user_id)->toBe($admin->id);
    $this->assertDatabaseCount('fee_obligations', 0);
    $this->assertDatabaseCount('fee_snapshots', 0);
    $this->assertDatabaseCount('ledger_entries', 0);
    expect(AuditEvent::query()->where('event_type', 'fee_rule.published')->count())->toBe(1);
})->with([
    'registration fixed' => [[], '₦100.01', 10001],
    'registration explicit zero' => [['model' => 'no_fee', 'amount_ngn' => '0'], '₦0.00', 0],
    'one day completion' => [['kind' => 'plan', 'rule_key' => 'completion_day', 'timing' => 'cycle_completion', 'model' => 'one_day',
        'basis' => 'contractual_daily_contribution', 'amount_ngn' => '0'], '₦2,000.00', 0],
    'completion percentage' => [['kind' => 'plan', 'rule_key' => 'completion_percentage', 'timing' => 'cycle_completion', 'model' => 'percentage',
        'basis' => 'net_cycle_contributions', 'basis_points' => '200', 'amount_ngn' => '0'], '₦2,000.00', 0],
    'withdrawal percentage' => [['kind' => 'plan', 'rule_key' => 'withdrawal_percentage', 'timing' => 'withdrawal', 'model' => 'percentage',
        'basis' => 'gross_withdrawal_debit', 'basis_points' => 200, 'amount_ngn' => '0'], '₦2,000.00', 0],
]);

test('publication requires an explicit confirmation and review with 422 and no owner writes', function (array $changes, string $field): void {
    $this->freezeTime();
    $admin = publicationReviewActor();
    $payload = publicationReviewPayload();
    $review = $this->actingAs($admin)->postJson(route('admin.fees.registration.preview'), $payload)->assertOk()->json('preview_fingerprint');
    $baseline = publicationReviewRows();

    $this->withSession(publicationReviewSession())->postJson(route('admin.fees.registration.store'), array_replace([
        ...$payload, 'confirmed' => true, 'preview_fingerprint' => $review,
    ], $changes))->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(publicationReviewRows())->toBe($baseline);
    expect(AuditEvent::query()->where('event_type', 'fee_rule.published')->exists())->toBeFalse();
})->with([
    'missing confirmation' => [['confirmed' => null], 'confirmed'],
    'declined confirmation' => [['confirmed' => false], 'confirmed'],
    'missing review' => [['preview_fingerprint' => null], 'preview_fingerprint'],
    'blank reason' => [['publication_reason' => ' '], 'publication_reason'],
]);

test('altered reviewed publication terms or a forged token reject with 409 before owner writes', function (array $changes): void {
    $this->freezeTime();
    $admin = publicationReviewActor();
    $payload = publicationReviewPayload();
    $review = $this->actingAs($admin)->postJson(route('admin.fees.registration.preview'), $payload)->assertOk()->json('preview_fingerprint');
    $baseline = publicationReviewRows();

    $this->withSession(publicationReviewSession())->postJson(route('admin.fees.registration.store'), array_replace([
        ...$payload, 'confirmed' => true, 'preview_fingerprint' => $review,
    ], $changes))->assertConflict();

    expect(publicationReviewRows())->toBe($baseline);
    expect(AuditEvent::query()->where('event_type', 'fee_rule.published')->exists())->toBeFalse();
})->with([
    'amount' => [['amount_ngn' => '200.00']],
    'name' => [['name' => 'Unreviewed name']],
    'description' => [['customer_description' => 'Unreviewed Customer disclosure.']],
    'reason' => [['publication_reason' => 'Unreviewed approval reason.']],
    'model' => [['model' => 'no_fee', 'amount_ngn' => '0']],
    'forged token' => [['preview_fingerprint' => str_repeat('0', 64)]],
]);

test('publication does not ask an admin to confirm their identity again', function (): void {
    $this->freezeTime();
    $admin = publicationReviewActor();
    $payload = publicationReviewPayload();
    $review = $this->actingAs($admin)->postJson(route('admin.fees.registration.preview'), $payload)->assertOk()->json('preview_fingerprint');
    $baseline = publicationReviewRows();

    assertToast($this->withSession(array_replace(publicationReviewSession(), ['auth.password_confirmed_at' => 1, 'auth.mfa_confirmed_at' => 1]))
        ->post(route('admin.fees.registration.store'), [...$payload, 'confirmed' => true, 'preview_fingerprint' => $review])
        ->assertRedirect()->assertSessionHasNoErrors(), 'success', 'Fee rule published');

    expect(publicationReviewRows())->not->toBe($baseline);
});

test('another authorized Admin cannot confirm the original actors publication review', function (): void {
    $this->freezeTime();
    $admin = publicationReviewActor();
    $other = publicationReviewActor();
    $payload = publicationReviewPayload();
    $review = $this->actingAs($admin)->postJson(route('admin.fees.registration.preview'), $payload)->assertOk()->json('preview_fingerprint');
    $baseline = publicationReviewRows();

    $this->actingAs($other)->withSession(publicationReviewSession())->postJson(route('admin.fees.registration.store'), [
        ...$payload, 'confirmed' => true, 'preview_fingerprint' => $review,
    ])->assertConflict();

    expect(publicationReviewRows())->toBe($baseline);
});

test('revoking the fee management grant after review rejects publication with 403', function (): void {
    $this->freezeTime();
    $admin = publicationReviewActor();
    $payload = publicationReviewPayload();
    $review = $this->actingAs($admin)->postJson(route('admin.fees.registration.preview'), $payload)->assertOk()->json('preview_fingerprint');
    $admin->revokePermissionTo(AdminPermission::FeesManage);
    $baseline = publicationReviewRows();

    $this->withSession(publicationReviewSession())->postJson(route('admin.fees.registration.store'), [
        ...$payload, 'confirmed' => true, 'preview_fingerprint' => $review,
    ])->assertForbidden();

    expect(publicationReviewRows())->toBe($baseline);
});

test('a catalogue publication after review rejects the stale version and permits fresh prospective confirmation', function (): void {
    $this->freezeTime();
    $admin = publicationReviewActor();
    $payload = publicationReviewPayload();
    $review = $this->actingAs($admin)->postJson(route('admin.fees.registration.preview'), $payload)->assertOk()->json('preview_fingerprint');
    $other = [...$payload, 'name' => 'Earlier prospective version', 'amount_ngn' => '200.00', 'effective_at' => now()->addMinutes(5)->toIso8601String()];
    $otherReview = $this->postJson(route('admin.fees.registration.preview'), $other)->assertOk()->json('preview_fingerprint');
    $this->withSession(publicationReviewSession())->postJson(route('admin.fees.registration.store'), [...$other, 'confirmed' => true, 'preview_fingerprint' => $otherReview])->assertRedirect();
    $baseline = publicationReviewRows();

    $this->postJson(route('admin.fees.registration.store'), [...$payload, 'confirmed' => true, 'preview_fingerprint' => $review])->assertConflict();

    expect(publicationReviewRows())->toBe($baseline);
    $fresh = $this->postJson(route('admin.fees.registration.preview'), $payload)->assertOk()->assertJsonPath('terms.current_catalogue_version', 1)->json('preview_fingerprint');
    $this->postJson(route('admin.fees.registration.store'), [...$payload, 'confirmed' => true, 'preview_fingerprint' => $fresh])->assertRedirect();
    expect(FeeRule::query()->latest('version')->first()->version)->toBe(2);
    expect(app(RegistrationFeeService::class)->getCurrentRule()->amount_kobo)->toBe(10001);
    $this->assertDatabaseCount('fee_obligations', 0);
});

test('a future effective instant that expired after review cannot publish retroactively', function (): void {
    $this->freezeTime();
    $admin = publicationReviewActor();
    $payload = [...publicationReviewPayload(), 'effective_at' => now()->addMinute()->toIso8601String()];
    $review = $this->actingAs($admin)->postJson(route('admin.fees.registration.preview'), $payload)->assertOk()->json('preview_fingerprint');
    $baseline = publicationReviewRows();
    $this->travel(2)->minutes();

    $this->withSession(publicationReviewSession())->postJson(route('admin.fees.registration.store'), [
        ...$payload, 'confirmed' => true, 'preview_fingerprint' => $review,
    ])->assertUnprocessable()->assertJsonValidationErrors('effective_at');

    expect(publicationReviewRows())->toBe($baseline);
});

test('read-only platform mode permits publication review and blocks confirmation', function (): void {
    $this->freezeTime();
    $admin = publicationReviewActor();
    app(PlatformState::class)->transition(['mode' => 'read_only', 'expected_version' => 1, 'operation_id' => (string) Str::uuid(),
        'operator' => 'test-operator', 'reason' => 'TEST FIXTURE readonly review.', 'incident' => 'TEST-FEE', 'expires_at' => null]);
    $payload = publicationReviewPayload();
    $baseline = publicationReviewRows();
    $review = $this->actingAs($admin)->postJson(route('admin.fees.registration.preview'), $payload)->assertOk()->json('preview_fingerprint');

    $this->withSession(publicationReviewSession())->postJson(route('admin.fees.registration.store'), [
        ...$payload, 'confirmed' => true, 'preview_fingerprint' => $review,
    ])->assertServiceUnavailable();

    expect(publicationReviewRows())->toBe($baseline);
});

test('publication audit failure rolls back the new version', function (): void {
    $this->freezeTime();
    $admin = publicationReviewActor();
    $payload = publicationReviewPayload();
    $review = $this->actingAs($admin)->postJson(route('admin.fees.registration.preview'), $payload)->assertOk()->json('preview_fingerprint');
    $baseline = publicationReviewRows();
    $this->partialMock(AuditCapture::class, function (MockInterface $mock): void {
        $mock->shouldReceive('record')->once()->withArgs(fn (string $eventType): bool => $eventType === 'fee_rule.published')
            ->andThrow(new RuntimeException('Injected fee publication audit failure.'));
    });

    $this->withSession(publicationReviewSession())->postJson(route('admin.fees.registration.store'), [
        ...$payload, 'confirmed' => true, 'preview_fingerprint' => $review,
    ])->assertInternalServerError();

    expect(publicationReviewRows())->toBe($baseline);
});

test('direct publication enforces confirmed reviews and native integer pricing inside the owner', function (string $failure): void {
    $this->freezeTime();
    $admin = publicationReviewActor();
    $owner = app(RegistrationFeeService::class);
    $terms = ['kind' => 'registration', 'model' => 'fixed', 'name' => 'Native owner terms', 'amount_kobo' => 10001,
        'customer_description' => 'An agreed onboarding fee.', 'publication_reason' => 'A reviewed owner command.'];
    $review = $owner->previewPublication($admin, $terms);
    $terms['confirmed'] = $failure !== 'confirmation';
    $terms['preview_fingerprint'] = $failure === 'review' ? str_repeat('0', 64) : $review['preview_fingerprint'];
    if ($failure === 'money') {
        $terms['amount_kobo'] = 10001.5;
    } elseif ($failure === 'rate') {
        $terms['basis_points'] = 0.5;
    } elseif ($failure === 'currency') {
        $terms['currency'] = 'USD';
    } elseif ($failure === 'reason') {
        $terms['publication_reason'] = ' ';
    }
    $request = Request::create('/admin/fees/registration', 'POST');
    $request->setLaravelSession(app('session')->driver());
    $request->session()->flush();
    $request->session()->put(publicationReviewSession());
    $baseline = publicationReviewRows();

    expect(fn () => $owner->publishRule($admin, $terms, $request))
        ->toThrow($failure === 'review' ? ConflictHttpException::class : ValidationException::class);

    expect(publicationReviewRows())->toBe($baseline);
})->with(['confirmation', 'review', 'money', 'rate', 'currency', 'reason']);

test('retiring the current catalogue invalidates a pending publication review without new owner writes', function (): void {
    $this->freezeTime();
    $admin = publicationReviewActor();
    $payload = publicationReviewPayload();
    $owner = app(RegistrationFeeService::class);
    $firstReview = $this->actingAs($admin)->postJson(route('admin.fees.registration.preview'), $payload)->assertOk()->json('preview_fingerprint');
    $this->withSession(publicationReviewSession())->postJson(route('admin.fees.registration.store'), [
        ...$payload, 'confirmed' => true, 'preview_fingerprint' => $firstReview,
    ])->assertRedirect();
    $rule = FeeRule::query()->sole();
    $this->travel(1)->seconds();
    $pending = $this->postJson(route('admin.fees.registration.preview'), $payload)->assertOk()->json('preview_fingerprint');
    $reason = 'End this option for new agreements.';
    $retirement = $owner->previewRetirement($admin, $rule->id, $reason);
    $this->withSession(publicationReviewSession())->postJson(route('admin.fees.registration.retire', $rule), [
        'reason' => $reason, 'confirmed' => true, 'preview_fingerprint' => $retirement['preview_fingerprint'],
    ])->assertRedirect();
    $baseline = publicationReviewRows();
    $auditCount = AuditEvent::query()->where('event_type', 'fee_rule.published')->count();

    $this->postJson(route('admin.fees.registration.store'), [
        ...$payload, 'confirmed' => true, 'preview_fingerprint' => $pending,
    ])->assertConflict();

    expect(publicationReviewRows())->toBe($baseline);
    expect(AuditEvent::query()->where('event_type', 'fee_rule.published')->count())->toBe($auditCount);
});
