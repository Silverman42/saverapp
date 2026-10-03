<?php

use App\Enums\AdminPermission;
use App\Models\AgentProfile;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\AuditProjection;
use App\Services\PlanSettlementService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\CreatesLifecycleCustomers;

uses(CreatesLifecycleCustomers::class);

test('TPC-AC-050: reviewed settlement audit retains exact protected lifecycle sources and accepted replay', function (): void {
    $this->freezeTime();
    Queue::fake();
    config()->set('collections.settlement_enabled', true);
    [, $customer, $agent] = $this->createLifecycleFixture();
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $service = app(PlanSettlementService::class);
    foreach (['prepare_termination' => 'early_termination_prepared', 'close' => 'closed'] as $action => $type) {
        $plan->refresh();
        $fromStatus = $plan->status->value;
        $fromVersion = $plan->version;
        $quote = $service->preview($agent->user, $plan, $action);
        $operation = (string) Str::uuid();
        $reason = 'SECRET reviewed '.$action.' explanation';
        $data = ['attempt_reference' => $operation, 'preview_fingerprint' => $quote['preview_fingerprint'],
            'reason' => $reason, 'customer_explanation' => 'Your original dated cycle remains retained.'];
        $service->confirm($agent->user, $plan, $action, $data);
        $plan->refresh();
        $lifecycle = $plan->lifecycleEvents()->where('event_type', $type)->sole();
        $supplement = DB::table('financial_workflow_supplements')->where('operation_reference', $operation)->sole();
        expect($lifecycle->reason)->toBe($reason)->and($lifecycle->actor_user_id)->toBe($agent->user_id)
            ->and($lifecycle->assignment_version)->toBe($customer->currentAssignment->version)
            ->and($lifecycle->from_status->value)->toBe($fromStatus)
            ->and($lifecycle->to_status)->toBe($plan->status)->and($lifecycle->plan_version)->toBe($plan->version)
            ->and(json_decode($supplement->facts, true, flags: JSON_THROW_ON_ERROR)['event_id'])->toBe($lifecycle->id)
            ->and($supplement->kind)->toBe($type)->and($supplement->actor_user_id)->toBe($agent->user_id);
        $event = AuditEvent::query()->where('event_type', 'thrift_plan.'.$type)->sole();
        $canonical = DB::table('canonical_audit_events')->where('legacy_audit_event_id', $event->id)->sole();
        expect($canonical->correlation_reference)->toBe($operation)
            ->and($event->target_reference)->toBe($plan->plan_id)->and($event->actor_id)->toBe($agent->user_id)
            ->and($event->payload['customer_profile_id'])->toBe($customer->id)
            ->and($event->payload['assignment_version'])->toBe($customer->currentAssignment->version)
            ->and($event->payload['terms_revision'])->toBe($plan->current_terms_revision)
            ->and($event->payload['lifecycle_event_id'])->toBe($lifecycle->id)
            ->and($event->payload['from'])->toBe($fromStatus)->and($event->payload['to'])->toBe($plan->status->value)
            ->and($event->payload['from_version'])->toBe($fromVersion)->and($event->payload['version'])->toBe($plan->version)
            ->and($event->payload['gate_fingerprint'])->toBe($quote['preview_fingerprint'])
            ->and($event->payload)->not->toHaveKey('reason')->and($canonical->content)->not->toContain('SECRET');
        $protected = DB::table('audit_protected_payloads')->where('canonical_event_id', $canonical->id)->sole();
        expect($protected->ciphertext)->not->toContain('SECRET')
            ->and(Crypt::decryptString($protected->ciphertext))->toContain($reason);
        $rows = planAuditOwnerRows();
        foreach (['financial_workflow_supplements', 'audit_events', 'canonical_audit_events', 'audit_protected_payloads'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
        }
        $service->confirm($agent->user, $plan, $action, $data);
        foreach ($rows as $table => $before) {
            expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($before);
        }
    }
    $this->actingAs($customer->user)->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page
        ->has('plan.history', 2)->where('plan.history.0.reason', null)->where('plan.history.1.reason', null)
        ->where('plan.history.0.actor', null)->where('plan.history.1.actor', null)->missing('audit'));
    expect(DB::table('ledger_posting_groups')->count())->toBe(0);
});

/** @return array<string, mixed> */
function planAuditOwnerRows(): array
{
    $rows = [];
    foreach (['thrift_plans', 'plan_terms_revisions', 'contribution_slots', 'fee_snapshots', 'plan_operation_attempts',
        'plan_lifecycle_events', 'plan_notification_intents', 'fee_obligations', 'fee_obligation_entries',
        'collection_receipts', 'collection_allocations', 'ledger_posting_groups', 'ledger_entries'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return $rows;
}

test('plan mutation failures retain scoped masked canonical evidence without changing owner records', function (string $failure): void {
    Queue::fake();
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $actor = $agent->user;
    $payload = ['attempt_reference' => (string) Str::uuid(), 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'plan_version' => $plan->version,
        'reason' => 'SECRET private submission', 'customer_explanation' => 'Safe explanation'];
    $status = 409;
    $category = 'state_conflict';
    $route = route('plans.pause', $plan->plan_id);
    if ($failure === 'conflict') {
        $payload['plan_version'] = 999;
    } elseif ($failure === 'validation') {
        unset($payload['attempt_reference']);
        $status = 422;
        $category = 'validation_failed';
    } elseif ($failure === 'creation') {
        $route = route('customers.plans.store', $customer->customer_id);
        $status = 422;
        $category = 'validation_failed';
    } elseif ($failure === 'role') {
        $actor = $admin;
        $status = 403;
        $category = 'authority_or_scope_denied';
    } elseif ($failure === 'scope') {
        $actor = AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id])->user;
        $status = 404;
        $category = 'authority_or_scope_denied';
    }
    $baseline = planAuditOwnerRows();
    $this->actingAs($actor)->postJson($route, $payload)->assertStatus($status);
    expect(planAuditOwnerRows())->toEqual($baseline);
    $event = AuditEvent::query()->where('event_type', 'thrift_plan.management_attempt')->sole();
    expect($event->actor_id)->toBe($actor->id)
        ->and($event->target_reference)->toBe(in_array($failure, ['scope', 'creation'], true) ? null : $plan->plan_id)
        ->and($event->payload['category'])->toBe($category)
        ->and($event->payload['customer_profile_id'])->toBe($failure === 'scope' ? null : $customer->id)
        ->and($event->payload['version'])->toBe(in_array($failure, ['scope', 'creation'], true) ? null : $plan->version);
    $canonical = DB::table('canonical_audit_events')->where('legacy_audit_event_id', $event->id)->sole();
    expect($canonical->outcome)->toBe($status === 409 ? 'Conflict' : ($status === 422 ? 'Failed' : 'Denied'))
        ->and($canonical->content)->not->toContain('SECRET')
        ->and($canonical->content)->not->toContain($payload['attempt_reference'] ?? 'SECRET');
    if ($failure === 'scope') {
        expect($canonical->content)->not->toContain($plan->plan_id);
    }
    app(AuditProjection::class)->drain();
    expect(json_encode(DB::table('audit_search_documents')->get(), JSON_THROW_ON_ERROR))->not->toContain('SECRET');
})->with(['conflict', 'validation', 'creation', 'role', 'scope']);

test('plan history redacts privileged explanations while canonical detail requires audit authority', function (): void {
    Queue::fake();
    config(['audit.enabled' => true]);
    [$admin, $customer, $agent] = $this->createLifecycleFixture();
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $payload = ['attempt_reference' => (string) Str::uuid(), 'customer_version' => $customer->version,
        'assignment_version' => $customer->currentAssignment->version, 'plan_version' => $plan->version,
        'reason' => 'SECRET privileged lifecycle explanation', 'customer_explanation' => 'Your plan is paused.'];
    $this->actingAs($agent->user)->post(route('plans.pause', $plan->plan_id), $payload)->assertRedirect();
    $event = AuditEvent::query()->where('event_type', 'thrift_plan.pause')->sole();
    $canonical = DB::table('canonical_audit_events')->where('legacy_audit_event_id', $event->id)->sole();
    expect($event->payload)->not->toHaveKey('reason')
        ->and($event->payload['customer_profile_id'])->toBe($customer->id)
        ->and($event->payload['assignment_version'])->toBe($customer->currentAssignment->version)
        ->and($event->payload['from_version'])->toBe(1)->and($event->payload['version'])->toBe(2)
        ->and($event->payload['terms_revision'])->toBe(1)
        ->and($canonical->correlation_reference)->toBe($payload['attempt_reference'])
        ->and($canonical->content)->not->toContain('SECRET');
    $protected = DB::table('audit_protected_payloads')->where('canonical_event_id', $canonical->id)->sole();
    expect($protected->ciphertext)->not->toContain('SECRET')
        ->and(Crypt::decryptString($protected->ciphertext))->toContain($payload['reason']);
    $baseline = planAuditOwnerRows();
    $this->actingAs($customer->user)->get(route('plans.show', $plan->plan_id))->assertInertia(fn (Assert $page) => $page
        ->where('plan.history.0.reason', null)->where('plan.history.0.actor', null)
        ->where('plan.history.0.explanation', $payload['customer_explanation'])->missing('audit'));
    foreach ([$customer->user, $agent->user, $admin] as $viewer) {
        $this->actingAs($viewer)->get(route('admin.audit.show', $canonical->event_id))->assertForbidden();
    }
    $admin->givePermissionTo(AdminPermission::AuditView);
    $this->actingAs($admin)->get(route('admin.audit.show', $canonical->event_id))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('event.summary.event_id', $canonical->event_id));
    expect(planAuditOwnerRows())->toEqual($baseline);
});

test('bound settlement denial retains only the currently scoped plan identity', function (bool $foreign): void {
    Queue::fake();
    [, $customer, $agent] = $this->createLifecycleFixture();
    $plan = $this->createLifecyclePlan($customer, $agent->user);
    $actor = $foreign ? AgentProfile::factory()->active()->create(['user_id' => User::factory()->agent()->withTwoFactor()->create()->id])->user : $agent->user;
    $baseline = planAuditOwnerRows();
    $this->actingAs($actor)->postJson(route('plans.settlement.confirm', [$plan->plan_id, 'close']), [
        'attempt_reference' => (string) Str::uuid(), 'preview_fingerprint' => str_repeat('a', 64),
        'reason' => 'SECRET settlement explanation', 'customer_explanation' => 'A safe explanation.', 'confirmed' => true,
    ])->assertStatus($foreign ? 403 : 503);
    $event = AuditEvent::query()->where('event_type', 'thrift_plan.management_attempt')->sole();
    expect($event->target_reference)->toBe($foreign ? null : $plan->plan_id)
        ->and($event->payload['customer_profile_id'])->toBe($foreign ? null : $customer->id)
        ->and($event->payload['category'])->toBe($foreign ? 'authority_or_scope_denied' : 'system_failed')
        ->and(planAuditOwnerRows())->toEqual($baseline);
    $canonical = DB::table('canonical_audit_events')->where('legacy_audit_event_id', $event->id)->sole();
    expect($canonical->content)->not->toContain('SECRET');
    if ($foreign) {
        expect($canonical->content)->not->toContain($plan->plan_id);
    }
})->with([false, true]);
