<script setup lang="ts">
import { HttpResponseError } from '@inertiajs/core';
import { Head, Link, router, useForm, useHttp, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { MoreHorizontal, Receipt, SlidersHorizontal } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import FormSheet from '@/components/FormSheet.vue';
import MoreDetails from '@/components/MoreDetails.vue';
import PageHeader from '@/components/PageHeader.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { dashboard } from '@/routes';
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
import { Card, CardContent } from '@/components/ui/card';
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
import { showToast } from '@/lib/flashToast';

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
        label: 'Current agent',
        placeholder: 'Agent ID',
    },
    {
        key: 'original_agent',
        label: 'Agent who added the fee',
        placeholder: 'Agent ID',
    },
    { key: 'cycle', label: 'Plan', placeholder: 'Plan ID' },
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
    { key: 'kind', label: 'Fee type' },
    { key: 'model', label: 'How it is charged' },
    { key: 'status', label: 'Status' },
    { key: 'source', label: 'Added from' },
    { key: 'currency', label: 'Currency' },
    { key: 'refund_status', label: 'Refund' },
    { key: 'reconciliation_status', label: 'Fee receipt batch' },
];
const hasFilters = computed(() =>
    Object.entries(props.filters).some(
        ([key, value]) => key !== 'sort' && key !== 'per_page' && value !== '',
    ),
);
const sheetFilterKeys = [
    'current_agent',
    'original_agent',
    'cycle',
    'date_from',
    'date_to',
    'kind',
    'model',
    'source',
    'currency',
    'refund_status',
    'reconciliation_status',
] as const;
const filtersOpen = ref(false);
const activeFilterCount = computed(
    () =>
        sheetFilterKeys.filter((key) => props.filters[key] !== '').length +
        (props.filters.sort !== 'newest' ? 1 : 0) +
        (Number(props.filters.per_page) !== 25 ? 1 : 0),
);
const hasSheetErrors = computed(() =>
    [...sheetFilterKeys, 'sort', 'per_page'].some(
        (key) => !!filterForm.errors[key as keyof FeeFilters],
    ),
);
watch(hasSheetErrors, (hasErrors) => {
    if (hasErrors) filtersOpen.value = true;
});
function applyFromSheet(): void {
    filtersOpen.value = false;
    applyFilters();
}
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
            { title: 'Fees', href: feesIndex() },
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
    accessMessage.value =
        'You no longer have access to fees. Anything you started is still saved. Once access is back, reload this page.';
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
                | { data?: Obligation[] }
                | undefined;
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
                    'We could not confirm your access. Anything you started is still saved.';
                return;
            }
            accessUnavailable.value = false;
            accessMessage.value = '';
            if (pendingAction.value) restorePending(pendingAction.value);
            actionMessage.value = pendingAction.value
                ? 'Access confirmed. You have an unfinished fee change. Check what happened to it before making another.'
                : '';
        },
        onError: () => {
            accessMessage.value =
                'We could not load this page. Anything you started is still saved.';
        },
        onHttpException: () => {
            blockFeeAccess();
            return false;
        },
        onNetworkError: () => {
            accessMessage.value =
                'Connection failed. Anything you started is still saved. Try again when you are back online.';
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
            'You have an unfinished fee change. Check what happened to it before making another.';
    } catch {
        storageBlocked.value = true;
        actionMessage.value =
            'Your browser cannot save fee changes right now, so new changes are turned off. Try another browser or turn off private mode.';
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
    showActionDialog.value = false;
    actionMessage.value =
        result.status === 'cancelled'
            ? 'The fee change was stopped. Nothing was saved, and you can start again.'
            : 'The fee change is saved.';
    router.reload({ only: ['summary', 'obligations'] });
    return true;
}

function reportAttemptError(error: unknown): void {
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
                actionMessage.value = `${data.message} Your change is still saved. Check what happened or stop it before trying again.`;
                return;
            }
        } catch {
            /* Retain the attempt when a response cannot be read. */
        }
    }
    actionMessage.value =
        'We did not get a reply, so we do not know if the change went through. Check what happened, try again, or stop it.';
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
                'The change has not gone through yet. Try again or stop it.';
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
            actionMessage.value = `${Object.values(cancelRequest.errors).flat().join(' ')} Your change is still saved. Check what happened or stop it before trying again.`;
            return;
        }
        if (!resolveAttempt(result, pending))
            throw new Error('Cancellation not terminal');
        showToast({
            type: 'info',
            title: 'Change stopped',
            description: 'Nothing was saved. You can start again.',
        });
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
                'Enter an amount above 0 (up to 2 decimals), a note for the customer and a reason. Notes can be up to 500 characters.';
            return;
        }
        try {
            sessionStorage.setItem(storageKey, JSON.stringify(pending));
            if (sessionStorage.getItem(storageKey) !== JSON.stringify(pending))
                throw new Error('Attempt was not saved');
        } catch {
            storageBlocked.value = true;
            actionMessage.value =
                'Your browser could not save this change, so nothing was sent.';
            return;
        }
        pendingAction.value = pending;
    }
    try {
        assertRetained(pending);
        Object.assign(prepareRequest, instructions(pending));
        const prepared = await prepareRequest.post(
            prepareAttempt.url(pending.obligation_id),
        );
        if (feeAccessBlocked.value) return;
        if (!prepared) {
            actionMessage.value = `${Object.values(prepareRequest.errors).flat().join(' ')} Your change is still saved. Check what happened or stop it before trying again.`;
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
    actionMessage.value = 'Saving your change…';
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
        <Head title="Fees" />

        <div v-if="feeAccessBlocked" class="space-y-6">
            <PageHeader title="Fees unavailable" />
            <div
                ref="accessNotice"
                tabindex="-1"
                role="alert"
                aria-live="assertive"
                aria-atomic="true"
                class="grid gap-3 rounded-xl border p-4 text-sm"
            >
                <p>{{ accessMessage }}</p>
                <div class="flex flex-wrap items-center gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="reloadingWorkspace || actionBusy"
                        @click="reloadAuthorizedWorkspace"
                        >{{
                            reloadingWorkspace ? 'Checking…' : 'Reload page'
                        }}</Button
                    >
                    <Link :href="dashboard()" class="text-sm underline"
                        >Back to Dashboard</Link
                    >
                </div>
            </div>
        </div>
        <div v-else class="space-y-6">
            <FeeSavingsApplicationDialog
                v-model:open="showSavingsDialog"
                :obligation="savingsObligation"
                @pending="savingsAttemptPending = $event"
            />
            <PageHeader
                title="Fees"
                description="See unpaid fees and what the business has earned."
            >
                <template #actions>
                    <Button as-child variant="outline">
                        <Link :href="registrationFeesIndex().url"
                            >Fee rules</Link
                        >
                    </Button>
                </template>
            </PageHeader>

            <div
                v-if="actionMessage || administrativePending"
                role="status"
                class="bg-muted flex flex-wrap items-center justify-between gap-3 rounded-xl p-4 text-sm"
            >
                <p>{{ actionMessage }}</p>
                <Button
                    v-if="pendingAction"
                    variant="outline"
                    size="sm"
                    :disabled="actionBusy"
                    @click="reopenAction"
                    >Review change</Button
                >
            </div>

            <Card :aria-busy="filterForm.processing">
                <CardContent class="grid gap-4 sm:grid-cols-3">
                    <div class="bg-muted/40 rounded-xl p-4">
                        <p class="text-muted-foreground text-sm">Unpaid fees</p>
                        <p class="mt-2 text-2xl font-semibold">
                            {{
                                summary.formatted_outstanding_amount ??
                                'Not available'
                            }}
                        </p>
                        <p class="text-muted-foreground mt-1 text-xs">
                            <template
                                v-if="
                                    summary.obligation_totals_status ===
                                    'available'
                                "
                                >{{ summary.pending_count }} of
                                {{ summary.obligation_count }} fees not fully
                                paid</template
                            >
                            <template v-else
                                >History missing for
                                {{ summary.unavailable_count }} fees. Try again
                                later.</template
                            >
                        </p>
                    </div>
                    <div class="bg-muted/40 rounded-xl p-4">
                        <p class="text-muted-foreground text-sm">
                            Fees earned this month
                        </p>
                        <template
                            v-if="summary.earnings.status === 'available'"
                        >
                            <p class="mt-2 text-2xl font-semibold">
                                {{ summary.earnings.formatted_month_net }}
                            </p>
                            <p class="text-muted-foreground mt-1 text-xs">
                                Today
                                {{ summary.earnings.formatted_today_net }}
                            </p>
                        </template>
                        <template v-else>
                            <Badge variant="secondary" class="mt-2"
                                >Not available</Badge
                            >
                            <p class="text-muted-foreground mt-2 text-xs">
                                {{ summary.earnings.message }}
                            </p>
                        </template>
                    </div>
                    <div class="bg-muted/40 rounded-xl p-4">
                        <p class="text-muted-foreground text-sm">
                            Refunds owed
                        </p>
                        <template
                            v-if="summary.refund_payable.status === 'available'"
                        >
                            <p class="mt-2 text-2xl font-semibold">
                                {{ summary.refund_payable.formatted_amount }}
                            </p>
                            <p class="text-muted-foreground mt-1 text-xs">
                                Not yet paid to customers
                            </p>
                        </template>
                        <template v-else>
                            <Badge variant="secondary" class="mt-2"
                                >Not available</Badge
                            >
                            <p class="text-muted-foreground mt-2 text-xs">
                                {{ summary.refund_payable.message }}
                            </p>
                        </template>
                    </div>
                    <div class="sm:col-span-3">
                        <MoreDetails>
                            <div
                                class="text-muted-foreground space-y-2 text-xs leading-5"
                            >
                                <p
                                    v-if="
                                        summary.earnings.status === 'available'
                                    "
                                >
                                    All-time fees earned:
                                    {{
                                        summary.earnings.formatted_lifetime_net
                                    }}
                                    ({{
                                        summary.earnings
                                            .formatted_lifetime_gross
                                    }}
                                    charged,
                                    {{
                                        summary.earnings
                                            .formatted_lifetime_refunds
                                    }}
                                    refunded).
                                </p>
                                <p>
                                    Earnings and refunds are for the whole
                                    business. Filters only change the list of
                                    fees. Updated {{ summary.as_of }}.
                                </p>
                            </div>
                        </MoreDetails>
                    </div>
                </CardContent>
            </Card>

            <form
                class="flex flex-row flex-wrap items-end gap-4"
                aria-label="Fee filters"
                @submit.prevent="applyFilters"
            >
                <div class="w-fit space-y-2">
                    <Label for="fee-filter-customer">Customer</Label>
                    <Input
                        id="fee-filter-customer"
                        v-model="filterForm.customer"
                        placeholder="Customer ID"
                        :aria-invalid="!!filterForm.errors.customer"
                        :aria-describedby="
                            filterForm.errors.customer
                                ? 'fee-filter-customer-error'
                                : undefined
                        "
                        class="w-56"
                    />
                    <InputError
                        role="alert"
                        id="fee-filter-customer-error"
                        :message="filterForm.errors.customer"
                    />
                </div>
                <div class="w-fit space-y-2">
                    <Label for="fee-filter-status">Status</Label>
                    <Select
                        :model-value="filterForm.status || '__all'"
                        @update:model-value="
                            filterForm.status =
                                $event === '__all' ? '' : String($event)
                        "
                    >
                        <SelectTrigger
                            id="fee-filter-status"
                            :aria-invalid="!!filterForm.errors.status"
                            :aria-describedby="
                                filterForm.errors.status
                                    ? 'fee-filter-status-error'
                                    : undefined
                            "
                            class="w-48"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__all">All</SelectItem>
                            <SelectItem
                                v-for="option in filter_options.status"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError
                        role="alert"
                        id="fee-filter-status-error"
                        :message="filterForm.errors.status"
                    />
                </div>
                <Button type="submit" :disabled="filterForm.processing">{{
                    filterForm.processing ? 'Loading…' : 'Search'
                }}</Button>
                <Button
                    type="button"
                    variant="outline"
                    @click="filtersOpen = true"
                >
                    <SlidersHorizontal class="size-4" />
                    Filters
                    <span
                        v-if="activeFilterCount > 0"
                        class="bg-primary text-primary-foreground inline-flex size-5 items-center justify-center rounded-full text-[11px]"
                        >{{ activeFilterCount }}</span
                    >
                </Button>
                <Button
                    v-if="hasFilters"
                    type="button"
                    variant="ghost"
                    :disabled="filterForm.processing"
                    @click="resetFilters"
                    >Clear</Button
                >
            </form>

            <FormSheet
                v-model:open="filtersOpen"
                title="Filters"
                description="Narrow down the list of fees."
            >
                <div class="grid gap-5">
                    <div
                        v-for="field in textFilters.filter(
                            (item) => item.key !== 'customer',
                        )"
                        :key="field.key"
                        class="grid gap-2"
                    >
                        <Label :for="`fee-filter-${field.key}`">{{
                            field.label
                        }}</Label>
                        <Input
                            :id="`fee-filter-${field.key}`"
                            v-model="filterForm[field.key]"
                            :placeholder="field.placeholder"
                            :aria-invalid="!!filterForm.errors[field.key]"
                            :aria-describedby="
                                filterForm.errors[field.key]
                                    ? `fee-filter-${field.key}-error`
                                    : undefined
                            "
                        />
                        <InputError
                            role="alert"
                            :id="`fee-filter-${field.key}-error`"
                            :message="filterForm.errors[field.key]"
                        />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="fee-filter-date-from">Added from</Label>
                            <DatePicker
                                id="fee-filter-date-from"
                                aria-label="Added from"
                                v-model="filterForm.date_from"
                                :error-message="filterForm.errors.date_from"
                            />
                            <InputError
                                role="alert"
                                :message="filterForm.errors.date_from"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="fee-filter-date-to">Added to</Label>
                            <DatePicker
                                id="fee-filter-date-to"
                                aria-label="Added to"
                                v-model="filterForm.date_to"
                                :error-message="filterForm.errors.date_to"
                            />
                            <InputError
                                role="alert"
                                :message="filterForm.errors.date_to"
                            />
                        </div>
                    </div>
                    <div
                        v-for="field in selectFilters.filter(
                            (item) => item.key !== 'status',
                        )"
                        :key="field.key"
                        class="grid gap-2"
                    >
                        <Label :for="`fee-filter-${field.key}`">{{
                            field.label
                        }}</Label>
                        <Select
                            :model-value="filterForm[field.key] || '__all'"
                            @update:model-value="
                                filterForm[field.key] =
                                    $event === '__all' ? '' : String($event)
                            "
                        >
                            <SelectTrigger
                                :id="`fee-filter-${field.key}`"
                                :aria-invalid="!!filterForm.errors[field.key]"
                                :aria-describedby="
                                    filterForm.errors[field.key]
                                        ? `fee-filter-${field.key}-error`
                                        : undefined
                                "
                                class="w-full"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__all">All</SelectItem>
                                <SelectItem
                                    v-for="option in filter_options[field.key]"
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
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="fee-filter-sort">Order</Label>
                            <Select v-model="filterForm.sort">
                                <SelectTrigger
                                    id="fee-filter-sort"
                                    class="w-full"
                                    ><SelectValue
                                /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="newest">
                                        Newest first
                                    </SelectItem>
                                    <SelectItem value="oldest">
                                        Oldest first
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError
                                role="alert"
                                :message="filterForm.errors.sort"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="fee-filter-rows">Rows per page</Label>
                            <Select
                                :model-value="String(filterForm.per_page)"
                                @update:model-value="
                                    filterForm.per_page = Number($event)
                                "
                            >
                                <SelectTrigger
                                    id="fee-filter-rows"
                                    class="w-full"
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
                    </div>
                    <MoreDetails label="What these filters mean">
                        <p class="text-muted-foreground text-xs leading-5">
                            "Agent who added the fee" may not be the agent who
                            collected the cash. A refund owed to a customer does
                            not mean it has been paid. Receipt batch status
                            comes from the paper fee receipts.
                        </p>
                    </MoreDetails>
                </div>
                <template #footer>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="filterForm.processing"
                        @click="resetFilters"
                        >Clear</Button
                    >
                    <Button
                        type="button"
                        :disabled="filterForm.processing"
                        @click="applyFromSheet"
                        >Show results</Button
                    >
                </template>
            </FormSheet>

            <section aria-labelledby="fee-list-heading" class="space-y-3">
                <h2 id="fee-list-heading" class="text-base font-medium">
                    Customer fees
                    <span class="text-muted-foreground font-normal"
                        >({{ summary.obligation_count }})</span
                    >
                </h2>
                <EmptyState
                    v-if="obligations.data.length === 0"
                    :icon="Receipt"
                    :title="
                        hasFilters
                            ? 'No fees match these filters'
                            : 'No fees yet'
                    "
                    :description="
                        hasFilters
                            ? 'Try changing or clearing the filters.'
                            : 'Fees will show here once customers are charged.'
                    "
                >
                    <Button
                        v-if="hasFilters"
                        variant="outline"
                        @click="resetFilters"
                        >Clear filters</Button
                    >
                </EmptyState>
                <div v-else class="overflow-x-auto rounded-xl border">
                    <table class="w-full min-w-[720px] text-left text-sm">
                        <thead
                            class="bg-muted/40 text-muted-foreground text-xs"
                        >
                            <tr>
                                <th class="px-4 py-3 font-medium">Customer</th>
                                <th class="px-4 py-3 font-medium">Fee</th>
                                <th class="px-4 py-3 text-right font-medium">
                                    Amount
                                </th>
                                <th class="px-4 py-3 text-right font-medium">
                                    Unpaid
                                </th>
                                <th class="px-4 py-3 font-medium">Status</th>
                                <th class="px-4 py-3 text-right font-medium">
                                    <span class="sr-only">Actions</span>
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
                                            showCustomer(obligation.customer_id)
                                                .url
                                        "
                                        class="font-medium hover:underline"
                                        >{{ obligation.customer_name }}</Link
                                    >
                                    <p class="text-muted-foreground text-xs">
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
                                </td>
                                <td class="px-4 py-3 text-right">
                                    {{
                                        obligation.formatted_amount ??
                                        'Not available'
                                    }}
                                    <p
                                        v-if="
                                            obligation.waived_amount_kobo &&
                                            obligation.formatted_waived_amount
                                        "
                                        class="text-muted-foreground mt-1 text-xs"
                                    >
                                        {{ obligation.formatted_waived_amount }}
                                        waived
                                    </p>
                                </td>
                                <td class="px-4 py-3 text-right font-medium">
                                    {{
                                        obligation.formatted_outstanding_amount ??
                                        'Not available'
                                    }}
                                </td>
                                <td class="px-4 py-3">
                                    <Badge variant="outline">{{
                                        obligation.status_label
                                    }}</Badge>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div
                                        v-if="
                                            obligation.can_waive ||
                                            obligation.can_correct ||
                                            obligation.can_apply_savings
                                        "
                                        class="flex justify-end gap-2"
                                    >
                                        <Button
                                            v-if="obligation.can_apply_savings"
                                            size="sm"
                                            variant="outline"
                                            :disabled="
                                                savingsAttemptPending ||
                                                administrativePending
                                            "
                                            @click="openSavings(obligation)"
                                            >Pay from savings</Button
                                        >
                                        <DropdownMenu
                                            :modal="false"
                                            v-if="
                                                obligation.can_waive ||
                                                obligation.can_correct
                                            "
                                        >
                                            <DropdownMenuTrigger as-child>
                                                <Button
                                                    size="icon"
                                                    variant="ghost"
                                                    :aria-label="`More actions for ${obligation.customer_name}`"
                                                    :disabled="
                                                        savingsAttemptPending ||
                                                        administrativePending
                                                    "
                                                >
                                                    <MoreHorizontal
                                                        class="size-4"
                                                    />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                <DropdownMenuItem
                                                    v-if="obligation.can_waive"
                                                    @select="
                                                        openAction(
                                                            obligation,
                                                            'waive',
                                                        )
                                                    "
                                                    >Waive fee</DropdownMenuItem
                                                >
                                                <DropdownMenuItem
                                                    v-if="
                                                        obligation.can_correct
                                                    "
                                                    @select="
                                                        openAction(
                                                            obligation,
                                                            'correct',
                                                        )
                                                    "
                                                    >Correct
                                                    amount</DropdownMenuItem
                                                >
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <nav
                    v-if="obligations.links.length > 3"
                    class="flex flex-wrap justify-end gap-1"
                    aria-label="Fee pages"
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
            </section>
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
                        action === 'waive' ? 'Waive fee' : 'Correct fee amount'
                    }}</DialogTitle>
                    <DialogDescription>
                        {{ selectedObligation?.customer_name }} owes
                        {{ selectedObligation?.formatted_outstanding_amount }}.
                        {{
                            action === 'waive'
                                ? 'Waiving lowers what they owe.'
                                : 'You can raise or lower an unpaid fee.'
                        }}
                    </DialogDescription>
                </DialogHeader>
                <p
                    v-if="pendingAction"
                    role="status"
                    class="bg-muted rounded-lg p-3 text-sm"
                >
                    This change is waiting to be confirmed, so it cannot be
                    edited.
                </p>
                <p v-if="actionMessage" role="status" class="text-sm">
                    {{ actionMessage }}
                </p>
                <form class="space-y-4" @submit.prevent="submitAction">
                    <fieldset
                        :disabled="administrativePending || actionBusy"
                        class="space-y-4"
                    >
                        <div v-if="action === 'correct'" class="space-y-1.5">
                            <Label for="fee-action-direction">Change</Label>
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
                                        Lower the fee
                                    </SelectItem>
                                    <SelectItem value="increase">
                                        Raise the fee
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
                        </div>
                        <div class="space-y-1.5">
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
                                >Note for the customer</Label
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
                                >Reason (staff only)</Label
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
                    <MoreDetails v-if="pendingAction">
                        <div
                            class="text-muted-foreground space-y-1 text-xs leading-5"
                        >
                            <p>
                                Fee #{{ pendingAction.obligation_id }} ·
                                Reference
                                {{ pendingAction.attempt_reference }}
                            </p>
                            <p>
                                Stopping cannot undo a change that already went
                                through. We check this before you can start
                                again.
                            </p>
                        </div>
                    </MoreDetails>
                    <DialogFooter class="flex-wrap">
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="actionBusy"
                            @click="showActionDialog = false"
                            >{{ pendingAction ? 'Close' : 'Cancel' }}</Button
                        >
                        <Button
                            v-if="pendingAction"
                            type="button"
                            variant="outline"
                            :disabled="actionBusy || storageBlocked"
                            @click="stopAction"
                            >Stop change</Button
                        >
                        <Button
                            v-if="pendingAction"
                            type="button"
                            variant="outline"
                            :disabled="actionBusy"
                            @click="checkActionOutcome"
                            >Check status</Button
                        >
                        <Button
                            type="submit"
                            :disabled="actionBusy || storageBlocked"
                            >{{
                                actionBusy
                                    ? 'Checking…'
                                    : pendingAction
                                      ? 'Try again'
                                      : action === 'waive'
                                        ? 'Waive fee'
                                        : 'Save'
                            }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
