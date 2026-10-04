<?php

use App\Enums\AdminPermission;
use App\Models\AgentProfile;
use App\Models\FinancialArtifact;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\CustomerReassignmentService;
use App\Services\FinancialArtifactService;
use App\Services\LedgerTransactionProjectionService;
use App\Services\LedgerTransactionReadService;
use App\Services\StatementPreviewService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

require_once __DIR__.'/../LedgerAcceptanceGapFixtures.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00:00', 'Africa/Lagos'));
    Queue::fake();
    Storage::fake('local');
});

/**
 * Customer A owns one real receipt and Agent A; Customer B has Agent B and two projected rows.
 *
 * @return array<string, mixed>
 */
function ledgerGapScopeWorld(): array
{
    [$agentA, $customerA, $assignmentA, $groupA] = ledgerGapReceipt();
    [$agentB, $customerB] = ledgerGapSecondCustomer();
    LedgerAccount::query()->update(['mapping_status' => 'mapped']);
    app(LedgerTransactionProjectionService::class)->rebuild();
    $referencesB = ledgerGapProjectionRows($customerB, 2, '2026-10-01');
    $referenceA = app(LedgerTransactionReadService::class)->search($agentA, [])['data'][0]['reference'];

    return ['agentA' => $agentA, 'customerA' => $customerA, 'assignmentA' => $assignmentA, 'groupA' => $groupA, 'referenceA' => $referenceA,
        'agentB' => $agentB, 'customerB' => $customerB, 'referencesB' => $referencesB];
}

test('LED-AC-032: Customer own, current Agent and Admin baseline see exactly their scope in list, detail, balance and statement', function (string $viewer, bool $seesA, bool $seesB): void {
    $world = ledgerGapScopeWorld();
    $user = match ($viewer) {
        'Customer A' => $world['customerA']->user,
        'Customer B' => $world['customerB']->user,
        'Agent A' => $world['agentA'],
        'Agent B' => $world['agentB'],
        'baseline Admin' => ledgerGapAdmin(),
        'audit.view Admin' => ledgerGapAdmin([AdminPermission::AuditView]),
    };
    $reads = app(LedgerTransactionReadService::class);
    $this->actingAs($user);

    $result = $reads->search($user, []);
    expect($result['total'])->toBe(($seesA ? 1 : 0) + ($seesB ? 2 : 0))
        ->and(array_column($result['data'], 'customer_id'))->toEqualCanonicalizing([
            ...($seesA ? [$world['customerA']->customer_id] : []), ...($seesB ? [$world['customerB']->customer_id, $world['customerB']->customer_id] : []),
        ]);
    $pick = fn (bool $allowed) => $allowed ? 'assertOk' : 'assertNotFound';
    $this->get(route('transactions.show', $world['referenceA']))->{$pick($seesA)}();
    $this->get(route('transactions.show', $world['referencesB'][0]))->{$pick($seesB)}();
    $this->getJson(route('customers.ledger-balance', $world['customerA']->customer_id))->{$pick($seesA)}();
    $this->getJson(route('customers.ledger-balance', $world['customerB']->customer_id))->{$pick($seesB)}();
    $this->get(route('customers.statements.preview', $world['customerA']->customer_id))->{$pick($seesA)}();
    $this->get(route('customers.statements.preview', $world['customerB']->customer_id))->{$pick($seesB)}();
    expect($seesA ? $reads->balance($user, $world['customerA'])['liability_kobo'] : null)->toBe($seesA ? 200000 : null);
    if (! $seesB) {
        expect(fn () => $reads->balance($user, $world['customerB']))->toThrow(NotFoundHttpException::class);
    }
})->with([
    'Customer A' => ['Customer A', true, false],
    'Customer B' => ['Customer B', false, true],
    'Agent A' => ['Agent A', true, false],
    'Agent B' => ['Agent B', false, true],
    'baseline Admin' => ['baseline Admin', true, true],
    'audit.view Admin' => ['audit.view Admin', true, true],
]);

test('LED-AC-033: reassignment immediately removes the former Agent transaction, statement and artifact access without rewriting history', function (): void {
    $world = ledgerGapScopeWorld();
    ['agentA' => $former, 'customerA' => $customer, 'groupA' => $group, 'referenceA' => $reference] = $world;
    $from = now('Africa/Lagos')->startOfMonth()->toDateString();
    $to = now('Africa/Lagos')->toDateString();
    $fingerprint = app(StatementPreviewService::class)->preview($former, $customer, $from, $to, 'Africa/Lagos')['preview_fingerprint'];
    $this->actingAs($former)->post(route('customers.statements.issue', $customer->customer_id), ['operation_reference' => (string) Str::uuid(), 'from' => $from,
        'to' => $to, 'preview_fingerprint' => $fingerprint, 'confirmed' => true])->assertRedirect();
    $artifact = FinancialArtifact::query()->sole();
    app(FinancialArtifactService::class)->render($artifact->id);
    $artifact->refresh();
    $download = fn (User $viewer): string => URL::temporarySignedRoute('financial-artifacts.download', now()->addMinutes(15), ['artifact' => $artifact, 'viewer' => $viewer->id]);
    $this->get($download($former))->assertOk();
    $this->get(route('financial-artifacts.show', $artifact))->assertOk();

    $replacement = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id]);
    $admin = ledgerGapAdmin([AdminPermission::CustomersReassign]);
    $owner = app(CustomerReassignmentService::class);
    $preview = $owner->preview($admin, $customer->fresh(), $replacement->id);
    $owner->execute($admin, $customer->fresh(), ['attempt_reference' => (string) Str::uuid(), 'version' => $preview['version'], 'assignment_version' => $preview['assignment_version'],
        'preview_token' => $preview['preview_token'], 'target_agent_id' => $replacement->id, 'confirmed' => true, 'reason' => 'Reviewed transfer.', 'customer_explanation' => 'Your service contact changed.']);
    $attribution = [$group->fresh()->actor_user_id, DB::table('collection_receipts')->value('recording_agent_profile_id')];

    $this->actingAs($former->fresh());
    $this->get(route('transactions.show', $reference))->assertNotFound();
    $this->get(route('transactions.index'))->assertInertia(fn ($page) => $page->where('result.total', 0)->has('result.data', 0));
    $this->getJson(route('customers.ledger-balance', $customer->customer_id))->assertNotFound();
    $this->get(route('customers.statements.preview', $customer->customer_id))->assertNotFound();
    $this->post(route('customers.statements.issue', $customer->customer_id), ['operation_reference' => (string) Str::uuid(), 'from' => $from, 'to' => $to,
        'preview_fingerprint' => $fingerprint, 'confirmed' => true])->assertNotFound();
    $this->get(route('financial-artifacts.show', $artifact))->assertNotFound();
    $this->get($download($former))->assertNotFound();

    $this->actingAs($replacement->user);
    $this->get(route('transactions.show', $reference))->assertOk();
    $this->get(route('financial-artifacts.show', $artifact))->assertOk();
    $this->get($download($replacement->user))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($attribution)->toBe([$former->id, $group->fresh()->entries()->first()->agent_profile_id])
        ->and($group->fresh()->actor_user_id)->toBe($former->id)
        ->and(DB::table('ledger_posting_groups')->count())->toBe(1);
});

test('LED-AC-034: neither a baseline Admin nor an audit.view holder can run a business export and an exporter gains no audit or mutation right', function (): void {
    ledgerGapScopeWorld();
    $payload = ['operation_reference' => (string) Str::uuid(), 'format' => 'csv', 'confirmed' => true];
    $baseline = ledgerGapAdmin();
    $auditor = ledgerGapAdmin([AdminPermission::AuditView]);
    $exporter = ledgerGapAdmin([AdminPermission::ReportsExport]);

    $this->actingAs($baseline)->post(route('reports.export', 'withdrawals'), $payload)->assertForbidden();
    $this->actingAs($auditor)->post(route('reports.export', 'withdrawals'), $payload)->assertForbidden();
    expect(FinancialArtifact::query()->count())->toBe(0);

    $this->actingAs($exporter)->withSession(ledgerGapFreshSession());
    $this->post(route('reports.export', 'withdrawals'), $payload)->assertRedirect();
    expect(FinancialArtifact::query()->sole()->kind)->toBe('report');
    $this->get(route('admin.audit.index'))->assertForbidden();
    $this->post(route('ledger.incidents.resolve', (string) Str::uuid()), ['note' => 'Fix', 'confirmed' => true])->assertForbidden();
    $this->post(route('admin.fees.obligations.waive', 1), ['attempt_reference' => (string) Str::uuid(), 'amount_ngn' => '1.00', 'reason' => 'Waive',
        'customer_description' => 'Waive', 'confirmed' => true])->assertForbidden();
    $this->post(route('financial-artifacts.hold', FinancialArtifact::query()->sole()), ['held' => true, 'reason' => 'Hold', 'confirmed' => true])->assertForbidden();
});

test('LED-AC-051: audit.view reads the audit workspace only and cannot post, resolve, rebuild, export or open an exporter artifact', function (): void {
    $world = ledgerGapScopeWorld();
    $exporter = ledgerGapAdmin([AdminPermission::ReportsExport]);
    $this->actingAs($exporter)->post(route('reports.export', 'withdrawals'), ['operation_reference' => (string) Str::uuid(), 'format' => 'csv', 'confirmed' => true])->assertRedirect();
    $artifact = FinancialArtifact::query()->sole();
    app(FinancialArtifactService::class)->render($artifact->id);
    $artifact->refresh();
    $auditor = ledgerGapAdmin([AdminPermission::AuditView]);
    $before = ledgerGapSnapshot();
    $signed = URL::temporarySignedRoute('financial-artifacts.download', now()->addMinutes(15), ['artifact' => $artifact, 'viewer' => $auditor->id]);

    $this->actingAs($auditor)->withSession(ledgerGapFreshSession());
    $this->get(route('admin.audit.index'))->assertOk();
    $this->get(route('financial-artifacts.show', $artifact))->assertNotFound();
    $this->get($signed)->assertNotFound();
    $this->post(route('financial-artifacts.cancel', $artifact), ['confirmed' => true])->assertNotFound();
    $this->post(route('financial-artifacts.hold', $artifact), ['held' => true, 'reason' => 'Hold', 'confirmed' => true])->assertForbidden();
    $this->post(route('reports.export', 'withdrawals'), ['operation_reference' => (string) Str::uuid(), 'format' => 'csv', 'confirmed' => true])->assertForbidden();
    $this->post(route('ledger.incidents.resolve', (string) Str::uuid()), ['note' => 'Fix', 'confirmed' => true])->assertForbidden();
    $this->postJson(route('reversals.preview', $world['groupA']->posting_reference))->assertForbidden();

    $rebuildRoutes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route): bool => str_contains($route->uri(), 'rebuild') || str_contains((string) $route->getName(), 'rebuild'));
    expect($rebuildRoutes)->toHaveCount(0)->and(ledgerGapSnapshot())->toEqual($before)->and($artifact->fresh()->status)->toBe('ready');
});

test('LED-AC-043: the ledger screens carry only read data and each role receives only its own actions', function (): void {
    $world = ledgerGapScopeWorld();
    $reconciler = ledgerGapAdmin([AdminPermission::ReconciliationManage]);
    $rowKeys = ['reference', 'type', 'status', 'customer_id', 'customer_name', 'occurred_on', 'committed_at', 'timezone', 'currency',
        'gross_amount_kobo', 'savings_effect_kobo', 'fee_amount_kobo', 'posting_group_count', 'source_type'];
    $forbidden = ['entries', 'journal', 'adjust', 'ledger_account', 'account_code', 'audit', 'editable', 'set_balance'];
    $containsNone = function (array $props) use ($forbidden): bool {
        $flat = strtolower(json_encode(array_keys(Arr::dot($props)), JSON_THROW_ON_ERROR));

        return collect($forbidden)->filter(fn (string $word): bool => str_contains($flat, $word))->isEmpty();
    };

    foreach ([['Customer', $world['customerA']->user, false, null], ['Agent', $world['agentA'], false, $world['groupA']->posting_reference],
        ['Admin', ledgerGapAdmin(), false, null], ['Reconciliation Admin', $reconciler, true, null]] as [$label, $viewer, $canResolve, $reversalOriginal]) {
        $this->actingAs($viewer);
        $index = $this->get(route('transactions.index'));
        $page = Arr::only($index->inertiaProps(), ['result', 'filters', 'timezone', 'incidents', 'can_resolve_incidents']);
        expect(array_keys($page))->toBe(['result', 'filters', 'timezone', 'incidents', 'can_resolve_incidents'], $label)
            ->and($page['can_resolve_incidents'])->toBe($canResolve, $label)
            ->and(array_keys($page['result']))->toBe(['status', 'stale', 'state', 'data', 'total', 'savings_effect_kobo', 'next_cursor'], $label)
            ->and(array_keys($page['result']['data'][0]))->toBe($rowKeys, $label)
            ->and($containsNone($page))->toBeTrue($label);

        $show = $this->get(route('transactions.show', $world['referenceA']));
        $detail = Arr::only($show->inertiaProps(), ['transaction', 'reversal_original']);
        expect($detail['reversal_original'])->toBe($reversalOriginal, $label)
            ->and(array_keys($detail['transaction']))->toEqualCanonicalizing([...$rowKeys, 'compensation_reference', 'original_reference', 'components', 'timeline', 'actors', 'state'], $label)
            ->and($containsNone($detail))->toBeTrue($label);

        $statement = $this->get(route('customers.statements.preview', $world['customerA']->customer_id));
        $screen = Arr::only($statement->inertiaProps(), ['customer', 'preview', 'from', 'to', 'issued_statements']);
        expect(array_keys($screen['preview']))->toEqualCanonicalizing(['status', 'customer_id', 'from', 'to', 'timezone', 'currency', 'cutoff_at',
            'ledger_watermark', 'projection_version', 'opening_kobo', 'activity_kobo', 'closing_kobo', 'current_reserved_kobo', 'current_available_kobo',
            'unpaid_fees_kobo', 'type_totals', 'lines', 'preview_fingerprint'], $label)
            ->and(array_keys($screen))->toBe(['customer', 'preview', 'from', 'to', 'issued_statements'], $label);
    }
});

test('LED-AC-043: only a reconciliation Admin is shown open integrity incidents and they are never offered an editable balance', function (): void {
    $world = ledgerGapScopeWorld();
    ledgerGapUnsupportedGroup($world['agentA']);
    expect(fn () => app(LedgerTransactionProjectionService::class)->rebuild())->toThrow(RuntimeException::class);
    $reconciler = ledgerGapAdmin([AdminPermission::ReconciliationManage]);

    $this->actingAs($world['agentA'])->get(route('transactions.index'))->assertInertia(fn ($page) => $page->has('incidents', 0)->where('can_resolve_incidents', false));
    $this->actingAs($world['customerA']->user)->get(route('transactions.index'))->assertInertia(fn ($page) => $page->has('incidents', 0)->where('can_resolve_incidents', false));
    $this->actingAs(ledgerGapAdmin())->get(route('transactions.index'))->assertInertia(fn ($page) => $page->has('incidents', 0)->where('can_resolve_incidents', false));
    $this->actingAs($reconciler)->get(route('transactions.index'))->assertInertia(fn ($page) => $page->has('incidents', 1)->where('can_resolve_incidents', true)
        ->has('incidents.0', fn ($incident) => $incident->hasAll(['reference', 'category', 'status', 'summary', 'detected_at', 'recovered_at'])->etc()));
});

test('LED-AC-050: transaction, balance, statement and incident actions leave protected audit events with the actor and no ledger amounts', function (): void {
    $world = ledgerGapScopeWorld();
    $agent = $world['agentA'];
    $this->actingAs($agent);
    $this->get(route('transactions.index'))->assertOk();
    $this->get(route('transactions.show', $world['referenceA']))->assertOk();
    $this->getJson(route('customers.ledger-balance', $world['customerA']->customer_id))->assertOk();
    $this->get(route('customers.statements.preview', $world['customerA']->customer_id))->assertOk();
    ledgerGapUnsupportedGroup($agent);
    expect(fn () => app(LedgerTransactionProjectionService::class)->rebuild())->toThrow(RuntimeException::class);

    $events = DB::table('canonical_audit_events')->whereIn('event_type', ['ledger.transactions_viewed', 'ledger.transaction_viewed', 'ledger.balance_viewed',
        'ledger.statement_previewed', 'ledger.integrity_incident', 'ledger.projection_promoted', 'ledger.collection_posted'])->get()->keyBy('event_type');

    expect($events->keys()->all())->toEqualCanonicalizing(['ledger.transactions_viewed', 'ledger.transaction_viewed', 'ledger.balance_viewed',
        'ledger.statement_previewed', 'ledger.integrity_incident', 'ledger.projection_promoted', 'ledger.collection_posted'])
        ->and((int) $events['ledger.transaction_viewed']->actor_id)->toBe($agent->id)
        ->and($events['ledger.transaction_viewed']->target_reference)->toBe($world['referenceA'])
        ->and((int) $events['ledger.balance_viewed']->actor_id)->toBe($agent->id)
        ->and($events['ledger.balance_viewed']->target_reference)->toBe($world['customerA']->customer_id)
        ->and((int) $events['ledger.statement_previewed']->actor_id)->toBe($agent->id)
        ->and($events['ledger.integrity_incident']->target_reference)->toBe(DB::table('ledger_integrity_incidents')->value('incident_reference'));
    foreach (['ledger.balance_viewed', 'ledger.statement_previewed', 'ledger.transaction_viewed'] as $type) {
        expect($events[$type]->content)->not->toContain('200000')->not->toContain('liability_kobo')->not->toContain('preview_fingerprint');
    }
});

test('LED-AC-050: an out-of-scope transaction detail is audited as denied without the Customer, while balance and statement denials currently write no audit', function (): void {
    $world = ledgerGapScopeWorld();
    $outsider = $world['agentB'];
    $this->actingAs($outsider);
    $auditCount = fn (): int => DB::table('canonical_audit_events')->count();

    $this->get(route('transactions.show', $world['referenceA']))->assertNotFound();
    $denied = DB::table('canonical_audit_events')->where('event_type', 'ledger.transaction_viewed')->sole();
    expect((int) $denied->actor_id)->toBe($outsider->id)->and($denied->outcome)->toBe('Denied')->and($denied->target_reference)->toBe($world['referenceA'])
        ->and($denied->content)->not->toContain($world['customerA']->customer_id);

    $before = $auditCount();
    $this->getJson(route('customers.ledger-balance', $world['customerA']->customer_id))->assertNotFound();
    $this->get(route('customers.statements.preview', $world['customerA']->customer_id))->assertNotFound();
    expect($auditCount())->toBe($before);
});
