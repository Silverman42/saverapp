<?php

use App\Enums\AdminPermission;
use App\Enums\CustomerAssignmentStatus;
use App\Models\AgentProfile;
use App\Models\CustomerAssignment;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../NoncashCollectionFixtures.php';

test('collection capture exposes current configured methods assignment versions and a protected proof reference', function (): void {
    [, $customer, $assignment, , , , $proof, $payload] = noncashFixture($this);
    $route = route('customers.collections.create', ['customer' => $customer->customer_id, 'evidence' => $proof]);
    $this->get($route)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('collections/Create')->where('customer.resource_id', $customer->id)
        ->where('customer.version', $customer->version)->where('customer.assignment_version', $assignment->version)
        ->where('initial_evidence', $proof)->has('collection_methods', 1)
        ->where('collection_methods.0.id', $payload['collection_method_version_id'])
        ->where('collection_methods.0.method_key', 'transfer'));
    LedgerAccount::query()->where('code', 'business_bank_ngn')->increment('version');
    $this->get($route)->assertOk()->assertInertia(fn (Assert $page) => $page->has('collection_methods', 0));
    config()->set('collections.noncash_enabled', false);
    $this->get($route)->assertOk()->assertInertia(fn (Assert $page) => $page->has('collection_methods', 0));
});

test('evidence pages deny Customer and ungranted Admin access while assigned Agents cannot review', function (): void {
    [$agent, $customer, , , , $admin, $proof] = noncashFixture($this);
    $index = route('collection-evidence.index');
    $view = route('collection-evidence.view', $proof);
    $this->get($index)->assertOk()->assertInertia(fn (Assert $page) => $page->has('evidence.data', 1)
        ->where('evidence.data.0.evidence_reference', $proof)->where('evidence.data.0.status', 'verified'));
    $this->get($view)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('collections/evidence/Show')->where('can_review', false)->where('can_record', true)
        ->where('evidence.customer_id', $customer->customer_id)->where('evidence.consumed', false));
    $this->actingAs($admin)->get($view)->assertOk()->assertInertia(fn (Assert $page) => $page->where('can_review', true)->where('can_record', false));
    $this->actingAs($customer->user)->get($index)->assertForbidden();
    $this->get($view)->assertForbidden();
    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())->get($index)->assertForbidden();
    $this->get($view)->assertForbidden();
    $admin->revokePermissionTo(AdminPermission::ReconciliationManage);
    $this->actingAs($admin->fresh())->get($view)->assertForbidden();
    $this->actingAs($agent)->get($view)->assertOk();
});

test('evidence queue and detail follow current assignment after handover', function (): void {
    [$agent, $customer, $assignment, , , , $proof] = noncashFixture($this);
    $successor = User::factory()->agent()->withTwoFactor()->create();
    $profile = AgentProfile::factory()->active()->create(['user_id' => $successor->id]);
    $assignment->update(['status' => CustomerAssignmentStatus::Ended, 'is_current' => null, 'ended_at' => now()]);
    CustomerAssignment::factory()->create(['customer_profile_id' => $customer->id, 'agent_profile_id' => $profile->id, 'version' => 2]);
    $this->actingAs($agent)->get(route('collection-evidence.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('evidence.data', 0));
    $this->get(route('collection-evidence.view', $proof))->assertForbidden();
    $this->actingAs($successor)->get(route('collection-evidence.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('evidence.data', 1));
    $this->get(route('collection-evidence.view', $proof))->assertOk()->assertInertia(fn (Assert $page) => $page->where('can_record', true));
});

test('consumed evidence remains readable and leaves the unconsumed queue without another review action', function (): void {
    [, $customer, , , , $admin, $proof, $payload] = noncashFixture($this);
    postNoncashReceipt($this, $customer, $payload);
    $this->actingAs($admin)->get(route('collection-evidence.index', ['status' => 'verified']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('evidence.data', 0));
    $this->get(route('collection-evidence.index', ['status' => 'consumed']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('evidence.data', 1)->where('evidence.data.0.status', 'consumed'));
    $this->get(route('collection-evidence.view', $proof))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('evidence.consumed', true)->where('can_review', false)->where('can_check_review', true));
    $operation = DB::table('collection_evidence_reviews')->sole()->operation_reference;
    $this->getJson(route('collection-evidence.reviews.show', [$proof, $operation]))->assertOk()->assertJsonPath('status', 'posted');
    $this->assertDatabaseCount('collection_receipts', 1);
});

test('review outcome recovery returns the original immutable operation even after a later review', function (): void {
    [$agent, , , , , $admin, $proof] = noncashFixture($this);
    $first = DB::table('collection_evidence_reviews')->sole();
    $operation = (string) Str::uuid();
    $this->actingAs($admin)->postJson(route('collection-evidence.review', $proof), [
        'operation_reference' => $operation, 'expected_version' => 1, 'outcome' => 'rejected',
        'reason' => 'Subsequent independent investigation rejected the claim.',
    ])->assertOk();
    $this->getJson(route('collection-evidence.reviews.show', [$proof, $first->operation_reference]))->assertOk()
        ->assertJson(['status' => 'posted', 'outcome' => 'verified', 'version' => 1]);
    $this->getJson(route('collection-evidence.reviews.show', [$proof, $operation]))->assertOk()
        ->assertJson(['status' => 'posted', 'outcome' => 'rejected', 'version' => 2]);
    $this->getJson(route('collection-evidence.reviews.show', [$proof, (string) Str::uuid()]))->assertNotFound();
    $otherAdmin = User::factory()->admin()->withTwoFactor()->create();
    $otherAdmin->givePermissionTo(AdminPermission::ReconciliationManage);
    $this->actingAs($otherAdmin)->getJson(route('collection-evidence.reviews.show', [$proof, $operation]))->assertNotFound();
    $this->actingAs($agent)->getJson(route('collection-evidence.reviews.show', [$proof, $operation]))->assertForbidden();
    $this->assertDatabaseCount('collection_evidence_reviews', 2);
    $this->assertDatabaseCount('collection_receipts', 0);
});

test('evidence queue status filters distinguish pending and rejected unconsumed claims', function (string $status): void {
    [$agent, $customer, $assignment, , $date, $admin, , $payload] = noncashFixture($this);
    $reference = (string) Str::uuid();
    $this->actingAs($agent)->postJson(route('customers.collection-evidence.store', $customer), [
        'evidence_reference' => $reference, 'customer_version' => $customer->version, 'assignment_version' => $assignment->version,
        'collection_method_version_id' => $payload['collection_method_version_id'], 'method_reference' => 'ANOTHER-PAYMENT',
        'received_date' => $date, 'amount_ngn' => '2000.00', 'source_attestation' => 'A separate payment awaiting independent review.',
        'files' => [UploadedFile::fake()->image('separate-proof.png')],
    ])->assertCreated();
    if ($status === 'rejected') {
        $this->actingAs($admin)->postJson(route('collection-evidence.review', $reference), [
            'operation_reference' => (string) Str::uuid(), 'expected_version' => 0, 'outcome' => 'rejected',
            'reason' => 'Independent investigation rejected the separate claim.',
        ])->assertOk();
    }
    $this->get(route('collection-evidence.index', ['status' => $status]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('evidence.data', 1)->where('evidence.data.0.evidence_reference', $reference)->where('evidence.data.0.status', $status));
    $this->get(route('collection-evidence.index', ['status' => 'verified']))->assertOk()->assertInertia(fn (Assert $page) => $page->has('evidence.data', 1));
    $this->assertDatabaseCount('collection_receipts', 0);
})->with(['pending', 'rejected']);
