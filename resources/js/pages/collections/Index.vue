<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronDown, Inbox, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import MoreDetails from '@/components/MoreDetails.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { DatePicker } from '@/components/ui/date-picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index as evidenceIndex } from '@/routes/collection-evidence';
import { dashboard } from '@/routes';
import {
    index as collectionsIndex,
    show as showReceipt,
} from '@/routes/collections';
import { create as createCollection } from '@/routes/customers/collections';
import { index as customersIndex } from '@/routes/customers';
import { index as batchesIndex } from '@/routes/collection-batches';

type Receipt = {
    id: string;
    customer_id: string;
    customer_name: string;
    plan_id: string | null;
    received_date: string;
    method: string;
    savings_kobo: number;
    fees_kobo: number;
    tender_kobo: number;
    can_record: boolean;
};
type DueSlot = {
    id: number;
    customer_id: string;
    customer_name: string;
    plan_id: string;
    ordinal: number;
    target_kobo: number;
    funded_kobo: number;
    advance_kobo: number;
    status: string;
};
type DueTotals = {
    slot_count: number;
    scheduled_kobo: number;
    covered_kobo: number;
    outstanding_kobo: number;
    blocked_target_kobo: number;
    unavailable_target_kobo: number;
    service_interrupted_target_kobo: number;
};
const props = defineProps<{
    receipts: {
        data: Receipt[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    date: string;
    timezone: string;
    totals: {
        tender_kobo: number;
        cash_kobo: number;
        bank_kobo: number;
        clearing_kobo: number;
        other_kobo: number;
        savings_kobo: number;
        fees_kobo: number;
        receipt_count: number;
    };
    due_totals: DueTotals;
    filters: { search: string; status: string };
    viewer_type: string;
    can_review_evidence: boolean;
    due_slots: {
        data: DueSlot[];
        prev_page_url: string | null;
        next_page_url: string | null;
    } | null;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Collections', href: collectionsIndex() },
        ],
    },
});
const money = (kobo: number): string =>
    `₦${(kobo / 100).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const selectedDate = ref(props.date);
const search = ref(props.filters.search);
const status = ref(props.filters.status);
const showEvidenceLink = computed(
    () => props.viewer_type === 'agent' || props.can_review_evidence,
);
const showBatchesLink = computed(
    () => props.viewer_type === 'admin' || props.viewer_type === 'agent',
);
function applyFilters(): void {
    router.get(
        collectionsIndex.url({
            query: {
                date: selectedDate.value,
                search: search.value || undefined,
                status: status.value === 'all' ? undefined : status.value,
            },
        }),
    );
}
</script>

<template>
    <Head title="Collections" />
    <div class="flex flex-col gap-6">
        <PageHeader
            title="Collections"
            description="Payments received and what is due."
        >
            <template
                v-if="
                    viewer_type === 'agent' ||
                    showEvidenceLink ||
                    showBatchesLink
                "
                #actions
            >
                <Button v-if="viewer_type === 'agent'" as-child
                    ><Link :href="customersIndex()"
                        >Record payment</Link
                    ></Button
                >
                <DropdownMenu
                    :modal="false"
                    v-if="showEvidenceLink || showBatchesLink"
                >
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline"
                            >More <ChevronDown class="size-4"
                        /></Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem v-if="showEvidenceLink" as-child>
                            <Link :href="evidenceIndex()"
                                >Payment evidence</Link
                            >
                        </DropdownMenuItem>
                        <DropdownMenuItem v-if="showBatchesLink" as-child>
                            <Link :href="batchesIndex()">Cash batches</Link>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </template>
        </PageHeader>

        <form
            class="flex flex-row flex-wrap items-end gap-4"
            aria-label="Collection filters"
            @submit.prevent="applyFilters"
        >
            <div class="grid w-fit gap-2">
                <Label for="collection-date">Date</Label
                ><DatePicker
                    id="collection-date"
                    aria-label="Date"
                    v-model="selectedDate"
                    class="w-fit"
                />
            </div>
            <div v-if="due_slots" class="grid w-fit gap-2">
                <Label for="collection-search">Customer</Label>
                <div class="relative">
                    <Search
                        class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
                    />
                    <Input
                        id="collection-search"
                        v-model="search"
                        class="w-fit pl-9"
                        maxlength="100"
                        placeholder="Name or ID"
                    />
                </div>
            </div>
            <div v-if="due_slots" class="grid w-fit gap-2">
                <Label for="collection-status">Status</Label>
                <Select
                    :model-value="status || 'all'"
                    @update:model-value="(value) => (status = String(value))"
                >
                    <SelectTrigger id="collection-status"
                        ><SelectValue
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All</SelectItem>
                        <SelectItem value="pending">Not paid yet</SelectItem>
                        <SelectItem value="partial">Part paid</SelectItem>
                        <SelectItem value="paid">Paid</SelectItem>
                        <SelectItem value="missed">Missed</SelectItem>
                        <SelectItem value="advance-covered"
                            >Paid early</SelectItem
                        >
                        <SelectItem value="blocked">Blocked</SelectItem>
                        <SelectItem value="unavailable"
                            >History not available</SelectItem
                        >
                        <SelectItem value="service-interrupted">
                            Agent not available
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <Button type="submit" variant="outline">Show</Button>
        </form>

        <Card>
            <CardHeader
                ><CardTitle>Received on {{ date }}</CardTitle></CardHeader
            >
            <CardContent class="space-y-4">
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="bg-muted/40 rounded-xl p-4">
                        <p class="text-muted-foreground text-sm">
                            Total received
                        </p>
                        <p class="mt-1 text-2xl font-semibold">
                            {{ money(totals.tender_kobo) }}
                        </p>
                    </div>
                    <div class="bg-muted/40 rounded-xl p-4">
                        <p class="text-muted-foreground text-sm">Cash</p>
                        <p class="mt-1 text-2xl font-semibold">
                            {{ money(totals.cash_kobo) }}
                        </p>
                    </div>
                    <div class="bg-muted/40 rounded-xl p-4">
                        <p class="text-muted-foreground text-sm">Payments</p>
                        <p class="mt-1 text-2xl font-semibold">
                            {{ totals.receipt_count }}
                        </p>
                    </div>
                </div>
                <MoreDetails label="See breakdown">
                    <dl
                        class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3"
                    >
                        <div>
                            <dt class="text-muted-foreground">Bank transfer</dt>
                            <dd class="font-medium">
                                {{ money(totals.bank_kobo) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                Waiting to reach the bank
                            </dt>
                            <dd class="font-medium">
                                {{ money(totals.clearing_kobo) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                Other methods held by agents
                            </dt>
                            <dd class="font-medium">
                                {{ money(totals.other_kobo) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">To savings</dt>
                            <dd class="font-medium">
                                {{ money(totals.savings_kobo) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Fees</dt>
                            <dd class="font-medium">
                                {{ money(totals.fees_kobo) }}
                            </dd>
                        </div>
                    </dl>
                    <p class="text-muted-foreground mt-3 text-xs">
                        Dates use the {{ timezone }} time zone.
                    </p>
                </MoreDetails>
            </CardContent>
        </Card>

        <Card v-if="due_slots">
            <CardHeader
                ><CardTitle>Due on {{ date }}</CardTitle></CardHeader
            >
            <CardContent class="space-y-5">
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="bg-muted/40 rounded-xl p-4">
                        <p class="text-muted-foreground text-sm">Expected</p>
                        <p class="mt-1 text-lg font-medium">
                            {{ money(due_totals.scheduled_kobo) }}
                        </p>
                    </div>
                    <div class="bg-muted/40 rounded-xl p-4">
                        <p class="text-muted-foreground text-sm">Paid</p>
                        <p class="mt-1 text-lg font-medium">
                            {{ money(due_totals.covered_kobo) }}
                        </p>
                    </div>
                    <div class="bg-muted/40 rounded-xl p-4">
                        <p class="text-muted-foreground text-sm">Still owed</p>
                        <p class="mt-1 text-lg font-medium">
                            {{ money(due_totals.outstanding_kobo) }}
                        </p>
                    </div>
                </div>
                <EmptyState
                    v-if="due_slots.data.length === 0"
                    :icon="Inbox"
                    title="Nothing due"
                    description="No customers match these filters. Try another date or status."
                />
                <ul v-else class="divide-y">
                    <li
                        v-for="slot in due_slots.data"
                        :key="slot.id"
                        class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm"
                    >
                        <div class="min-w-0">
                            <p class="font-medium">{{ slot.customer_name }}</p>
                            <p class="text-muted-foreground mt-0.5 text-xs">
                                Day {{ slot.ordinal }} ·
                                {{ money(slot.funded_kobo) }} of
                                {{ money(slot.target_kobo) }} paid
                                <span v-if="slot.advance_kobo > 0"
                                    >· {{ money(slot.advance_kobo) }} paid
                                    early</span
                                >
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            <Badge variant="secondary" class="capitalize">{{
                                slot.status.replaceAll('-', ' ')
                            }}</Badge>
                            <Button
                                v-if="
                                    viewer_type === 'agent' &&
                                    ['pending', 'partial', 'missed'].includes(
                                        slot.status,
                                    )
                                "
                                as-child
                                size="sm"
                                variant="outline"
                                ><Link
                                    :href="createCollection(slot.customer_id)"
                                    >Record cash</Link
                                ></Button
                            >
                        </div>
                    </li>
                </ul>
                <nav
                    v-if="due_slots.prev_page_url || due_slots.next_page_url"
                    aria-label="Due list pages"
                    class="flex gap-4 text-sm"
                >
                    <Link
                        v-if="due_slots.prev_page_url"
                        :href="due_slots.prev_page_url"
                        class="underline-offset-4 hover:underline"
                        >Previous</Link
                    ><Link
                        v-if="due_slots.next_page_url"
                        :href="due_slots.next_page_url"
                        class="underline-offset-4 hover:underline"
                        >Next</Link
                    >
                </nav>
                <MoreDetails
                    v-if="
                        due_totals.blocked_target_kobo > 0 ||
                        due_totals.service_interrupted_target_kobo > 0 ||
                        due_totals.unavailable_target_kobo > 0
                    "
                    label="Not counted in still owed"
                >
                    <ul class="text-muted-foreground grid gap-1 text-sm">
                        <li v-if="due_totals.blocked_target_kobo > 0">
                            Blocked: {{ money(due_totals.blocked_target_kobo) }}
                        </li>
                        <li
                            v-if="
                                due_totals.service_interrupted_target_kobo > 0
                            "
                        >
                            Agent not available:
                            {{
                                money(
                                    due_totals.service_interrupted_target_kobo,
                                )
                            }}
                        </li>
                        <li v-if="due_totals.unavailable_target_kobo > 0">
                            History not available:
                            {{ money(due_totals.unavailable_target_kobo) }}
                        </li>
                    </ul>
                    <p class="text-muted-foreground mt-2 text-xs">
                        Totals cover all {{ due_totals.slot_count }} matching
                        days, on every page. A payment can cover other days.
                    </p>
                </MoreDetails>
            </CardContent>
        </Card>

        <Card>
            <CardHeader><CardTitle>Payments</CardTitle></CardHeader>
            <CardContent>
                <EmptyState
                    v-if="receipts.data.length === 0"
                    :icon="Inbox"
                    title="No payments on this date"
                    description="Pick another date to see earlier payments."
                />
                <ul v-else class="divide-y">
                    <li
                        v-for="receipt in receipts.data"
                        :key="receipt.id"
                        class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
                    >
                        <div class="min-w-0">
                            <Link
                                :href="showReceipt(receipt.id)"
                                class="text-sm font-medium underline-offset-4 hover:underline"
                                >{{ receipt.customer_name }}</Link
                            >
                            <p class="text-muted-foreground mt-0.5 text-xs">
                                {{ receipt.method }} ·
                                {{ receipt.received_date }} · {{ receipt.id }}
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="text-sm font-medium">{{
                                money(receipt.tender_kobo)
                            }}</span>
                            <Button
                                v-if="receipt.can_record"
                                as-child
                                size="sm"
                                variant="ghost"
                                ><Link
                                    :href="
                                        createCollection(receipt.customer_id)
                                    "
                                    >Record another</Link
                                ></Button
                            >
                        </div>
                    </li>
                </ul>
                <nav
                    v-if="receipts.prev_page_url || receipts.next_page_url"
                    aria-label="Payment pages"
                    class="mt-4 flex gap-4 text-sm"
                >
                    <Link
                        v-if="receipts.prev_page_url"
                        :href="receipts.prev_page_url"
                        class="underline-offset-4 hover:underline"
                        >Previous</Link
                    ><Link
                        v-if="receipts.next_page_url"
                        :href="receipts.next_page_url"
                        class="underline-offset-4 hover:underline"
                        >Next</Link
                    >
                </nav>
            </CardContent>
        </Card>
    </div>
</template>
