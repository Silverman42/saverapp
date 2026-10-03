<?php

use App\Enums\AdminPermission;
use App\Models\AuditEvent;
use App\Models\CollectionBatch;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use App\Services\CollectionReadService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../CollectionExceptionFixtures.php';

test('reasoned investigation and awaiting-action transitions preserve custody and block reconciliation', function (): void {
    [, $customer, , $batch, $exception] = collectionInvestigationFixture($this);
    $groups = LedgerPostingGroup::query()->pluck('id')->all();
    foreach (['investigating', 'awaiting_action', 'investigating'] as $status) {
        $this->post(route('collection-batches.exceptions.progress', [$batch, $exception]), [
            'batch_version' => $batch->fresh()->version, 'status' => $status,
            'reason' => 'Verified investigation details require original Agent handoff.', 'confirmed' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
        expect($exception->fresh()->status)->toBe($status);
        $this->post(route('collection-batches.review', $batch), [
            'batch_version' => $batch->fresh()->version, 'reason' => 'Unresolved investigation remains.', 'confirmed' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
        expect($batch->fresh()->status)->toBe('exception');
    }
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(200000);
    expect(LedgerPostingGroup::query()->pluck('id')->all())->toBe($groups);
    expect(DB::table('collection_exception_events')->where('collection_exception_id', $exception->id)->pluck('event_type')->all())
        ->toBe(['opened', 'investigating', 'awaiting_action', 'investigating']);
    $this->assertDatabaseCount('collection_exceptions', 1);
});

test('investigation rejects skipped states stale versions cross-batch cases and unauthorized actors', function (): void {
    [$agent, $customer, $admin, $batch, $exception] = collectionInvestigationFixture($this);
    $route = route('collection-batches.exceptions.progress', [$batch, $exception]);
    $data = ['batch_version' => $batch->version, 'status' => 'investigating', 'reason' => 'Investigate original custody.', 'confirmed' => true];
    $this->post($route, [...$data, 'status' => 'awaiting_action'])->assertConflict();
    $this->post($route, [...$data, 'reason' => '   '])->assertSessionHasErrors('reason');
    $this->post($route, [...$data, 'batch_version' => $batch->version + 1])->assertConflict();
    $other = new CollectionBatch;
    $other->fill([...$batch->getAttributes(), 'revision' => $batch->revision + 1, 'version' => 1]);
    $other->save();
    $this->post(route('collection-batches.exceptions.progress', [$other, $exception]), [...$data, 'batch_version' => 1])->assertConflict();
    foreach ([$agent, $customer->user, User::factory()->admin()->create()] as $actor) {
        $this->actingAs($actor)->post($route, $data)->assertForbidden();
    }
    $admin->revokePermissionTo(AdminPermission::ReconciliationManage);
    $this->actingAs($admin)->post($route, $data)->assertForbidden();
    expect($exception->fresh()->status)->toBe('open');
    $this->assertDatabaseCount('collection_exception_events', 1);
});

test('late canonical audit failure rolls back exception investigation history and versions', function (): void {
    [, , , $batch, $exception] = collectionInvestigationFixture($this);
    Event::listen('eloquent.creating: '.AuditEvent::class, function (AuditEvent $event): void {
        if ($event->event_type === 'collection.exception_progressed') {
            throw new RuntimeException('Investigation audit unavailable.');
        }
    });
    $this->withoutExceptionHandling();
    expect(fn () => $this->post(route('collection-batches.exceptions.progress', [$batch, $exception]), [
        'batch_version' => $batch->version, 'status' => 'investigating', 'reason' => 'Investigate original custody.', 'confirmed' => true,
    ]))->toThrow(RuntimeException::class, 'Investigation audit unavailable.');
    expect($exception->fresh()->status)->toBe('open')->and($batch->fresh()->version)->toBe($batch->version);
    $this->assertDatabaseCount('collection_exception_events', 1);
});

test('an awaiting-action shortage resolves only after verified remittance and reopening restarts investigation', function (): void {
    [, $customer, , $batch, $exception, $date] = collectionInvestigationFixture($this);
    foreach (['investigating', 'awaiting_action'] as $status) {
        $this->post(route('collection-batches.exceptions.progress', [$batch, $exception]), [
            'batch_version' => $batch->fresh()->version, 'status' => $status, 'reason' => 'Original custody and required handoff verified.', 'confirmed' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
    }
    $resolve = ['reason' => 'Confirmed full original Agent handoff matches the batch.', 'confirmed' => true];
    $this->post(route('collection-batches.exceptions.resolve', [$batch, $exception]), [...$resolve, 'batch_version' => $batch->fresh()->version])->assertConflict();
    $this->post(route('collection-batches.remittances.store', $batch), [
        'batch_version' => $batch->fresh()->version, 'handoff_reference' => 'INVESTIGATED-FULL-HANDOFF', 'amount_ngn' => '2000.00',
        'handoff_date' => $date, 'receiving_location' => 'Lagos business office', 'source_attestation' => 'Counted full original Agent custody.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $groups = LedgerPostingGroup::query()->pluck('id')->all();
    $this->post(route('collection-batches.exceptions.resolve', [$batch, $exception]), [...$resolve, 'batch_version' => $batch->fresh()->version])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect($exception->fresh()->status)->toBe('resolved');
    $this->post(route('collection-batches.exceptions.reopen', [$batch, $exception]), [
        'batch_version' => $batch->fresh()->version, 'reason' => 'New conflicting count evidence requires another review.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($exception->fresh()->status)->toBe('reopened');
    $this->post(route('collection-batches.exceptions.progress', [$batch, $exception]), [
        'batch_version' => $batch->fresh()->version, 'status' => 'investigating', 'reason' => 'Investigate the conflicting new evidence.', 'confirmed' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($exception->fresh()->status)->toBe('investigating');
    expect(app(CollectionReadService::class)->position($customer)['liability_kobo'])->toBe(200000);
    expect(LedgerPostingGroup::query()->pluck('id')->all())->toBe($groups);
    expect(DB::table('collection_exception_events')->where('collection_exception_id', $exception->id)->pluck('event_type')->all())
        ->toBe(['opened', 'investigating', 'awaiting_action', 'resolved', 'reopened', 'investigating']);
});
