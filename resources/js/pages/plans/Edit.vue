<script setup lang="ts">
import PlanEstimateSummary from '@/components/PlanEstimateSummary.vue';
import type { PlanEstimate } from '@/types/plan-estimate';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import { AlertCircle, CheckCircle2 } from '@lucide/vue';
import type { AcceptableValue } from 'reka-ui';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
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
import {
    edit as editPlan,
    index as plansIndex,
    show as showPlan,
    update as updatePlan,
} from '@/routes/plans';

type FeeOption = {
    id: number;
    version: number;
    name: string;
    formatted_amount: string;
    model: string;
    timing: string;
    customer_description: string;
};

type RevisionPreview = {
    estimate: PlanEstimate | null;
    preview_fingerprint: string;
    plan_version: number;
    terms_revision: number;
    customer_version: number;
    assignment_version: number;
    business_version: number;
    terms: {
        name: string;
        contribution_amount_kobo: number;
        formatted_contribution_amount: string;
        contribution_days: number;
        start_date: string;
        scheduled_end_date: string;
        formatted_expected_gross: string;
        customer_visible_notes: string | null;
    };
    fee: {
        rule_id: number;
        rule_version: number;
        name: string;
        formatted_amount: string;
        estimate_available: boolean;
        customer_description: string;
        early_termination_policy_version: number | null;
        early_termination_description: string | null;
    };
    financial_terms_changed: boolean;
    financial_terms_locked: boolean;
    slots: Array<{
        ordinal: number;
        due_date: string;
        formatted_amount: string;
    }>;
};

const props = defineProps<{
    plan: {
        id: string;
        status_label: string;
        version: number;
        terms_revision: number;
        current_terms: { name: string };
    };
    customer: {
        id: string;
        name: string;
        version: number;
        assignment_version: number | null;
    };
    business: { version: number };
    form: {
        name: string;
        amount_ngn: string;
        start_date: string;
        contribution_days: number;
        customer_visible_notes: string;
        fee_rule_id: number;
        fee_rule_version: number;
        reason: string;
        customer_explanation: string;
    };
    preview: RevisionPreview | null;
    fee_options: FeeOption[];
    attempt_reference: string;
    financial_terms_locked: boolean;
}>();

const form = useForm({
    ...props.form,
    attempt_reference: props.attempt_reference,
    preview_fingerprint: '',
    plan_version: props.plan.version,
    terms_revision: props.plan.terms_revision,
    customer_version: props.customer.version,
    assignment_version: props.customer.assignment_version ?? 0,
    business_version: props.business.version,
    customer_agreement_attested: false,
});

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Plans', href: plansIndex() },
            { title: 'Plan', href: plansIndex() },
            { title: 'Amend terms', href: '#' },
        ],
    },
});

const amountKobo = (): number | null => {
    const match = form.amount_ngn
        .trim()
        .match(/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?$/);
    if (!match) return null;
    return Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'));
};

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
                'reason',
                'customer_explanation',
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
    if (typeof value === 'string') form.fee_rule_id = Number(value);
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
    if (form.reason.trim() !== '') query.reason = form.reason;
    if (form.customer_explanation.trim() !== '')
        query.customer_explanation = form.customer_explanation;

    router.get(
        editPlan(props.plan.id, { query }).url,
        {},
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: (currentPage) => {
                const current = currentPage.props.plan as
                    | { id?: string }
                    | undefined;
                if (
                    currentPage.component !== 'plans/Edit' ||
                    current?.id !== props.plan.id ||
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
        plan_version: props.preview?.plan_version ?? 0,
        terms_revision: props.preview?.terms_revision ?? 0,
        customer_version: props.preview?.customer_version ?? 0,
        assignment_version: props.preview?.assignment_version ?? 0,
        business_version: props.preview?.business_version ?? 0,
        fee_rule_version: props.preview?.fee.rule_version ?? 0,
    })).patch(updatePlan(props.plan.id).url, { preserveScroll: true });
};
</script>

<template>
    <Head :title="`Amend ${plan.id}`" />

    <div class="mx-auto w-full max-w-5xl space-y-6">
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">
                Amend thrift plan
            </h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                {{ plan.id }} · {{ customer.name }} · revision
                {{ plan.terms_revision }}
            </p>
        </div>

        <Alert v-if="financial_terms_locked">
            <AlertCircle class="size-4" />
            <AlertTitle>Financial and schedule terms are locked</AlertTitle>
            <AlertDescription
                >Activity has started. You can correct the plan name and
                Customer-visible notes; contribution amount, dates, duration,
                and fee terms cannot change.</AlertDescription
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
                <CardTitle>Proposed revision</CardTitle>
                <CardDescription
                    >Prior revisions and schedule slot identities remain in the
                    plan history.</CardDescription
                >
            </CardHeader>
            <form @submit.prevent="submit">
                <CardContent class="grid gap-5 sm:grid-cols-2">
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="edit-plan-name">Plan name</Label>
                        <Input
                            id="edit-plan-name"
                            :disabled="busy"
                            v-model="form.name"
                            maxlength="100"
                        />
                        <p
                            v-if="form.errors.name"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.name }}
                        </p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="edit-plan-amount"
                            >Daily contribution amount (NGN)</Label
                        >
                        <Input
                            id="edit-plan-amount"
                            v-model="form.amount_ngn"
                            inputmode="decimal"
                            :disabled="busy || financial_terms_locked"
                        />
                        <p
                            v-if="form.errors.amount_ngn"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.amount_ngn }}
                        </p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="edit-plan-date">Start date</Label>
                        <DatePicker
                            id="edit-plan-date"
                            aria-label="Start date"
                            v-model="form.start_date"
                            :error-message="form.errors.start_date"
                            :disabled="busy || financial_terms_locked"
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
                        <Label for="edit-plan-days">Contribution days</Label>
                        <Input
                            id="edit-plan-days"
                            v-model.number="form.contribution_days"
                            type="number"
                            min="1"
                            max="366"
                            :disabled="busy || financial_terms_locked"
                        />
                        <p
                            v-if="form.errors.contribution_days"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.contribution_days }}
                        </p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="edit-plan-fee">Fee option</Label>
                        <Select
                            :model-value="String(form.fee_rule_id)"
                            :disabled="busy || financial_terms_locked"
                            @update:model-value="setFeeRule"
                        >
                            <SelectTrigger id="edit-plan-fee" class="w-full"
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
                        <Label for="edit-plan-notes"
                            >Customer-visible notes
                            <span class="text-muted-foreground font-normal"
                                >(optional)</span
                            ></Label
                        >
                        <textarea
                            id="edit-plan-notes"
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
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="edit-plan-reason"
                            >Internal reason for revision</Label
                        >
                        <textarea
                            id="edit-plan-reason"
                            :disabled="busy"
                            v-model="form.reason"
                            rows="2"
                            maxlength="500"
                            class="border-input bg-background focus-visible:ring-ring/30 min-h-20 w-full rounded-xl border px-3 py-2 text-sm shadow-sm outline-none focus-visible:ring-2"
                        />
                        <p
                            v-if="form.errors.reason"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.reason }}
                        </p>
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="edit-plan-explanation"
                            >Explanation for Customer</Label
                        >
                        <textarea
                            id="edit-plan-explanation"
                            :disabled="busy"
                            v-model="form.customer_explanation"
                            rows="2"
                            maxlength="500"
                            class="border-input bg-background focus-visible:ring-ring/30 min-h-20 w-full rounded-xl border px-3 py-2 text-sm shadow-sm outline-none focus-visible:ring-2"
                        />
                        <p
                            v-if="form.errors.customer_explanation"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.customer_explanation }}
                        </p>
                    </div>
                </CardContent>
                <CardFooter
                    class="flex flex-wrap justify-between gap-3 border-t pt-5"
                >
                    <Button v-if="!busy" as-child variant="outline"
                        ><Link :href="showPlan(plan.id).url"
                            >Back to plan</Link
                        ></Button
                    >
                    <Button
                        type="button"
                        variant="secondary"
                        :disabled="busy"
                        @click="requestPreview"
                        >{{
                            previewBusy
                                ? 'Building revision preview…'
                                : 'Build revision preview'
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
                        <CardTitle>Revision preview</CardTitle
                        ><CardDescription
                            >Review the proposed schedule and fee terms before
                            confirming with the Customer.</CardDescription
                        >
                    </div>
                </div>
            </CardHeader>
            <CardContent class="space-y-5">
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
                            Timezone stays with this plan
                        </p>
                    </div>
                </div>
                <div class="space-y-3 rounded-xl border p-4">
                    <h2 class="font-medium">Contractual estimates</h2>
                    <PlanEstimateSummary :estimate="preview.estimate" />
                </div>

                <div class="rounded-xl border p-4">
                    <h2 class="font-medium">Fee terms</h2>
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
                    <p class="text-muted-foreground mt-2 text-xs">
                        {{
                            preview.fee.estimate_available
                                ? 'Any assessment waits for the financial workflow that owns it.'
                                : 'Calculated when a withdrawal is quoted.'
                        }}
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
                <Alert
                    ><AlertCircle class="size-4" /><AlertTitle
                        >Actual collections and balances remain
                        unavailable</AlertTitle
                    ><AlertDescription
                        >This revision changes agreed terms only. It will not
                        add, remove, or recalculate financial
                        activity.</AlertDescription
                    ></Alert
                >
                <div class="flex items-start gap-3 rounded-xl border p-4">
                    <Checkbox
                        id="revision-agreement"
                        v-model:checked="form.customer_agreement_attested"
                        :disabled="busy || !previewIsCurrent"
                    />
                    <div class="grid gap-1">
                        <Label for="revision-agreement" class="leading-5"
                            >I confirmed this revised agreement with the
                            Customer.</Label
                        >
                        <p class="text-muted-foreground text-xs">
                            The assigned Agent records the Customer’s agreement.
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
                    v-if="form.errors.preview_fingerprint"
                    class="text-destructive text-sm"
                >
                    {{ form.errors.preview_fingerprint }}
                </p>
                <p
                    v-if="!previewIsCurrent"
                    class="text-sm text-amber-700 dark:text-amber-400"
                >
                    Terms changed after this preview. Build a fresh preview
                    before confirming.
                </p>
            </CardContent>
            <CardFooter class="flex justify-end border-t pt-5">
                <Button
                    :disabled="
                        busy ||
                        !previewIsCurrent ||
                        !form.customer_agreement_attested ||
                        form.reason.trim().length < 3 ||
                        form.customer_explanation.trim().length < 3
                    "
                    @click="submit"
                >
                    {{
                        form.processing
                            ? 'Saving revision…'
                            : 'Confirm revision'
                    }}
                </Button>
            </CardFooter>
        </Card>
    </div>
</template>
