<?php

use App\Enums\PlatformMode;
use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\DeliverCollectionNotificationIntent;
use App\Models\User;
use App\Services\CollectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../CollectionFixtures.php';

beforeEach(function (): void {
    config()->set('collections.enabled', true);
});

/** @return array<string, string> */
function collectionReadFinancialSources(): array
{
    $sources = [];
    foreach (['ledger_posting_groups', 'ledger_entries', 'collection_receipts', 'collection_allocations',
        'collection_fee_components', 'collection_batches', 'withdrawal_reservations', 'fee_obligations',
        'fee_obligation_entries', 'thrift_plans', 'plan_terms_revisions', 'contribution_slots'] as $table) {
        $sources[$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }

    return $sources;
}

test('COL-AC-059: authoritative mapping failure returns safe 503 read shapes and restores real balances', function (string $endpoint, string $shape): void {
    $this->freezeTime();
    Queue::fake([DeliverCollectionNotificationIntent::class]);
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $payload);
    $url = $endpoint === 'card' ? route('plans.card', $plan) : route('collections.show', $receipt);
    $headers = match ($shape) {
        'inertia' => ['X-Inertia' => 'true', 'Accept' => 'text/html',
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/'))],
        'json' => ['Accept' => 'application/json'],
        default => [],
    };
    $original = collectionReadFinancialSources();
    DB::table('ledger_accounts')->where('code', 'customer_savings_liability_ngn')->update(['mapping_status' => 'unmapped']);

    $response = $this->actingAs($agent)->get($url, $headers);

    $response->assertServiceUnavailable();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    if ($shape === 'json') {
        $response->assertExactJson([
            'message' => PlatformMode::Unavailable->message(),
            'error_code' => 'collection_read_unavailable',
            'correlation_reference' => $response->json('correlation_reference'),
        ]);
        expect($response->json('correlation_reference'))->toBeString()->not->toBeEmpty();
    } elseif ($shape === 'inertia') {
        $response->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'PlatformUnavailable')
            ->assertJsonPath('props.message', PlatformMode::Unavailable->message())
            ->assertJsonPath('props.error_code', 'collection_read_unavailable');
        expect(array_keys($response->json('props')))->toBe(['message', 'error_code', 'correlation_reference']);
    } else {
        $response->assertInertia(fn (Assert $page) => $page->component('PlatformUnavailable')
            ->where('message', PlatformMode::Unavailable->message())->where('error_code', 'collection_read_unavailable')
            ->has('correlation_reference')->missing('auth')->missing('platform')->missing('customer')
            ->missing('customer_id')->missing('card')->missing('receipt')->missing('position')
            ->missing('can_record')->missing('liability_kobo')->missing('available_kobo'));
    }
    $response->assertDontSee($customer->user->name)->assertDontSee($customer->customer_id)
        ->assertDontSee('Savings account mapping is unavailable')->assertDontSee('ledger_accounts');
    expect(collectionReadFinancialSources())->toBe($original);
    DB::table('ledger_accounts')->where('code', 'customer_savings_liability_ngn')->update(['mapping_status' => 'mapped']);

    $this->get($url)->assertInertia(fn (Assert $page) => $page
        ->component($endpoint === 'card' ? 'collections/Card' : 'collections/Show')
        ->where(($endpoint === 'card' ? 'card' : 'receipt').'.position', [
            'liability_kobo' => 200000, 'reservations_kobo' => 0, 'available_kobo' => 200000,
        ]));
    expect(collectionReadFinancialSources())->toBe($original);
})->with(['thrift card' => ['card'], 'receipt detail' => ['receipt']])
    ->with(['direct browser' => ['direct'], 'Inertia request' => ['inertia'], 'JSON request' => ['json']]);

test('COL-AC-059: authoritative reservation and entry integrity failure returns safe 503 without financial writes', function (string $endpoint, string $damage): void {
    $this->freezeTime();
    Queue::fake([DeliverCollectionNotificationIntent::class]);
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $payload);
    if ($damage === 'entry') {
        DB::table('ledger_entries')->where('customer_profile_id', $customer->id)->where('side', 'credit')->update(['amount_kobo' => 0]);
    } else {
        DB::table('withdrawal_reservations')->insert([
            'customer_profile_id' => $customer->id, 'owner_reference' => 'private-invalid-reservation',
            'gross_amount_kobo' => 0, 'status' => 'live', 'version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $before = collectionReadFinancialSources();
    $url = $endpoint === 'card' ? route('plans.card', $plan) : route('collections.show', $receipt);

    $this->actingAs($agent)->get($url, ['X-Inertia' => 'true', 'Accept' => 'text/html',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/'))])
        ->assertServiceUnavailable()->assertJsonPath('component', 'PlatformUnavailable')
        ->assertJsonPath('props.error_code', 'collection_read_unavailable')
        ->assertJsonMissingPath('props.auth')->assertJsonMissingPath('props.card')->assertJsonMissingPath('props.receipt')
        ->assertDontSee('private-invalid-reservation')->assertDontSee('integrity is unavailable');

    expect(collectionReadFinancialSources())->toBe($before);
})->with(['thrift card' => ['card'], 'receipt detail' => ['receipt']])
    ->with(['invalid ledger entry' => ['entry'], 'invalid live reservation' => ['reservation']]);

test('COL-AC-059: out of scope collection reads remain 404 during an authoritative outage', function (string $endpoint): void {
    $this->freezeTime();
    Queue::fake([DeliverCollectionNotificationIntent::class]);
    [$agent, $customer, $assignment, $plan, $today] = collectionFixture();
    $payload = collectionPayload($customer, $assignment, $plan, $today, '2000.00');
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $payload);
    DB::table('ledger_accounts')->where('code', 'customer_savings_liability_ngn')->update(['mapping_status' => 'unmapped']);
    $foreignAgent = User::factory()->agent()->withTwoFactor()->create();
    $before = collectionReadFinancialSources();
    $url = $endpoint === 'card' ? route('plans.card', $plan) : route('collections.show', $receipt);

    $this->actingAs($foreignAgent)->getJson($url)->assertNotFound()
        ->assertDontSee('collection_read_unavailable')->assertDontSee($customer->customer_id);

    expect(collectionReadFinancialSources())->toBe($before);
})->with(['thrift card' => ['card'], 'receipt detail' => ['receipt']]);
