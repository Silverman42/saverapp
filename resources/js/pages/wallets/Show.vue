<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import { Wallet } from "@lucide/vue";
import EmptyState from "@/components/EmptyState.vue";
import PageHeader from "@/components/PageHeader.vue";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { dashboard } from "@/routes";
import {
    index as customersIndex,
    show as customerShow,
} from "@/routes/customers";
import { create as createWithdrawal } from "@/routes/customers/withdrawals";
import { show as showWithdrawal } from "@/routes/withdrawals";

type HistoryRow = {
    id: number;
    reference: string;
    type: string;
    occurred_on: string;
    amount_kobo: number;
    withdrawal_id: string | null;
};
const props = defineProps<{
    customer: { id: string; name: string };
    wallet: {
        liability_kobo: number;
        reservations_kobo: number;
        available_kobo: number;
        as_of: string;
    } | null;
    active: boolean;
    live_payout_id: string | null;
    history: {
        data: HistoryRow[];
        next_page_url: string | null;
        prev_page_url: string | null;
    };
    can_request_payout: boolean;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: "Dashboard", href: dashboard() },
            { title: "Customers", href: customersIndex() },
            { title: "Wallet", href: "#" },
        ],
    },
});
const money = (kobo: number): string =>
    `₦${(Math.abs(kobo) / 100).toLocaleString("en-NG", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const typeLabels: Record<string, string> = {
    plan_to_wallet_transfer: "Moved from a plan",
    plan_to_wallet_transfer_compensation: "Plan transfer corrected",
    wallet_contribution: "Paid into a plan",
    external_fee_receipt: "Fee paid",
    receipt_reclassification: "Plan payment corrected",
    cash_withdrawal: "Cash payout",
    bank_withdrawal: "Bank payout",
    offline_withdrawal: "Paid outside platform",
    withdrawal_compensation: "Payout returned",
};
const typeLabel = (type: string): string =>
    typeLabels[type] ??
    type.replaceAll("_", " ").replace(/^./, (letter) => letter.toUpperCase());
const payoutHref = createWithdrawal.url(props.customer.id, {
    query: { source: "wallet" },
});
</script>

<template>
    <Head title="Customer wallet" />
    <div class="flex flex-col gap-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <PageHeader
                title="Customer wallet"
                :description="`Money ${customer.name} has moved out of savings plans.`"
            />
            <div class="flex flex-wrap gap-2">
                <Button as-child variant="outline"
                    ><Link :href="customerShow(customer.id)"
                        >View customer</Link
                    ></Button
                >
                <Button v-if="can_request_payout && !live_payout_id" as-child
                    ><Link :href="payoutHref">Pay out from wallet</Link></Button
                >
            </div>
        </div>

        <p v-if="!active" role="status" class="bg-muted rounded-xl p-4 text-sm">
            The wallet is turned off. Balances stay visible, but no new wallet
            moves can be made.
        </p>

        <Card>
            <CardContent>
                <p
                    v-if="wallet === null"
                    role="status"
                    class="text-muted-foreground text-sm"
                >
                    The wallet balance is unavailable right now.
                </p>
                <dl v-else class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <dt class="text-muted-foreground text-sm">Balance</dt>
                        <dd class="text-2xl font-semibold">
                            {{ money(wallet.liability_kobo) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-sm">
                            Set aside for a payout
                        </dt>
                        <dd class="text-2xl font-semibold">
                            {{ money(wallet.reservations_kobo) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-sm">Available</dt>
                        <dd class="text-2xl font-semibold">
                            {{ money(wallet.available_kobo) }}
                        </dd>
                    </div>
                </dl>
                <p
                    v-if="live_payout_id"
                    class="text-muted-foreground mt-4 text-sm"
                >
                    A payout is in progress.
                    <Link
                        :href="showWithdrawal.url(live_payout_id)"
                        class="text-foreground underline"
                        >View {{ live_payout_id }}</Link
                    >
                </p>
            </CardContent>
        </Card>

        <EmptyState
            v-if="history.data.length === 0"
            :icon="Wallet"
            title="No wallet activity yet"
            description="Money moved out of a plan, paid into a plan or paid out will show here."
        />
        <Card v-else>
            <CardContent>
                <ul class="divide-y">
                    <li
                        v-for="row in history.data"
                        :key="row.id"
                        class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm first:pt-0 last:pb-0"
                    >
                        <div class="min-w-0">
                            <p class="font-medium">{{ typeLabel(row.type) }}</p>
                            <p class="text-muted-foreground">
                                {{ row.occurred_on }} ·
                                <Link
                                    v-if="row.withdrawal_id"
                                    :href="
                                        showWithdrawal.url(row.withdrawal_id)
                                    "
                                    class="underline"
                                    >{{ row.withdrawal_id }}</Link><span
                                    v-else
                                    >{{ row.reference }}</span
                                >
                            </p>
                        </div>
                        <p
                            class="font-medium tabular-nums"
                            :class="
                                row.amount_kobo < 0
                                    ? 'text-foreground'
                                    : 'text-emerald-700 dark:text-emerald-400'
                            "
                        >
                            {{ row.amount_kobo < 0 ? "−" : "+"
                            }}{{ money(row.amount_kobo) }}
                        </p>
                    </li>
                </ul>
                <div
                    v-if="history.prev_page_url || history.next_page_url"
                    class="mt-4 flex justify-between"
                >
                    <Button
                        v-if="history.prev_page_url"
                        as-child
                        variant="outline"
                        ><Link :href="history.prev_page_url"
                            >Previous</Link
                        ></Button
                    ><span v-else />
                    <Button
                        v-if="history.next_page_url"
                        as-child
                        variant="outline"
                        ><Link :href="history.next_page_url">Next</Link></Button
                    >
                </div>
            </CardContent>
        </Card>
    </div>
</template>
