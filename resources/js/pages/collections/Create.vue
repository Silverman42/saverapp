<script setup lang="ts">
import { Head, Link, useForm, useHttp } from '@inertiajs/vue3';
import { HttpResponseError } from '@inertiajs/core';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { newOperationReference } from '@/lib/operation-reference';
import CollectionEvidencePanel from '@/components/CollectionEvidencePanel.vue';
import InputError from '@/components/InputError.vue';
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
    { collection_methods: () => [], initial_evidence: null },
);
const FEE_ONLY = '__fee_only';
const paymentChoice = ref(props.initial_evidence ? 'evidence' : 'cash');
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
    timeOptionsHttp.processing
        ? 'Checking received time…'
        : 'Reviewing allocation and tender…',
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
            'This receipt form is no longer available with your current access. Return to Collections to open an available record. No money was submitted by this review.';
    } else {
        reviewMessage.value =
            error instanceof HttpResponseError
                ? 'Receipt review could not be completed. Your draft is retained. No money was submitted by this review. Try reviewing again before recording the receipt.'
                : 'Receipt review could not reach the server. Your draft is retained. No money was submitted by this review. Check your connection and review again.';
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
                'This attempt needs a result check before any money is recorded again.';
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
        timeNotice.value =
            'Enter the actual business-local time when this payment was received.';
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
                'This local time did not occur in the business timezone. Choose another time.';
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
                ? 'This local time occurred twice. Choose the offset that matches when the payment was received.'
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
            'Select matching, independently verified evidence before reviewing this receipt.';
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
            ? 'The original receipt was posted.'
            : 'The lookup did not confirm a result. Check again before retrying.';
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
            ? 'No posted receipt was found for this reference. You can retry the original details.'
            : 'The lookup could not confirm the result. Keep this reference and check again.';
    }
}
</script>

<template>
    <Head title="Record collection" />
    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">
                {{
                    replacement_reversal && !accessUnavailable
                        ? 'Apply controlled receipt replacement'
                        : 'Record collection'
                }}
            </h1>
            <p
                v-if="
                    !accessUnavailable &&
                    replacement_reversal &&
                    typeof replacement_controlled_kobo === 'number'
                "
                role="status"
            >
                Allocate the remaining controlled amount
                {{ money(replacement_controlled_kobo) }} once. No new physical
                tender is received.
            </p>
            <p
                v-else-if="replacement_reversal && !accessUnavailable"
                role="alert"
            >
                The remaining controlled amount is unavailable. Reload before
                allocating funds.
            </p>
            <p class="text-muted-foreground mt-1.5 text-sm">
                <template v-if="accessUnavailable">
                    You must have current access to review a receipt.
                </template>
                <template v-else>
                    {{
                        replacement_reversal
                            ? 'Review the replacement allocation for'
                            : 'Confirm money actually received from'
                    }}
                    {{ customer.name }}.
                </template>
            </p>
        </div>
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
                        ? 'Receipt form unavailable'
                        : 'Receipt review unavailable'
                }}</AlertTitle>
                <AlertDescription class="grid gap-2">
                    <p>{{ reviewMessage }}</p>
                    <p v-if="accessUnavailable && outcomeUnknown">
                        You must check the result of the earlier attempt. The
                        system kept its original reference. You must have
                        current access to check its result.
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
        >
            {{
                timeOptionsHttp.processing
                    ? 'Checking the received time before reviewing this receipt. Wait for the result.'
                    : 'Reviewing the allocation and tender. Wait for the preview before recording this receipt.'
            }}
        </p>
        <p
            v-if="submissionPending && !accessUnavailable"
            role="status"
            aria-live="polite"
        >
            Recording receipt. Wait for the result before trying again.
        </p>
        <Alert v-if="outcomeUnknown && !accessUnavailable">
            <AlertTitle>Submission outcome unknown</AlertTitle>
            <AlertDescription class="grid gap-2"
                >Keep this attempt open and look up its original reference
                before trying again: {{ form.attempt_reference }}.<Button
                    type="button"
                    variant="outline"
                    class="w-fit"
                    :disabled="attemptLookup.processing"
                    @click="lookupAttempt"
                    >Check original attempt</Button
                >
                <p v-if="lookupNotice" role="status" aria-live="polite">
                    {{ lookupNotice }}
                </p>
                <Link
                    v-if="recoveredReceipt"
                    :href="showReceipt(recoveredReceipt)"
                    class="underline"
                    @click="forgetAttempt"
                    >Open posted receipt {{ recoveredReceipt }}</Link
                ><Button
                    v-else-if="retryAllowed && preview"
                    type="button"
                    variant="outline"
                    class="w-fit"
                    :disabled="
                        submissionPending || form.processing || !form.confirmed
                    "
                    @click="submit(true)"
                    >Retry original attempt</Button
                ><span v-else-if="retryAllowed"
                    >Re-enter the receipt details below and review them before
                    retrying this reference.</span
                ></AlertDescription
            >
        </Alert>
        <div
            v-if="!replacement_reversal && !accessUnavailable"
            class="grid gap-3"
        >
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
                <SelectTrigger id="payment-choice"
                    ><SelectValue
                /></SelectTrigger>
                <SelectContent>
                    <SelectItem value="cash">Cash</SelectItem>
                    <SelectItem
                        v-if="collection_methods.length || initial_evidence"
                        value="evidence"
                    >
                        Verified noncash payment
                    </SelectItem>
                </SelectContent>
            </Select>
            <p
                v-if="!collection_methods.length"
                class="text-muted-foreground text-sm"
            >
                Noncash collection methods are not available now.
            </p>
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
        </div>
        <Card v-if="!accessUnavailable">
            <CardHeader><CardTitle>Receipt details</CardTitle></CardHeader>
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
                                    >Fee payment only</SelectItem
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
                        <Label for="collection-date"
                            >Date money was received</Label
                        >
                        <DatePicker
                            id="collection-date"
                            aria-label="Date money was received"
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
                            >Actual time received in
                            {{ business_timezone }}</Label
                        >
                        <Input
                            id="collection-time"
                            v-model="form.received_local_time"
                            type="time"
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
                        <p class="text-muted-foreground text-sm">
                            This plan uses
                            {{
                                plans.find((plan) => plan.id === form.plan_id)
                                    ?.timezone
                            }}. The time sets the slot date.
                        </p>
                        <p
                            v-if="timeNotice"
                            role="alert"
                            class="text-destructive text-sm"
                        >
                            {{ timeNotice }}
                        </p>
                        <div v-if="validOffsets.length > 1" class="grid gap-2">
                            <Label for="collection-offset"
                                >UTC offset at the time received</Label
                            >
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
                                    ><SelectValue
                                        placeholder="Choose the correct occurrence"
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
                    <div class="grid gap-2">
                        <Label for="collection-savings"
                            >Savings received (NGN)</Label
                        >
                        <Input
                            id="collection-savings"
                            v-model="form.savings_ngn"
                            inputmode="decimal"
                            placeholder="2000.00"
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
                        v-for="(fee, index) in fee_obligations"
                        :key="fee.id"
                        class="grid gap-2"
                    >
                        <Label :for="`collection-fee-${fee.id}`"
                            >{{ fee.description }} · due
                            {{ money(fee.outstanding_kobo) }}</Label
                        >
                        <Input
                            :id="`collection-fee-${fee.id}`"
                            v-model="form.fees[index]!.amount_ngn"
                            inputmode="decimal"
                            placeholder="0.00"
                            :aria-invalid="Boolean(feeValidationError(fee.id))"
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
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="collection-late"
                            >Late recording reason, if received before
                            today</Label
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
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="collection-notes"
                            >Customer-visible note</Label
                        >
                        <Input
                            id="collection-notes"
                            v-model="form.notes"
                            maxlength="500"
                            :aria-invalid="Boolean(validationError('notes'))"
                            :aria-describedby="
                                validationError('notes')
                                    ? 'collection-notes-error'
                                    : undefined
                            "
                        />
                        <InputError
                            id="collection-notes-error"
                            role="alert"
                            :message="validationError('notes')"
                        />
                    </div>
                    <div class="sm:col-span-2">
                        <Button
                            type="button"
                            :disabled="reviewBusy"
                            @click="review"
                            >{{
                                reviewBusy
                                    ? reviewBusyLabel
                                    : 'Review allocation and tender'
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
        <div
            v-if="!accessUnavailable && Object.keys(form.errors).length"
            role="alert"
            class="text-destructive grid gap-1 text-sm"
        >
            <p v-for="(error, key) in form.errors" :key="key">{{ error }}</p>
            <p>Review the current details before recording money again.</p>
        </div>
        <Card v-if="preview && !accessUnavailable">
            <CardHeader
                ><CardTitle>Confirm payment received</CardTitle></CardHeader
            >
            <CardContent class="flex flex-col gap-4">
                <p role="status" aria-live="polite" class="text-sm">
                    Preview ready. Savings {{ money(preview.savings_kobo) }} ·
                    fees {{ money(preview.fees_kobo) }} · total tender
                    {{ money(preview.tender_kobo) }}
                </p>
                <p class="text-muted-foreground text-sm">
                    The received date uses {{ preview.timezone }}. This action
                    records Customer savings and
                    {{ preview.method_context.method_label }} custody.
                    {{
                        preview.method_context.custody_account_code ===
                        'payment_clearing_ngn'
                            ? 'Clearing stays pending until an independent bank settlement.'
                            : ''
                    }}
                </p>
                <p
                    v-if="preview.received_at_utc"
                    class="text-muted-foreground text-sm"
                >
                    Received at {{ form.received_local_time }}
                    {{ preview.timezone }} ({{ form.received_utc_offset }};
                    {{ preview.received_at_utc }} UTC). In the plan’s
                    {{ preview.plan_timezone }} timezone, the date is
                    {{ preview.plan_received_date }}.
                </p>
                <ul class="grid gap-2 text-sm">
                    <li v-for="slot in preview.allocations" :key="slot.slot_id">
                        {{ slot.due_date }} · {{ money(slot.amount_kobo) }}
                    </li>
                </ul>
                <Button
                    v-if="
                        preview.slot_options.length > 1 &&
                        form.allocations.length === 0
                    "
                    type="button"
                    variant="outline"
                    class="w-fit"
                    :disabled="
                        submissionPending || outcomeUnknown || form.processing
                    "
                    @click="customizeAllocation"
                    >Change slot allocation</Button
                >
                <label class="flex items-start gap-3 text-sm"
                    ><input
                        v-model="form.confirmed"
                        type="checkbox"
                        :disabled="
                            submissionPending ||
                            (outcomeUnknown && !retryAllowed) ||
                            form.processing
                        "
                    />
                    I confirm that this payment was received and the split above
                    is correct.</label
                >
                <div class="flex gap-3">
                    <Button
                        type="button"
                        :disabled="
                            submissionPending ||
                            form.processing ||
                            outcomeUnknown ||
                            !form.confirmed
                        "
                        @click="submit()"
                        >Record receipt</Button
                    ><Link
                        v-if="!submissionPending && !outcomeUnknown"
                        :href="collectionsIndex()"
                        class="text-muted-foreground self-center text-sm underline"
                        >Cancel</Link
                    >
                </div>
            </CardContent>
        </Card>
        <Card v-if="form.allocations.length && !accessUnavailable"
            ><CardHeader
                ><CardTitle>Custom slot allocation</CardTitle></CardHeader
            ><CardContent class="grid gap-3"
                ><p class="text-muted-foreground text-sm">
                    Enter an amount for each slot that you chose. Leave the
                    other slots empty. Then review again.
                </p>
                <div
                    v-for="item in form.allocations"
                    :key="item.slot_id"
                    class="grid max-w-xs gap-2"
                >
                    <Label :for="`slot-${item.slot_id}`"
                        >{{
                            customSlots.find(
                                (slot) => slot.slot_id === item.slot_id,
                            )?.due_date
                        }}
                        · up to
                        {{
                            money(
                                customSlots.find(
                                    (slot) => slot.slot_id === item.slot_id,
                                )?.capacity_kobo ?? 0,
                            )
                        }}</Label
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
                <Button
                    type="button"
                    class="w-fit"
                    :disabled="
                        previewHttp.processing ||
                        submissionPending ||
                        outcomeUnknown
                    "
                    @click="review"
                    >{{
                        reviewBusy
                            ? reviewBusyLabel
                            : 'Review custom allocation'
                    }}</Button
                ></CardContent
            ></Card
        >
    </div>
</template>
