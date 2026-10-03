<?php

use App\Enums\AdminPermission;
use App\Enums\FeeSettlementSource;
use App\Models\BusinessProfile;
use App\Models\ChargeCategoryVersion;
use App\Models\CollectionReceipt;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\FeeRule;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\ManualCharge;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Services\CollectionReadService;
use App\Services\FeeObligationService;
use App\Services\ThriftPlanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\CreatesLifecycleCustomers;

uses(CreatesLifecycleCustomers::class);

require_once __DIR__.'/../CollectionFixtures.php';
require_once __DIR__.'/../NoncashCollectionFixtures.php';
require_once __DIR__.'/../FeeFixtures.php';
require_once __DIR__.'/../ManualChargeFixtures.php';

/** @return array<string, array<int, array<string, mixed>>> */
function collectionDependencyOwners(): array
{
    $owners = [];
    foreach (['collection_receipts', 'collection_batches', 'collection_allocations',
        'collection_fee_components', 'ledger_posting_groups', 'ledger_entries',
        'fee_rules', 'fee_snapshots', 'fee_obligations', 'fee_obligation_entries', 'plan_lifecycle_events', 'collection_notification_intents',
        'thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'withdrawal_reservations',
        'ledger_transaction_references', 'ledger_transaction_projections', 'notification_inbox_intents', 'management_mail_dispatches'] as $table) {
        $owners[$table] = DB::table($table)->orderBy('id')->get()
            ->map(static fn (stdClass $row): array => (array) $row)->all();
    }

    return $owners;
}

beforeEach(function (): void {
    config()->set('collections.enabled', true);
});

test('COL-AC-029/063: unavailable existing savings rejects preview and commit without new money', function (string $failure): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture(3);
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $payload['preview_fingerprint'] = $this->actingAs($agent)
        ->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json('preview_fingerprint');

    if ($failure === 'mapping') {
        DB::table('ledger_accounts')->where('code', 'customer_savings_liability_ngn')
            ->update(['mapping_status' => 'unmapped']);
    } else {
        DB::table('withdrawal_reservations')->insert([
            'customer_profile_id' => $customer->id, 'owner_reference' => 'unavailable-source',
            'gross_amount_kobo' => $failure === 'zero reservation' ? 0 : 100000,
            'status' => 'live', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $before = collectionDependencyOwners();

    $this->postJson(route('customers.collections.store', $customer->customer_id), $payload)
        ->assertStatus(503);
    $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertStatus(503);

    expect(collectionDependencyOwners())->toBe($before);
    expect(DB::table('audit_events')->where('event_type', 'collection.receipt_posted')->count())->toBe(0);
})->with(['unmapped savings account' => ['mapping'], 'zero live reservation' => ['zero reservation'],
    'new tender must not conceal excessive reservation' => ['excessive reservation']]);

test('COL-AC-063: protected evidence lost after preview rejects noncash commit without financial effects', function (bool $missing, int $status): void {
    [$agent, $customer, $assignment, $plan, $today, $admin, $reference, $payload] = noncashFixture($this);
    $payload['preview_fingerprint'] = $this->actingAs($agent)
        ->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json('preview_fingerprint');
    $file = DB::table('collection_evidence_files')->first();
    if ($missing) {
        Storage::disk('collection_evidence')->delete($file->storage_path);
    } else {
        Storage::disk('collection_evidence')->put($file->storage_path, 'changed private evidence');
    }
    $before = collectionDependencyOwners();
    $proof = DB::table('collection_payment_evidence')->orderBy('id')->get()->toJson();

    $this->postJson(route('customers.collections.store', $customer->customer_id), $payload)
        ->assertStatus($status);

    expect(collectionDependencyOwners())->toBe($before);
    expect(DB::table('collection_payment_evidence')->orderBy('id')->get()->toJson())->toBe($proof);
    expect(DB::table('audit_events')->where('event_type', 'collection.receipt_posted')->count())->toBe(0);
})->with(['missing bytes' => [true, 503], 'changed bytes' => [false, 409]]);

test('COL-AC-063: required cash and split tender chart outages make preview and commit unavailable', function (bool $withFee, string $account): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture(3, feeAmountKobo: $withFee ? 50000 : 0,
        feeSource: FeeSettlementSource::ExternalReceipt);
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    if ($withFee) {
        $obligation = app(FeeObligationService::class)->assessSnapshot($plan->currentTermsRevision()->feeSnapshot, $agent);
        $payload['fees'] = [['obligation_id' => $obligation->id, 'amount_ngn' => '500.00']];
    }
    $payload['preview_fingerprint'] = $this->actingAs($agent)
        ->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json('preview_fingerprint');
    DB::table('ledger_accounts')->where('code', $account)->update(['mapping_status' => 'unmapped']);
    $owners = collectionDependencyOwners();
    $successfulAudits = DB::table('canonical_audit_events')->where('outcome', 'Succeeded')->orderBy('id')->get()->all();

    $preview = $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload);
    $commit = $this->postJson(route('customers.collections.store', $customer->customer_id), $payload);

    expect(collectionDependencyOwners())->toBe($owners);
    expect(DB::table('canonical_audit_events')->where('outcome', 'Succeeded')->orderBy('id')->get()->all())->toEqual($successfulAudits);
    $this->assertDatabaseMissing('canonical_audit_events', ['event_type' => 'collection.receipt_posted', 'outcome' => 'Succeeded']);
    expect(['preview' => $preview->status(), 'commit' => $commit->status()])->toBe(['preview' => 503, 'commit' => 503]);
})->with(['cash principal custody mapping' => [false, 'agent_receivable_ngn'],
    'split tender custody mapping' => [true, 'agent_receivable_ngn'], 'split tender fee income mapping' => [true, 'fee_income_ngn']]);

test('COL-AC-063: an unrelated fee income outage leaves no fee cash principal collection available', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture(3);
    DB::table('ledger_accounts')->where('code', 'fee_income_ngn')->update(['mapping_status' => 'unmapped']);
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $payload['preview_fingerprint'] = $this->actingAs($agent)
        ->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json('preview_fingerprint');

    $this->post(route('customers.collections.store', $customer->customer_id), $payload)
        ->assertRedirect()->assertSessionHasNoErrors();

    $receipt = CollectionReceipt::query()->sole();
    expect($receipt->savings_amount_kobo)->toBe(200000);
    expect($receipt->fee_amount_kobo)->toBe(0);
    expect($receipt->tender_amount_kobo)->toBe(200000);
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(200000);
    $this->assertDatabaseCount('ledger_posting_groups', 1);
    $this->assertDatabaseCount('fee_obligations', 0);
    $this->assertDatabaseCount('fee_obligation_entries', 0);
    $this->assertDatabaseCount('collection_fee_components', 0);
});

test('COL-AC-063: selected fee agreement damage after review makes preview and confirmation unavailable', function (string $damage): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture(3);
    $fee = reportFeeObligation($agent, $customer);
    $foreignFee = $damage === 'foreign snapshot' ? reportFeeObligation($agent, CustomerProfile::factory()->create(), version: 2) : null;
    $payload = [...collectionPayload($customer, $assignment, $plan, $today, '0.00'),
        'plan_id' => null, 'plan_version' => null, 'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => '500.00']]];
    $payload['preview_fingerprint'] = $this->actingAs($agent)
        ->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json('preview_fingerprint');
    if ($damage === 'rule version') {
        DB::table('fee_snapshots')->where('id', $fee->fee_snapshot_id)->update(['fee_rule_version' => 2]);
    } elseif ($damage === 'missing assessment') {
        DB::table('fee_obligation_entries')->where('fee_obligation_id', $fee->id)->where('entry_type', 'assessment')->delete();
    } elseif ($damage === 'foreign registration source') {
        $otherCustomer = CustomerProfile::factory()->create();
        DB::table('fee_snapshots')->where('id', $fee->fee_snapshot_id)->update(['source_id' => $otherCustomer->customer_id]);
        DB::table('fee_obligations')->where('id', $fee->id)->update(['source_id' => $otherCustomer->customer_id]);
    } else {
        DB::table('fee_obligations')->where('id', $fee->id)->update(['fee_snapshot_id' => $foreignFee->fee_snapshot_id]);
    }
    $owners = collectionDependencyOwners();
    $successfulAudits = DB::table('canonical_audit_events')->where('outcome', 'Succeeded')->orderBy('id')->get()->all();

    $preview = $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload);
    $commit = $this->postJson(route('customers.collections.store', $customer->customer_id), $payload);

    expect(['preview' => $preview->status(), 'commit' => $commit->status(),
        'receipts' => DB::table('collection_receipts')->count(),
        'settlements' => DB::table('fee_obligation_entries')->where('entry_type', 'settlement')->count(),
        'journals' => DB::table('ledger_posting_groups')->count(), 'notices' => DB::table('collection_notification_intents')->count()])
        ->toBe(['preview' => 503, 'commit' => 503, 'receipts' => 0, 'settlements' => 0, 'journals' => 0, 'notices' => 0]);
    expect(collectionDependencyOwners())->toBe($owners);
    expect(DB::table('canonical_audit_events')->where('outcome', 'Succeeded')->orderBy('id')->get()->all())->toEqual($successfulAudits);
    $this->assertDatabaseMissing('canonical_audit_events', ['event_type' => 'collection.receipt_posted', 'outcome' => 'Succeeded']);
})->with(['lost immutable rule version' => ['rule version'], 'foreign Customer agreement' => ['foreign snapshot'],
    'missing original assessment' => ['missing assessment'], 'foreign registration source' => ['foreign registration source']]);

test('selected registration fee retirement preserves the reviewed physical receipt and exact accepted replay', function (): void {
    $this->freezeTime();
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture(3);
    $fee = reportFeeObligation($agent, $customer);
    $snapshot = $fee->feeSnapshot;
    $rule = $snapshot->feeRule;
    $snapshotBefore = $snapshot->getAttributes();
    $feeBefore = $fee->fresh()->getAttributes();
    $assessmentBefore = $fee->entries()->sole()->getAttributes();
    $ruleBefore = $rule->getAttributes();
    $planBefore = $plan->fresh()->getAttributes();
    $termsBefore = $plan->currentTermsRevision()->getAttributes();
    $slotsBefore = $plan->slots()->orderBy('id')->get()->map(fn (object $slot): array => $slot->getAttributes())->all();
    $payload = [...collectionPayload($customer, $assignment, $plan, $today, '0.00'),
        'plan_id' => null, 'plan_version' => null, 'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => '500.00']]];
    $payload['preview_fingerprint'] = $this->actingAs($agent)
        ->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json('preview_fingerprint');
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $reason = 'End new registration agreements while preserving issued obligations.';
    $review = $this->actingAs($admin)->postJson(route('admin.fees.registration.retirement-preview', $rule), ['reason' => $reason])
        ->assertOk()->json('preview_fingerprint');

    $this->withSession(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp])
        ->postJson(route('admin.fees.registration.retire', $rule), ['reason' => $reason, 'preview_fingerprint' => $review, 'confirmed' => true])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($rule->fresh()->retired_at->timestamp)->toBe(now()->timestamp);
    expect(collect($rule->fresh()->getAttributes())->except(['retired_at', 'updated_at'])->all())
        ->toBe(collect($ruleBefore)->except(['retired_at', 'updated_at'])->all());
    expect($snapshot->fresh()->getAttributes())->toBe($snapshotBefore);
    expect($fee->fresh()->getAttributes())->toBe($feeBefore);
    expect($fee->entries()->sole()->getAttributes())->toBe($assessmentBefore);
    $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->assertJsonPath('preview_fingerprint', $payload['preview_fingerprint']);
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)
        ->assertRedirect()->assertSessionHasNoErrors();
    $receipt = CollectionReceipt::query()->sole();
    expect($receipt->method)->toBe('cash');
    expect($receipt->customer_profile_id)->toBe($customer->id);
    expect($receipt->recording_agent_profile_id)->toBe($assignment->agent_profile_id);
    expect($receipt->thrift_plan_id)->toBeNull();
    expect($receipt->savings_posting_group_id)->toBeNull();
    expect($receipt->savings_amount_kobo)->toBe(0);
    expect($receipt->fee_amount_kobo)->toBe(50000);
    expect($receipt->tender_amount_kobo)->toBe(50000);
    $component = DB::table('collection_fee_components')->sole();
    expect((int) $component->collection_receipt_id)->toBe($receipt->id);
    expect((int) $component->fee_obligation_id)->toBe($fee->id);
    $lines = DB::table('ledger_entries as lines')->join('ledger_accounts as accounts', 'accounts.id', '=', 'lines.ledger_account_id')
        ->where('lines.ledger_posting_group_id', $component->ledger_posting_group_id)->orderBy('lines.line_number')
        ->get(['accounts.code', 'lines.side', 'lines.amount_kobo', 'lines.customer_profile_id', 'lines.agent_profile_id', 'lines.fee_obligation_id'])
        ->map(fn (object $line): array => ['account' => $line->code, 'side' => $line->side, 'amount_kobo' => (int) $line->amount_kobo,
            'customer_id' => (int) $line->customer_profile_id, 'agent_id' => $line->agent_profile_id === null ? null : (int) $line->agent_profile_id,
            'fee_id' => (int) $line->fee_obligation_id])->all();
    expect($lines)->toBe([
        ['account' => 'agent_receivable_ngn', 'side' => 'debit', 'amount_kobo' => 50000,
            'customer_id' => $customer->id, 'agent_id' => $assignment->agent_profile_id, 'fee_id' => $fee->id],
        ['account' => 'fee_income_ngn', 'side' => 'credit', 'amount_kobo' => 50000,
            'customer_id' => $customer->id, 'agent_id' => null, 'fee_id' => $fee->id],
    ]);
    $settlement = $fee->entries()->where('entry_type', 'settlement')->sole();
    expect($settlement->source_type)->toBe('collection_receipt');
    expect($settlement->source_id)->toBe($receipt->id.'-'.$fee->id);
    expect($settlement->amount_kobo)->toBe(50000);
    expect($fee->fresh()->outstandingAmountKobo())->toBe(0);
    expect($fee->entries()->where('entry_type', 'assessment')->sole()->getAttributes())->toBe($assessmentBefore);
    expect($plan->fresh()->getAttributes())->toBe($planBefore);
    expect($plan->currentTermsRevision()->getAttributes())->toBe($termsBefore);
    expect($plan->slots()->orderBy('id')->get()->map(fn (object $slot): array => $slot->getAttributes())->all())->toBe($slotsBefore);
    expect($snapshot->fresh()->getAttributes())->toBe($snapshotBefore);
    $this->assertDatabaseCount('collection_allocations', 0);
    $this->assertDatabaseCount('collection_receipts', 1);
    $this->assertDatabaseCount('ledger_posting_groups', 1);
    $this->assertDatabaseCount('collection_fee_components', 1);
    $this->assertDatabaseCount('collection_notification_intents', 3);
    $this->assertDatabaseHas('collection_notification_intents', [
        'collection_receipt_id' => $receipt->id,
        'recipient_user_id' => $agent->id,
        'audience_type' => 'current_agent',
        'channel' => 'database',
    ]);
    $baseline = collectionDependencyOwners();
    $successfulAudits = DB::table('canonical_audit_events')->where('outcome', 'Succeeded')->orderBy('id')->get()->all();

    $this->post(route('customers.collections.store', $customer->customer_id), $payload)
        ->assertRedirect(route('collections.show', $receipt))->assertSessionHasNoErrors();

    expect(collectionDependencyOwners())->toBe($baseline);
    expect(DB::table('canonical_audit_events')->where('outcome', 'Succeeded')->orderBy('id')->get()->all())->toEqual($successfulAudits);
});

test('selected fee existence stays private for foreign and unknown IDs through preview and confirmation', function (): void {
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture(3);
    $ownFee = reportFeeObligation($agent, $customer);
    $foreignCustomer = CustomerProfile::factory()->create();
    $foreignFee = reportFeeObligation($agent, $foreignCustomer, version: 2);
    $payload = [...collectionPayload($customer, $assignment, $plan, $today, '0.00'),
        'plan_id' => null, 'plan_version' => null, 'fees' => [['obligation_id' => $ownFee->id, 'amount_ngn' => '500.00']]];
    $payload['preview_fingerprint'] = $this->actingAs($agent)
        ->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json('preview_fingerprint');
    $owners = collectionDependencyOwners();
    $successfulAudits = DB::table('canonical_audit_events')->where('outcome', 'Succeeded')->orderBy('id')->get()->all();
    $responses = [];

    foreach (['foreign' => $foreignFee->id, 'unknown' => $foreignFee->id + 1] as $case => $feeId) {
        $data = [...$payload, 'fees' => [['obligation_id' => $feeId, 'amount_ngn' => '500.00']]];
        $preview = $this->postJson(route('customers.collections.preview', $customer->customer_id), $data);
        $commit = $this->postJson(route('customers.collections.store', $customer->customer_id), $data);
        $responses[$case] = ['preview_status' => $preview->status(), 'preview_message' => $preview->json('message'),
            'commit_status' => $commit->status(), 'commit_message' => $commit->json('message')];
    }

    expect(collectionDependencyOwners())->toBe($owners);
    expect(DB::table('canonical_audit_events')->where('outcome', 'Succeeded')->orderBy('id')->get()->all())->toEqual($successfulAudits);
    expect($responses)->toBe([
        'foreign' => ['preview_status' => 404, 'preview_message' => 'Record unavailable.', 'commit_status' => 404, 'commit_message' => 'Record unavailable.'],
        'unknown' => ['preview_status' => 404, 'preview_message' => 'Record unavailable.', 'commit_status' => 404, 'commit_message' => 'Record unavailable.'],
    ]);
});

test('COL-AC-063: selected manual fee retained owner damage makes preview and confirmation unavailable', function (string $damage): void {
    config()->set('fees.manual_charges_enabled', true);
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture(3);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $this->actingAs($admin)->withSession(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp])
        ->post(route('admin.charges.publish'), [
            'publication_reference' => (string) Str::uuid(), 'category_key' => 'selected-manual-service',
            'kind' => 'manual_fee', 'purpose' => 'Reviewed manual service terms.',
            'customer_description' => 'Agreed manual service fee.', 'amount_ngn' => '500.00', 'confirmed' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
    $category = ChargeCategoryVersion::query()->sole();
    $instructions = reviewManualCharge($this, [
        'operation_reference' => (string) Str::uuid(), 'customer_id' => $customer->customer_id,
        'plan_id' => $plan->plan_id, 'category_id' => $category->id, 'customer_version' => $customer->fresh()->version,
        'plan_version' => $plan->fresh()->version, 'reason' => 'Independently reviewed selected service assessment.', 'confirmed' => true,
    ]);
    $this->post(route('admin.charges.assess'), $instructions)->assertRedirect()->assertSessionHasNoErrors();
    $charge = ManualCharge::query()->sole();
    $fee = FeeObligation::query()->findOrFail($charge->fee_obligation_id);
    $otherFee = $damage === 'crossed obligation binding' ? reportFeeObligation($agent, $customer) : null;
    expect($fee->source_type)->toBe('manual_charge');
    expect($fee->source_id)->toBe($charge->operation_reference);
    expect($fee->outstandingAmountKobo())->toBe(50000);
    $payload = [...collectionPayload($customer->fresh(), $assignment, $plan->fresh(), $today, '0.00'),
        'plan_id' => null, 'plan_version' => null, 'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => '500.00']]];
    $payload['preview_fingerprint'] = $this->actingAs($agent)
        ->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->json('preview_fingerprint');
    DB::table('manual_charges')->where('id', $charge->id)->update($damage === 'missing operation reference'
        ? ['operation_reference' => (string) Str::uuid()]
        : ['fee_obligation_id' => $otherFee->id]);
    $owners = collectionDependencyOwners();
    $manualSources = [];
    foreach (['manual_charges', 'charge_category_versions', 'manual_charge_notification_intents'] as $table) {
        $manualSources[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    $successfulAudits = DB::table('canonical_audit_events')->where('outcome', 'Succeeded')->orderBy('id')->get()->all();

    $preview = $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload);
    $commit = $this->postJson(route('customers.collections.store', $customer->customer_id), $payload);

    expect(['preview' => $preview->status(), 'commit' => $commit->status(),
        'receipts' => DB::table('collection_receipts')->count(),
        'settlements' => DB::table('fee_obligation_entries')->where('entry_type', 'settlement')->count(),
        'journals' => DB::table('ledger_posting_groups')->count(), 'notices' => DB::table('collection_notification_intents')->count()])
        ->toBe(['preview' => 503, 'commit' => 503, 'receipts' => 0, 'settlements' => 0, 'journals' => 0, 'notices' => 0]);
    expect(collectionDependencyOwners())->toBe($owners);
    foreach ($manualSources as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
    expect(DB::table('canonical_audit_events')->where('outcome', 'Succeeded')->orderBy('id')->get()->all())->toEqual($successfulAudits);
})->with(['missing operation reference' => ['missing operation reference'], 'crossed obligation binding' => ['crossed obligation binding']]);

function automaticFeeDependencyPlan(User $agent, CustomerProfile $customer, FeeRule $rule): ThriftPlan
{
    $data = ['name' => 'Reviewed automatic contribution fee cycle', 'amount_ngn' => '2000.00',
        'start_date' => now('Africa/Lagos')->toDateString(), 'contribution_days' => 2, 'customer_visible_notes' => '',
        'fee_rule_id' => $rule->id, 'fee_rule_version' => $rule->version, 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'business_version' => BusinessProfile::current()->version,
        'customer_agreement_attested' => true];
    $owner = app(ThriftPlanService::class);
    $data['preview_fingerprint'] = $owner->preview($agent, $customer, $data)['preview_fingerprint'];

    return $owner->create($agent, $customer, (string) Str::uuid(), $data)['plan'];
}

test('COL-AC-029/063: automatic due cycle fee requires its retained Customer plan agreement and pricing source', function (string $damage): void {
    [$admin, $customer, $agentProfile] = $this->createLifecycleFixture();
    [, $otherCustomer, $otherAgentProfile] = $this->createLifecycleFixture();
    $agent = $agentProfile->user;
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    $rule = FeeRule::create(['version' => 1, 'name' => 'Retained first contribution external fee', 'kind' => 'plan',
        'rule_key' => 'automatic-due-source', 'model' => 'fixed', 'timing' => 'first_contribution', 'basis' => 'none',
        'settlement_source' => 'external_receipt', 'currency' => 'NGN', 'amount_kobo' => 50000,
        'customer_description' => 'Agreed contribution fee remains separately outstanding.', 'effective_at' => now()->subDay(),
        'published_by_user_id' => $admin->id, 'publication_reason' => 'Reviewed original automatic contribution fee terms.']);
    $plan = automaticFeeDependencyPlan($agent, $customer, $rule);
    $otherPlan = automaticFeeDependencyPlan($otherAgentProfile->user, $otherCustomer, $rule);
    $original = $plan->currentTermsRevision();
    $foreign = $otherPlan->currentTermsRevision()->feeSnapshot;
    expect($original->feeSnapshot->customer_profile_id)->toBe($customer->id);
    expect($foreign->customer_profile_id)->toBe($otherCustomer->id);
    expect($foreign->source_id)->toBe($otherPlan->plan_id.'-R1');
    $payload = collectionPayload($customer, $customer->currentAssignment, $plan, now('Africa/Lagos')->toDateString(), '2000.00');
    $payload['preview_fingerprint'] = $this->actingAs($agent)
        ->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertOk()->json('preview_fingerprint');
    if ($damage === 'crossed Customer plan') {
        DB::table('plan_terms_revisions')->where('id', $original->id)->update(['fee_snapshot_id' => $foreign->id]);
    } else {
        DB::table('fee_snapshots')->where('id', $original->fee_snapshot_id)->update(['fee_rule_version' => $rule->version + 1]);
    }
    $owners = collectionDependencyOwners();
    $successfulAudits = DB::table('canonical_audit_events')->where('outcome', 'Succeeded')->orderBy('id')->get()->all();
    $preview = $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload);
    $commit = $this->postJson(route('customers.collections.store', $customer->customer_id), $payload);

    expect(['preview' => $preview->status(), 'commit' => $commit->status(),
        'receipts' => DB::table('collection_receipts')->count(), 'assessments' => DB::table('fee_obligation_entries')->count(),
        'journals' => DB::table('ledger_posting_groups')->count(), 'notices' => DB::table('collection_notification_intents')->count()])
        ->toBe(['preview' => 503, 'commit' => 503, 'receipts' => 0, 'assessments' => 0, 'journals' => 0, 'notices' => 0]);
    expect(collectionDependencyOwners())->toBe($owners);
    expect(DB::table('canonical_audit_events')->where('outcome', 'Succeeded')->orderBy('id')->get()->all())->toEqual($successfulAudits);
})->with(['Crossed real other Customer plan agreement' => ['crossed Customer plan'],
    'Missing retained original published rule version' => ['rule version']]);

test('automatic due cycle fee retains its reviewed original agreement after retirement and accepted receipt replay', function (): void {
    $this->freezeTime();
    [$admin, $customer, $agentProfile] = $this->createLifecycleFixture();
    $agent = $agentProfile->user;
    $admin->givePermissionTo(AdminPermission::FeesManage);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    $rule = FeeRule::create(['version' => 1, 'name' => 'Retained first contribution external fee', 'kind' => 'plan',
        'rule_key' => 'automatic-retirement-source', 'model' => 'fixed', 'timing' => 'first_contribution', 'basis' => 'none',
        'settlement_source' => 'external_receipt', 'currency' => 'NGN', 'amount_kobo' => 50000,
        'customer_description' => 'Agreed contribution fee remains separately outstanding.', 'effective_at' => now()->subDay(),
        'published_by_user_id' => $admin->id, 'publication_reason' => 'Reviewed original contribution fee terms.']);
    $plan = automaticFeeDependencyPlan($agent, $customer, $rule);
    $snapshot = $plan->currentTermsRevision()->feeSnapshot;
    $snapshotBefore = $snapshot->getAttributes();
    $termsBefore = $plan->termsRevisions()->get()->toArray();
    $slotsBefore = $plan->slots()->get()->toArray();
    $payload = collectionPayload($customer, $customer->currentAssignment, $plan, now('Africa/Lagos')->toDateString(), '2000.00');
    $payload['preview_fingerprint'] = $this->actingAs($agent)
        ->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertOk()->json('preview_fingerprint');
    $reason = 'Retire new selection while honoring existing cycle agreements.';
    $retirement = $this->actingAs($admin)->postJson(route('admin.fees.registration.retirement-preview', $rule), ['reason' => $reason])
        ->assertOk()->json('preview_fingerprint');
    $this->withSession(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp])
        ->postJson(route('admin.fees.registration.retire', $rule), ['reason' => $reason,
            'preview_fingerprint' => $retirement, 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    expect($rule->fresh()->retired_at->timestamp)->toBe(now()->timestamp);
    expect($snapshot->fresh()->getAttributes())->toBe($snapshotBefore);
    $this->actingAs($agent)->postJson(route('customers.collections.preview', $customer->customer_id), $payload)
        ->assertOk()->assertJsonPath('preview_fingerprint', $payload['preview_fingerprint']);
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect()->assertSessionHasNoErrors();
    $receipt = CollectionReceipt::query()->sole();
    $fee = FeeObligation::query()->sole();
    expect($receipt->savings_amount_kobo)->toBe(200000)->and($receipt->fee_amount_kobo)->toBe(0)
        ->and($receipt->tender_amount_kobo)->toBe(200000)->and($receipt->thrift_plan_id)->toBe($plan->id);
    expect($fee->customer_profile_id)->toBe($customer->id)->and($fee->fee_snapshot_id)->toBe($snapshot->id)
        ->and($fee->source_type)->toBe($snapshot->source_type)->and($fee->source_id)->toBe($snapshot->source_id)
        ->and($fee->outstandingAmountKobo())->toBe(50000)->and($fee->settledAmountKobo())->toBe(0);
    expect($fee->entries()->sole()->entry_type->value)->toBe('assessment');
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(200000);
    expect($plan->termsRevisions()->get()->toArray())->toBe($termsBefore);
    expect($plan->slots()->get()->toArray())->toBe($slotsBefore);
    expect($snapshot->fresh()->getAttributes())->toBe($snapshotBefore);
    $this->assertDatabaseCount('ledger_posting_groups', 1);
    $this->assertDatabaseCount('collection_fee_components', 0);
    $this->assertDatabaseCount('collection_allocations', 1);
    DB::table('fee_snapshots')->where('id', $snapshot->id)->update(['fee_rule_version' => 99]);
    $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertStatus(503);
    $baseline = collectionDependencyOwners();
    $successfulAudits = DB::table('canonical_audit_events')->where('outcome', 'Succeeded')->orderBy('id')->get()->all();
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)
        ->assertRedirect(route('collections.show', $receipt))->assertSessionHasNoErrors();
    expect(collectionDependencyOwners())->toBe($baseline);
    expect(DB::table('canonical_audit_events')->where('outcome', 'Succeeded')->orderBy('id')->get()->all())->toEqual($successfulAudits);
});

test('selected historical plan fee requires its original cycle owner before fee only physical payment', function (string $source): void {
    if ($source === 'legacy') {
        [$agent, $customer, $assignment, $plan, $date] = collectionFixture(2, feeAmountKobo: 50000,
            feeSource: FeeSettlementSource::ExternalReceipt);
        $rule = $plan->currentTermsRevision()->feeSnapshot->feeRule;
    } else {
        [$admin, $customer, $profile] = $this->createLifecycleFixture();
        $agent = $profile->user;
        $assignment = $customer->currentAssignment;
        $date = now('Africa/Lagos')->toDateString();
        FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
        $rule = FeeRule::create(['version' => 1, 'name' => 'Original assessed external cycle fee', 'kind' => 'plan',
            'rule_key' => 'selected-original-cycle', 'model' => 'fixed', 'timing' => 'first_contribution', 'basis' => 'none',
            'settlement_source' => 'external_receipt', 'currency' => 'NGN', 'amount_kobo' => 50000,
            'customer_description' => 'Original first contribution fee separately payable.', 'effective_at' => now()->subDay(),
            'published_by_user_id' => $admin->id, 'publication_reason' => 'Reviewed original cycle fee.']);
        $plan = automaticFeeDependencyPlan($agent, $customer, $rule);
    }
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $otherCustomer = CustomerProfile::factory()->create();
    CustomerAssignment::factory()->create(['customer_profile_id' => $otherCustomer->id,
        'agent_profile_id' => $assignment->agent_profile_id, 'assigned_by_user_id' => $agent->id]);
    $otherPlan = automaticFeeDependencyPlan($agent, $otherCustomer, $rule);
    $payload = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $payload['preview_fingerprint'] = $this->actingAs($agent)
        ->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertOk()->json('preview_fingerprint');
    $this->post(route('customers.collections.store', $customer->customer_id), $payload)->assertRedirect()->assertSessionHasNoErrors();
    $fee = FeeObligation::query()->sole();
    expect($fee->customer_profile_id)->toBe($customer->id)->and($fee->outstandingAmountKobo())->toBe(50000);
    expect($fee->source_type)->toBe($source === 'legacy' ? 'plan' : 'plan_terms_revision');
    $payment = [...collectionPayload($customer, $assignment, $plan->fresh(), $date, '0.00'),
        'plan_id' => null, 'plan_version' => null, 'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => '500.00']]];
    $payment['preview_fingerprint'] = $this->postJson(route('customers.collections.preview', $customer->customer_id), $payment)
        ->assertOk()->json('preview_fingerprint');
    if ($source === 'legacy') {
        DB::table('fee_snapshots')->where('id', $fee->fee_snapshot_id)->update(['source_id' => $otherPlan->plan_id]);
        DB::table('fee_obligations')->where('id', $fee->id)->update(['source_id' => $otherPlan->plan_id]);
    } elseif ($source === 'unavailable owner') {
        DB::table('fee_snapshots')->where('id', $fee->fee_snapshot_id)->update(['source_type' => 'unavailable_cycle_owner']);
        DB::table('fee_obligations')->where('id', $fee->id)->update(['source_type' => 'unavailable_cycle_owner']);
    } else {
        DB::table('plan_terms_revisions')->where('thrift_plan_id', $plan->id)
            ->where('fee_snapshot_id', $fee->fee_snapshot_id)->update(['fee_snapshot_id' => $otherPlan->currentTermsRevision()->fee_snapshot_id]);
    }
    $owners = collectionDependencyOwners();
    $successfulAudits = DB::table('canonical_audit_events')->where('outcome', 'Succeeded')->orderBy('id')->get()->all();
    $preview = $this->postJson(route('customers.collections.preview', $customer->customer_id), $payment);
    $commit = $this->postJson(route('customers.collections.store', $customer->customer_id), $payment);

    expect(['preview' => $preview->status(), 'commit' => $commit->status(),
        'receipts' => DB::table('collection_receipts')->count(),
        'settlements' => DB::table('fee_obligation_entries')->where('entry_type', 'settlement')->count(),
        'journals' => DB::table('ledger_posting_groups')->count()])
        ->toBe(['preview' => 503, 'commit' => 503, 'receipts' => 1, 'settlements' => 0, 'journals' => 1]);
    expect(collectionDependencyOwners())->toBe($owners);
    expect(DB::table('canonical_audit_events')->where('outcome', 'Succeeded')->orderBy('id')->get()->all())->toEqual($successfulAudits);
})->with(['Detached actual modern original assessment revision' => ['modern'],
    'Legacy original assessment crossed to another real Customer cycle' => ['legacy'],
    'Unavailable original cycle source owner' => ['unavailable owner']]);

test('selected historical plan fee honors original assessed revision after descriptive amendment retirement and accepted replay', function (): void {
    $this->freezeTime();
    [$admin, $customer, $profile] = $this->createLifecycleFixture();
    $agent = $profile->user;
    $admin->givePermissionTo(AdminPermission::FeesManage);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    $rule = FeeRule::create(['version' => 1, 'name' => 'Original assessed external cycle fee', 'kind' => 'plan',
        'rule_key' => 'historical-original-cycle', 'model' => 'fixed', 'timing' => 'first_contribution', 'basis' => 'none',
        'settlement_source' => 'external_receipt', 'currency' => 'NGN', 'amount_kobo' => 50000,
        'customer_description' => 'Original agreed cycle fee separately payable.', 'effective_at' => now()->subDay(),
        'published_by_user_id' => $admin->id, 'publication_reason' => 'Reviewed original first contribution terms.']);
    $plan = automaticFeeDependencyPlan($agent, $customer, $rule);
    $original = $plan->currentTermsRevision();
    $snapshotBefore = $original->feeSnapshot->getAttributes();
    $slotsBefore = $plan->slots()->get()->toArray();
    $date = now('Africa/Lagos')->toDateString();
    $contribution = collectionPayload($customer, $customer->currentAssignment, $plan, $date, '2000.00');
    $contribution['preview_fingerprint'] = $this->actingAs($agent)
        ->postJson(route('customers.collections.preview', $customer->customer_id), $contribution)->assertOk()->json('preview_fingerprint');
    $this->post(route('customers.collections.store', $customer->customer_id), $contribution)->assertRedirect()->assertSessionHasNoErrors();
    $fee = FeeObligation::query()->sole();
    expect($fee->source_type)->toBe('plan_terms_revision')->and($fee->source_id)->toBe($plan->plan_id.'-R1');
    $assessmentBefore = $fee->entries()->sole()->getAttributes();
    $plan->refresh();
    $revision = ['name' => 'Customer agreed clearer cycle name', 'amount_ngn' => '2000.00', 'start_date' => $date,
        'contribution_days' => 2, 'customer_visible_notes' => 'Original money and dates preserved.',
        'fee_rule_id' => $rule->id, 'fee_rule_version' => $rule->version, 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'business_version' => BusinessProfile::current()->version,
        'plan_version' => $plan->version, 'terms_revision' => $plan->current_terms_revision,
        'customer_agreement_attested' => true, 'reason' => 'Customer confirmed descriptive details.',
        'customer_explanation' => 'The original assessed fee and money agreement are preserved.'];
    $plans = app(ThriftPlanService::class);
    $revision['preview_fingerprint'] = $plans->previewRevision($agent, $plan, $revision)['preview_fingerprint'];
    $plan = $plans->revise($agent, $plan, (string) Str::uuid(), $revision);
    expect($plan->current_terms_revision)->toBe(2)->and($plan->currentTermsRevision()->fee_snapshot_id)->toBe($fee->fee_snapshot_id);
    $reason = 'Retire new cycle selection while preserving issued fee obligations.';
    $retirement = $this->actingAs($admin)->postJson(route('admin.fees.registration.retirement-preview', $rule), ['reason' => $reason])
        ->assertOk()->json('preview_fingerprint');
    $this->withSession(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp])
        ->postJson(route('admin.fees.registration.retire', $rule), ['reason' => $reason,
            'preview_fingerprint' => $retirement, 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    expect($rule->fresh()->retired_at->timestamp)->toBe(now()->timestamp);
    $payment = [...collectionPayload($customer, $customer->currentAssignment, $plan, $date, '0.00'),
        'plan_id' => null, 'plan_version' => null, 'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => '500.00']]];
    $payment['preview_fingerprint'] = $this->actingAs($agent)
        ->postJson(route('customers.collections.preview', $customer->customer_id), $payment)->assertOk()->json('preview_fingerprint');
    $this->post(route('customers.collections.store', $customer->customer_id), $payment)->assertRedirect()->assertSessionHasNoErrors();
    $receipt = CollectionReceipt::query()->where('attempt_reference', $payment['attempt_reference'])->sole();
    expect($receipt->thrift_plan_id)->toBeNull()->and($receipt->savings_amount_kobo)->toBe(0)
        ->and($receipt->fee_amount_kobo)->toBe(50000)->and($receipt->tender_amount_kobo)->toBe(50000);
    $component = DB::table('collection_fee_components')->where('collection_receipt_id', $receipt->id)->sole();
    expect((int) $component->fee_obligation_id)->toBe($fee->id);
    $group = DB::table('ledger_posting_groups')->where('id', $component->ledger_posting_group_id)->sole();
    expect((int) $group->thrift_plan_id)->toBe($plan->id)->and($group->source_id)->toBe($receipt->id.'-'.$fee->id);
    expect($fee->fresh()->outstandingAmountKobo())->toBe(0)->and($fee->fresh()->settledAmountKobo())->toBe(50000);
    expect($fee->entries()->where('entry_type', 'assessment')->sole()->getAttributes())->toBe($assessmentBefore);
    expect($original->fresh()->feeSnapshot->getAttributes())->toBe($snapshotBefore);
    expect($plan->slots()->get()->toArray())->toBe($slotsBefore);
    $this->assertDatabaseCount('collection_receipts', 2);
    $this->assertDatabaseCount('ledger_posting_groups', 2);
    $baseline = collectionDependencyOwners();
    $successfulAudits = DB::table('canonical_audit_events')->where('outcome', 'Succeeded')->orderBy('id')->get()->all();
    $this->post(route('customers.collections.store', $customer->customer_id), $payment)
        ->assertRedirect(route('collections.show', $receipt))->assertSessionHasNoErrors();
    expect(collectionDependencyOwners())->toBe($baseline);
    expect(DB::table('canonical_audit_events')->where('outcome', 'Succeeded')->orderBy('id')->get()->all())->toEqual($successfulAudits);
});

test('mandatory automatic savings fee application requires only affordable accounting destinations', function (bool $affordable, bool $completion): void {
    [$admin, $customer, $profile] = $this->createLifecycleFixture();
    $agent = $profile->user;
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    $amount = $affordable ? 50000 : 300000;
    $rule = FeeRule::create(['version' => 1, 'name' => 'Agreed automatic savings fee application', 'kind' => 'plan',
        'rule_key' => 'mandatory-application-mapping', 'model' => 'fixed', 'timing' => $completion ? 'cycle_completion' : 'first_contribution', 'basis' => 'none',
        'settlement_source' => 'savings_application', 'currency' => 'NGN', 'amount_kobo' => $amount,
        'customer_description' => 'Apply the full agreed fee when savings can cover it.', 'effective_at' => now()->subDay(),
        'published_by_user_id' => $admin->id, 'publication_reason' => 'Reviewed original full application agreement.']);
    $plan = automaticFeeDependencyPlan($agent, $customer, $rule);
    $snapshotBefore = $plan->currentTermsRevision()->feeSnapshot->getAttributes();
    $termsBefore = $plan->termsRevisions()->get()->toArray();
    $slotsBefore = $plan->slots()->get()->toArray();
    $payload = collectionPayload($customer, $customer->currentAssignment, $plan, now('Africa/Lagos')->toDateString(), $completion ? '4000.00' : '2000.00');
    $payload['preview_fingerprint'] = $this->actingAs($agent)
        ->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertOk()->json('preview_fingerprint');
    DB::table('ledger_accounts')->where('code', 'fee_income_ngn')->update(['mapping_status' => 'unmapped']);
    $owners = collectionDependencyOwners();
    $successfulAudits = DB::table('canonical_audit_events')->where('outcome', 'Succeeded')->orderBy('id')->get()->all();
    $preview = $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload);
    $commit = $this->post(route('customers.collections.store', $customer->customer_id), $payload);
    if ($affordable) {
        expect(['preview' => $preview->status(), 'commit' => $commit->status(),
            'receipts' => DB::table('collection_receipts')->count(), 'entries' => DB::table('fee_obligation_entries')->count(),
            'journals' => DB::table('ledger_posting_groups')->count(), 'notices' => DB::table('collection_notification_intents')->count()])
            ->toBe(['preview' => 503, 'commit' => 503, 'receipts' => 0, 'entries' => 0, 'journals' => 0, 'notices' => 0]);
        expect(collectionDependencyOwners())->toBe($owners);
        expect(DB::table('canonical_audit_events')->where('outcome', 'Succeeded')->orderBy('id')->get()->all())->toEqual($successfulAudits);
    } else {
        $preview->assertOk()->assertJsonPath('preview_fingerprint', $payload['preview_fingerprint']);
        $commit->assertRedirect()->assertSessionHasNoErrors();
        $receipt = CollectionReceipt::query()->sole();
        $fee = FeeObligation::query()->sole();
        expect($receipt->savings_amount_kobo)->toBe(200000)->and($receipt->fee_amount_kobo)->toBe(0)
            ->and($receipt->tender_amount_kobo)->toBe(200000);
        expect($fee->amount_kobo)->toBe(300000)->and($fee->outstandingAmountKobo())->toBe(300000)
            ->and($fee->settledAmountKobo())->toBe(0);
        expect($fee->entries()->sole()->entry_type->value)->toBe('assessment');
        expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(200000);
        expect($plan->currentTermsRevision()->feeSnapshot->getAttributes())->toBe($snapshotBefore);
        expect($plan->termsRevisions()->get()->toArray())->toBe($termsBefore);
        expect($plan->slots()->get()->toArray())->toBe($slotsBefore);
        $this->assertDatabaseCount('ledger_posting_groups', 1);
        $this->assertDatabaseCount('collection_fee_components', 0);
        $this->assertDatabaseCount('collection_allocations', 1);
        $this->assertDatabaseMissing('ledger_posting_groups', ['event_type' => 'savings_fee_application']);
        expect(LedgerAccount::query()->where('code', 'fee_income_ngn')->sole()->mapping_status)->toBe('unmapped');
        $originalAssessment = $fee->entries()->sole()->getAttributes();
        $later = collectionPayload($customer, $customer->currentAssignment, $plan->fresh(), now('Africa/Lagos')->toDateString(), '2000.00');
        $later['preview_fingerprint'] = $this->postJson(route('customers.collections.preview', $customer->customer_id), $later)
            ->assertOk()->json('preview_fingerprint');
        $this->post(route('customers.collections.store', $customer->customer_id), $later)->assertRedirect()->assertSessionHasNoErrors();
        expect(app(CollectionReadService::class)->position($customer)['available_kobo'])->toBe(400000);
        expect($fee->fresh()->outstandingAmountKobo())->toBe(300000)->and($fee->fresh()->settledAmountKobo())->toBe(0);
        expect($fee->entries()->sole()->getAttributes())->toBe($originalAssessment);
        expect($plan->fresh()->status->value)->toBe('completed');
        expect($plan->currentTermsRevision()->feeSnapshot->getAttributes())->toBe($snapshotBefore);
        expect($plan->termsRevisions()->get()->toArray())->toBe($termsBefore);
        expect($plan->slots()->get()->toArray())->toBe($slotsBefore);
        $this->assertDatabaseCount('collection_receipts', 2);
        $this->assertDatabaseCount('ledger_posting_groups', 2);
        $this->assertDatabaseCount('collection_allocations', 2);
        $this->assertDatabaseCount('fee_obligation_entries', 1);
        $this->assertDatabaseMissing('ledger_posting_groups', ['event_type' => 'savings_fee_application']);
        expect(LedgerAccount::query()->where('code', 'fee_income_ngn')->sole()->mapping_status)->toBe('unmapped');
    }
})->with(['Required affordable first contribution application' => [true, false],
    'Required affordable completion application' => [true, true], 'Unaffordable first fee and later contribution remain permitted' => [false, false]]);
