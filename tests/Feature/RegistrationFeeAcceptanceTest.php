<?php

use App\Enums\AdminPermission;
use App\Enums\LedgerAccountCode;
use App\Jobs\DeliverCollectionNotificationIntent;
use App\Jobs\DeliverCustomerHandoverNotice;
use App\Jobs\DeliverCustomerInvitationJob;
use App\Jobs\DeliverCustomerStatusNotificationIntent;
use App\Jobs\DeliverFeeApplicationNotificationIntent;
use App\Jobs\DeliverPlanNotificationIntent;
use App\Jobs\DeliverWithdrawalNotificationIntent;
use App\Jobs\MaterializeNotificationIntent;
use App\Models\AgentProfile;
use App\Models\BusinessProfile;
use App\Models\CollectionBatch;
use App\Models\CollectionException;
use App\Models\CollectionReceipt;
use App\Models\CustomerProfile;
use App\Models\CustomerRecovery;
use App\Models\FeeObligation;
use App\Models\FeeRule;
use App\Models\FeeSnapshot;
use App\Models\FinancialPeriod;
use App\Models\Invitation;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Services\CollectionReadService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\StatementPreviewService;
use App\Services\ThriftPlanService;
use App\Services\WithdrawalBalanceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array<string, int> */
function registrationAcceptanceFresh(): array
{
    return ['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp];
}

/** @return array{User, User, FeeRule} */
function registrationAcceptanceFixture(object $test, bool $zero = false, string $amountNgn = '500.00'): array
{
    $business = BusinessProfile::current();
    $business->invitation_sender_email = 'invitations@saverapp.ng';
    $business->invitation_sender_name = 'SaverApp Security';
    $business->is_invitation_sender_verified = true;
    $business->save();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $rule = registrationAcceptancePublish($test, $admin, $zero, $amountNgn);
    $agent = User::factory()->agent()->withTwoFactor()->create();
    AgentProfile::factory()->active()->create(['user_id' => $agent->id]);

    return [$admin, $agent, $rule];
}

function registrationAcceptancePublish(object $test, User $admin, bool $zero = false, string $amountNgn = '500.00'): FeeRule
{
    $terms = ['kind' => 'registration', 'name' => 'Original registration terms', 'model' => $zero ? 'no_fee' : 'fixed',
        'amount_ngn' => $zero ? '0' : $amountNgn, 'customer_description' => 'Agreed account registration fee.',
        'publication_reason' => 'Approved prospective registration terms.'];
    $review = $test->actingAs($admin)->postJson(route('admin.fees.registration.preview'), $terms)->assertOk()->json('preview_fingerprint');
    $test->withSession(registrationAcceptanceFresh())->postJson(route('admin.fees.registration.store'), [
        ...$terms, 'confirmed' => true, 'preview_fingerprint' => $review,
    ])->assertRedirect();

    return FeeRule::query()->latest('version')->firstOrFail();
}

/** @return array<string, mixed> */
function registrationAcceptancePayload(FeeRule $rule): array
{
    return ['attempt_reference' => (string) Str::uuid(), 'fee_rule_version' => $rule->version,
        'name' => 'Registration acceptance Customer', 'email' => 'registration.acceptance@saverapp.test', 'phone' => '+2348012345678'];
}

/** @return array<string, array<int, array<string, mixed>>> */
function registrationAcceptanceRows(): array
{
    $rows = [];
    foreach (['users', 'customer_profiles', 'customer_assignments', 'customer_status_histories', 'creation_attempts',
        'fee_snapshots', 'fee_obligations', 'fee_obligation_entries', 'invitations', 'ledger_posting_groups', 'ledger_entries',
        'collection_receipts', 'collection_allocations', 'withdrawal_reservations', 'thrift_plans'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    }
    $rows['public_id_sequences'] = DB::table('public_id_sequences')->orderBy('entity_type')->get()->map(fn (object $row): array => (array) $row)->all();

    return $rows;
}

test('FEE-AC-007: reviewed positive or zero registration preserves one agreed snapshot and exact assessment through replay', function (bool $zero): void {
    $this->freezeTime();
    Queue::fake([DeliverCustomerInvitationJob::class, MaterializeNotificationIntent::class]);
    [, $agent, $rule] = registrationAcceptanceFixture($this, $zero);
    $payload = registrationAcceptancePayload($rule);
    $this->actingAs($agent)->postJson(route('customers.store'), $payload)->assertRedirect();
    $customer = CustomerProfile::query()->sole();
    $snapshot = FeeSnapshot::query()->sole();
    expect($snapshot->fee_rule_id)->toBe($rule->id)->and($snapshot->fee_rule_version)->toBe(1)
        ->and($snapshot->currency)->toBe('NGN')->and($snapshot->amount_kobo)->toBe($zero ? 0 : 50000)
        ->and($snapshot->customer_description)->toBe('Agreed account registration fee.')
        ->and($snapshot->source_type)->toBe('registration')->and($snapshot->source_id)->toBe((string) $customer->id);
    if ($zero) {
        $this->assertDatabaseCount('fee_obligations', 0);
        $this->assertDatabaseCount('fee_obligation_entries', 0);
    } else {
        $obligation = FeeObligation::query()->sole();
        expect($obligation->fee_snapshot_id)->toBe($snapshot->id)->and($obligation->outstandingAmountKobo())->toBe(50000);
        $this->assertDatabaseCount('fee_obligation_entries', 1);
        $this->assertDatabaseHas('fee_obligation_entries', ['fee_obligation_id' => $obligation->id,
            'entry_type' => 'assessment', 'amount_kobo' => 50000, 'source_type' => 'registration', 'source_id' => (string) $customer->id]);
    }
    $this->assertDatabaseCount('ledger_entries', 0);
    $this->assertDatabaseCount('ledger_posting_groups', 0);
    $baseline = registrationAcceptanceRows();
    $this->postJson(route('customers.store'), $payload)->assertRedirect();
    expect(registrationAcceptanceRows())->toBe($baseline);
    Queue::assertPushed(DeliverCustomerInvitationJob::class, 1);
})->with(['positive' => false, 'zero' => true]);

test('FEE-AC-008: coordinated registration fault rolls back every usable owner before same-attempt retry', function (string $fault): void {
    $this->freezeTime();
    [, $agent, $rule] = registrationAcceptanceFixture($this);
    Queue::fake([DeliverCustomerInvitationJob::class]);
    $payload = registrationAcceptancePayload($rule);
    $baseline = registrationAcceptanceRows();
    $definitions = [
        'snapshot' => 'BEFORE INSERT ON fee_snapshots',
        'obligation' => 'BEFORE INSERT ON fee_obligations',
        'entry' => 'BEFORE INSERT ON fee_obligation_entries',
        'invitation' => 'BEFORE INSERT ON invitations',
        'attempt' => "BEFORE UPDATE ON creation_attempts WHEN NEW.status = 'committed'",
        'audit' => "BEFORE INSERT ON canonical_audit_events WHEN NEW.event_type = 'customer.registered'",
    ];
    DB::statement('CREATE TRIGGER fail_registration_acceptance '.$definitions[$fault]." BEGIN SELECT RAISE(ABORT, 'injected registration persistence outage'); END");
    try {
        $this->actingAs($agent)->postJson(route('customers.store'), $payload)->assertInternalServerError();
    } finally {
        DB::statement('DROP TRIGGER fail_registration_acceptance');
    }
    expect(registrationAcceptanceRows())->toBe($baseline);
    $this->assertDatabaseMissing('audit_events', ['event_type' => 'customer.registered']);
    $this->assertDatabaseMissing('audit_events', ['event_type' => 'fee.obligation.assessed']);
    Queue::assertNotPushed(DeliverCustomerInvitationJob::class);
    Queue::fake([DeliverCustomerInvitationJob::class]);
    $this->postJson(route('customers.store'), $payload)->assertRedirect();
    $this->assertDatabaseCount('customer_profiles', 1);
    $this->assertDatabaseCount('fee_snapshots', 1);
    $this->assertDatabaseCount('fee_obligations', 1);
    $this->assertDatabaseCount('fee_obligation_entries', 1);
    $this->assertDatabaseCount('invitations', 1);
    $this->assertDatabaseCount('creation_attempts', 1);
    expect(FeeObligation::query()->sole()->outstandingAmountKobo())->toBe(50000);
    Queue::assertPushed(DeliverCustomerInvitationJob::class, 1);
})->with(['snapshot', 'obligation', 'entry', 'invitation', 'attempt', 'audit']);

test('FEE-AC-009: actual publication after registration preview requires reconfirmation and never reprices an issued snapshot', function (): void {
    $this->freezeTime();
    Queue::fake([DeliverCustomerInvitationJob::class, MaterializeNotificationIntent::class]);
    [$admin, $agent, $rule] = registrationAcceptanceFixture($this);
    $payload = registrationAcceptancePayload($rule);
    $this->actingAs($agent)->getJson(route('customers.fee-preview', ['attempt_reference' => $payload['attempt_reference']]))
        ->assertOk()->assertJsonPath('version', 1)->assertJsonPath('amount_kobo', 50000);
    $this->travel(1)->seconds();
    $successor = registrationAcceptancePublish($this, $admin, true);
    $baseline = registrationAcceptanceRows();
    $this->actingAs($agent)->postJson(route('customers.store'), $payload)->assertConflict();
    expect(registrationAcceptanceRows())->toBe($baseline);
    $review = $this->getJson(route('customers.fee-preview', ['attempt_reference' => $payload['attempt_reference']]))
        ->assertOk()->assertJsonPath('version', 2)->assertJsonPath('amount_kobo', 0)->json();
    $payload['fee_rule_version'] = $review['version'];
    $this->postJson(route('customers.store'), $payload)->assertRedirect();
    $snapshot = FeeSnapshot::query()->sole();
    $original = $snapshot->getAttributes();
    expect($snapshot->fee_rule_id)->toBe($successor->id)->and($snapshot->amount_kobo)->toBe(0);
    $this->travel(1)->seconds();
    registrationAcceptancePublish($this, $admin);
    expect($snapshot->fresh()->getAttributes())->toBe($original);
    $this->assertDatabaseCount('fee_obligations', 0);
    $this->assertDatabaseCount('ledger_entries', 0);
    Queue::assertPushed(DeliverCustomerInvitationJob::class, 1);
});

test('FEE-AC-010: unpaid registration preserves debt through activation and permits one Agent fee-only receipt', function (bool $activate): void {
    $this->freezeTime();
    Queue::fake([DeliverCustomerInvitationJob::class, DeliverCollectionNotificationIntent::class, MaterializeNotificationIntent::class]);
    [, $agent, $rule] = registrationAcceptanceFixture($this);
    $this->actingAs($agent)->postJson(route('customers.store'), registrationAcceptancePayload($rule))->assertRedirect();
    $customer = CustomerProfile::query()->sole();
    $snapshot = FeeSnapshot::query()->sole();
    $obligation = FeeObligation::query()->sole();
    $financial = registrationAcceptanceRows();
    $pricing = array_diff_key($snapshot->getAttributes(), ['acknowledged_at' => true, 'updated_at' => true]);
    if ($activate) {
        $invitation = Invitation::query()->sole();
        $token = Str::random(64);
        $invitation->token_hash = hash('sha256', $token);
        $invitation->save();
        Auth::logout();
        $this->get(route('invitations.customer.show', $token))->assertOk();
        $this->post(route('invitations.customer.activate', $token), ['fee_acknowledged' => true,
            'password' => 'ValidRegistrationPassword2026!', 'password_confirmation' => 'ValidRegistrationPassword2026!'])
            ->assertRedirect(route('customer.dashboard'));
        $this->assertAuthenticatedAs($customer->user->fresh());
    } else {
        expect($customer->user->account_state->value)->toBe('invited');
    }
    expect($obligation->fresh()->outstandingAmountKobo())->toBe(50000);
    expect(array_diff_key($snapshot->fresh()->getAttributes(), ['acknowledged_at' => true, 'updated_at' => true]))->toBe($pricing);
    if ($activate) {
        expect($snapshot->fresh()->acknowledged_at)->not->toBeNull();
    } else {
        expect($snapshot->fresh()->acknowledged_at)->toBeNull();
    }
    foreach (['fee_obligations', 'fee_obligation_entries', 'ledger_posting_groups', 'ledger_entries', 'collection_receipts',
        'collection_allocations', 'withdrawal_reservations', 'thrift_plans'] as $table) {
        expect(DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all())->toBe($financial[$table]);
    }
    config()->set('collections.enabled', true);
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    $assignment = $customer->currentAssignment;
    $payload = ['attempt_reference' => (string) Str::uuid(), 'customer_version' => $customer->version,
        'assignment_version' => $assignment->version, 'business_version' => BusinessProfile::current()->version,
        'plan_id' => null, 'plan_version' => null, 'received_date' => now('Africa/Lagos')->toDateString(), 'savings_ngn' => '0.00',
        'fees' => [['obligation_id' => $obligation->id, 'amount_ngn' => '500.00']], 'allocations' => [],
        'late_reason' => '', 'notes' => '', 'confirmed' => true];
    $preview = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertOk()->json();
    $payload['preview_fingerprint'] = $preview['preview_fingerprint'];
    $this->postJson(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();
    $this->postJson(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect();
    expect($obligation->fresh()->outstandingAmountKobo())->toBe(0);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(0);
    $this->assertDatabaseCount('collection_receipts', 1);
    $this->assertDatabaseHas('collection_receipts', ['fee_amount_kobo' => 50000, 'tender_amount_kobo' => 50000, 'savings_amount_kobo' => 0]);
    $this->assertDatabaseCount('collection_allocations', 0);
    $this->assertDatabaseCount('thrift_plans', 0);
    $this->assertDatabaseCount('fee_obligation_entries', 2);
    Queue::assertPushed(DeliverCustomerInvitationJob::class, 1);
    Queue::assertPushed(DeliverCollectionNotificationIntent::class);
})->with(['activated receipt' => true, 'invited receipt' => false]);

/** @return array<string, array<int, array<string, mixed>>> */
function registrationLifecycleFinancialRows(): array
{
    $rows = [];
    foreach (['fee_rules', 'fee_snapshots', 'fee_obligations', 'fee_obligation_entries', 'ledger_posting_groups', 'ledger_entries',
        'collection_receipts', 'collection_fee_components', 'collection_allocations', 'collection_batches', 'cash_remittances',
        'collection_batch_reviews', 'withdrawal_reservations', 'withdrawal_requests', 'thrift_plans', 'plan_terms_revisions', 'contribution_slots'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    }

    return $rows;
}

test('FEE-AC-011: actual invitation resend correction and cancellation preserve exact fee and financial history', function (): void {
    $this->freezeTime();
    Queue::fake([DeliverCustomerInvitationJob::class, MaterializeNotificationIntent::class]);
    [, $agent, $rule] = registrationAcceptanceFixture($this);
    $this->actingAs($agent)->postJson(route('customers.store'), registrationAcceptancePayload($rule))->assertRedirect();
    $customer = CustomerProfile::query()->sole();
    $baseline = registrationLifecycleFinancialRows();
    $this->travel(2)->minutes();

    $this->postJson(route('customers.invitations.resend', $customer->customer_id))->assertRedirect()->assertSessionHasNoErrors();
    expect(registrationLifecycleFinancialRows())->toBe($baseline);
    expect(Invitation::query()->latest('generation')->firstOrFail()->generation)->toBe(2);
    $this->postJson(route('customers.invitations.correct-email', $customer->customer_id), [
        'email' => 'corrected.registration@saverapp.test', 'reason' => 'Customer verified the corrected email address.',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect(registrationLifecycleFinancialRows())->toBe($baseline);
    expect($customer->user->fresh()->email)->toBe('corrected.registration@saverapp.test');
    expect(Invitation::query()->latest('generation')->firstOrFail()->generation)->toBe(3);
    $this->postJson(route('customers.invitations.cancel', $customer->customer_id), [
        'reason' => 'Customer requested cancellation of this invitation.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(registrationLifecycleFinancialRows())->toBe($baseline);
    expect(Invitation::query()->latest('generation')->firstOrFail()->status->value)->toBe('cancelled');
    expect(FeeObligation::query()->sole()->outstandingAmountKobo())->toBe(50000);
    Queue::assertPushed(DeliverCustomerInvitationJob::class, 3);
});

test('FEE-AC-011: settled actual registration survives reassignment recovery archive restoration and replay without repricing or earnings', function (): void {
    $this->freezeTime();
    Queue::fake([DeliverCustomerInvitationJob::class, DeliverCollectionNotificationIntent::class, MaterializeNotificationIntent::class,
        DeliverCustomerHandoverNotice::class, DeliverCustomerStatusNotificationIntent::class]);
    [$admin, $agent, $rule] = registrationAcceptanceFixture($this);
    $admin->givePermissionTo([AdminPermission::CustomersManage, AdminPermission::CustomersReassign,
        AdminPermission::SecurityOperationsManage, AdminPermission::ReconciliationManage]);
    LedgerAccount::query()->where('code', LedgerAccountCode::RefundPayable->value)->update(['mapping_status' => 'mapped']);
    $this->actingAs($agent)->postJson(route('customers.store'), registrationAcceptancePayload($rule))->assertRedirect();
    $customer = CustomerProfile::query()->sole();
    $invitation = Invitation::query()->sole();
    $token = Str::random(64);
    $invitation->update(['token_hash' => hash('sha256', $token)]);
    Auth::logout();
    $this->post(route('invitations.customer.activate', $token), ['fee_acknowledged' => true,
        'password' => 'ValidRegistrationPassword2026!', 'password_confirmation' => 'ValidRegistrationPassword2026!'])->assertRedirect(route('customer.dashboard'));
    config()->set('collections.enabled', true);
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    $obligation = FeeObligation::query()->sole();
    $date = now('Africa/Lagos')->toDateString();
    $receipt = ['attempt_reference' => (string) Str::uuid(), 'customer_version' => $customer->fresh()->version,
        'assignment_version' => $customer->currentAssignment->version, 'business_version' => BusinessProfile::current()->version,
        'plan_id' => null, 'plan_version' => null, 'received_date' => $date, 'savings_ngn' => '0.00',
        'fees' => [['obligation_id' => $obligation->id, 'amount_ngn' => '500.00']], 'allocations' => [],
        'late_reason' => '', 'notes' => '', 'confirmed' => true];
    $receipt['preview_fingerprint'] = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $receipt)->assertOk()->json('preview_fingerprint');
    $this->postJson(route('customers.collections.store', $customer->customer_id), $receipt)->assertRedirect();
    $this->travel(1)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $batch = CollectionBatch::query()->sole();
    $this->actingAs($admin)->withSession(['auth.login_at' => now()->timestamp, 'auth.last_active_at' => now()->timestamp])
        ->post(route('collection-batches.remittances.store', $batch), [
            'batch_version' => $batch->version, 'handoff_reference' => 'REGISTRATION-LIFECYCLE-PAID', 'amount_ngn' => '500.00',
            'handoff_date' => $date, 'receiving_location' => 'Business cash office',
            'source_attestation' => 'Counted the original Agent fee tender into business custody.', 'confirmed' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('collection-batches.review', $batch), ['batch_version' => $batch->fresh()->version,
        'reason' => 'Original paid fee cash and recorded handoff reconcile.', 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    expect($batch->fresh()->status)->toBe('reconciled');
    expect($obligation->fresh()->outstandingAmountKobo())->toBe(0);
    $baseline = registrationLifecycleFinancialRows();
    $replacement = User::factory()->agent()->withTwoFactor()->create();
    $replacementProfile = AgentProfile::factory()->active()->create(['user_id' => $replacement->id]);
    $quote = $this->postJson(route('customers.reassignment.preview', $customer->customer_id), ['target_agent_id' => $replacementProfile->id])->assertOk()->json();
    $handover = ['attempt_reference' => (string) Str::uuid(), 'version' => $customer->fresh()->version,
        'assignment_version' => $customer->currentAssignment->version, 'target_agent_id' => $replacementProfile->id,
        'preview_token' => $quote['preview_token'], 'confirmed' => true, 'reason' => 'Approved service handover.',
        'customer_explanation' => 'Your new assigned Agent will assist you.'];
    $handoverResult = $this->withSession(registrationAcceptanceFresh())->postJson(route('customers.reassignment.store', $customer->customer_id), $handover)->assertOk()->json();
    $this->postJson(route('customers.reassignment.store', $customer->customer_id), $handover)->assertOk()->assertExactJson($handoverResult);
    expect(registrationLifecycleFinancialRows())->toBe($baseline);
    $customer->refresh()->unsetRelation('currentAssignment');
    $verification = ['attempt_reference' => (string) Str::uuid(), 'confirmed' => true, 'version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'email' => 'recovered.registration@saverapp.test',
        'in_person' => true, 'record_compared' => true, 'verified_at' => now()->toIso8601String(),
        'procedure_reference' => 'REGISTRATION-IDENTITY-CHECK', 'notes' => 'Verified Customer identity in person.'];
    $this->actingAs($replacement)->postJson(route('customers.recovery.store', $customer->customer_id), $verification)->assertOk()->assertJsonPath('state', 'awaiting_approval');
    $recovery = CustomerRecovery::query()->sole();
    $decision = ['attempt_reference' => (string) Str::uuid(), 'confirmed' => true, 'recovery_version' => $recovery->version,
        'reason' => 'Reviewed the independent identity verification.'];
    $this->actingAs($admin)->withSession(registrationAcceptanceFresh())->postJson(route('customers.recovery.update', [$customer->customer_id, $recovery->reference, 'approve']), $decision)->assertOk()->assertJsonPath('state', 'awaiting_activation');
    expect(registrationLifecycleFinancialRows())->toBe($baseline);
    $notice = DB::table('customer_handover_notices as n')->join('customer_handover_events as e', 'e.id', '=', 'n.customer_handover_event_id')
        ->where('e.customer_recovery_id', $recovery->id)->where('n.purpose', 'recovery_activation')->orderByDesc('n.id')->first();
    $challenge = json_decode(Crypt::decryptString($notice->payload), true, flags: JSON_THROW_ON_ERROR);
    Auth::logout();
    $activation = ['token' => $challenge['token'], 'password' => 'RecoveredRegistrationPassword2026!', 'password_confirmation' => 'RecoveredRegistrationPassword2026!'];
    $this->post(route('customer-recovery.activate', $recovery->reference), $activation)->assertRedirect(route('login'));
    expect($recovery->fresh()->state)->toBe('completed');
    expect($customer->user->fresh()->email)->toBe('recovered.registration@saverapp.test');
    expect(registrationLifecycleFinancialRows())->toBe($baseline);
    $this->postJson(route('customer-recovery.activate', $recovery->reference), $activation)->assertUnprocessable();
    expect(registrationLifecycleFinancialRows())->toBe($baseline);
    foreach (['archive' => 'archived', 'restore' => 'inactive'] as $action => $target) {
        if ($action === 'archive') {
            $this->actingAs($admin)->postJson(route('customers.lifecycle.preview', $customer->customer_id))->assertOk()->assertJsonPath('eligible', true);
        }
        $customer->refresh()->unsetRelation('currentAssignment');
        $payload = ['attempt_reference' => (string) Str::uuid(), 'version' => $customer->version,
            'assignment_version' => $customer->currentAssignment->version, 'confirmed' => true,
            'reason' => 'Reviewed settled Customer lifecycle transition.', 'customer_explanation' => 'Your participation status was updated.'];
        $result = $this->actingAs($admin)->withSession(registrationAcceptanceFresh())->postJson(route('customers.lifecycle.'.$action, $customer->customer_id), $payload)->assertOk()->assertJsonPath('status', $target)->json();
        $this->postJson(route('customers.lifecycle.'.$action, $customer->customer_id), $payload)->assertOk()->assertExactJson($result);
        expect(registrationLifecycleFinancialRows())->toBe($baseline);
    }
    expect(FeeSnapshot::query()->count())->toBe(1);
    expect(FeeObligation::query()->sole()->outstandingAmountKobo())->toBe(0);
    expect(app(CollectionReadService::class)->position($customer->fresh())['liability_kobo'])->toBe(0);
    Queue::assertPushed(DeliverCustomerInvitationJob::class, 1);
    Queue::assertPushed(DeliverCollectionNotificationIntent::class);
    Queue::assertPushed(DeliverCustomerHandoverNotice::class);
    Queue::assertPushed(DeliverCustomerStatusNotificationIntent::class);
});

test('FEE-AC-012/027: actual partial receipt and remainder disposition derive correct fee state without over-waiver or extra earnings', function (bool $waive): void {
    $this->freezeTime();
    Queue::fake([DeliverCustomerInvitationJob::class, DeliverCollectionNotificationIntent::class, MaterializeNotificationIntent::class]);
    [$admin, $agent, $rule] = registrationAcceptanceFixture($this, amountNgn: '1000.00');
    $this->actingAs($agent)->postJson(route('customers.store'), registrationAcceptancePayload($rule))->assertRedirect();
    $customer = CustomerProfile::query()->sole();
    $snapshot = FeeSnapshot::query()->sole();
    $obligation = FeeObligation::query()->sole();
    $terms = $snapshot->getAttributes();
    expect($obligation->status->value)->toBe('pending');
    expect($obligation->assessedAmountKobo())->toBe(100000);
    config()->set('collections.enabled', true);
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    $receipt = ['attempt_reference' => (string) Str::uuid(), 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'business_version' => BusinessProfile::current()->version,
        'plan_id' => null, 'plan_version' => null, 'received_date' => now('Africa/Lagos')->toDateString(), 'savings_ngn' => '0.00',
        'fees' => [['obligation_id' => $obligation->id, 'amount_ngn' => '400.00']], 'allocations' => [],
        'late_reason' => '', 'notes' => '', 'confirmed' => true];
    $receipt['preview_fingerprint'] = $this->postJson(route('customers.collections.preview', $customer->customer_id), $receipt)->assertOk()->json('preview_fingerprint');
    $this->postJson(route('customers.collections.store', $customer->customer_id), $receipt)->assertRedirect();
    $this->postJson(route('customers.collections.store', $customer->customer_id), $receipt)->assertRedirect();
    expect($obligation->fresh()->status->value)->toBe('partially_settled');
    expect($obligation->settledAmountKobo())->toBe(40000);
    expect($obligation->outstandingAmountKobo())->toBe(60000);
    $baseline = registrationLifecycleFinancialRows();
    $waiver = ['attempt_reference' => (string) Str::uuid(), 'amount_ngn' => '600.00',
        'reason' => 'Approved relief of only the original unpaid remainder.', 'customer_description' => 'Your remaining registration fee was waived.'];
    foreach (['0.00', '600.01'] as $invalid) {
        $this->actingAs($admin)->withSession(registrationAcceptanceFresh())->postJson(route('admin.fees.obligations.waive', $obligation),
            [...$waiver, 'attempt_reference' => (string) Str::uuid(), 'amount_ngn' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('amount_ngn');
        expect(registrationLifecycleFinancialRows())->toBe($baseline);
    }

    if ($waive) {
        $this->postJson(route('admin.fees.obligations.waive', $obligation), $waiver)->assertRedirect(route('admin.fees.index'));
        $after = registrationLifecycleFinancialRows();
        $beforeWithoutEntries = $baseline;
        unset($after['fee_obligation_entries'], $beforeWithoutEntries['fee_obligation_entries']);
        expect($after)->toBe($beforeWithoutEntries);
        $this->assertDatabaseHas('fee_obligation_entries', ['fee_obligation_id' => $obligation->id, 'entry_type' => 'waiver',
            'amount_kobo' => 60000, 'source_type' => 'admin_waiver', 'source_id' => $waiver['attempt_reference'], 'actor_user_id' => $admin->id]);
        $baseline = registrationLifecycleFinancialRows();
        $this->postJson(route('admin.fees.obligations.waive', $obligation), $waiver)->assertRedirect(route('admin.fees.index'));
        expect(registrationLifecycleFinancialRows())->toBe($baseline);
        $this->postJson(route('admin.fees.obligations.waive', $obligation), [...$waiver, 'amount_ngn' => '599.00'])->assertConflict();
        expect(registrationLifecycleFinancialRows())->toBe($baseline);
    } else {
        $remainder = [...$receipt, 'attempt_reference' => (string) Str::uuid(),
            'fees' => [['obligation_id' => $obligation->id, 'amount_ngn' => '600.00']]];
        $remainder['preview_fingerprint'] = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $remainder)->assertOk()->json('preview_fingerprint');
        $this->postJson(route('customers.collections.store', $customer->customer_id), $remainder)->assertRedirect();
        $this->postJson(route('customers.collections.store', $customer->customer_id), $remainder)->assertRedirect();
        $baseline = registrationLifecycleFinancialRows();
    }
    $this->actingAs($admin)->withSession(registrationAcceptanceFresh())->postJson(route('admin.fees.obligations.waive', $obligation),
        [...$waiver, 'attempt_reference' => (string) Str::uuid(), 'amount_ngn' => '1.00'])->assertUnprocessable()->assertJsonValidationErrors('amount_ngn');
    expect(registrationLifecycleFinancialRows())->toBe($baseline);
    expect($snapshot->fresh()->getAttributes())->toBe($terms);
    expect($rule->fresh()->amount_kobo)->toBe(100000);
    expect($obligation->fresh()->assessedAmountKobo())->toBe(100000);
    expect($obligation->settledAmountKobo())->toBe($waive ? 40000 : 100000);
    expect($obligation->waivedAmountKobo())->toBe($waive ? 60000 : 0);
    expect($obligation->outstandingAmountKobo())->toBe(0);
    expect($obligation->status->value)->toBe($waive ? 'partially_settled_and_waived' : 'settled');
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(0);
    $this->assertDatabaseCount('fee_obligation_entries', 3);
    $this->assertDatabaseCount('collection_receipts', $waive ? 1 : 2);
    $this->assertDatabaseCount('collection_allocations', 0);
    $this->assertDatabaseCount('thrift_plans', 0);
    $this->get(route('admin.fees.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('summary.outstanding_amount_kobo', 0)->where('summary.pending_count', 0)
        ->where('summary.earnings.lifetime_gross_kobo', $waive ? 40000 : 100000)
        ->where('summary.earnings.lifetime_net_kobo', $waive ? 40000 : 100000)
        ->where('summary.earnings.lifetime_refunds_kobo', 0));
    Queue::assertPushed(DeliverCustomerInvitationJob::class, 1);
    $agentIntents = DB::table('collection_notification_intents')->where('recipient_user_id', $agent->id)->where('channel', 'database')->get();
    expect($agentIntents)->toHaveCount($waive ? 1 : 2);
    foreach ($agentIntents as $intent) {
        expect($intent->audience_type)->toBe('current_agent');
        Queue::assertPushed(DeliverCollectionNotificationIntent::class, fn (DeliverCollectionNotificationIntent $job): bool => $job->intentId === $intent->id);
    }
    Queue::assertPushed(DeliverCollectionNotificationIntent::class, $waive ? 3 : 6);
})->with(['waived remainder' => true, 'paid remainder' => false]);

test('FEE-AC-027: full waiver of an actual unpaid registration fee preserves pricing and creates no money or income', function (): void {
    $this->freezeTime();
    Queue::fake([DeliverCustomerInvitationJob::class, MaterializeNotificationIntent::class]);
    [$admin, $agent, $rule] = registrationAcceptanceFixture($this);
    $this->actingAs($agent)->postJson(route('customers.store'), registrationAcceptancePayload($rule))->assertRedirect();
    $obligation = FeeObligation::query()->sole();
    $baseline = registrationLifecycleFinancialRows();
    $payload = ['attempt_reference' => (string) Str::uuid(), 'amount_ngn' => '500.00',
        'reason' => 'Approved full relief of the unpaid registration assessment.', 'customer_description' => 'Your unpaid registration fee was waived.'];

    $this->actingAs($admin)->withSession(registrationAcceptanceFresh())->postJson(route('admin.fees.obligations.waive', $obligation), $payload)
        ->assertRedirect(route('admin.fees.index'));

    $after = registrationLifecycleFinancialRows();
    unset($baseline['fee_obligation_entries'], $after['fee_obligation_entries']);
    expect($after)->toBe($baseline);
    expect($obligation->fresh()->status->value)->toBe('waived');
    expect($obligation->assessedAmountKobo())->toBe(50000);
    expect($obligation->settledAmountKobo())->toBe(0);
    expect($obligation->waivedAmountKobo())->toBe(50000);
    expect($obligation->outstandingAmountKobo())->toBe(0);
    $this->assertDatabaseCount('fee_obligation_entries', 2);
    $this->assertDatabaseCount('collection_receipts', 0);
    $this->assertDatabaseCount('ledger_posting_groups', 0);
    $this->get(route('admin.fees.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('summary.outstanding_amount_kobo', 0)->where('summary.earnings.lifetime_net_kobo', 0));
    Queue::assertPushed(DeliverCustomerInvitationJob::class, 1);
});

test('FEE-AC-018/019: actual split receipt commits savings and fee together and custody never recognizes the fee again', function (string $fault): void {
    $this->freezeTime();
    Queue::fake([DeliverCustomerInvitationJob::class, DeliverCollectionNotificationIntent::class, DeliverPlanNotificationIntent::class, MaterializeNotificationIntent::class]);
    [$admin, $agent, $rule] = registrationAcceptanceFixture($this, amountNgn: '1000.00');
    $this->actingAs($agent)->postJson(route('customers.store'), registrationAcceptancePayload($rule))->assertRedirect();
    $customer = CustomerProfile::query()->sole();
    $token = Str::random(64);
    Invitation::query()->sole()->update(['token_hash' => hash('sha256', $token)]);
    Auth::logout();
    $this->post(route('invitations.customer.activate', $token), ['fee_acknowledged' => true,
        'password' => 'ValidSplitReceiptPassword2026!', 'password_confirmation' => 'ValidSplitReceiptPassword2026!'])->assertRedirect(route('customer.dashboard'));
    $customer->refresh();
    $fee = FeeObligation::query()->sole();
    $feeTerms = $fee->feeSnapshot->getAttributes();
    $terms = ['kind' => 'plan', 'rule_key' => 'split_receipt', 'name' => 'Explicit zero cycle fee', 'model' => 'no_fee',
        'amount_ngn' => '0', 'timing' => 'first_contribution', 'basis' => 'none', 'settlement_source' => 'external_receipt',
        'customer_description' => 'No fee on this cycle.', 'publication_reason' => 'Approved prospective split-receipt cycle terms.'];
    $review = $this->actingAs($admin)->postJson(route('admin.fees.registration.preview'), $terms)->assertOk()->json('preview_fingerprint');
    $this->withSession(registrationAcceptanceFresh())->postJson(route('admin.fees.registration.store'), [...$terms,
        'confirmed' => true, 'preview_fingerprint' => $review])->assertRedirect()->assertSessionHasNoErrors();
    $planRule = FeeRule::query()->where('kind', 'plan')->sole();
    $data = ['name' => 'Actual mixed receipt cycle', 'amount_ngn' => '2000.00', 'start_date' => now('Africa/Lagos')->toDateString(),
        'contribution_days' => 3, 'customer_visible_notes' => '', 'fee_rule_id' => $planRule->id, 'fee_rule_version' => $planRule->version,
        'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
        'business_version' => BusinessProfile::current()->version, 'customer_agreement_attested' => true];
    $plans = app(ThriftPlanService::class);
    $data['preview_fingerprint'] = $plans->preview($agent, $customer, $data)['preview_fingerprint'];
    $plan = $plans->create($agent, $customer, (string) Str::uuid(), $data)['plan'];
    config()->set(['collections.enabled' => true, 'collections.settlement_enabled' => true]);
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    $date = now('Africa/Lagos')->toDateString();
    $payload = ['attempt_reference' => (string) Str::uuid(), 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'business_version' => BusinessProfile::current()->version,
        'plan_id' => $plan->plan_id, 'plan_version' => $plan->version, 'received_date' => $date, 'savings_ngn' => '2000.00',
        'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => '1000.00']], 'allocations' => [], 'late_reason' => '', 'notes' => '', 'confirmed' => true];
    $baseline = array_merge(registrationAcceptanceRows(), registrationLifecycleFinancialRows());
    $payload['preview_fingerprint'] = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertOk()->json('preview_fingerprint');
    expect(array_merge(registrationAcceptanceRows(), registrationLifecycleFinancialRows()))->toBe($baseline);
    $this->assertDatabaseCount('ledger_entries', 0);
    if ($fault !== 'none') {
        $table = $fault === 'savings allocation' ? 'collection_allocations' : 'collection_fee_components';
        DB::statement('CREATE TRIGGER fail_split_acceptance BEFORE INSERT ON '.$table." BEGIN SELECT RAISE(ABORT, 'injected split receipt outage'); END");
        try {
            $this->postJson(route('customers.collections.store', $customer->customer_id), $payload)->assertInternalServerError();
        } finally {
            DB::statement('DROP TRIGGER fail_split_acceptance');
        }
        expect(array_merge(registrationAcceptanceRows(), registrationLifecycleFinancialRows()))->toBe($baseline);
        $this->assertDatabaseCount('collection_notification_intents', 0);
        Queue::assertNotPushed(DeliverCollectionNotificationIntent::class);
    }
    $this->postJson(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect()->assertSessionHasNoErrors();
    $posted = array_merge(registrationAcceptanceRows(), registrationLifecycleFinancialRows());
    $this->postJson(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect()->assertSessionHasNoErrors();
    expect(array_merge(registrationAcceptanceRows(), registrationLifecycleFinancialRows()))->toBe($posted);
    $receipt = CollectionReceipt::query()->sole();
    expect($receipt->tender_amount_kobo)->toBe(300000);
    expect($receipt->savings_amount_kobo)->toBe(200000);
    expect($receipt->fee_amount_kobo)->toBe(100000);
    expect($fee->fresh()->outstandingAmountKobo())->toBe(0);
    expect($fee->settledAmountKobo())->toBe(100000);
    expect($fee->feeSnapshot->getAttributes())->toBe($feeTerms);
    $this->assertDatabaseHas('collection_fee_components', ['collection_receipt_id' => $receipt->id, 'fee_obligation_id' => $fee->id, 'amount_kobo' => 100000]);
    $this->assertDatabaseHas('collection_allocations', ['collection_receipt_id' => $receipt->id, 'amount_kobo' => 200000]);
    $this->assertDatabaseCount('collection_allocations', 1);
    $this->assertDatabaseCount('fee_obligations', 1);
    $this->assertDatabaseCount('fee_obligation_entries', 2);
    expect(app(CollectionReadService::class)->card($plan->fresh())['paid_slots'])->toBe(1);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(200000);
    foreach ([[LedgerAccountCode::FeeIncome, 'credit', 100000], [LedgerAccountCode::CustomerSavingsLiability, 'credit', 200000], [LedgerAccountCode::AgentReceivable, 'debit', 300000]] as [$code, $side, $amount]) {
        expect((int) DB::table('ledger_entries')->where('ledger_account_id', LedgerAccount::query()->where('code', $code)->sole()->id)
            ->where('side', $side)->sum('amount_kobo'))->toBe($amount);
    }
    Queue::assertPushed(DeliverCustomerInvitationJob::class, 1);
    Queue::assertPushed(DeliverPlanNotificationIntent::class, 3);
    $agentIntent = DB::table('collection_notification_intents')->where('collection_receipt_id', $receipt->id)
        ->where('recipient_user_id', $agent->id)->where('channel', 'database')->sole();
    expect($agentIntent->audience_type)->toBe('current_agent');
    Queue::assertPushed(DeliverCollectionNotificationIntent::class, fn (DeliverCollectionNotificationIntent $job): bool => $job->intentId === $agentIntent->id);
    Queue::assertPushed(DeliverCollectionNotificationIntent::class, 3);
    if ($fault !== 'none') {
        return;
    }
    $recognition = function () use ($fee, $customer): array {
        return ['fee' => DB::table('fee_obligation_entries')->orderBy('id')->get()->all(),
            'income' => DB::table('ledger_entries')->where('ledger_account_id', LedgerAccount::query()->where('code', LedgerAccountCode::FeeIncome)->sole()->id)->orderBy('id')->get()->all(),
            'savings' => DB::table('ledger_entries')->where('ledger_account_id', LedgerAccount::query()->where('code', LedgerAccountCode::CustomerSavingsLiability)->sole()->id)->orderBy('id')->get()->all(),
            'outstanding' => $fee->fresh()->outstandingAmountKobo(), 'liability' => app(CollectionReadService::class)->position($customer)['liability_kobo']];
    };
    $recognized = $recognition();
    $this->travel(1)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $this->travelBack();
    expect($recognition())->toEqual($recognized);
    $batch = CollectionBatch::query()->sole();
    $admin->givePermissionTo(AdminPermission::ReconciliationManage);
    $this->actingAs($admin)->post(route('collection-batches.remittances.store', $batch), [
        'handoff_reference' => 'SPLIT-PARTIAL', 'amount_ngn' => '2500.00', 'handoff_date' => $date,
        'receiving_location' => 'Business till', 'source_attestation' => 'Counted part of original mixed tender.',
        'batch_version' => $batch->version, 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    expect($recognition())->toEqual($recognized);
    $this->post(route('collection-batches.review', $batch), ['batch_version' => $batch->fresh()->version,
        'reason' => 'Original tender has a remaining counted shortage.', 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    expect($batch->fresh()->status)->toBe('exception');
    $exception = CollectionException::query()->sole();
    expect($exception->status)->toBe('open');
    expect($recognition())->toEqual($recognized);
    $this->post(route('collection-batches.remittances.store', $batch), [
        'handoff_reference' => 'SPLIT-REMAINDER', 'amount_ngn' => '500.00', 'handoff_date' => $date,
        'receiving_location' => 'Business till', 'source_attestation' => 'Counted remaining original mixed tender.',
        'batch_version' => $batch->fresh()->version, 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    expect($recognition())->toEqual($recognized);
    $this->post(route('collection-batches.exceptions.resolve', [$batch, $exception]), ['batch_version' => $batch->fresh()->version,
        'reason' => 'All original mixed tender now counted into custody.', 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('collection-batches.review', $batch), ['batch_version' => $batch->fresh()->version,
        'reason' => 'Original tender and both counted handoffs reconcile.', 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    expect($batch->fresh()->status)->toBe('reconciled');
    expect($recognition())->toEqual($recognized);
})->with(['normal recognition' => 'none', 'savings allocation fails' => 'savings allocation', 'fee allocation fails' => 'fee component']);

test('an actual registration savings concession retains its selected paid cycle and rejects damaged source history', function (string $damage): void {
    $this->freezeTime();
    Queue::fake([DeliverCustomerInvitationJob::class, DeliverCollectionNotificationIntent::class, DeliverPlanNotificationIntent::class, MaterializeNotificationIntent::class]);
    config()->set(['fees.savings_applications_enabled' => true, 'fees.refunds_enabled' => true, 'collections.enabled' => true]);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    [$admin, $agent, $rule] = registrationAcceptanceFixture($this);
    $this->actingAs($agent)->postJson(route('customers.store'), registrationAcceptancePayload($rule))->assertRedirect();
    $customer = CustomerProfile::query()->sole();
    $token = Str::random(64);
    Invitation::query()->sole()->update(['token_hash' => hash('sha256', $token)]);
    Auth::logout();
    $this->post(route('invitations.customer.activate', $token), ['fee_acknowledged' => true,
        'password' => 'ValidRegistrationSavingsPassword2026!', 'password_confirmation' => 'ValidRegistrationSavingsPassword2026!'])->assertRedirect(route('customer.dashboard'));
    $customer->refresh();
    $fee = FeeObligation::query()->sole();
    $pricing = $fee->feeSnapshot->getAttributes();
    $terms = ['kind' => 'plan', 'rule_key' => 'registration_savings', 'name' => 'No cycle fee', 'model' => 'no_fee',
        'amount_ngn' => '0', 'timing' => 'first_contribution', 'basis' => 'none', 'settlement_source' => 'external_receipt',
        'customer_description' => 'No fee on this cycle.', 'publication_reason' => 'Approved prospective cycle terms.'];
    $review = $this->actingAs($admin)->postJson(route('admin.fees.registration.preview'), $terms)->assertOk()->json('preview_fingerprint');
    $this->withSession(registrationAcceptanceFresh())->postJson(route('admin.fees.registration.store'), [...$terms,
        'confirmed' => true, 'preview_fingerprint' => $review])->assertRedirect()->assertSessionHasNoErrors();
    $planRule = FeeRule::query()->where('kind', 'plan')->sole();
    $date = now('Africa/Lagos')->toDateString();
    $data = ['name' => 'Selected registration payment cycle', 'amount_ngn' => '2000.00', 'start_date' => $date,
        'contribution_days' => 3, 'customer_visible_notes' => '', 'fee_rule_id' => $planRule->id, 'fee_rule_version' => $planRule->version,
        'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
        'business_version' => BusinessProfile::current()->version, 'customer_agreement_attested' => true];
    $plans = app(ThriftPlanService::class);
    $data['preview_fingerprint'] = $plans->preview($agent, $customer, $data)['preview_fingerprint'];
    $plan = $plans->create($agent, $customer, (string) Str::uuid(), $data)['plan'];
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    $receipt = ['attempt_reference' => (string) Str::uuid(), 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'business_version' => BusinessProfile::current()->version,
        'plan_id' => $plan->plan_id, 'plan_version' => $plan->version, 'received_date' => $date, 'savings_ngn' => '2000.00',
        'fees' => [], 'allocations' => [], 'late_reason' => '', 'notes' => '', 'confirmed' => true];
    $receipt['preview_fingerprint'] = $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $receipt)->assertOk()->json('preview_fingerprint');
    $this->postJson(route('customers.collections.store', $customer->customer_id), $receipt)->assertRedirect()->assertSessionHasNoErrors();
    $application = ['plan_id' => $plan->plan_id, 'reason' => 'Reviewed exceptional registration payment.', 'customer_description' => 'Registration fee paid from this cycle.'];
    $quote = $this->actingAs($admin)->postJson(route('admin.fees.obligations.savings-preview', $fee), $application)->assertOk()->json();
    $application = [...$application, 'attempt_reference' => (string) Str::uuid(), 'confirmed' => true,
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at']];
    $this->withSession(registrationAcceptanceFresh())->postJson(route('admin.fees.obligations.apply-savings', $fee), $application)->assertOk();
    expect(app(WithdrawalBalanceService::class)->position($customer, $plan)['cycle_liability_kobo'])->toBe(150000);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $originalReceipt = CollectionReceipt::query()->sole()->getAttributes();
    $originalAllocations = DB::table('collection_allocations')->get()->all();
    $this->travel(1)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $batch = CollectionBatch::query()->sole();
    $admin->givePermissionTo(AdminPermission::ReconciliationManage);
    $this->actingAs($admin)->withSession([...registrationAcceptanceFresh(),
        'auth.login_at' => now()->timestamp, 'auth.last_active_at' => now()->timestamp])->post(route('collection-batches.remittances.store', $batch), [
            'batch_version' => $batch->version, 'handoff_reference' => 'REGISTRATION-SAVINGS-BACKING', 'amount_ngn' => '2000.00',
            'handoff_date' => $date, 'receiving_location' => 'Business cash office', 'source_attestation' => 'Original savings tender counted into custody.',
            'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    $refund = ['refund_reference' => (string) Str::uuid(), 'kind' => 'savings', 'amount_ngn' => '100.00',
        'reason' => 'Approved partial registration concession.', 'confirmed' => true];

    $this->withSession(registrationAcceptanceFresh())->post(route('admin.fees.refunds.store', $fee), $refund)->assertRedirect()->assertSessionHasNoErrors();

    $group = LedgerPostingGroup::query()->where('source_type', 'fee_refund')->sole();
    expect($group->thrift_plan_id)->toBe($plan->id);
    expect($group->entries()->where('side', 'credit')->sole()->thrift_plan_id)->toBe($plan->id);
    expect(app(WithdrawalBalanceService::class)->position($customer, $plan)['cycle_liability_kobo'])->toBe(160000);
    expect(app(WithdrawalBalanceService::class)->positions([$plan])[$plan->id]['cycle_liability_kobo'])->toBe(160000);
    expect($fee->fresh()->outstandingAmountKobo())->toBe(0);
    expect($fee->settledAmountKobo())->toBe(50000);
    expect($fee->feeSnapshot->getAttributes())->toBe($pricing);
    expect(CollectionReceipt::query()->sole()->getAttributes())->toBe($originalReceipt);
    expect(DB::table('collection_allocations')->get()->all())->toEqual($originalAllocations);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $statement = app(StatementPreviewService::class)->preview($customer->user, $customer, $date, now('Africa/Lagos')->toDateString(), 'Africa/Lagos');
    expect($statement)->toMatchArray(['status' => 'ready', 'opening_kobo' => 0, 'activity_kobo' => 160000, 'closing_kobo' => 160000]);
    $baseline = registrationAcceptanceRows();
    $this->post(route('admin.fees.refunds.store', $fee), $refund)->assertRedirect()->assertSessionHasNoErrors();
    expect(registrationAcceptanceRows())->toBe($baseline);
    $this->assertDatabaseCount('fee_refunds', 1);
    if ($damage === 'none') {
        return;
    }
    if ($damage === 'application journal') {
        $paidGroup = LedgerPostingGroup::query()->where('source_type', 'fee_savings_application')->sole();
        DB::table('ledger_entries')->where('ledger_posting_group_id', $paidGroup->id)->where('side', 'debit')->update(['amount_kobo' => 49999]);
    } else {
        DB::table('ledger_posting_groups')->where('id', $group->id)->update(['thrift_plan_id' => null]);
    }
    $baseline = registrationAcceptanceRows();
    expect(fn () => app(WithdrawalBalanceService::class)->position($customer, $plan))->toThrow(RuntimeException::class);
    expect(app(WithdrawalBalanceService::class)->positions([$plan])[$plan->id])->toBeNull();
    expect(fn () => app(LedgerTransactionProjectionService::class)->rebuild())->toThrow(RuntimeException::class);
    if ($damage === 'application journal') {
        $this->post(route('admin.fees.refunds.store', $fee), [...$refund, 'refund_reference' => (string) Str::uuid()])->assertConflict();
    }
    expect(registrationAcceptanceRows())->toBe($baseline);
})->with(['valid original selected cycle' => 'none', 'damaged paid application' => 'application journal', 'damaged refund cycle' => 'refund cycle']);

/** @return array{User, User, CustomerProfile, ThriftPlan} */
function registrationSavingsAcceptanceCycle(object $test, string $registrationNgn, string $dailyNgn, string $planFeeNgn = '0'): array
{
    config()->set(['fees.savings_applications_enabled' => true, 'collections.enabled' => true]);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    [$admin, $agent, $registration] = registrationAcceptanceFixture($test, $registrationNgn === '0', $registrationNgn);
    $test->actingAs($agent)->postJson(route('customers.store'), registrationAcceptancePayload($registration))->assertRedirect();
    $customer = CustomerProfile::query()->sole();
    $token = Str::random(64);
    Invitation::query()->sole()->update(['token_hash' => hash('sha256', $token)]);
    Auth::logout();
    $test->post(route('invitations.customer.activate', $token), ['fee_acknowledged' => true,
        'password' => 'ValidExplicitFeeAcceptancePassword2026!', 'password_confirmation' => 'ValidExplicitFeeAcceptancePassword2026!'])->assertRedirect(route('customer.dashboard'));
    $customer->refresh();
    $terms = ['kind' => 'plan', 'rule_key' => 'explicit_fee_acceptance', 'name' => 'Agreed acceptance cycle fee',
        'model' => $planFeeNgn === '0' ? 'no_fee' : 'fixed', 'amount_ngn' => $planFeeNgn,
        'timing' => 'first_contribution', 'basis' => 'none', 'settlement_source' => $planFeeNgn === '0' ? 'external_receipt' : 'savings_application',
        'customer_description' => 'Original agreed cycle fee.', 'publication_reason' => 'Reviewed prospective cycle option.'];
    $fingerprint = $test->actingAs($admin)->postJson(route('admin.fees.registration.preview'), $terms)->assertOk()->json('preview_fingerprint');
    $test->withSession(registrationAcceptanceFresh())->postJson(route('admin.fees.registration.store'), [...$terms,
        'confirmed' => true, 'preview_fingerprint' => $fingerprint])->assertRedirect()->assertSessionHasNoErrors();
    $rule = FeeRule::query()->where('kind', 'plan')->sole();
    $data = ['name' => 'Actual savings application acceptance cycle', 'amount_ngn' => $dailyNgn, 'start_date' => now('Africa/Lagos')->toDateString(),
        'contribution_days' => 3, 'customer_visible_notes' => 'Original reviewed cycle.', 'fee_rule_id' => $rule->id, 'fee_rule_version' => $rule->version,
        'customer_version' => $customer->version, 'assignment_version' => $customer->currentAssignment->version,
        'business_version' => BusinessProfile::current()->version, 'customer_agreement_attested' => true];
    $data['preview_fingerprint'] = app(ThriftPlanService::class)->preview($agent, $customer, $data)['preview_fingerprint'];
    $test->actingAs($agent)->postJson(route('customers.plans.store', $customer->customer_id), [...$data,
        'attempt_reference' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
    $plan = ThriftPlan::query()->sole();
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);

    return [$admin, $agent, $customer, $plan];
}

/** @return array<string, mixed> */
function registrationSavingsAcceptanceContribution(object $test, User $agent, CustomerProfile $customer, ThriftPlan $plan, string $amountNgn): array
{
    $customer->refresh();
    $plan->refresh();
    $payload = ['attempt_reference' => (string) Str::uuid(), 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'business_version' => BusinessProfile::current()->version,
        'plan_id' => $plan->plan_id, 'plan_version' => $plan->version, 'received_date' => now('Africa/Lagos')->toDateString(),
        'savings_ngn' => $amountNgn, 'fees' => [], 'allocations' => [], 'late_reason' => '', 'notes' => '', 'confirmed' => true];
    $payload['preview_fingerprint'] = $test->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertOk()->json('preview_fingerprint');
    $test->postJson(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect()->assertSessionHasNoErrors();

    return $payload;
}

/** @return array<string, array<int, array<string, mixed>>> */
function registrationSavingsAcceptanceRows(): array
{
    $rows = [];
    foreach (['fee_rules', 'fee_snapshots', 'fee_obligations', 'fee_obligation_entries', 'fee_savings_applications',
        'ledger_posting_groups', 'ledger_entries', 'collection_receipts', 'collection_fee_components', 'collection_allocations',
        'contribution_slots', 'withdrawal_requests', 'withdrawal_reservations', 'thrift_plans', 'plan_terms_revisions',
        'fee_application_notification_intents', 'notification_events', 'notification_inbox_intents', 'management_mail_dispatches'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    }

    return $rows;
}

test('FEE-AC-020: actual five thousand savings and four thousand reservation cannot settle a two thousand fee or create a hidden hold', function (): void {
    $this->freezeTime();
    Queue::fake([DeliverCustomerInvitationJob::class, DeliverCollectionNotificationIntent::class, DeliverPlanNotificationIntent::class,
        DeliverFeeApplicationNotificationIntent::class, DeliverWithdrawalNotificationIntent::class, MaterializeNotificationIntent::class]);
    [$admin, $agent, $customer, $plan] = registrationSavingsAcceptanceCycle($this, '2000.00', '5000.00');
    registrationSavingsAcceptanceContribution($this, $agent, $customer, $plan, '5000.00');
    $fee = FeeObligation::query()->sole();
    $pricing = $fee->feeSnapshot->getAttributes();
    $application = ['plan_id' => $plan->plan_id, 'reason' => 'Reviewed exceptional registration payment.', 'customer_description' => 'Registration paid from this cycle.'];
    $instruction = $application;
    $quote = $this->actingAs($admin)->postJson(route('admin.fees.obligations.savings-preview', $fee), $application)->assertOk()->json();
    $application = [...$application, 'attempt_reference' => (string) Str::uuid(), 'confirmed' => true,
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at']];
    config()->set(['withdrawals.cash_enabled' => true, 'withdrawals.cash_certified' => true]);
    $withdrawal = ['plan_id' => $plan->plan_id, 'type' => 'partial', 'gross_ngn' => '4000.00', 'method' => 'cash',
        'destination_reference' => 'customer:'.$customer->id, 'reason' => 'Customer requested a reviewed partial withdrawal.', 'internal_notes' => ''];
    $review = $this->actingAs($agent)->postJson(route('customers.withdrawals.preview', $customer->customer_id), $withdrawal)->assertOk()->json();
    $this->post(route('customers.withdrawals.store', $customer->customer_id), [...$withdrawal,
        'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => $review['preview_fingerprint'], 'quote_expires_at' => $review['quote_expires_at'],
        'customer_version' => $review['customer_version'], 'assignment_version' => $review['assignment_version'],
        'plan_version' => $review['plan_version'], 'business_version' => $review['business_version'], 'instruction_attested' => true, 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    $before = registrationSavingsAcceptanceRows();

    $this->actingAs($admin)->postJson(route('admin.fees.obligations.savings-preview', $fee), $instruction)->assertUnprocessable()->assertJsonValidationErrors('plan_id');
    $this->withSession(registrationAcceptanceFresh())->postJson(route('admin.fees.obligations.apply-savings', $fee), $application)->assertUnprocessable()->assertJsonValidationErrors('plan_id');

    expect(registrationSavingsAcceptanceRows())->toBe($before);
    expect($fee->fresh()->outstandingAmountKobo())->toBe(200000);
    expect($fee->feeSnapshot->getAttributes())->toBe($pricing);
    $position = app(WithdrawalBalanceService::class)->position($customer, $plan);
    expect($position['cycle_liability_kobo'])->toBe(500000)->and($position['cycle_reservations_kobo'])->toBe(400000)->and($position['cycle_available_kobo'])->toBe(100000);
    $this->assertDatabaseCount('withdrawal_reservations', 1);
    $this->assertDatabaseCount('fee_savings_applications', 0);
    $this->assertDatabaseCount('fee_obligation_entries', 1);
    $this->assertDatabaseCount('ledger_posting_groups', 1);
    Queue::assertNotPushed(DeliverFeeApplicationNotificationIntent::class);
});

test('FEE-AC-021: actual insufficient first contribution and later gross funding leave the agreed fee unpaid until one explicit reviewed settlement', function (): void {
    $this->freezeTime();
    Queue::fake([DeliverCustomerInvitationJob::class, DeliverCollectionNotificationIntent::class, DeliverPlanNotificationIntent::class,
        DeliverFeeApplicationNotificationIntent::class, MaterializeNotificationIntent::class]);
    [$admin, $agent, $customer, $plan] = registrationSavingsAcceptanceCycle($this, '0', '300.00', '500.00');
    $first = registrationSavingsAcceptanceContribution($this, $agent, $customer, $plan, '300.00');
    $fee = FeeObligation::query()->sole();
    $pricing = $fee->feeSnapshot->getAttributes();
    expect($fee->assessedAmountKobo())->toBe(50000)->and($fee->outstandingAmountKobo())->toBe(50000);
    expect(app(WithdrawalBalanceService::class)->position($customer, $plan)['cycle_liability_kobo'])->toBe(30000);
    $this->assertDatabaseCount('ledger_posting_groups', 1);
    $this->assertDatabaseCount('fee_obligation_entries', 1);
    $before = registrationSavingsAcceptanceRows();
    $this->postJson(route('customers.collections.store', $customer->customer_id), $first)->assertRedirect()->assertSessionHasNoErrors();
    expect(registrationSavingsAcceptanceRows())->toBe($before);

    registrationSavingsAcceptanceContribution($this, $agent, $customer, $plan, '600.00');

    expect(app(WithdrawalBalanceService::class)->position($customer, $plan)['cycle_liability_kobo'])->toBe(90000);
    expect($fee->fresh()->outstandingAmountKobo())->toBe(50000);
    $this->assertDatabaseCount('ledger_posting_groups', 2);
    $this->assertDatabaseCount('fee_obligation_entries', 1);
    expect((int) DB::table('collection_allocations')->sum('amount_kobo'))->toBe(90000);
    $originalReceipts = DB::table('collection_receipts')->orderBy('id')->get()->all();
    $originalAllocations = DB::table('collection_allocations')->orderBy('id')->get()->all();
    $application = ['plan_id' => $plan->plan_id, 'reason' => 'Reviewed settlement after original insufficient contribution.', 'customer_description' => 'Agreed cycle fee paid from savings.'];
    $quote = $this->actingAs($admin)->postJson(route('admin.fees.obligations.savings-preview', $fee), $application)->assertOk()->json();
    expect($quote['amount_kobo'])->toBe(50000)->and($quote['remaining_cycle_savings_kobo'])->toBe(40000)->and($quote['remaining_fee_kobo'])->toBe(0);
    $application = [...$application, 'attempt_reference' => (string) Str::uuid(), 'confirmed' => true,
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at']];
    $this->withSession(registrationAcceptanceFresh())->postJson(route('admin.fees.obligations.apply-savings', $fee), $application)->assertOk()->assertJsonPath('status', 'posted');
    $group = LedgerPostingGroup::query()->where('source_type', 'fee_savings_application')->sole();
    expect($group->thrift_plan_id)->toBe($plan->id)->and($group->actor_user_id)->toBe($admin->id);
    expect($fee->fresh()->outstandingAmountKobo())->toBe(0)->and($fee->settledAmountKobo())->toBe(50000);
    expect(app(WithdrawalBalanceService::class)->position($customer, $plan)['cycle_liability_kobo'])->toBe(40000);
    expect($fee->feeSnapshot->getAttributes())->toBe($pricing);
    expect(DB::table('collection_receipts')->orderBy('id')->get()->all())->toEqual($originalReceipts);
    expect(DB::table('collection_allocations')->orderBy('id')->get()->all())->toEqual($originalAllocations);
    $this->assertDatabaseCount('fee_application_notification_intents', 3);
    $before = registrationSavingsAcceptanceRows();
    $this->postJson(route('admin.fees.obligations.apply-savings', $fee), $application)->assertOk()->assertJsonPath('posting_reference', $group->posting_reference);
    expect(registrationSavingsAcceptanceRows())->toBe($before);
    $this->postJson(route('admin.fees.obligations.apply-savings', $fee), [...$application, 'reason' => 'Changed original instruction.'])->assertConflict();
    expect(registrationSavingsAcceptanceRows())->toBe($before);
    Queue::assertPushed(DeliverFeeApplicationNotificationIntent::class, 1);
});

test('FEE-AC-022: actual outstanding registration and repeated contributions never net debt before distinct confirmed savings settlement', function (): void {
    $this->freezeTime();
    Queue::fake([DeliverCustomerInvitationJob::class, DeliverCollectionNotificationIntent::class, DeliverPlanNotificationIntent::class,
        DeliverFeeApplicationNotificationIntent::class, MaterializeNotificationIntent::class]);
    [$admin, $agent, $customer, $plan] = registrationSavingsAcceptanceCycle($this, '3000.00', '1000.00');
    $fee = FeeObligation::query()->sole();
    $pricing = $fee->feeSnapshot->getAttributes();
    registrationSavingsAcceptanceContribution($this, $agent, $customer, $plan, '1000.00');
    expect($fee->fresh()->outstandingAmountKobo())->toBe(300000);
    expect(app(WithdrawalBalanceService::class)->position($customer, $plan)['cycle_liability_kobo'])->toBe(100000);
    $application = ['plan_id' => $plan->plan_id, 'reason' => 'Reviewed exceptional registration settlement.', 'customer_description' => 'Original registration paid from this selected cycle.'];
    $before = registrationSavingsAcceptanceRows();
    $this->actingAs($admin)->postJson(route('admin.fees.obligations.savings-preview', $fee), $application)->assertUnprocessable()->assertJsonValidationErrors('plan_id');
    expect(registrationSavingsAcceptanceRows())->toBe($before);
    registrationSavingsAcceptanceContribution($this, $agent, $customer, $plan, '2000.00');
    expect($fee->fresh()->outstandingAmountKobo())->toBe(300000)->and($fee->settledAmountKobo())->toBe(0);
    expect(app(WithdrawalBalanceService::class)->position($customer, $plan)['cycle_liability_kobo'])->toBe(300000);
    expect((int) DB::table('collection_allocations')->sum('amount_kobo'))->toBe(300000);
    $this->assertDatabaseCount('ledger_posting_groups', 2);
    $this->assertDatabaseCount('fee_obligation_entries', 1);
    $this->assertDatabaseCount('fee_savings_applications', 0);
    $originalReceipts = DB::table('collection_receipts')->orderBy('id')->get()->all();
    $originalAllocations = DB::table('collection_allocations')->orderBy('id')->get()->all();
    $quote = $this->actingAs($admin)->postJson(route('admin.fees.obligations.savings-preview', $fee), $application)->assertOk()->json();
    expect($quote['amount_kobo'])->toBe(300000)->and($quote['remaining_cycle_savings_kobo'])->toBe(0);
    $application = [...$application, 'attempt_reference' => (string) Str::uuid(), 'confirmed' => true,
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at']];

    $this->withSession(registrationAcceptanceFresh())->postJson(route('admin.fees.obligations.apply-savings', $fee), $application)->assertOk()->assertJsonPath('status', 'posted');

    $group = LedgerPostingGroup::query()->where('source_type', 'fee_savings_application')->sole();
    expect($group->thrift_plan_id)->toBe($plan->id)->and($group->actor_user_id)->toBe($admin->id);
    expect($group->entries()->count())->toBe(2);
    $this->assertDatabaseHas('fee_obligation_entries', ['fee_obligation_id' => $fee->id, 'entry_type' => 'settlement', 'amount_kobo' => 300000,
        'source_type' => 'fee_savings_application', 'source_id' => $application['attempt_reference'], 'ledger_posting_reference' => $group->posting_reference]);
    expect($fee->fresh()->outstandingAmountKobo())->toBe(0)->and($fee->settledAmountKobo())->toBe(300000);
    expect(app(WithdrawalBalanceService::class)->position($customer, $plan)['cycle_liability_kobo'])->toBe(0);
    expect($fee->feeSnapshot->getAttributes())->toBe($pricing);
    expect(DB::table('collection_receipts')->orderBy('id')->get()->all())->toEqual($originalReceipts);
    expect(DB::table('collection_allocations')->orderBy('id')->get()->all())->toEqual($originalAllocations);
    $this->assertDatabaseCount('fee_savings_applications', 1);
    $this->assertDatabaseCount('fee_application_notification_intents', 3);
    $before = registrationSavingsAcceptanceRows();
    $this->postJson(route('admin.fees.obligations.apply-savings', $fee), $application)->assertOk()->assertJsonPath('posting_reference', $group->posting_reference);
    expect(registrationSavingsAcceptanceRows())->toBe($before);
    Queue::assertPushed(DeliverFeeApplicationNotificationIntent::class, 1);
});
