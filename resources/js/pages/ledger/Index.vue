<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { dashboard } from '@/routes';
import { resolve as resolveIncident } from '@/routes/ledger/incidents';
import {
    index as transactionsIndex,
    show as showTransaction,
} from '@/routes/transactions';
import { DatePicker } from '@/components/ui/date-picker';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

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

type Incident = {
    reference: string;
    category: string;
    status: string;
    summary: string;
    detected_at: string;
    recovered_at: string | null;
};
const props = defineProps<{
    incidents: Incident[];
    can_resolve_incidents: boolean;
    result: {
        status: 'ready' | 'unavailable';
        stale?: boolean;
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
const typeSelection = computed({
    get: () => type.value || '__all',
    set: (value: string) => {
        type.value = value === '__all' ? '' : value;
    },
});
const pageSizeSelection = computed({
    get: () => String(pageSize.value),
    set: (value: string) => {
        pageSize.value = Number(value);
    },
});

const resolution = useForm({ note: '', confirmed: true });
function resolve(reference: string): void {
    resolution.post(resolveIncident.url(reference), {
        preserveScroll: true,
        onSuccess: () => resolution.reset('note'),
    });
}
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
                This list shows posted financial activity in your current scope.
                Dates use
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
            <Select v-model="typeSelection"
                ><SelectTrigger aria-label="Transaction type" class="h-11 w-fit"
                    ><SelectValue /></SelectTrigger
                ><SelectContent>
                    <SelectItem value="__all">All types</SelectItem>
                    <SelectItem value="contribution">Contribution</SelectItem>
                    <SelectItem value="remittance">Remittance</SelectItem>
                    <SelectItem value="withdrawal">Withdrawal</SelectItem>
                    <SelectItem value="reversal">Correction</SelectItem>
                    <SelectItem value="deduction">Deduction</SelectItem>
                    <SelectItem value="fee_application"
                        >Fee applied from savings</SelectItem
                    >
                    <SelectItem value="fee_refund"
                        >Fee refund entitlement</SelectItem
                    >
                    <SelectItem value="external_refund_payment"
                        >Cash refund payment</SelectItem
                    >
                    <SelectItem value="earnings_draw">Earnings draw</SelectItem>
                </SelectContent></Select
            >
            <DatePicker id="transactions-from" v-model="from" class="w-fit" />
            <DatePicker id="transactions-to" v-model="to" class="w-fit" />
            <Select v-model="pageSizeSelection"
                ><SelectTrigger aria-label="Rows per page" class="h-11 w-fit"
                    ><SelectValue /></SelectTrigger
                ><SelectContent>
                    <SelectItem value="25">25 rows</SelectItem>
                    <SelectItem value="50">50 rows</SelectItem>
                    <SelectItem value="100">100 rows</SelectItem>
                </SelectContent></Select
            >
            <Button type="submit">Apply filters</Button>
        </form>

        <Card v-if="can_resolve_incidents && incidents.length > 0">
            <CardContent class="grid gap-4 pt-6">
                <p class="font-medium">Ledger integrity incidents</p>
                <div
                    v-for="incident in incidents"
                    :key="incident.reference"
                    class="grid gap-2 rounded-md border p-3 text-sm"
                >
                    <p>
                        {{ incident.category.replaceAll('_', ' ') }} ·
                        {{ incident.status }} · detected
                        {{ incident.detected_at }}
                    </p>
                    <p class="text-muted-foreground">{{ incident.summary }}</p>
                    <form
                        v-if="incident.status === 'recovered'"
                        class="flex flex-wrap items-end gap-3"
                        @submit.prevent="resolve(incident.reference)"
                    >
                        <div class="grid gap-1">
                            <label
                                :for="`resolve-${incident.reference}`"
                                class="text-sm font-medium"
                                >Resolution note</label
                            >
                            <Input
                                :id="`resolve-${incident.reference}`"
                                v-model="resolution.note"
                                maxlength="500"
                                required
                            />
                        </div>
                        <Button type="submit" :disabled="resolution.processing"
                            >Resolve incident</Button
                        >
                    </form>
                    <p v-else class="text-muted-foreground">
                        You can resolve this incident only after the ledger
                        verifies with no errors.
                    </p>
                </div>
            </CardContent>
        </Card>
        <Card v-if="result.status === 'unavailable'">
            <CardContent class="pt-6">
                <p class="font-medium">Transaction history is unavailable</p>
                <p class="text-muted-foreground mt-1 text-sm">
                    The ledger projection must verify before the page can show
                    financial totals.
                </p>
            </CardContent>
        </Card>
        <template v-else>
            <Card v-if="result.stale" role="status">
                <CardContent class="pt-6">
                    <p class="font-medium">
                        Showing the last verified history, which may be behind
                    </p>
                    <p class="text-muted-foreground mt-1 text-sm">
                        Entries after ledger watermark
                        {{ result.state.watermark }} do not show. Balances and
                        balance actions are not available until the ledger
                        verifies again.
                    </p>
                </CardContent>
            </Card>
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
                        No posted transactions agree with these filters.
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
