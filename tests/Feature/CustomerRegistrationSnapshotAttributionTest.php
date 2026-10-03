<?php

use App\Models\CustomerProfile;
use App\Models\FeeRule;
use App\Models\FeeSnapshot;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\FeeObligationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\CreatesLifecycleCustomers;

uses(CreatesLifecycleCustomers::class);

require_once __DIR__.'/../CollectionFixtures.php';

/** @return array{User, User, CustomerProfile, FeeSnapshot} */
function profileRegistrationAttributionFixture(bool $publicSource = false): array
{
    $admin = User::factory()->admin()->withTwoFactor()->create();
    [$agent, $customer] = collectionFixture();
    $rule = FeeRule::create(['version' => 1, 'name' => 'Original registration agreement', 'kind' => 'registration',
        'rule_key' => 'registration', 'model' => 'fixed', 'timing' => 'registration', 'basis' => 'none',
        'settlement_source' => 'external_receipt', 'currency' => 'NGN', 'amount_kobo' => 50000,
        'customer_description' => 'Agreed registration fee.', 'effective_at' => now()->subDay(),
        'published_by_user_id' => $admin->id, 'publication_reason' => 'Fixture registration agreement.']);
    $snapshot = FeeSnapshot::create(['customer_profile_id' => $customer->id, 'source_type' => 'registration',
        'source_id' => $publicSource ? $customer->customer_id : (string) $customer->id, 'fee_rule_id' => $rule->id,
        'fee_rule_version' => 1, 'name' => $rule->name, 'kind' => $rule->kind, 'model' => $rule->model,
        'timing' => $rule->timing, 'basis' => $rule->basis, 'settlement_source' => $rule->settlement_source,
        'currency' => 'NGN', 'amount_kobo' => 50000, 'basis_amount_kobo' => 0,
        'customer_description' => $rule->customer_description, 'acknowledged_at' => now()]);
    $fee = app(FeeObligationService::class)->assessRegistrationSnapshot($snapshot, $admin);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    config()->set('collections.enabled', true);
    $data = ['attempt_reference' => (string) Str::uuid(), 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'business_version' => 1,
        'plan_id' => null, 'plan_version' => null, 'received_date' => now('Africa/Lagos')->toDateString(),
        'savings_ngn' => '0.00', 'fees' => [['obligation_id' => $fee->id, 'amount_ngn' => '200.00']],
        'allocations' => [], 'late_reason' => '', 'notes' => '', 'confirmed' => true];
    $data['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $data)['preview_fingerprint'];
    app(CollectionService::class)->record($agent, $customer, $data);

    return [$admin, $agent, $customer, $snapshot];
}

test('profile registration terms and paid remainder ignore an earlier plan agreement in each permitted role', function (string $role, bool $publicSource): void {
    [$admin, $agent, $customer, $snapshot] = profileRegistrationAttributionFixture($publicSource);
    $actor = match ($role) {
        'Admin' => $admin, 'Agent' => $agent, default => $customer->user
    };
    $financial = [];
    foreach (['fee_snapshots', 'fee_obligations', 'fee_obligation_entries', 'collection_receipts', 'ledger_posting_groups', 'ledger_entries'] as $table) {
        $financial[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    $this->actingAs($actor)->get(route('customers.show', $customer->customer_id))->assertInertia(fn (Assert $page) => $page
        ->component('customers/Show')->where('customer.fee_snapshot.name', 'Original registration agreement')
        ->where('customer.fee_snapshot.amount_kobo', 50000)->where('customer.fee_snapshot.model', 'fixed')
        ->where('customer.fee_snapshot.obligation.status', 'available')
        ->where('customer.fee_snapshot.obligation.assessed_amount_kobo', 50000)
        ->where('customer.fee_snapshot.obligation.settled_amount_kobo', 20000)
        ->where('customer.fee_snapshot.obligation.outstanding_amount_kobo', 30000));

    expect($customer->fresh()->feeSnapshot->id)->toBe($snapshot->id);
    foreach ($financial as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }
})->with(['Admin' => ['Admin', false], 'Agent' => ['Agent', false], 'Customer' => ['Customer', false],
    'legacy public ID' => ['Customer', true]]);

test('invalid registration attribution remains unavailable across eager profile and summary reads', function (string $damage): void {
    [, , $customer, $snapshot] = profileRegistrationAttributionFixture();
    $foreign = CustomerProfile::factory()->create();
    $changes = match ($damage) {
        'foreign source' => ['source_id' => $foreign->customer_id],
        'wrong kind' => ['kind' => 'plan'],
        'wrong source type' => ['source_type' => 'manual_charge'],
    };
    DB::table('fee_snapshots')->where('id', $snapshot->id)->update($changes);

    $customers = CustomerProfile::query()->whereIn('id', [$customer->id, $foreign->id])->with('feeSnapshot')->get()->keyBy('id');

    expect($customers[$customer->id]->feeSnapshot)->toBeNull()
        ->and($customers[$foreign->id]->feeSnapshot)->toBeNull()
        ->and(app(FeeObligationService::class)->customerSummary($customers[$customer->id])['status'])->toBe('unavailable');
    $this->actingAs($customer->user)->get(route('customers.show', $customer->customer_id))->assertInertia(fn (Assert $page) => $page
        ->missing('customer.fee_snapshot'));
})->with(['foreign source', 'wrong kind', 'wrong source type']);

test('a Customer with only plan fee terms has unavailable registration terms rather than an invented zero fee', function (): void {
    $agent = User::factory()->agent()->withTwoFactor()->create();
    $customer = CustomerProfile::factory()->create();
    $this->createLifecyclePlan($customer, $agent);

    $this->actingAs($customer->user)->get(route('customers.show', $customer->customer_id))->assertInertia(fn (Assert $page) => $page
        ->missing('customer.fee_snapshot'));

    expect(app(FeeObligationService::class)->customerSummary($customer->fresh())['status'])->toBe('unavailable');
});
