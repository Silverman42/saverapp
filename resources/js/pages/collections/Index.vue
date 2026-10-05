<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Card, CardContent } from '@/components/ui/card';
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
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">Collections</h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                This page shows daily work and posted receipts for your current
                Customers. Dates use {{ timezone }}.
            </p>
        </div>
        <div class="flex flex-row flex-wrap items-end gap-4">
            <div class="grid w-fit gap-2">
                <Label for="collection-date">Business date</Label
                ><DatePicker
                    id="collection-date"
                    aria-label="Business date"
                    v-model="selectedDate"
                    class="w-fit"
                />
            </div>
            <div v-if="due_slots" class="grid w-fit gap-2">
                <Label for="collection-search">Customer name or ID</Label
                ><Input
                    id="collection-search"
                    v-model="search"
                    class="w-fit"
                    maxlength="100"
                />
            </div>
            <div v-if="due_slots" class="grid w-fit gap-2">
                <Label for="collection-status">Due work</Label>
                <Select
                    :model-value="status || 'all'"
                    @update:model-value="(value) => (status = String(value))"
                >
                    <SelectTrigger id="collection-status"
                        ><SelectValue
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All</SelectItem>
                        <SelectItem value="pending">Pending</SelectItem>
                        <SelectItem value="partial">Partial</SelectItem>
                        <SelectItem value="paid">Paid</SelectItem>
                        <SelectItem value="missed">Missed</SelectItem>
                        <SelectItem value="advance-covered"
                            >Advance covered</SelectItem
                        >
                        <SelectItem value="blocked">Blocked</SelectItem>
                        <SelectItem value="unavailable"
                            >History unavailable</SelectItem
                        >
                        <SelectItem value="service-interrupted">
                            Agent unavailable
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <Button type="button" variant="outline" @click="applyFilters"
                >Apply filters</Button
            >
        </div>
        <Link
            v-if="viewer_type === 'agent' || can_review_evidence"
            :href="evidenceIndex()"
            class="w-fit text-sm underline"
            >Open protected payment evidence</Link
        >
        <Card
            ><CardContent class="grid gap-3 pt-6 sm:grid-cols-2 lg:grid-cols-4"
                ><div>
                    Receipts received {{ date }}<br /><strong>{{
                        totals.receipt_count
                    }}</strong>
                </div>
                <div>
                    Total received<br /><strong>{{
                        money(totals.tender_kobo)
                    }}</strong>
                </div>
                <div>
                    Cash received<br /><strong>{{
                        money(totals.cash_kobo)
                    }}</strong>
                </div>
                <div>
                    Bank received<br /><strong>{{
                        money(totals.bank_kobo)
                    }}</strong>
                </div>
                <div>
                    Clearing captured<br /><strong>{{
                        money(totals.clearing_kobo)
                    }}</strong>
                </div>
                <div>
                    Other Agent custody<br /><strong>{{
                        money(totals.other_kobo)
                    }}</strong>
                </div>
                <div>
                    Savings component<br /><strong>{{
                        money(totals.savings_kobo)
                    }}</strong>
                </div>
                <div>
                    Fee component<br /><strong>{{
                        money(totals.fees_kobo)
                    }}</strong>
                </div></CardContent
            ></Card
        >
        <Card v-if="due_slots"
            ><CardContent class="pt-6"
                ><h2 class="font-medium">Slots due {{ date }}</h2>
                <p class="text-muted-foreground mt-1 text-sm">
                    Due work totals include all matching slots on all pages.
                    Received tender uses the receipt date. It can fund other
                    days.
                </p>
                <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <div>
                        Matching slots<br /><strong>{{
                            due_totals.slot_count
                        }}</strong>
                    </div>
                    <div>
                        Scheduled<br /><strong>{{
                            money(due_totals.scheduled_kobo)
                        }}</strong>
                    </div>
                    <div>
                        Covered<br /><strong>{{
                            money(due_totals.covered_kobo)
                        }}</strong>
                    </div>
                    <div>
                        Outstanding eligible<br /><strong>{{
                            money(due_totals.outstanding_kobo)
                        }}</strong>
                    </div>
                    <div>
                        Blocked target<br /><strong>{{
                            money(due_totals.blocked_target_kobo)
                        }}</strong>
                    </div>
                </div>
                <p
                    v-if="due_totals.service_interrupted_target_kobo > 0"
                    class="text-muted-foreground mt-3 text-sm"
                >
                    The assigned Agent is unavailable for
                    {{ money(due_totals.service_interrupted_target_kobo) }} of
                    scheduled targets. Eligible outstanding does not include
                    these rows. Recorded contributions do not change.
                </p>
                <p
                    v-if="due_totals.unavailable_target_kobo > 0"
                    class="text-muted-foreground mt-3 text-sm"
                >
                    Participation history is unavailable for
                    {{ money(due_totals.unavailable_target_kobo) }} of scheduled
                    targets. Eligible outstanding does not include these rows.
                </p>
                <p
                    v-if="due_slots.data.length === 0"
                    class="text-muted-foreground mt-5 text-sm"
                >
                    No slots in your current scope match these filters.
                </p>
                <ul v-else class="mt-5 grid gap-3 sm:grid-cols-2">
                    <li
                        v-for="slot in due_slots.data"
                        :key="slot.id"
                        class="rounded-md border p-3 text-sm"
                    >
                        <strong
                            >{{ slot.customer_name }} · day
                            {{ slot.ordinal }}</strong
                        >
                        <p class="mt-1 capitalize">
                            {{ slot.status.replaceAll('-', ' ') }}
                        </p>
                        <p class="mt-1">
                            {{ money(slot.funded_kobo) }} of
                            {{ money(slot.target_kobo) }} funded
                            <span v-if="slot.advance_kobo > 0"
                                >· {{ money(slot.advance_kobo) }} covered before
                                this due date</span
                            >
                        </p>
                        <Link
                            v-if="
                                viewer_type === 'agent' &&
                                ['pending', 'partial', 'missed'].includes(
                                    slot.status,
                                )
                            "
                            :href="createCollection(slot.customer_id)"
                            class="text-primary mt-2 inline-block underline"
                            >Record collection</Link
                        >
                    </li>
                </ul>
                <div class="mt-4 flex gap-4 text-sm">
                    <Link
                        v-if="due_slots.prev_page_url"
                        :href="due_slots.prev_page_url"
                        class="underline"
                        >Previous due slots</Link
                    ><Link
                        v-if="due_slots.next_page_url"
                        :href="due_slots.next_page_url"
                        class="underline"
                        >Next due slots</Link
                    >
                </div></CardContent
            ></Card
        >
        <Link
            v-if="viewer_type === 'agent'"
            :href="customersIndex()"
            class="text-primary w-fit text-sm underline"
            >Find another assigned Customer to record catch-up or advance
            cash</Link
        >
        <Link
            v-if="viewer_type === 'admin' || viewer_type === 'agent'"
            :href="batchesIndex()"
            class="text-primary w-fit text-sm underline"
            >Cash batches and reconciliation</Link
        >
        <Card
            ><CardContent class="pt-6">
                <p
                    v-if="receipts.data.length === 0"
                    class="text-muted-foreground text-sm"
                >
                    Your current scope has no receipts for this date.
                </p>
                <ul v-else class="divide-y">
                    <li
                        v-for="receipt in receipts.data"
                        :key="receipt.id"
                        class="flex flex-wrap items-center justify-between gap-3 py-4 first:pt-0 last:pb-0"
                    >
                        <div class="grid gap-1">
                            <Link
                                :href="showReceipt(receipt.id)"
                                class="font-medium underline"
                                >{{ receipt.id }}</Link
                            ><span class="text-muted-foreground text-sm"
                                >{{ receipt.customer_name }} · received
                                {{ receipt.received_date }} ·
                                {{ receipt.method }}</span
                            >
                        </div>
                        <div class="flex items-center gap-4">
                            <span class="text-sm">{{
                                money(receipt.tender_kobo)
                            }}</span
                            ><Link
                                v-if="receipt.can_record"
                                :href="createCollection(receipt.customer_id)"
                                class="text-primary text-sm underline"
                                >Record another</Link
                            >
                        </div>
                    </li>
                </ul>
            </CardContent></Card
        >
        <div class="flex gap-4 text-sm">
            <Link
                v-if="receipts.prev_page_url"
                :href="receipts.prev_page_url"
                class="underline"
                >Previous</Link
            ><Link
                v-if="receipts.next_page_url"
                :href="receipts.next_page_url"
                class="underline"
                >Next</Link
            >
        </div>
    </div>
</template>
