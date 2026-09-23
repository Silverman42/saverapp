<script setup lang="ts">
import { Head, Link, useForm, useHttp } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
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
    allocations: Array<{ slot_id: number; due_date: string; amount_kobo: number }>;
    slot_options: Array<{ slot_id: number; due_date: string; capacity_kobo: number }>;
};

const props = defineProps<{
    customer: { id: string; name: string };
    plans: Array<{ id: string; name: string }>;
    fee_obligations: Array<{ id: number; description: string; outstanding_kobo: number }>;
    today: string;
}>();

defineOptions({
    layout: { breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Collections', href: collectionsIndex() },
        { title: 'Record cash', href: '#' },
    ] },
});

const form = useForm({
    attempt_reference: crypto.randomUUID(), preview_fingerprint: '',
    customer_version: 0, assignment_version: 0, business_version: 0,
    plan_id: props.plans[0]?.id ?? '', plan_version: null as number | null,
    received_date: props.today, savings_ngn: '',
    fees: props.fee_obligations.map((fee) => ({ obligation_id: fee.id, amount_ngn: '' })),
    allocations: [] as Array<{ slot_id: number; amount_ngn: string }>,
    late_reason: '', notes: '', confirmed: false,
});
const previewHttp = useHttp({
    plan_id: '', received_date: '', savings_ngn: '',
    fees: [] as Array<{ obligation_id: number; amount_ngn: string }>,
    allocations: [] as Array<{ slot_id: number; amount_ngn: string }>,
    late_reason: '', notes: '',
});
const preview = ref<Preview | null>(null);
const customSlots = ref<Array<{ slot_id: number; due_date: string; capacity_kobo: number }>>([]);
const outcomeUnknown = ref(false);
const attemptLookup = useHttp({});
const recoveredReceipt = ref<string | null>(null);
const money = (kobo: number): string => `₦${(kobo / 100).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

watch(() => [form.plan_id, form.received_date, form.savings_ngn, form.late_reason, form.notes,
    ...form.fees.map((fee) => fee.amount_ngn), JSON.stringify(form.allocations)], () => {
    preview.value = null;
    form.preview_fingerprint = '';
    form.confirmed = false;
});

async function review(): Promise<void> {
    previewHttp.plan_id = form.plan_id;
    previewHttp.received_date = form.received_date;
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

function submit(): void {
    if (!preview.value || !form.confirmed) return;
    form.transform((data) => ({
        ...data, savings_ngn: data.savings_ngn || '0',
        fees: data.fees.filter((fee) => fee.amount_ngn !== '' && fee.amount_ngn !== '0'),
        allocations: data.allocations.filter((item) => item.amount_ngn !== '' && item.amount_ngn !== '0'),
    })).post(storeCollection.url(props.customer.id), {
        onError: () => { preview.value = null; },
        onFinish: () => { if (!form.hasErrors && !form.wasSuccessful) outcomeUnknown.value = true; },
    });
}

async function lookupAttempt(): Promise<void> {
    try {
        const result = await attemptLookup.get(showAttempt.url(form.attempt_reference)) as { status: string; receipt_reference: string };
        recoveredReceipt.value = result.status === 'posted' ? result.receipt_reference : null;
    } catch {
        recoveredReceipt.value = null;
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
        <Alert v-if="outcomeUnknown">
            <AlertTitle>Submission outcome unknown</AlertTitle>
            <AlertDescription class="grid gap-2">Keep this attempt open and look up its original reference before trying again: {{ form.attempt_reference }}.<Button type="button" variant="outline" class="w-fit" :disabled="attemptLookup.processing" @click="lookupAttempt">Check original attempt</Button><Link v-if="recoveredReceipt" :href="showReceipt(recoveredReceipt)" class="underline">Open posted receipt {{ recoveredReceipt }}</Link><span v-else>If no receipt appears, keep this same attempt reference when retrying.</span></AlertDescription>
        </Alert>
        <Card>
            <CardHeader><CardTitle>Receipt details</CardTitle></CardHeader>
            <CardContent class="grid gap-5 sm:grid-cols-2">
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
                    <p v-for="(error, key) in previewHttp.errors" :key="key" class="text-destructive mt-2 text-sm">{{ error }}</p>
                </div>
            </CardContent>
        </Card>
        <Card v-if="preview">
            <CardHeader><CardTitle>Confirm cash received</CardTitle></CardHeader>
            <CardContent class="flex flex-col gap-4">
                <p class="text-sm">Savings {{ money(preview.savings_kobo) }} · fees {{ money(preview.fees_kobo) }} · total cash {{ money(preview.tender_kobo) }}</p>
                <p class="text-muted-foreground text-sm">Received date uses {{ preview.timezone }}. This records Customer savings and money held by the Agent.</p>
                <ul class="grid gap-2 text-sm">
                    <li v-for="slot in preview.allocations" :key="slot.slot_id">{{ slot.due_date }} · {{ money(slot.amount_kobo) }}</li>
                </ul>
                <Button v-if="preview.slot_options.length > 1 && form.allocations.length === 0" type="button" variant="outline" class="w-fit" @click="customizeAllocation">Change slot allocation</Button>
                <label class="flex items-start gap-3 text-sm"><input v-model="form.confirmed" type="checkbox" /> I confirm that this cash was received and the split above is correct.</label>
                <div class="flex gap-3"><Button type="button" :disabled="form.processing || !form.confirmed" @click="submit">Record receipt</Button><Link :href="collectionsIndex()" class="text-muted-foreground self-center text-sm underline">Cancel</Link></div>
                <p v-for="(error, key) in form.errors" :key="key" class="text-destructive text-sm">{{ error }}</p>
            </CardContent>
        </Card>
        <Card v-if="form.allocations.length"><CardHeader><CardTitle>Custom slot allocation</CardTitle></CardHeader><CardContent class="grid gap-3"><p class="text-muted-foreground text-sm">Enter an amount for each chosen slot. Leave unused slots empty, then review again.</p><div v-for="item in form.allocations" :key="item.slot_id" class="grid max-w-xs gap-2"><Label :for="`slot-${item.slot_id}`">{{ customSlots.find((slot) => slot.slot_id === item.slot_id)?.due_date }} · up to {{ money(customSlots.find((slot) => slot.slot_id === item.slot_id)?.capacity_kobo ?? 0) }}</Label><Input :id="`slot-${item.slot_id}`" v-model="item.amount_ngn" inputmode="decimal" placeholder="0.00" /></div><Button type="button" class="w-fit" :disabled="previewHttp.processing" @click="review">Review custom allocation</Button></CardContent></Card>
    </div>
</template>
