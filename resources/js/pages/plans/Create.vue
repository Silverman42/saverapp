<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { AlertCircle, CheckCircle2, FileCheck2 } from '@lucide/vue';
import type { AcceptableValue } from 'reka-ui';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Checkbox } from '@/components/ui/checkbox';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { DatePicker } from '@/components/ui/date-picker';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { dashboard } from '@/routes';
import { show as showCustomer } from '@/routes/customers';
import { create as createCustomerPlan, store as storeCustomerPlan } from '@/routes/customers/plans';
import { index as plansIndex } from '@/routes/plans';

type FeeOption = {
    id: number;
    version: number;
    name: string;
    formatted_amount: string;
    model: string;
    timing: string;
    customer_description: string;
};

type PlanPreview = {
    available: boolean;
    preview_fingerprint: string;
    customer: { id: string; name: string; status: string; version: number; assignment_version: number };
    business: { timezone: string; version: number };
    terms: {
        name: string;
        currency: string;
        contribution_amount_kobo: number;
        formatted_contribution_amount: string;
        contribution_days: number;
        start_date: string;
        scheduled_end_date: string;
        expected_gross_kobo: number;
        formatted_expected_gross: string;
        customer_visible_notes: string | null;
    };
    fee: {
        rule_id: number;
        rule_version: number;
        name: string;
        model: string;
        timing: string;
        basis: string;
        amount_kobo: number;
        estimate_available: boolean;
        formatted_amount: string;
        customer_description: string;
    };
    slots: Array<{ ordinal: number; due_date: string; formatted_amount: string }>;
};

const props = defineProps<{
    customer: { id: string; name: string; status: string; version: number; assignment_version: number | null };
    business: { timezone: string; version: number };
    fee_options: FeeOption[];
    form: {
        name: string;
        amount_ngn: string;
        start_date: string;
        contribution_days: string;
        customer_visible_notes: string;
        fee_rule_id: string;
    };
    preview: PlanPreview | null;
    attempt_reference: string;
    predecessor_plan_id: string | null;
}>();

const form = useForm({
    ...props.form,
    attempt_reference: props.attempt_reference,
    preview_fingerprint: '',
    fee_rule_version: 0,
    customer_version: props.customer.version,
    assignment_version: props.customer.assignment_version ?? 0,
    business_version: props.business.version,
    customer_agreement_attested: false,
    predecessor_plan_id: props.predecessor_plan_id ?? '',
});

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Plans', href: plansIndex() },
            { title: 'Create plan', href: '#' },
        ],
    },
});

const previewIsCurrent = computed(() => {
    const preview = props.preview;
    return preview !== null
        && preview.terms.name === form.name.trim()
        && preview.terms.start_date === form.start_date
        && preview.terms.contribution_days === Number(form.contribution_days)
        && preview.terms.customer_visible_notes === (form.customer_visible_notes.trim() || null)
        && String(preview.fee.rule_id) === String(form.fee_rule_id)
        && preview.terms.contribution_amount_kobo === amountKobo();
});

const preview = computed(() => props.preview);
const customerError = computed(() => (form.errors as Record<string, string | undefined>).customer);

const amountKobo = (): number | null => {
    const match = form.amount_ngn.trim().match(/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?$/);
    if (!match) return null;
    return Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'));
};

watch(
    () => [form.name, form.amount_ngn, form.start_date, form.contribution_days, form.customer_visible_notes, form.fee_rule_id],
    () => {
        form.customer_agreement_attested = false;
    },
);

const setFeeRule = (value: AcceptableValue): void => {
    if (typeof value === 'string') form.fee_rule_id = value;
};

const requestPreview = (): void => {
    const query: Record<string, string | number> = {
        preview: 1,
        name: form.name,
        amount_ngn: form.amount_ngn,
        start_date: form.start_date,
        contribution_days: form.contribution_days,
        fee_rule_id: form.fee_rule_id,
    };
    if (form.customer_visible_notes.trim() !== '') query.customer_visible_notes = form.customer_visible_notes;
    if (props.predecessor_plan_id) query.predecessor_plan_id = props.predecessor_plan_id;

    router.get(createCustomerPlan.url(props.customer.id, { query }), {}, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const submit = (): void => {
    if (!previewIsCurrent.value || !props.preview || !form.customer_agreement_attested) return;

    form.transform((data) => ({
        ...data,
        attempt_reference: props.attempt_reference,
        preview_fingerprint: props.preview?.preview_fingerprint ?? '',
        fee_rule_version: props.preview?.fee.rule_version ?? 0,
        customer_version: props.preview?.customer.version ?? 0,
        assignment_version: props.preview?.customer.assignment_version ?? 0,
        business_version: props.preview?.business.version ?? 0,
        predecessor_plan_id: props.predecessor_plan_id ?? '',
    })).post(storeCustomerPlan(props.customer.id).url, { preserveScroll: true });
};
</script>

<template>
    <Head :title="`Create thrift plan for ${customer.name}`" />

    <div class="mx-auto w-full max-w-5xl space-y-6">
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">Create daily thrift plan</h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                {{ customer.name }} · {{ customer.id }} · schedule dates use {{ business.timezone }}.
            </p>
        </div>

        <Alert v-if="customer.status !== 'active'" variant="destructive">
            <AlertCircle class="size-4" />
            <AlertTitle>Customer is {{ customer.status }}</AlertTitle>
            <AlertDescription>New plans can only be created for Active Customers.</AlertDescription>
        </Alert>

        <Alert v-if="fee_options.length === 0" variant="destructive">
            <AlertCircle class="size-4" />
            <AlertTitle>No current plan fee option</AlertTitle>
            <AlertDescription>Plan creation is unavailable until a current fee option is published.</AlertDescription>
        </Alert>

        <Card>
            <CardHeader>
                <CardTitle>Agreed terms</CardTitle>
                <CardDescription>Enter the terms discussed with the Customer, then build a server-side preview before confirming.</CardDescription>
            </CardHeader>
            <form @submit.prevent="submit">
                <CardContent class="grid gap-5 sm:grid-cols-2">
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="plan-name">Plan name</Label>
                        <Input id="plan-name" v-model="form.name" maxlength="100" autocomplete="off" />
                        <p v-if="form.errors.name" class="text-destructive text-sm">{{ form.errors.name }}</p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="plan-amount">Daily contribution amount (NGN)</Label>
                        <Input id="plan-amount" v-model="form.amount_ngn" inputmode="decimal" placeholder="e.g. 500.00" />
                        <p v-if="form.errors.amount_ngn" class="text-destructive text-sm">{{ form.errors.amount_ngn }}</p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="plan-start-date">Start date</Label>
                        <DatePicker id="plan-start-date" v-model="form.start_date" />
                        <p v-if="form.errors.start_date" class="text-destructive text-sm">{{ form.errors.start_date }}</p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="plan-days">Daily contribution days</Label>
                        <Input id="plan-days" v-model="form.contribution_days" type="number" min="1" max="366" />
                        <p v-if="form.errors.contribution_days" class="text-destructive text-sm">{{ form.errors.contribution_days }}</p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="plan-fee-rule">Fee option</Label>
                        <Select :model-value="String(form.fee_rule_id)" @update:model-value="setFeeRule">
                            <SelectTrigger id="plan-fee-rule" class="w-full"><SelectValue placeholder="Choose fee option" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="option in fee_options" :key="option.id" :value="String(option.id)">{{ option.name }} · {{ option.formatted_amount }}</SelectItem>
                            </SelectContent>
                        </Select>
                        <p v-if="form.errors.fee_rule_id" class="text-destructive text-sm">{{ form.errors.fee_rule_id }}</p>
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="plan-notes">Customer-visible notes <span class="text-muted-foreground font-normal">(optional)</span></Label>
                        <textarea id="plan-notes" v-model="form.customer_visible_notes" rows="3" maxlength="2000" class="min-h-24 w-full rounded-xl border border-input bg-background px-3 py-2 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring/30" />
                        <p v-if="form.errors.customer_visible_notes" class="text-destructive text-sm">{{ form.errors.customer_visible_notes }}</p>
                    </div>
                </CardContent>
                <CardFooter class="flex flex-wrap justify-between gap-3 border-t pt-5">
                    <Button as-child variant="outline"><Link :href="showCustomer(customer.id).url">Back to Customer</Link></Button>
                    <Button type="button" variant="secondary" :disabled="fee_options.length === 0" @click="requestPreview">Build agreement preview</Button>
                </CardFooter>
            </form>
        </Card>

        <Card v-if="preview">
            <CardHeader>
                <div class="flex items-start gap-3">
                    <CheckCircle2 class="text-primary mt-0.5 size-5 shrink-0" />
                    <div>
                        <CardTitle>Agreement preview</CardTitle>
                        <CardDescription>Confirm these terms with the Customer. This preview does not record collections or a savings balance.</CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent class="space-y-5">
                <div class="rounded-xl border p-4">
                    <p class="text-muted-foreground text-xs">Plan and Customer</p>
                    <p class="mt-1 font-semibold">{{ preview.terms.name }} · {{ preview.customer.name }}</p>
                    <p class="text-muted-foreground mt-1 text-sm">Daily contributions in {{ preview.terms.currency }}, from {{ preview.terms.start_date }} through {{ preview.terms.scheduled_end_date }} ({{ preview.business.timezone }}).</p>
                    <p class="mt-2 text-sm">Customer-visible notes: {{ preview.terms.customer_visible_notes || 'None' }}</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-xl border p-4"><p class="text-muted-foreground text-xs">Daily contribution</p><p class="mt-1 font-semibold">{{ preview.terms.formatted_contribution_amount }}</p></div>
                    <div class="rounded-xl border p-4"><p class="text-muted-foreground text-xs">Scheduled days</p><p class="mt-1 font-semibold">{{ preview.terms.contribution_days }}</p></div>
                    <div class="rounded-xl border p-4"><p class="text-muted-foreground text-xs">Expected gross</p><p class="mt-1 font-semibold">{{ preview.terms.formatted_expected_gross }}</p><p class="text-muted-foreground mt-1 text-xs">Contractual estimate only</p></div>
                    <div class="rounded-xl border p-4"><p class="text-muted-foreground text-xs">Schedule end</p><p class="mt-1 font-semibold">{{ preview.terms.scheduled_end_date }}</p><p class="text-muted-foreground mt-1 text-xs">{{ preview.business.timezone }}</p></div>
                </div>

                <div class="rounded-xl border p-4">
                    <div class="flex items-center gap-2"><FileCheck2 class="text-primary size-4" /><h2 class="font-medium">Fee terms</h2></div>
                    <p class="mt-2 text-sm font-medium">{{ preview.fee.name }} · {{ preview.fee.formatted_amount }}</p>
                    <p class="text-muted-foreground mt-1 text-sm">{{ preview.fee.customer_description }}</p>
                    <p class="text-muted-foreground mt-1 text-sm">Basis: {{ preview.fee.basis.replaceAll('_', ' ') }} · Timing: {{ preview.fee.timing.replaceAll('_', ' ') }}</p>
                    <p v-if="!preview.fee.estimate_available" class="text-muted-foreground mt-2 text-xs">This fee is calculated when a withdrawal is quoted; no amount is estimated here.</p>
                    <p v-else class="text-muted-foreground mt-2 text-xs">This is a contractual estimate. Any financial assessment waits for its owning workflow.</p>
                </div>

                <div>
                    <h2 class="font-medium">First scheduled dates</h2>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <Badge v-for="slot in preview.slots.slice(0, 7)" :key="slot.ordinal" variant="outline">{{ slot.due_date }}</Badge>
                        <Badge v-if="preview.slots.length > 7" variant="secondary">+ {{ preview.slots.length - 7 }} more days</Badge>
                    </div>
                </div>

                <Alert>
                    <AlertCircle class="size-4" />
                    <AlertTitle>Collections and savings progress are unavailable</AlertTitle>
                    <AlertDescription>Only the agreed terms and expected dates will be saved. This action will not record or estimate money received.</AlertDescription>
                </Alert>

                <div class="flex items-start gap-3 rounded-xl border p-4">
                    <Checkbox id="plan-agreement" v-model:checked="form.customer_agreement_attested" :disabled="!previewIsCurrent" />
                    <div class="grid gap-1">
                        <Label for="plan-agreement" class="leading-5">I confirmed these terms and the fee disclosure with the Customer.</Label>
                        <p class="text-muted-foreground text-xs">The Customer’s agreement is recorded by the assigned Agent.</p>
                    </div>
                </div>
                <p v-if="form.errors.customer_agreement_attested" class="text-destructive text-sm">{{ form.errors.customer_agreement_attested }}</p>
                <p v-if="!previewIsCurrent" class="text-amber-700 text-sm dark:text-amber-400">Terms changed after this preview. Build a fresh preview before confirming.</p>
                <p v-if="form.errors.preview_fingerprint" class="text-destructive text-sm">{{ form.errors.preview_fingerprint }}</p>
                <p v-if="customerError" class="text-destructive text-sm">{{ customerError }}</p>
                <p v-if="form.errors.predecessor_plan_id" class="text-destructive text-sm">{{ form.errors.predecessor_plan_id }}</p>
            </CardContent>
            <CardFooter class="flex justify-end border-t pt-5">
                <Button :disabled="form.processing || !previewIsCurrent || !form.customer_agreement_attested || fee_options.length === 0" @click="submit">
                    {{ form.processing ? 'Saving plan…' : 'Confirm and create plan' }}
                </Button>
            </CardFooter>
        </Card>
    </div>
</template>
