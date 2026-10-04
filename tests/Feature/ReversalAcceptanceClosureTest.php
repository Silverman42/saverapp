<?php

use App\Enums\AdminPermission;
use App\Enums\CustomerStatus;
use App\Enums\LedgerAccountCode;
use App\Models\AgentProfile;
use App\Models\ChargeCategoryVersion;
use App\Models\CustomerAssignment;
use App\Models\CustomerProfile;
use App\Models\FinancialPeriod;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerPostingGroup;
use App\Models\ManualCharge;
use App\Models\ReversalRequest;
use App\Models\ThriftPlan;
use App\Models\User;
use App\Services\CollectionLedgerService;
use App\Services\CollectionReadService;
use App\Services\CollectionService;
use App\Services\ReversalService;
use App\Services\WithdrawalService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../ReversalFixtures.php';
require_once __DIR__.'/../ReversalAcceptanceGapFixtures.php';
require_once __DIR__.'/../FeeFixtures.php';
require_once __DIR__.'/../ManualChargeFixtures.php';

test('REV-AC-001: an Admin holding every permission cannot edit, delete or partially reverse a posted original', function (): void {
    ['agent' => $agent, 'original' => $original] = revGapReceipt();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(Permission::query()->where('guard_name', 'web')->pluck('name')->all());
    $before = revGapEffectCounts();
    $lines = $original->entries()->orderBy('line_number')->get()->map->getAttributes()->all();
    $path = '/ledger-postings/'.$original->posting_reference;

    foreach (['put', 'patch', 'delete'] as $method) {
        expect($this->actingAs($admin)->withSession(revGapFreshSession())->{$method.'Json'}($path, ['amount_kobo' => 1])->status())->toBeIn([404, 405]);
    }
    $this->actingAs($admin)->withSession(revGapFreshSession())->postJson(route('reversals.preview', $original->posting_reference))->assertForbidden();
    $quote = revGapQuote($this, $agent, $original);
    $this->actingAs($agent)->postJson(route('reversals.store', $original->posting_reference), revGapSubmission($quote, ['amount_kobo' => 100000]))
        ->assertUnprocessable();
    expect(fn () => $original->entries()->first()->update(['amount_kobo' => 1]))->toThrow(RuntimeException::class, 'immutable')
        ->and(fn () => $original->entries()->first()->delete())->toThrow(RuntimeException::class)
        ->and(fn () => $original->fresh()->delete())->toThrow(RuntimeException::class);

    expect(revGapEffectCounts())->toBe($before)
        ->and($original->entries()->orderBy('line_number')->get()->map->getAttributes()->all())->toBe($lines);
});

test('REV-AC-002: a remittance and a fee waiver or deduction cannot be routed through correction', function (): void {
    ['agent' => $agent, 'customer' => $customer, 'receipt' => $receipt, 'original' => $original] = revGapReceipt();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $remittance = DB::table('cash_remittances')->insertGetId([
        'handoff_reference' => 'REM-'.Str::uuid(), 'receiving_location' => 'Business till', 'source_attestation' => 'Counted handoff',
        'collection_batch_id' => $receipt->collection_batch_id, 'agent_profile_id' => $receipt->recording_agent_profile_id,
        'confirmed_by_user_id' => $admin->id, 'amount_kobo' => 200000, 'handoff_date' => now()->toDateString(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $group = DB::transaction(fn () => app(CollectionLedgerService::class)->postCashRemittance($remittance, $receipt->recording_agent_profile_id, 200000, $admin));
    $before = revGapEffectCounts();

    $this->actingAs($agent)->postJson(route('reversals.preview', $group->posting_reference))->assertNotFound();
    $quote = revGapQuote($this, $agent, $original);
    foreach (['waive_fee' => true, 'deduction_kobo' => 500, 'new_deduction' => 'other'] as $field => $value) {
        $this->actingAs($agent)->postJson(route('reversals.store', $original->posting_reference), revGapSubmission($quote, [$field => $value]))
            ->assertUnprocessable();
    }
    expect(revGapEffectCounts())->toBe($before)->and(ReversalRequest::count())->toBe(0);
});

test('REV-AC-013: a multi-slot receipt with a fee releases every allocation and restores the obligation once', function (): void {
    config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true]);
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(2);
    $obligation = reportFeeObligation($agent, $customer, 500);
    $payload = collectionPayload($customer, $assignment, $plan, $date, '4000.00');
    $payload['fees'] = [['obligation_id' => $obligation->id, 'amount_ngn' => '5.00']];
    $payload['preview_fingerprint'] = app(CollectionService::class)->preview($agent, $customer, $payload)['preview_fingerprint'];
    $receipt = app(CollectionService::class)->record($agent, $customer, $payload);
    LedgerAccount::query()->whereIn('code', [LedgerAccountCode::UnappliedFunds->value, LedgerAccountCode::BusinessDistributions->value])->update(['mapping_status' => 'mapped']);
    $allocations = DB::table('collection_allocations')->where('collection_receipt_id', $receipt->id)->count();
    expect($allocations)->toBe(2)->and($obligation->fresh()->outstandingAmountKobo())->toBe(0);

    $request = approveReceiptCorrection($this, $agent, $customer, $assignment, LedgerPostingGroup::findOrFail($receipt->savings_posting_group_id));

    $compensation = LedgerPostingGroup::findOrFail($request->fresh()->compensation_posting_group_id);
    expect($request->fresh()->state)->toBe('approved_posted')
        ->and(DB::table('collection_allocation_releases')->count())->toBe($allocations)
        ->and($obligation->fresh()->outstandingAmountKobo())->toBe(500)
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(0)
        ->and((int) $compensation->entries()->where('side', 'debit')->sum('amount_kobo'))->toBe((int) $compensation->entries()->where('side', 'credit')->sum('amount_kobo'))
        ->and(LedgerPostingGroup::query()->where('source_type', $compensation->source_type)->where('source_id', $compensation->source_id)->count())->toBe(1)
        ->and(DB::table('collection_allocations')->where('collection_receipt_id', $receipt->id)->count())->toBe($allocations);
});

test('REV-AC-020: an unpaid withdrawal stays on the Module 08 cancellation and hold path with no reversal', function (): void {
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    enableFixtureMethod();
    $withdrawal = submittedWithdrawal($agent, $customer, $assignment, $plan);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::WithdrawalsReview, AdminPermission::ReversalsReview]);
    $this->actingAs($admin)->withSession(revGapFreshSession())->post(route('withdrawals.approve', $withdrawal), [
        'attempt_reference' => (string) Str::uuid(), 'version' => 1, 'confirmed' => true, 'decision_note' => 'Reviewed',
    ])->assertRedirect();
    DB::transaction(function () use ($customer): void {
        $customer->forceFill(['operational_status' => CustomerStatus::Restricted])->save();
        app(WithdrawalService::class)->applyCustomerStatus($customer, CustomerStatus::Restricted);
    });
    expect($withdrawal->fresh()->held)->toBeTrue()->and($withdrawal->fresh()->state)->toBe('approved');

    $this->actingAs($admin)->withSession(revGapFreshSession())->post(route('withdrawals.revoke', $withdrawal), [
        'attempt_reference' => (string) Str::uuid(), 'version' => $withdrawal->fresh()->version, 'confirmed' => true,
        'internal_reason' => 'Corrected through Module 08', 'customer_explanation' => 'Request withdrawn',
    ])->assertRedirect();
    expect($withdrawal->fresh()->state)->toBe('cancelled')
        ->and(LedgerPostingGroup::query()->where('source_type', 'withdrawal')->count())->toBe(0)
        ->and(ReversalRequest::count())->toBe(0);
});

test('REV-AC-024: after reassignment the compensation keeps the original Agent cash custody', function (): void {
    ['agent' => $agent, 'customer' => $customer, 'original' => $original] = revGapReceipt();
    $originalProfile = AgentProfile::query()->where('user_id', $agent->id)->sole();
    $request = revGapSubmit($this, $agent, $original);
    [, $successorProfile] = revGapReassign($customer);
    $receivable = LedgerAccount::query()->where('code', LedgerAccountCode::AgentReceivable->value)->sole();
    $before = (int) LedgerEntry::query()->where('ledger_account_id', $receivable->id)->where('agent_profile_id', $originalProfile->id)->sum('amount_kobo');

    revGapDecision($this, revGapAdmin(), $request, 'approve')->assertRedirect();

    $compensation = LedgerPostingGroup::findOrFail($request->fresh()->compensation_posting_group_id);
    expect($compensation->entries()->where('ledger_account_id', $receivable->id)->where('agent_profile_id', $successorProfile->id)->count())->toBe(0)
        ->and((int) LedgerEntry::query()->where('ledger_account_id', $receivable->id)->where('agent_profile_id', $successorProfile->id)->sum('amount_kobo'))->toBe(0)
        ->and((int) LedgerEntry::query()->where('ledger_account_id', $receivable->id)->where('agent_profile_id', $originalProfile->id)->sum('amount_kobo'))->toBe($before)
        ->and($request->fresh()->initiating_agent_profile_id)->toBe($originalProfile->id);
});

test('REV-AC-025: a Customer later found Archived records an investigation and requires restoration without mutation', function (): void {
    ['agent' => $agent, 'customer' => $customer, 'original' => $original] = revGapReceipt();
    $request = revGapSubmit($this, $agent, $original);
    $admin = revGapAdmin();
    $fingerprint = app(ReversalService::class)->reviewPreview($admin, $request->fresh())['preview_fingerprint'];
    revGapSetCustomerStatus($customer, CustomerStatus::Archived->value);
    $before = revGapEffectCounts();

    revGapDecision($this, $admin, $request, 'approve', ['preview_fingerprint' => $fingerprint])->assertConflict();

    $after = revGapEffectCounts();
    expect($after['canonical_audit_events'])->toBe($before['canonical_audit_events'] + 1)
        ->and(collect($after)->except('canonical_audit_events')->all())->toBe(collect($before)->except('canonical_audit_events')->all())
        ->and(DB::table('audit_events')->where('event_type', 'reversal.archived_discovery')->where('target_id', $request->id)->count())->toBe(1)
        ->and($request->fresh()->state)->toBe('pending_review');

    revGapSetCustomerStatus($customer, CustomerStatus::Active->value);
    revGapDecision($this, $admin, $request, 'approve')->assertRedirect();
    expect($request->fresh()->state)->toBe('approved_posted');
});

test('REV-AC-022: a fault at any ledger, custody, allocation, plan or decision step commits no decision or effect', function (string $pattern, int $occurrence): void {
    ['agent' => $agent, 'customer' => $customer, 'original' => $original, 'plan' => $plan] = revGapReceipt();
    $request = revGapSubmit($this, $agent, $original);
    $admin = revGapAdmin();
    $tables = ['ledger_posting_groups', 'ledger_entries', 'collection_allocation_releases', 'financial_workflow_supplements',
        'plan_lifecycle_events', 'reversal_attempts', 'reversal_events', 'reversal_notification_intents'];
    $before = collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    $planBefore = $plan->fresh()->getAttributes();
    $seen = 0;
    $armed = true;
    DB::listen(static function (QueryExecuted $query) use (&$seen, &$armed, $pattern, $occurrence): void {
        if ($armed && preg_match($pattern, $query->sql) && ++$seen === $occurrence) {
            $armed = false;
            throw new RuntimeException('Injected correction-step failure.');
        }
    });

    revGapDecision($this, $admin, $request, 'approve')->assertServerError();

    expect(collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all())->toBe($before)
        ->and($plan->fresh()->getAttributes())->toBe($planBefore)
        ->and($request->fresh()->state)->toBe('pending_review')
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(200000);
    revGapDecision($this, $admin, $request, 'approve')->assertRedirect();
    expect($request->fresh()->state)->toBe('approved_posted')
        ->and(LedgerPostingGroup::query()->count())->toBe($before['ledger_posting_groups'] + 1);
})->with([
    'compensation group' => ['/\Ainsert into ["`]ledger_posting_groups["`]/i', 1],
    'custody line' => ['/\Ainsert into ["`]ledger_entries["`]/i', 1],
    'liability line' => ['/\Ainsert into ["`]ledger_entries["`]/i', 2],
    'allocation release' => ['/\Ainsert into ["`]collection_allocation_releases["`]/i', 1],
    'workflow supplement' => ['/\Ainsert into ["`]financial_workflow_supplements["`]/i', 1],
    'plan state' => ['/\Aupdate ["`]thrift_plans["`]/i', 1],
    'plan lifecycle' => ['/\Ainsert into ["`]plan_lifecycle_events["`]/i', 1],
    'request decision' => ['/\Aupdate ["`]reversal_requests["`]/i', 1],
]);

/** @return array{0: User, 1: CustomerProfile, 2: CustomerAssignment, 3: User, 4: LedgerPostingGroup} */
function reversalDeductionFixture(object $test): array
{
    $test->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Lagos'));
    config()->set(['fees.manual_charges_enabled' => true, 'fees.deduction_corrections_enabled' => true]);
    [$agent, $customer, $assignment, $plan] = withdrawalFixture();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    FinancialPeriod::factory()->create();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::DeductionsManage);
    $test->actingAs($admin)->withSession(revGapFreshSession())->post(route('admin.charges.publish'), [
        'publication_reference' => (string) Str::uuid(), 'category_key' => 'approved-deduction', 'kind' => 'deduction',
        'purpose' => 'Approved service category', 'customer_description' => 'Agreed service charge', 'amount_ngn' => '100.01', 'confirmed' => true,
    ])->assertRedirect();
    $category = ChargeCategoryVersion::query()->latest('id')->firstOrFail();
    $payload = reviewManualCharge($test, ['operation_reference' => (string) Str::uuid(), 'customer_id' => $customer->customer_id,
        'plan_id' => $plan->plan_id, 'category_id' => $category->id, 'customer_version' => $customer->version,
        'plan_version' => $plan->version, 'reason' => 'Incorrect charge under investigation', 'confirmed' => true]);
    $test->post(route('admin.charges.assess'), $payload)->assertRedirect();
    $test->travel(1)->days();

    return [$agent, $customer, $assignment, $admin, LedgerPostingGroup::findOrFail(ManualCharge::query()->sole()->ledger_posting_group_id)];
}

test('REV-AC-018: a changed deduction destination blocks correction without effect', function (): void {
    [$agent, , , , $original] = reversalDeductionFixture($this);
    $destination = LedgerAccount::query()->where('code', LedgerAccountCode::OtherDeductionDestination->value)->sole();
    DB::table('ledger_accounts')->where('id', $destination->id)->update(['version' => $destination->version + 1]);
    $before = revGapEffectCounts();

    $this->actingAs($agent)->withSession(['auth.login_at' => now()->timestamp, 'auth.last_active_at' => now()->timestamp])
        ->postJson(route('reversals.preview', $original->posting_reference))->assertConflict()
        ->assertJsonPath('message', 'The approved deduction destination changed and requires an owned correction.');
    expect(revGapEffectCounts())->toBe($before);
});

test('REV-AC-018: a corrected deduction restores savings and a replacement needs its own deduction grant', function (): void {
    [$agent, $customer, $assignment, $admin, $original] = reversalDeductionFixture($this);
    $this->withSession(['auth.login_at' => now()->timestamp, 'auth.last_active_at' => now()->timestamp]);
    $request = approveReceiptCorrection($this, $agent, $customer, $assignment, $original);
    expect($request->fresh()->state)->toBe('approved_posted')
        ->and(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(100000)
        ->and(ManualCharge::query()->count())->toBe(1);

    $reviewer = revGapAdmin();
    $this->actingAs($reviewer)->withSession(revGapFreshSession())->postJson(route('admin.charges.preview'), [
        'customer_id' => $customer->customer_id, 'plan_id' => ThriftPlan::query()->sole()->plan_id,
        'category_id' => ChargeCategoryVersion::query()->sole()->id, 'customer_version' => $customer->fresh()->version,
        'plan_version' => 1, 'reason' => 'Replacement', 'mode' => 'deduction',
    ])->assertForbidden();
    $this->actingAs($agent)->postJson(route('admin.charges.preview'), ['mode' => 'deduction'])->assertForbidden();
    expect(ManualCharge::query()->count())->toBe(1);
});
