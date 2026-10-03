<?php

use App\Enums\AdminPermission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../NoncashCollectionFixtures.php';

test('method management exposes safe version history and current custody mappings only to permitted Admins', function (): void {
    [$agent, $customer, , , , $admin, , $payload] = noncashFixture($this);
    $this->actingAs($admin)->get(route('collection-methods.manage'))->assertInertia(fn (Assert $page) => $page
        ->component('collections/Methods')->has('methods.data', 1)->has('accounts', 3)
        ->where('methods.data.0.id', $payload['collection_method_version_id'])
        ->where('latest_versions.transfer', 1)->missing('methods.data.0.reason')->missing('methods.data.0.payload_hash')
        ->missing('methods.data.0.publication_reference'));
    foreach ([$agent, $customer->user, User::factory()->admin()->withTwoFactor()->create()] as $actor) {
        $this->actingAs($actor)->get(route('collection-methods.manage'))->assertForbidden();
    }
    $admin->revokePermissionTo(AdminPermission::BusinessSettingsManage);
    $this->actingAs($admin)->get(route('collection-methods.manage'))->assertForbidden();
});

test('method publication recovery returns only the original actor result and respects revoked authority', function (): void {
    [, , , , , $admin] = noncashFixture($this);
    $reference = DB::table('collection_method_versions')->value('publication_reference');
    $this->actingAs($admin)->getJson(route('collection-methods.publication-result', $reference))->assertOk()
        ->assertExactJson(['method_version_id' => 1, 'method_key' => 'transfer', 'version' => 1, 'label' => 'Verified transfer']);
    $other = User::factory()->admin()->withTwoFactor()->create();
    $other->givePermissionTo(AdminPermission::BusinessSettingsManage);
    $this->actingAs($other)->getJson(route('collection-methods.publication-result', $reference))->assertNotFound();
    $this->actingAs($admin)->getJson(route('collection-methods.publication-result', (string) Str::uuid()))->assertNotFound();
    $admin->revokePermissionTo(AdminPermission::BusinessSettingsManage);
    $this->getJson(route('collection-methods.publication-result', $reference))->assertForbidden();
});

test('workspace publication preserves immutable versions supports replay and rejects stale or changed instructions', function (): void {
    [, , , , , $admin] = noncashFixture($this);
    $data = ['publication_reference' => (string) Str::uuid(), 'method_key' => 'transfer', 'version' => 2,
        'label' => 'Reviewed bank destination', 'custody_account_code' => 'business_bank_ngn', 'mapping_version' => 1,
        'destination_key' => 'reviewed-destination', 'attachment_required' => true, 'reason' => 'Independent destination and evidence reviewed.'];
    $this->actingAs($admin)->withSession(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
    $response = $this->postJson(route('collection-methods.store'), $data)->assertCreated();
    $id = $response->json('method_version_id');
    $this->postJson(route('collection-methods.store'), $data)->assertCreated()->assertJsonPath('method_version_id', $id);
    $this->postJson(route('collection-methods.store'), [...$data, 'destination_key' => 'another-destination'])->assertConflict();
    $this->postJson(route('collection-methods.store'), [...$data, 'publication_reference' => (string) Str::uuid()])->assertConflict();
    $this->assertDatabaseCount('collection_method_versions', 2);
    $this->getJson(route('collection-methods.publication-result', $data['publication_reference']))->assertOk()->assertJsonPath('method_version_id', $id);
    $this->get(route('collection-methods.manage'))->assertInertia(fn (Assert $page) => $page
        ->where('latest_versions.transfer', 2)->where('methods.data.0.destination_key', 'reviewed-destination')
        ->where('methods.data.1.destination_key', 'test-destination')->missing('methods.data.0.reason'));
});

test('method workspace refreshes mapping versions and omits retired or future custody destinations', function (): void {
    [, , , , , $admin] = noncashFixture($this);
    DB::table('ledger_accounts')->where('code', 'business_bank_ngn')->update(['version' => 2]);
    DB::table('ledger_accounts')->where('code', 'agent_receivable_ngn')->update(['retired_at' => now()]);
    DB::table('ledger_accounts')->where('code', 'payment_clearing_ngn')->update(['effective_at' => now()->addDay()]);
    $this->actingAs($admin)->get(route('collection-methods.manage'))->assertInertia(fn (Assert $page) => $page
        ->has('accounts', 1)->where('accounts.0.code', 'business_bank_ngn')->where('accounts.0.version', 2));
    $this->withSession(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp])
        ->postJson(route('collection-methods.store'), ['publication_reference' => (string) Str::uuid(), 'method_key' => 'transfer',
            'version' => 2, 'label' => 'Stale bank configuration', 'custody_account_code' => 'business_bank_ngn', 'mapping_version' => 1,
            'destination_key' => 'stale-destination', 'attachment_required' => true, 'reason' => 'Previously reviewed mapping version.'])
        ->assertConflict();
    $this->assertDatabaseCount('collection_method_versions', 1);
});
