<?php

use App\Enums\AdminPermission;
use App\Enums\CustomerStatus;
use App\Models\BankPayoutAttempt;
use App\Models\CollectionReceipt;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\BankPayoutService;
use App\Services\CollectionLedgerService;
use App\Services\WithdrawalBalanceService;
use App\Services\WithdrawalService;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/../BankPayoutMySqlFixtures.php';

beforeEach(function (): void {
    if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'saverapp_audit_testing') {
        $this->markTestSkipped('Requires the isolated saverapp_audit_testing MySQL database.');
    }
    $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();
    config()->set('withdrawals.bank.fake_store', 'file');
});

/** @param array<string, mixed> $submission */
function withdrawalMysqlSubmit(int $agentId, int $customerId, array $submission): Closure
{
    return bankMysqlWorker(static function () use ($agentId, $customerId, $submission): string {
        try {
            app(WithdrawalService::class)->submit(User::findOrFail($agentId), CustomerProfile::findOrFail($customerId), $submission);

            return 'submitted';
        } catch (ConflictHttpException|ValidationException) {
            return 'blocked';
        }
    });
}

function withdrawalMysqlDecide(int $adminId, int $withdrawalId, string $action): Closure
{
    return bankMysqlWorker(static function () use ($adminId, $withdrawalId, $action): string {
        $request = Request::create('/withdrawals/decide', 'POST');
        $session = new Store('withdrawal-worker', new ArraySessionHandler(600));
        $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
            'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
        $request->setLaravelSession($session);
        try {
            app(WithdrawalService::class)->decide(User::findOrFail($adminId), WithdrawalRequest::findOrFail($withdrawalId), $action, [
                'attempt_reference' => (string) Str::uuid(), 'version' => 1, 'confirmed' => true,
                'decision_note' => 'Race decision', 'internal_reason' => 'Race decision', 'customer_explanation' => 'Race decision',
            ], $request);

            return $action;
        } catch (ConflictHttpException) {
            return 'blocked';
        }
    });
}

function withdrawalMysqlDeposit(int $receiptId, int $customerId, int $agentProfileId, int $amount, int $agentId): Closure
{
    return bankMysqlWorker(static function () use ($receiptId, $customerId, $agentProfileId, $amount, $agentId): string {
        DB::transaction(function () use ($receiptId, $customerId, $agentProfileId, $amount, $agentId): void {
            $group = app(CollectionLedgerService::class)->postCashSavings($receiptId, $customerId, $agentProfileId, $amount, User::findOrFail($agentId));
            CollectionReceipt::query()->whereKey($receiptId)->update(['savings_posting_group_id' => $group->id]);
        });

        return 'deposited';
    });
}

/** @return array<string, mixed> */
function withdrawalMysqlSubmission(User $agent, CustomerProfile $customer, array $request): array
{
    $quote = app(WithdrawalService::class)->preview($agent, $customer->fresh(), $request);

    return [...$request, 'attempt_reference' => (string) Str::uuid(),
        'preview_fingerprint' => $quote['preview_fingerprint'], 'quote_expires_at' => $quote['quote_expires_at'],
        'customer_version' => $quote['customer_version'], 'assignment_version' => $quote['assignment_version'],
        'plan_version' => $quote['plan_version'], 'business_version' => $quote['business_version'],
        'instruction_attested' => true, 'confirmed' => true];
}

test('WDL-AC-014 mysql two simultaneous submissions reserve once', function (): void {
    Queue::fake();
    [, $agent, $customer, $plan, $withdrawal, $destination] = bankPayoutFixture($this, '1', '300.00', approve: false);
    DB::transaction(fn () => app(WithdrawalService::class)->decide($agent, $withdrawal, 'cancel', [
        'attempt_reference' => (string) Str::uuid(), 'version' => 1, 'confirmed' => true, 'internal_reason' => 'Reset for race',
    ], Request::create('/')));
    $request = ['plan_id' => $plan->plan_id, 'type' => 'partial', 'method' => 'bank_transfer',
        'destination_reference' => $destination->destination_reference, 'reason' => 'Race request', 'internal_notes' => ''];
    $first = withdrawalMysqlSubmission($agent, $customer, [...$request, 'gross_ngn' => '300.00']);
    $second = withdrawalMysqlSubmission($agent, $customer, [...$request, 'gross_ngn' => '250.00']);

    $outcomes = bankMysqlRun([
        withdrawalMysqlSubmit($agent->id, $customer->id, $first),
        withdrawalMysqlSubmit($agent->id, $customer->id, $second),
    ]);

    sort($outcomes);
    $position = app(WithdrawalBalanceService::class)->position($customer->fresh(), $plan->fresh());
    expect($outcomes)->toBe(['blocked', 'submitted'])
        ->and(DB::table('withdrawal_reservations')->where('status', 'live')->count())->toBe(1)
        ->and($position['available_kobo'])->toBeGreaterThanOrEqual(0)
        ->and($position['liability_kobo'])->toBeGreaterThanOrEqual($position['reservations_kobo']);
});

test('WDL-AC-018 mysql two Admin decisions commit exactly one outcome and post no money', function (): void {
    Queue::fake();
    [$admin, , , , $withdrawal] = bankPayoutFixture($this, '1', '300.00', approve: false);
    $other = User::factory()->admin()->withTwoFactor()->create();
    $other->givePermissionTo(AdminPermission::WithdrawalsReview);
    $entries = DB::table('ledger_entries')->count();

    $outcomes = bankMysqlRun([
        withdrawalMysqlDecide($admin->id, $withdrawal->id, 'approve'),
        withdrawalMysqlDecide($other->id, $withdrawal->id, 'reject'),
    ]);

    expect(array_values(array_diff($outcomes, ['blocked'])))->toHaveCount(1)
        ->and($withdrawal->fresh()->state)->toBeIn(['approved', 'rejected'])
        ->and(DB::table('withdrawal_events')->whereIn('event_type', ['approve', 'reject'])->count())->toBe(1)
        ->and(DB::table('ledger_entries')->count())->toBe($entries);
});

test('WDL-AC-033 mysql a deposit racing a payout start serializes without overdraw', function (): void {
    Queue::fake();
    [$admin, $agent, $customer, $plan, $withdrawal] = bankPayoutFixture($this, '1');
    $template = CollectionReceipt::query()->where('customer_profile_id', $customer->id)->firstOrFail();
    $receipt = $template->replicate(['savings_posting_group_id'])->fill([
        'method' => 'cash', 'custody_account_code' => 'agent_receivable_ngn', 'collection_method_version_id' => null,
        'collection_payment_evidence_id' => null, 'collection_evidence_review_id' => null, 'method_reference' => null,
        'receipt_reference' => 'TXN-RACE-'.Str::upper(Str::random(6)), 'attempt_reference' => (string) Str::uuid(),
        'tender_amount_kobo' => 50000, 'savings_amount_kobo' => 50000, 'fee_amount_kobo' => 0, 'recorded_at' => now(),
    ]);
    $receipt->save();
    $before = app(WithdrawalBalanceService::class)->position($customer, $plan)['liability_kobo'];

    $outcomes = bankMysqlRun([
        bankMysqlStart($admin->id, $withdrawal->id, (string) Str::uuid(), $withdrawal->version),
        withdrawalMysqlDeposit($receipt->id, $customer->id, $agent->agentProfile->id, 50000, $agent->id),
    ]);

    $position = app(WithdrawalBalanceService::class)->position($customer->fresh(), $plan->fresh());
    expect($outcomes)->toBe(['started', 'deposited'])
        ->and(BankPayoutAttempt::query()->count())->toBe(1)
        ->and($position['available_kobo'])->toBeGreaterThanOrEqual(0)
        ->and($position['liability_kobo'])->toBeGreaterThanOrEqual($position['reservations_kobo'])
        ->and($position['liability_kobo'])->toBeIn([$before + 50000, $before + 50000 - $withdrawal->gross_amount_kobo]);
});

test('WDL-AC-044 mysql execution-first resolves the same attempt to Payment failed with the hold active', function (): void {
    Queue::fake();
    [$admin, , $customer, , $withdrawal] = bankPayoutFixture($this, '2');

    $outcomes = bankMysqlRun([
        bankMysqlStart($admin->id, $withdrawal->id, (string) Str::uuid(), $withdrawal->version),
        bankMysqlRestrict($customer->id),
    ]);

    expect($outcomes[1])->toBe('restricted');
    $attempt = BankPayoutAttempt::query()->first();
    if ($attempt === null) {
        expect($outcomes[0])->toBe('blocked')->and($withdrawal->fresh()->held)->toBeTrue()
            ->and($withdrawal->fresh()->state)->toBe('approved');

        return;
    }
    config()->set(['withdrawals.bank_enabled' => true, 'withdrawals.bank_certified' => true, 'withdrawals.bank.provider' => 'fake']);
    app(BankPayoutService::class)->dispatch($attempt->id);

    $fresh = $withdrawal->fresh();
    expect($fresh->state)->toBe('payment_failed')->and($fresh->held)->toBeTrue()
        ->and($fresh->hold_reason)->toBe('customer_restricted')
        ->and(BankPayoutAttempt::query()->count())->toBe(1)
        ->and(DB::table('withdrawal_reservations')->value('status'))->toBe('live')
        ->and($customer->fresh()->operational_status)->toBe(CustomerStatus::Restricted);
});
