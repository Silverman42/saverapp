<?php

use App\Enums\AdminPermission;
use App\Enums\CustomerStatus;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\CustomerStatusManagementService;
use App\Services\FeeSavingsApplicationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

require_once __DIR__.'/../WithdrawalFixtures.php';
require_once __DIR__.'/../FeeFixtures.php';
require_once __DIR__.'/../CashExecutionFixtures.php';

class LosePreparedFeeAttemptResponse
{
    public static bool $enabled = false;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (self::$enabled && $response->isSuccessful()) {
            self::$enabled = false;
            throw new RuntimeException('The prepared attempt response did not reach the caller.');
        }

        return $response;
    }
}

/** @return array{User, FeeObligation, array<string, mixed>, string} */
function durableFeeAttemptFixture(string $operation): array
{
    [$agent, $customer, , $plan] = withdrawalFixture();
    FinancialPeriod::factory()->create(['month' => now('Africa/Lagos')->startOfMonth()->toDateString()]);
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $fee = reportFeeObligation($agent, $customer, 20000);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $payload = ['attempt_reference' => (string) Str::uuid(), 'reason' => 'PRIVATE reviewed original fee instruction.',
        'customer_description' => 'Your registration agreement was reviewed.', 'confirmed' => true];
    if ($operation === 'apply_savings') {
        config()->set('fees.savings_applications_enabled', true);
        $payload['plan_id'] = $plan->plan_id;
        $quote = app(FeeSavingsApplicationService::class)->preview($admin, $fee->id, $payload);
        $payload['preview_fingerprint'] = $quote['preview_fingerprint'];
        $payload['quote_expires_at'] = $quote['quote_expires_at'];
    } else {
        $payload['amount_ngn'] = '100.00';
        if ($operation === 'correct') {
            $payload['direction'] = 'reduce';
        }
    }
    $route = $operation === 'apply_savings' ? 'apply-savings' : $operation;

    return [$admin, $fee, $payload, route('admin.fees.obligations.'.$route, $fee->id)];
}

/** @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function durableFeeAttemptBody(string $operation, array $payload): array
{
    return ['operation' => $operation, 'attempt_reference' => $payload['attempt_reference'], 'payload' => $payload];
}

/** @param array<string, mixed> $payload */
function durableFeeSuccessfulCommit(TestCase $test, string $operation, string $url, array $payload): TestResponse
{
    if ($operation === 'apply_savings') {
        return $test->postJson($url, $payload)->assertOk()->assertJsonPath('status', 'posted');
    }

    return $test->post($url, $payload)->assertRedirect()->assertSessionHasNoErrors();
}

/** @return array<string, array<int, object>> */
function durableFeeAttemptFinancialRows(): array
{
    $rows = [];
    foreach (['fee_snapshots', 'fee_obligations', 'fee_obligation_entries', 'fee_obligation_events',
        'fee_savings_applications', 'ledger_posting_groups', 'ledger_entries', 'collection_receipts',
        'collection_allocations', 'collection_fee_components', 'withdrawal_requests', 'withdrawal_reservations',
        'thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'fee_obligation_notification_intents',
        'fee_application_notification_intents'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return $rows;
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 09:00:00', 'UTC'));
});

test('lost preparation response replays one immutable attempt with no financial effect', function (string $operation): void {
    [$admin, $fee, $payload] = durableFeeAttemptFixture($operation);
    $body = durableFeeAttemptBody($operation, $payload);
    $prepareUrl = route('admin.fees.obligations.attempts.prepare', $fee->id);
    Route::getRoutes()->getByName('admin.fees.obligations.attempts.prepare')->middleware(LosePreparedFeeAttemptResponse::class);
    $before = durableFeeAttemptFinancialRows();
    LosePreparedFeeAttemptResponse::$enabled = true;
    try {
        $this->actingAs($admin)->withSession(cashSession())->postJson($prepareUrl, $body)->assertServerError();
    } finally {
        LosePreparedFeeAttemptResponse::$enabled = false;
    }
    $retained = DB::table('fee_action_attempts')->sole();
    $this->travel(11)->minutes();

    $response = $this->withSession(cashSession())->postJson($prepareUrl, $body)
        ->assertOk()->assertJsonPath('status', 'prepared')->assertJsonPath('operation', $operation)
        ->assertJsonPath('attempt_reference', $payload['attempt_reference'])->assertJsonPath('obligation_id', $fee->id);

    expect($response->getContent())->not->toContain('PRIVATE', $payload['customer_description']);
    expect(DB::table('fee_action_attempts')->sole())->toEqual($retained);
    expect(durableFeeAttemptFinancialRows())->toEqual($before);
})->with(['waive', 'correct', 'apply_savings']);

test('prepared attempt rejects changed instruction actor and obligation with 409 and retains its original binding', function (string $change): void {
    [$admin, $fee, $payload, $commitUrl] = durableFeeAttemptFixture('correct');
    $prepareUrl = route('admin.fees.obligations.attempts.prepare', $fee->id);
    $this->actingAs($admin)->withSession(cashSession())->postJson($prepareUrl, durableFeeAttemptBody('correct', $payload))->assertOk();
    $operation = 'correct';
    if ($change === 'operation') {
        $operation = 'waive';
        unset($payload['direction']);
        $commitUrl = route('admin.fees.obligations.waive', $fee->id);
    } elseif ($change === 'actor') {
        $foreign = User::factory()->admin()->withTwoFactor()->create();
        $foreign->givePermissionTo(AdminPermission::FeesManage);
        $this->actingAs($foreign);
    } elseif ($change === 'obligation') {
        $agent = User::query()->findOrFail($fee->created_by_user_id);
        $customer = CustomerProfile::factory()->create();
        $other = reportFeeObligation($agent, $customer, 20000, 2);
        $prepareUrl = route('admin.fees.obligations.attempts.prepare', $other->id);
    } else {
        $payload[$change] = match ($change) {
            'amount_ngn' => '101.00',
            'direction' => 'increase',
            'reason' => 'Different private reason.',
            default => 'Different Customer wording.',
        };
    }
    $before = durableFeeAttemptFinancialRows();
    $retained = DB::table('fee_action_attempts')->get()->all();

    $this->postJson($prepareUrl, durableFeeAttemptBody($operation, $payload))->assertConflict();
    if (! in_array($change, ['actor', 'obligation'], true)) {
        $this->postJson($commitUrl, $payload)->assertConflict();
        $this->postJson(route('admin.fees.obligations.attempts.cancel', $fee->id), durableFeeAttemptBody($operation, $payload))->assertConflict();
    }

    expect(DB::table('fee_action_attempts')->get()->all())->toEqual($retained);
    expect(durableFeeAttemptFinancialRows())->toEqual($before);
})->with(['amount_ngn', 'reason', 'customer_description', 'direction', 'operation', 'actor', 'obligation']);

test('prepared savings attempt rejects altered reviewed plan fingerprint and expiry with 409', function (string $change): void {
    [$admin, $fee, $payload, $commitUrl] = durableFeeAttemptFixture('apply_savings');
    $this->actingAs($admin)->withSession(cashSession())->postJson(route('admin.fees.obligations.attempts.prepare', $fee->id), durableFeeAttemptBody('apply_savings', $payload))->assertOk();
    $before = durableFeeAttemptFinancialRows();
    $retained = DB::table('fee_action_attempts')->get()->all();
    $payload[$change] = match ($change) {
        'plan_id' => 'PLN-DIFFERENT-REVIEW',
        'preview_fingerprint' => str_repeat('f', 64),
        default => now()->addMinutes(9)->toIso8601String(),
    };

    $this->postJson(route('admin.fees.obligations.attempts.prepare', $fee->id), durableFeeAttemptBody('apply_savings', $payload))->assertConflict();
    $this->postJson($commitUrl, $payload)->assertConflict();
    $this->postJson(route('admin.fees.obligations.attempts.cancel', $fee->id), durableFeeAttemptBody('apply_savings', $payload))->assertConflict();

    expect(DB::table('fee_action_attempts')->get()->all())->toEqual($retained);
    expect(durableFeeAttemptFinancialRows())->toEqual($before);
})->with(['plan_id', 'preview_fingerprint', 'quote_expires_at']);

test('cancellation winner rejects delayed original commit and replay without changing money', function (string $operation): void {
    [$admin, $fee, $payload, $commitUrl] = durableFeeAttemptFixture($operation);
    $body = durableFeeAttemptBody($operation, $payload);
    $this->actingAs($admin)->withSession(cashSession())->postJson(route('admin.fees.obligations.attempts.prepare', $fee->id), $body)->assertOk();
    $before = durableFeeAttemptFinancialRows();
    $cancelUrl = route('admin.fees.obligations.attempts.cancel', $fee->id);

    $cancelled = $this->postJson($cancelUrl, $body)->assertOk()->assertJsonPath('status', 'cancelled');
    $this->postJson($cancelUrl, $body)->assertOk()->assertExactJson($cancelled->json());
    $this->postJson($commitUrl, $payload)->assertConflict();
    $this->postJson($commitUrl, $payload)->assertConflict();
    $this->postJson(route('admin.fees.obligations.attempts.prepare', $fee->id), $body)
        ->assertOk()->assertJsonPath('status', 'cancelled');
    $changed = [...$payload, 'reason' => 'A replacement instruction cannot reuse a cancelled reference.'];
    $this->postJson(route('admin.fees.obligations.attempts.prepare', $fee->id), durableFeeAttemptBody($operation, $changed))->assertConflict();
    $this->getJson(route('admin.fees.obligations.attempts.status', [$fee->id, $payload['attempt_reference']]))
        ->assertOk()->assertJsonPath('status', 'cancelled');

    expect(durableFeeAttemptFinancialRows())->toEqual($before);
    expect($fee->fresh()->outstandingAmountKobo())->toBe(20000);
    $this->assertDatabaseCount('fee_action_attempts', 1);
})->with(['waive', 'correct', 'apply_savings']);

test('reference-only recovery can stop an unprepared original before its delayed request arrives', function (string $operation): void {
    [$admin, $fee, $payload, $commitUrl] = durableFeeAttemptFixture($operation);
    $before = durableFeeAttemptFinancialRows();
    $statusUrl = route('admin.fees.obligations.attempts.status', [$fee->id, $payload['attempt_reference']]);
    $body = ['operation' => $operation, 'attempt_reference' => $payload['attempt_reference']];
    $this->actingAs($admin)->getJson($statusUrl)->assertNotFound();

    $cancelled = $this->withSession(cashSession())->postJson(route('admin.fees.obligations.attempts.cancel', $fee->id), $body)
        ->assertOk()->assertJsonPath('status', 'cancelled');
    $this->postJson(route('admin.fees.obligations.attempts.cancel', $fee->id), $body)->assertOk()->assertExactJson($cancelled->json());
    $this->postJson($commitUrl, $payload)->assertConflict();
    $this->postJson($commitUrl, $payload)->assertConflict();
    $this->getJson($statusUrl)->assertOk()->assertExactJson($cancelled->json());

    expect(durableFeeAttemptFinancialRows())->toEqual($before);
    $this->assertDatabaseCount('fee_action_attempts', 1);
})->with(['waive', 'correct', 'apply_savings']);

test('reference-only cancellation resolves a legacy successful original through its retained verified source', function (string $operation): void {
    [$admin, $fee, $payload, $commitUrl] = durableFeeAttemptFixture($operation);
    $this->actingAs($admin)->withSession(cashSession());
    durableFeeSuccessfulCommit($this, $operation, $commitUrl, $payload);
    DB::table('fee_action_attempts')->where('attempt_reference', $payload['attempt_reference'])->delete();
    $before = durableFeeAttemptFinancialRows();
    $body = ['operation' => $operation, 'attempt_reference' => $payload['attempt_reference']];

    $this->postJson(route('admin.fees.obligations.attempts.cancel', $fee->id), $body)->assertOk()->assertJsonPath('status', 'recorded');
    durableFeeSuccessfulCommit($this, $operation, $commitUrl, $payload);

    expect(durableFeeAttemptFinancialRows())->toEqual($before);
    $this->assertDatabaseCount('fee_action_attempts', 0);
})->with(['waive', 'correct', 'apply_savings']);

test('commit winner makes cancellation return the same verified source and expired replay repeats no money', function (string $operation): void {
    [$admin, $fee, $payload, $commitUrl] = durableFeeAttemptFixture($operation);
    $body = durableFeeAttemptBody($operation, $payload);
    $this->actingAs($admin)->withSession(cashSession())->postJson(route('admin.fees.obligations.attempts.prepare', $fee->id), $body)->assertOk();
    durableFeeSuccessfulCommit($this, $operation, $commitUrl, $payload);
    $statusUrl = route('admin.fees.obligations.attempts.status', [$fee->id, $payload['attempt_reference']]);
    $recorded = $this->getJson($statusUrl)->assertOk()->assertJsonPath('status', 'recorded');
    $before = durableFeeAttemptFinancialRows();
    $this->travel(11)->minutes();

    $this->withSession(cashSession())->postJson(route('admin.fees.obligations.attempts.cancel', $fee->id), $body)
        ->assertOk()->assertExactJson($recorded->json());
    durableFeeSuccessfulCommit($this, $operation, $commitUrl, $payload);
    $this->postJson(route('admin.fees.obligations.attempts.prepare', $fee->id), $body)
        ->assertOk()->assertExactJson($recorded->json());

    expect(durableFeeAttemptFinancialRows())->toEqual($before);
    expect($fee->fresh()->outstandingAmountKobo())->toBe($operation === 'apply_savings' ? 0 : 10000);
    if ($operation === 'apply_savings') {
        $group = DB::table('ledger_posting_groups')->where('source_id', $payload['attempt_reference'])->sole();
        expect($recorded->json('posting_reference'))->toBe($group->posting_reference);
        $lines = DB::table('ledger_entries')->where('ledger_posting_group_id', $group->id)->get();
        expect($lines->sum('amount_kobo'))->toBe(40000);
        expect($lines->pluck('side')->sort()->values()->all())->toBe(['credit', 'debit']);
        $this->assertDatabaseCount('fee_savings_applications', 1);
    } else {
        $entry = DB::table('fee_obligation_entries')->where('source_id', $payload['attempt_reference'])->sole();
        expect($recorded->json('entry_id'))->toBe($entry->id);
        $this->assertDatabaseCount('fee_obligation_events', 1);
    }
})->with(['waive', 'correct', 'apply_savings']);

test('expired savings commit can be cancelled before a fresh reviewed instruction succeeds', function (): void {
    [$admin, $fee, $payload, $commitUrl] = durableFeeAttemptFixture('apply_savings');
    $body = durableFeeAttemptBody('apply_savings', $payload);
    $this->actingAs($admin)->withSession(cashSession())->postJson(route('admin.fees.obligations.attempts.prepare', $fee->id), $body)->assertOk();
    $before = durableFeeAttemptFinancialRows();
    $this->travel(11)->minutes();
    $this->withSession(cashSession())->postJson($commitUrl, $payload)->assertConflict();
    expect(durableFeeAttemptFinancialRows())->toEqual($before);

    $this->postJson(route('admin.fees.obligations.attempts.cancel', $fee->id), $body)->assertOk()->assertJsonPath('status', 'cancelled');
    $quote = app(FeeSavingsApplicationService::class)->preview($admin, $fee->id, $payload);
    $fresh = [...$payload, 'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at']];
    $this->postJson(route('admin.fees.obligations.attempts.prepare', $fee->id), durableFeeAttemptBody('apply_savings', $fresh))->assertOk();
    durableFeeSuccessfulCommit($this, 'apply_savings', $commitUrl, $fresh);
    $committed = durableFeeAttemptFinancialRows();
    $this->postJson($commitUrl, $payload)->assertConflict();

    expect(durableFeeAttemptFinancialRows())->toEqual($committed);
    expect($fee->fresh()->outstandingAmountKobo())->toBe(0);
    $this->assertDatabaseCount('fee_action_attempts', 2);
    $this->assertDatabaseCount('fee_savings_applications', 1);
});

test('identical direct savings commit preserves recovery when valid instruction fields use a different key order', function (): void {
    [$admin, $fee, $payload] = durableFeeAttemptFixture('apply_savings');
    $request = Request::create('/admin/fees/application', 'POST');
    $request->setLaravelSession(app('session')->driver());
    $request->session()->put(cashSession());
    $group = app(FeeSavingsApplicationService::class)->apply($admin, $fee->id, $payload, $request);
    $before = durableFeeAttemptFinancialRows();
    $body = durableFeeAttemptBody('apply_savings', $payload);

    $this->actingAs($admin)->withSession(cashSession())->postJson(route('admin.fees.obligations.attempts.cancel', $fee->id), $body)
        ->assertOk()->assertJsonPath('status', 'recorded')->assertJsonPath('posting_reference', $group->posting_reference);
    expect(app(FeeSavingsApplicationService::class)->apply($admin, $fee->id, $payload, $request)->id)->toBe($group->id);

    expect(durableFeeAttemptFinancialRows())->toEqual($before);
    expect($fee->fresh()->outstandingAmountKobo())->toBe(0);
});

test('foreign and revoked operators cannot disclose or stop a prepared original attempt', function (): void {
    [$admin, $fee, $payload] = durableFeeAttemptFixture('waive');
    $body = durableFeeAttemptBody('waive', $payload);
    $this->actingAs($admin)->withSession(cashSession())->postJson(route('admin.fees.obligations.attempts.prepare', $fee->id), $body)->assertOk();
    $foreign = User::factory()->admin()->withTwoFactor()->create();
    $foreign->givePermissionTo(AdminPermission::FeesManage);
    $before = durableFeeAttemptFinancialRows();
    $retained = DB::table('fee_action_attempts')->get()->all();
    $statusUrl = route('admin.fees.obligations.attempts.status', [$fee->id, $payload['attempt_reference']]);
    $cancelUrl = route('admin.fees.obligations.attempts.cancel', $fee->id);

    $this->actingAs($foreign)->getJson($statusUrl)->assertNotFound();
    $this->withSession(cashSession())->postJson($cancelUrl, $body)->assertConflict();
    $admin->revokePermissionTo(AdminPermission::FeesManage);
    $this->actingAs($admin)->getJson($statusUrl)->assertForbidden();
    $this->postJson($cancelUrl, $body)->assertForbidden();

    expect(DB::table('fee_action_attempts')->get()->all())->toEqual($retained);
    expect(durableFeeAttemptFinancialRows())->toEqual($before);
});

test('stopping a prepared instruction requires fresh authentication while its read-only status remains available', function (string $operation): void {
    [$admin, $fee, $payload] = durableFeeAttemptFixture($operation);
    $body = durableFeeAttemptBody($operation, $payload);
    $this->actingAs($admin)->withSession(cashSession())->postJson(route('admin.fees.obligations.attempts.prepare', $fee->id), $body)->assertOk();
    $before = durableFeeAttemptFinancialRows();
    $retained = DB::table('fee_action_attempts')->get()->all();
    $this->flushSession();

    $this->postJson(route('admin.fees.obligations.attempts.cancel', $fee->id), $body)->assertStatus(423);
    $this->getJson(route('admin.fees.obligations.attempts.status', [$fee->id, $payload['attempt_reference']]))
        ->assertOk()->assertJsonPath('status', 'prepared');

    expect(DB::table('fee_action_attempts')->get()->all())->toEqual($retained);
    expect(durableFeeAttemptFinancialRows())->toEqual($before);
    $this->withSession(cashSession())->postJson(route('admin.fees.obligations.attempts.cancel', $fee->id), $body)
        ->assertOk()->assertJsonPath('status', 'cancelled');
    expect(durableFeeAttemptFinancialRows())->toEqual($before);
})->with(['waive', 'apply_savings']);

test('rejected administrative commit can be cancelled before a smaller fresh review succeeds', function (): void {
    [$admin, $fee, $payload, $commitUrl] = durableFeeAttemptFixture('waive');
    $body = durableFeeAttemptBody('waive', $payload);
    $this->actingAs($admin)->withSession(cashSession())->postJson(route('admin.fees.obligations.attempts.prepare', $fee->id), $body)->assertOk();
    $intervening = [...$payload, 'attempt_reference' => (string) Str::uuid(), 'amount_ngn' => '150.00'];
    $this->post($commitUrl, $intervening)->assertRedirect()->assertSessionHasNoErrors();
    $before = durableFeeAttemptFinancialRows();

    $this->postJson($commitUrl, $payload)->assertUnprocessable();
    expect(durableFeeAttemptFinancialRows())->toEqual($before);
    $this->postJson(route('admin.fees.obligations.attempts.cancel', $fee->id), $body)->assertOk()->assertJsonPath('status', 'cancelled');
    $fresh = [...$payload, 'attempt_reference' => (string) Str::uuid(), 'amount_ngn' => '50.00'];
    $this->postJson(route('admin.fees.obligations.attempts.prepare', $fee->id), durableFeeAttemptBody('waive', $fresh))->assertOk();
    $this->post($commitUrl, $fresh)->assertRedirect()->assertSessionHasNoErrors();
    $committed = durableFeeAttemptFinancialRows();
    $this->postJson($commitUrl, $payload)->assertConflict();

    expect(durableFeeAttemptFinancialRows())->toEqual($committed);
    expect($fee->fresh()->outstandingAmountKobo())->toBe(0);
    $this->assertDatabaseCount('fee_obligation_events', 2);
});

test('historical Customer inactivity cannot prevent stopping an uncommitted fee instruction', function (string $operation): void {
    [$admin, $fee, $payload, $commitUrl] = durableFeeAttemptFixture($operation);
    $body = durableFeeAttemptBody($operation, $payload);
    $this->actingAs($admin)->withSession(cashSession())->postJson(route('admin.fees.obligations.attempts.prepare', $fee->id), $body)->assertOk();
    $admin->givePermissionTo(AdminPermission::CustomersManage);
    $customer = $fee->customerProfile;
    app(CustomerStatusManagementService::class)->transition($admin, $customer, CustomerStatus::Inactive,
        $customer->version, 'Reviewed temporary inactivity.', 'Existing history remains available.');
    $before = durableFeeAttemptFinancialRows();

    $this->postJson(route('admin.fees.obligations.attempts.cancel', $fee->id), $body)->assertOk()->assertJsonPath('status', 'cancelled');
    $this->postJson($commitUrl, $payload)->assertConflict();

    expect(durableFeeAttemptFinancialRows())->toEqual($before);
    expect($fee->fresh()->customerProfile->operational_status)->toBe(CustomerStatus::Inactive);
})->with(['waive', 'apply_savings']);

test('corrupt committed source cannot be reset to cancelled or replayed as new finance', function (string $operation): void {
    [$admin, $fee, $payload, $commitUrl] = durableFeeAttemptFixture($operation);
    $body = durableFeeAttemptBody($operation, $payload);
    $this->actingAs($admin)->withSession(cashSession())->postJson(route('admin.fees.obligations.attempts.prepare', $fee->id), $body)->assertOk();
    durableFeeSuccessfulCommit($this, $operation, $commitUrl, $payload);
    DB::table('fee_obligation_entries')->where('source_id', $payload['attempt_reference'])->update(['currency' => 'USD']);
    $before = durableFeeAttemptFinancialRows();
    $retained = DB::table('fee_action_attempts')->get()->all();

    $this->getJson(route('admin.fees.obligations.attempts.status', [$fee->id, $payload['attempt_reference']]))->assertConflict();
    $this->postJson(route('admin.fees.obligations.attempts.cancel', $fee->id), $body)->assertConflict();
    $this->postJson($commitUrl, $payload)->assertConflict();
    $this->postJson(route('admin.fees.obligations.attempts.prepare', $fee->id), $body)->assertConflict();

    expect(DB::table('fee_action_attempts')->get()->all())->toEqual($retained);
    expect(durableFeeAttemptFinancialRows())->toEqual($before);
})->with(['waive', 'correct', 'apply_savings']);
