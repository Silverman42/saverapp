<?php

use App\Enums\AdminPermission;
use App\Enums\LedgerAccountCode;
use App\Http\Controllers\ReconciliationController;
use App\Models\CollectionBatch;
use App\Models\CollectionException;
use App\Models\CustomerProfile;
use App\Models\FinancialWorkflowSupplement;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\CollectionBatchPosition;
use App\Services\CollectionExceptionResolution;
use App\Services\CollectionMethodCatalogue;
use App\Services\CollectionPaymentEvidenceService;
use App\Services\CollectionService;
use App\Services\FinancialCashPosition;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/../CollectionFixtures.php';
require_once __DIR__.'/../NoncashCollectionFixtures.php';
require_once __DIR__.'/../CollectionExceptionFixtures.php';

beforeEach(function (): void {
    if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'saverapp_audit_testing') {
        $this->markTestSkipped('Requires the isolated saverapp_audit_testing MySQL database.');
    }
    $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();
    config()->set(['collections.enabled' => true, 'collections.noncash_enabled' => true,
        'collections.evidence_scanner_binary' => '/opt/clamav/bin/clamdscan', 'collections.evidence_scanner_version' => 'test-signatures-1']);
    Storage::fake('collection_evidence');
    Process::fake(['*clamdscan*' => Process::result()]);
});

function noncashMysqlClaim(int $actorId, int $customerId, array $payload, string $evidenceRoot): Closure
{
    return static function () use ($actorId, $customerId, $payload, $evidenceRoot): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe noncash race database.');
        }
        config()->set(['collections.enabled' => true, 'collections.noncash_enabled' => true, 'filesystems.disks.collection_evidence.root' => $evidenceRoot]);
        $actor = User::query()->findOrFail($actorId);
        $customer = CustomerProfile::query()->findOrFail($customerId);
        try {
            $service = app(CollectionService::class);
            $payload['preview_fingerprint'] = $service->preview($actor, $customer, $payload)['preview_fingerprint'];
            $service->record($actor, $customer, $payload);

            return 'posted';
        } catch (ConflictHttpException) {
            return 'blocked';
        }
    };
}

test('mysql competing receipts consume one independently verified payment exactly once', function (): void {
    [$agent, $customer, $assignment, $plan, $date] = collectionFixture(3);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo([AdminPermission::BusinessSettingsManage, AdminPermission::ReconciliationManage]);
    LedgerAccount::query()->where('code', 'business_bank_ngn')->update(['mapping_status' => 'mapped']);
    $request = Request::create('/collection-methods', 'POST');
    $session = new Store('noncash-test', new ArraySessionHandler(600));
    $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp, 'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
    $request->setLaravelSession($session);
    app()->instance('request', $request);
    $method = app(CollectionMethodCatalogue::class)->publish($admin, [
        'publication_reference' => (string) Str::uuid(), 'method_key' => 'transfer', 'version' => 1,
        'label' => 'Verified test bank', 'custody_account_code' => 'business_bank_ngn', 'mapping_version' => 1,
        'destination_key' => 'test-bank', 'attachment_required' => true, 'reason' => 'Approved isolated test destination.',
    ]);
    $reference = (string) Str::uuid();
    app(CollectionPaymentEvidenceService::class)->store($agent, $customer, [
        'evidence_reference' => $reference, 'customer_version' => $customer->version, 'assignment_version' => $assignment->version,
        'collection_method_version_id' => $method, 'method_reference' => 'BANK-RACE-1', 'received_date' => $date,
        'amount_ngn' => '2000.00', 'source_attestation' => 'Customer supplied the payment confirmation.',
    ], [UploadedFile::fake()->image('proof.png')]);
    app(CollectionPaymentEvidenceService::class)->review($admin, $reference, [
        'operation_reference' => (string) Str::uuid(), 'expected_version' => 0, 'outcome' => 'verified',
        'reason' => 'Independent bank receipt confirmed.', 'verified_reference' => 'BANK-RACE-1',
        'verified_amount_ngn' => '2000.00', 'verified_destination_key' => 'test-bank',
    ]);
    $payload = [...collectionPayload($customer, $assignment, $plan, $date, '2000.00'),
        'method' => 'transfer', 'collection_method_version_id' => $method, 'evidence_reference' => $reference];
    $root = Storage::disk('collection_evidence')->path('');
    $outcomes = Concurrency::driver('process')->run([
        noncashMysqlClaim($agent->id, $customer->id, $payload, $root),
        noncashMysqlClaim($agent->id, $customer->id, [...$payload, 'attempt_reference' => (string) Str::uuid()], $root),
    ]);
    sort($outcomes);
    expect($outcomes)->toBe(['blocked', 'posted']);
    $this->assertDatabaseCount('collection_receipts', 1);
    $this->assertDatabaseCount('ledger_posting_groups', 1);
    $this->assertDatabaseCount('collection_batches', 1);
    $this->assertDatabaseCount('collection_notification_intents', 2);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::BusinessBank))->toBe(200000)
        ->and(DB::table('ledger_entries')->whereNotNull('agent_profile_id')->count())->toBe(0);
});

function noncashExceptionMysqlTask(int $actorId, int $batchId, int $exceptionId, string $action, array $payload, string $evidenceRoot): Closure
{
    return static function () use ($actorId, $batchId, $exceptionId, $action, $payload, $evidenceRoot): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe exception race database.');
        }
        config()->set(['collections.enabled' => true, 'collections.noncash_enabled' => true,
            'filesystems.disks.collection_evidence.root' => $evidenceRoot]);
        $actor = User::query()->findOrFail($actorId);
        $request = Request::create('/collection-batches/exceptions/'.$action, 'POST', $payload);
        $session = new Store('exception-race', new ArraySessionHandler(600));
        $session->start();
        $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
            'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
        $request->setLaravelSession($session);
        app()->instance('request', $request);
        $request->setUserResolver(static fn (): User => $actor);
        try {
            $controller = app(ReconciliationController::class);
            $batch = CollectionBatch::query()->findOrFail($batchId);
            $exception = CollectionException::query()->findOrFail($exceptionId);
            if ($action === 'resolve') {
                $controller->resolveException($batch, $exception, $request, app(AuthorizationService::class));

                return 'resolved';
            }
            if ($action === 'reopen') {
                $controller->reopenException($batch, $exception, $request, app(AuthorizationService::class));

                return 'reopened';
            }
            throw new RuntimeException('Unsupported exception race action.');
        } catch (ConflictHttpException) {
            return 'blocked';
        }
    };
}

function noncashExceptionMysqlFinancialRows(): array
{
    $rows = [];
    foreach (['collection_payment_evidence', 'collection_evidence_files', 'collection_evidence_reviews', 'collection_method_versions',
        'collection_receipts', 'collection_allocations', 'collection_fee_components', 'ledger_posting_groups',
        'ledger_entries', 'fee_obligations', 'fee_obligation_entries', 'cash_remittances', 'collection_settlements',
        'collection_notification_intents', 'reversal_requests'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return $rows;
}

test('mysql competing exception resolution reopening and renewed resolution retain one owner per version', function (string $method, string $custody): void {
    [, $customer, , , , $admin, , $payload] = noncashFixture($this, $method, $custody);
    $receipt = postNoncashReceipt($this, $customer, $payload);
    $this->travel(1)->days();
    $this->artisan('collections:freeze-batches')->assertSuccessful();
    $this->travelBack();
    $this->actingAs($admin)->post(route('collection-batches.exceptions.store', $receipt->batch), [
        'batch_version' => $receipt->batch->fresh()->version, 'kind' => 'missing_transfer', 'amount_ngn' => '2000.00',
        'reason' => 'Original payment needs an independently matched resolution.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $exception = CollectionException::query()->sole();
    $other = User::factory()->admin()->withTwoFactor()->create();
    $other->givePermissionTo(AdminPermission::ReconciliationManage);
    $financial = noncashExceptionMysqlFinancialRows();
    $position = app(CollectionBatchPosition::class)->read($receipt->batch);
    $root = Storage::disk('collection_evidence')->path('');
    $initialVersion = $receipt->batch->fresh()->version;
    $opened = DB::table('collection_exception_events')->sole();
    $earlierEvents = [$opened];
    $earlierProofs = [];
    Process::preventStrayProcesses(false);

    foreach (['resolve', 'reopen', 'resolve'] as $index => $action) {
        $version = $initialVersion + $index;
        $data = ['batch_version' => $version, 'reason' => $action === 'reopen'
            ? 'New contradictory reference requires renewed independent investigation.'
            : 'Original payment independently matches the complete retained receipt.', 'confirmed' => true];
        if ($action === 'resolve') {
            $data = [...$data, 'resolution_kind' => 'verified_match', 'receipt_reference' => $receipt->receipt_reference];
        }
        $outcomes = Concurrency::driver('process')->run([
            noncashExceptionMysqlTask($admin->id, $receipt->collection_batch_id, $exception->id, $action, $data, $root),
            noncashExceptionMysqlTask($other->id, $receipt->collection_batch_id, $exception->id, $action,
                [...$data, 'reason' => 'Independent second reviewer: '.$data['reason']], $root),
        ]);
        sort($outcomes);
        $status = $action === 'resolve' ? 'resolved' : 'reopened';
        expect($outcomes)->toBe(['blocked', $status]);
        expect($receipt->batch->fresh()->version)->toBe($version + 1);
        expect($exception->fresh()->status)->toBe($status);
        expect(noncashExceptionMysqlFinancialRows())->toEqual($financial);
        expect(app(CollectionBatchPosition::class)->read($receipt->batch))->toBe($position);
        $events = DB::table('collection_exception_events')->orderBy('id')->get()->all();
        expect(array_slice($events, 0, count($earlierEvents)))->toEqual($earlierEvents);
        expect($events)->toHaveCount($index + 2);
        $event = $events[array_key_last($events)];
        expect($event->event_type)->toBe($status);
        expect((int) $event->batch_version)->toBe($version);
        expect((int) $event->actor_user_id)->toBeIn([$admin->id, $other->id]);
        $proofs = DB::table('financial_workflow_supplements')->orderBy('id')->get()->all();
        expect(array_slice($proofs, 0, count($earlierProofs)))->toEqual($earlierProofs);
        expect($proofs)->toHaveCount($index === 2 ? 2 : 1);
        if ($action === 'resolve') {
            $proof = FinancialWorkflowSupplement::query()->latest('id')->firstOrFail();
            expect($proof->facts['batch_version'])->toBe($version);
            expect($proof->actor_user_id)->toBe((int) $event->actor_user_id);
            expect($proof->facts['receipt_id'])->toBe($receipt->id);
            expect($proof->facts['evidence_id'])->toBe($receipt->collection_payment_evidence_id);
            app(CollectionExceptionResolution::class)->assertRecorded($proof, $receipt->batch->fresh());
        }
        expect(DB::table('canonical_audit_events')->where('target_type', CollectionException::class)
            ->where('target_id', $exception->id)->where('event_type', 'collection.exception_'.$status)->count())
            ->toBe($action === 'reopen' || $index === 0 ? 1 : 2);
        $earlierEvents = $events;
        $earlierProofs = $proofs;
    }
    expect(DB::table('collection_exception_events')->orderBy('id')->pluck('event_type')->all())
        ->toBe(['opened', 'resolved', 'reopened', 'resolved']);
    $this->assertDatabaseCount('collection_batch_reviews', 0);
    $this->get(route('collection-batches.show', $receipt->batch))->assertInertia(fn (AssertableInertia $page) => $page
        ->has('resolution_records.data', 2)->where('exceptions.data.0.status', 'resolved'));
})->with(['bank transfer' => ['transfer', 'business_bank_ngn'], 'POS clearing' => ['pos', 'payment_clearing_ngn'], 'configured other custody' => ['other', 'agent_receivable_ngn']]);

test('mysql competing cash shortage resolution reopening and renewed resolution retain the counted handoff and earlier review', function (): void {
    [, , $admin, $batch, $exception, $date] = collectionInvestigationFixture($this);
    $this->post(route('collection-batches.remittances.store', $batch), [
        'batch_version' => $batch->fresh()->version, 'handoff_reference' => 'MYSQL-EXCEPTION-FULL-HANDOFF',
        'amount_ngn' => '2000.00', 'handoff_date' => $date, 'receiving_location' => 'Lagos business office',
        'source_attestation' => 'Independently counted full original Agent custody.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $other = User::factory()->admin()->withTwoFactor()->create();
    $other->givePermissionTo(AdminPermission::ReconciliationManage);
    $initialVersion = $batch->fresh()->version;
    $financial = noncashExceptionMysqlFinancialRows();
    $priorReviews = DB::table('collection_batch_reviews')->orderBy('id')->get()->all();
    $earlierEvents = DB::table('collection_exception_events')->orderBy('id')->get()->all();
    $priorAudits = DB::table('canonical_audit_events')->orderBy('id')->get()->all();
    $root = Storage::disk('collection_evidence')->path('');
    expect($priorReviews)->toHaveCount(1);
    expect($priorReviews[0]->outcome)->toBe('exception');
    expect((int) $priorReviews[0]->outstanding_kobo)->toBe(200000);
    expect(app(CollectionBatchPosition::class)->read($batch))->toBe([
        'expected_kobo' => 200000, 'received_kobo' => 200000, 'outstanding_kobo' => 0, 'settlement_pending' => false,
    ]);
    Process::preventStrayProcesses(false);

    foreach (['resolve', 'reopen', 'resolve'] as $index => $action) {
        $version = $initialVersion + $index;
        $data = ['batch_version' => $version, 'reason' => $action === 'reopen'
            ? 'New conflicting count evidence requires renewed custody review.'
            : 'Independently counted full original handoff resolves the shortage.', 'confirmed' => true];
        $outcomes = Concurrency::driver('process')->run([
            noncashExceptionMysqlTask($admin->id, $batch->id, $exception->id, $action, $data, $root),
            noncashExceptionMysqlTask($other->id, $batch->id, $exception->id, $action,
                [...$data, 'reason' => 'Independent second reviewer: '.$data['reason']], $root),
        ]);
        sort($outcomes);
        $status = $action === 'resolve' ? 'resolved' : 'reopened';
        expect($outcomes)->toBe(['blocked', $status]);
        expect($batch->fresh()->version)->toBe($version + 1);
        expect($exception->fresh()->status)->toBe($status);
        expect(noncashExceptionMysqlFinancialRows())->toEqual($financial);
        expect(DB::table('collection_batch_reviews')->orderBy('id')->get()->all())->toEqual($priorReviews);
        expect(app(CollectionBatchPosition::class)->read($batch))->toBe([
            'expected_kobo' => 200000, 'received_kobo' => 200000, 'outstanding_kobo' => 0, 'settlement_pending' => false,
        ]);
        $events = DB::table('collection_exception_events')->orderBy('id')->get()->all();
        expect(array_slice($events, 0, count($earlierEvents)))->toEqual($earlierEvents);
        expect($events)->toHaveCount($index + 2);
        $event = $events[array_key_last($events)];
        expect($event->event_type)->toBe($status);
        expect((int) $event->batch_version)->toBe($version);
        expect((int) $event->actor_user_id)->toBeIn([$admin->id, $other->id]);
        $canonical = DB::table('canonical_audit_events')->where('target_type', CollectionException::class)
            ->where('target_id', $exception->id)->where('event_type', 'collection.exception_'.$status)->orderByDesc('id')->first();
        expect($canonical)->not->toBeNull();
        $content = json_decode($canonical->content, true, flags: JSON_THROW_ON_ERROR);
        expect($content['actor_id'])->toBe((int) $event->actor_user_id);
        expect($content['actor_type'])->toBe('admin');
        expect($content['authority']['required_permission'])->toBe('reconciliation.manage');
        expect($content['outcome'])->toBe('Succeeded');
        expect($content['safe_changes']['batch_id'])->toBe($batch->id);
        expect(DB::table('canonical_audit_events')->where('target_type', CollectionException::class)
            ->where('target_id', $exception->id)->where('event_type', 'collection.exception_'.$status)->count())
            ->toBe($action === 'reopen' || $index === 0 ? 1 : 2);
        $audits = DB::table('canonical_audit_events')->orderBy('id')->get()->all();
        expect(array_slice($audits, 0, count($priorAudits)))->toEqual($priorAudits);
        $this->assertDatabaseCount('financial_workflow_supplements', 0);
        if ($action === 'resolve') {
            app(CollectionExceptionResolution::class)->assertResolvedCases($batch->fresh());
            expect($content['safe_changes']['resolution_kind'])->toBe('remittance');
        }
        $earlierEvents = $events;
        $priorAudits = $audits;
    }
    expect(DB::table('collection_exception_events')->orderBy('id')->pluck('event_type')->all())
        ->toBe(['opened', 'resolved', 'reopened', 'resolved']);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::BusinessCash))->toBe(200000);
    $receivable = LedgerAccount::query()->where('code', LedgerAccountCode::AgentReceivable->value)->sole();
    $custodyLines = DB::table('ledger_entries')->where('ledger_account_id', $receivable->id)
        ->where('agent_profile_id', $batch->agent_profile_id)->orderBy('id')->get(['side', 'amount_kobo'])
        ->map(fn (object $line): array => ['side' => $line->side, 'amount_kobo' => (int) $line->amount_kobo])->all();
    expect($custodyLines)->toBe([['side' => 'debit', 'amount_kobo' => 200000], ['side' => 'credit', 'amount_kobo' => 200000]]);
    expect(app(FinancialCashPosition::class)->balance(LedgerAccountCode::CustomerSavingsLiability))->toBe(200000);
    $this->post(route('collection-batches.review', $batch), [
        'batch_version' => $batch->fresh()->version, 'reason' => 'Retained counted handoff and latest independent resolution permit reconciliation.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($batch->fresh()->status)->toBe('reconciled');
    $reviews = DB::table('collection_batch_reviews')->orderBy('id')->get()->all();
    expect(array_slice($reviews, 0, 1))->toEqual($priorReviews);
    expect($reviews)->toHaveCount(2);
    expect($reviews[1]->outcome)->toBe('reconciled');
    expect((int) $reviews[1]->remitted_kobo)->toBe(200000);
    expect((int) $reviews[1]->outstanding_kobo)->toBe(0);
    expect(noncashExceptionMysqlFinancialRows())->toEqual($financial);
});
