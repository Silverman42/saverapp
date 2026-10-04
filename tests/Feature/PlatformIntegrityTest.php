<?php

use App\Models\CollectionReceipt;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\PlatformDiagnostics;
use App\Services\PlatformGuard;
use App\Services\PlatformIntegrity;
use App\Support\PlatformBlocked;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/../WithdrawalFixtures.php';
require_once __DIR__.'/../CollectionFixtures.php';

/** @return array{0: User, 1: CollectionReceipt} */
function integrityReceiptFixture(): array
{
    config()->set(['collections.enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(1, slotAmountKobo: 200000);
    $collection = app(CollectionService::class);
    $payload = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $payload['preview_fingerprint'] = $collection->preview($agent, $customer, $payload)['preview_fingerprint'];

    return [$agent, $collection->record($agent, $customer, $payload)];
}

test('a clean cutoff passes every invariant and leaves financial writes open', function (): void {
    integrityReceiptFixture();

    $run = app(PlatformIntegrity::class)->verify('ops-service');

    expect($run['status'])->toBe('passed')
        ->and(collect($run['results'])->pluck('status')->unique()->all())->toBe(['passed'])
        ->and(app(PlatformDiagnostics::class)->report()['checks']['integrity']['state'])->toBe('Ready');
    app(PlatformGuard::class)->assertAllowed('financial');
});

test('each corrupted invariant blocks financial writes but not other work', function (string $domain, Closure $corrupt): void {
    [, $receipt] = integrityReceiptFixture();
    $groups = LedgerPostingGroup::query()->count();
    $corrupt($receipt);

    $run = app(PlatformIntegrity::class)->verify('ops-service');

    expect($run['status'])->toBe('failed')->and($run['failed_domains'])->toContain($domain);
    expect(fn () => app(PlatformGuard::class)->assertAllowed('financial'))->toThrow(PlatformBlocked::class);
    app(PlatformGuard::class)->assertAllowed('mutation');
    app(PlatformGuard::class)->assertAllowed('read');
    expect(LedgerPostingGroup::query()->count())->toBe($groups)
        ->and(app(PlatformDiagnostics::class)->report()['checks']['integrity']['state'])->toBe('Failed');
})->with([
    'ledger' => ['ledger', fn (CollectionReceipt $receipt) => DB::table('ledger_entries')->where('ledger_posting_group_id', $receipt->savings_posting_group_id)->orderBy('id')->limit(1)->delete()],
    'collections' => ['collections', fn (CollectionReceipt $receipt) => DB::table('collection_receipts')->where('id', $receipt->id)->update(['tender_amount_kobo' => 1])],
    'reservations' => ['reservations', fn (CollectionReceipt $receipt) => DB::table('withdrawal_reservations')->insert(['customer_profile_id' => $receipt->customer_profile_id,
        'owner_reference' => 'corrupt', 'gross_amount_kobo' => 0, 'status' => 'live', 'version' => 1, 'created_at' => now(), 'updated_at' => now()])],
    'identity' => ['identity', fn (CollectionReceipt $receipt) => DB::table('users')
        ->where('id', DB::table('customer_profiles')->where('id', $receipt->customer_profile_id)->value('user_id'))->update(['user_type' => 'agent'])],
    'work' => ['work', fn () => DB::table('audit_projection_state')->where('id', 1)->update(['watermark' => 999999])],
]);

test('only a newer passing live run lifts the restriction', function (): void {
    [, $receipt] = integrityReceiptFixture();
    $integrity = app(PlatformIntegrity::class);
    DB::table('collection_receipts')->where('id', $receipt->id)->update(['tender_amount_kobo' => 1]);
    $integrity->verify('ops-service');
    DB::table('collection_receipts')->where('id', $receipt->id)->update(['tender_amount_kobo' => $receipt->tender_amount_kobo]);

    expect($integrity->blockedDomains())->toBe(['collections']);
    expect($integrity->verify('ops-service')['status'])->toBe('passed')
        ->and($integrity->blockedDomains())->toBe([]);
});

test('integrity runs are immutable evidence', function (): void {
    app(PlatformIntegrity::class)->verify('ops-service');

    expect(fn () => DB::table('platform_integrity_runs')->update(['status' => 'passed']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('platform_integrity_runs')->delete())->toThrow(QueryException::class);
});

test('the command requires an operator and reports a failed run as failure', function (): void {
    $this->artisan('platform:verify-integrity')->assertFailed();
    $this->artisan('platform:verify-integrity', ['--operator' => 'ops-service', '--json' => true])->assertSuccessful();
    DB::table('audit_projection_state')->where('id', 1)->update(['watermark' => 999999]);
    $this->artisan('platform:verify-integrity', ['--operator' => 'ops-service', '--json' => true])->assertFailed();

    expect(DB::table('platform_integrity_runs')->count())->toBe(2);
});

test('projection promotion verifies a new version and repeats without source effects', function (): void {
    integrityReceiptFixture();
    $sources = fn (): array => [LedgerPostingGroup::query()->count(), DB::table('ledger_entries')->count(), DB::table('collection_receipts')->count(),
        DB::table('notification_inbox_intents')->count(), DB::table('withdrawal_requests')->count()];
    $before = $sources();
    $options = ['--operator' => 'ops-service', '--reason' => 'Index rebuild', '--incident' => 'INC-200', '--json' => true];

    foreach (['ledger', 'audit'] as $projection) {
        $table = $projection.'_projection_state';
        $version = (int) DB::table($table)->value('active_version');
        $this->artisan('platform:promote-projection', ['projection' => $projection, '--expected-version' => $version] + $options)->assertSuccessful();
        $this->artisan('platform:promote-projection', ['projection' => $projection, '--expected-version' => $version + 1] + $options)->assertSuccessful();
        $this->artisan('platform:promote-projection', ['projection' => $projection, '--expected-version' => $version] + $options)->assertFailed();
        expect((int) DB::table($table)->value('active_version'))->toBe($version + 2);
    }
    $ledgerVersion = (int) DB::table('ledger_projection_state')->value('active_version');

    expect($sources())->toBe($before)
        ->and(DB::table('ledger_transaction_projections')->where('projection_version', $ledgerVersion)->count())
        ->toBe(DB::table('ledger_transaction_projections')->where('projection_version', $ledgerVersion - 1)->count());
});

test('a failed promotion keeps the last verified projection version active', function (): void {
    $actor = User::factory()->agent()->create();
    LedgerPostingGroup::create(['posting_reference' => 'TEST-UNSUPPORTED', 'idempotency_key' => 'unsupported-1', 'payload_hash' => str_repeat('e', 64),
        'source_type' => 'unsupported_event', 'source_id' => '1', 'event_type' => 'unsupported_event', 'currency' => 'NGN',
        'actor_user_id' => $actor->id, 'occurred_at' => now(), 'committed_at' => now()]);
    $version = (int) DB::table('ledger_projection_state')->value('active_version');

    $this->artisan('platform:promote-projection', ['projection' => 'ledger', '--expected-version' => $version,
        '--operator' => 'ops-service', '--reason' => 'Index rebuild', '--incident' => 'INC-200'])->assertFailed();

    expect((int) DB::table('ledger_projection_state')->value('active_version'))->toBe($version);
});

test('no route offers a direct balance reservation ledger audit or reconciliation edit', function (): void {
    $direct = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [])
        ->map(fn ($route) => $route->getName() ?? $route->uri())
        ->filter(fn (string $name) => preg_match('/(balance|reservation|ledger|audit|reconciliation)[^.]*\.(update|edit|destroy|adjust|set)$/i', $name) === 1
            || preg_match('/(adjust|suspense)/i', $name) === 1);

    expect($direct->values()->all())->toBe([]);
});
