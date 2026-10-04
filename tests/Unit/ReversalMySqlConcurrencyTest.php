<?php

use App\Models\LedgerPostingGroup;
use App\Models\ReversalRequest;
use App\Models\User;
use App\Services\CollectionLedgerService;
use App\Services\ReversalService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/../ReversalFixtures.php';
require_once __DIR__.'/../ReversalAcceptanceGapFixtures.php';

beforeEach(function (): void {
    if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'saverapp_audit_testing') {
        $this->markTestSkipped('Requires the isolated saverapp_audit_testing MySQL database.');
    }
    $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();
});

/** @param list<Closure> $workers */
function reversalMysqlRun(array $workers): array
{
    Facade::clearResolvedInstances();
    app()->forgetInstance(ProcessFactory::class);

    return Concurrency::driver('process')->run($workers);
}

function reversalMysqlWorker(Closure $work): Closure
{
    return static function () use ($work): string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe reversal race database.');
        }
        config()->set(['collections.enabled' => true, 'collections.receipt_corrections_enabled' => true]);
        try {
            return $work();
        } catch (ConflictHttpException|ValidationException) {
            return 'blocked';
        } catch (QueryException $exception) {
            throw new RuntimeException($exception->getMessage());
        }
    };
}

/** @param array<string, mixed> $data */
function reversalMysqlSubmit(int $agentId, int $originalId, array $data): Closure
{
    return reversalMysqlWorker(static function () use ($agentId, $originalId, $data): string {
        app(ReversalService::class)->submit(User::findOrFail($agentId), LedgerPostingGroup::findOrFail($originalId), $data);

        return 'submitted';
    });
}

/** @param array<string, mixed> $data */
function reversalMysqlApprove(int $adminId, int $reversalId, array $data): Closure
{
    return reversalMysqlWorker(static function () use ($adminId, $reversalId, $data): string {
        $request = Request::create('/reversals/approve', 'POST');
        $session = new Store('reversal-worker', new ArraySessionHandler(600));
        $session->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
            'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
        $request->setLaravelSession($session);
        app(ReversalService::class)->decide(User::findOrFail($adminId), ReversalRequest::findOrFail($reversalId), 'approve', $data, $request);

        return 'posted';
    });
}

function reversalMysqlRemittance(int $remittanceId, int $agentProfileId, int $amount, int $adminId): Closure
{
    return reversalMysqlWorker(static function () use ($remittanceId, $agentProfileId, $amount, $adminId): string {
        DB::transaction(function () use ($remittanceId, $agentProfileId, $amount, $adminId): void {
            $group = app(CollectionLedgerService::class)->postCashRemittance($remittanceId, $agentProfileId, $amount, User::findOrFail($adminId));
            DB::table('cash_remittances')->where('id', $remittanceId)->update(['ledger_posting_group_id' => $group->id]);
        });

        return 'remitted';
    });
}

test('REV-AC-012 mysql concurrent Agent submissions leave one pending request', function (): void {
    ['agent' => $agent, 'original' => $original] = revGapReceipt();
    $quote = revGapQuote($this, $agent, $original);

    $outcomes = reversalMysqlRun([
        reversalMysqlSubmit($agent->id, $original->id, revGapSubmission($quote)),
        reversalMysqlSubmit($agent->id, $original->id, revGapSubmission($quote)),
    ]);

    sort($outcomes);
    expect($outcomes)->toBe(['blocked', 'submitted'])
        ->and(ReversalRequest::query()->where('original_posting_group_id', $original->id)->where('state', 'pending_review')->count())->toBe(1)
        ->and(LedgerPostingGroup::query()->count())->toBe(1);
});

test('REV-AC-027 mysql approval racing a remittance serializes and a stale preview never posts', function (): void {
    ['agent' => $agent, 'original' => $original, 'receipt' => $receipt] = revGapReceipt();
    $request = revGapSubmit($this, $agent, $original);
    $admin = revGapAdmin();
    $fingerprint = app(ReversalService::class)->reviewPreview($admin, $request->fresh())['preview_fingerprint'];
    $remittance = DB::table('cash_remittances')->insertGetId([
        'handoff_reference' => 'REM-'.Str::uuid(), 'receiving_location' => 'Business till', 'source_attestation' => 'Counted handoff',
        'collection_batch_id' => $receipt->collection_batch_id, 'agent_profile_id' => $receipt->recording_agent_profile_id,
        'confirmed_by_user_id' => $admin->id, 'amount_kobo' => 200000, 'handoff_date' => now()->toDateString(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $decision = ['attempt_reference' => (string) Str::uuid(), 'version' => $request->fresh()->version,
        'decision_reason' => 'Reviewed original custody evidence.', 'confirmed' => true, 'preview_fingerprint' => $fingerprint];

    $outcomes = reversalMysqlRun([
        reversalMysqlApprove($admin->id, $request->id, $decision),
        reversalMysqlRemittance($remittance, $receipt->recording_agent_profile_id, 200000, $admin->id),
    ]);

    expect($outcomes[1])->toBe('remitted')->and($outcomes[0])->toBeIn(['posted', 'blocked']);
    $state = $request->fresh()->state;
    expect($state)->toBe($outcomes[0] === 'posted' ? 'approved_posted' : 'pending_review')
        ->and(LedgerPostingGroup::query()->where('event_type', 'like', '%reversal%')->orWhereKey($request->fresh()->compensation_posting_group_id ?? 0)->count())
        ->toBeLessThanOrEqual(1);
    if ($outcomes[0] === 'blocked') {
        expect(app(ReversalService::class)->reviewPreview($admin, $request->fresh())['preview_fingerprint'])->not->toBe($fingerprint);
    }
    foreach (LedgerPostingGroup::query()->get() as $group) {
        expect((int) $group->entries()->where('side', 'debit')->sum('amount_kobo'))->toBe((int) $group->entries()->where('side', 'credit')->sum('amount_kobo'));
    }
});
