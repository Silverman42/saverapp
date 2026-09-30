<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { dashboard } from '@/routes';
import {
    index as transactionsIndex,
    show as showTransaction,
} from '@/routes/transactions';
import { DatePicker } from '@/components/ui/date-picker';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

type Transaction = {
    reference: string;
    type: string;
    status: string;
    customer_id: string | null;
    customer_name: string | null;
    occurred_on: string;
    committed_at: string;
    gross_amount_kobo: number;
    savings_effect_kobo: number;
    fee_amount_kobo: number;
};

const props = defineProps<{
    result: {
        status: 'ready' | 'unavailable';
        data: Transaction[];
        total: number | null;
        savings_effect_kobo: number | null;
        next_cursor: string | null;
        state: {
            verified_at: string | null;
            version: number;
            watermark: number;
        };
    };
    filters: {
        customer?: string;
        type?: string;
        reference?: string;
        from: string;
        to: string;
        page_size?: number;
        cursor?: string;
    };
    timezone: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Transactions', href: transactionsIndex() },
        ],
    },
});

const customer = ref(props.filters.customer ?? '');
const reference = ref(props.filters.reference ?? '');
const type = ref(props.filters.type ?? '');
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const pageSize = ref(props.filters.page_size ?? 25);

function query(cursor?: string): void {
    router.get(
        transactionsIndex.url({
            query: {
                customer: customer.value || undefined,
                reference: reference.value || undefined,
                type: type.value || undefined,
                from: from.value,
                to: to.value,
                page_size: pageSize.value,
                cursor,
            },
        }),
    );
}

function money(kobo: number): string {
    const absolute = Math.abs(kobo);
    return `${kobo < 0 ? '−' : ''}₦${(absolute / 100).toLocaleString('en-NG', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;
}
</script>

<template>
    <Head title="Transactions" />
    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">Transactions</h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                Posted financial activity in your current scope. Dates use
                {{ timezone }}.
            </p>
        </div>

        <form class="flex flex-row flex-wrap gap-4" @submit.prevent="query()">
            <Input
                v-model="reference"
                aria-label="Transaction reference"
                placeholder="Transaction reference"
                class="w-fit"
            />
            <Input
                v-model="customer"
                aria-label="Customer ID"
                placeholder="Customer ID"
                class="w-fit"
            />
            <select
                v-model="type"
                aria-label="Transaction type"
                class="border-input bg-background h-11 w-fit rounded-md border px-3 text-sm"
            >
                <option value="">All types</option>
                <option value="contribution">Contribution</option>
                <option value="remittance">Remittance</option>
                <option value="withdrawal">Withdrawal</option>
                <option value="reversal">Correction</option>
                <option value="deduction">Deduction</option>
                <option value="fee_refund">Fee refund entitlement</option>
                <option value="external_refund_payment">
                    Cash refund payment
                </option>
                <option value="earnings_draw">Earnings draw</option>
            </select>
            <DatePicker id="transactions-from" v-model="from" class="w-fit" />
            <DatePicker id="transactions-to" v-model="to" class="w-fit" />
            <select
                v-model.number="pageSize"
                aria-label="Rows per page"
                class="border-input bg-background h-11 w-fit rounded-md border px-3 text-sm"
            >
                <option :value="25">25 rows</option>
                <option :value="50">50 rows</option>
                <option :value="100">100 rows</option>
            </select>
            <Button type="submit">Apply filters</Button>
        </form>

        <Card v-if="result.status === 'unavailable'">
            <CardContent class="pt-6">
                <p class="font-medium">Transaction history is unavailable</p>
                <p class="text-muted-foreground mt-1 text-sm">
                    The ledger projection needs verification before financial
                    totals can be shown.
                </p>
            </CardContent>
        </Card>
        <template v-else>
            <p class="text-muted-foreground text-sm">
                {{ result.total }} matching transactions · savings effect
                {{ money(result.savings_effect_kobo ?? 0) }}
                <span v-if="result.state.verified_at">
                    · verified {{ result.state.verified_at }}</span
                >
            </p>
            <Card>
                <CardContent class="pt-6">
                    <p
                        v-if="result.data.length === 0"
                        class="text-muted-foreground text-sm"
                    >
                        No posted transactions match these filters.
                    </p>
                    <ul v-else class="divide-y">
                        <li
                            v-for="transaction in result.data"
                            :key="transaction.reference"
                            class="flex flex-wrap items-start justify-between gap-4 py-4 first:pt-0 last:pb-0"
                        >
                            <div class="grid gap-1">
                                <Link
                                    :href="
                                        showTransaction(transaction.reference)
                                    "
                                    class="font-medium underline"
                                    >{{ transaction.reference }}</Link
                                >
                                <span class="text-muted-foreground text-sm">
                                    {{ transaction.type }} ·
                                    {{
                                        transaction.customer_name ??
                                        'Business custody'
                                    }}
                                    · occurred {{ transaction.occurred_on }}
                                </span>
                                <span class="text-muted-foreground text-xs"
                                    >Posted {{ transaction.committed_at }} UTC ·
                                    {{ transaction.status }}</span
                                >
                            </div>
                            <div class="text-right text-sm">
                                <strong>{{
                                    money(transaction.savings_effect_kobo)
                                }}</strong>
                                <p class="text-muted-foreground">
                                    Gross
                                    {{ money(transaction.gross_amount_kobo) }}
                                </p>
                            </div>
                        </li>
                    </ul>
                </CardContent>
            </Card>
            <Button
                v-if="result.next_cursor"
                type="button"
                variant="outline"
                class="w-fit"
                @click="query(result.next_cursor)"
                >Next page</Button
            >
        </template>
    </div>
</template>
