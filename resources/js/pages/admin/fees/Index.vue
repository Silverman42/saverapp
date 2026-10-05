<script setup lang="ts">
import { HttpResponseError } from '@inertiajs/core';
import { Head, Link, router, useForm, useHttp, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { dashboard, freshAuthentication } from '@/routes';
import {
    isOperationReference,
    newOperationReference,
} from '@/lib/operation-reference';
import { index as feesIndex } from '@/routes/admin/fees';
import {
    prepare as prepareAttempt,
    cancel as cancelAttempt,
    status as attemptStatus,
} from '@/routes/admin/fees/obligations/attempts';
import { index as registrationFeesIndex } from '@/routes/admin/fees/registration';
import { show as showCustomer } from '@/routes/customers';
import {
    correct as correctObligation,
    waive as waiveObligation,
} from '@/routes/admin/fees/obligations';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { DatePicker } from '@/components/ui/date-picker';
import InputError from '@/components/InputError.vue';
import FeeSavingsApplicationDialog from '@/components/FeeSavingsApplicationDialog.vue';

type Obligation = {
    id: number;
    customer_id: string;
    customer_name: string;
    kind: string;
    rule_name: string;
    currency: string;
    model: string | null;
    source: string;
    amount_kobo: number | null;
    formatted_amount: string | null;
    formatted_settled_amount: string | null;
    formatted_waived_amount: string | null;
    settled_amount_kobo: number | null;
    waived_amount_kobo: number | null;
    outstanding_amount_kobo: number | null;
    formatted_outstanding_amount: string | null;
    status: string;
    status_label: string;
    can_waive: boolean;
    can_correct: boolean;
    can_apply_savings: boolean;
};

type PageLink = { url: string | null; label: string; active: boolean };
type FeeFilters = {
    date_from: string;
    date_to: string;
    customer: string;
    current_agent: string;
    original_agent: string;
    cycle: string;
    kind: string;
    model: string;
    status: string;
    source: string;
    currency: string;
    refund_status: string;
    reconciliation_status: string;
    sort: string;
    per_page: number;
};
type FilterOption = { value: string; label: string };

const props = defineProps<{
    filters: FeeFilters;
    filter_options: Record<string, FilterOption[]>;
    summary: {
        obligation_count: number;
        pending_count: number | null;
        formatted_outstanding_amount: string | null;
        obligation_totals_status: 'available' | 'unavailable';
        unavailable_count: number;
        as_of: string;
        earnings: {
            status: 'available' | 'unavailable';
            message?: string;
            formatted_lifetime_gross?: string;
            formatted_lifetime_refunds?: string;
            formatted_lifetime_net?: string;
            formatted_today_net?: string;
            formatted_month_net?: string;
            today_net_kobo?: number;
            month_net_kobo?: number;
        };
        refund_payable: {
            status: 'available' | 'unavailable';
            message?: string;
            formatted_amount?: string;
        };
    };
    obligations: {
        data: Obligation[];
        links: PageLink[];
    };
}>();

const filterForm = useForm({ ...props.filters });
const textFilters: {
    key: 'customer' | 'current_agent' | 'original_agent' | 'cycle';
    label: string;
    placeholder: string;
}[] = [
    { key: 'customer', label: 'Customer', placeholder: 'Customer ID' },
    {
        key: 'current_agent',
        label: 'Current assigned Agent',
        placeholder: 'Agent ID',
    },
    {
        key: 'original_agent',
        label: 'Original assessment Agent',
        placeholder: 'Agent ID',
    },
    { key: 'cycle', label: 'Cycle', placeholder: 'Plan ID' },
];
const selectFilters: {
    key:
        | 'kind'
        | 'model'
        | 'status'
        | 'source'
        | 'currency'
        | 'refund_status'
        | 'reconciliation_status';
    label: string;
}[] = [
    { key: 'kind', label: 'Fee kind' },
    { key: 'model', label: 'Fee model' },
    { key: 'status', label: 'Obligation state' },
    { key: 'source', label: 'Assessment source' },
    { key: 'currency', label: 'Currency' },
    { key: 'refund_status', label: 'Recorded refund outcome' },
    { key: 'reconciliation_status', label: 'Linked fee receipt batch' },
];
const hasFilters = computed(() =>
    Object.entries(props.filters).some(
        ([key, value]) => key !== 'sort' && key !== 'per_page' && value !== '',
    ),
);
const hasAdvancedErrors = computed(() =>
    selectFilters.some((field) => !!filterForm.errors[field.key]),
);
function applyFilters(): void {
    if (feeAccessBlocked.value) return;
    filterForm.get(feesIndex().url, {
        preserveState: true,
        preserveScroll: true,
    });
}
function resetFilters(): void {
    if (feeAccessBlocked.value) return;
    router.get(feesIndex().url);
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Fees and Deductions', href: feesIndex() },
        ],
    },
});

const selectedObligation = ref<Obligation | null>(null);
const action = ref<'waive' | 'correct'>('waive');
const showActionDialog = ref(false);
const savingsObligation = ref<Obligation | null>(null);
const showSavingsDialog = ref(false);
const savingsAttemptPending = ref(false);

function openSavings(selected: Obligation): void {
    if (
        feeAccessBlocked.value ||
        administrativePending.value ||
        actionBusy.value
    )
        return;
    savingsObligation.value = selected;
    showSavingsDialog.value = true;
}

const form = useForm({
    amount_ngn: '',
    direction: 'reduce',
    reason: '',
    customer_description: '',
    attempt_reference: '',
});

type PendingAction = {
    schema_version: 1;
    actor_id: number;
    obligation_id: number;
    action: 'waive' | 'correct';
    attempt_reference: string;
    payload: {
        amount_ngn: string;
        direction: string;
        reason: string;
        customer_description: string;
    };
};
type AttemptResult = {
    status: 'prepared' | 'cancelled' | 'recorded';
    operation: 'waive' | 'correct' | 'apply_savings';
    obligation_id: number;
    attempt_reference: string;
    action?: 'waive' | 'correct';
    direction?: 'reduce' | 'increase' | null;
    entry_id?: number;
    amount_kobo?: number;
    currency?: string;
};
type AttemptInstructions = {
    operation: 'waive' | 'correct';
    attempt_reference: string;
    payload: {
        amount_ngn: string;
        direction?: string;
        reason: string;
        customer_description: string;
    };
};
const page = usePage();
const originalActorId = page.props.auth.user.id;
const hasFeePermission = computed(
    () =>
        page.props.auth.user.id === originalActorId &&
        page.props.auth.permissions.includes('fees.manage'),
);
const accessUnavailable = ref(false);
const feeAccessBlocked = computed(
    () => accessUnavailable.value || !hasFeePermission.value,
);
const accessNotice = ref<HTMLElement | null>(null);
const accessMessage = ref('');
const reloadingWorkspace = ref(false);
const storageKey = `fee-admin-action-v1-${page.props.auth.user.id}`;
const pendingAction = ref<PendingAction | null>(null);
const storageBlocked = ref(false);
const actionMessage = ref('');
const freshRequired = ref(false);
const outcomeRequest = useHttp<Record<string, never>, AttemptResult>({});
const prepareRequest = useHttp<AttemptInstructions, AttemptResult>({
    operation: 'waive',
    attempt_reference: '',
    payload: { amount_ngn: '', reason: '', customer_description: '' },
});
const cancelRequest = useHttp<AttemptInstructions, AttemptResult>({
    operation: 'waive',
    attempt_reference: '',
    payload: { amount_ngn: '', reason: '', customer_description: '' },
});
const actionBusy = computed(
    () =>
        form.processing ||
        outcomeRequest.processing ||
        prepareRequest.processing ||
        cancelRequest.processing,
);
const administrativePending = computed(
    () => pendingAction.value !== null || storageBlocked.value,
);

function focusAccessNotice(): void {
    void nextTick(() => accessNotice.value?.focus());
}

function blockFeeAccess(): void {
    accessUnavailable.value = true;
    selectedObligation.value = null;
    savingsObligation.value = null;
    showActionDialog.value = false;
    showSavingsDialog.value = false;
    freshRequired.value = false;
    accessMessage.value =
        'The fee workspace is unavailable with your current access. Any saved attempt remains retained. Reload the current workspace after access is restored before checking its outcome.';
    focusAccessNotice();
}

watch(
    hasFeePermission,
    (allowed) => {
        if (!allowed) blockFeeAccess();
    },
    { immediate: true },
);

function reloadAuthorizedWorkspace(): void {
    if (reloadingWorkspace.value || actionBusy.value) return;
    reloadingWorkspace.value = true;
    router.reload({
        onSuccess: (currentPage) => {
            const auth = currentPage.props.auth;
            const obligations = currentPage.props.obligations as
                { data?: Obligation[] } | undefined;
            if (
                currentPage.component !== 'admin/fees/Index' ||
                auth?.user?.id !== originalActorId ||
                !auth.permissions.includes('fees.manage') ||
                !Array.isArray(obligations?.data) ||
                !currentPage.props.summary ||
                !currentPage.props.filters ||
                !currentPage.props.filter_options
            ) {
                accessMessage.value =
                    'Current fee access could not be verified. Any saved attempt remains retained.';
                return;
            }
            accessUnavailable.value = false;
            accessMessage.value = '';
            if (pendingAction.value) restorePending(pendingAction.value);
            actionMessage.value = pendingAction.value
                ? 'Current fee access has been verified. The original saved attempt remains retained. Check its outcome before another adjustment.'
                : '';
        },
        onError: () => {
            accessMessage.value =
                'The current fee workspace could not be verified. Any saved attempt remains retained.';
        },
        onHttpException: () => {
            blockFeeAccess();
            return false;
        },
        onNetworkError: () => {
            accessMessage.value =
                'The current fee workspace could not be verified because the connection failed. Any saved attempt remains retained. Reload again after the connection is restored.';
            return false;
        },
        onFinish: () => {
            reloadingWorkspace.value = false;
            if (feeAccessBlocked.value) focusAccessNotice();
        },
    });
}

function validPending(value: unknown): value is PendingAction {
    if (!value || typeof value !== 'object') return false;
    const candidate = value as Partial<PendingAction>;
    const payload = candidate.payload;
    return (
        candidate.schema_version === 1 &&
        candidate.actor_id === page.props.auth.user.id &&
        Number.isSafeInteger(candidate.obligation_id) &&
        Number(candidate.obligation_id) > 0 &&
        (candidate.action === 'waive' || candidate.action === 'correct') &&
        typeof candidate.attempt_reference === 'string' &&
        isOperationReference(candidate.attempt_reference) &&
        !!payload &&
        typeof payload === 'object' &&
        typeof payload.amount_ngn === 'string' &&
        /^\d{1,10}(\.\d{1,2})?$/.test(payload.amount_ngn) &&
        Number(payload.amount_ngn) > 0 &&
        (payload.direction === 'reduce' || payload.direction === 'increase') &&
        typeof payload.reason === 'string' &&
        payload.reason.trim().length > 0 &&
        payload.reason.length <= 500 &&
        typeof payload.customer_description === 'string' &&
        payload.customer_description.trim().length > 0 &&
        payload.customer_description.length <= 500
    );
}

function restorePending(pending: PendingAction): void {
    if (feeAccessBlocked.value) return;
    action.value = pending.action;
    form.amount_ngn = pending.payload.amount_ngn;
    form.direction = pending.payload.direction;
    form.reason = pending.payload.reason;
    form.customer_description = pending.payload.customer_description;
    form.attempt_reference = pending.attempt_reference;
    selectedObligation.value =
        props.obligations.data.find(
            (item) => item.id === pending.obligation_id,
        ) ?? null;
}

onMounted(() => {
    try {
        const stored = sessionStorage.getItem(storageKey);
        if (stored === null) return;
        const pending: unknown = JSON.parse(stored);
        if (!validPending(pending)) throw new Error('Invalid saved attempt');
        pendingAction.value = pending;
        restorePending(pending);
        actionMessage.value =
            'A submitted fee action needs its outcome checked before another adjustment.';
    } catch {
        storageBlocked.value = true;
        actionMessage.value =
            'Saved fee action recovery is unavailable. No new adjustment can be submitted safely.';
    }
});

function openAction(
    selected: Obligation,
    selectedAction: 'waive' | 'correct',
): void {
    if (
        feeAccessBlocked.value ||
        administrativePending.value ||
        savingsAttemptPending.value
    )
        return;
    selectedObligation.value = selected;
    action.value = selectedAction;
    form.reset();
    form.clearErrors();
    form.direction = 'reduce';
    form.attempt_reference = newOperationReference();
    actionMessage.value = '';
    freshRequired.value = false;
    showActionDialog.value = true;
}

function reopenAction(): void {
    if (feeAccessBlocked.value || !pendingAction.value || actionBusy.value)
        return;
    restorePending(pendingAction.value);
    showActionDialog.value = true;
}

function instructions(pending: PendingAction): AttemptInstructions {
    return {
        operation: pending.action,
        attempt_reference: pending.attempt_reference,
        payload: {
            amount_ngn: pending.payload.amount_ngn,
            reason: pending.payload.reason,
            customer_description: pending.payload.customer_description,
            ...(pending.action === 'correct'
                ? { direction: pending.payload.direction }
                : {}),
        },
    };
}

function assertRetained(pending: PendingAction): void {
    const retained: unknown = JSON.parse(
        sessionStorage.getItem(storageKey) ?? 'null',
    );
    if (
        !validPending(retained) ||
        JSON.stringify(retained) !== JSON.stringify(pending)
    )
        throw new Error('Saved attempt changed');
}

function resolveAttempt(
    result: AttemptResult,
    pending: PendingAction,
): boolean {
    if (feeAccessBlocked.value)
        throw new Error('Current fee access is unavailable');
    if (
        result.attempt_reference !== pending.attempt_reference ||
        result.operation !== pending.action ||
        result.obligation_id !== pending.obligation_id
    )
        throw new Error('Outcome mismatch');
    if (result.status === 'prepared') return false;
    if (result.status !== 'cancelled' && result.status !== 'recorded')
        throw new Error('Unknown outcome');
    if (
        result.status === 'recorded' &&
        (result.action !== pending.action ||
            result.direction !==
                (pending.action === 'correct'
                    ? pending.payload.direction
                    : null) ||
            result.currency !== 'NGN' ||
            result.amount_kobo !==
                Math.round(Number(pending.payload.amount_ngn) * 100) ||
            !Number.isSafeInteger(result.entry_id) ||
            Number(result.entry_id) < 1)
    )
        throw new Error('Recorded outcome mismatch');
    assertRetained(pending);
    sessionStorage.removeItem(storageKey);
    if (sessionStorage.getItem(storageKey) !== null)
        throw new Error('Saved attempt remains');
    pendingAction.value = null;
    form.resetAndClearErrors();
    freshRequired.value = false;
    showActionDialog.value = false;
    actionMessage.value =
        result.status === 'cancelled'
            ? 'The original attempt is cancelled. Delayed requests cannot record it. You may start a new review.'
            : 'The original fee action is recorded. Its outcome has been verified.';
    router.reload({ only: ['summary', 'obligations'] });
    return true;
}

function reportAttemptError(error: unknown): void {
    freshRequired.value =
        error instanceof HttpResponseError && error.response.status === 423;
    if (error instanceof HttpResponseError) {
        if (error.response.status === 403) {
            blockFeeAccess();
            return;
        }
        try {
            const data: unknown = JSON.parse(error.response.data);
            if (
                data &&
                typeof data === 'object' &&
                'message' in data &&
                typeof data.message === 'string'
            ) {
                actionMessage.value = `${data.message} The original attempt remains retained. Check its outcome or stop it safely before a new review.`;
                return;
            }
        } catch {
            /* Retain the attempt when a response cannot be read. */
        }
    }
    actionMessage.value =
        'The fee action remains unresolved. Keep its reference and original values. Check its outcome, retry the saved action, or stop it safely; a missing response does not authorize a new adjustment.';
}

async function checkActionOutcome(): Promise<void> {
    const pending = pendingAction.value;
    if (feeAccessBlocked.value || !pending || actionBusy.value) return;
    try {
        assertRetained(pending);
        const result = await outcomeRequest.get(
            attemptStatus.url({
                obligation: pending.obligation_id,
                attemptReference: pending.attempt_reference,
            }),
        );
        if (feeAccessBlocked.value) return;
        if (!resolveAttempt(result, pending))
            actionMessage.value =
                'The original attempt is prepared and has no recorded outcome. Retry its saved values or stop it safely before a new review.';
    } catch (error) {
        reportAttemptError(error);
    }
}

async function stopAction(): Promise<void> {
    const pending = pendingAction.value;
    if (
        feeAccessBlocked.value ||
        !pending ||
        actionBusy.value ||
        storageBlocked.value
    )
        return;
    try {
        assertRetained(pending);
        Object.assign(cancelRequest, instructions(pending));
        const result = await cancelRequest.post(
            cancelAttempt.url(pending.obligation_id),
        );
        if (feeAccessBlocked.value) return;
        if (!result) {
            actionMessage.value = `${Object.values(cancelRequest.errors).flat().join(' ')} The original attempt remains retained. Check its outcome or stop it safely before a new review.`;
            return;
        }
        if (!resolveAttempt(result, pending))
            throw new Error('Cancellation not terminal');
    } catch (error) {
        reportAttemptError(error);
    }
}

async function submitAction(): Promise<void> {
    if (
        feeAccessBlocked.value ||
        actionBusy.value ||
        storageBlocked.value ||
        savingsAttemptPending.value
    )
        return;
    let pending = pendingAction.value;
    if (!pending) {
        if (!selectedObligation.value) return;
        pending = {
            schema_version: 1,
            actor_id: page.props.auth.user.id,
            obligation_id: selectedObligation.value.id,
            action: action.value,
            attempt_reference: form.attempt_reference,
            payload: {
                amount_ngn: String(form.amount_ngn),
                direction: form.direction,
                reason: form.reason.trim(),
                customer_description: form.customer_description.trim(),
            },
        };
        if (!validPending(pending)) {
            actionMessage.value =
                'Enter a positive NGN amount with at most two decimals, an internal reason and a Customer disclosure (up to 500 characters each).';
            return;
        }
        try {
            sessionStorage.setItem(storageKey, JSON.stringify(pending));
            if (sessionStorage.getItem(storageKey) !== JSON.stringify(pending))
                throw new Error('Attempt was not saved');
        } catch {
            storageBlocked.value = true;
            actionMessage.value =
                'Your browser could not save this attempt. No adjustment was submitted.';
            return;
        }
        pendingAction.value = pending;
    }
    try {
        assertRetained(pending);
        Object.assign(prepareRequest, instructions(pending));
        freshRequired.value = false;
        const prepared = await prepareRequest.post(
            prepareAttempt.url(pending.obligation_id),
        );
        if (feeAccessBlocked.value) return;
        if (!prepared) {
            actionMessage.value = `${Object.values(prepareRequest.errors).flat().join(' ')} The original attempt remains retained. Check its outcome or stop it safely before a new review.`;
            return;
        }
        if (resolveAttempt(prepared, pending)) return;
        assertRetained(pending);
    } catch (error) {
        reportAttemptError(error);
        return;
    }
    restorePending(pending);
    if (feeAccessBlocked.value) return;
    const endpoint =
        pending.action === 'waive'
            ? waiveObligation(pending.obligation_id)
            : correctObligation(pending.obligation_id);
    actionMessage.value =
        'Submitting the saved action. Its reference and values remain unchanged until the recorded outcome is verified.';
    form.post(endpoint.url, {
        preserveScroll: true,
        onHttpException: (response) => {
            if (response.status === 403) {
                blockFeeAccess();
                return false;
            }
        },
        onFinish: () => {
            void checkActionOutcome();
        },
    });
}
</script>

<template>
    <div>
        <Head title="Fees and Deductions" />

        <div v-if="feeAccessBlocked" class="space-y-6">
            <h1 class="text-[25px] font-medium tracking-tight">
                Fee workspace unavailable
            </h1>
            <div
                ref="accessNotice"
                tabindex="-1"
                role="alert"
                aria-live="assertive"
                aria-atomic="true"
                class="grid gap-3 rounded-lg border p-4 text-sm"
            >
                <p>{{ accessMessage }}</p>
                <Button
                    type="button"
                    variant="outline"
                    class="w-fit"
                    :disabled="reloadingWorkspace || actionBusy"
                    @click="reloadAuthorizedWorkspace"
                    >{{
                        reloadingWorkspace
                            ? 'Checking current access…'
                            : 'Reload current fee workspace'
                    }}</Button
                >
                <Link :href="dashboard()" class="w-fit underline"
                    >Back to Dashboard</Link
                >
            </div>
        </div>
        <div v-else class="space-y-6">
            <FeeSavingsApplicationDialog
                v-model:open="showSavingsDialog"
                :obligation="savingsObligation"
                @pending="savingsAttemptPending = $event"
            />
            <div
                v-if="actionMessage || administrativePending"
                role="status"
                class="rounded-lg border p-4 text-sm"
            >
                <p>{{ actionMessage }}</p>
                <Button
                    v-if="pendingAction"
                    variant="outline"
                    class="mt-3"
                    :disabled="actionBusy"
                    @click="reopenAction"
                    >Review pending fee action</Button
                >
            </div>
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
            >
                <div>
                    <h1 class="text-[25px] font-medium tracking-tight">
                        Fees and Deductions
                    </h1>
                    <p class="text-muted-foreground mt-1.5 text-sm">
                        This page shows outstanding fee obligations and the
                        earnings from committed ledger postings.
                    </p>
                </div>
                <Button as-child variant="outline">
                    <Link :href="registrationFeesIndex().url"
                        >Manage fee rules</Link
                    >
                </Button>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Find fee obligations</CardTitle>
                    <CardDescription
                        >Filters apply to the complete obligation register.
                        Earnings and refund payable remain business-wide ledger
                        positions.</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <form class="space-y-4" @submit.prevent="applyFilters">
                        <div class="flex flex-wrap items-end gap-4">
                            <div
                                v-for="field in textFilters"
                                :key="field.key"
                                class="w-fit space-y-1.5"
                            >
                                <Label :for="`fee-filter-${field.key}`">{{
                                    field.label
                                }}</Label>
                                <Input
                                    :id="`fee-filter-${field.key}`"
                                    v-model="filterForm[field.key]"
                                    :placeholder="field.placeholder"
                                    :aria-invalid="
                                        !!filterForm.errors[field.key]
                                    "
                                    :aria-describedby="
                                        filterForm.errors[field.key]
                                            ? `fee-filter-${field.key}-error`
                                            : undefined
                                    "
                                    class="h-11 w-56"
                                />
                                <InputError
                                    role="alert"
                                    :id="`fee-filter-${field.key}-error`"
                                    :message="filterForm.errors[field.key]"
                                />
                            </div>
                            <div class="w-fit space-y-1.5">
                                <Label for="fee-filter-date-from"
                                    >Assessed from</Label
                                >
                                <DatePicker
                                    id="fee-filter-date-from"
                                    aria-label="Assessed from"
                                    v-model="filterForm.date_from"
                                    :error-message="filterForm.errors.date_from"
                                />
                                <InputError
                                    role="alert"
                                    :message="filterForm.errors.date_from"
                                />
                            </div>
                            <div class="w-fit space-y-1.5">
                                <Label for="fee-filter-date-to"
                                    >Assessed through</Label
                                >
                                <DatePicker
                                    id="fee-filter-date-to"
                                    aria-label="Assessed through"
                                    v-model="filterForm.date_to"
                                    :error-message="filterForm.errors.date_to"
                                />
                                <InputError
                                    role="alert"
                                    :message="filterForm.errors.date_to"
                                />
                            </div>
                        </div>
                        <details :open="hasAdvancedErrors">
                            <summary class="cursor-pointer text-sm font-medium">
                                Fee terms and financial outcome filters
                            </summary>
                            <div class="mt-4 flex flex-wrap items-end gap-4">
                                <div
                                    v-for="field in selectFilters"
                                    :key="field.key"
                                    class="w-fit space-y-1.5"
                                >
                                    <Label :for="`fee-filter-${field.key}`">{{
                                        field.label
                                    }}</Label>
                                    <Select
                                        :model-value="
                                            filterForm[field.key] || '__all'
                                        "
                                        @update:model-value="
                                            filterForm[field.key] =
                                                $event === '__all'
                                                    ? ''
                                                    : String($event)
                                        "
                                    >
                                        <SelectTrigger
                                            :id="`fee-filter-${field.key}`"
                                            :aria-invalid="
                                                !!filterForm.errors[field.key]
                                            "
                                            :aria-describedby="
                                                filterForm.errors[field.key]
                                                    ? `fee-filter-${field.key}-error`
                                                    : undefined
                                            "
                                            class="w-56"
                                            ><SelectValue
                                        /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__all"
                                                >All</SelectItem
                                            >
                                            <SelectItem
                                                v-for="option in filter_options[
                                                    field.key
                                                ]"
                                                :key="option.value"
                                                :value="option.value"
                                            >
                                                {{ option.label }}
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        role="alert"
                                        :id="`fee-filter-${field.key}-error`"
                                        :message="filterForm.errors[field.key]"
                                    />
                                </div>
                            </div>
                            <p class="text-muted-foreground mt-3 text-xs">
                                The original assessment Agent is the Agent who
                                created the obligation. This Agent did not
                                always collect the cash. Refund outcomes show
                                recorded savings returns or external
                                entitlements. An entitlement does not prove that
                                cash was paid. Batch status comes from the
                                linked physical fee receipts.
                            </p>
                        </details>
                        <div class="flex flex-wrap items-end gap-4">
                            <div class="w-fit space-y-1.5">
                                <Label for="fee-filter-sort">Order</Label>
                                <Select v-model="filterForm.sort">
                                    <SelectTrigger id="fee-filter-sort"
                                        ><SelectValue
                                    /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="newest">
                                            Newest assessed first
                                        </SelectItem>
                                        <SelectItem value="oldest">
                                            Oldest assessed first
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError
                                    role="alert"
                                    :message="filterForm.errors.sort"
                                />
                            </div>
                            <div class="w-fit space-y-1.5">
                                <Label for="fee-filter-rows">Rows</Label>
                                <Select
                                    :model-value="String(filterForm.per_page)"
                                    @update:model-value="
                                        filterForm.per_page = Number($event)
                                    "
                                >
                                    <SelectTrigger id="fee-filter-rows"
                                        ><SelectValue
                                    /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="25">25</SelectItem>
                                        <SelectItem value="50">50</SelectItem>
                                        <SelectItem value="100">100</SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError
                                    role="alert"
                                    :message="filterForm.errors.per_page"
                                />
                            </div>
                            <Button
                                type="submit"
                                :disabled="filterForm.processing"
                                >{{
                                    filterForm.processing
                                        ? 'Loading obligations…'
                                        : 'Apply filters'
                                }}</Button
                            >
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="filterForm.processing"
                                @click="resetFilters"
                                >Reset filters</Button
                            >
                        </div>
                    </form>
                </CardContent>
            </Card>

            <div
                class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                :aria-busy="filterForm.processing"
            >
                <Card>
                    <CardHeader class="pb-2"
                        ><CardDescription
                            >Matching outstanding fees</CardDescription
                        ></CardHeader
                    >
                    <CardContent>
                        <p class="text-2xl font-semibold">
                            {{
                                summary.formatted_outstanding_amount ??
                                'Unavailable'
                            }}
                        </p>
                        <p class="text-muted-foreground mt-1 text-xs">
                            <template
                                v-if="
                                    summary.obligation_totals_status ===
                                    'available'
                                "
                                >{{ summary.pending_count }} obligations have an
                                unpaid balance</template
                            >
                            <template v-else
                                >Financial history is not available for
                                {{ summary.unavailable_count }} obligations. Try
                                again after the source is verified.</template
                            >
                        </p>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader class="pb-2"
                        ><CardDescription
                            >Matching fee obligations</CardDescription
                        ></CardHeader
                    >
                    <CardContent>
                        <p class="text-2xl font-semibold">
                            {{ summary.obligation_count }}
                        </p>
                        <p class="text-muted-foreground mt-1 text-xs">
                            Total for the filtered register on all pages
                        </p>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader class="pb-2"
                        ><CardDescription
                            >Business-wide lifetime net
                            earnings</CardDescription
                        ></CardHeader
                    >
                    <CardContent v-if="summary.earnings.status === 'available'">
                        <p class="text-2xl font-semibold">
                            {{ summary.earnings.formatted_lifetime_net }}
                        </p>
                        <p class="text-muted-foreground mt-1 text-xs">
                            Gross
                            {{ summary.earnings.formatted_lifetime_gross }} ·
                            refunds
                            {{ summary.earnings.formatted_lifetime_refunds }}
                        </p>
                        <p class="text-muted-foreground mt-1 text-xs">
                            Today {{ summary.earnings.formatted_today_net }} ·
                            this month
                            {{ summary.earnings.formatted_month_net }}
                        </p>
                    </CardContent>
                    <CardContent v-else>
                        <Badge variant="secondary">Unavailable</Badge>
                        <p class="text-muted-foreground mt-2 text-xs">
                            {{ summary.earnings.message }}
                        </p>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader class="pb-2"
                        ><CardDescription
                            >Refund payable</CardDescription
                        ></CardHeader
                    >
                    <CardContent
                        v-if="summary.refund_payable.status === 'available'"
                    >
                        <p class="text-2xl font-semibold">
                            {{ summary.refund_payable.formatted_amount }}
                        </p>
                        <p class="text-muted-foreground mt-1 text-xs">
                            External refund entitlements that are not paid
                        </p>
                    </CardContent>
                    <CardContent v-else>
                        <Badge variant="secondary">Unavailable</Badge>
                        <p class="text-muted-foreground mt-2 text-xs">
                            {{ summary.refund_payable.message }}
                        </p>
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Fee obligation status</CardTitle>
                    <CardDescription
                        >Balances are derived from immutable assessments,
                        settlements, waivers, and corrections. Updated
                        {{ summary.as_of }}.</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <div
                        v-if="obligations.data.length === 0"
                        class="text-muted-foreground rounded-lg border border-dashed p-8 text-center text-sm"
                    >
                        {{
                            hasFilters
                                ? 'No fee obligations match these filters.'
                                : 'No fee obligations are recorded.'
                        }}
                    </div>
                    <div v-else class="overflow-x-auto rounded-lg border">
                        <table class="w-full min-w-[850px] text-left text-sm">
                            <thead
                                class="bg-muted/40 text-muted-foreground text-xs uppercase"
                            >
                                <tr>
                                    <th class="px-4 py-3 font-medium">
                                        Customer
                                    </th>
                                    <th class="px-4 py-3 font-medium">Fee</th>
                                    <th
                                        class="px-4 py-3 text-right font-medium"
                                    >
                                        Assessed
                                    </th>
                                    <th
                                        class="px-4 py-3 text-right font-medium"
                                    >
                                        Outstanding
                                    </th>
                                    <th class="px-4 py-3 font-medium">
                                        Status
                                    </th>
                                    <th
                                        class="px-4 py-3 text-right font-medium"
                                    >
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr
                                    v-for="obligation in obligations.data"
                                    :key="obligation.id"
                                >
                                    <td class="px-4 py-3">
                                        <Link
                                            :href="
                                                showCustomer(
                                                    obligation.customer_id,
                                                ).url
                                            "
                                            class="font-medium hover:underline"
                                            >{{
                                                obligation.customer_name
                                            }}</Link
                                        >
                                        <p
                                            class="text-muted-foreground text-xs"
                                        >
                                            {{ obligation.customer_id }}
                                        </p>
                                    </td>
                                    <td class="px-4 py-3">
                                        <p>{{ obligation.rule_name }}</p>
                                        <p
                                            class="text-muted-foreground text-xs capitalize"
                                        >
                                            {{ obligation.kind }}
                                        </p>
                                        <p
                                            class="text-muted-foreground text-xs"
                                        >
                                            {{ obligation.currency }} ·
                                            {{
                                                obligation.model ??
                                                'Terms unavailable'
                                            }}
                                            · {{ obligation.source }}
                                        </p>
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono">
                                        {{
                                            obligation.formatted_amount ??
                                            'Unavailable'
                                        }}
                                        <p
                                            class="text-muted-foreground mt-1 text-xs"
                                        >
                                            Settled
                                            {{
                                                obligation.formatted_settled_amount ??
                                                'Unavailable'
                                            }}
                                        </p>
                                        <p
                                            class="text-muted-foreground text-xs"
                                        >
                                            Waived
                                            {{
                                                obligation.formatted_waived_amount ??
                                                'Unavailable'
                                            }}
                                        </p>
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono">
                                        {{
                                            obligation.formatted_outstanding_amount ??
                                            'Unavailable'
                                        }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <Badge variant="outline">{{
                                            obligation.status_label
                                        }}</Badge>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex justify-end gap-2">
                                            <Button
                                                v-if="
                                                    obligation.can_apply_savings
                                                "
                                                size="sm"
                                                variant="outline"
                                                :disabled="
                                                    savingsAttemptPending ||
                                                    administrativePending
                                                "
                                                @click="openSavings(obligation)"
                                                >Apply from savings</Button
                                            >
                                            <Button
                                                v-if="obligation.can_waive"
                                                :disabled="
                                                    savingsAttemptPending ||
                                                    administrativePending
                                                "
                                                size="sm"
                                                variant="outline"
                                                @click="
                                                    openAction(
                                                        obligation,
                                                        'waive',
                                                    )
                                                "
                                                >Waive</Button
                                            >
                                            <Button
                                                v-if="obligation.can_correct"
                                                :disabled="
                                                    savingsAttemptPending ||
                                                    administrativePending
                                                "
                                                size="sm"
                                                variant="ghost"
                                                @click="
                                                    openAction(
                                                        obligation,
                                                        'correct',
                                                    )
                                                "
                                                >Correct</Button
                                            >
                                            <span
                                                v-if="
                                                    !obligation.can_waive &&
                                                    !obligation.can_correct &&
                                                    !obligation.can_apply_savings
                                                "
                                                class="text-muted-foreground text-xs"
                                                >—</span
                                            >
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <nav
                        v-if="obligations.links.length > 3"
                        class="mt-4 flex flex-wrap justify-end gap-1"
                        aria-label="Fee obligation pages"
                    >
                        <template
                            v-for="(link, index) in obligations.links"
                            :key="index"
                        >
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                preserve-scroll
                                class="rounded border px-3 py-1.5 text-xs"
                                :class="
                                    link.active
                                        ? 'bg-primary text-primary-foreground'
                                        : 'hover:bg-muted'
                                "
                                v-html="link.label"
                            />
                            <span
                                v-else
                                class="text-muted-foreground rounded border px-3 py-1.5 text-xs"
                                v-html="link.label"
                            />
                        </template>
                    </nav>
                </CardContent>
            </Card>

            <div
                class="rounded-lg border border-amber-500/30 bg-amber-500/5 p-4 text-sm"
            >
                <p class="font-medium">Financial owner availability</p>
                <p class="text-muted-foreground mt-1">
                    Earnings and refund payable use their mapped accounting
                    owners. Each unavailable position shows its reason above.
                    Assessment, settlement, waiver, correction and payout each
                    check their current permissions and financial contracts
                    again.
                </p>
            </div>
        </div>

        <Dialog
            v-if="!feeAccessBlocked"
            :open="showActionDialog"
            @update:open="!actionBusy && (showActionDialog = $event)"
        >
            <DialogContent
                class="max-h-[90dvh] overflow-y-auto"
                :show-close-button="!actionBusy"
                @escape-key-down="actionBusy && $event.preventDefault()"
                @interact-outside="actionBusy && $event.preventDefault()"
            >
                <DialogHeader>
                    <DialogTitle>{{
                        action === 'waive'
                            ? 'Waive fee balance'
                            : 'Correct unsettled assessment'
                    }}</DialogTitle>
                    <DialogDescription>
                        {{ selectedObligation?.customer_name }} ·
                        {{ selectedObligation?.formatted_outstanding_amount }}
                        currently outstanding. Waivers and reductions decrease
                        unpaid fees; increases raise an unsettled assessment.
                        This action changes the fee balance and is audited.
                    </DialogDescription>
                </DialogHeader>
                <p v-if="pendingAction" role="status" class="text-sm">
                    Saved {{ pendingAction.action }} for obligation
                    {{ pendingAction.obligation_id }}. Reference
                    {{ pendingAction.attempt_reference }}. Submitted values are
                    locked until its outcome is verified.
                </p>
                <p v-if="actionMessage" role="status" class="text-sm">
                    {{ actionMessage }}
                </p>
                <form class="space-y-4" @submit.prevent="submitAction">
                    <fieldset
                        :disabled="administrativePending || actionBusy"
                        class="space-y-4"
                    >
                        <div class="space-y-1.5">
                            <template v-if="action === 'correct'">
                                <Label for="fee-action-direction"
                                    >Correction direction</Label
                                >
                                <Select v-model="form.direction">
                                    <SelectTrigger
                                        id="fee-action-direction"
                                        :aria-invalid="!!form.errors.direction"
                                        aria-describedby="fee-action-direction-error"
                                        class="w-full"
                                        ><SelectValue
                                    /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="reduce">
                                            Reduce assessed fee
                                        </SelectItem>
                                        <SelectItem value="increase">
                                            Increase assessed fee
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <p
                                    v-if="form.errors.direction"
                                    id="fee-action-direction-error"
                                    role="alert"
                                    class="text-destructive text-xs"
                                >
                                    {{ form.errors.direction }}
                                </p>
                            </template>
                            <Label for="fee-action-amount">Amount (NGN)</Label>
                            <Input
                                id="fee-action-amount"
                                :aria-invalid="!!form.errors.amount_ngn"
                                aria-describedby="fee-action-amount-error"
                                inputmode="decimal"
                                v-model="form.amount_ngn"
                                type="number"
                                min="0.01"
                                step="0.01"
                                required
                            />
                            <p
                                v-if="form.errors.amount_ngn"
                                id="fee-action-amount-error"
                                role="alert"
                                class="text-destructive text-xs"
                            >
                                {{ form.errors.amount_ngn }}
                            </p>
                        </div>
                        <div class="space-y-1.5">
                            <Label for="fee-action-description"
                                >Customer disclosure</Label
                            >
                            <Input
                                id="fee-action-description"
                                :aria-invalid="
                                    !!form.errors.customer_description
                                "
                                aria-describedby="fee-action-description-error"
                                v-model="form.customer_description"
                                maxlength="500"
                                required
                            />
                            <p
                                v-if="form.errors.customer_description"
                                id="fee-action-description-error"
                                role="alert"
                                class="text-destructive text-xs"
                            >
                                {{ form.errors.customer_description }}
                            </p>
                        </div>
                        <div class="space-y-1.5">
                            <Label for="fee-action-reason"
                                >Internal reason</Label
                            >
                            <textarea
                                id="fee-action-reason"
                                :aria-invalid="!!form.errors.reason"
                                aria-describedby="fee-action-reason-error"
                                v-model="form.reason"
                                rows="3"
                                maxlength="500"
                                required
                                class="border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:outline-none"
                            />
                            <p
                                v-if="form.errors.reason"
                                id="fee-action-reason-error"
                                role="alert"
                                class="text-destructive text-xs"
                            >
                                {{ form.errors.reason }}
                            </p>
                        </div>
                        <p
                            v-if="form.errors.attempt_reference"
                            class="text-destructive text-xs"
                        >
                            {{ form.errors.attempt_reference }}
                        </p>
                    </fieldset>
                    <p
                        v-if="pendingAction"
                        class="text-muted-foreground text-sm"
                    >
                        Stopping this attempt cannot undo a recorded adjustment.
                        The server will verify any winning commit or prevent
                        later requests before permitting a new review.
                    </p>
                    <Button v-if="freshRequired" as-child variant="outline"
                        ><Link :href="freshAuthentication()"
                            >Confirm password and authenticator</Link
                        ></Button
                    >
                    <DialogFooter class="flex-wrap">
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="actionBusy"
                            @click="showActionDialog = false"
                            >{{ pendingAction ? 'Hide' : 'Cancel' }}</Button
                        >
                        <Button
                            v-if="pendingAction"
                            type="button"
                            variant="outline"
                            :disabled="actionBusy || storageBlocked"
                            @click="stopAction"
                            >Stop pending action</Button
                        >
                        <Button
                            v-if="pendingAction"
                            type="button"
                            variant="outline"
                            :disabled="actionBusy"
                            @click="checkActionOutcome"
                            >Check outcome</Button
                        >
                        <Button
                            type="submit"
                            :disabled="actionBusy || storageBlocked"
                            >{{
                                actionBusy
                                    ? 'Checking…'
                                    : pendingAction
                                      ? 'Retry saved action'
                                      : 'Confirm'
                            }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
