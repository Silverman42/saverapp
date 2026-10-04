<?php

use App\Data\LedgerPostingCommand;
use App\Data\LedgerPostingLine;
use App\Enums\AdminPermission;
use App\Enums\FeeLedgerPostingType;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Models\BusinessProfile;
use App\Models\CustomerProfile;
use App\Models\FinancialPeriod;
use App\Models\LedgerEntry;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use App\Services\CollectionLedgerService;
use App\Services\CollectionReadService;
use App\Services\LedgerPostingService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\LedgerTransactionReadService;
use App\Services\PublicIdGenerator;
use App\Services\StatementPreviewService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

require_once __DIR__.'/../LedgerAcceptanceGapFixtures.php';

/** A fee command whose two lines can each be bent to violate one posting rule. */
function ledgerGapFeeCommand(User $actor, CustomerProfile $customer, int $debitKobo, int $creditKobo, LedgerEntrySide $creditSide = LedgerEntrySide::Credit, string $currency = 'NGN', ?int $obligationId = 1): LedgerPostingCommand
{
    return new LedgerPostingCommand(
        FeeLedgerPostingType::SavingsFeeApplication, 'gap-'.Str::uuid(), 'fee_application', 'gap-source-1', $currency, $actor, $customer->id,
        new DateTimeImmutable, [
            new LedgerPostingLine(LedgerAccountCode::CustomerSavingsLiability, LedgerEntrySide::Debit, $debitKobo, $customer->id, null, $obligationId),
            new LedgerPostingLine(LedgerAccountCode::FeeIncome, $creditSide, $creditKobo, $customer->id, null, $obligationId),
        ], 'Gap acceptance fee',
    );
}

test('LED-AC-001: an unbalanced posting command posts no group, entry, projection or audit', function (int $debit, int $credit, LedgerEntrySide $side, string $message): void {
    $actor = User::factory()->agent()->create();
    $customer = CustomerProfile::factory()->create();
    $before = ledgerGapSnapshot();

    expect(fn () => app(LedgerPostingService::class)->postFee(ledgerGapFeeCommand($actor, $customer, $debit, $credit, $side)))
        ->toThrow(ConflictHttpException::class, $message);

    expect(ledgerGapSnapshot())->toEqual($before)
        ->and(LedgerPostingGroup::query()->count())->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe(0)
        ->and(DB::table('canonical_audit_events')->where('event_type', 'ledger.fee_posted')->count())->toBe(0);
})->with([
    'credit smaller than debit' => [1000, 999, LedgerEntrySide::Credit, 'balance exactly'],
    'credit larger than debit' => [1000, 1001, LedgerEntrySide::Credit, 'balance exactly'],
    'both lines debit' => [1000, 1000, LedgerEntrySide::Debit, 'approved posting pattern'],
]);

test('LED-AC-001: balanced posted groups keep equal debit and credit totals in kobo', function (): void {
    [, , , $group] = ledgerGapReceipt();

    $entries = $group->entries()->get();

    expect($entries)->toHaveCount(2)
        ->and($entries->where('side', LedgerEntrySide::Debit)->sum('amount_kobo'))->toBe(200000)
        ->and($entries->where('side', LedgerEntrySide::Credit)->sum('amount_kobo'))->toBe(200000);
});

test('LED-AC-002: the ledger refuses amounts outside the one to 999,999,999,999 kobo window', function (int $amount): void {
    $actor = User::factory()->agent()->create();
    $customer = CustomerProfile::factory()->create();
    $before = ledgerGapSnapshot();

    expect(fn () => app(LedgerPostingService::class)->postFee(ledgerGapFeeCommand($actor, $customer, $amount, $amount)))
        ->toThrow(ConflictHttpException::class, 'approved posting pattern');

    expect(ledgerGapSnapshot())->toEqual($before);
})->with(['zero' => 0, 'negative' => -1, 'cap plus one' => 1_000_000_000_000, 'integer maximum' => PHP_INT_MAX]);

test('LED-AC-002: 2,000.01 naira stays exactly 200001 kobo in entries, balance, projection and statement', function (): void {
    [$agent, $customer, , $group] = ledgerGapReceipt(200001, '2000.01');
    app(LedgerTransactionProjectionService::class)->rebuild();
    $timezone = BusinessProfile::current()->timezone;

    $transactions = app(LedgerTransactionReadService::class);
    $statement = app(StatementPreviewService::class)->preview($agent, $customer, now($timezone)->toDateString(), now($timezone)->toDateString(), $timezone);

    expect($group->entries()->pluck('amount_kobo')->all())->toBe([200001, 200001])
        ->and($transactions->balance($agent, $customer)['liability_kobo'])->toBe(200001)
        ->and($transactions->search($agent, [])['data'][0]['gross_amount_kobo'])->toBe(200001)
        ->and($statement['closing_kobo'])->toBe(200001);
});

test('LED-AC-002: projection totals that exceed the integer range fail closed instead of truncating', function (): void {
    [$agent] = ledgerGapReceipt();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $verified = DB::table('ledger_projection_state')->value('verified_at');
    $cash = DB::table('ledger_accounts')->where('code', LedgerAccountCode::BusinessCash->value)->value('id');
    $group = LedgerPostingGroup::create(['posting_reference' => 'BANK-OVERFLOW-'.Str::uuid(), 'idempotency_key' => 'overflow-'.Str::uuid(),
        'payload_hash' => str_repeat('b', 64), 'source_type' => 'bank_payout_attempt', 'source_id' => 'overflow', 'event_type' => 'bank_payout_attempt',
        'currency' => 'NGN', 'actor_user_id' => $agent->id, 'occurred_at' => now(), 'occurred_on' => now()->toDateString(),
        'business_timezone' => 'Africa/Lagos', 'schema_version' => 1, 'committed_at' => now()]);
    foreach ([[LedgerEntrySide::Debit, PHP_INT_MAX], [LedgerEntrySide::Debit, 10], [LedgerEntrySide::Credit, PHP_INT_MAX], [LedgerEntrySide::Credit, 10]] as $index => [$side, $amount]) {
        LedgerEntry::create(['ledger_posting_group_id' => $group->id, 'line_number' => $index + 1, 'ledger_account_id' => $cash,
            'side' => $side, 'amount_kobo' => $amount]);
    }

    expect(fn () => app(LedgerTransactionProjectionService::class)->rebuild())->toThrow(RuntimeException::class, 'exceed the supported integer range');

    $state = app(LedgerTransactionReadService::class)->state();
    expect($state['status'])->toBe('stale')->and($state['verified_at'])->toBe((string) $verified)
        ->and(DB::table('ledger_integrity_incidents')->where('status', 'open')->count())->toBe(1);
});

test('LED-AC-002: statement totals that exceed the integer range fail closed instead of truncating', function (): void {
    [$agent, $customer] = ledgerGapReceipt();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $state = DB::table('ledger_projection_state')->first();
    $today = now(BusinessProfile::current()->timezone)->toDateString();
    foreach ([PHP_INT_MAX - 5, 10] as $index => $effect) {
        $referenceId = DB::table('ledger_transaction_references')->insertGetId(['root_type' => 'gap_fixture', 'root_id' => 'overflow-'.$index,
            'transaction_reference' => 'TXN-OVERFLOW-'.$index, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ledger_transaction_projections')->insert(['ledger_transaction_reference_id' => $referenceId, 'projection_version' => $state->active_version,
            'customer_profile_id' => $customer->id, 'type' => 'contribution', 'status' => 'posted', 'occurred_on' => $today, 'committed_at' => now(),
            'timezone' => 'Africa/Lagos', 'currency' => 'NGN', 'gross_amount_kobo' => 1, 'savings_effect_kobo' => $effect, 'fee_amount_kobo' => 0,
            'posting_group_count' => 1, 'source_max_group_id' => $state->ledger_group_watermark, 'source_hash' => str_repeat('d', 64),
            'created_at' => now(), 'updated_at' => now()]);
    }

    expect(app(StatementPreviewService::class)->preview($agent, $customer, $today, $today, 'Africa/Lagos'))
        ->toMatchArray(['status' => 'unavailable', 'message' => 'Statement totals exceed the supported range.']);
});

test('LED-AC-005: a posting with a missing or mismatched Customer, Agent, currency or obligation dimension leaves nothing behind', function (string $defect, string $exception, string $message): void {
    [$agent, $customer] = ledgerGapReceipt();
    $receipt = DB::table('collection_receipts')->first();
    $other = CustomerProfile::factory()->create();
    $before = ledgerGapSnapshot();
    $attempt = match ($defect) {
        'missing fee customer' => fn () => app(LedgerPostingService::class)->postFee(new LedgerPostingCommand(FeeLedgerPostingType::SavingsFeeApplication,
            'gap-'.Str::uuid(), 'fee_application', 'gap-source-2', 'NGN', $agent, null, new DateTimeImmutable, [], 'Gap acceptance fee')),
        'foreign currency' => fn () => app(LedgerPostingService::class)->postFee(ledgerGapFeeCommand($agent, $customer, 500, 500, currency: 'USD')),
        'missing obligation' => fn () => app(LedgerPostingService::class)->postFee(ledgerGapFeeCommand($agent, $customer, 500, 500, obligationId: null)),
        'savings for another customer' => fn () => DB::transaction(fn () => app(CollectionLedgerService::class)
            ->postCashSavings($receipt->id, $other->id, $receipt->recording_agent_profile_id, 200000, $agent)),
        'savings for another agent' => fn () => DB::transaction(fn () => app(CollectionLedgerService::class)
            ->postCashSavings($receipt->id, $customer->id, $receipt->recording_agent_profile_id + 99, 200000, $agent)),
        'remittance with no authoritative source' => fn () => DB::transaction(fn () => app(CollectionLedgerService::class)
            ->postCashRemittance(999, $receipt->recording_agent_profile_id, 200000, $agent)),
    };

    expect($attempt)->toThrow($exception, $message);

    expect(ledgerGapSnapshot())->toEqual($before);
})->with([
    'missing fee customer' => ['missing fee customer', ModelNotFoundException::class, 'No query results'],
    'foreign currency' => ['foreign currency', ConflictHttpException::class, 'unsupported source, currency'],
    'missing obligation' => ['missing obligation', ConflictHttpException::class, 'approved posting pattern'],
    'savings for another customer' => ['savings for another customer', ConflictHttpException::class, 'dimensions changed'],
    'savings for another agent' => ['savings for another agent', ConflictHttpException::class, 'dimensions changed'],
    'remittance with no authoritative source' => ['remittance with no authoritative source', ConflictHttpException::class, 'authoritative cash source'],
]);

test('LED-AC-005: a stored group missing its Customer, Agent or cycle dimension blocks projection promotion and balances', function (string $damage): void {
    [$agent, $customer, , $group] = ledgerGapReceipt();
    app(LedgerTransactionProjectionService::class)->rebuild();
    [$admin] = ledgerGapRemittance();
    app(LedgerTransactionProjectionService::class)->rebuild();
    match ($damage) {
        'customer on liability line' => DB::table('ledger_entries')->where('ledger_posting_group_id', $group->id)->where('side', 'credit')->update(['customer_profile_id' => null]),
        'agent on receivable line' => DB::table('ledger_entries')->where('ledger_posting_group_id', $group->id)->where('side', 'debit')->update(['agent_profile_id' => null]),
        'cycle on group' => DB::table('ledger_posting_groups')->where('id', $group->id)->update(['thrift_plan_id' => null]),
    };

    expect(fn () => app(LedgerTransactionProjectionService::class)->rebuild())->toThrow(RuntimeException::class);

    expect(DB::table('ledger_integrity_incidents')->where('status', 'open')->count())->toBe(1)
        ->and(app(LedgerTransactionReadService::class)->state()['status'])->toBe('stale');
})->with(['customer on liability line', 'agent on receivable line', 'cycle on group']);

test('LED-AC-006: concurrent-style reference generation is globally unique and the store refuses a duplicate', function (): void {
    $generator = app(PublicIdGenerator::class);

    $numbers = collect(range(1, 40))->map(fn (): string => $generator->generate('ledger_transaction'));

    expect($numbers->unique())->toHaveCount(40);
    DB::table('ledger_transaction_references')->insert(['root_type' => 'gap_fixture', 'root_id' => 'one', 'transaction_reference' => 'TXN-20261001-000001',
        'created_at' => now(), 'updated_at' => now()]);
    expect(fn () => DB::table('ledger_transaction_references')->insert(['root_type' => 'gap_fixture', 'root_id' => 'two', 'transaction_reference' => 'TXN-20261001-000001',
        'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
});

test('LED-AC-006: knowing a transaction reference outside the viewer scope yields 404, never 403 or the record', function (): void {
    [$agent, $customer, , $group] = ledgerGapReceipt();
    [$otherAgent, $otherCustomer] = ledgerGapSecondCustomer();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $reference = app(LedgerTransactionReadService::class)->search($agent, [])['data'][0]['reference'];

    $this->actingAs($otherCustomer->user)->get(route('transactions.show', $reference))->assertNotFound();
    $this->actingAs($otherAgent)->get(route('transactions.show', $reference))->assertNotFound();
    $this->get(route('customers.ledger-balance', $customer->customer_id))->assertNotFound();
    $this->get(route('customers.statements.preview', $customer->customer_id))->assertNotFound();
    $this->actingAs($agent)->get(route('transactions.show', $reference))->assertOk();
    $this->actingAs($customer->user)->get(route('transactions.show', $reference))->assertOk();
});

test('LED-AC-006: a cursor cannot be replayed by another viewer, another filter set or after tampering', function (): void {
    [$agent, $customer] = ledgerGapReceipt();
    [$otherAgent, $otherCustomer] = ledgerGapSecondCustomer();
    app(LedgerTransactionProjectionService::class)->rebuild();
    ledgerGapProjectionRows($customer, 5, '2026-10-01', '2026-10-01 08:00:00', 60);
    $reads = app(LedgerTransactionReadService::class);
    $cursor = $reads->search($agent, ['page_size' => 2])['next_cursor'];
    expect($cursor)->not->toBeNull();

    foreach ([
        'another Agent' => fn () => $reads->search($otherAgent, ['page_size' => 2, 'cursor' => $cursor]),
        'the Customer' => fn () => $reads->search($customer->user, ['page_size' => 2, 'cursor' => $cursor]),
        'another filter' => fn () => $reads->search($agent, ['page_size' => 2, 'type' => 'withdrawal', 'cursor' => $cursor]),
        'tampered' => fn () => $reads->search($agent, ['page_size' => 2, 'cursor' => substr($cursor, 0, -4).'AAAA']),
    ] as $replay) {
        expect($replay)->toThrow(UnprocessableEntityHttpException::class);
    }

    $this->actingAs($otherAgent)->get(route('transactions.index', ['cursor' => $cursor]))->assertUnprocessable();
    expect($reads->search($agent, ['page_size' => 2, 'cursor' => $cursor])['data'])->toHaveCount(2);
});

test('LED-AC-007: even an Admin with every permission has no route, field or command that accepts journal lines', function (): void {
    [$agent, $customer, , $group] = ledgerGapReceipt();
    app(LedgerTransactionProjectionService::class)->rebuild();
    $admin = ledgerGapAdmin(array_map(fn (string $value): AdminPermission => AdminPermission::from($value), AdminPermission::values()));
    $before = ledgerGapSnapshot();
    $lines = [['account' => 'customer_savings_liability_ngn', 'side' => 'credit', 'amount_kobo' => 1_000_000]];
    $mutating = collect(Route::getRoutes()->getRoutes())->filter(fn ($route): bool => array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== []);

    $routesThatMentionTheLedger = $mutating->map(fn ($route): string => $route->uri())->filter(fn (string $uri): bool => str_contains($uri, 'ledger')
        || str_contains($uri, 'journal') || str_contains($uri, 'transactions') || str_contains($uri, 'balance'))->values()->all();
    expect(collect(Route::getRoutes()->getRoutes())->map(fn ($route): string => $route->uri().'|'.$route->getName())->filter(fn (string $name): bool => str_contains($name, 'journal') || str_contains($name, 'adjust'))->all())->toBe([])
        ->and($routesThatMentionTheLedger)->toEqualCanonicalizing(['ledger-postings/{posting}/reversals', 'ledger-postings/{posting}/reversals/preview', 'ledger/incidents/{reference}/resolve']);

    $this->actingAs($admin)->withSession(ledgerGapFreshSession());
    $this->post('/transactions', ['lines' => $lines])->assertStatus(405);
    $this->post('/ledger/journals', ['lines' => $lines])->assertNotFound();
    $this->post(route('ledger.incidents.resolve', (string) Str::uuid()), ['lines' => $lines, 'note' => 'Journal fix', 'confirmed' => true])->assertNotFound();
    $this->post(route('customers.statements.issue', $customer->customer_id), ['lines' => $lines, 'confirmed' => true])->assertSessionHasErrors('lines');
    $this->post(route('reversals.store', $group->posting_reference), ['lines' => $lines, 'confirmed' => true])->assertSessionHasErrors('lines');

    expect(ledgerGapSnapshot())->toEqual($before)->and(DB::table('reversal_requests')->count())->toBe(0);
});

test('LED-AC-007: the posting boundary accepts only closed owner patterns, never arbitrary client lines', function (): void {
    $actor = User::factory()->agent()->create();
    $customer = CustomerProfile::factory()->create();
    $before = ledgerGapSnapshot();
    $manual = new LedgerPostingCommand(FeeLedgerPostingType::SavingsFeeApplication, 'gap-'.Str::uuid(), 'fee_application', 'gap-source-3', 'NGN', $actor,
        $customer->id, new DateTimeImmutable, [
            new LedgerPostingLine(LedgerAccountCode::BusinessCash, LedgerEntrySide::Debit, 5000, $customer->id, null, 1),
            new LedgerPostingLine(LedgerAccountCode::CustomerSavingsLiability, LedgerEntrySide::Credit, 5000, $customer->id, null, 1),
        ], 'Manual journal');
    $unknownSource = new LedgerPostingCommand(FeeLedgerPostingType::SavingsFeeApplication, 'gap-'.Str::uuid(), 'admin_manual_journal', 'gap-source-4', 'NGN', $actor,
        $customer->id, new DateTimeImmutable, [
            new LedgerPostingLine(LedgerAccountCode::CustomerSavingsLiability, LedgerEntrySide::Debit, 5000, $customer->id, null, 1),
            new LedgerPostingLine(LedgerAccountCode::FeeIncome, LedgerEntrySide::Credit, 5000, $customer->id, null, 1),
        ], 'Manual journal');
    $disabled = new LedgerPostingCommand(FeeLedgerPostingType::OtherDeduction, 'gap-'.Str::uuid(), 'manual_charge', 'gap-source-5', 'NGN', $actor,
        $customer->id, new DateTimeImmutable, [
            new LedgerPostingLine(LedgerAccountCode::CustomerSavingsLiability, LedgerEntrySide::Debit, 5000, $customer->id, null, 1),
            new LedgerPostingLine(LedgerAccountCode::OtherDeductionDestination, LedgerEntrySide::Credit, 5000, $customer->id, null, 1),
        ], 'Manual journal');

    foreach ([$manual, $unknownSource, $disabled] as $command) {
        expect(fn () => app(LedgerPostingService::class)->postFee($command))->toThrow(ConflictHttpException::class);
    }

    expect(ledgerGapSnapshot())->toEqual($before);
});

test('LED-AC-008: remittance posts Dr Business cash and Cr Agent receivable and never touches Customer liability', function (): void {
    [$agent, $customer, , $receiptGroup] = ledgerGapReceipt();
    $position = app(CollectionReadService::class)->position($customer);
    $receiptAgent = DB::table('collection_receipts')->value('recording_agent_profile_id');

    [, $remittanceId, $group] = ledgerGapRemittance();

    $entries = $group->entries()->with('account')->get();
    expect($entries)->toHaveCount(2)
        ->and([$entries[0]->account->code, $entries[0]->side, $entries[0]->amount_kobo])->toBe([LedgerAccountCode::BusinessCash, LedgerEntrySide::Debit, 200000])
        ->and([$entries[1]->account->code, $entries[1]->side, $entries[1]->amount_kobo])->toBe([LedgerAccountCode::AgentReceivable, LedgerEntrySide::Credit, 200000])
        ->and($entries[1]->agent_profile_id)->toBe($receiptAgent)
        ->and($entries->pluck('customer_profile_id')->filter()->all())->toBe([])
        ->and($group->customer_profile_id)->toBeNull()
        ->and($entries->pluck('account.code')->contains(LedgerAccountCode::CustomerSavingsLiability))->toBeFalse()
        ->and(app(CollectionReadService::class)->position($customer))->toBe($position);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $remittance = DB::table('ledger_transaction_projections')->where('type', 'remittance')->sole();
    expect($remittance->savings_effect_kobo)->toBe(0)->and($remittance->customer_profile_id)->toBeNull()->and($remittance->gross_amount_kobo)->toBe(200000);
});

test('LED-AC-014: a late receipt keeps its occurrence date, commits later in UTC and sorts and cuts off reproducibly', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00:00', 'Africa/Lagos'));
    [$agent, $customer, $assignment, , $plan] = ledgerGapReceipt(200000, '2000.00', 2, -1);
    $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00:00', 'Africa/Lagos'));
    $late = ledgerGapRecordReceipt($agent, $customer, $assignment, $plan, '2026-10-01', '2000.00', 'Cash counted after returning from the field.');

    $lateGroup = LedgerPostingGroup::findOrFail($late->savings_posting_group_id);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $reads = app(LedgerTransactionReadService::class);
    $statements = app(StatementPreviewService::class);
    $first = $reads->search($agent, [])['data'];
    $window = $statements->preview($agent, $customer, '2026-10-01', '2026-10-01', 'Africa/Lagos');
    app(LedgerTransactionProjectionService::class)->rebuild();
    $second = $reads->search($agent, [])['data'];
    $again = $statements->preview($agent, $customer, '2026-10-01', '2026-10-01', 'Africa/Lagos');

    expect($lateGroup->occurred_on->toDateString())->toBe('2026-10-01')
        ->and($lateGroup->occurred_at->utc()->toDateTimeString())->toBe('2026-09-30 23:00:00')
        ->and($lateGroup->committed_at->utc()->toDateTimeString())->toBe('2026-10-02 11:00:00')
        ->and($lateGroup->committed_at->greaterThan($lateGroup->occurred_at))->toBeTrue()
        ->and(array_column($first, 'occurred_on'))->toBe(['2026-10-01', '2026-10-02'])
        ->and(array_column($first, 'committed_at'))->toBe(['2026-10-02 11:00:00', '2026-10-02 09:00:00'])
        ->and(array_column($second, 'reference'))->toBe(array_column($first, 'reference'))
        ->and([$window['opening_kobo'], $window['activity_kobo'], $window['closing_kobo']])->toBe([0, 200000, 200000])
        ->and(array_column($window['lines'], 'committed_at'))->toBe(['2026-10-02 11:00:00'])
        ->and([$again['opening_kobo'], $again['activity_kobo'], $again['closing_kobo'], $again['lines']])->toBe([0, 200000, 200000, $window['lines']]);
});

test('LED-AC-024: no override backdates a posting into a closed month or edits an occurrence date', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00:00', 'Africa/Lagos'));
    [$agent, $customer, $assignment, $group, $plan] = ledgerGapReceipt(200000, '2000.00', 2, -1);
    FinancialPeriod::query()->whereDate('month', '2026-09-01')->update(['status' => 'closed']);
    $before = ledgerGapSnapshot();

    expect(fn () => ledgerGapRecordReceipt($agent, $customer, $assignment, $plan, '2026-09-30', '2000.00', 'Late cash from a closed month.'))
        ->toThrow(ConflictHttpException::class, 'not open');
    expect(fn () => $group->update(['occurred_on' => '2026-09-30']))->toThrow(RuntimeException::class, 'immutable');

    expect(ledgerGapSnapshot())->toEqual($before)
        ->and(array_filter(AdminPermission::values(), fn (string $permission): bool => str_contains($permission, 'backdate') || str_contains($permission, 'override')))->toBe([]);
});

test('LED-AC-016: simultaneous live gross reservations are subtracted once and an unreserved request changes nothing', function (): void {
    [$agent, $customer, , , $plan] = ledgerGapReceipt(50000, '500.00');
    app(LedgerTransactionProjectionService::class)->rebuild();
    $reads = app(LedgerTransactionReadService::class);
    $reserve = fn (string $reference, int $kobo, string $status) => DB::table('withdrawal_reservations')->insert(['customer_profile_id' => $customer->id,
        'thrift_plan_id' => $plan->id, 'owner_reference' => $reference, 'gross_amount_kobo' => $kobo, 'status' => $status, 'version' => 1,
        'created_at' => now(), 'updated_at' => now()]);
    expect($reads->balance($agent, $customer))->toBe(['status' => 'ready', 'liability_kobo' => 50000, 'reservations_kobo' => 0, 'available_kobo' => 50000]);

    $reserve('WDL-GAP-1', 12000, 'live');
    $reserve('WDL-GAP-2', 8000, 'live');
    $reserve('WDL-GAP-3', 4000, 'released');
    $reserve('WDL-GAP-4', 3000, 'consumed');

    expect($reads->balance($agent, $customer))->toBe(['status' => 'ready', 'liability_kobo' => 50000, 'reservations_kobo' => 20000, 'available_kobo' => 30000])
        ->and($reads->balances($agent, [$customer])[$customer->id])->toBe(['status' => 'ready', 'liability_kobo' => 50000, 'reservations_kobo' => 20000, 'available_kobo' => 30000])
        ->and(app(CollectionReadService::class)->position($customer))->toBe(['liability_kobo' => 50000, 'reservations_kobo' => 20000, 'available_kobo' => 30000]);
});
