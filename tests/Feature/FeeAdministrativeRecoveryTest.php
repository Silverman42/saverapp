<?php

use App\Enums\AdminPermission;
use App\Jobs\MaterializeNotificationIntent;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

require_once __DIR__.'/../CollectionFixtures.php';
require_once __DIR__.'/../FeeFixtures.php';
require_once __DIR__.'/../CashExecutionFixtures.php';

class LoseCommittedFeeAdministrativeResponse
{
    public static bool $enabled = false;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (self::$enabled && $response->isRedirect()) {
            self::$enabled = false;
            throw new RuntimeException('Committed fee response could not reach the caller.');
        }

        return $response;
    }
}

/** @return array{User, FeeObligation, array<string, mixed>, string} */
function administrativeRecoveryFixture(string $action): array
{
    [$agent, $customer] = collectionFixture();
    $fee = reportFeeObligation($agent, $customer);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $payload = ['attempt_reference' => (string) Str::uuid(), 'amount_ngn' => '100.00',
        'reason' => 'SECRET reviewed fee relief.', 'customer_description' => 'Your unpaid agreement was reviewed.',
        'confirmed' => true];
    if ($action !== 'waive') {
        $payload['direction'] = $action;
    }

    return [$admin, $fee, $payload, route('admin.fees.obligations.'.($action === 'waive' ? 'waive' : 'correct'), $fee->id)];
}

/** @return array<string, array<int, object>> */
function administrativeRecoveryRows(): array
{
    $rows = [];
    foreach (['fee_snapshots', 'fee_obligations', 'fee_obligation_entries', 'fee_obligation_events',
        'ledger_posting_groups', 'ledger_entries', 'collection_receipts', 'collection_fee_components',
        'collection_allocations', 'withdrawal_requests', 'withdrawal_reservations', 'thrift_plans',
        'plan_terms_revisions', 'contribution_slots', 'audit_events', 'canonical_audit_events',
        'audit_protected_payloads', 'fee_obligation_notification_intents', 'notification_inbox_intents',
        'notification_inbox_aliases', 'notification_events', 'notifications'] as $table) {
        $query = DB::table($table)->orderBy('id');
        if (in_array($table, ['audit_events', 'canonical_audit_events'], true)) {
            $query->where('event_type', '!=', 'fee.management_attempt');
        }
        $rows[$table] = $query->get()->all();
    }

    return $rows;
}

test('lost committed fee response is recovered without repeating entries audits or notices', function (string $action, int $remaining): void {
    $this->freezeTime();
    Queue::fake([MaterializeNotificationIntent::class]);
    [$admin, $fee, $payload, $url] = administrativeRecoveryFixture($action);
    Route::getRoutes()->getByName('admin.fees.obligations.'.($action === 'waive' ? 'waive' : 'correct'))
        ->middleware(LoseCommittedFeeAdministrativeResponse::class);
    LoseCommittedFeeAdministrativeResponse::$enabled = true;
    try {
        $this->actingAs($admin)->withSession(cashSession())->post($url, $payload)->assertServerError();
    } finally {
        LoseCommittedFeeAdministrativeResponse::$enabled = false;
    }
    expect($fee->fresh()->outstandingAmountKobo())->toBe($remaining);
    $entry = DB::table('fee_obligation_entries')->where('source_id', $payload['attempt_reference'])->sole();
    $this->assertDatabaseCount('fee_obligation_events', 1);
    $this->assertDatabaseCount('fee_obligation_notification_intents', 3);
    $this->assertDatabaseCount('ledger_posting_groups', 0);
    $baseline = administrativeRecoveryRows();
    $this->flushSession();
    $status = $this->getJson(route('admin.fees.obligations.action-status', [$fee->id, $payload['attempt_reference']]))
        ->assertOk()->assertJsonPath('status', 'recorded')->assertJsonPath('entry_id', $entry->id)
        ->assertJsonPath('action', $action === 'waive' ? 'waive' : 'correct')
        ->assertJsonPath('direction', $action === 'waive' ? null : $action)
        ->assertJsonPath('amount_kobo', 10000)->assertJsonPath('currency', 'NGN')
        ->assertJsonPath('attempt_reference', $payload['attempt_reference']);
    expect(array_keys($status->json()))->toBe(['status', 'action', 'direction', 'attempt_reference', 'entry_id', 'amount_kobo', 'currency', 'recorded_at']);
    expect($status->getContent())->not->toContain('SECRET', $payload['customer_description']);
    $this->withSession(cashSession())->post($url, $payload)->assertRedirect()->assertSessionHasNoErrors();
    expect(administrativeRecoveryRows())->toEqual($baseline);
    $this->post($url, [...$payload, 'amount_ngn' => '101.00'])->assertConflict();
    expect(administrativeRecoveryRows())->toEqual($baseline);
})->with(['waiver' => ['waive', 40000], 'reduction' => ['reduce', 40000], 'increase' => ['increase', 60000]]);

test('missing administrative status preserves the original attempt through a delayed commit and replay', function (string $action): void {
    $this->freezeTime();
    Queue::fake([MaterializeNotificationIntent::class]);
    [$admin, $fee, $payload, $url] = administrativeRecoveryFixture($action);
    $baseline = administrativeRecoveryRows();
    $statusUrl = route('admin.fees.obligations.action-status', [$fee->id, $payload['attempt_reference']]);
    $this->actingAs($admin)->getJson($statusUrl)->assertNotFound();
    expect(administrativeRecoveryRows())->toEqual($baseline);
    $this->withSession(cashSession())->post($url, $payload)->assertRedirect()->assertSessionHasNoErrors();
    $baseline = administrativeRecoveryRows();
    $this->getJson($statusUrl)->assertOk()->assertJsonPath('status', 'recorded');
    $this->post($url, $payload)->assertRedirect()->assertSessionHasNoErrors();
    expect(administrativeRecoveryRows())->toEqual($baseline);
})->with(['waive', 'reduce', 'increase']);

test('administrative status and replay require the original actor and current fee authority', function (): void {
    $this->freezeTime();
    Queue::fake([MaterializeNotificationIntent::class]);
    [$admin, $fee, $payload, $url] = administrativeRecoveryFixture('waive');
    $this->actingAs($admin)->withSession(cashSession())->post($url, $payload)->assertRedirect()->assertSessionHasNoErrors();
    $statusUrl = route('admin.fees.obligations.action-status', [$fee->id, $payload['attempt_reference']]);
    $foreign = User::factory()->admin()->withTwoFactor()->create();
    $foreign->givePermissionTo(AdminPermission::FeesManage);
    $baseline = administrativeRecoveryRows();
    $this->actingAs($foreign)->getJson($statusUrl)->assertNotFound();
    $this->withSession(cashSession())->post($url, $payload)->assertConflict();
    $this->actingAs($admin)->getJson(route('admin.fees.obligations.action-status', [$fee->id + 1000, $payload['attempt_reference']]))->assertNotFound();
    $admin->revokePermissionTo(AdminPermission::FeesManage);
    $this->actingAs($admin)->getJson($statusUrl)->assertForbidden();
    $this->post($url, $payload)->assertForbidden();
    expect(administrativeRecoveryRows())->toEqual($baseline);
    expect(DB::table('audit_events')->where('event_type', 'fee.management_attempt')->count())->toBe(2);
});

test('recorded administrative attempt cannot be repurposed by changing its reviewed payload or obligation', function (string $change): void {
    $this->freezeTime();
    Queue::fake([MaterializeNotificationIntent::class]);
    [$admin, $fee, $payload, $url] = administrativeRecoveryFixture('reduce');
    $this->actingAs($admin)->withSession(cashSession())->post($url, $payload)->assertRedirect()->assertSessionHasNoErrors();
    if ($change === 'obligation') {
        $agent = User::query()->findOrFail($fee->created_by_user_id);
        $customer = CustomerProfile::factory()->create();
        $other = reportFeeObligation($agent, $customer, 50000, 2);
        $url = route('admin.fees.obligations.correct', $other->id);
    } else {
        $payload[$change] = match ($change) {
            'direction' => 'increase',
            'reason' => 'A different internal justification.',
            default => 'A different Customer explanation.',
        };
    }
    $baseline = administrativeRecoveryRows();
    $this->post($url, $payload)->assertConflict();
    expect(administrativeRecoveryRows())->toEqual($baseline);
    expect($fee->fresh()->outstandingAmountKobo())->toBe(40000);
})->with(['reason', 'customer_description', 'direction', 'obligation']);

test('administrative recovery fails closed when committed entry or its source proof is corrupt', function (string $damage): void {
    $this->freezeTime();
    Queue::fake([MaterializeNotificationIntent::class]);
    [$admin, $fee, $payload, $url] = administrativeRecoveryFixture('reduce');
    $this->actingAs($admin)->withSession(cashSession())->post($url, $payload)->assertRedirect()->assertSessionHasNoErrors();
    $entry = DB::table('fee_obligation_entries')->where('source_id', $payload['attempt_reference'])->sole();
    if ($damage === 'event') {
        DB::table('fee_obligation_events')->where('fee_obligation_entry_id', $entry->id)->update(['amount_kobo' => 1]);
    } elseif ($damage === 'currency') {
        DB::table('fee_obligation_entries')->where('id', $entry->id)->update(['currency' => 'USD']);
    } elseif ($damage === 'source') {
        DB::table('fee_obligation_entries')->where('id', $entry->id)->update(['source_type' => 'unknown_source']);
    } elseif ($damage === 'idempotency') {
        DB::table('fee_obligation_entries')->where('id', $entry->id)->update(['idempotency_key' => 'damaged-key']);
    } elseif ($damage === 'audit') {
        $auditId = DB::table('fee_obligation_events')->where('fee_obligation_entry_id', $entry->id)->value('audit_event_id');
        DB::table('audit_events')->where('id', $auditId)->update(['target_id' => $fee->id + 1]);
    } else {
        DB::table('fee_obligation_entries')->where('id', $entry->id)->update(['ledger_posting_reference' => 'unverified-ledger-source']);
    }
    $baseline = administrativeRecoveryRows();
    $this->getJson(route('admin.fees.obligations.action-status', [$fee->id, $payload['attempt_reference']]))->assertConflict();
    $this->post($url, $payload)->assertConflict();
    expect(administrativeRecoveryRows())->toEqual($baseline);
    expect(DB::table('audit_events')->where('event_type', 'fee.management_attempt')->count())->toBe(1);
})->with(['event', 'currency', 'source', 'ledger', 'audit', 'idempotency']);
