<?php

use App\Enums\AdminPermission;
use App\Models\BusinessProfile;
use App\Models\CollectionBatch;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\BusinessSettings;
use App\Services\BusinessSettingsCatalogue;
use App\Services\BusinessSettingsReadiness;
use App\Services\CollectionBatchPosition;
use App\Services\CollectionEvidenceScanner;
use App\Services\FinancialReleaseEvidenceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

require_once __DIR__.'/../NoncashCollectionFixtures.php';
require_once __DIR__.'/../CashExecutionFixtures.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00:00', 'Africa/Lagos'));
    LedgerAccount::query()->whereNotNull('effective_at')->update(['effective_at' => now()->subDay()]);
    DB::table('cash_method_versions')->update(['effective_at' => now()->subDay()]);
});

/** @param list<string> $capabilities */
function recordNoncashOwnerEvidence(User $actor, array $capabilities): void
{
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    config()->set('app.financial_release_revision', 'noncash-test-release');
    $evidence = app(FinancialReleaseEvidenceService::class);
    foreach ($capabilities as $capability) {
        foreach ($evidence::ROLES as $role) {
            $evidence->record($actor, ['capability' => $capability, 'owner_role' => $role, 'version' => 1, 'state' => 'accepted',
                'dependency_hash' => $evidence->dependencyHash(), 'valid_until' => now()->addDay()->toIso8601String(),
                'evidence' => 'TEST FIXTURE non-cash collection owner release evidence.']);
        }
    }
}

/** @param array<string, mixed> $patch */
function publishNoncashSettings(User $actor, array $patch): void
{
    $settings = app(BusinessSettings::class);
    $settings->import();
    $draft = $settings->saveDraft($actor, $patch, BusinessProfile::current()->version, (string) Str::uuid());
    $preview = $settings->preview($actor, $draft['draft_id'], $draft['revision'], null);
    $request = Request::create('/admin/business-settings', 'POST');
    $request->setLaravelSession(app('session')->driver());
    $request->session()->put(cashSession());
    $settings->publish($actor, $draft['draft_id'], $draft['revision'], $preview['reference'], 'Enable reviewed collection method', (string) Str::uuid(), $request);
}

test('non-cash readiness requires owner evidence, custody mapping, the switch and a scanner', function (string $capability, string $custody): void {
    $actor = User::factory()->admin()->withTwoFactor()->create();
    $actor->givePermissionTo(AdminPermission::BusinessSettingsManage);
    $readiness = fn (): array => app(BusinessSettingsReadiness::class)->checks()[$capability];
    expect($readiness()['state'])->toBe('Unavailable')
        ->and(app(BusinessSettingsCatalogue::class)->definitions()[$capability]['editable'])->toBeFalse();

    config()->set(['collections.noncash_enabled' => true, 'collections.evidence_scanner_fake' => true]);
    recordNoncashOwnerEvidence($actor, [$capability]);
    expect($readiness())->blocker->toBe('')->state->toBe('Ready to enable')
        ->and(app(BusinessSettingsCatalogue::class)->definitions()[$capability]['editable'])->toBeTrue();

    config()->set('collections.evidence_scanner_fake', false);
    expect($readiness())->state->toBe('Unavailable')->blocker->toContain('evidence scanner');
    config()->set('collections.evidence_scanner_fake', true);

    config()->set('collections.noncash_enabled', false);
    expect($readiness())->state->toBe('Unavailable')->blocker->toContain('non-cash collection switch');
    config()->set('collections.noncash_enabled', true);

    if ($custody !== '') {
        LedgerAccount::query()->where('code', $custody)->update(['mapping_status' => 'unconfigured']);
        expect($readiness())->state->toBe('Unavailable')->blocker->toContain($custody);
    }
})->with([
    'transfer' => ['collection_transfer', 'business_bank_ngn'],
    'pos' => ['collection_pos', 'payment_clearing_ngn'],
    'other' => ['collection_other', ''],
]);

test('a published non-cash method records receipts while Agent cash stays disabled', function (string $method, string $custody): void {
    [, $customer, , , , $admin, , $payload] = noncashFixture($this, $method, $custody);
    recordNoncashOwnerEvidence($admin, ['collections', 'collection_'.$method]);

    publishNoncashSettings($admin, ['collections' => true]);
    $this->postJson(route('customers.collections.preview', $customer->customer_id), $payload)->assertServiceUnavailable();

    publishNoncashSettings($admin, ['collection_'.$method => true]);
    $payload['business_version'] = BusinessProfile::current()->version;
    $this->get(route('customers.collections.create', $customer->customer_id))->assertOk()
        ->assertInertia(fn ($page) => $page->where('cash_enabled', false)->has('collection_methods', 1));
    $this->postJson(route('customers.collections.preview', $customer->customer_id), [...$payload, 'method' => 'cash',
        'collection_method_version_id' => null, 'evidence_reference' => null])->assertServiceUnavailable();

    $receipt = postNoncashReceipt($this, $customer, $payload);
    expect($receipt->method)->toBe($method)->and($receipt->custody_account_code)->toBe($custody);
})->with([['transfer', 'business_bank_ngn'], ['pos', 'payment_clearing_ngn']]);

test('an Other method with Agent custody reconciles through a cash handoff', function (): void {
    [, $customer, , , $date, $admin, , $payload] = noncashFixture($this, 'other', 'agent_receivable_ngn');
    $receipt = postNoncashReceipt($this, $customer, $payload);
    $this->travel(1)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $this->travel(-1)->days();
    $batch = CollectionBatch::query()->findOrFail($receipt->collection_batch_id);

    $this->actingAs($admin)->withSession(cashSession())->post(route('collection-batches.remittances.store', $batch), [
        'handoff_reference' => 'OTHER-AGENT-HANDOFF', 'amount_ngn' => '2000.00', 'handoff_date' => $date,
        'receiving_location' => 'Lagos office', 'source_attestation' => 'Counted Other-method tender held by the Agent.',
        'batch_version' => $batch->fresh()->version, 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(app(CollectionBatchPosition::class)->read($batch->fresh()))
        ->expected_kobo->toBe(200000)->received_kobo->toBe(200000)->outstanding_kobo->toBe(0);
});

test('the fake evidence scanner only works in local or testing environments', function (): void {
    config()->set(['collections.evidence_scanner_fake' => true, 'collections.evidence_scanner_binary' => null]);
    $scanner = app(CollectionEvidenceScanner::class);
    expect($scanner->isConfigured())->toBeTrue()
        ->and($scanner->scan('/tmp/unused'))->toBe(CollectionEvidenceScanner::LOCAL_FAKE_VERSION);

    app()->detectEnvironment(fn (): string => 'production');
    expect($scanner->isConfigured())->toBeFalse()
        ->and(fn () => $scanner->scan('/tmp/unused'))->toThrow(ServiceUnavailableHttpException::class);
    app()->detectEnvironment(fn (): string => 'testing');
});
