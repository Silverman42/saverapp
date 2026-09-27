<?php

use App\Enums\AdminPermission;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerEntrySide;
use App\Models\LedgerAccount;
use App\Models\LedgerPostingGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('admin fee overview renders earnings for today, this month, and all time with immutable dates', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-27 12:00:00', 'UTC'));
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $admin->givePermissionTo(AdminPermission::FeesManage);
    $incomeAccount = LedgerAccount::query()->where('code', LedgerAccountCode::FeeIncome->value)->sole();
    $cashAccount = LedgerAccount::query()->where('code', LedgerAccountCode::BusinessCash->value)->sole();

    foreach ([
        ['2026-08-31 23:59:59', LedgerEntrySide::Credit, 10000],
        ['2026-09-01 00:00:00', LedgerEntrySide::Credit, 20000],
        ['2026-09-27 00:00:00', LedgerEntrySide::Credit, 30000],
        ['2026-09-27 09:00:00', LedgerEntrySide::Debit, 5000],
    ] as $index => [$committedAt, $side, $amountKobo]) {
        $posting = LedgerPostingGroup::create([
            'posting_reference' => 'FEE-OVERVIEW-'.$index,
            'idempotency_key' => 'fee-overview-'.$index,
            'payload_hash' => str_repeat('a', 64),
            'source_type' => 'fee_settlement',
            'source_id' => (string) $index,
            'event_type' => 'fee_settlement',
            'currency' => 'NGN',
            'actor_user_id' => $admin->id,
            'occurred_at' => $committedAt,
            'committed_at' => $committedAt,
        ]);
        $posting->entries()->createMany([
            ['line_number' => 1, 'ledger_account_id' => $incomeAccount->id, 'side' => $side, 'amount_kobo' => $amountKobo],
            ['line_number' => 2, 'ledger_account_id' => $cashAccount->id,
                'side' => $side === LedgerEntrySide::Credit ? LedgerEntrySide::Debit : LedgerEntrySide::Credit,
                'amount_kobo' => $amountKobo],
        ]);
    }

    $this->actingAs($admin)->get(route('admin.fees.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/fees/Index')
            ->where('summary.earnings.status', 'available')
            ->where('summary.earnings.lifetime_gross_kobo', 60000)
            ->where('summary.earnings.lifetime_refunds_kobo', 5000)
            ->where('summary.earnings.lifetime_net_kobo', 55000)
            ->where('summary.earnings.today_net_kobo', 25000)
            ->where('summary.earnings.month_net_kobo', 45000));
});
