<?php

use App\Enums\AdminPermission;
use App\Enums\CustomerStatus;
use App\Models\ChargeCategoryVersion;
use App\Models\CustomerProfile;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\CustomerStatusManagementService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../CollectionFixtures.php';
require_once __DIR__.'/../FeeFixtures.php';
require_once __DIR__.'/../ManualChargeFixtures.php';

/** @return array<string, array<int, object>> */
function feeAuditFinancialRows(): array
{
    $rows = [];
    foreach (['thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'fee_rules', 'fee_snapshots',
        'fee_obligations', 'fee_obligation_entries', 'manual_charges', 'fee_savings_applications', 'fee_refunds',
        'collection_receipts', 'collection_allocations', 'collection_fee_components', 'collection_batches',
        'ledger_posting_groups', 'ledger_entries', 'withdrawal_requests', 'withdrawal_reservations',
        'cash_disbursements', 'financial_cash_events', 'financial_cash_notification_intents',
        'fee_application_notification_intents', 'manual_charge_notification_intents'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return $rows;
}

test('FEE-AC-045: failed reviewed fee commands retain only current verified owner evidence', function (string $failure): void {
    $this->freezeTime();
    Queue::fake();
    config()->set(['collections.enabled' => true, 'fees.manual_charges_enabled' => true, 'fees.savings_applications_enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $cash = collectionPayload($customer, $assignment, $plan, $date, '2000.00');
    $cash['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $cash)['preview_fingerprint'];
    app(CollectionService::class)->record($agent, $customer, $cash);
    $fee = reportFeeObligation($agent, $customer, 50000);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $this->actingAs($admin)->withSession(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
    $reference = (string) Str::uuid();
    $family = 'fee';
    $operation = 'admin.fees.obligations.apply-savings';
    $status = 409;
    $expectedCustomer = $customer->id;
    $expectedTarget = $fee->id;
    $expectedReference = (string) $fee->id;
    $expectedSourceVersion = 1;
    if (in_array($failure, ['changed charge', 'revoked charge'], true)) {
        $this->post(route('admin.charges.publish'), ['publication_reference' => (string) Str::uuid(),
            'category_key' => 'audit-service', 'kind' => 'manual_fee', 'purpose' => 'Approved service',
            'customer_description' => 'Agreed service charge', 'amount_ngn' => '100.01', 'confirmed' => true])
            ->assertRedirect()->assertSessionHasNoErrors();
        $category = ChargeCategoryVersion::query()->sole();
        $payload = reviewManualCharge($this, ['operation_reference' => $reference, 'customer_id' => $customer->customer_id,
            'plan_id' => $plan->plan_id, 'category_id' => $category->id, 'customer_version' => $customer->version,
            'plan_version' => $plan->fresh()->version, 'reason' => 'PRIVATE exact charge review.', 'confirmed' => true]);
        $family = 'charge';
        $operation = 'admin.charges.assess';
        $url = route($operation);
        $status = $failure === 'changed charge' ? 409 : 403;
        $expectedTarget = $customer->id;
        $expectedReference = $customer->customer_id;
        if ($failure === 'revoked charge') {
            $admin->revokePermissionTo(AdminPermission::FeesManage);
            $expectedCustomer = $expectedTarget = $expectedReference = null;
        } else {
            $admin->givePermissionTo(AdminPermission::CustomersManage);
            $customer = app(CustomerStatusManagementService::class)->transition($admin, $customer, CustomerStatus::Inactive,
                $customer->version, 'Reviewed temporary inactivity.', 'Existing records remain available.');
            $customer = app(CustomerStatusManagementService::class)->transition($admin, $customer, CustomerStatus::Active,
                $customer->version, 'Reviewed return to activity.', 'Normal activity may resume.');
            expect($customer->version)->toBe(3);
            $expectedSourceVersion = $customer->version;
            $payload['reason'] = 'PRIVATE changed review and payment secret.';
        }
    } elseif ($failure === 'unavailable refund') {
        config()->set('fees.refunds_enabled', false);
        $operation = 'admin.fees.refunds.store';
        $url = route($operation, $fee);
        $payload = ['refund_reference' => $reference, 'kind' => 'savings', 'amount_ngn' => '1.00',
            'reason' => 'PRIVATE refund explanation.', 'confirmed' => true];
        $status = 503;
    } elseif ($failure === 'invalid publication') {
        $family = 'fee_rule';
        $operation = 'admin.fees.registration.store';
        $url = route($operation);
        $payload = ['publication_reference' => $reference, 'name' => 'PRIVATE failed rule',
            'publication_reason' => 'PRIVATE failed explanation', 'confirmed' => true];
        $status = 422;
        $expectedCustomer = $expectedTarget = $expectedReference = null;
    } else {
        $review = ['plan_id' => $plan->plan_id, 'reason' => 'PRIVATE original application review.',
            'customer_description' => 'PRIVATE payment description.'];
        $quote = $this->postJson(route('admin.fees.obligations.savings-preview', $fee), $review)->assertOk()->json();
        $payload = [...$review, 'attempt_reference' => $reference, 'confirmed' => true,
            'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at']];
        $selected = $fee;
        if ($failure === 'crossed application') {
            $foreign = CustomerProfile::factory()->create();
            $selected = reportFeeObligation($agent, $foreign, 50000, 2);
            $status = 404;
            $expectedCustomer = $expectedTarget = $expectedReference = null;
        } else {
            LedgerAccount::query()->where('code', 'fee_income_ngn')->update(['mapping_status' => 'unmapped']);
        }
        $url = route($operation, $selected);
    }
    $before = feeAuditFinancialRows();
    $this->postJson($url, $payload)->assertStatus($status);
    expect(feeAuditFinancialRows())->toEqual($before);
    $event = DB::table('canonical_audit_events')->where('event_type', $family.'.management_attempt')->sole();
    $content = json_decode($event->content, true, flags: JSON_THROW_ON_ERROR);
    expect($event->source_version)->toBe($expectedSourceVersion)
        ->and($event->outcome)->toBe($status === 409 ? 'Conflict' : ($status === 422 || $status === 503 ? 'Failed' : 'Denied'))
        ->and($event->actor_id)->toBe($admin->id)->and($event->target_id)->toBe($expectedTarget)
        ->and($event->target_reference)->toBe($expectedReference)->and($event->correlation_reference)->toBe(hash('sha256', $reference))
        ->and($content['safe_changes']['operation'])->toBe($operation)
        ->and($content['safe_changes']['customer_profile_id'])->toBe($expectedCustomer)
        ->and($content['safe_changes']['category'])->toBe(match ($status) {
            409 => 'state_conflict', 422 => 'validation_failed', 503 => 'system_failed', default => 'authority_or_scope_denied',
        })
        ->and($event->content)->not->toContain('PRIVATE')->not->toContain($reference)
        ->and($content['safe_changes'])->not->toHaveKeys(['amount_ngn', 'reason', 'customer_description', 'evidence', 'preview_fingerprint']);
    if ($expectedCustomer === null) {
        expect($event->content)->not->toContain($customer->customer_id)->not->toContain($plan->plan_id);
    }
})->with(['changed charge', 'revoked charge', 'unavailable application', 'crossed application', 'invalid publication', 'unavailable refund']);

test('FEE-AC-045: accepted manual charge retains protected reason and original operation correlation on replay', function (): void {
    $this->freezeTime();
    Queue::fake();
    config()->set('fees.manual_charges_enabled', true);
    [, $customer, , $plan] = collectionFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $this->actingAs($admin)->withSession(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
        'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
    $this->post(route('admin.charges.publish'), ['publication_reference' => (string) Str::uuid(),
        'category_key' => 'audit-accepted', 'kind' => 'manual_fee', 'purpose' => 'Approved service',
        'customer_description' => 'Agreed service charge', 'amount_ngn' => '100.01', 'confirmed' => true])
        ->assertRedirect()->assertSessionHasNoErrors();
    $category = ChargeCategoryVersion::query()->sole();
    $reference = (string) Str::uuid();
    $reason = 'PRIVATE accepted charge explanation.';
    $payload = reviewManualCharge($this, ['operation_reference' => $reference, 'customer_id' => $customer->customer_id,
        'plan_id' => $plan->plan_id, 'category_id' => $category->id, 'customer_version' => $customer->version,
        'plan_version' => $plan->fresh()->version, 'reason' => $reason, 'confirmed' => true]);
    $this->postJson(route('admin.charges.assess'), $payload)->assertOk();
    $charge = DB::table('manual_charges')->where('operation_reference', $reference)->sole();
    $event = DB::table('canonical_audit_events')->where('event_type', 'charge.assessed')->sole();
    expect($event->target_id)->toBe($charge->id)->and($event->target_reference)->toBe($reference)
        ->and($event->actor_id)->toBe($admin->id)->and($event->correlation_reference)->toBe($reference)
        ->and($event->content)->not->toContain($reason);
    $protected = DB::table('audit_protected_payloads')->where('canonical_event_id', $event->id)->sole();
    expect($protected->ciphertext)->not->toContain($reason)
        ->and(json_decode(Crypt::decryptString($protected->ciphertext), true, flags: JSON_THROW_ON_ERROR)['reason'])->toBe($reason);
    $rows = feeAuditFinancialRows();
    foreach (['audit_events', 'canonical_audit_events', 'audit_protected_payloads', 'audit_projection_work'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    $this->postJson(route('admin.charges.assess'), $payload)->assertOk();
    foreach ($rows as $table => $before) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($before);
    }
});
