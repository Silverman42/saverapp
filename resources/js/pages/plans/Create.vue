<script setup lang="ts">
import PlanEstimateSummary from '@/components/PlanEstimateSummary.vue';
import type { PlanEstimate } from '@/types/plan-estimate';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
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
import {
    create as createCustomerPlan,
    store as storeCustomerPlan,
} from '@/routes/customers/plans';
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
    estimate: PlanEstimate | null;
    available: boolean;
    preview_fingerprint: string;
    customer: {
        id: string;
        name: string;
        status: string;
        version: number;
        assignment_version: number;
    };
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
        early_termination_policy_version: number | null;
        early_termination_description: string | null;
    };
    slots: Array<{
        ordinal: number;
        due_date: string;
        formatted_amount: string;
    }>;
};

const props = defineProps<{
    customer: {
        id: string;
        name: string;
        status: string;
        version: number;
        assignment_version: number | null;
    };
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

const previewBusy = ref(false);
const previewRequiresRefresh = ref(false);
const previewMessage = ref('');
const previewNotice = ref<HTMLElement | null>(null);
const busy = computed(() => previewBusy.value || form.processing);
const previewGeneralErrors = computed(() =>
    Object.entries(form.errors).filter(
        ([field]) =>
            ![
                'name',
                'amount_ngn',
                'start_date',
                'contribution_days',
                'customer_visible_notes',
                'fee_rule_id',
            ].includes(field),
    ),
);

const focusPreviewNotice = (): void => {
    if (previewMessage.value) void nextTick(() => previewNotice.value?.focus());
};

const previewIsCurrent = computed(() => {
    const preview = props.preview;
    return (
        !previewBusy.value &&
        !previewRequiresRefresh.value &&
        preview !== null &&
        preview.terms.name === form.name.trim() &&
        preview.terms.start_date === form.start_date &&
        preview.terms.contribution_days === Number(form.contribution_days) &&
        preview.terms.customer_visible_notes ===
            (form.customer_visible_notes.trim() || null) &&
        String(preview.fee.rule_id) === String(form.fee_rule_id) &&
        preview.terms.contribution_amount_kobo === amountKobo()
    );
});

const preview = computed(() => props.preview);
const customerError = computed(
    () => (form.errors as Record<string, string | undefined>).customer,
);

const amountKobo = (): number | null => {
    const match = form.amount_ngn
        .trim()
        .match(/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?$/);
    if (!match) return null;
    return Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'));
};

watch(
    () => [
        form.name,
        form.amount_ngn,
        form.start_date,
        form.contribution_days,
        form.customer_visible_notes,
        form.fee_rule_id,
    ],
    () => {
        form.customer_agreement_attested = false;
    },
);

const setFeeRule = (value: AcceptableValue): void => {
    if (busy.value) return;
    if (typeof value === 'string') form.fee_rule_id = value;
};

const requestPreview = (): void => {
    if (busy.value) return;
    previewBusy.value = true;
    previewRequiresRefresh.value = true;
    previewMessage.value = '';
    form.customer_agreement_attested = false;
    const query: Record<string, string | number> = {
        preview: 1,
        name: form.name,
        amount_ngn: form.amount_ngn,
        start_date: form.start_date,
        contribution_days: form.contribution_days,
        fee_rule_id: form.fee_rule_id,
    };
    if (form.customer_visible_notes.trim() !== '')
        query.customer_visible_notes = form.customer_visible_notes;
    if (props.predecessor_plan_id)
        query.predecessor_plan_id = props.predecessor_plan_id;

    router.get(
        createCustomerPlan.url(props.customer.id, { query }),
        {},
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: (currentPage) => {
                const current = currentPage.props.customer as
                    { id?: string } | undefined;
                if (
                    currentPage.component !== 'plans/Create' ||
                    current?.id !== props.customer.id ||
                    !currentPage.props.preview
                ) {
                    previewMessage.value =
                        'A current preview was not returned. Your draft is retained. Build a fresh preview before confirming.';
                    return;
                }
                previewRequiresRefresh.value = false;
                form.clearErrors();
            },
            onError: (errors) => {
                form.clearErrors().setError(errors);
                previewMessage.value =
                    'The preview could not be built. Review the validation errors and build a fresh preview before confirming.';
            },
            onHttpException: (response) => {
                previewMessage.value =
                    response.status === 403 || response.status === 404
                        ? 'The preview is unavailable or your access has changed. Reload to check current access before confirming.'
                        : 'The preview could not be verified. Your draft is retained. Build a fresh preview before confirming.';
                return false;
            },
            onNetworkError: () => {
                previewMessage.value =
                    'The preview could not be checked because the connection failed. Your draft is retained. Check your connection and build a fresh preview before confirming.';
                return false;
            },
            onCancel: () => {
                previewMessage.value =
                    'Preview checking was interrupted. Your draft is retained. Build a fresh preview before confirming.';
            },
            onFinish: () => {
                previewBusy.value = false;
                focusPreviewNotice();
            },
        },
    );
};

const submit = (): void => {
    if (
        busy.value ||
        !previewIsCurrent.value ||
        !props.preview ||
        !form.customer_agreement_attested
    )
        return;

    form.transform((data) => ({
        ...data,
        attempt_reference: props.attempt_reference,
        preview_fingerprint: props.preview?.preview_fingerprint ?? '',
        fee_rule_version: props.preview?.fee.rule_version ?? 0,
        customer_version: props.preview?.customer.version ?? 0,
        assignment_version: props.preview?.customer.assignment_version ?? 0,
        business_version: props.preview?.business.version ?? 0,
        predecessor_plan_id: props.predecessor_plan_id ?? '',
    })).post(storeCustomerPlan(props.customer.id).url, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="`Create thrift plan for ${customer.name}`" />

    <div class="mx-auto w-full max-w-5xl space-y-6">
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">
                Create daily thrift plan
            </h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                {{ customer.name }} · {{ customer.id }} · schedule dates use
                {{ business.timezone }}.
            </p>
        </div>

        <Alert v-if="customer.status !== 'active'" variant="destructive">
            <AlertCircle class="size-4" />
            <AlertTitle>Customer is {{ customer.status }}</AlertTitle>
            <AlertDescription
                >New plans can only be created for Active
                Customers.</AlertDescription
            >
        </Alert>

        <Alert v-if="fee_options.length === 0" variant="destructive">
            <AlertCircle class="size-4" />
            <AlertTitle>No current plan fee option</AlertTitle>
            <AlertDescription
                >Plan creation is unavailable until a current fee option is
                published.</AlertDescription
            >
        </Alert>

        <p v-if="previewBusy" role="status" aria-live="polite">
            Checking the current agreement and schedule. Wait for the preview
            before confirming.
        </p>
        <div
            v-if="previewMessage"
            ref="previewNotice"
            role="alert"
            tabindex="-1"
            aria-live="assertive"
            aria-atomic="true"
            class="rounded-lg border p-4 text-sm"
        >
            <p>{{ previewMessage }}</p>
            <ul v-if="previewGeneralErrors.length" class="mt-2 grid gap-1">
                <li v-for="[field, error] in previewGeneralErrors" :key="field">
                    {{ error }}
                </li>
            </ul>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Agreed terms</CardTitle>
                <CardDescription
                    >Enter the terms that you agreed with the Customer. Then
                    make a preview before you confirm.</CardDescription
                >
            </CardHeader>
            <form @submit.prevent="submit">
                <CardContent class="grid gap-5 sm:grid-cols-2">
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="plan-name">Plan name</Label>
                        <Input
                            id="plan-name"
                            :disabled="busy"
                            v-model="form.name"
                            maxlength="100"
                            autocomplete="off"
                        />
                        <p
                            v-if="form.errors.name"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.name }}
                        </p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="plan-amount"
                            >Daily contribution amount (NGN)</Label
                        >
                        <Input
                            id="plan-amount"
                            :disabled="busy"
                            v-model="form.amount_ngn"
                            inputmode="decimal"
                            placeholder="e.g. 500.00"
                        />
                        <p
                            v-if="form.errors.amount_ngn"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.amount_ngn }}
                        </p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="plan-start-date">Start date</Label>
                        <DatePicker
                            id="plan-start-date"
                            :disabled="busy"
                            aria-label="Start date"
                            v-model="form.start_date"
                            :error-message="form.errors.start_date"
                        />
                        <p
                            v-if="form.errors.start_date"
                            role="alert"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.start_date }}
                        </p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="plan-days">Daily contribution days</Label>
                        <Input
                            id="plan-days"
                            :disabled="busy"
                            v-model="form.contribution_days"
                            type="number"
                            min="1"
                            max="366"
                        />
                        <p
                            v-if="form.errors.contribution_days"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.contribution_days }}
                        </p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="plan-fee-rule">Fee option</Label>
                        <Select
                            :disabled="busy"
                            :model-value="String(form.fee_rule_id)"
                            @update:model-value="setFeeRule"
                        >
                            <SelectTrigger id="plan-fee-rule" class="w-full"
                                ><SelectValue placeholder="Choose fee option"
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in fee_options"
                                    :key="option.id"
                                    :value="String(option.id)"
                                    >{{ option.name }} ·
                                    {{ option.formatted_amount }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <p
                            v-if="form.errors.fee_rule_id"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.fee_rule_id }}
                        </p>
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="plan-notes"
                            >Customer-visible notes
                            <span class="text-muted-foreground font-normal"
                                >(optional)</span
                            ></Label
                        >
                        <textarea
                            id="plan-notes"
                            :disabled="busy"
                            v-model="form.customer_visible_notes"
                            rows="3"
                            maxlength="2000"
                            class="border-input bg-background focus-visible:ring-ring/30 min-h-24 w-full rounded-xl border px-3 py-2 text-sm shadow-sm outline-none focus-visible:ring-2"
                        />
                        <p
                            v-if="form.errors.customer_visible_notes"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.customer_visible_notes }}
                        </p>
                    </div>
                </CardContent>
                <CardFooter
                    class="flex flex-wrap justify-between gap-3 border-t pt-5"
                >
                    <Button v-if="!busy" as-child variant="outline"
                        ><Link :href="showCustomer(customer.id).url"
                            >Back to Customer</Link
                        ></Button
                    >
                    <Button
                        type="button"
                        variant="secondary"
                        :disabled="busy || fee_options.length === 0"
                        @click="requestPreview"
                        >{{
                            previewBusy
                                ? 'Building agreement preview…'
                                : 'Build agreement preview'
                        }}</Button
                    >
                </CardFooter>
            </form>
        </Card>

        <Card v-if="preview && !previewBusy && !previewRequiresRefresh">
            <CardHeader>
                <div class="flex items-start gap-3">
                    <CheckCircle2 class="text-primary mt-0.5 size-5 shrink-0" />
                    <div>
                        <CardTitle>Agreement preview</CardTitle>
                        <CardDescription
                            >Confirm these terms with the Customer. This preview
                            does not record collections or a savings
                            balance.</CardDescription
                        >
                    </div>
                </div>
            </CardHeader>
            <CardContent class="space-y-5">
                <div class="rounded-xl border p-4">
                    <p class="text-muted-foreground text-xs">
                        Plan and Customer
                    </p>
                    <p class="mt-1 font-semibold">
                        {{ preview.terms.name }} · {{ preview.customer.name }}
                    </p>
                    <p class="text-muted-foreground mt-1 text-sm">
                        Daily contributions in {{ preview.terms.currency }},
                        from {{ preview.terms.start_date }} through
                        {{ preview.terms.scheduled_end_date }} ({{
                            preview.business.timezone
                        }}).
                    </p>
                    <p class="mt-2 text-sm">
                        Customer-visible notes:
                        {{ preview.terms.customer_visible_notes || 'None' }}
                    </p>
                </div>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="rounded-xl border p-4">
                        <p class="text-muted-foreground text-xs">
                            Daily contribution
                        </p>
                        <p class="mt-1 font-semibold">
                            {{ preview.terms.formatted_contribution_amount }}
                        </p>
                    </div>
                    <div class="rounded-xl border p-4">
                        <p class="text-muted-foreground text-xs">
                            Scheduled days
                        </p>
                        <p class="mt-1 font-semibold">
                            {{ preview.terms.contribution_days }}
                        </p>
                    </div>
                    <div class="rounded-xl border p-4">
                        <p class="text-muted-foreground text-xs">
                            Schedule end
                        </p>
                        <p class="mt-1 font-semibold">
                            {{ preview.terms.scheduled_end_date }}
                        </p>
                        <p class="text-muted-foreground mt-1 text-xs">
                            {{ preview.business.timezone }}
                        </p>
                    </div>
                </div>

                <div class="space-y-3 rounded-xl border p-4">
                    <h2 class="font-medium">Contractual estimates</h2>
                    <PlanEstimateSummary :estimate="preview.estimate" />
                </div>

                <div class="rounded-xl border p-4">
                    <div class="flex items-center gap-2">
                        <FileCheck2 class="text-primary size-4" />
                        <h2 class="font-medium">Fee terms</h2>
                    </div>
                    <p class="mt-2 text-sm font-medium">
                        {{ preview.fee.name }} ·
                        {{ preview.fee.formatted_amount }}
                    </p>
                    <p class="text-muted-foreground mt-1 text-sm">
                        {{ preview.fee.customer_description }}
                    </p>
                    <p
                        v-if="preview.fee.early_termination_description"
                        class="text-muted-foreground mt-2 text-sm"
                    >
                        Early termination:
                        {{ preview.fee.early_termination_description }}
                    </p>
                    <p class="text-muted-foreground mt-1 text-sm">
                        Basis: {{ preview.fee.basis.replaceAll('_', ' ') }} ·
                        Timing:
                        {{ preview.fee.timing.replaceAll('_', ' ') }}
                    </p>
                    <p
                        v-if="!preview.fee.estimate_available"
                        class="text-muted-foreground mt-2 text-xs"
                    >
                        We calculate this fee when a withdrawal is quoted. No
                        amount shows here.
                    </p>
                    <p v-else class="text-muted-foreground mt-2 text-xs">
                        This is an estimate from the agreement. The related
                        financial process calculates the actual fee.
                    </p>
                </div>

                <div>
                    <h2 class="font-medium">First scheduled dates</h2>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <Badge
                            v-for="slot in preview.slots.slice(0, 7)"
                            :key="slot.ordinal"
                            variant="outline"
                            >{{ slot.due_date }}</Badge
                        >
                        <Badge
                            v-if="preview.slots.length > 7"
                            variant="secondary"
                            >+ {{ preview.slots.length - 7 }} more days</Badge
                        >
                    </div>
                </div>

                <Alert>
                    <AlertCircle class="size-4" />
                    <AlertTitle
                        >Collections and savings progress are
                        unavailable</AlertTitle
                    >
                    <AlertDescription
                        >This saves only the agreed terms and expected dates. It
                        does not record or estimate money
                        received.</AlertDescription
                    >
                </Alert>

                <div class="flex items-start gap-3 rounded-xl border p-4">
                    <Checkbox
                        id="plan-agreement"
                        v-model="form.customer_agreement_attested"
                        :disabled="busy || !previewIsCurrent"
                    />
                    <div class="grid gap-1">
                        <Label for="plan-agreement" class="leading-5"
                            >I confirmed these terms and the fee disclosure with
                            the Customer.</Label
                        >
                        <p class="text-muted-foreground text-xs">
                            The Customer’s agreement is recorded by the assigned
                            Agent.
                        </p>
                    </div>
                </div>
                <p
                    v-if="form.errors.customer_agreement_attested"
                    class="text-destructive text-sm"
                >
                    {{ form.errors.customer_agreement_attested }}
                </p>
                <p
                    v-if="!previewIsCurrent"
                    class="text-sm text-amber-700 dark:text-amber-400"
                >
                    The terms changed after this preview. Make a new preview
                    before you confirm.
                </p>
                <p
                    v-if="form.errors.preview_fingerprint"
                    class="text-destructive text-sm"
                >
                    {{ form.errors.preview_fingerprint }}
                </p>
                <p v-if="customerError" class="text-destructive text-sm">
                    {{ customerError }}
                </p>
                <p
                    v-if="form.errors.predecessor_plan_id"
                    class="text-destructive text-sm"
                >
                    {{ form.errors.predecessor_plan_id }}
                </p>
            </CardContent>
            <CardFooter class="flex justify-end border-t pt-5">
                <Button
                    :disabled="
                        busy ||
                        !previewIsCurrent ||
                        !form.customer_agreement_attested ||
                        fee_options.length === 0
                    "
                    @click="submit"
                >
                    {{
                        form.processing
                            ? 'Saving plan…'
                            : 'Confirm and create plan'
                    }}
                </Button>
            </CardFooter>
        </Card>
    </div>
</template>
