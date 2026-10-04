<?php

use App\Enums\AdminPermission;
use App\Models\BusinessConfigurationDraft;
use App\Models\BusinessConfigurationVersion;
use App\Models\BusinessProfile;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\AuditCapture;
use App\Services\BusinessSettings;
use App\Services\BusinessSettingsReadiness;
use App\Services\CollectionService;
use App\Services\FinancialReleaseEvidenceService;
use App\Services\NotificationPipeline;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../WithdrawalFixtures.php';
require_once __DIR__.'/../CollectionFixtures.php';

function configurationManager(): User
{
    $user = User::factory()->admin()->withTwoFactor()->create();
    $user->givePermissionTo(AdminPermission::BusinessSettingsManage);

    return $user;
}
function configurationRequest(): Request
{
    $request = Request::create('/admin/business-settings', 'POST');
    $request->setLaravelSession(app('session')->driver());
    $request->session()->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);

    return $request;
}
function configurationDraft(User $actor, array $patch, ?string $effectiveAt = null): array
{
    $settings = app(BusinessSettings::class);
    $settings->import();
    $draft = $settings->saveDraft($actor, $patch, BusinessProfile::current()->version, (string) Str::uuid());
    $preview = $settings->preview($actor, $draft['draft_id'], $draft['revision'], $effectiveAt);

    return [...$draft, 'preview' => $preview];
}
function publishConfiguration(User $actor, array $draft, ?string $operation = null): array
{
    return app(BusinessSettings::class)->publish($actor, $draft['draft_id'], $draft['revision'], $draft['preview']['reference'],
        'Reviewed prospective change', $operation ?? (string) Str::uuid(), configurationRequest());
}

test('plan creation remains disabled until complete current owner evidence permits explicit publication', function (): void {
    $this->freezeTime();
    $actor = configurationManager();
    $settings = app(BusinessSettings::class);
    $settings->import();
    expect(fn () => configurationDraft($actor, ['plan_creation' => true]))->toThrow(ValidationException::class);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    config()->set('app.financial_release_revision', 'plan-creation-test-release');
    $evidence = app(FinancialReleaseEvidenceService::class);
    foreach ($evidence::ROLES as $role) {
        $evidence->record($actor, ['capability' => 'plan_creation', 'owner_role' => $role,
            'version' => 1, 'state' => 'accepted', 'dependency_hash' => $evidence->dependencyHash(),
            'valid_until' => now()->addDay()->toIso8601String(), 'evidence' => 'TEST FIXTURE current plan-owner release evidence.']);
    }
    expect($evidence->check('plan_creation')['state'])->toBe('Ready to enable');
    expect(fn () => $settings->ensureFeature('plan_creation'))->toThrow(HttpException::class);
    $draft = configurationDraft($actor, ['plan_creation' => true]);
    publishConfiguration($actor, $draft);
    $settings->ensureFeature('plan_creation');
    expect($settings->resolve()['values']['plan_creation'])->toBeTrue();
    LedgerAccount::query()->where('code', 'customer_savings_liability_ngn')->increment('version');
    expect(fn () => $settings->ensureFeature('plan_creation'))->toThrow(HttpException::class);
});

test('trusted import preserves identity and values without inventing prior history or enabling financial methods', function () {
    BusinessProfile::current()->update(['display_name' => 'Trusted thrift', 'version' => 7]);
    $version = app(BusinessSettings::class)->import();
    expect($version->version)->toBe(7)->and($version->values['display_name'])->toBe('Trusted thrift')->and($version->values['collections'])->toBeFalse();
    expect(app(BusinessSettings::class)->import()->id)->toBe($version->id);
    $this->assertDatabaseCount('business_configuration_versions', 1);
    $this->assertDatabaseCount('business_settings_notification_intents', 0);
});
test('local cash certification permits audited settings publication only when explicitly enabled', function () {
    $actor = configurationManager();
    $settings = app(BusinessSettings::class);
    $settings->import();
    expect(app(BusinessSettingsReadiness::class)->checks()['collections']['state'])->toBe('Unavailable');
    expect(fn () => $settings->saveDraft($actor, ['collections' => true], 1, (string) Str::uuid()))
        ->toThrow(ValidationException::class);

    config()->set('collections.local_certified', true);
    expect(app(BusinessSettingsReadiness::class)->checks()['collection_cash']['state'])->toBe('Ready to enable');
    app()->detectEnvironment(static fn (): string => 'production');
    try {
        expect(app(BusinessSettingsReadiness::class)->checks()['collection_cash']['state'])->toBe('Unavailable');
    } finally {
        app()->detectEnvironment(static fn (): string => 'testing');
    }
    $draft = configurationDraft($actor, ['collections' => true]);
    publishConfiguration($actor, $draft);
    $settings->ensureFeature('collections');
    expect(fn () => $settings->ensureFeature('collection_cash'))->toThrow(HttpException::class);

    $cashDraft = configurationDraft($actor, ['collection_cash' => true]);
    publishConfiguration($actor, $cashDraft);
    $settings->ensureFeature('collection_cash');
    expect($settings->resolve()['values']['collections'])->toBeTrue()
        ->and($settings->resolve()['values']['collection_cash'])->toBeTrue();

    config()->set('collections.local_certified', false);
    expect(fn () => $settings->ensureFeature('collections'))->toThrow(HttpException::class);
});
test('baseline admins read safe configuration but cannot mutate it', function () {
    app(BusinessSettings::class)->import();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $this->actingAs($admin)->get(route('admin.business-settings.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/business-settings/Index')->where('settings.can_manage', false)->missing('settings.values.legal_name')->missing('settings.values.address'));
    $this->post(route('admin.business-settings.drafts.store'), ['operation_id' => (string) Str::uuid(), 'base_version' => 1, 'patch' => ['display_name' => 'Denied']])->assertForbidden();
    expect(BusinessProfile::current()->display_name)->toBe('SaverApp');
});
test('customers and agents cannot access configuration management', function (string $type) {
    $user = User::factory()->{$type}()->withTwoFactor()->create();
    $this->actingAs($user)->get(route('admin.business-settings.index'))->assertForbidden();
})->with(['customer', 'agent']);
test('unauthenticated settings requests require sign in', function () {
    $this->get(route('admin.business-settings.index'))->assertRedirect(route('login'));
});
test('draft and preview do not change runtime configuration', function () {
    $actor = configurationManager();
    $draft = configurationDraft($actor, ['display_name' => 'New name']);
    expect(BusinessProfile::current()->display_name)->toBe('SaverApp');
    expect($draft['preview']['diff']['display_name'])->toBe(['before' => 'SaverApp', 'after' => 'New name']);
    expect(DB::table('business_configuration_drafts')->value('patch'))->not->toContain('New name');
    expect(DB::table('business_configuration_operations')->value('result'))->not->toContain('draft_id');
});
test('publication becomes effective only after acknowledged atomic capture and preserves previous version', function () {
    $actor = configurationManager();
    $draft = configurationDraft($actor, ['display_name' => 'New name']);
    $result = publishConfiguration($actor, $draft);
    expect(BusinessProfile::current()->display_name)->toBe('New name')->and(BusinessProfile::current()->version)->toBe(2);
    expect(BusinessConfigurationVersion::query()->where('version', 1)->sole()->values['display_name'])->toBe('SaverApp');
    $this->assertDatabaseHas('business_configuration_work', ['configuration_id' => $result['configuration_id'], 'status' => 'effective']);
    $this->assertDatabaseCount('business_configuration_acknowledgements', 2);
    $this->assertDatabaseCount('business_settings_notification_intents', 2);
    expect(DB::table('canonical_audit_events')->where('event_type', 'business_settings.published')->exists())->toBeTrue();
});
test('identical publication retries return the original version without another event', function () {
    $actor = configurationManager();
    $draft = configurationDraft($actor, ['display_name' => 'New name']);
    $operation = (string) Str::uuid();
    $result = publishConfiguration($actor, $draft, $operation);
    expect(publishConfiguration($actor, $draft, $operation))->toBe($result);
    $this->assertDatabaseCount('business_configuration_versions', 2);
    expect(app(BusinessSettings::class)->result($actor, $operation))->toBe($result);
});
test('changed operation input conflicts instead of publishing twice', function () {
    $actor = configurationManager();
    $settings = app(BusinessSettings::class);
    $settings->import();
    $operation = (string) Str::uuid();
    $settings->saveDraft($actor, ['display_name' => 'First'], 1, $operation);
    expect(fn () => $settings->saveDraft($actor, ['display_name' => 'Second'], 1, $operation))->toThrow(ConflictHttpException::class);
    $this->assertDatabaseCount('business_configuration_drafts', 1);
});
test('saving a new draft revision invalidates its old preview', function () {
    $actor = configurationManager();
    $draft = configurationDraft($actor, ['display_name' => 'First']);
    app(BusinessSettings::class)->saveDraft($actor, ['display_name' => 'Second'], 1, (string) Str::uuid(), $draft['draft_id'], 1);
    expect(fn () => publishConfiguration($actor, $draft))->toThrow(ConflictHttpException::class);
    expect(BusinessConfigurationDraft::query()->sole()->preview)->toBeNull();
});
test('a competing publication makes the older draft stale', function () {
    $actor = configurationManager();
    $first = configurationDraft($actor, ['display_name' => 'First']);
    $second = configurationDraft($actor, ['display_name' => 'Second']);
    publishConfiguration($actor, $first);
    expect(fn () => publishConfiguration($actor, $second))->toThrow(ConflictHttpException::class);
    expect(BusinessProfile::current()->display_name)->toBe('First');
});
test('stale fresh authentication blocks publication without changing settings', function () {
    $actor = configurationManager();
    $draft = configurationDraft($actor, ['display_name' => 'New name']);
    $request = configurationRequest();
    $request->session()->put('auth.mfa_confirmed_at', now()->subMinutes(11)->timestamp);
    expect(fn () => app(BusinessSettings::class)->publish($actor, $draft['draft_id'], 1, $draft['preview']['reference'], 'Reason', (string) Str::uuid(), $request))->toThrow(ConflictHttpException::class);
    $this->assertDatabaseCount('business_configuration_versions', 1);
});
test('revoked manager authority prevents publication using a retained actor object', function () {
    $actor = configurationManager();
    $draft = configurationDraft($actor, ['display_name' => 'New name']);
    $actor->revokePermissionTo(AdminPermission::BusinessSettingsManage);
    expect(fn () => publishConfiguration($actor, $draft))->toThrow(HttpException::class);
    $this->assertDatabaseCount('business_configuration_versions', 1);
});
test('another manager cannot edit or publish a private draft', function () {
    $draft = configurationDraft(configurationManager(), ['legal_name' => 'Private legal name']);
    $other = configurationManager();
    $this->actingAs($other)->post(route('admin.business-settings.drafts.preview', $draft['draft_id']), ['revision' => 1])->assertNotFound();
});
test('unknown protected and owner fields reject the whole draft', function (array $patch) {
    $actor = configurationManager();
    app(BusinessSettings::class)->import();
    expect(fn () => app(BusinessSettings::class)->saveDraft($actor, $patch, 1, (string) Str::uuid()))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('business_configuration_drafts', 0);
})->with([
    'fee override' => [['display_name' => 'Valid', 'fee_amount_kobo' => 0]],
    'MFA override' => [['mfa_required' => false]], 'account creation' => [['account_code' => 'anything']],
    'unsupported method' => [['collection_transfer' => true]], 'template body' => [['template_body' => 'Body']],
    'non-midnight boundary' => [['day_boundary' => '01:00']], 'currency change' => [['currency' => 'USD']],
    'unready timezone' => [['timezone' => 'America/New_York']], 'secret' => [['api_key' => 'secret']],
]);
test('invalid typed values and unsafe profile content are rejected', function (array $patch) {
    $actor = configurationManager();
    app(BusinessSettings::class)->import();
    expect(fn () => app(BusinessSettings::class)->saveDraft($actor, $patch, 1, (string) Str::uuid()))->toThrow(ValidationException::class);
})->with([
    'markup' => [['display_name' => '<script>bad</script>']], 'blank name' => [['display_name' => '']],
    'fractional kobo' => [['receipt_minimum_kobo' => 1.5]], 'numeric string' => [['late_lookback_days' => '30']],
    'negative lookback' => [['late_lookback_days' => -1]], 'excess lookback' => [['late_lookback_days' => 366]],
    'zero cap' => [['receipt_maximum_kobo' => 0]], 'cap overflow' => [['receipt_maximum_kobo' => 1000000000000]],
    'minimum exceeds cap' => [['receipt_minimum_kobo' => 200, 'receipt_maximum_kobo' => 100]],
    'credential URL' => [['website' => 'https://user:password@example.org']], 'local URL' => [['website' => 'https://127.0.0.1/path']],
    'bad email' => [['support_email' => 'invalid']], 'bad phone' => [['support_phone' => '08000000000']],
]);
test('unexpected top-level input is not silently discarded', function () {
    $actor = configurationManager();
    app(BusinessSettings::class)->import();
    $this->actingAs($actor)->post(route('admin.business-settings.drafts.store'), ['operation_id' => (string) Str::uuid(), 'base_version' => 1,
        'patch' => ['display_name' => 'Valid'], 'business_id' => 'injected'])->assertSessionHasErrors('business_id');
    $this->assertDatabaseCount('business_configuration_drafts', 0);
});
test('scheduled configuration catches up once and does not take effect before due time', function () {
    $this->freezeTime();
    $actor = configurationManager();
    $draft = configurationDraft($actor, ['page_size' => 50], now()->addHour()->toIso8601String());
    publishConfiguration($actor, $draft);
    expect(app(BusinessSettings::class)->resolve()['values']['page_size'])->toBe(25);
    expect(app(BusinessSettings::class)->drain())->toBe(0);
    $this->travel(61)->minutes();
    expect(app(BusinessSettings::class)->drain())->toBe(1)->and(app(BusinessSettings::class)->drain())->toBe(0);
    expect(app(BusinessSettings::class)->resolve()['values']['page_size'])->toBe(50);
});
test('scheduled cancellation retains immutable version and prevents activation', function () {
    $actor = configurationManager();
    $draft = configurationDraft($actor, ['page_size' => 50], now()->addHour()->toIso8601String());
    $result = publishConfiguration($actor, $draft);
    app(BusinessSettings::class)->cancel($actor, $result['configuration_id'], 'No longer needed', (string) Str::uuid(), configurationRequest());
    $this->travel(2)->hours();
    expect(app(BusinessSettings::class)->drain())->toBe(0);
    $this->assertDatabaseCount('business_configuration_versions', 2);
    expect(BusinessProfile::current()->version)->toBe(1);
});
test('overlapping pending bundles require resolution before another publication', function () {
    $actor = configurationManager();
    $first = configurationDraft($actor, ['page_size' => 50], now()->addHour()->toIso8601String());
    publishConfiguration($actor, $first);
    $second = configurationDraft($actor, ['display_name' => 'Another']);
    expect(fn () => publishConfiguration($actor, $second))->toThrow(ConflictHttpException::class);
});
test('consumer failure leaves propagation blocked and prevents effective success', function () {
    $actor = configurationManager();
    $draft = configurationDraft($actor, ['display_name' => 'New name']);
    $this->partialMock(BusinessSettingsReadiness::class, fn ($mock) => $mock->shouldReceive('acknowledge')->andReturn(false));
    $result = publishConfiguration($actor, $draft);
    $this->assertDatabaseHas('business_configuration_work', ['configuration_id' => $result['configuration_id'], 'status' => 'blocked']);
    expect(BusinessProfile::current()->display_name)->toBe('SaverApp');
    $this->assertDatabaseCount('business_configuration_acknowledgements', 0);
});
test('canonical audit failure rolls publication and outbox back together', function () {
    $actor = configurationManager();
    $draft = configurationDraft($actor, ['display_name' => 'New name']);
    $this->mock(AuditCapture::class, fn ($mock) => $mock->shouldReceive('record')->andThrow(new RuntimeException('Audit unavailable')));
    expect(fn () => publishConfiguration($actor, $draft))->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('business_configuration_versions', 1);
    $this->assertDatabaseCount('business_settings_notification_intents', 0);
    expect(BusinessConfigurationDraft::query()->sole()->status)->toBe('draft');
});
test('rollback publishes a new higher version and retains intervening values', function () {
    $actor = configurationManager();
    $original = app(BusinessSettings::class)->import();
    publishConfiguration($actor, configurationDraft($actor, ['display_name' => 'New name']));
    $operation = (string) Str::uuid();
    $rollback = app(BusinessSettings::class)->rollbackDraft($actor, $original->id, $operation);
    $originalResult = $rollback;
    $rollback['preview'] = app(BusinessSettings::class)->preview($actor, $rollback['draft_id'], $rollback['revision'], null);
    publishConfiguration($actor, $rollback);
    expect(app(BusinessSettings::class)->rollbackDraft($actor, $original->id, $operation))->toBe($originalResult);
    expect(BusinessProfile::current()->version)->toBe(3)->and(BusinessProfile::current()->display_name)->toBe('SaverApp');
    expect(BusinessConfigurationVersion::query()->where('version', 2)->sole()->values['display_name'])->toBe('New name');
});
test('published evidence rejects model and direct database mutation', function () {
    $version = app(BusinessSettings::class)->import();
    expect(fn () => $version->update(['source' => 'tampered']))->toThrow(LogicException::class);
    expect(fn () => DB::table('business_configuration_versions')->update(['source' => 'tampered']))->toThrow(QueryException::class);
    expect(fn () => DB::table('business_configuration_versions')->delete())->toThrow(QueryException::class);
    expect(fn () => DB::table('business_profiles')->update(['business_id' => 'BUS-OTHER']))->toThrow(QueryException::class);
});
test('another business cannot be inserted even using an alternate singleton key', function () {
    expect(fn () => DB::table('business_profiles')->insert(['business_id' => 'BUS-OTHER', 'display_name' => 'Other', 'singleton_key' => 2]))->toThrow(QueryException::class);
    expect(BusinessProfile::query()->count())->toBe(1);
});
test('configuration notice recipients are rechecked after grant revocation', function () {
    $actor = configurationManager();
    publishConfiguration($actor, configurationDraft($actor, ['display_name' => 'New name']));
    $intent = DB::table('notification_inbox_intents')->first();
    $actor->revokePermissionTo(AdminPermission::BusinessSettingsManage);
    expect(app(NotificationPipeline::class)->recipientScope($actor)->where('i.id', $intent->id)->exists())->toBeFalse();
    expect($intent->summary)->not->toContain('Reviewed prospective change');
});
test('configuration import does not certify environment-enabled financial features', function () {
    config(['collections.enabled' => true]);
    app(BusinessSettings::class)->import();
    expect(fn () => app(BusinessSettings::class)->ensureFeature('collections'))->toThrow(HttpException::class);
    $this->assertDatabaseCount('collection_receipts', 0);
});
test('collection limits require a future business midnight', function () {
    $actor = configurationManager();
    expect(fn () => configurationDraft($actor, ['late_lookback_days' => 0]))->toThrow(ValidationException::class);
    $at = now()->setTimezone('Africa/Lagos')->addDay()->startOfDay()->utc()->toIso8601String();
    $draft = configurationDraft($actor, ['late_lookback_days' => 365], $at);
    publishConfiguration($actor, $draft);
    expect(app(BusinessSettings::class)->collectionLimits()['values']['late_lookback_days'])->toBe(30);
    $this->travelTo(CarbonImmutable::parse($at)->addMinute());
    expect(fn () => app(BusinessSettings::class)->collectionLimits())->toThrow(HttpException::class);
    app(BusinessSettings::class)->drain();
    expect(app(BusinessSettings::class)->collectionLimits()['values']['late_lookback_days'])->toBe(365);
});
test('presentation defaults change new queries while explicit filters still win', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-26 12:00:00', 'Africa/Lagos'));
    $actor = configurationManager();
    publishConfiguration($actor, configurationDraft($actor, ['dashboard_activity_range' => 'week', 'page_size' => 50, 'week_start' => 'Sunday']));
    $this->actingAs($actor)->get(route('admin.dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('filters.from', '2026-09-20')->where('filters.page_size', 50));
    $this->get(route('admin.dashboard', ['period' => 'today', 'page_size' => 25]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('filters.from', '2026-09-26')->where('filters.page_size', '25'));
});
test('disabling collections blocks new entry while posted receipt history and reconciliation stay available', function () {
    config(['collections.enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(1, slotAmountKobo: 200000);
    $collection = app(CollectionService::class);
    $payload = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $payload['preview_fingerprint'] = $collection->preview($agent, $customer, $payload)['preview_fingerprint'];
    $receipt = $collection->record($agent, $customer, $payload);
    $ledger = [DB::table('ledger_posting_groups')->count(), DB::table('ledger_entries')->count()];

    app(BusinessSettings::class)->import();

    $this->actingAs($agent)->get(route('customers.collections.create', $customer))->assertStatus(503);
    $this->actingAs($agent)->post(route('customers.collections.store', $customer), collectionPayload($customer, $assignment, $plan, $date, '2000.00'))->assertStatus(503);
    $this->actingAs($agent)->get(route('collections.show', $receipt))->assertOk();
    $this->actingAs($agent)->get(route('collection-batches.show', $receipt->collection_batch_id))->assertOk();
    $this->assertDatabaseCount('collection_receipts', 1);
    expect([DB::table('ledger_posting_groups')->count(), DB::table('ledger_entries')->count()])->toBe($ledger);
});
