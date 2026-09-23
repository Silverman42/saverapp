<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Card, CardContent } from '@/components/ui/card';
import { DatePicker } from '@/components/ui/date-picker';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { index as collectionsIndex, show as showReceipt } from '@/routes/collections';
import { create as createCollection } from '@/routes/customers/collections';
import { index as batchesIndex } from '@/routes/collection-batches';

type Receipt = { id: string; customer_id: string; customer_name: string; plan_id: string | null; received_date: string; savings_kobo: number; fees_kobo: number; tender_kobo: number };
type DueSlot = { customer_id: string; customer_name: string; plan_id: string; ordinal: number; target_kobo: number; funded_kobo: number; advance_kobo: number; blocked: boolean };
const props = defineProps<{ receipts: { data: Receipt[]; prev_page_url: string | null; next_page_url: string | null }; date: string; timezone: string; totals: { tender_kobo: number; receipt_count: number }; assigned_customers: Array<{ id: string; name: string }>; due_slots: { data: DueSlot[]; prev_page_url: string | null; next_page_url: string | null } | null }>();
defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Collections', href: collectionsIndex() }] } });
const money = (kobo: number): string => `₦${(kobo / 100).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const selectedDate = ref(props.date);
function applyDate(): void { router.get(collectionsIndex.url({ query: { date: selectedDate.value } })); }
</script>

<template>
    <Head title="Collections" />
    <div class="flex flex-col gap-6">
        <div><h1 class="text-[25px] font-medium tracking-tight">Collections</h1><p class="text-muted-foreground mt-1.5 text-sm">Daily work and posted cash receipts in your current Customer scope. Dates use {{ timezone }}.</p></div>
        <div class="flex flex-row flex-wrap items-end gap-4"><DatePicker id="collection-date" v-model="selectedDate" class="w-fit" /><Button type="button" variant="outline" @click="applyDate">Show date</Button></div>
        <Card><CardContent class="grid gap-3 pt-6 sm:grid-cols-2"><div>Receipts for {{ date }}<br /><strong>{{ totals.receipt_count }}</strong></div><div>Cash recorded<br /><strong>{{ money(totals.tender_kobo) }}</strong></div></CardContent></Card>
        <Card v-if="due_slots"><CardContent class="pt-6"><h2 class="mb-3 font-medium">Slots due {{ date }}</h2><p v-if="due_slots.data.length === 0" class="text-muted-foreground text-sm">No slots due in your current scope.</p><ul v-else class="grid gap-3 sm:grid-cols-2"><li v-for="slot in due_slots.data" :key="`${slot.plan_id}-${slot.ordinal}`" class="rounded-md border p-3 text-sm"><strong>{{ slot.customer_name }} · day {{ slot.ordinal }}</strong><p class="mt-1">{{ money(slot.funded_kobo) }} of {{ money(slot.target_kobo) }} funded <span v-if="slot.advance_kobo > 0">· {{ money(slot.advance_kobo) }} covered before today</span></p><p v-if="slot.blocked" class="text-muted-foreground mt-1">Plan currently blocked for collection</p><Link v-else :href="createCollection(slot.customer_id)" class="text-primary mt-2 inline-block underline">Record cash</Link></li></ul><div class="mt-4 flex gap-4 text-sm"><Link v-if="due_slots.prev_page_url" :href="due_slots.prev_page_url" class="underline">Previous due slots</Link><Link v-if="due_slots.next_page_url" :href="due_slots.next_page_url" class="underline">Next due slots</Link></div></CardContent></Card>
        <Card v-if="assigned_customers.length"><CardContent class="pt-6"><h2 class="mb-3 font-medium">Assigned Customers</h2><ul class="grid gap-2 sm:grid-cols-2"><li v-for="customer in assigned_customers" :key="customer.id" class="flex items-center justify-between gap-3 text-sm"><span>{{ customer.name }}</span><Link :href="createCollection(customer.id)" class="text-primary underline">Record cash</Link></li></ul></CardContent></Card>
        <Link :href="batchesIndex()" class="text-primary w-fit text-sm underline">Cash batches and reconciliation</Link>
        <Card><CardContent class="pt-6">
            <p v-if="receipts.data.length === 0" class="text-muted-foreground text-sm">No receipts for this date in your current scope.</p>
            <ul v-else class="divide-y">
                <li v-for="receipt in receipts.data" :key="receipt.id" class="flex flex-wrap items-center justify-between gap-3 py-4 first:pt-0 last:pb-0">
                    <div class="grid gap-1"><Link :href="showReceipt(receipt.id)" class="font-medium underline">{{ receipt.id }}</Link><span class="text-muted-foreground text-sm">{{ receipt.customer_name }} · received {{ receipt.received_date }}</span></div>
                    <div class="flex items-center gap-4"><span class="text-sm">{{ money(receipt.tender_kobo) }}</span><Link :href="createCollection(receipt.customer_id)" class="text-primary text-sm underline">Record another</Link></div>
                </li>
            </ul>
        </CardContent></Card>
        <div class="flex gap-4 text-sm"><Link v-if="receipts.prev_page_url" :href="receipts.prev_page_url" class="underline">Previous</Link><Link v-if="receipts.next_page_url" :href="receipts.next_page_url" class="underline">Next</Link></div>
    </div>
</template>
