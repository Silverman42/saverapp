<script setup lang="ts">
import { Head, Link, router, usePage, usePoll, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { Download, Inbox, RefreshCw, SlidersHorizontal } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import FormSheet from '@/components/FormSheet.vue';
import MoreDetails from '@/components/MoreDetails.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
import { index, show, exportMethod as exportReport } from '@/routes/reports';
import type {
    ReportDefinition,
    ReportFilters,
    ReportResult,
} from '@/types/reports';

const props = defineProps<{
    definition: ReportDefinition;
    filters: ReportFilters;
    report: ReportResult;
    scopeSummary: { fingerprint: string; can_collect: boolean };
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Reports', href: index() },
            { title: 'Report' },
        ],
    },
});
const exportForm = useForm({
    operation_reference: crypto.randomUUID(),
    format: 'csv',
    confirmed: false,
});
function createExport(format: 'csv' | 'pdf'): void {
    if (!exportForm.confirmed) return;
    exportForm.format = format;
    exportForm
        .transform((data) => {
            const selected = { ...props.filters };
            delete selected.cursor;
            return { ...selected, ...data };
        })
        .post(exportReport.url(props.definition.code));
}
const page = usePage();
const isAdmin = computed(() => page.props.auth.user.user_type === 'admin');
const visible = ref<ReportResult | null>(props.report);
const pending = ref(false);
const notice = ref('');
const filters = ref<ReportFilters>({ ...props.filters });
const secondaryTitles: Record<string, string> = {
    primary: 'Results',
    recorded_activity: 'Payments recorded',
    business_cash: 'Business cash',
    batch_reconciliation: 'Cash batch checks',
    external_receipts: 'Fees paid in cash',
    fee_obligations: 'Unpaid fees',
    custody_batches: 'Cash batches to check',
    refund_payables: 'Refunds owed',
    payout_incidents: 'Payout issues',
    posted_payouts: 'Payouts made',
    fee_applications: 'Fees paid from savings',
    fee_refunds: 'Fee refunds',
    other_deductions: 'Other deductions',
    funding_progress: 'Plan progress',
};
const statuses = ['active', 'inactive', 'restricted', 'archived'];
const planStatuses = ['active', 'paused', 'completed', 'closed', 'cancelled'];
const workflowStates = [
    'pending_review',
    'approved',
    'processing',
    'outcome_unknown',
    'payment_failed',
    'rejected',
    'cancelled',
    'expired',
    'posted',
];
const agentBases = computed(() =>
    props.definition.code === 'reconciliation'
        ? ['custody']
        : ['contributions', 'collection-performance'].includes(
                props.definition.code,
            )
          ? ['current', 'recording']
          : ['current'],
);
const availableFilters = computed(() =>
    props.definition.filters.filter(
        (field) => isAdmin.value || !['agent', 'agent_basis'].includes(field),
    ),
);
const errors = computed(() => page.props.errors);

watch(
    () => props.filters,
    (value) => {
        filters.value = { ...value };
    },
);
watch(
    () => props.report,
    (value) => {
        if (value.manifest.scope_hash === props.scopeSummary.fingerprint) {
            visible.value = value;
            notice.value = '';
        }
    },
);
watch(
    () => props.scopeSummary.fingerprint,
    (value, previous) => {
        if (value !== previous) {
            visible.value = null;
            notice.value = 'Your access changed. Refreshing the report.';
            refresh();
        }
    },
);
function clearOnFailure(): void {
    visible.value = null;
    notice.value =
        'We could not load this report. Select Refresh to try again.';
}
usePoll(5000, {
    only: ['scopeSummary'],
    onHttpException: clearOnFailure,
    onNetworkError: clearOnFailure,
});
function visit(query: ReportFilters): void {
    visible.value = null;
    notice.value = '';
    router.get(
        show.url(props.definition.code, { query }),
        {},
        {
            preserveState: true,
            preserveScroll: true,
            onStart: () => {
                pending.value = true;
            },
            onFinish: () => {
                pending.value = false;
            },
            onError: clearOnFailure,
            onHttpException: clearOnFailure,
            onNetworkError: clearOnFailure,
        },
    );
}
function apply(): void {
    const { cursor: _cursor, ...query } = filters.value;
    visit(query);
}
function refresh(): void {
    const { cursor: _cursor, ...query } = props.filters;
    visit(query);
}
function next(cursor: string | null): void {
    if (cursor) visit({ ...props.filters, cursor });
}
function cell(value: string | number | boolean | null | undefined): string {
    if (value === null || value === undefined) return '-';
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    return String(value);
}
const filtersOpen = ref(false);
const exportOpen = ref(false);
const filterLabels: Record<string, string> = {
    customer: 'Customer ID',
    customer_status: 'Customer status',
    plan: 'Plan ID',
    plan_status: 'Plan status',
    state: 'Status',
    agent: 'Agent ID',
    agent_basis: 'Agent',
};
const agentBasisLabels: Record<string, string> = {
    current: 'Customer’s current agent',
    recording: 'Agent who recorded it',
    custody: 'Agent holding the cash',
};
const sectionStatusLabels: Record<string, string> = {
    Partial: 'Partly complete',
    Unavailable: 'Not available',
    'Too large': 'Too many results',
};
const summaries: Record<string, string> = {
    'customer-summary': 'Each customer’s current savings.',
    contributions: 'Payments received, by date.',
    withdrawals: 'Withdrawal requests and payouts.',
    fees: 'Fees charged, paid and still owed.',
    'collection-performance': 'How much was collected, by date.',
    reconciliation: 'Cash held by agents and the business.',
    'agent-performance': 'Each agent’s customers and collections.',
    plans: 'Plans, their status and progress.',
    exceptions: 'Items that need attention.',
};
function humanize(value: string): string {
    const words = value.replaceAll('_', ' ').replaceAll('-', ' ');
    return words.charAt(0).toUpperCase() + words.slice(1);
}
const activeFilterCount = computed(
    () =>
        availableFilters.value.filter((field) =>
            Boolean(props.filters[field as keyof ReportFilters]),
        ).length +
        (props.filters.group ? 1 : 0) +
        ((props.filters.page_size ?? 25) !== 25 ? 1 : 0),
);
function applyFromSheet(): void {
    filtersOpen.value = false;
    apply();
}
function clearSheetFilters(): void {
    for (const field of availableFilters.value) {
        (filters.value as Record<string, unknown>)[field] = '';
    }
    filters.value.group = '';
    filters.value.page_size = 25;
    filtersOpen.value = false;
    apply();
}
</script>

<template>
    <div class="space-y-6">
        <Head :title="definition.title" />
        <PageHeader
            :title="definition.title"
            :description="summaries[definition.code] ?? definition.basis"
        >
            <template #actions>
                <Button variant="outline" :disabled="pending" @click="refresh"
                    ><RefreshCw
                        class="size-4"
                        :class="pending ? 'animate-spin' : ''"
                    />Refresh</Button
                >
                <Button
                    v-if="definition.export_available"
                    @click="exportOpen = true"
                    ><Download class="size-4" />Download</Button
                >
            </template>
        </PageHeader>

        <form
            class="flex flex-row flex-wrap items-end gap-4"
            aria-label="Report filters"
            @submit.prevent="apply"
        >
            <template v-if="definition.activity">
                <div class="w-fit space-y-2">
                    <Label for="report-from">From</Label
                    ><DatePicker
                        id="report-from"
                        v-model="filters.from"
                        aria-label="From"
                    />
                </div>
                <div class="w-fit space-y-2">
                    <Label for="report-to">To</Label
                    ><DatePicker
                        id="report-to"
                        v-model="filters.to"
                        aria-label="To"
                    />
                </div>
                <Button type="submit" :disabled="pending">Show</Button>
            </template>
            <Button type="button" variant="outline" @click="filtersOpen = true">
                <SlidersHorizontal class="size-4" />
                Filters
                <span
                    v-if="activeFilterCount > 0"
                    class="bg-primary text-primary-foreground inline-flex size-5 items-center justify-center rounded-full text-[11px]"
                    >{{ activeFilterCount }}</span
                >
            </Button>
        </form>

        <FormSheet
            v-model:open="filtersOpen"
            title="Filters"
            description="Narrow down what this report shows."
        >
            <div class="grid gap-5">
                <div
                    v-for="field in availableFilters"
                    :key="field"
                    class="grid gap-2"
                >
                    <Label :for="`report-${field}`">{{
                        filterLabels[field] ?? humanize(field)
                    }}</Label>
                    <Select
                        v-if="field === 'customer_status'"
                        :model-value="filters.customer_status || '__all'"
                        @update:model-value="
                            filters.customer_status =
                                $event === '__all' ? '' : String($event ?? '')
                        "
                        ><SelectTrigger :id="`report-${field}`" class="w-full"
                            ><SelectValue /></SelectTrigger
                        ><SelectContent
                            ><SelectItem value="__all">All statuses</SelectItem
                            ><SelectItem
                                v-for="value in statuses"
                                :key="value"
                                :value="value"
                                >{{ humanize(value) }}</SelectItem
                            ></SelectContent
                        ></Select
                    >
                    <Select
                        v-else-if="field === 'plan_status'"
                        :model-value="filters.plan_status || '__all'"
                        @update:model-value="
                            filters.plan_status =
                                $event === '__all' ? '' : String($event ?? '')
                        "
                        ><SelectTrigger :id="`report-${field}`" class="w-full"
                            ><SelectValue /></SelectTrigger
                        ><SelectContent
                            ><SelectItem value="__all">All statuses</SelectItem
                            ><SelectItem
                                v-for="value in planStatuses"
                                :key="value"
                                :value="value"
                                >{{ humanize(value) }}</SelectItem
                            ></SelectContent
                        ></Select
                    >
                    <Select
                        v-else-if="field === 'state'"
                        :model-value="filters.state || '__all'"
                        @update:model-value="
                            filters.state =
                                $event === '__all' ? '' : String($event ?? '')
                        "
                        ><SelectTrigger :id="`report-${field}`" class="w-full"
                            ><SelectValue /></SelectTrigger
                        ><SelectContent
                            ><SelectItem value="__all">All statuses</SelectItem
                            ><SelectItem
                                v-for="value in workflowStates"
                                :key="value"
                                :value="value"
                                >{{ humanize(value) }}</SelectItem
                            ></SelectContent
                        ></Select
                    >
                    <Select
                        v-else-if="field === 'agent_basis'"
                        :model-value="filters.agent_basis || '__all'"
                        @update:model-value="
                            filters.agent_basis =
                                $event === '__all' ? '' : String($event ?? '')
                        "
                        ><SelectTrigger :id="`report-${field}`" class="w-full"
                            ><SelectValue /></SelectTrigger
                        ><SelectContent
                            ><SelectItem value="__all">Choose one</SelectItem
                            ><SelectItem
                                v-for="value in agentBases"
                                :key="value"
                                :value="value"
                                >{{
                                    agentBasisLabels[value] ?? humanize(value)
                                }}</SelectItem
                            ></SelectContent
                        ></Select
                    >
                    <Input
                        v-else-if="field === 'customer'"
                        :id="`report-${field}`"
                        v-model="filters.customer"
                        placeholder="CUS-…"
                    />
                    <Input
                        v-else-if="field === 'plan'"
                        :id="`report-${field}`"
                        v-model="filters.plan"
                        placeholder="Plan ID"
                    />
                    <Input
                        v-else-if="field === 'agent'"
                        :id="`report-${field}`"
                        v-model="filters.agent"
                        placeholder="AGT-…"
                    />
                </div>
                <div v-if="definition.groups.length" class="grid gap-2">
                    <Label for="report-group">Group by</Label
                    ><Select
                        :model-value="filters.group || '__all'"
                        @update:model-value="
                            filters.group =
                                $event === '__all' ? '' : String($event ?? '')
                        "
                        ><SelectTrigger id="report-group" class="w-full"
                            ><SelectValue /></SelectTrigger
                        ><SelectContent
                            ><SelectItem value="__all">No grouping</SelectItem
                            ><SelectItem
                                v-for="value in definition.groups"
                                :key="value"
                                :value="value"
                                >{{ humanize(value) }}</SelectItem
                            ></SelectContent
                        ></Select
                    >
                </div>
                <div class="grid gap-2">
                    <Label for="report-size">Rows per page</Label
                    ><Select
                        :model-value="String(filters.page_size ?? 25)"
                        @update:model-value="filters.page_size = Number($event)"
                        ><SelectTrigger id="report-size" class="w-full"
                            ><SelectValue /></SelectTrigger
                        ><SelectContent
                            ><SelectItem
                                v-for="size in [25, 50, 100]"
                                :key="size"
                                :value="String(size)"
                                >{{ size }}</SelectItem
                            ></SelectContent
                        ></Select
                    >
                </div>
            </div>
            <template #footer>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="pending"
                    @click="clearSheetFilters"
                    >Clear</Button
                >
                <Button
                    type="button"
                    :disabled="pending"
                    @click="applyFromSheet"
                    >Show results</Button
                >
            </template>
        </FormSheet>

        <FormSheet
            v-if="definition.export_available"
            v-model:open="exportOpen"
            title="Download report"
            description="Create a private file with the filters you chose."
        >
            <div class="grid gap-5">
                <div class="bg-muted/40 flex items-start gap-3 rounded-xl p-4">
                    <Checkbox
                        id="export-confirmed"
                        v-model="exportForm.confirmed"
                        aria-describedby="export-reason"
                    />
                    <Label for="export-confirmed" class="leading-5"
                        >I understand this file uses the filters shown and may
                        not include everything.</Label
                    >
                </div>
                <p
                    v-for="(error, key) in exportForm.errors"
                    :key="key"
                    role="alert"
                    class="text-destructive text-sm"
                >
                    {{ error }}
                </p>
                <MoreDetails label="About downloads">
                    <p
                        id="export-reason"
                        class="text-muted-foreground text-xs leading-5"
                    >
                        {{ definition.export_reason }}
                    </p>
                </MoreDetails>
            </div>
            <template #footer>
                <Button
                    variant="outline"
                    :disabled="exportForm.processing || !exportForm.confirmed"
                    @click="createExport('csv')"
                    >Create CSV</Button
                >
                <Button
                    :disabled="exportForm.processing || !exportForm.confirmed"
                    @click="createExport('pdf')"
                    >Create PDF</Button
                >
            </template>
        </FormSheet>

        <div
            v-if="Object.keys(errors).length"
            role="alert"
            class="text-destructive text-sm"
        >
            <p v-for="(error, field) in errors" :key="field">{{ error }}</p>
        </div>
        <p v-if="notice" role="status" class="text-muted-foreground text-sm">
            {{ notice }}
        </p>
        <div v-if="pending" role="status" aria-live="polite" class="space-y-3">
            <p class="sr-only">Loading report…</p>
            <div
                class="bg-muted h-32 animate-pulse rounded-2xl motion-reduce:animate-none"
            />
            <div
                class="bg-muted h-48 animate-pulse rounded-2xl motion-reduce:animate-none"
            />
        </div>
        <template v-else-if="visible">
            <div class="-mt-2 space-y-2">
                <p class="text-muted-foreground text-xs">
                    Updated {{ visible.manifest.cutoff }}
                </p>
                <p
                    v-if="
                        visible.manifest.drill_down &&
                        !visible.manifest.drill_down.reconciled
                    "
                    role="status"
                    class="text-sm font-medium text-amber-700 dark:text-amber-400"
                >
                    {{ visible.manifest.drill_down_note }}
                </p>
            </div>
            <Card v-for="(section, code) in visible.sections" :key="code">
                <CardHeader
                    class="flex flex-row flex-wrap items-center justify-between gap-3"
                >
                    <CardTitle>{{
                        definition.code === 'fees' && code === 'primary'
                            ? 'Fee activity'
                            : (secondaryTitles[code] ?? humanize(String(code)))
                    }}</CardTitle>
                    <Badge
                        v-if="section.status !== 'Current'"
                        variant="secondary"
                        >{{
                            sectionStatusLabels[section.status] ??
                            section.status
                        }}</Badge
                    >
                </CardHeader>
                <CardContent class="space-y-5">
                    <p
                        v-if="
                            section.status === 'Unavailable' ||
                            section.status === 'Too large'
                        "
                        class="text-muted-foreground text-sm"
                    >
                        {{ section.reason }}
                    </p>
                    <div
                        v-if="section.metrics.length"
                        class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
                    >
                        <div
                            v-for="metric in section.metrics"
                            :key="metric.code"
                            class="bg-muted/40 rounded-xl p-4"
                        >
                            <p class="text-muted-foreground text-sm">
                                {{ metric.title }}
                            </p>
                            <p
                                class="mt-2 text-2xl font-semibold break-words tabular-nums"
                            >
                                {{ metric.display }}
                            </p>
                        </div>
                    </div>
                    <p
                        v-if="section.total !== null && section.total > 0"
                        class="text-muted-foreground text-sm"
                    >
                        {{ section.total }} records in total. Totals include
                        every page.
                    </p>
                    <div
                        v-if="section.rows.length"
                        class="overflow-x-auto rounded-xl border"
                        tabindex="0"
                        :aria-label="`${definition.title} results, scroll sideways if needed`"
                    >
                        <table class="w-full text-left text-sm">
                            <caption class="sr-only">
                                {{
                                    definition.title
                                }}
                                results
                            </caption>
                            <thead class="text-muted-foreground text-xs">
                                <tr>
                                    <th
                                        v-for="(
                                            title, field
                                        ) in section.columns"
                                        :key="field"
                                        scope="col"
                                        class="p-3 font-medium whitespace-nowrap"
                                    >
                                        {{ title }}
                                    </th>
                                    <th scope="col" class="p-3">
                                        <span class="sr-only">Details</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in section.rows"
                                    :key="row.key"
                                    class="border-t"
                                >
                                    <td
                                        v-for="(_, field) in section.columns"
                                        :key="field"
                                        class="p-3 whitespace-nowrap tabular-nums"
                                    >
                                        {{ cell(row[field]) }}
                                    </td>
                                    <td class="p-3 text-right">
                                        <Link
                                            v-if="row.href"
                                            :href="row.href"
                                            class="font-medium underline-offset-4 hover:underline"
                                            >View<span class="sr-only">
                                                {{
                                                    row.reference ??
                                                    row.customer
                                                }}</span
                                            ></Link
                                        >
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <EmptyState
                        v-else-if="section.total === 0"
                        role="status"
                        :icon="Inbox"
                        title="Nothing to show"
                        description="Nothing matches these filters. Try changing them."
                    />
                    <div
                        v-if="section.groups.length"
                        class="overflow-x-auto rounded-xl border"
                        tabindex="0"
                        aria-label="Totals by group"
                    >
                        <table class="w-full text-left text-sm">
                            <caption class="sr-only">
                                Totals by group, across all pages
                            </caption>
                            <thead class="text-muted-foreground text-xs">
                                <tr>
                                    <th scope="col" class="p-3 font-medium">
                                        Group
                                    </th>
                                    <th scope="col" class="p-3 font-medium">
                                        Records
                                    </th>
                                    <th
                                        v-for="metric in section.metrics"
                                        :key="metric.code"
                                        scope="col"
                                        class="p-3 font-medium"
                                    >
                                        {{ metric.title }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="group in section.groups"
                                    :key="group.label"
                                    class="border-t"
                                >
                                    <th scope="row" class="p-3 font-normal">
                                        {{ group.label }}
                                    </th>
                                    <td class="p-3 tabular-nums">
                                        {{ group.count }}
                                    </td>
                                    <td
                                        v-for="metric in group.metrics"
                                        :key="metric.code"
                                        class="p-3 whitespace-nowrap tabular-nums"
                                    >
                                        {{ metric.display }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div
                        v-if="section.next_cursor"
                        class="flex flex-wrap gap-3"
                    >
                        <Button
                            variant="outline"
                            @click="next(section.next_cursor)"
                            >Next page</Button
                        ><Button variant="ghost" @click="refresh"
                            >Back to first page</Button
                        >
                    </div>
                    <MoreDetails
                        v-if="
                            section.metrics.length ||
                            (section.reason &&
                                section.status !== 'Unavailable' &&
                                section.status !== 'Too large')
                        "
                        label="About these numbers"
                    >
                        <div
                            class="text-muted-foreground space-y-3 text-xs leading-5"
                        >
                            <p
                                v-if="
                                    section.status !== 'Unavailable' &&
                                    section.status !== 'Too large'
                                "
                            >
                                {{ section.reason }}
                            </p>
                            <dl class="space-y-2">
                                <div
                                    v-for="metric in section.metrics"
                                    :key="metric.code"
                                >
                                    <dt class="text-foreground font-medium">
                                        {{ metric.title }}
                                    </dt>
                                    <dd>
                                        {{ metric.definition }}
                                        {{ metric.scope_note }}
                                        {{ metric.source }} ·
                                        {{ metric.date_basis }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </MoreDetails>
                </CardContent>
            </Card>
            <MoreDetails label="About this report">
                <dl
                    class="text-muted-foreground grid gap-3 text-xs leading-5 sm:grid-cols-2"
                >
                    <div class="sm:col-span-2">
                        <dt class="text-foreground">What it covers</dt>
                        <dd>{{ definition.basis }}. {{ definition.reason }}</dd>
                    </div>
                    <div>
                        <dt class="text-foreground">Time zone and currency</dt>
                        <dd>
                            {{ visible.manifest.timezone }} ·
                            {{ visible.manifest.currency }}
                        </dd>
                    </div>
                    <div v-if="definition.activity">
                        <dt class="text-foreground">Exact time range (UTC)</dt>
                        <dd>
                            {{ visible.manifest.utc_start }} up to
                            {{ visible.manifest.utc_end_exclusive }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-foreground">Versions</dt>
                        <dd>
                            Report {{ visible.manifest.definition_version }} ·
                            Format {{ visible.manifest.schema_version }}
                        </dd>
                    </div>
                    <div v-if="visible.manifest.owner_watermarks.ledger">
                        <dt class="text-foreground">Records</dt>
                        <dd>
                            {{
                                visible.manifest.owner_watermarks.ledger.status
                            }}
                            · version
                            {{
                                visible.manifest.owner_watermarks.ledger.version
                            }}
                            · position
                            {{
                                visible.manifest.owner_watermarks.ledger
                                    .watermark
                            }}
                        </dd>
                    </div>
                    <div
                        v-if="
                            !visible.manifest.drill_down ||
                            visible.manifest.drill_down.reconciled
                        "
                        class="sm:col-span-2"
                    >
                        <dt class="text-foreground">Note</dt>
                        <dd>{{ visible.manifest.drill_down_note }}</dd>
                    </div>
                </dl>
            </MoreDetails>
        </template>
    </div>
</template>
