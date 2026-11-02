<?php

use App\Enums\AdminPermission;
use App\Enums\FinancialNoteType;
use App\Models\AuditEvent;
use App\Models\FinancialNote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function financialNoteManager(): User
{
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FinancialNotesManage);

    return $admin;
}

/**
 * @return array<string, string>
 */
function financialNotePayload(array $overrides = []): array
{
    return [
        'type' => 'debit',
        'title' => 'Office rent',
        'amount_ngn' => '12,500.50',
        'note_date' => '2026-10-15',
        'category' => 'Rent',
        'body' => 'Paid October rent to the landlord.',
        ...$overrides,
    ];
}

test('only Admins holding the financial notes grant can reach any financial note route', function (): void {
    $note = FinancialNote::factory()->create();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $routes = [
        ['get', route('admin.financial-notes.index'), []],
        ['post', route('admin.financial-notes.store'), financialNotePayload()],
        ['put', route('admin.financial-notes.update', $note), financialNotePayload(['version' => 1])],
        ['post', route('admin.financial-notes.archive', $note), ['version' => 1]],
        ['post', route('admin.financial-notes.restore', $note), ['version' => 1]],
    ];

    foreach ([$admin, User::factory()->agent()->create(), User::factory()->customer()->create()] as $user) {
        foreach ($routes as [$method, $url, $data]) {
            $this->actingAs($user)->{$method}($url, $data)->assertForbidden();
        }
    }
    expect(FinancialNote::query()->count())->toBe(1)
        ->and($note->fresh()->version)->toBe(1);

    $admin->givePermissionTo(AdminPermission::FinancialNotesManage);
    $this->actingAs($admin->fresh())->get(route('admin.financial-notes.index'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/financial-notes/Index')
        ->has('notes.data', 1));
});

test('creating a note stores the amount in kobo and records an audit event without touching the ledger', function (): void {
    $admin = financialNoteManager();
    $ledgerCounts = fn (): array => collect(['ledger_entries', 'ledger_posting_groups'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    $before = $ledgerCounts();

    assertToast($this->actingAs($admin)->post(route('admin.financial-notes.store'), financialNotePayload())
        ->assertRedirect(route('admin.financial-notes.index')), 'success', 'Note added');

    $note = FinancialNote::query()->sole();
    expect($note->type)->toBe(FinancialNoteType::Debit)
        ->and($note->amount_kobo)->toBe(1_250_050)
        ->and($note->note_date->toDateString())->toBe('2026-10-15')
        ->and($note->category)->toBe('Rent')
        ->and($note->status)->toBe(FinancialNote::STATUS_ACTIVE)
        ->and($note->created_by_user_id)->toBe($admin->id)
        ->and($ledgerCounts())->toBe($before);

    $event = AuditEvent::query()->where('event_type', 'financial_note.created')->sole();
    expect($event->target_reference)->toBe($note->reference);
});

test('invalid note input is rejected without saving', function (array $overrides, string $field): void {
    $this->actingAs(financialNoteManager())
        ->post(route('admin.financial-notes.store'), financialNotePayload($overrides))
        ->assertSessionHasErrors($field);

    expect(FinancialNote::query()->count())->toBe(0);
})->with([
    'zero amount' => [['amount_ngn' => '0'], 'amount_ngn'],
    'negative amount' => [['amount_ngn' => '-50'], 'amount_ngn'],
    'three decimals' => [['amount_ngn' => '10.505'], 'amount_ngn'],
    'unknown type' => [['type' => 'transfer'], 'type'],
    'missing title' => [['title' => ''], 'title'],
    'missing body' => [['body' => ''], 'body'],
    'bad date' => [['note_date' => '15/10/2026'], 'note_date'],
]);

test('editing a note bumps its version, audits changed fields and rejects a stale version', function (): void {
    $admin = financialNoteManager();
    $note = FinancialNote::factory()->debit()->create(['amount_kobo' => 10_000, 'title' => 'Fuel']);

    assertToast($this->actingAs($admin)->put(route('admin.financial-notes.update', $note), financialNotePayload([
        'title' => 'Generator fuel', 'amount_ngn' => '250', 'version' => 1,
    ])), 'success', 'Note updated');

    $note->refresh();
    expect($note->title)->toBe('Generator fuel')
        ->and($note->amount_kobo)->toBe(25_000)
        ->and($note->version)->toBe(2)
        ->and($note->updated_by_user_id)->toBe($admin->id);
    $event = AuditEvent::query()->where('event_type', 'financial_note.updated')->sole();
    expect(json_encode($event->toArray()))->toContain('amount_kobo')->not->toContain('Generator fuel');

    assertToast($this->put(route('admin.financial-notes.update', $note), financialNotePayload([
        'title' => 'Stale edit', 'version' => 1,
    ])), 'error', 'Note not changed');
    expect($note->fresh()->title)->toBe('Generator fuel')
        ->and($note->fresh()->version)->toBe(2);
});

test('archiving hides a note from the list and totals and restoring brings it back', function (): void {
    $admin = financialNoteManager();
    $note = FinancialNote::factory()->credit()->create(['amount_kobo' => 50_000]);

    assertToast($this->actingAs($admin)->post(route('admin.financial-notes.archive', $note), ['version' => 1]), 'success', 'Note archived');
    $note->refresh();
    expect($note->status)->toBe(FinancialNote::STATUS_ARCHIVED)
        ->and($note->archived_by_user_id)->toBe($admin->id)
        ->and($note->version)->toBe(2);

    $this->get(route('admin.financial-notes.index'))->assertInertia(fn (Assert $page) => $page
        ->has('notes.data', 0)->where('summary.credit', '₦0.00'));
    $this->get(route('admin.financial-notes.index', ['status' => 'archived']))->assertInertia(fn (Assert $page) => $page
        ->has('notes.data', 1));

    $this->put(route('admin.financial-notes.update', $note), financialNotePayload(['version' => 2]));
    expect($note->fresh()->version)->toBe(2);

    assertToast($this->post(route('admin.financial-notes.restore', $note), ['version' => 2]), 'success', 'Note restored');
    expect($note->fresh()->status)->toBe(FinancialNote::STATUS_ACTIVE)
        ->and($note->fresh()->archived_at)->toBeNull()
        ->and(AuditEvent::query()->whereIn('event_type', ['financial_note.archived', 'financial_note.restored'])->count())->toBe(2);
});

test('filters narrow the list and the credit, debit and net totals', function (): void {
    $admin = financialNoteManager();
    FinancialNote::factory()->credit()->create(['amount_kobo' => 100_000, 'note_date' => '2026-10-01', 'title' => 'Sponsorship', 'category' => 'Grants']);
    FinancialNote::factory()->debit()->create(['amount_kobo' => 30_000, 'note_date' => '2026-10-10', 'title' => 'Rent', 'category' => 'Rent']);
    FinancialNote::factory()->debit()->create(['amount_kobo' => 200_000, 'note_date' => '2026-09-01', 'title' => 'Laptop', 'category' => 'Equipment']);

    $this->actingAs($admin)->get(route('admin.financial-notes.index'))->assertInertia(fn (Assert $page) => $page
        ->has('notes.data', 3)
        ->where('summary.credit', '₦1,000.00')
        ->where('summary.debit', '₦2,300.00')
        ->where('summary.net', '-₦1,300.00')
        ->where('summary.net_is_negative', true));

    $this->get(route('admin.financial-notes.index', ['from' => '2026-10-01', 'to' => '2026-10-31']))
        ->assertInertia(fn (Assert $page) => $page->has('notes.data', 2)->where('summary.net', '₦700.00'));

    $this->get(route('admin.financial-notes.index', ['type' => 'debit', 'search' => 'lap']))
        ->assertInertia(fn (Assert $page) => $page->has('notes.data', 1)->where('notes.data.0.title', 'Laptop'));
});
