<?php

use App\Enums\AdminPermission;
use App\Models\AuditEvent;
use App\Models\ChargeCategoryVersion;
use App\Models\FeeRule;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\ManualChargeService;
use App\Services\RegistrationFeeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'saverapp_audit_testing') {
        $this->markTestSkipped('Requires the isolated saverapp_audit_testing MySQL database.');
    }

    $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();
});

function feeRuleMysqlActor(): User
{
    $actor = User::factory()->admin()->withTwoFactor()->create();
    $actor->givePermissionTo(AdminPermission::FeesManage->value);

    return $actor;
}

/** @param array<string, mixed> $terms */
function manualFeeRuleMysqlTask(int $actorId, array $terms): Closure
{
    return static function () use ($actorId, $terms): int {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe manual fee rule concurrency database.');
        }
        $request = Request::create('/admin/charges/publish', 'POST');
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put(['auth.fresh_until' => now()->addMinutes(10)->timestamp,
            'auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);

        return app(ManualChargeService::class)->publish(User::findOrFail($actorId), $terms, $request)->id;
    };
}

test('mysql concurrent manual fee category publications retain independent category versions and globally unique rule versions', function (bool $sameCategory): void {
    LedgerAccount::query()->where('code', 'fee_income')->update(['mapping_status' => 'mapped']);
    $first = feeRuleMysqlActor();
    $second = feeRuleMysqlActor();
    $terms = ['category_key' => 'document-service', 'kind' => 'manual_fee', 'amount_kobo' => 1001,
        'purpose' => 'Reviewed independent Customer service.', 'customer_description' => 'Agreed service fee.',
        'publication_reference' => (string) Str::uuid()];
    $otherTerms = [...$terms, 'category_key' => $sameCategory ? 'document-service' : 'statement-service',
        'publication_reference' => (string) Str::uuid(), 'amount_kobo' => 1201];
    $tasks = [manualFeeRuleMysqlTask($first->id, $terms), manualFeeRuleMysqlTask($second->id, $otherTerms)];

    $results = Concurrency::driver('process')->run($tasks);

    expect(array_unique($results))->toHaveCount(2);
    $categories = ChargeCategoryVersion::query()->orderBy('id')->get();
    $rules = FeeRule::query()->where('kind', 'manual')->orderBy('version')->get();
    expect($categories->pluck('version')->all())->toBe($sameCategory ? [1, 2] : [1, 1]);
    expect($rules->pluck('version')->all())->toBe([1, 2]);
    foreach ($categories as $category) {
        $rule = $rules->firstWhere('id', $category->fee_rule_id);
        expect($rule)->not->toBeNull();
        expect($rule->rule_key)->toBe('manual-'.$category->category_key);
        expect($rule->amount_kobo)->toBe($category->amount_kobo);
    }
    $before = ['categories' => $categories->toArray(), 'rules' => $rules->toArray()];
    expect(Concurrency::driver('process')->run($tasks))->toBe($results);
    expect(['categories' => ChargeCategoryVersion::query()->orderBy('id')->get()->toArray(),
        'rules' => FeeRule::query()->where('kind', 'manual')->orderBy('version')->get()->toArray()])->toBe($before);
    expect(AuditEvent::query()->where('event_type', 'charge.category_published')->count())->toBe(2);
    $this->assertDatabaseCount('fee_obligations', 0);
    $this->assertDatabaseCount('ledger_entries', 0);
})->with(['different categories' => false, 'same category' => true]);

/** @return array<string, mixed> */
function feeRuleMysqlTerms(string $effectiveAt, string $name, int $amountKobo): array
{
    return [
        'kind' => 'registration', 'model' => 'fixed', 'name' => $name,
        'amount_kobo' => $amountKobo, 'effective_at' => $effectiveAt,
        'customer_description' => 'Your immutable registration fee.',
        'publication_reason' => 'Isolated concurrent publication acceptance.',
    ];
}

/** @param array<string, mixed> $terms */
function feeRuleMysqlTask(int $actorId, array $terms, bool $reviewAgainOnConflict = false): Closure
{
    return static function () use ($actorId, $terms, $reviewAgainOnConflict): int|string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe fee rule concurrency database.');
        }

        $request = Request::create('/admin/fees/registration', 'POST');
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put([
            'auth.fresh_until' => now()->addMinutes(10)->timestamp,
            'auth.password_confirmed_at' => now()->timestamp,
            'auth.mfa_confirmed_at' => now()->timestamp,
        ]);

        $owner = app(RegistrationFeeService::class);
        $actor = User::query()->findOrFail($actorId);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $review = $owner->previewPublication($actor, $terms);
            try {
                return $owner->publishRule($actor, [...$terms, 'confirmed' => true, 'preview_fingerprint' => $review['preview_fingerprint']], $request)->id;
            } catch (ConflictHttpException $exception) {
                if ($exception->getMessage() === 'The reviewed fee terms or catalogue changed. Review again.' && $reviewAgainOnConflict && $attempt === 0) {
                    continue;
                }
                if (! in_array($exception->getMessage(), ['A fee rule already starts at the requested effective time.', 'The reviewed fee terms or catalogue changed. Review again.'], true)) {
                    throw $exception;
                }

                return 'same_start_conflict';
            }
        }

        throw new RuntimeException('Publication review attempts exhausted.');
    };
}

test('mysql competing registration rules at one instant commit one version and retain prior terms', function (bool $retainPriorRule): void {
    $first = feeRuleMysqlActor();
    $second = feeRuleMysqlActor();
    $priorStart = now()->addHour()->startOfSecond();
    $raceStart = $priorStart->copy()->addHour();
    $prior = null;
    $priorAttributes = null;
    if ($retainPriorRule) {
        $priorId = feeRuleMysqlTask($first->id, feeRuleMysqlTerms($priorStart->toIso8601String(), 'Retained predecessor', 50000))();
        $prior = FeeRule::query()->findOrFail($priorId);
        $priorAttributes = $prior->getAttributes();
    }

    $results = Concurrency::driver('process')->run([
        feeRuleMysqlTask($first->id, feeRuleMysqlTerms($raceStart->toIso8601String(), 'First reviewed rule', 10000)),
        feeRuleMysqlTask($second->id, feeRuleMysqlTerms($raceStart->toIso8601String(), 'Second reviewed rule', 20000)),
    ]);

    expect(collect($results)->filter(fn (mixed $result): bool => is_int($result)))->toHaveCount(1);
    expect(collect($results)->filter(fn (mixed $result): bool => $result === 'same_start_conflict'))->toHaveCount(1);
    $winnerId = collect($results)->first(fn (mixed $result): bool => is_int($result));
    $winner = FeeRule::query()->findOrFail($winnerId);
    expect($winner->version)->toBe($retainPriorRule ? 2 : 1);
    expect($winner->effective_at->toIso8601String())->toBe($raceStart->toIso8601String());
    expect($winner->retired_at)->toBeNull();
    expect($winner->amount_kobo)->toBe($winner->published_by_user_id === $first->id ? 10000 : 20000);
    $this->assertDatabaseCount('fee_rules', $retainPriorRule ? 2 : 1);
    expect(AuditEvent::query()->where('event_type', 'fee_rule.published')->count())->toBe($retainPriorRule ? 2 : 1);
    expect(AuditEvent::query()->where('event_type', 'fee_rule.published')->where('target_id', $winner->id)->count())->toBe(1);
    if ($prior !== null && $priorAttributes !== null) {
        $retained = $prior->fresh();
        expect($retained->retired_at->toIso8601String())->toBe($raceStart->toIso8601String());
        expect(collect($retained->getAttributes())->except(['retired_at', 'updated_at'])->all())
            ->toBe(collect($priorAttributes)->except(['retired_at', 'updated_at'])->all());
    }
    $this->assertDatabaseCount('fee_obligations', 0);
    $this->assertDatabaseCount('fee_snapshots', 0);
    $this->assertDatabaseCount('ledger_entries', 0);
})->with(['initial publication' => false, 'retained predecessor' => true]);

test('mysql publications at distinct future starts retain non-overlapping chronological intervals', function (): void {
    $first = feeRuleMysqlActor();
    $second = feeRuleMysqlActor();
    $priorStart = now()->addHour()->startOfSecond();
    $middleStart = $priorStart->copy()->addHour();
    $lastStart = $middleStart->copy()->addHour();
    $priorId = feeRuleMysqlTask($first->id, feeRuleMysqlTerms($priorStart->toIso8601String(), 'Original terms', 50000))();
    $priorAttributes = FeeRule::query()->findOrFail($priorId)->getAttributes();

    $results = Concurrency::driver('process')->run([
        feeRuleMysqlTask($second->id, feeRuleMysqlTerms($lastStart->toIso8601String(), 'Later terms', 30000), true),
        feeRuleMysqlTask($first->id, feeRuleMysqlTerms($middleStart->toIso8601String(), 'Intermediate terms', 20000), true),
    ]);

    expect($results)->each->toBeInt();
    expect($results[0])->not->toBe($results[1]);
    $rules = FeeRule::query()->orderBy('effective_at')->get();
    expect($rules)->toHaveCount(3);
    expect($rules->pluck('version')->sort()->values()->all())->toBe([1, 2, 3]);
    expect($rules->pluck('amount_kobo')->all())->toBe([50000, 20000, 30000]);
    expect($rules[0]->effective_at->toIso8601String())->toBe($priorStart->toIso8601String());
    expect($rules[0]->retired_at->toIso8601String())->toBe($middleStart->toIso8601String());
    expect($rules[1]->effective_at->toIso8601String())->toBe($middleStart->toIso8601String());
    expect($rules[1]->retired_at->toIso8601String())->toBe($lastStart->toIso8601String());
    expect($rules[2]->effective_at->toIso8601String())->toBe($lastStart->toIso8601String());
    expect($rules[2]->retired_at)->toBeNull();
    expect(collect($rules[0]->getAttributes())->except(['retired_at', 'updated_at'])->all())
        ->toBe(collect($priorAttributes)->except(['retired_at', 'updated_at'])->all());
    expect(AuditEvent::query()->where('event_type', 'fee_rule.published')->count())->toBe(3);
    $this->assertDatabaseCount('fee_obligations', 0);
    $this->assertDatabaseCount('fee_snapshots', 0);
    $this->assertDatabaseCount('ledger_entries', 0);
});

test('mysql publication observes a successor committed after its transaction snapshot began', function (): void {
    $first = feeRuleMysqlActor();
    $second = feeRuleMysqlActor();
    $priorStart = now()->addHour()->startOfSecond();
    $middleStart = $priorStart->copy()->addHour();
    $lastStart = $middleStart->copy()->addHour();
    feeRuleMysqlTask($first->id, feeRuleMysqlTerms($priorStart->toIso8601String(), 'Original retained terms', 50000))();

    $middleId = DB::transaction(function () use ($first, $second, $middleStart, $lastStart): int|string {
        expect(FeeRule::query()->count())->toBe(1);
        $results = Concurrency::driver('process')->run([
            feeRuleMysqlTask($second->id, feeRuleMysqlTerms($lastStart->toIso8601String(), 'Committed later successor', 30000)),
        ]);
        expect($results[0])->toBeInt();

        return feeRuleMysqlTask($first->id, feeRuleMysqlTerms($middleStart->toIso8601String(), 'Inserted intermediate rule', 20000))();
    });

    $middle = FeeRule::query()->findOrFail($middleId);
    expect($middle->retired_at?->toIso8601String())->toBe($lastStart->toIso8601String());
    $rules = FeeRule::query()->orderBy('effective_at')->get();
    expect($rules)->toHaveCount(3);
    expect($rules->pluck('amount_kobo')->all())->toBe([50000, 20000, 30000]);
    expect($rules[0]->retired_at?->toIso8601String())->toBe($middleStart->toIso8601String());
    expect($rules[2]->retired_at)->toBeNull();
    expect(AuditEvent::query()->where('event_type', 'fee_rule.published')->count())->toBe(3);
    $this->assertDatabaseCount('fee_obligations', 0);
    $this->assertDatabaseCount('fee_snapshots', 0);
    $this->assertDatabaseCount('ledger_entries', 0);
});

function feeRuleMysqlRetirementTask(int $actorId, int $ruleId, string $reason, string $fingerprint): Closure
{
    return static function () use ($actorId, $ruleId, $reason, $fingerprint): int|string {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'saverapp_audit_testing') {
            throw new RuntimeException('Unsafe fee rule retirement concurrency database.');
        }
        $request = Request::create('/admin/fees/registration/retire', 'POST');
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put([
            'auth.fresh_until' => now()->addMinutes(10)->timestamp,
            'auth.password_confirmed_at' => now()->timestamp,
            'auth.mfa_confirmed_at' => now()->timestamp,
        ]);
        try {
            return app(RegistrationFeeService::class)->retireRule(User::query()->findOrFail($actorId), $ruleId, $reason, $request, $fingerprint, true)->id;
        } catch (ConflictHttpException $exception) {
            if ($exception->getMessage() !== 'This fee rule has already ended. Review the current catalogue.') {
                throw $exception;
            }

            return 'already_ended';
        }
    };
}

test('mysql competing reviewed retirements preserve one applicability end and one audit event', function (): void {
    $first = feeRuleMysqlActor();
    $second = feeRuleMysqlActor();
    $ruleId = feeRuleMysqlTask($first->id, feeRuleMysqlTerms('', 'Agreed registration terms', 10001))();
    $original = FeeRule::query()->findOrFail($ruleId)->getAttributes();
    $owner = app(RegistrationFeeService::class);
    $reason = 'End selection for new agreements.';
    $firstReview = $owner->previewRetirement($first, $ruleId, $reason);
    $secondReview = $owner->previewRetirement($second, $ruleId, $reason);

    $results = Concurrency::driver('process')->run([
        feeRuleMysqlRetirementTask($first->id, $ruleId, $reason, $firstReview['preview_fingerprint']),
        feeRuleMysqlRetirementTask($second->id, $ruleId, $reason, $secondReview['preview_fingerprint']),
    ]);

    expect(collect($results)->filter(fn (mixed $result): bool => is_int($result)))->toHaveCount(1);
    expect(collect($results)->filter(fn (mixed $result): bool => $result === 'already_ended'))->toHaveCount(1);
    $retired = FeeRule::query()->findOrFail($ruleId);
    expect($retired->retired_at)->not->toBeNull();
    expect(collect($retired->getAttributes())->except(['retired_at', 'updated_at'])->all())
        ->toBe(collect($original)->except(['retired_at', 'updated_at'])->all());
    expect($owner->getCurrentRule())->toBeNull();
    expect(AuditEvent::query()->where('event_type', 'fee_rule.retired')->count())->toBe(1);
    $this->assertDatabaseCount('fee_rules', 1);
    $this->assertDatabaseCount('fee_obligations', 0);
    $this->assertDatabaseCount('fee_snapshots', 0);
    $this->assertDatabaseCount('ledger_entries', 0);
});
