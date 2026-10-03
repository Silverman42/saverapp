<?php

use App\Enums\AdminPermission;
use App\Models\AuditEvent;
use App\Models\FeeRule;
use App\Models\User;

/** @return array<string, mixed> */
function feePublicationPayload(): array
{
    return [
        'kind' => 'plan',
        'rule_key' => 'withdrawal_percentage',
        'name' => 'Withdrawal percentage',
        'model' => 'percentage',
        'timing' => 'withdrawal',
        'basis' => 'gross_withdrawal_debit',
        'settlement_source' => 'withdrawal_payout',
        'amount_ngn' => '0',
        'basis_points' => 200,
        'customer_description' => 'Two percent of the withdrawal debit.',
        'publication_reason' => 'Publish the approved fee terms.',
    ];
}

/** @return array<string, int> */
function feePublicationFreshSession(): array
{
    return [
        'auth.password_confirmed_at' => now()->timestamp,
        'auth.mfa_confirmed_at' => now()->timestamp,
        'auth.fresh_until' => now()->addMinutes(10)->timestamp,
    ];
}

test('fee publication rejects invalid inputs without publishing or creating financial records', function (array $changes, string $errorField): void {
    $this->freezeTime();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage->value);

    $this->actingAs($admin)->withSession(feePublicationFreshSession())
        ->postJson(route('admin.fees.registration.store'), array_replace(feePublicationPayload(), ['confirmed' => true, 'preview_fingerprint' => str_repeat('0', 64)], $changes))
        ->assertUnprocessable()->assertJsonValidationErrors($errorField);

    $this->assertDatabaseCount('fee_rules', 0);
    $this->assertDatabaseCount('fee_obligations', 0);
    $this->assertDatabaseCount('fee_snapshots', 0);
    $this->assertDatabaseCount('ledger_entries', 0);
    expect(AuditEvent::query()->where('event_type', 'fee_rule.published')->exists())->toBeFalse();
})->with([
    'fractional kobo' => [['amount_ngn' => '1.001'], 'amount_ngn'],
    'negative money' => [['amount_ngn' => '-1'], 'amount_ngn'],
    'oversized money' => [['amount_ngn' => '10000000000.00'], 'amount_ngn'],
    'overflow money' => [['amount_ngn' => '999999999999999999999999999'], 'amount_ngn'],
    'fractional basis points' => [['basis_points' => 0.5], 'basis_points'],
    'negative basis points' => [['basis_points' => -1], 'basis_points'],
    'excessive basis points' => [['basis_points' => 10001], 'basis_points'],
    'unknown currency' => [['currency' => 'USD'], 'currency'],
    'invalid effective time' => [['effective_at' => 'not-a-date'], 'effective_at'],
    'blank name' => [['name' => ' '], 'name'],
    'oversized name' => [['name' => str_repeat('a', 101)], 'name'],
    'oversized description' => [['customer_description' => str_repeat('a', 501)], 'customer_description'],
    'unsupported stacked fee model' => [['model' => 'stacked'], 'model'],
    'unsupported penalty fee model' => [['model' => 'penalty'], 'model'],
    'unsupported percentage timing' => [['timing' => 'first_contribution', 'basis' => 'net_cycle_contributions', 'settlement_source' => 'savings_application'], 'basis_points'],
    'unsupported one day basis' => [['model' => 'one_day', 'timing' => 'first_contribution', 'basis_points' => null, 'settlement_source' => 'savings_application'], 'basis'],
    'wrong settlement source' => [['settlement_source' => 'external_receipt'], 'settlement_source'],
    'unsupported registration model' => [['kind' => 'registration', 'rule_key' => 'registration', 'timing' => 'registration', 'basis' => 'none', 'settlement_source' => 'external_receipt'], 'model'],
]);

test('fee publication accepts supported integer rate and money boundaries in NGN', function (array $changes, int $amountKobo, ?int $basisPoints): void {
    $this->freezeTime();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage->value);

    $payload = array_replace(feePublicationPayload(), $changes);
    $review = $this->actingAs($admin)->postJson(route('admin.fees.registration.preview'), $payload)->assertOk()->json('preview_fingerprint');
    $this->withSession(feePublicationFreshSession())
        ->postJson(route('admin.fees.registration.store'), [...$payload, 'confirmed' => true, 'preview_fingerprint' => $review])
        ->assertRedirect(route('admin.fees.registration.index'));

    $this->assertDatabaseCount('fee_rules', 1);
    $rule = FeeRule::query()->sole();
    expect($rule->currency)->toBe('NGN');
    expect($rule->amount_kobo)->toBe($amountKobo);
    expect($rule->basis_points)->toBe($basisPoints);
    expect($rule->published_by_user_id)->toBe($admin->id);
    $this->assertDatabaseCount('fee_obligations', 0);
    $this->assertDatabaseCount('fee_snapshots', 0);
    $this->assertDatabaseCount('ledger_entries', 0);
    expect(AuditEvent::query()->where('event_type', 'fee_rule.published')->count())->toBe(1);
})->with([
    'zero rate' => [['basis_points' => 0, 'currency' => 'NGN'], 0, 0],
    'full rate' => [['basis_points' => 10000], 0, 10000],
    'minimum fixed money' => [['model' => 'fixed', 'basis' => 'none', 'basis_points' => null, 'amount_ngn' => '0.01'], 1, null],
    'maximum fixed money' => [['model' => 'fixed', 'basis' => 'none', 'basis_points' => null, 'amount_ngn' => '9999999999.99'], 999999999999, null],
    'explicit no fee' => [['model' => 'no_fee', 'basis' => 'none', 'basis_points' => null], 0, null],
]);
