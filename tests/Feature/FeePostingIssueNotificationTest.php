<?php

use App\Enums\AdminPermission;
use App\Models\CustomerProfile;
use App\Models\FeeObligation;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\NotificationPipeline;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../CollectionFixtures.php';
require_once __DIR__.'/../FeeFixtures.php';

/** @return array{User, CustomerProfile, FeeObligation, array<string, mixed>} */
function feePostingIssueFixture(object $test): array
{
    $test->freezeTime();
    Queue::fake();
    config()->set(['collections.enabled' => true, 'fees.savings_applications_enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $cash = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $cash['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $cash)['preview_fingerprint'];
    app(CollectionService::class)->record($agent, $customer, $cash);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $fee = reportFeeObligation($agent, $customer, 50000);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $test->actingAs($admin)->withSession(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
    $review = ['plan_id' => $plan->plan_id, 'reason' => 'PRIVATE exact reviewed application.', 'customer_description' => 'PRIVATE agreed fee settlement.'];
    $quote = $test->postJson(route('admin.fees.obligations.savings-preview', $fee), $review)->assertOk()->json();

    return [$admin, $customer, $fee, [...$review, 'attempt_reference' => (string) Str::uuid(), 'confirmed' => true,
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at']]];
}

/** @return array<string, array<int, object>> */
function feePostingIssueMoneyRows(): array
{
    $rows = [];
    foreach (['fee_obligations', 'fee_obligation_entries', 'fee_snapshots', 'fee_savings_applications', 'fee_application_notification_intents',
        'ledger_posting_groups', 'ledger_entries', 'ledger_transaction_projections', 'ledger_transaction_references', 'ledger_projection_state',
        'collection_receipts', 'collection_allocations', 'collection_fee_components', 'thrift_plans', 'plan_terms_revisions', 'contribution_slots'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return $rows;
}

test('actual reviewed fee application system failure retains a masked operator issue until exact authoritative posting and replay', function (): void {
    [$admin, $customer, $fee, $payload] = feePostingIssueFixture($this);
    $before = feePostingIssueMoneyRows();
    DB::statement("CREATE TRIGGER fail_fee_posting_projection BEFORE INSERT ON ledger_transaction_projections WHEN NEW.type = 'fee_application' BEGIN SELECT RAISE(ABORT, 'PRIVATE projection outage'); END");
    $this->postJson(route('admin.fees.obligations.apply-savings', $fee), $payload)->assertServerError();
    expect(feePostingIssueMoneyRows())->toEqual($before);
    $failure = DB::table('canonical_audit_events')->where('event_type', 'fee.management_attempt')->sole();
    expect($failure->outcome)->toBe('Failed')->and($failure->correlation_reference)->toBe(hash('sha256', $payload['attempt_reference']))
        ->and($failure->content)->not->toContain('PRIVATE')->not->toContain($payload['attempt_reference']);
    $issue = DB::table('fee_operational_issues')->where('issue_kind', 'posting_issue')->sole();
    $context = json_decode(Crypt::decryptString($issue->context_ciphertext), true, flags: JSON_THROW_ON_ERROR);
    expect($issue->state)->toBe('outcome_unconfirmed')->and($issue->category)->toBe('system_failed')
        ->and($issue->fee_obligation_id)->toBe($fee->id)->and($issue->customer_profile_id)->toBe($customer->id)
        ->and($issue->audit_event_id)->toBe($failure->legacy_audit_event_id)
        ->and($issue->operation_reference)->toBe(hash('sha256', $payload['attempt_reference']))
        ->and($issue->context_ciphertext)->not->toContain($payload['attempt_reference'])->not->toContain('PRIVATE')
        ->and($context['command_reference'])->toBe($payload['attempt_reference']);
    $owner = DB::table('fee_issue_notification_intents')->where('fee_operational_issue_id', $issue->id)->sole();
    expect($owner->recipient_user_id)->toBe($admin->id)->and($owner->audience_type)->toBe('fee_manager')
        ->and(DB::table('fee_issue_notification_intents')->where('recipient_user_id', $customer->user_id)->count())->toBe(0);
    $inbox = DB::table('notification_inbox_intents')->where('notification_id', $owner->notification_id)->sole();
    expect($inbox->summary)->toContain('outcome is unconfirmed')->not->toContain('PRIVATE')->not->toContain($payload['attempt_reference']);
    app(NotificationPipeline::class)->materialize($inbox->id);
    $this->actingAs($customer->user)->get(route('notifications.show', $owner->notification_id))->assertNotFound();
    $this->actingAs($admin)->get(route('notifications.show', $owner->notification_id))->assertOk();
    $this->postJson(route('admin.fees.obligations.apply-savings', $fee), $payload)->assertServerError();
    expect(DB::table('fee_operational_issues')->where('issue_kind', 'posting_issue')->count())->toBe(1)
        ->and(feePostingIssueMoneyRows())->toEqual($before);
    DB::statement('DROP TRIGGER fail_fee_posting_projection');
    $this->postJson(route('admin.fees.obligations.apply-savings', $fee), $payload)->assertOk();
    $posted = feePostingIssueMoneyRows();
    $this->getJson(route('admin.fees.obligations.savings-status', ['obligation' => $fee->id, 'attemptReference' => $payload['attempt_reference']]))
        ->assertOk()->assertJsonPath('status', 'posted');
    $this->postJson(route('admin.fees.obligations.apply-savings', $fee), $payload)->assertOk();
    expect(feePostingIssueMoneyRows())->toEqual($posted)->and(DB::table('fee_savings_applications')->count())->toBe(1)
        ->and(DB::table('fee_application_notification_intents')->count())->toBe(3)
        ->and(DB::table('fee_operational_issues')->where('issue_kind', 'posting_issue')->count())->toBe(1);
    $this->get(route('notifications.show', $owner->notification_id))->assertNotFound();
});

test('invalid revoked or crossed reviewed application commands create no posting issue or financial changes', function (string $failure): void {
    [$admin, $customer, $fee, $payload] = feePostingIssueFixture($this);
    $selected = $fee;
    $status = 422;
    if ($failure === 'invalid identity') {
        $payload['attempt_reference'] = 'PRIVATE invalid command identity';
    } elseif ($failure === 'revoked') {
        $admin->revokePermissionTo(AdminPermission::FeesManage);
        $status = 403;
    } else {
        $foreign = CustomerProfile::factory()->create();
        $selected = reportFeeObligation($admin, $foreign, 50000, 2);
        $status = 404;
    }
    $before = feePostingIssueMoneyRows();
    $this->postJson(route('admin.fees.obligations.apply-savings', $selected), $payload)->assertStatus($status);
    expect(DB::table('fee_operational_issues')->where('issue_kind', 'posting_issue')->count())->toBe(0)
        ->and(feePostingIssueMoneyRows())->toEqual($before);
})->with(['invalid identity', 'revoked', 'crossed owner']);
