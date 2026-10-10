<?php

use App\Enums\AdminPermission;
use App\Models\BusinessConfigurationVersion;
use App\Models\BusinessProfile;
use App\Models\CollectionReceipt;
use App\Models\User;
use App\Services\BusinessSettings;
use App\Services\CollectionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

require_once __DIR__.'/../CollectionFixtures.php';

beforeEach(function (): void {
    config()->set('collections.enabled', true);
    config()->set('collections.local_certified', true);
});

function cfgClosureRequest(): Request
{
    $request = Request::create('/admin/business-settings', 'POST');
    $request->setLaravelSession(app('session')->driver());
    $request->session()->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);

    return $request;
}

/** @param array<string, mixed> $patch
 * @return array<string, mixed>
 */
function cfgClosureDraft(User $actor, array $patch, ?string $effectiveAt = null): array
{
    $settings = app(BusinessSettings::class);
    $draft = $settings->saveDraft($actor, $patch, BusinessProfile::current()->version, (string) Str::uuid());

    return [...$draft, 'preview' => $settings->preview($actor, $draft['draft_id'], $draft['revision'], $effectiveAt)];
}

/** @param array<string, mixed> $draft */
function cfgClosurePublish(User $actor, array $draft): void
{
    app(BusinessSettings::class)->publish($actor, $draft['draft_id'], $draft['revision'], $draft['preview']['reference'],
        'Reviewed prospective change', (string) Str::uuid(), cfgClosureRequest());
}

function cfgClosureManager(): User
{
    $actor = User::factory()->admin()->withTwoFactor()->create();
    $actor->givePermissionTo(AdminPermission::BusinessSettingsManage);
    app(BusinessSettings::class)->import();
    cfgClosurePublish($actor, cfgClosureDraft($actor, ['collections' => true]));
    cfgClosurePublish($actor, cfgClosureDraft($actor, ['collection_cash' => true]));

    return $actor;
}

/** @param array<string, int> $patch */
function cfgClosurePublishLimits(object $test, User $actor, array $patch): void
{
    $midnight = CarbonImmutable::now('Africa/Lagos')->addDay()->startOfDay();
    cfgClosurePublish($actor, cfgClosureDraft($actor, $patch, $midnight->utc()->toIso8601String()));
    $test->travelTo($midnight->addMinute());
    app(BusinessSettings::class)->drain();
}

/** @return array<string, mixed> */
function cfgClosurePreview(User $agent, mixed $customer, mixed $assignment, mixed $plan, string $date, string $amount, string $lateReason = ''): array
{
    $payload = collectionPayload($customer->refresh(), $assignment->refresh(), $plan->refresh(), $date, $amount);
    $payload['business_version'] = BusinessProfile::current()->version;
    $payload['late_reason'] = $lateReason;

    return app(CollectionService::class)->preview($agent, $customer, $payload);
}

test('CFG-AC-010: every non-midnight operational boundary is rejected and the effective version is kept', function (string $boundary): void {
    $actor = cfgClosureManager();
    $before = app(BusinessSettings::class)->resolve();

    expect(fn () => cfgClosureDraft($actor, ['day_boundary' => $boundary]))->toThrow(ValidationException::class);

    expect(app(BusinessSettings::class)->resolve())->toBe($before)
        ->and($before['values']['day_boundary'])->toBe('00:00');
    $this->assertDatabaseCount('business_configuration_drafts', 2);
})->with(['quarter past' => '00:15', 'half past' => '00:30', 'quarter to' => '23:45', 'early morning' => '06:00', 'seconds' => '00:00:01', 'offset' => '00:00+01:00']);

test('CFG-AC-016: receipt minimum, maximum and maximum plus one are enforced and a lower cap never rewrites earlier receipts', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00', 'Africa/Lagos'));
    $actor = cfgClosureManager();
    cfgClosurePublishLimits($this, $actor, ['receipt_minimum_kobo' => 10000, 'receipt_maximum_kobo' => 500000]);
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture(10, slotAmountKobo: 100000);
    $today = CarbonImmutable::now('Africa/Lagos')->toDateString();

    expect(fn () => cfgClosurePreview($agent, $customer, $assignment, $plan, $today, '99.99'))->toThrow(ValidationException::class)
        ->and(fn () => cfgClosurePreview($agent, $customer, $assignment, $plan, $today, '5000.01'))->toThrow(ValidationException::class);
    expect(cfgClosurePreview($agent, $customer, $assignment, $plan, $today, '100.00')['savings_kobo'])->toBe(10000)
        ->and(cfgClosurePreview($agent, $customer, $assignment, $plan, $today, '5000.00')['savings_kobo'])->toBe(500000);

    $payload = collectionPayload($customer->refresh(), $assignment->refresh(), $plan->refresh(), $today, '4000.00');
    $payload['business_version'] = BusinessProfile::current()->version;
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $payload);
    $ledger = [DB::table('ledger_posting_groups')->count(), DB::table('ledger_entries')->count()];

    cfgClosurePublishLimits($this, $actor, ['receipt_maximum_kobo' => 200000]);

    $today = CarbonImmutable::now('Africa/Lagos')->toDateString();
    expect(fn () => cfgClosurePreview($agent, $customer, $assignment, $plan, $today, '2000.01'))->toThrow(ValidationException::class);
    expect(CollectionReceipt::query()->sole()->savings_amount_kobo)->toBe(400000)
        ->and(CollectionReceipt::query()->sole()->id)->toBe($receipt->id)
        ->and([DB::table('ledger_posting_groups')->count(), DB::table('ledger_entries')->count()])->toBe($ledger)
        ->and(BusinessConfigurationVersion::query()->orderBy('version')->pluck('values')->map(fn (array $values): int => $values['receipt_maximum_kobo'])->all())
        ->toBe([999999999999, 999999999999, 999999999999, 500000, 200000]);
});

test('CFG-AC-017: late lookback 0, 30 and 365 bound received dates without bypassing future-date or period owners', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00', 'Africa/Lagos'));
    $actor = cfgClosureManager();
    [$agent, $customer, $assignment, $plan] = collectionFixture(5, startOffsetDays: -45, slotAmountKobo: 100000);
    $preview = fn (string $date, string $reason = 'Customer paid while the Agent was offline.') => cfgClosurePreview($agent, $customer, $assignment, $plan, $date, '1000.00', $reason);
    $localToday = fn (): CarbonImmutable => CarbonImmutable::now('Africa/Lagos')->startOfDay();

    expect(fn () => $preview($localToday()->subDays(31)->toDateString()))->toThrow(ValidationException::class);
    expect($preview($localToday()->subDays(30)->toDateString())['received_date'])->toBe($localToday()->subDays(30)->toDateString());
    expect(fn () => $preview($localToday()->subDay()->toDateString(), ''))->toThrow(ValidationException::class);

    cfgClosurePublishLimits($this, $actor, ['late_lookback_days' => 0]);
    expect(fn () => $preview($localToday()->subDay()->toDateString()))->toThrow(ValidationException::class)
        ->and(fn () => $preview($localToday()->addDay()->toDateString()))->toThrow(ValidationException::class);
    expect($preview($localToday()->toDateString(), '')['received_date'])->toBe($localToday()->toDateString());

    cfgClosurePublishLimits($this, $actor, ['late_lookback_days' => 365]);
    expect(fn () => $preview($localToday()->addDay()->toDateString()))->toThrow(ValidationException::class);
    expect(fn () => $preview($localToday()->subDays(200)->toDateString()))->toThrow(ConflictHttpException::class, 'The cash receipt month is not open for posting.');
    expect(fn () => $preview($localToday()->subDays(366)->toDateString()))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('collection_receipts', 0);
});

test('CFG-AC-032: malformed values reject the whole draft with safe field errors', function (array $patch, string $field): void {
    $actor = cfgClosureManager();
    $before = app(BusinessSettings::class)->resolve();

    try {
        app(BusinessSettings::class)->saveDraft($actor, $patch, $before['version'], (string) Str::uuid());
        $this->fail('The malformed draft was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
        expect(json_encode($exception->errors(), JSON_THROW_ON_ERROR))->not->toContain('password')->not->toContain('secret-value');
    }

    expect(app(BusinessSettings::class)->resolve())->toBe($before);
    $this->assertDatabaseCount('business_configuration_drafts', 2);
})->with([
    'control character' => [['display_name' => "Saver\u{0007}App", 'page_size' => 50], 'display_name'],
    'bidirectional override' => [['display_name' => "Saver\u{202E}ppA"], 'display_name'],
    'invalid UTF-8' => [['legal_name' => "\xC3\x28"], 'legal_name'],
    'invalid UTF-8 beside a valid change' => [['display_name' => 'Valid', 'address' => "\xFF"], 'address'],
    'markup in address' => [['address' => '<img src=x onerror=alert(1)>'], 'address'],
    'non-HTTPS website' => [['website' => 'http://saverapp.ng'], 'website'],
    'javascript website' => [['website' => 'javascript:alert(1)'], 'website'],
    'local website' => [['website' => 'https://intranet.local'], 'website'],
    'oversized name' => [['display_name' => str_repeat('a', 151)], 'display_name'],
    'oversized address' => [['address' => str_repeat('a', 501)], 'address'],
    'unknown logo asset' => [['logo_reference' => str_repeat('a', 64)], 'logo_reference'],
    'malformed logo reference' => [['logo_reference' => '../../etc/passwd'], 'logo_reference'],
    'decimal money string' => [['receipt_minimum_kobo' => '100.00'], 'receipt_minimum_kobo'],
    'float money' => [['receipt_maximum_kobo' => 1e12], 'receipt_maximum_kobo'],
    'boolean money' => [['receipt_minimum_kobo' => true], 'receipt_minimum_kobo'],
    'array value' => [['display_name' => ['nested' => 'value']], 'display_name'],
    'string flag' => [['collections' => 'true'], 'collections'],
    'unsupported page size' => [['page_size' => 75], 'page_size'],
    'unsupported range' => [['report_range' => 'year'], 'report_range'],
    'unsupported week start' => [['week_start' => 'Saturday'], 'week_start'],
    'unsupported export' => [['export_format' => 'xlsx'], 'export_format'],
    'unknown code' => [['secret_token' => 'secret-value'], 'secret_token'],
]);

test('CFG-AC-032: a past, immediate or non-midnight effective time for a collection limit fails without a preview', function (?string $effectiveAt): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00', 'Africa/Lagos'));
    $actor = cfgClosureManager();
    $settings = app(BusinessSettings::class);
    $draft = $settings->saveDraft($actor, ['late_lookback_days' => 10], $settings->resolve()['version'], (string) Str::uuid());

    expect(fn () => $settings->preview($actor, $draft['draft_id'], $draft['revision'], $effectiveAt))->toThrow(ValidationException::class);
    expect(DB::table('business_configuration_drafts')->where('id', $draft['draft_id'])->value('preview'))->toBeNull();
})->with([
    'immediate' => [null],
    'past midnight' => ['2026-09-14T23:00:00Z'],
    'future quarter hour' => ['2026-09-16T23:15:00Z'],
    'future UTC midnight' => ['2026-09-17T00:00:00Z'],
]);
