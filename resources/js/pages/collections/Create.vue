<script setup lang="ts">
import { Head, Link, useForm, useHttp } from '@inertiajs/vue3';
import { HttpResponseError } from '@inertiajs/core';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { newOperationReference } from '@/lib/operation-reference';
import CollectionEvidencePanel from '@/components/CollectionEvidencePanel.vue';
import InputError from '@/components/InputError.vue';
import MoreDetails from '@/components/MoreDetails.vue';
import PageHeader from '@/components/PageHeader.vue';
import type {
    CollectionMethod,
    PaymentEvidence,
} from '@/types/collection-evidence';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
    index as collectionsIndex,
    show as showReceipt,
} from '@/routes/collections';
import { show as showAttempt } from '@/routes/collections/attempts';
import {
    preview as previewCollection,
    store as storeCollection,
} from '@/routes/customers/collections';
import {
    preview as previewReplacement,
    store as storeReplacement,
} from '@/routes/reversals/replacement';
import { timeOptions } from '@/routes/customers/collections';

type Preview = {
    replacement_fingerprint?: string;
    preview_fingerprint: string;
    method_context: { method_label: string; custody_account_code: string };
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
    allocations: Array<{
        slot_id: number;
        due_date: string;
        amount_kobo: number;
    }>;
    slot_options: Array<{
        slot_id: number;
        due_date: string;
        capacity_kobo: number;
    }>;
};

const props = withDefaults(
    defineProps<{
        customer: {
            id: string;
            name: string;
            resource_id?: number;
            version?: number;
            assignment_version?: number | null;
        };
        collection_methods?: CollectionMethod[];
        cash_enabled?: boolean;
        initial_evidence?: string | null;
        plans: Array<{ id: string; name: string; timezone: string | null }>;
        fee_obligations: Array<{
            id: number;
            description: string;
            outstanding_kobo: number;
        }>;
        today: string;
        business_timezone: string;
        replacement_reversal?: string;
        replacement_controlled_kobo?: number;
    }>(),
    {
        collection_methods: () => [],
        cash_enabled: true,
        initial_evidence: null,
    },
);
const FEE_ONLY = '__fee_only';
const isLate = computed(() => form.received_date < props.today);
const paymentChoice = ref(
    props.initial_evidence || !props.cash_enabled ? 'evidence' : 'cash',
);
const selectedEvidence = ref<PaymentEvidence | null>(null);
const evidenceNotice = ref('');
const evidenceCustomer = computed(() => ({
    id: props.customer.id,
    resource_id: props.customer.resource_id ?? 0,
    version: props.customer.version ?? 0,
    assignment_version: props.customer.assignment_version ?? null,
}));
function selectEvidence(proof: PaymentEvidence | null): void {
    selectedEvidence.value = proof;
    form.method =
        proof?.method_key ??
        (paymentChoice.value === 'evidence'
            ? (props.collection_methods[0]?.method_key ?? 'transfer')
            : 'cash');
    form.collection_method_version_id =
        proof?.collection_method_version_id ?? null;
    form.evidence_reference = proof?.evidence_reference ?? null;
    evidenceNotice.value = '';
    if (proof) form.received_date = proof.received_date;
}
watch(paymentChoice, () => selectEvidence(null));
const crossZone = computed(
    () =>
        props.plans.find((plan) => plan.id === form.plan_id)?.timezone !==
            props.business_timezone && form.plan_id !== '',
);

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Collections', href: collectionsIndex() },
            { title: 'Record collection', href: '#' },
        ],
    },
});

const form = useForm({
    attempt_reference: newOperationReference(),
    method: 'cash' as 'cash' | 'transfer' | 'pos' | 'other',
    collection_method_version_id: null as number | null,
    evidence_reference: null as string | null,
    preview_fingerprint: '',
    replacement_fingerprint: '',
    customer_version: 0,
    assignment_version: 0,
    business_version: 0,
    plan_id: props.plans[0]?.id ?? '',
    plan_version: null as number | null,
    received_date: props.today,
    received_local_time: '',
    received_utc_offset: '',
    savings_ngn: '',
    fees: props.fee_obligations.map((fee) => ({
        obligation_id: fee.id,
        amount_ngn: '',
    })),
    allocations: [] as Array<{ slot_id: number; amount_ngn: string }>,
    late_reason: '',
    notes: '',
    confirmed: false,
});
const previewHttp = useHttp({
    method: 'cash' as 'cash' | 'transfer' | 'pos' | 'other',
    collection_method_version_id: null as number | null,
    evidence_reference: null as string | null,
    plan_id: '',
    received_date: '',
    received_local_time: '',
    received_utc_offset: '',
    savings_ngn: '',
    fees: [] as Array<{ obligation_id: number; amount_ngn: string }>,
    allocations: [] as Array<{ slot_id: number; amount_ngn: string }>,
    late_reason: '',
    notes: '',
});
const preview = ref<Preview | null>(null);
const accessUnavailable = ref(false);
const reviewMessage = ref('');
const reviewNotice = ref<HTMLElement | null>(null);
const timeOptionsHttp = useHttp({});
const reviewBusy = computed(
    () => previewHttp.processing || timeOptionsHttp.processing,
);
const reviewBusyLabel = computed(() =>
    timeOptionsHttp.processing ? 'Checking time…' : 'Checking…',
);
function validationError(field: string): string | undefined {
    return (
        (previewHttp.errors as Record<string, string | undefined>)[field] ??
        (form.errors as Record<string, string | undefined>)[field]
    );
}
function feeValidationError(obligationId: number): string | undefined {
    const previewIndex = previewHttp.fees.findIndex(
        (fee) => fee.obligation_id === obligationId,
    );
    const submittedIndex = form.fees
        .filter((fee) => fee.amount_ngn !== '' && fee.amount_ngn !== '0')
        .findIndex((fee) => fee.obligation_id === obligationId);
    return (
        (previewHttp.errors as Record<string, string | undefined>)[
            `fees.${previewIndex}.amount_ngn`
        ] ??
        (form.errors as Record<string, string | undefined>)[
            `fees.${submittedIndex}.amount_ngn`
        ] ??
        validationError('fees') ??
        validationError('amount')
    );
}
const validOffsets = ref<Array<{ offset: string; received_at_utc: string }>>(
    [],
);
const timeNotice = ref('');
const customSlots = ref<
    Array<{ slot_id: number; due_date: string; capacity_kobo: number }>
>([]);
const submissionPending = ref(false);
const outcomeUnknown = ref(false);
const attemptLookup = useHttp({});
const recoveredReceipt = ref<string | null>(null);
const lookupNotice = ref('');
const retryAllowed = ref(false);
const pendingAttemptStorageKey = `collection-pending-attempt:${props.customer.id}`;
const money = (kobo: number): string =>
    `₦${(kobo / 100).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

function reportReviewFailure(error: unknown): void {
    preview.value = null;
    form.preview_fingerprint = '';
    form.replacement_fingerprint = '';
    form.confirmed = false;
    if (
        error instanceof HttpResponseError &&
        [403, 404].includes(error.response.status)
    ) {
        accessUnavailable.value = true;
        selectedEvidence.value = null;
        evidenceNotice.value = '';
        customSlots.value = [];
        validOffsets.value = [];
        timeNotice.value = '';
        previewHttp.defaults({
            method: 'cash',
            collection_method_version_id: null,
            evidence_reference: null,
            plan_id: '',
            received_date: '',
            received_local_time: '',
            received_utc_offset: '',
            savings_ngn: '',
            fees: [],
            allocations: [],
            late_reason: '',
            notes: '',
        });
        previewHttp.reset();
        previewHttp.response = null;
        previewHttp.clearErrors();
        timeOptionsHttp.response = null;
        timeOptionsHttp.clearErrors();
        form.clearErrors();
        if (!outcomeUnknown.value && !submissionPending.value) {
            form.method = 'cash';
            form.collection_method_version_id = null;
            form.evidence_reference = null;
            form.customer_version = 0;
            form.assignment_version = 0;
            form.business_version = 0;
            form.plan_id = '';
            form.plan_version = null;
            form.received_date = '';
            form.received_local_time = '';
            form.received_utc_offset = '';
            form.savings_ngn = '';
            form.fees = [];
            form.allocations = [];
            form.late_reason = '';
            form.notes = '';
        }
        retryAllowed.value = false;
        recoveredReceipt.value = null;
        lookupNotice.value = '';
        reviewMessage.value =
            'You no longer have access to this customer. Nothing was recorded. Go back to Collections.';
    } else {
        reviewMessage.value =
            error instanceof HttpResponseError
                ? 'We could not check this payment. Your details are kept and nothing was recorded. Try again.'
                : 'We could not reach the server. Your details are kept and nothing was recorded. Check your connection and try again.';
    }
    void nextTick(() => reviewNotice.value?.focus());
}

function rememberAttempt(): void {
    try {
        sessionStorage.setItem(
            pendingAttemptStorageKey,
            form.attempt_reference,
        );
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
        if (
            reference &&
            /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(
                reference,
            )
        ) {
            form.attempt_reference = reference;
            outcomeUnknown.value = true;
            lookupNotice.value =
                'Check your last attempt before recording this payment again.';
        }
    } catch {
        // A fresh form remains usable when tab storage is unavailable.
    }
});

watch(
    () => [form.plan_id, form.received_date, form.received_local_time],
    () => {
        validOffsets.value = [];
        form.received_utc_offset = '';
        timeNotice.value = '';
    },
);

watch(
    () => [
        form.method,
        form.collection_method_version_id,
        form.evidence_reference,
        form.plan_id,
        form.received_date,
        form.received_local_time,
        form.received_utc_offset,
        form.savings_ngn,
        form.late_reason,
        form.notes,
        ...form.fees.map((fee) => fee.amount_ngn),
        JSON.stringify(form.allocations),
    ],
    () => {
        preview.value = null;
        form.preview_fingerprint = '';
        form.confirmed = false;
    },
);

async function resolveTime(): Promise<boolean> {
    if (accessUnavailable.value) return false;
    if (!crossZone.value) return true;
    if (!form.received_local_time) {
        timeNotice.value = 'Enter the time the money was received.';
        return false;
    }
    try {
        const result = (await timeOptionsHttp.get(
            timeOptions.url(props.customer.id, {
                query: {
                    plan_id: form.plan_id,
                    received_date: form.received_date,
                    received_local_time: form.received_local_time,
                },
            }),
        )) as
            | { options: Array<{ offset: string; received_at_utc: string }> }
            | undefined;
        if (!result) return false;
        validOffsets.value = result.options;
        if (result.options.length === 0) {
            form.received_utc_offset = '';
            timeNotice.value =
                'That time did not happen because of a clock change. Pick another time.';
            return false;
        }
        if (
            !result.options.some(
                (option) => option.offset === form.received_utc_offset,
            )
        ) {
            form.received_utc_offset =
                result.options.length === 1 ? result.options[0]!.offset : '';
        }
        timeNotice.value =
            result.options.length > 1 && !form.received_utc_offset
                ? 'That time happened twice because of a clock change. Pick the right one below.'
                : '';
        return form.received_utc_offset !== '';
    } catch (error) {
        reportReviewFailure(error);
        return false;
    }
}

async function review(): Promise<void> {
    if (
        accessUnavailable.value ||
        reviewBusy.value ||
        submissionPending.value ||
        form.processing
    )
        return;
    reviewMessage.value = '';
    preview.value = null;
    form.preview_fingerprint = '';
    form.replacement_fingerprint = '';
    form.confirmed = false;
    if (
        !props.replacement_reversal &&
        paymentChoice.value === 'evidence' &&
        !selectedEvidence.value
    ) {
        evidenceNotice.value =
            'Choose checked payment proof before you continue.';
        return;
    }
    if (!(await resolveTime())) return;
    previewHttp.method = form.method;
    previewHttp.collection_method_version_id =
        form.collection_method_version_id;
    previewHttp.evidence_reference = form.evidence_reference;
    previewHttp.plan_id = form.plan_id;
    previewHttp.received_date = form.received_date;
    previewHttp.received_local_time = crossZone.value
        ? form.received_local_time
        : '';
    previewHttp.received_utc_offset = crossZone.value
        ? form.received_utc_offset
        : '';
    previewHttp.savings_ngn = form.savings_ngn || '0';
    previewHttp.fees = form.fees.filter(
        (fee) => fee.amount_ngn !== '' && fee.amount_ngn !== '0',
    );
    previewHttp.allocations = form.allocations.filter(
        (item) => item.amount_ngn !== '' && item.amount_ngn !== '0',
    );
    previewHttp.late_reason = form.late_reason;
    previewHttp.notes = form.notes;
    try {
        const result = (await previewHttp.post(
            props.replacement_reversal
                ? previewReplacement.url(props.replacement_reversal)
                : previewCollection.url(props.customer.id),
        )) as Preview | undefined;
        if (!result) return;
        preview.value = result;
        form.replacement_fingerprint = result.replacement_fingerprint ?? '';
        form.preview_fingerprint = result.preview_fingerprint;
        form.customer_version = result.customer_version;
        form.assignment_version = result.assignment_version;
        form.business_version = result.business_version;
        form.plan_version = result.plan_version;
    } catch (error) {
        reportReviewFailure(error);
    }
}

function customizeAllocation(): void {
    if (accessUnavailable.value || !preview.value) return;
    customSlots.value = preview.value.slot_options;
    form.allocations = preview.value.slot_options.map((slot) => ({
        slot_id: slot.slot_id,
        amount_ngn: (() => {
            const amount =
                preview.value?.allocations.find(
                    (item) => item.slot_id === slot.slot_id,
                )?.amount_kobo ?? 0;
            return amount > 0 ? (amount / 100).toFixed(2) : '';
        })(),
    }));
}

function submit(retryOriginal = false): void {
    if (
        accessUnavailable.value ||
        !preview.value ||
        !form.confirmed ||
        submissionPending.value ||
        (outcomeUnknown.value && !retryOriginal)
    )
        return;
    submissionPending.value = true;
    lookupNotice.value = '';
    retryAllowed.value = false;
    rememberAttempt();
    form.transform((data) => ({
        ...Object.fromEntries(
            Object.entries(data).filter(
                ([key]) =>
                    props.replacement_reversal ||
                    key !== 'replacement_fingerprint',
            ),
        ),
        savings_ngn: data.savings_ngn || '0',
        fees: data.fees.filter(
            (fee) => fee.amount_ngn !== '' && fee.amount_ngn !== '0',
        ),
        allocations: data.allocations.filter(
            (item) => item.amount_ngn !== '' && item.amount_ngn !== '0',
        ),
    })).post(
        props.replacement_reversal
            ? storeReplacement.url(props.replacement_reversal)
            : storeCollection.url(props.customer.id),
        {
            onError: () => {
                preview.value = null;
                outcomeUnknown.value = false;
                forgetAttempt();
            },
            onSuccess: () => {
                outcomeUnknown.value = false;
                forgetAttempt();
            },
            onNetworkError: () => {
                outcomeUnknown.value = true;
            },
            onHttpException: () => {
                outcomeUnknown.value = true;
                return false;
            },
            onFinish: () => {
                submissionPending.value = false;
            },
        },
    );
}

async function lookupAttempt(): Promise<void> {
    if (accessUnavailable.value) return;
    retryAllowed.value = false;
    try {
        const result = (await attemptLookup.get(
            showAttempt.url(form.attempt_reference, {
                query: { customer: props.customer.id },
            }),
        )) as { status: string; receipt_reference: string };
        recoveredReceipt.value =
            result.status === 'posted' ? result.receipt_reference : null;
        lookupNotice.value = recoveredReceipt.value
            ? 'The payment was recorded.'
            : 'We could not confirm it yet. Check again before trying again.';
    } catch (error) {
        recoveredReceipt.value = null;
        if (
            error instanceof HttpResponseError &&
            error.response.status === 404
        ) {
            try {
                retryAllowed.value =
                    JSON.parse(error.response.data)?.status === 'unresolved';
            } catch {
                retryAllowed.value = false;
            }
        }
        lookupNotice.value = retryAllowed.value
            ? 'It was not recorded. You can try again with the same details.'
            : 'We could not check it. Keep this page open and try again.';
    }
}
</script>

<template>
    <Head title="Record collection" />
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
        <PageHeader
            :title="
                replacement_reversal && !accessUnavailable
                    ? 'Replace receipt'
                    : 'Record payment'
            "
            :description="
                accessUnavailable
                    ? 'You no longer have access to this form.'
                    : replacement_reversal
                      ? `Choose where the money goes for ${customer.name}.`
                      : `From ${customer.name}.`
            "
        />
        <p
            v-if="
                !accessUnavailable &&
                replacement_reversal &&
                typeof replacement_controlled_kobo === 'number'
            "
            role="status"
            class="bg-muted -mt-2 rounded-xl p-4 text-sm"
        >
            Amount to place: {{ money(replacement_controlled_kobo) }}. You can
            place it once. No new money is collected.
        </p>
        <p
            v-else-if="replacement_reversal && !accessUnavailable"
            role="alert"
            class="text-destructive -mt-2 text-sm"
        >
            We could not load the amount to place. Refresh the page first.
        </p>

        <div
            v-if="reviewMessage"
            ref="reviewNotice"
            tabindex="-1"
            role="alert"
            aria-live="assertive"
            aria-atomic="true"
        >
            <Alert variant="destructive" role="presentation">
                <AlertTitle>{{
                    accessUnavailable
                        ? 'Form not available'
                        : 'Could not check payment'
                }}</AlertTitle>
                <AlertDescription class="grid gap-2">
                    <p>{{ reviewMessage }}</p>
                    <p v-if="accessUnavailable && outcomeUnknown">
                        Your last attempt still needs checking. You need access
                        to this customer to check it.
                    </p>
                    <Link
                        v-if="accessUnavailable"
                        :href="collectionsIndex()"
                        class="w-fit underline"
                        >Back to Collections</Link
                    >
                </AlertDescription>
            </Alert>
        </div>
        <p
            v-if="reviewBusy && !accessUnavailable"
            role="status"
            aria-live="polite"
            class="text-muted-foreground text-sm"
        >
            {{
                timeOptionsHttp.processing
                    ? 'Checking the time. Please wait.'
                    : 'Checking the payment. Please wait.'
            }}
        </p>
        <p
            v-if="submissionPending && !accessUnavailable"
            role="status"
            aria-live="polite"
            class="text-muted-foreground text-sm"
        >
            Recording payment. Please wait.
        </p>
        <Alert v-if="outcomeUnknown && !accessUnavailable">
            <AlertTitle>We are not sure it was saved</AlertTitle>
            <AlertDescription class="grid gap-3">
                <p>Check first, so the payment is not recorded twice.</p>
                <Button
                    type="button"
                    variant="outline"
                    class="w-fit"
                    :disabled="attemptLookup.processing"
                    @click="lookupAttempt"
                    >Check status</Button
                >
                <p v-if="lookupNotice" role="status" aria-live="polite">
                    {{ lookupNotice }}
                </p>
                <Link
                    v-if="recoveredReceipt"
                    :href="showReceipt(recoveredReceipt)"
                    class="w-fit underline"
                    @click="forgetAttempt"
                    >Open receipt {{ recoveredReceipt }}</Link
                ><Button
                    v-else-if="retryAllowed && preview"
                    type="button"
                    variant="outline"
                    class="w-fit"
                    :disabled="
                        submissionPending || form.processing || !form.confirmed
                    "
                    @click="submit(true)"
                    >Try again</Button
                ><span v-else-if="retryAllowed"
                    >Enter the payment details again, check them, then try
                    again.</span
                >
                <p class="text-muted-foreground text-xs break-all">
                    Reference: {{ form.attempt_reference }}
                </p>
            </AlertDescription>
        </Alert>

        <Card v-if="!replacement_reversal && !accessUnavailable">
            <CardHeader>
                <CardTitle class="flex items-center gap-3"
                    ><span
                        class="bg-accent text-accent-foreground inline-flex size-6 items-center justify-center rounded-full text-xs"
                        aria-hidden="true"
                        >1</span
                    >How did they pay?</CardTitle
                >
            </CardHeader>
            <CardContent class="grid gap-4">
                <div class="grid gap-2">
                    <Label for="payment-choice">Payment method</Label>
                    <Select
                        v-model="paymentChoice"
                        :disabled="
                            submissionPending ||
                            outcomeUnknown ||
                            form.processing ||
                            reviewBusy
                        "
                    >
                        <SelectTrigger
                            id="payment-choice"
                            class="w-full sm:w-72"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem v-if="cash_enabled" value="cash"
                                >Cash</SelectItem
                            >
                            <SelectItem
                                v-if="
                                    collection_methods.length ||
                                    initial_evidence
                                "
                                value="evidence"
                            >
                                Transfer or POS (with proof)
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p
                        v-if="!collection_methods.length"
                        class="text-muted-foreground text-xs"
                    >
                        Transfer and POS are not set up yet.
                    </p>
                </div>
                <CollectionEvidencePanel
                    v-if="paymentChoice === 'evidence'"
                    :customer="evidenceCustomer"
                    :methods="collection_methods"
                    :today="today"
                    :initial-reference="initial_evidence"
                    :disabled="
                        submissionPending ||
                        outcomeUnknown ||
                        form.processing ||
                        reviewBusy
                    "
                    @selected="selectEvidence"
                />
                <p
                    v-if="evidenceNotice"
                    role="alert"
                    class="text-destructive text-sm"
                >
                    {{ evidenceNotice }}
                </p>
            </CardContent>
        </Card>

        <Card v-if="!accessUnavailable">
            <CardHeader>
                <CardTitle class="flex items-center gap-3"
                    ><span
                        v-if="!replacement_reversal"
                        class="bg-accent text-accent-foreground inline-flex size-6 items-center justify-center rounded-full text-xs"
                        aria-hidden="true"
                        >2</span
                    >Amount</CardTitle
                >
            </CardHeader>
            <CardContent
                ><fieldset
                    :disabled="
                        submissionPending ||
                        (outcomeUnknown && !(retryAllowed && !preview)) ||
                        form.processing ||
                        reviewBusy
                    "
                    class="grid gap-5 sm:grid-cols-2"
                >
                    <legend class="sr-only">Payment amount</legend>
                    <div class="grid gap-2">
                        <Label for="collection-plan">Plan</Label>
                        <Select
                            :model-value="form.plan_id || FEE_ONLY"
                            @update:model-value="
                                (value) =>
                                    (form.plan_id =
                                        value === FEE_ONLY ? '' : String(value))
                            "
                        >
                            <SelectTrigger
                                id="collection-plan"
                                class="w-full"
                                :aria-invalid="
                                    Boolean(validationError('plan_id'))
                                "
                                :aria-describedby="
                                    validationError('plan_id')
                                        ? 'collection-plan-error'
                                        : undefined
                                "
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="FEE_ONLY"
                                    >No plan (fees only)</SelectItem
                                >
                                <SelectItem
                                    v-for="plan in plans"
                                    :key="plan.id"
                                    :value="plan.id"
                                >
                                    {{ plan.name }} · {{ plan.id }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError
                            id="collection-plan-error"
                            role="alert"
                            :message="validationError('plan_id')"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="collection-date">Date received</Label>
                        <DatePicker
                            id="collection-date"
                            aria-label="Date received"
                            v-model="form.received_date"
                            :error-message="validationError('received_date')"
                        />
                        <InputError
                            role="alert"
                            :message="validationError('received_date')"
                        />
                    </div>
                    <div v-if="crossZone" class="grid gap-2 sm:col-span-2">
                        <Label for="collection-time"
                            >Time received ({{ business_timezone }})</Label
                        >
                        <Input
                            id="collection-time"
                            v-model="form.received_local_time"
                            type="time"
                            class="w-fit"
                            :aria-invalid="
                                Boolean(validationError('received_local_time'))
                            "
                            :aria-describedby="
                                validationError('received_local_time')
                                    ? 'collection-time-error'
                                    : undefined
                            "
                        />
                        <InputError
                            id="collection-time-error"
                            role="alert"
                            :message="validationError('received_local_time')"
                        />
                        <p class="text-muted-foreground text-xs">
                            This plan uses
                            {{
                                plans.find((plan) => plan.id === form.plan_id)
                                    ?.timezone
                            }}
                            time, so we need the time to pick the right day.
                        </p>
                        <p
                            v-if="timeNotice"
                            role="alert"
                            class="text-destructive text-sm"
                        >
                            {{ timeNotice }}
                        </p>
                        <div v-if="validOffsets.length > 1" class="grid gap-2">
                            <Label for="collection-offset">Which time?</Label>
                            <Select
                                :model-value="
                                    form.received_utc_offset || undefined
                                "
                                @update:model-value="
                                    (value) =>
                                        (form.received_utc_offset = String(
                                            value ?? '',
                                        ))
                                "
                            >
                                <SelectTrigger
                                    id="collection-offset"
                                    class="w-full"
                                    ><SelectValue placeholder="Choose one"
                                /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="option in validOffsets"
                                        :key="option.received_at_utc"
                                        :value="option.offset"
                                    >
                                        {{ option.offset }} ·
                                        {{ option.received_at_utc }} UTC
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="collection-savings"
                            >Savings amount (NGN)</Label
                        >
                        <Input
                            id="collection-savings"
                            v-model="form.savings_ngn"
                            inputmode="decimal"
                            placeholder="2000.00"
                            class="h-12 text-lg sm:w-72"
                            :aria-invalid="
                                Boolean(
                                    validationError('savings_ngn') ||
                                    validationError('amount'),
                                )
                            "
                            :aria-describedby="
                                validationError('savings_ngn') ||
                                validationError('amount')
                                    ? 'collection-savings-error'
                                    : undefined
                            "
                        />
                        <InputError
                            id="collection-savings-error"
                            role="alert"
                            :message="
                                validationError('savings_ngn') ||
                                validationError('amount')
                            "
                        />
                    </div>
                    <div
                        v-if="fee_obligations.length"
                        class="grid gap-4 border-t pt-4 sm:col-span-2 sm:grid-cols-2"
                    >
                        <p class="text-sm font-medium sm:col-span-2">
                            Fees owed
                            <span class="text-muted-foreground font-normal"
                                >(leave empty if not paying now)</span
                            >
                        </p>
                        <div
                            v-for="(fee, index) in fee_obligations"
                            :key="fee.id"
                            class="grid gap-2"
                        >
                            <Label :for="`collection-fee-${fee.id}`"
                                >{{ fee.description }} (owes
                                {{ money(fee.outstanding_kobo) }})</Label
                            >
                            <Input
                                :id="`collection-fee-${fee.id}`"
                                v-model="form.fees[index]!.amount_ngn"
                                inputmode="decimal"
                                placeholder="0.00"
                                :aria-invalid="
                                    Boolean(feeValidationError(fee.id))
                                "
                                :aria-describedby="
                                    feeValidationError(fee.id)
                                        ? `collection-fee-${fee.id}-error`
                                        : undefined
                                "
                            />
                            <InputError
                                :id="`collection-fee-${fee.id}-error`"
                                role="alert"
                                :message="feeValidationError(fee.id)"
                            />
                        </div>
                    </div>
                    <div
                        v-if="
                            isLate ||
                            form.late_reason ||
                            validationError('late_reason')
                        "
                        class="grid gap-2 sm:col-span-2"
                    >
                        <Label for="collection-late"
                            >Why is this being recorded late?</Label
                        >
                        <Input
                            id="collection-late"
                            v-model="form.late_reason"
                            maxlength="500"
                            :aria-invalid="
                                Boolean(validationError('late_reason'))
                            "
                            :aria-describedby="
                                validationError('late_reason')
                                    ? 'collection-late-error'
                                    : undefined
                            "
                        />
                        <InputError
                            id="collection-late-error"
                            role="alert"
                            :message="validationError('late_reason')"
                        />
                    </div>
                    <div class="sm:col-span-2">
                        <MoreDetails
                            label="More options"
                            :default-open="
                                Boolean(form.notes || validationError('notes'))
                            "
                        >
                            <div class="grid gap-2">
                                <Label for="collection-notes"
                                    >Note for the customer</Label
                                >
                                <Input
                                    id="collection-notes"
                                    v-model="form.notes"
                                    maxlength="500"
                                    :aria-invalid="
                                        Boolean(validationError('notes'))
                                    "
                                    :aria-describedby="
                                        validationError('notes')
                                            ? 'collection-notes-error'
                                            : undefined
                                    "
                                />
                                <p class="text-muted-foreground text-xs">
                                    The customer can see this note.
                                </p>
                                <InputError
                                    id="collection-notes-error"
                                    role="alert"
                                    :message="validationError('notes')"
                                />
                            </div>
                        </MoreDetails>
                    </div>
                    <div v-if="!preview" class="border-t pt-4 sm:col-span-2">
                        <Button
                            type="button"
                            size="lg"
                            class="w-full sm:w-auto"
                            :disabled="reviewBusy"
                            @click="review"
                            >{{
                                reviewBusy ? reviewBusyLabel : 'Check amounts'
                            }}</Button
                        >
                        <p
                            v-for="(error, key) in previewHttp.errors"
                            :key="key"
                            role="alert"
                            class="text-destructive mt-2 text-sm"
                        >
                            {{ error }}
                        </p>
                    </div>
                </fieldset></CardContent
            >
        </Card>

        <Card v-if="form.allocations.length && !accessUnavailable">
            <CardHeader>
                <CardTitle>Choose days</CardTitle>
                <p class="text-muted-foreground text-sm">
                    Enter an amount for each day you want to pay. Leave the rest
                    empty, then check again.
                </p>
            </CardHeader>
            <CardContent class="grid gap-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div
                        v-for="item in form.allocations"
                        :key="item.slot_id"
                        class="grid gap-2"
                    >
                        <Label :for="`slot-${item.slot_id}`"
                            >{{
                                customSlots.find(
                                    (slot) => slot.slot_id === item.slot_id,
                                )?.due_date
                            }}
                            (up to
                            {{
                                money(
                                    customSlots.find(
                                        (slot) => slot.slot_id === item.slot_id,
                                    )?.capacity_kobo ?? 0,
                                )
                            }})</Label
                        ><Input
                            :id="`slot-${item.slot_id}`"
                            v-model="item.amount_ngn"
                            inputmode="decimal"
                            :disabled="
                                submissionPending ||
                                outcomeUnknown ||
                                form.processing
                            "
                            placeholder="0.00"
                        />
                    </div>
                </div>
                <Button
                    v-if="!preview"
                    type="button"
                    class="w-fit"
                    :disabled="
                        previewHttp.processing ||
                        submissionPending ||
                        outcomeUnknown
                    "
                    @click="review"
                    >{{ reviewBusy ? reviewBusyLabel : 'Check again' }}</Button
                >
            </CardContent>
        </Card>

        <div
            v-if="!accessUnavailable && Object.keys(form.errors).length"
            role="alert"
            class="text-destructive grid gap-1 text-sm"
        >
            <p v-for="(error, key) in form.errors" :key="key">{{ error }}</p>
            <p>Check the details and try again.</p>
        </div>

        <Card v-if="preview && !accessUnavailable" class="border-primary/30">
            <CardHeader>
                <CardTitle class="flex items-center gap-3"
                    ><span
                        v-if="!replacement_reversal"
                        class="bg-accent text-accent-foreground inline-flex size-6 items-center justify-center rounded-full text-xs"
                        aria-hidden="true"
                        >3</span
                    >Check and record</CardTitle
                >
            </CardHeader>
            <CardContent class="flex flex-col gap-5">
                <div
                    role="status"
                    aria-live="polite"
                    class="grid gap-3 sm:grid-cols-3"
                >
                    <div class="bg-muted/40 rounded-xl p-4">
                        <p class="text-muted-foreground text-sm">Total</p>
                        <p class="mt-1 text-2xl font-semibold">
                            {{ money(preview.tender_kobo) }}
                        </p>
                    </div>
                    <div class="bg-muted/40 rounded-xl p-4">
                        <p class="text-muted-foreground text-sm">Savings</p>
                        <p class="mt-1 text-lg font-medium">
                            {{ money(preview.savings_kobo) }}
                        </p>
                    </div>
                    <div class="bg-muted/40 rounded-xl p-4">
                        <p class="text-muted-foreground text-sm">Fees</p>
                        <p class="mt-1 text-lg font-medium">
                            {{ money(preview.fees_kobo) }}
                        </p>
                    </div>
                </div>
                <div v-if="preview.allocations.length">
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <h3 class="text-sm font-medium">Days this pays for</h3>
                        <Button
                            v-if="
                                preview.slot_options.length > 1 &&
                                form.allocations.length === 0
                            "
                            type="button"
                            variant="ghost"
                            size="sm"
                            :disabled="
                                submissionPending ||
                                outcomeUnknown ||
                                form.processing
                            "
                            @click="customizeAllocation"
                            >Change days</Button
                        >
                    </div>
                    <ul class="mt-2 divide-y text-sm">
                        <li
                            v-for="slot in preview.allocations"
                            :key="slot.slot_id"
                            class="flex justify-between gap-3 py-2"
                        >
                            <span>{{ slot.due_date }}</span>
                            <span class="font-medium">{{
                                money(slot.amount_kobo)
                            }}</span>
                        </li>
                    </ul>
                </div>
                <MoreDetails>
                    <div
                        class="text-muted-foreground grid gap-2 text-xs leading-5"
                    >
                        <p>
                            Paid by {{ preview.method_context.method_label }}.
                            Dates use the {{ preview.timezone }} time zone.
                        </p>
                        <p
                            v-if="
                                preview.method_context.custody_account_code ===
                                'payment_clearing_ngn'
                            "
                        >
                            This stays pending until the bank deposit is
                            checked.
                        </p>
                        <p v-if="preview.received_at_utc">
                            Received at {{ form.received_local_time }}
                            {{ preview.timezone }} ({{
                                form.received_utc_offset
                            }}, {{ preview.received_at_utc }} UTC). In the
                            plan’s {{ preview.plan_timezone }} time zone, the
                            date is {{ preview.plan_received_date }}.
                        </p>
                    </div>
                </MoreDetails>
                <label class="flex items-start gap-3 text-sm"
                    ><input
                        v-model="form.confirmed"
                        type="checkbox"
                        class="mt-0.5"
                        :disabled="
                            submissionPending ||
                            (outcomeUnknown && !retryAllowed) ||
                            form.processing
                        "
                    />
                    I received this money and the amounts above are
                    correct.</label
                >
                <div
                    class="flex flex-col-reverse gap-3 border-t pt-4 sm:flex-row sm:items-center"
                >
                    <Button
                        type="button"
                        size="lg"
                        class="w-full sm:w-auto"
                        :disabled="
                            submissionPending ||
                            form.processing ||
                            outcomeUnknown ||
                            !form.confirmed
                        "
                        @click="submit()"
                        >Record payment</Button
                    ><Link
                        v-if="!submissionPending && !outcomeUnknown"
                        :href="collectionsIndex()"
                        class="text-muted-foreground text-center text-sm underline-offset-4 hover:underline"
                        >Cancel</Link
                    >
                </div>
            </CardContent>
        </Card>
    </div>
</template>
