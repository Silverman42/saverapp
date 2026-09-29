<script setup lang="ts">
import { Head, Link, useForm, useHttp } from '@inertiajs/vue3';
import { HttpResponseError } from '@inertiajs/core';
import { computed, onMounted, ref, watch } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { DatePicker } from '@/components/ui/date-picker';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { index as collectionsIndex, show as showReceipt } from '@/routes/collections';
import { show as showAttempt } from '@/routes/collections/attempts';
import { preview as previewCollection, store as storeCollection } from '@/routes/customers/collections';
import { timeOptions } from '@/routes/customers/collections';

type Preview = {
    preview_fingerprint: string;
    customer_version: number;
    assignment_version: number;
    business_version: number;
    plan_version: number | null;
    savings_kobo: number;
    fees_kobo: number;
    tender_kobo: number;
    timezone: string;
    received_at_utc: string | null;
    plan_timezone: string | null;
    plan_received_date: string | null;
    allocations: Array<{ slot_id: number; due_date: string; amount_kobo: number }>;
    slot_options: Array<{ slot_id: number; due_date: string; capacity_kobo: number }>;
};

const props = defineProps<{
    customer: { id: string; name: string };
    plans: Array<{ id: string; name: string; timezone: string | null }>;
    fee_obligations: Array<{ id: number; description: string; outstanding_kobo: number }>;
    today: string;
    business_timezone: string;
}>();
const crossZone = computed(() => props.plans.find((plan) => plan.id === form.plan_id)?.timezone !== props.business_timezone && form.plan_id !== '');

function newAttemptReference(): string {
    if (crypto.randomUUID) return crypto.randomUUID();

    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6]! & 0x0f) | 0x40;
    bytes[8] = (bytes[8]! & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

defineOptions({
    layout: { breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Collections', href: collectionsIndex() },
        { title: 'Record cash', href: '#' },
    ] },
});

const form = useForm({
    attempt_reference: newAttemptReference(), preview_fingerprint: '',
    customer_version: 0, assignment_version: 0, business_version: 0,
    plan_id: props.plans[0]?.id ?? '', plan_version: null as number | null,
    received_date: props.today, received_local_time: '', received_utc_offset: '', savings_ngn: '',
    fees: props.fee_obligations.map((fee) => ({ obligation_id: fee.id, amount_ngn: '' })),
    allocations: [] as Array<{ slot_id: number; amount_ngn: string }>,
    late_reason: '', notes: '', confirmed: false,
});
const previewHttp = useHttp({
    plan_id: '', received_date: '', received_local_time: '', received_utc_offset: '', savings_ngn: '',
    fees: [] as Array<{ obligation_id: number; amount_ngn: string }>,
    allocations: [] as Array<{ slot_id: number; amount_ngn: string }>,
    late_reason: '', notes: '',
});
const preview = ref<Preview | null>(null);
const timeOptionsHttp = useHttp({});
const validOffsets = ref<Array<{ offset: string; received_at_utc: string }>>([]);
const timeNotice = ref('');
const customSlots = ref<Array<{ slot_id: number; due_date: string; capacity_kobo: number }>>([]);
const submissionPending = ref(false);
const outcomeUnknown = ref(false);
const attemptLookup = useHttp({});
const recoveredReceipt = ref<string | null>(null);
const lookupNotice = ref('');
const retryAllowed = ref(false);
const pendingAttemptStorageKey = `collection-pending-attempt:${props.customer.id}`;
const money = (kobo: number): string => `₦${(kobo / 100).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

function rememberAttempt(): void {
    try {
        sessionStorage.setItem(pendingAttemptStorageKey, form.attempt_reference);
    } catch {
        // The current page still retains the reference when tab storage is unavailable.
    }
}

function forgetAttempt(): void {
    try {
        sessionStorage.removeItem(pendingAttemptStorageKey);
    } catch {
        // Storage can become unavailable while a request is in flight.
    }
}

onMounted(() => {
    try {
        const reference = sessionStorage.getItem(pendingAttemptStorageKey);
        if (reference && /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(reference)) {
            form.attempt_reference = reference;
            outcomeUnknown.value = true;
            lookupNotice.value = 'This attempt needs a result check before any cash is recorded again.';
        }
    } catch {
        // A fresh form remains usable when tab storage is unavailable.
    }
});

watch(() => [form.plan_id, form.received_date, form.received_local_time], () => {
    validOffsets.value = [];
    form.received_utc_offset = '';
    timeNotice.value = '';
});

watch(() => [form.plan_id, form.received_date, form.received_local_time, form.received_utc_offset,
    form.savings_ngn, form.late_reason, form.notes,
    ...form.fees.map((fee) => fee.amount_ngn), JSON.stringify(form.allocations)], () => {
    preview.value = null;
    form.preview_fingerprint = '';
    form.confirmed = false;
});

async function resolveTime(): Promise<boolean> {
    if (!crossZone.value) return true;
    if (!form.received_local_time) {
        timeNotice.value = 'Enter the actual business-local time when this cash was received.';
        return false;
    }
    try {
        const result = await timeOptionsHttp.get(timeOptions.url(props.customer.id, { query: {
            plan_id: form.plan_id, received_date: form.received_date, received_local_time: form.received_local_time,
        } })) as { options: Array<{ offset: string; received_at_utc: string }> };
        validOffsets.value = result.options;
        if (result.options.length === 0) {
            form.received_utc_offset = '';
            timeNotice.value = 'This local time did not occur in the business timezone. Choose another time.';
            return false;
        }
        if (!result.options.some((option) => option.offset === form.received_utc_offset)) {
            form.received_utc_offset = result.options.length === 1 ? result.options[0]!.offset : '';
        }
        timeNotice.value = result.options.length > 1 && !form.received_utc_offset
            ? 'This local time occurred twice. Choose the offset that matches when the cash was received.' : '';
        return form.received_utc_offset !== '';
    } catch {
        timeNotice.value = 'Valid offsets could not be checked. Try again before reviewing this receipt.';
        return false;
    }
}

async function review(): Promise<void> {
    if (!await resolveTime()) return;
    previewHttp.plan_id = form.plan_id;
    previewHttp.received_date = form.received_date;
    previewHttp.received_local_time = crossZone.value ? form.received_local_time : '';
    previewHttp.received_utc_offset = crossZone.value ? form.received_utc_offset : '';
    previewHttp.savings_ngn = form.savings_ngn || '0';
    previewHttp.fees = form.fees.filter((fee) => fee.amount_ngn !== '' && fee.amount_ngn !== '0');
    previewHttp.allocations = form.allocations.filter((item) => item.amount_ngn !== '' && item.amount_ngn !== '0');
    previewHttp.late_reason = form.late_reason;
    previewHttp.notes = form.notes;
    try {
        const result = await previewHttp.post(previewCollection.url(props.customer.id)) as Preview;
        preview.value = result;
        form.preview_fingerprint = result.preview_fingerprint;
        form.customer_version = result.customer_version;
        form.assignment_version = result.assignment_version;
        form.business_version = result.business_version;
        form.plan_version = result.plan_version;
    } catch {
        preview.value = null;
    }
}

function customizeAllocation(): void {
    if (!preview.value) return;
    customSlots.value = preview.value.slot_options;
    form.allocations = preview.value.slot_options.map((slot) => ({
        slot_id: slot.slot_id,
        amount_ngn: (() => {
            const amount = preview.value?.allocations.find((item) => item.slot_id === slot.slot_id)?.amount_kobo ?? 0;
            return amount > 0 ? (amount / 100).toFixed(2) : '';
        })(),
    }));
}

function submit(retryOriginal = false): void {
    if (!preview.value || !form.confirmed || submissionPending.value || (outcomeUnknown.value && !retryOriginal)) return;
    submissionPending.value = true;
    lookupNotice.value = '';
    retryAllowed.value = false;
    rememberAttempt();
    form.transform((data) => ({
        ...data, savings_ngn: data.savings_ngn || '0',
        fees: data.fees.filter((fee) => fee.amount_ngn !== '' && fee.amount_ngn !== '0'),
        allocations: data.allocations.filter((item) => item.amount_ngn !== '' && item.amount_ngn !== '0'),
    })).post(storeCollection.url(props.customer.id), {
        onError: () => { preview.value = null; outcomeUnknown.value = false; forgetAttempt(); },
        onSuccess: () => { outcomeUnknown.value = false; forgetAttempt(); },
        onNetworkError: () => { outcomeUnknown.value = true; },
        onHttpException: () => { outcomeUnknown.value = true; return false; },
        onFinish: () => { submissionPending.value = false; },
    });
}

async function lookupAttempt(): Promise<void> {
    retryAllowed.value = false;
    try {
        const result = await attemptLookup.get(showAttempt.url(form.attempt_reference, { query: { customer: props.customer.id } })) as { status: string; receipt_reference: string };
        recoveredReceipt.value = result.status === 'posted' ? result.receipt_reference : null;
        lookupNotice.value = recoveredReceipt.value ? 'The original receipt was posted.' : 'The lookup did not confirm a result. Check again before retrying.';
    } catch (error) {
        recoveredReceipt.value = null;
        if (error instanceof HttpResponseError && error.response.status === 404) {
            try {
                retryAllowed.value = JSON.parse(error.response.data)?.status === 'unresolved';
            } catch {
                retryAllowed.value = false;
            }
        }
        lookupNotice.value = retryAllowed.value
            ? 'No posted receipt was found for this reference. You can retry the original details.'
            : 'The lookup could not confirm the result. Keep this reference and check again.';
    }
}
</script>

<template>
    <Head title="Record cash collection" />
    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">Record cash collection</h1>
            <p class="text-muted-foreground mt-1.5 text-sm">Confirm money actually received from {{ customer.name }}.</p>
        </div>
        <p v-if="submissionPending" role="status" aria-live="polite">Recording cash. Wait for the result before trying again.</p>
        <Alert v-if="outcomeUnknown">
            <AlertTitle>Submission outcome unknown</AlertTitle>
            <AlertDescription class="grid gap-2">Keep this attempt open and look up its original reference before trying again: {{ form.attempt_reference }}.<Button type="button" variant="outline" class="w-fit" :disabled="attemptLookup.processing" @click="lookupAttempt">Check original attempt</Button><p v-if="lookupNotice" role="status" aria-live="polite">{{ lookupNotice }}</p><Link v-if="recoveredReceipt" :href="showReceipt(recoveredReceipt)" class="underline" @click="forgetAttempt">Open posted receipt {{ recoveredReceipt }}</Link><Button v-else-if="retryAllowed && preview" type="button" variant="outline" class="w-fit" :disabled="submissionPending || form.processing || !form.confirmed" @click="submit(true)">Retry original attempt</Button><span v-else-if="retryAllowed">Re-enter the cash details below and review them before retrying this reference.</span></AlertDescription>
        </Alert>
        <Card>
            <CardHeader><CardTitle>Receipt details</CardTitle></CardHeader>
            <CardContent><fieldset :disabled="submissionPending || (outcomeUnknown && !(retryAllowed && !preview)) || form.processing" class="grid gap-5 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="collection-plan">Plan</Label>
                    <select id="collection-plan" v-model="form.plan_id" class="h-11 rounded-md border border-input bg-background px-3 text-sm">
                        <option value="">Fee payment only</option>
                        <option v-for="plan in plans" :key="plan.id" :value="plan.id">{{ plan.name }} · {{ plan.id }}</option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label for="collection-date">Date money was received</Label>
                    <DatePicker id="collection-date" v-model="form.received_date" />
                </div>
                <div v-if="crossZone" class="grid gap-2 sm:col-span-2">
                    <Label for="collection-time">Actual time received in {{ business_timezone }}</Label>
                    <Input id="collection-time" v-model="form.received_local_time" type="time" />
                    <p class="text-muted-foreground text-sm">This plan uses {{ plans.find((plan) => plan.id === form.plan_id)?.timezone }}. The time determines its slot date.</p>
                    <p v-if="timeNotice" role="alert" class="text-destructive text-sm">{{ timeNotice }}</p>
                    <div v-if="validOffsets.length > 1" class="grid gap-2">
                        <Label for="collection-offset">UTC offset at the time received</Label>
                        <select id="collection-offset" v-model="form.received_utc_offset" class="h-11 rounded-md border border-input bg-background px-3 text-sm">
                            <option value="">Choose the correct occurrence</option>
                            <option v-for="option in validOffsets" :key="option.received_at_utc" :value="option.offset">{{ option.offset }} · {{ option.received_at_utc }} UTC</option>
                        </select>
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="collection-savings">Savings received (NGN)</Label>
                    <Input id="collection-savings" v-model="form.savings_ngn" inputmode="decimal" placeholder="2000.00" />
                </div>
                <div v-for="(fee, index) in fee_obligations" :key="fee.id" class="grid gap-2">
                    <Label :for="`collection-fee-${fee.id}`">{{ fee.description }} · due {{ money(fee.outstanding_kobo) }}</Label>
                    <Input :id="`collection-fee-${fee.id}`" v-model="form.fees[index]!.amount_ngn" inputmode="decimal" placeholder="0.00" />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="collection-late">Late recording reason, if received before today</Label>
                    <Input id="collection-late" v-model="form.late_reason" maxlength="500" />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="collection-notes">Customer-visible note</Label>
                    <Input id="collection-notes" v-model="form.notes" maxlength="500" />
                </div>
                <div class="sm:col-span-2">
                    <Button type="button" :disabled="previewHttp.processing" @click="review">Review allocation and tender</Button>
                    <p v-for="(error, key) in previewHttp.errors" :key="key" role="alert" class="text-destructive mt-2 text-sm">{{ error }}</p>
                </div>
            </fieldset></CardContent>
        </Card>
        <div v-if="Object.keys(form.errors).length" role="alert" class="text-destructive grid gap-1 text-sm">
            <p v-for="(error, key) in form.errors" :key="key">{{ error }}</p>
            <p>Review the current details before recording cash again.</p>
        </div>
        <Card v-if="preview">
            <CardHeader><CardTitle>Confirm cash received</CardTitle></CardHeader>
            <CardContent class="flex flex-col gap-4">
                <p role="status" aria-live="polite" class="text-sm">Preview ready. Savings {{ money(preview.savings_kobo) }} · fees {{ money(preview.fees_kobo) }} · total cash {{ money(preview.tender_kobo) }}</p>
                <p class="text-muted-foreground text-sm">Received date uses {{ preview.timezone }}. This records Customer savings and money held by the Agent.</p>
                <p v-if="preview.received_at_utc" class="text-muted-foreground text-sm">Received at {{ form.received_local_time }} {{ preview.timezone }} ({{ form.received_utc_offset }}; {{ preview.received_at_utc }} UTC). In the plan’s {{ preview.plan_timezone }} timezone, the date is {{ preview.plan_received_date }}.</p>
                <ul class="grid gap-2 text-sm">
                    <li v-for="slot in preview.allocations" :key="slot.slot_id">{{ slot.due_date }} · {{ money(slot.amount_kobo) }}</li>
                </ul>
                <Button v-if="preview.slot_options.length > 1 && form.allocations.length === 0" type="button" variant="outline" class="w-fit" :disabled="submissionPending || outcomeUnknown || form.processing" @click="customizeAllocation">Change slot allocation</Button>
                <label class="flex items-start gap-3 text-sm"><input v-model="form.confirmed" type="checkbox" :disabled="submissionPending || (outcomeUnknown && !retryAllowed) || form.processing" /> I confirm that this cash was received and the split above is correct.</label>
                <div class="flex gap-3"><Button type="button" :disabled="submissionPending || form.processing || outcomeUnknown || !form.confirmed" @click="submit()">Record receipt</Button><Link v-if="!submissionPending && !outcomeUnknown" :href="collectionsIndex()" class="text-muted-foreground self-center text-sm underline">Cancel</Link></div>
            </CardContent>
        </Card>
        <Card v-if="form.allocations.length"><CardHeader><CardTitle>Custom slot allocation</CardTitle></CardHeader><CardContent class="grid gap-3"><p class="text-muted-foreground text-sm">Enter an amount for each chosen slot. Leave unused slots empty, then review again.</p><div v-for="item in form.allocations" :key="item.slot_id" class="grid max-w-xs gap-2"><Label :for="`slot-${item.slot_id}`">{{ customSlots.find((slot) => slot.slot_id === item.slot_id)?.due_date }} · up to {{ money(customSlots.find((slot) => slot.slot_id === item.slot_id)?.capacity_kobo ?? 0) }}</Label><Input :id="`slot-${item.slot_id}`" v-model="item.amount_ngn" inputmode="decimal" :disabled="submissionPending || outcomeUnknown || form.processing" placeholder="0.00" /></div><Button type="button" class="w-fit" :disabled="previewHttp.processing || submissionPending || outcomeUnknown" @click="review">Review custom allocation</Button></CardContent></Card>
    </div>
</template>
