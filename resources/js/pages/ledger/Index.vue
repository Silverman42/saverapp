<script setup lang="ts">
import { Head, Link, router, usePage, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import {
    ArrowRight,
    ReceiptText,
    Search,
    SlidersHorizontal,
} from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import FormSheet from '@/components/FormSheet.vue';
import MoreDetails from '@/components/MoreDetails.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { dashboard } from '@/routes';
import { resolve as resolveIncident } from '@/routes/ledger/incidents';
import {
    index as transactionsIndex,
    show as showTransaction,
} from '@/routes/transactions';
import { DatePicker } from '@/components/ui/date-picker';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
const page = usePage();
const dateRangeError = computed(
    () => page.props.errors.from ?? page.props.errors.to,
);
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

const filtersOpen = ref(false);
const activeFilterCount = computed(
    () =>
        (props.filters.customer ? 1 : 0) +
        ((props.filters.page_size ?? 25) !== 25 ? 1 : 0),
);
const typeLabels: Record<string, string> = {
    contribution: 'Contribution',
    remittance: 'Remittance',
    withdrawal: 'Withdrawal',
    reversal: 'Correction',
    deduction: 'Deduction',
    fee_application: 'Fee taken from savings',
    fee_refund: 'Fee refund owed',
    external_refund_payment: 'Cash refund',
    earnings_draw: 'Earnings draw',
};
function typeLabel(value: string): string {
    if (typeLabels[value]) return typeLabels[value];
    const words = value.replaceAll('_', ' ');
    return words.charAt(0).toUpperCase() + words.slice(1);
}

const resolution = useForm({ note: '', confirmed: true });
const resolvingIncident = ref<Incident | null>(null);
function resolve(reference: string): void {
    resolution.post(resolveIncident.url(reference), {
        preserveScroll: true,
        onSuccess: () => {
            resolution.reset('note');
            resolvingIncident.value = null;
        },
    });
}
function applyFromSheet(): void {
    filtersOpen.value = false;
    query();
}
function clearSheetFilters(): void {
    customer.value = '';
    pageSize.value = 25;
    filtersOpen.value = false;
    query();
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
        <PageHeader
            title="Transactions"
            description="Money in and out for the customers you can see."
        />

        <form
            class="flex flex-row flex-wrap items-end gap-4"
            aria-label="Transaction filters"
            @submit.prevent="query()"
        >
            <div class="relative w-fit">
                <Search
                    class="text-muted-foreground absolute top-3.5 left-3 size-4"
                />
                <Input
                    v-model="reference"
                    aria-label="Transaction reference"
                    placeholder="Search by reference"
                    class="w-64 pl-9"
                />
            </div>
            <Select v-model="typeSelection"
                ><SelectTrigger aria-label="Transaction type" class="h-11 w-fit"
                    ><SelectValue /></SelectTrigger
                ><SelectContent>
                    <SelectItem value="__all">All types</SelectItem>
                    <SelectItem
                        v-for="(label, value) in typeLabels"
                        :key="value"
                        :value="value"
                        >{{ label }}</SelectItem
                    >
                </SelectContent></Select
            >
            <DatePicker
                id="transactions-from"
                :error-message="dateRangeError"
                v-model="from"
                aria-label="From date"
                class="w-fit"
            />
            <DatePicker
                id="transactions-to"
                :error-message="dateRangeError"
                v-model="to"
                aria-label="To date"
                class="w-fit"
            />
            <Button type="submit">Show</Button>
            <Button type="button" variant="outline" @click="filtersOpen = true">
                <SlidersHorizontal class="size-4" />
                Filters
                <span
                    v-if="activeFilterCount > 0"
                    class="bg-primary text-primary-foreground inline-flex size-5 items-center justify-center rounded-full text-[11px]"
                    >{{ activeFilterCount }}</span
                >
            </Button>
            <InputError class="basis-full" :message="dateRangeError" />
        </form>

        <FormSheet
            v-model:open="filtersOpen"
            title="Filters"
            description="Narrow down the transactions you see."
        >
            <div class="grid gap-5">
                <div class="grid gap-2">
                    <Label for="transactions-customer">Customer ID</Label>
                    <Input
                        id="transactions-customer"
                        v-model="customer"
                        placeholder="CUS-…"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="transactions-page-size">Rows per page</Label>
                    <Select v-model="pageSizeSelection"
                        ><SelectTrigger
                            id="transactions-page-size"
                            class="w-full"
                            ><SelectValue /></SelectTrigger
                        ><SelectContent>
                            <SelectItem value="25">25</SelectItem>
                            <SelectItem value="50">50</SelectItem>
                            <SelectItem value="100">100</SelectItem>
                        </SelectContent></Select
                    >
                </div>
            </div>
            <template #footer>
                <Button
                    type="button"
                    variant="outline"
                    @click="clearSheetFilters"
                    >Clear</Button
                >
                <Button type="button" @click="applyFromSheet"
                    >Show results</Button
                >
            </template>
        </FormSheet>

        <Card v-if="can_resolve_incidents && incidents.length > 0">
            <CardHeader><CardTitle>Issues to check</CardTitle></CardHeader>
            <CardContent>
                <ul class="divide-y">
                    <li
                        v-for="incident in incidents"
                        :key="incident.reference"
                        class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0"
                    >
                        <div class="min-w-0 space-y-0.5 text-sm">
                            <p class="font-medium capitalize">
                                {{ incident.category.replaceAll('_', ' ') }}
                            </p>
                            <p class="text-muted-foreground">
                                {{ incident.summary }}
                            </p>
                            <p class="text-muted-foreground text-xs">
                                Found {{ incident.detected_at }} ·
                                {{ incident.status }}
                            </p>
                        </div>
                        <Button
                            v-if="incident.status === 'recovered'"
                            variant="outline"
                            size="sm"
                            @click="resolvingIncident = incident"
                            >Resolve</Button
                        >
                        <p
                            v-else
                            class="text-muted-foreground max-w-xs text-xs"
                        >
                            You can resolve this once the next check passes.
                        </p>
                    </li>
                </ul>
            </CardContent>
        </Card>

        <EmptyState
            v-if="result.status === 'unavailable'"
            :icon="ReceiptText"
            title="Transactions are not available right now"
            description="We are still checking the records. Please try again in a few minutes."
        />
        <template v-else>
            <div
                v-if="result.stale"
                role="status"
                class="bg-muted/40 rounded-xl p-4 text-sm"
            >
                <p class="font-medium">This list may be out of date</p>
                <p class="text-muted-foreground mt-1">
                    The newest entries may be missing. Balances are paused until
                    the records are checked again.
                </p>
            </div>
            <Card>
                <CardContent class="space-y-4">
                    <p class="text-muted-foreground text-sm">
                        {{ result.total }} transactions from
                        {{ filters.from }} to {{ filters.to }} · Net effect on
                        savings
                        <span class="text-foreground font-medium">{{
                            money(result.savings_effect_kobo ?? 0)
                        }}</span>
                    </p>
                    <EmptyState
                        v-if="result.data.length === 0"
                        :icon="ReceiptText"
                        title="No transactions found"
                        description="Try a different date range or clear your filters."
                    />
                    <ul v-else class="divide-y">
                        <li
                            v-for="transaction in result.data"
                            :key="transaction.reference"
                        >
                            <Link
                                :href="showTransaction(transaction.reference)"
                                class="group hover:bg-accent/40 focus-visible:ring-ring -mx-2 flex flex-wrap items-center justify-between gap-4 rounded-lg px-2 py-3 focus-visible:ring-2 focus-visible:outline-none"
                            >
                                <div class="grid min-w-0 gap-0.5">
                                    <span class="text-sm font-medium">{{
                                        typeLabel(transaction.type)
                                    }}</span>
                                    <span class="text-muted-foreground text-xs">
                                        {{
                                            transaction.customer_name ??
                                            'Business cash'
                                        }}
                                        · {{ transaction.occurred_on }} ·
                                        {{ transaction.reference }}
                                    </span>
                                </div>
                                <div class="flex items-center gap-3">
                                    <div class="text-right text-sm">
                                        <p class="font-medium">
                                            {{
                                                money(
                                                    transaction.savings_effect_kobo,
                                                )
                                            }}
                                        </p>
                                        <p
                                            class="text-muted-foreground text-xs"
                                        >
                                            Total
                                            {{
                                                money(
                                                    transaction.gross_amount_kobo,
                                                )
                                            }}
                                        </p>
                                    </div>
                                    <ArrowRight
                                        class="text-muted-foreground size-4 shrink-0"
                                    />
                                </div>
                            </Link>
                        </li>
                    </ul>
                    <Button
                        v-if="result.next_cursor"
                        type="button"
                        variant="outline"
                        class="w-fit"
                        @click="query(result.next_cursor)"
                        >Next page</Button
                    >
                    <MoreDetails label="About this list">
                        <dl
                            class="text-muted-foreground grid gap-2 text-xs sm:grid-cols-2"
                        >
                            <div>
                                <dt class="text-foreground">Dates shown in</dt>
                                <dd>{{ timezone }}</dd>
                            </div>
                            <div v-if="result.state.verified_at">
                                <dt class="text-foreground">Last checked</dt>
                                <dd>{{ result.state.verified_at }}</dd>
                            </div>
                            <div>
                                <dt class="text-foreground">Record position</dt>
                                <dd>
                                    {{ result.state.watermark }} (version
                                    {{ result.state.version }})
                                </dd>
                            </div>
                        </dl>
                    </MoreDetails>
                </CardContent>
            </Card>
        </template>
    </div>

    <Dialog
        :open="resolvingIncident !== null"
        @update:open="
            (open) => {
                if (!open && !resolution.processing) resolvingIncident = null;
            }
        "
    >
        <DialogContent v-if="resolvingIncident">
            <DialogHeader>
                <DialogTitle>Resolve this issue?</DialogTitle>
                <DialogDescription>{{
                    resolvingIncident.summary
                }}</DialogDescription>
            </DialogHeader>
            <form
                :id="`resolve-form-${resolvingIncident.reference}`"
                class="grid gap-2"
                @submit.prevent="resolve(resolvingIncident.reference)"
            >
                <Label :for="`resolve-${resolvingIncident.reference}`"
                    >What was done?</Label
                >
                <Input
                    :id="`resolve-${resolvingIncident.reference}`"
                    v-model="resolution.note"
                    maxlength="500"
                    required
                />
                <p
                    v-for="(error, key) in resolution.errors"
                    :key="key"
                    role="alert"
                    class="text-destructive text-sm"
                >
                    {{ error }}
                </p>
            </form>
            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="resolution.processing"
                    @click="resolvingIncident = null"
                    >Cancel</Button
                >
                <Button
                    type="submit"
                    :form="`resolve-form-${resolvingIncident.reference}`"
                    :disabled="resolution.processing"
                    >Resolve</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
