<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import CollectionSettlementPanel from '@/components/CollectionSettlementPanel.vue';
import FormSheet from '@/components/FormSheet.vue';
import MoreDetails from '@/components/MoreDetails.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { DatePicker } from '@/components/ui/date-picker';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
    index as batchesIndex,
    review as reviewBatch,
} from '@/routes/collection-batches';
import { store as storeRemittance } from '@/routes/collection-batches/remittances';
import {
    resolve as resolveException,
    reopen as reopenException,
    store as storeException,
    progress as progressException,
} from '@/routes/collection-batches/exceptions';

type Page<T> = {
    data: T[];
    prev_page_url: string | null;
    next_page_url: string | null;
};
type Receipt = { id: string; tender_kobo: number };
type Remittance = { reference: string; amount_kobo: number };
type Exception = {
    id: number;
    kind: string;
    status: string;
    amount_kobo: number;
    reason: string | null;
};
type Batch = {
    id: number;
    date: string;
    revision: number;
    status: string;
    version: number;
    expected_kobo: number;
    savings_kobo: number;
    fees_kobo: number;
    remitted_kobo: number;
    outstanding_kobo: number;
    receipt_count: number;
    method_label: string;
    cash_handoff_allowed: boolean;
    settlement_pending: boolean;
    custody_account_code: string;
    timezone: string;
};
const props = defineProps<{
    batch: Batch;
    receipts: Page<Receipt> | null;
    remittances: Page<Remittance>;
    exceptions: Page<Exception>;
    resolution_records: Page<{
        id: number;
        exception_id: number;
        kind: string;
        receipt_reference: string | null;
        reversal_id: string | null;
        outstanding_kobo: number;
        cause: string;
        recorded_at: string | null;
    }> | null;
    can_manage: boolean;
    settlement_banks: {
        id: number;
        label: string;
        version: number;
        destination_key: string;
    }[];
    settlements: Page<{
        reference: string;
        amount_kobo: number;
        date: string;
        bank_reference: string;
        files: { id: number }[];
    }> | null;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Cash batches', href: batchesIndex() },
            { title: 'Batch', href: '#' },
        ],
    },
});
const money = (kobo: number): string =>
    `₦${(kobo / 100).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const label = (value: string): string => value.replaceAll('_', ' ');
const remittance = useForm({
    handoff_reference: '',
    amount_ngn: '',
    handoff_date: props.batch.date,
    receiving_location: '',
    source_attestation: '',
    batch_version: props.batch.version,
    confirmed: false,
});
const review = useForm({
    batch_version: props.batch.version,
    reason: '',
    confirmed: false,
});
const resolution = useForm({
    batch_version: props.batch.version,
    reason: '',
    confirmed: false,
    resolution_kind: 'remittance',
    receipt_reference: '',
    reversal_id: '',
});
const reopening = useForm({
    batch_version: props.batch.version,
    reason: '',
    confirmed: false,
});
const progress = useForm({
    batch_version: props.batch.version,
    status: 'investigating',
    reason: '',
    confirmed: false,
});
const investigation = useForm({
    batch_version: props.batch.version,
    kind: props.batch.cash_handoff_allowed ? 'overage' : 'missing_transfer',
    amount_ngn: '',
    reason: '',
    confirmed: false,
});
watch(
    () => props.batch.version,
    (version) => {
        remittance.batch_version = version;
        review.batch_version = version;
        resolution.batch_version = version;
        reopening.batch_version = version;
        investigation.batch_version = version;
        progress.batch_version = version;
        progress.reason = '';
        progress.confirmed = false;
    },
);

type BatchAction = 'handoff' | 'review' | 'report';
type IssueAction = 'progress' | 'close' | 'resolve' | 'reopen';
const batchSheet = ref<BatchAction | null>(null);
const issueSheet = ref<{ action: IssueAction; item: Exception } | null>(null);
const batchSheetOpen = computed({
    get: () => batchSheet.value !== null,
    set: (open: boolean) => {
        if (!open) {
            batchSheet.value = null;
        }
    },
});
const issueSheetOpen = computed({
    get: () => issueSheet.value !== null,
    set: (open: boolean) => {
        if (!open) {
            issueSheet.value = null;
        }
    },
});
const isWorkable = computed(
    () => props.batch.status !== 'open' && props.batch.status !== 'reconciled',
);
const batchActions = computed(() => {
    const actions: { key: BatchAction; label: string }[] = [];

    if (
        props.can_manage &&
        props.batch.cash_handoff_allowed &&
        isWorkable.value
    ) {
        actions.push({ key: 'handoff', label: 'Confirm cash' });
    }

    if (
        props.can_manage &&
        !props.batch.settlement_pending &&
        isWorkable.value
    ) {
        actions.push({ key: 'review', label: 'Review batch' });
    }

    if (props.can_manage && props.batch.status !== 'open') {
        actions.push({ key: 'report', label: 'Report issue' });
    }

    return actions;
});
const batchSheetTitles: Record<
    BatchAction,
    { title: string; description: string }
> = {
    handoff: {
        title: 'Confirm cash',
        description: 'Record cash an agent handed over to you.',
    },
    review: {
        title: 'Review batch',
        description: 'Check the batch and save your review.',
    },
    report: {
        title: 'Report issue',
        description: 'Flag a problem with this batch.',
    },
};
const issueSheetTitles: Record<IssueAction, string> = {
    progress: 'Update issue',
    close: 'Close issue',
    resolve: 'Resolve issue',
    reopen: 'Reopen issue',
};
function openIssue(action: IssueAction, item: Exception): void {
    resolution.clearErrors();
    reopening.clearErrors();
    progress.clearErrors();
    issueSheet.value = { action, item };
}
const closeBatchSheet = (): void => {
    batchSheet.value = null;
};
const closeIssueSheet = (): void => {
    issueSheet.value = null;
};

function confirmRemittance(): void {
    remittance.post(storeRemittance.url(props.batch.id), {
        onSuccess: closeBatchSheet,
    });
}
function submitReview(): void {
    review.post(reviewBatch.url(props.batch.id), {
        onSuccess: closeBatchSheet,
    });
}
function resolve(exceptionId: number): void {
    resolution.resolution_kind = 'remittance';
    resolution.receipt_reference = '';
    resolution.reversal_id = '';
    resolution.post(resolveException.url([props.batch.id, exceptionId]), {
        onSuccess: closeIssueSheet,
    });
}
function resolveEvidence(exceptionId: number): void {
    if (
        !resolution.confirmed ||
        resolution.processing ||
        resolution.resolution_kind === 'remittance'
    )
        return;
    resolution.post(resolveException.url([props.batch.id, exceptionId]), {
        onSuccess: closeIssueSheet,
    });
}
function advanceException(exceptionId: number, status: string): void {
    progress.status = status;
    progress.post(progressException.url([props.batch.id, exceptionId]), {
        onSuccess: closeIssueSheet,
    });
}
function reopen(exceptionId: number): void {
    reopening.post(reopenException.url([props.batch.id, exceptionId]), {
        onSuccess: closeIssueSheet,
    });
}
function report(): void {
    investigation.post(storeException.url(props.batch.id), {
        onSuccess: closeBatchSheet,
    });
}
</script>

<template>
    <Head :title="`Collection batch ${batch.date}`" />
    <div class="flex flex-col gap-6">
        <PageHeader
            :title="`Batch for ${batch.date}`"
            :description="
                batch.revision > 1
                    ? `${batch.method_label}, late additions`
                    : batch.method_label
            "
        >
            <template v-if="batchActions.length" #actions>
                <Button
                    type="button"
                    @click="batchSheet = batchActions[0].key"
                    >{{ batchActions[0].label }}</Button
                >
                <Button
                    v-if="batchActions.length === 2"
                    type="button"
                    variant="outline"
                    @click="batchSheet = batchActions[1].key"
                    >{{ batchActions[1].label }}</Button
                >
                <DropdownMenu
                    :modal="false"
                    v-else-if="batchActions.length > 2"
                >
                    <DropdownMenuTrigger as-child>
                        <Button type="button" variant="outline"
                            >More <ChevronDown class="size-4"
                        /></Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem
                            v-for="action in batchActions.slice(1)"
                            :key="action.key"
                            @select="batchSheet = action.key"
                            >{{ action.label }}</DropdownMenuItem
                        >
                    </DropdownMenuContent>
                </DropdownMenu>
            </template>
        </PageHeader>

        <p
            v-if="batch.settlement_pending"
            class="bg-muted rounded-xl p-4 text-sm"
            role="status"
        >
            You can review this batch once the bank deposit is checked.
        </p>

        <Card>
            <CardHeader
                class="flex flex-row flex-wrap items-center justify-between gap-3"
            >
                <CardTitle>Summary</CardTitle>
                <Badge variant="secondary" class="capitalize">{{
                    label(batch.status)
                }}</Badge>
            </CardHeader>
            <CardContent class="space-y-3">
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="bg-muted/40 rounded-xl p-4">
                        <p class="text-muted-foreground text-sm">Recorded</p>
                        <p class="mt-1 text-2xl font-semibold">
                            {{ money(batch.expected_kobo) }}
                        </p>
                    </div>
                    <div class="bg-muted/40 rounded-xl p-4">
                        <p class="text-muted-foreground text-sm">
                            {{
                                batch.cash_handoff_allowed
                                    ? 'Handed over'
                                    : 'Reached the bank'
                            }}
                        </p>
                        <p class="mt-1 text-2xl font-semibold">
                            {{ money(batch.remitted_kobo) }}
                        </p>
                    </div>
                    <div class="bg-muted/40 rounded-xl p-4">
                        <p class="text-muted-foreground text-sm">
                            {{
                                batch.cash_handoff_allowed
                                    ? 'Agent still holds'
                                    : 'Still to reach the bank'
                            }}
                        </p>
                        <p class="mt-1 text-2xl font-semibold">
                            {{ money(batch.outstanding_kobo) }}
                        </p>
                    </div>
                </div>
                <p class="text-muted-foreground text-xs">
                    Savings {{ money(batch.savings_kobo) }} · Fees
                    {{ money(batch.fees_kobo) }}
                </p>
            </CardContent>
        </Card>

        <Card
            v-if="
                can_manage &&
                batch.custody_account_code === 'payment_clearing_ngn'
            "
        >
            <CardContent class="grid gap-4 pt-6">
                <CollectionSettlementPanel
                    :batch-id="batch.id"
                    :batch-version="batch.version"
                    :date="batch.date"
                    :timezone="batch.timezone"
                    :outstanding-kobo="batch.outstanding_kobo"
                    :can-record="
                        batch.status !== 'open' &&
                        batch.status !== 'reconciled' &&
                        batch.outstanding_kobo > 0
                    "
                    :banks="settlement_banks"
                    :settlements="settlements?.data ?? []"
                />
                <nav
                    v-if="
                        settlements?.prev_page_url || settlements?.next_page_url
                    "
                    aria-label="Bank deposit pages"
                    class="flex gap-4 text-sm"
                >
                    <Link
                        v-if="settlements?.prev_page_url"
                        :href="settlements.prev_page_url"
                        class="underline-offset-4 hover:underline"
                        >Previous</Link
                    >
                    <Link
                        v-if="settlements?.next_page_url"
                        :href="settlements.next_page_url"
                        class="underline-offset-4 hover:underline"
                        >Next</Link
                    >
                </nav>
            </CardContent>
        </Card>

        <Card v-if="exceptions.data.length">
            <CardHeader><CardTitle>Issues</CardTitle></CardHeader>
            <CardContent>
                <ul class="divide-y">
                    <li
                        v-for="item in exceptions.data"
                        :key="item.id"
                        class="flex flex-wrap items-start justify-between gap-3 py-4 first:pt-0 last:pb-0"
                    >
                        <div class="min-w-0 text-sm">
                            <p class="flex flex-wrap items-center gap-2">
                                <span class="font-medium capitalize">{{
                                    label(item.kind)
                                }}</span>
                                <Badge variant="outline" class="capitalize">{{
                                    label(item.status)
                                }}</Badge>
                            </p>
                            <p class="mt-1">{{ money(item.amount_kobo) }}</p>
                            <p
                                v-if="item.reason"
                                class="text-muted-foreground mt-0.5 text-xs"
                            >
                                {{ item.reason }}
                            </p>
                        </div>
                        <div
                            v-if="can_manage"
                            class="flex flex-wrap items-center gap-2"
                        >
                            <Button
                                v-if="
                                    [
                                        'cash_shortage',
                                        'missing_transfer',
                                    ].includes(item.kind) &&
                                    item.status !== 'resolved'
                                "
                                type="button"
                                size="sm"
                                @click="openIssue('resolve', item)"
                                >Resolve</Button
                            >
                            <Button
                                v-if="
                                    item.kind === 'cash_shortage' &&
                                    item.status !== 'resolved' &&
                                    batch.outstanding_kobo === 0
                                "
                                type="button"
                                size="sm"
                                variant="outline"
                                @click="openIssue('close', item)"
                                >Close</Button
                            >
                            <Button
                                v-if="item.status !== 'resolved'"
                                type="button"
                                size="sm"
                                variant="outline"
                                @click="openIssue('progress', item)"
                                >Update</Button
                            >
                            <Button
                                v-if="item.status === 'resolved'"
                                type="button"
                                size="sm"
                                variant="outline"
                                @click="openIssue('reopen', item)"
                                >Reopen</Button
                            >
                        </div>
                    </li>
                </ul>
                <nav
                    v-if="exceptions.prev_page_url || exceptions.next_page_url"
                    aria-label="Issue pages"
                    class="mt-4 flex gap-4 text-sm"
                >
                    <Link
                        v-if="exceptions.prev_page_url"
                        :href="exceptions.prev_page_url"
                        class="underline-offset-4 hover:underline"
                        >Previous</Link
                    ><Link
                        v-if="exceptions.next_page_url"
                        :href="exceptions.next_page_url"
                        class="underline-offset-4 hover:underline"
                        >Next</Link
                    >
                </nav>
            </CardContent>
        </Card>

        <Card v-if="(can_manage && receipts) || batch.cash_handoff_allowed">
            <CardHeader><CardTitle>Records</CardTitle></CardHeader>
            <CardContent class="grid gap-6 md:grid-cols-2">
                <section
                    v-if="can_manage && receipts"
                    aria-labelledby="batch-receipts"
                >
                    <h3 id="batch-receipts" class="text-sm font-medium">
                        Payments ({{ batch.receipt_count }})
                    </h3>
                    <p
                        v-if="receipts.data.length === 0"
                        class="text-muted-foreground mt-2 text-sm"
                    >
                        No payments in this batch.
                    </p>
                    <ul v-else class="mt-2 divide-y text-sm">
                        <li
                            v-for="receipt in receipts.data"
                            :key="receipt.id"
                            class="flex items-center justify-between gap-3 py-2"
                        >
                            <span class="text-muted-foreground break-all">{{
                                receipt.id
                            }}</span>
                            <span class="font-medium">{{
                                money(receipt.tender_kobo)
                            }}</span>
                        </li>
                    </ul>
                    <nav
                        v-if="receipts.prev_page_url || receipts.next_page_url"
                        aria-label="Payment pages"
                        class="mt-3 flex gap-4 text-sm"
                    >
                        <Link
                            v-if="receipts.prev_page_url"
                            :href="receipts.prev_page_url"
                            class="underline-offset-4 hover:underline"
                            >Previous</Link
                        ><Link
                            v-if="receipts.next_page_url"
                            :href="receipts.next_page_url"
                            class="underline-offset-4 hover:underline"
                            >Next</Link
                        >
                    </nav>
                </section>
                <section
                    v-if="batch.cash_handoff_allowed"
                    aria-labelledby="batch-handoffs"
                >
                    <h3 id="batch-handoffs" class="text-sm font-medium">
                        Cash handed over
                    </h3>
                    <p
                        v-if="remittances.data.length === 0"
                        class="text-muted-foreground mt-2 text-sm"
                    >
                        No cash handed over yet.
                    </p>
                    <ul v-else class="mt-2 divide-y text-sm">
                        <li
                            v-for="item in remittances.data"
                            :key="item.reference"
                            class="flex items-center justify-between gap-3 py-2"
                        >
                            <span class="text-muted-foreground break-all">{{
                                item.reference
                            }}</span>
                            <span class="font-medium">{{
                                money(item.amount_kobo)
                            }}</span>
                        </li>
                    </ul>
                    <nav
                        v-if="
                            remittances.prev_page_url ||
                            remittances.next_page_url
                        "
                        aria-label="Handover pages"
                        class="mt-3 flex gap-4 text-sm"
                    >
                        <Link
                            v-if="remittances.prev_page_url"
                            :href="remittances.prev_page_url"
                            class="underline-offset-4 hover:underline"
                            >Previous</Link
                        ><Link
                            v-if="remittances.next_page_url"
                            :href="remittances.next_page_url"
                            class="underline-offset-4 hover:underline"
                            >Next</Link
                        >
                    </nav>
                </section>
            </CardContent>
        </Card>

        <MoreDetails
            v-if="
                can_manage &&
                resolution_records &&
                resolution_records.data.length
            "
            label="Resolved issue history"
        >
            <ul class="divide-y text-sm">
                <li
                    v-for="record in resolution_records.data"
                    :key="record.id"
                    class="grid gap-0.5 py-3"
                >
                    <p class="font-medium capitalize">
                        Issue {{ record.exception_id }} ·
                        {{ label(record.kind) }}
                    </p>
                    <p>{{ record.cause }}</p>
                    <p class="text-muted-foreground text-xs break-all">
                        {{ record.receipt_reference ?? 'No receipt reference'
                        }}<span v-if="record.reversal_id">
                            · {{ record.reversal_id }}</span
                        >
                        · Still owed then {{ money(record.outstanding_kobo) }}
                        <span v-if="record.recorded_at">
                            · {{ record.recorded_at }}</span
                        >
                    </p>
                </li>
            </ul>
            <nav
                v-if="
                    resolution_records.prev_page_url ||
                    resolution_records.next_page_url
                "
                aria-label="Resolved issue pages"
                class="mt-3 flex gap-4 text-sm"
            >
                <Link
                    v-if="resolution_records.prev_page_url"
                    :href="resolution_records.prev_page_url"
                    class="underline-offset-4 hover:underline"
                    >Previous</Link
                ><Link
                    v-if="resolution_records.next_page_url"
                    :href="resolution_records.next_page_url"
                    class="underline-offset-4 hover:underline"
                    >Next</Link
                >
            </nav>
        </MoreDetails>

        <FormSheet
            v-model:open="batchSheetOpen"
            :title="batchSheet ? batchSheetTitles[batchSheet].title : ''"
            :description="
                batchSheet ? batchSheetTitles[batchSheet].description : ''
            "
        >
            <form
                v-if="batchSheet === 'handoff'"
                id="batch-action-form"
                class="grid gap-5"
                @submit.prevent="confirmRemittance"
            >
                <div class="grid gap-5 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="handoff-amount">Amount counted (NGN)</Label
                        ><Input
                            id="handoff-amount"
                            v-model="remittance.amount_ngn"
                            inputmode="decimal"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="handoff-date">Date handed over</Label
                        ><DatePicker
                            id="handoff-date"
                            aria-label="Date handed over"
                            v-model="remittance.handoff_date"
                        />
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="handoff-reference">Handover reference</Label
                    ><Input
                        id="handoff-reference"
                        v-model="remittance.handoff_reference"
                    />
                    <p class="text-muted-foreground text-xs">
                        Use a reference you have not used before.
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="handoff-location">Where you received it</Label
                    ><Input
                        id="handoff-location"
                        v-model="remittance.receiving_location"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="handoff-attestation"
                        >Who counted it and where it came from</Label
                    ><Input
                        id="handoff-attestation"
                        v-model="remittance.source_attestation"
                    />
                </div>
                <label class="flex items-start gap-2 text-sm"
                    ><input
                        v-model="remittance.confirmed"
                        type="checkbox"
                        class="mt-0.5"
                    />
                    I counted and received this cash.</label
                >
                <div
                    v-if="remittance.hasErrors"
                    role="alert"
                    class="text-destructive grid gap-1 text-sm"
                >
                    <p v-for="(error, key) in remittance.errors" :key="key">
                        {{ error }}
                    </p>
                </div>
            </form>
            <form
                v-else-if="batchSheet === 'review'"
                id="batch-action-form"
                class="grid gap-5"
                @submit.prevent="submitReview"
            >
                <div class="grid gap-2">
                    <Label for="review-reason">Review note</Label
                    ><Input id="review-reason" v-model="review.reason" />
                    <p class="text-muted-foreground text-xs">
                        Say what you checked.
                    </p>
                </div>
                <label class="flex items-start gap-2 text-sm"
                    ><input
                        v-model="review.confirmed"
                        type="checkbox"
                        class="mt-0.5"
                    />
                    I reviewed this batch. If cash is short, an issue opens and
                    customer savings stay the same.</label
                >
                <div
                    v-if="review.hasErrors"
                    role="alert"
                    class="text-destructive grid gap-1 text-sm"
                >
                    <p v-for="(error, key) in review.errors" :key="key">
                        {{ error }}
                    </p>
                </div>
            </form>
            <form
                v-else-if="batchSheet === 'report'"
                id="batch-action-form"
                class="grid gap-5"
                @submit.prevent="report"
            >
                <div class="grid gap-2">
                    <Label for="exception-kind">What is wrong?</Label>
                    <Select v-model="investigation.kind">
                        <SelectTrigger id="exception-kind" class="w-full"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-if="batch.cash_handoff_allowed"
                                value="overage"
                            >
                                More cash than recorded
                            </SelectItem>
                            <SelectItem value="missing_transfer">
                                Transfer proof is missing
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div class="grid gap-2">
                    <Label for="exception-amount">Amount (NGN)</Label
                    ><Input
                        id="exception-amount"
                        v-model="investigation.amount_ngn"
                        inputmode="decimal"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="exception-reason">What happened</Label
                    ><Input
                        id="exception-reason"
                        v-model="investigation.reason"
                    />
                </div>
                <label class="flex items-start gap-2 text-sm"
                    ><input
                        v-model="investigation.confirmed"
                        type="checkbox"
                        class="mt-0.5"
                    />
                    Open an issue. This does not change customer savings.</label
                >
                <div
                    v-if="investigation.hasErrors"
                    role="alert"
                    class="text-destructive grid gap-1 text-sm"
                >
                    <p v-for="(error, key) in investigation.errors" :key="key">
                        {{ error }}
                    </p>
                </div>
            </form>
            <template #footer>
                <Button type="button" variant="outline" @click="closeBatchSheet"
                    >Cancel</Button
                >
                <Button
                    v-if="batchSheet === 'handoff'"
                    type="submit"
                    form="batch-action-form"
                    :disabled="remittance.processing || !remittance.confirmed"
                    >Confirm cash</Button
                >
                <Button
                    v-else-if="batchSheet === 'review'"
                    type="submit"
                    form="batch-action-form"
                    :disabled="review.processing || !review.confirmed"
                    >Save review</Button
                >
                <Button
                    v-else-if="batchSheet === 'report'"
                    type="submit"
                    form="batch-action-form"
                    :disabled="
                        investigation.processing || !investigation.confirmed
                    "
                    >Report issue</Button
                >
            </template>
        </FormSheet>

        <FormSheet
            v-model:open="issueSheetOpen"
            :title="issueSheet ? issueSheetTitles[issueSheet.action] : ''"
            :description="
                issueSheet
                    ? `${label(issueSheet.item.kind)}, ${money(issueSheet.item.amount_kobo)}`
                    : ''
            "
        >
            <div v-if="issueSheet" class="grid gap-5">
                <template v-if="issueSheet.action === 'progress'">
                    <div class="grid gap-2">
                        <Label :for="`progress-${issueSheet.item.id}`"
                            >What you found or what happens next</Label
                        >
                        <Input
                            :id="`progress-${issueSheet.item.id}`"
                            v-model="progress.reason"
                            maxlength="500"
                        />
                    </div>
                    <label class="flex items-start gap-2 text-sm"
                        ><input
                            v-model="progress.confirmed"
                            type="checkbox"
                            class="mt-0.5"
                        />
                        I want to save this update.</label
                    >
                    <p class="text-muted-foreground text-xs">
                        Customer savings stay the same. Any missing money still
                        needs a checked deposit or handover.
                    </p>
                    <div
                        v-if="progress.hasErrors"
                        role="alert"
                        class="text-destructive grid gap-1 text-sm"
                    >
                        <p
                            v-for="(error, field) in progress.errors"
                            :key="field"
                        >
                            {{ error }}
                        </p>
                    </div>
                </template>

                <template v-else-if="issueSheet.action === 'close'">
                    <div class="grid gap-2">
                        <Label :for="`resolution-${issueSheet.item.id}`"
                            >Reason</Label
                        ><Input
                            :id="`resolution-${issueSheet.item.id}`"
                            v-model="resolution.reason"
                        />
                    </div>
                    <label class="flex items-start gap-2 text-sm"
                        ><input
                            v-model="resolution.confirmed"
                            type="checkbox"
                            class="mt-0.5"
                        />
                        This issue can be closed.</label
                    >
                </template>

                <template v-else-if="issueSheet.action === 'resolve'">
                    <div class="grid gap-2">
                        <Label :for="`resolution-kind-${issueSheet.item.id}`"
                            >How was it resolved?</Label
                        >
                        <Select v-model="resolution.resolution_kind">
                            <SelectTrigger
                                :id="`resolution-kind-${issueSheet.item.id}`"
                                class="w-full"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="remittance">
                                    Choose one
                                </SelectItem>
                                <SelectItem
                                    v-if="
                                        issueSheet.item.kind ===
                                        'missing_transfer'
                                    "
                                    value="verified_match"
                                >
                                    Matched to a checked transfer
                                </SelectItem>
                                <SelectItem value="approved_correction">
                                    Linked to an approved correction
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="grid gap-2">
                        <Label :for="`matched-receipt-${issueSheet.item.id}`"
                            >Receipt number in this batch</Label
                        >
                        <Input
                            :id="`matched-receipt-${issueSheet.item.id}`"
                            v-model="resolution.receipt_reference"
                            maxlength="80"
                        />
                    </div>
                    <div
                        v-if="
                            resolution.resolution_kind === 'approved_correction'
                        "
                        class="grid gap-2"
                    >
                        <Label :for="`matched-reversal-${issueSheet.item.id}`"
                            >Correction number</Label
                        ><Input
                            :id="`matched-reversal-${issueSheet.item.id}`"
                            v-model="resolution.reversal_id"
                            maxlength="80"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label
                            :for="`verified-resolution-${issueSheet.item.id}`"
                            >What caused it and how it was fixed</Label
                        ><Input
                            :id="`verified-resolution-${issueSheet.item.id}`"
                            v-model="resolution.reason"
                            maxlength="500"
                        />
                    </div>
                    <label class="flex items-start gap-2 text-sm"
                        ><input
                            v-model="resolution.confirmed"
                            type="checkbox"
                            class="mt-0.5"
                        />
                        I checked these records.</label
                    >
                    <p class="text-muted-foreground text-xs">
                        This only closes the issue. Customer savings stay the
                        same and no refund is paid.
                    </p>
                </template>

                <template v-else-if="issueSheet.action === 'reopen'">
                    <div class="grid gap-2">
                        <Label :for="`reopen-${issueSheet.item.id}`"
                            >Why reopen it?</Label
                        ><Input
                            :id="`reopen-${issueSheet.item.id}`"
                            v-model="reopening.reason"
                        />
                    </div>
                    <label class="flex items-start gap-2 text-sm"
                        ><input
                            v-model="reopening.confirmed"
                            type="checkbox"
                            class="mt-0.5"
                        />
                        This issue needs to be reopened.</label
                    >
                    <div
                        v-if="reopening.hasErrors"
                        role="alert"
                        class="text-destructive grid gap-1 text-sm"
                    >
                        <p v-for="(error, key) in reopening.errors" :key="key">
                            {{ error }}
                        </p>
                    </div>
                </template>

                <div
                    v-if="
                        resolution.hasErrors &&
                        (issueSheet.action === 'close' ||
                            issueSheet.action === 'resolve')
                    "
                    role="alert"
                    class="text-destructive grid gap-1 text-sm"
                >
                    <p v-for="(error, key) in resolution.errors" :key="key">
                        {{ error }}
                    </p>
                </div>
            </div>
            <template #footer>
                <Button type="button" variant="outline" @click="closeIssueSheet"
                    >Cancel</Button
                >
                <template v-if="issueSheet">
                    <Button
                        v-if="issueSheet.action === 'progress'"
                        type="button"
                        :disabled="
                            progress.processing ||
                            !progress.confirmed ||
                            !progress.reason.trim()
                        "
                        @click="
                            advanceException(
                                issueSheet.item.id,
                                issueSheet.item.status === 'investigating'
                                    ? 'awaiting_action'
                                    : 'investigating',
                            )
                        "
                        >{{
                            issueSheet.item.status === 'investigating'
                                ? 'Mark waiting'
                                : 'Start checking'
                        }}</Button
                    >
                    <Button
                        v-else-if="issueSheet.action === 'close'"
                        type="button"
                        :disabled="
                            resolution.processing || !resolution.confirmed
                        "
                        @click="resolve(issueSheet.item.id)"
                        >Close issue</Button
                    >
                    <Button
                        v-else-if="issueSheet.action === 'resolve'"
                        type="button"
                        :disabled="
                            resolution.processing ||
                            !resolution.confirmed ||
                            !resolution.reason.trim() ||
                            !resolution.receipt_reference ||
                            resolution.resolution_kind === 'remittance'
                        "
                        @click="resolveEvidence(issueSheet.item.id)"
                        >Resolve</Button
                    >
                    <Button
                        v-else-if="issueSheet.action === 'reopen'"
                        type="button"
                        :disabled="reopening.processing || !reopening.confirmed"
                        @click="reopen(issueSheet.item.id)"
                        >Reopen</Button
                    >
                </template>
            </template>
        </FormSheet>
    </div>
</template>
