<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { DatePicker } from '@/components/ui/date-picker';
import { dashboard } from '@/routes';
import { index as batchesIndex, review as reviewBatch } from '@/routes/collection-batches';
import { store as storeRemittance } from '@/routes/collection-batches/remittances';
import { watch } from 'vue';
import { resolve as resolveException, reopen as reopenException, store as storeException } from '@/routes/collection-batches/exceptions';

type Batch = { id: number; date: string; revision: number; status: string; version: number; expected_kobo: number; savings_kobo: number; fees_kobo: number; remitted_kobo: number; outstanding_kobo: number; receipts: Array<{ id: string; tender_kobo: number }>; remittances: Array<{ reference: string; amount_kobo: number }>; exceptions: Array<{ id: number; kind: string; status: string; amount_kobo: number; reason: string }> };
const props = defineProps<{ batch: Batch; can_manage: boolean }>();
defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Cash batches', href: batchesIndex() }, { title: 'Batch', href: '#' }] } });
const money = (kobo: number): string => `₦${(kobo / 100).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const remittance = useForm({ handoff_reference: '', amount_ngn: '', handoff_date: props.batch.date, receiving_location: '', source_attestation: '', batch_version: props.batch.version, confirmed: false });
const review = useForm({ batch_version: props.batch.version, reason: '', confirmed: false });
const resolution = useForm({ batch_version: props.batch.version, reason: '', confirmed: false });
const reopening = useForm({ batch_version: props.batch.version, reason: '', confirmed: false });
const investigation = useForm({ batch_version: props.batch.version, kind: 'overage', amount_ngn: '', reason: '', confirmed: false });
watch(() => props.batch.version, (version) => {
    remittance.batch_version = version;
    review.batch_version = version;
    resolution.batch_version = version;
    reopening.batch_version = version;
    investigation.batch_version = version;
});
function confirmRemittance(): void { remittance.post(storeRemittance.url(props.batch.id)); }
function submitReview(): void { review.post(reviewBatch.url(props.batch.id)); }
function resolve(exceptionId: number): void { resolution.post(resolveException.url([props.batch.id, exceptionId])); }
function reopen(exceptionId: number): void { reopening.post(reopenException.url([props.batch.id, exceptionId])); }
function report(): void { investigation.post(storeException.url(props.batch.id)); }
</script>

<template>
    <Head :title="`Cash batch ${batch.date}`" />
    <div class="flex flex-col gap-6">
        <div><h1 class="text-[25px] font-medium tracking-tight">Cash batch {{ batch.date }}</h1><p class="text-muted-foreground mt-1.5 text-sm">Revision {{ batch.revision }} · {{ batch.status.replaceAll('_', ' ') }}</p></div>
        <Card><CardHeader><CardTitle>Custody position</CardTitle></CardHeader><CardContent class="grid gap-3 sm:grid-cols-3"><div>Cash recorded<br /><strong>{{ money(batch.expected_kobo) }}</strong><p class="text-muted-foreground text-sm">Savings {{ money(batch.savings_kobo) }} · fees {{ money(batch.fees_kobo) }}</p></div><div>Confirmed handoffs<br /><strong>{{ money(batch.remitted_kobo) }}</strong></div><div>Outstanding Agent receivable<br /><strong>{{ money(batch.outstanding_kobo) }}</strong></div></CardContent></Card>
        <Card v-if="can_manage"><CardHeader><CardTitle>Posted receipts</CardTitle></CardHeader><CardContent><p v-if="batch.receipts.length === 0" class="text-muted-foreground text-sm">No receipts.</p><ul v-else class="grid gap-2 text-sm"><li v-for="receipt in batch.receipts" :key="receipt.id">{{ receipt.id }} · {{ money(receipt.tender_kobo) }}</li></ul></CardContent></Card>
        <Card><CardHeader><CardTitle>Confirmed handoffs</CardTitle></CardHeader><CardContent><p v-if="batch.remittances.length === 0" class="text-muted-foreground text-sm">No confirmed cash handoffs.</p><ul v-else class="grid gap-2 text-sm"><li v-for="item in batch.remittances" :key="item.reference">{{ item.reference }} · {{ money(item.amount_kobo) }}</li></ul></CardContent></Card>
        <Card v-if="can_manage && batch.status !== 'open' && batch.status !== 'reconciled'"><CardHeader><CardTitle>Confirm counted cash</CardTitle></CardHeader><CardContent class="grid gap-4 sm:grid-cols-2"><div class="grid gap-2"><Label for="handoff-reference">Unique handoff reference</Label><Input id="handoff-reference" v-model="remittance.handoff_reference" /></div><div class="grid gap-2"><Label for="handoff-amount">Amount counted (NGN)</Label><Input id="handoff-amount" v-model="remittance.amount_ngn" inputmode="decimal" /></div><div class="grid gap-2"><Label for="handoff-date">Handoff date</Label><DatePicker id="handoff-date" v-model="remittance.handoff_date" /></div><div class="grid gap-2"><Label for="handoff-location">Receiving location</Label><Input id="handoff-location" v-model="remittance.receiving_location" /></div><div class="grid gap-2 sm:col-span-2"><Label for="handoff-attestation">Count and source attestation</Label><Input id="handoff-attestation" v-model="remittance.source_attestation" /></div><label class="flex gap-2 text-sm sm:col-span-2"><input v-model="remittance.confirmed" type="checkbox" /> I counted and received this cash.</label><Button type="button" :disabled="remittance.processing || !remittance.confirmed" class="w-fit" @click="confirmRemittance">Confirm handoff</Button><p v-for="(error, key) in remittance.errors" :key="key" class="text-destructive text-sm">{{ error }}</p></CardContent></Card>
        <Card v-if="can_manage && batch.status !== 'open' && batch.status !== 'reconciled'"><CardHeader><CardTitle>Review batch</CardTitle></CardHeader><CardContent class="grid gap-4"><div class="grid gap-2"><Label for="review-reason">Review reason and evidence summary</Label><Input id="review-reason" v-model="review.reason" /></div><label class="flex gap-2 text-sm"><input v-model="review.confirmed" type="checkbox" /> Confirm this review. A shortage opens an exception and retains Customer credit.</label><Button type="button" :disabled="review.processing || !review.confirmed" class="w-fit" @click="submitReview">Record review</Button><p v-for="(error, key) in review.errors" :key="key" class="text-destructive text-sm">{{ error }}</p></CardContent></Card>
        <Card v-if="can_manage && batch.status !== 'open'"><CardHeader><CardTitle>Report an investigation</CardTitle></CardHeader><CardContent class="grid max-w-md gap-3"><Label for="exception-kind">Issue</Label><select id="exception-kind" v-model="investigation.kind" class="rounded-md border bg-background p-2 text-sm"><option value="overage">Counted cash overage</option><option value="missing_transfer">Missing transfer evidence</option></select><Label for="exception-amount">Amount (NGN)</Label><Input id="exception-amount" v-model="investigation.amount_ngn" inputmode="decimal" /><Label for="exception-reason">Evidence and reason</Label><Input id="exception-reason" v-model="investigation.reason" /><label class="flex gap-2 text-sm"><input v-model="investigation.confirmed" type="checkbox" /> Open an investigation without changing Customer savings or assigning this amount.</label><Button type="button" class="w-fit" :disabled="investigation.processing || !investigation.confirmed" @click="report">Report issue</Button><p v-for="(error, key) in investigation.errors" :key="key" class="text-destructive text-sm">{{ error }}</p></CardContent></Card>
        <Card v-if="batch.exceptions.length"><CardHeader><CardTitle>Exceptions</CardTitle></CardHeader><CardContent class="grid gap-4"><div v-for="item in batch.exceptions" :key="item.id" class="rounded-md border p-3 text-sm"><strong>{{ item.kind.replaceAll('_', ' ') }} · {{ item.status }}</strong><p>{{ money(item.amount_kobo) }} · {{ item.reason }}</p><div v-if="can_manage && item.kind === 'cash_shortage' && item.status === 'open' && batch.outstanding_kobo === 0" class="mt-3 grid gap-2"><Label :for="`resolution-${item.id}`">Resolution reason</Label><Input :id="`resolution-${item.id}`" v-model="resolution.reason" /><label class="flex gap-2"><input v-model="resolution.confirmed" type="checkbox" /> Confirm this exception can close.</label><Button type="button" class="w-fit" :disabled="resolution.processing || !resolution.confirmed" @click="resolve(item.id)">Resolve exception</Button></div><div v-if="can_manage && item.status === 'resolved'" class="mt-3 grid gap-2"><Label :for="`reopen-${item.id}`">New evidence or reopening reason</Label><Input :id="`reopen-${item.id}`" v-model="reopening.reason" /><label class="flex gap-2"><input v-model="reopening.confirmed" type="checkbox" /> Confirm this case must reopen.</label><Button type="button" variant="outline" class="w-fit" :disabled="reopening.processing || !reopening.confirmed" @click="reopen(item.id)">Reopen exception</Button></div></div><p v-for="(error, key) in resolution.errors" :key="key" class="text-destructive text-sm">{{ error }}</p><p v-for="(error, key) in reopening.errors" :key="key" class="text-destructive text-sm">{{ error }}</p></CardContent></Card>
        <Link :href="batchesIndex()" class="text-primary w-fit text-sm underline">Back to cash batches</Link>
    </div>
</template>
