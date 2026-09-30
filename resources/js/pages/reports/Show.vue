<script setup lang="ts">
import { Head, Link, router, usePage, usePoll, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { DatePicker } from '@/components/ui/date-picker';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
    primary: 'Report results',
    recorded_activity: 'Recorded receipt activity',
    business_cash: 'Business cash custody',
    batch_reconciliation: 'Cash batch reconciliation',
    external_receipts: 'External fee receipts',
    fee_obligations: 'Outstanding fee obligations',
    custody_batches: 'Cash batches needing reconciliation',
    refund_payables: 'Refund payables',
    funding_progress: 'Current plan funding progress',
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
        'The report could not be verified. Refresh to start a new run.';
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
    if (value === null || value === undefined) return '—';
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    return String(value);
}
</script>

<template>
    <div class="space-y-6">
        <Head :title="definition.title" />
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-[25px] font-medium tracking-tight">
                    {{ definition.title }}
                </h1>
                <p class="text-muted-foreground mt-1.5 text-sm">
                    {{ definition.basis }}
                </p>
            </div>
            <div class="flex gap-2">
                <Button variant="outline" :disabled="pending" @click="refresh"
                    >Refresh report</Button
                >
                <Button
                    v-if="definition.export_available"
                    variant="outline"
                    :disabled="exportForm.processing || !exportForm.confirmed"
                    @click="createExport('csv')"
                    >Export CSV</Button
                >
                <Button
                    v-if="definition.export_available"
                    variant="outline"
                    :disabled="exportForm.processing || !exportForm.confirmed"
                    @click="createExport('pdf')"
                    >Export PDF</Button
                >
            </div>
        </header>
        <label v-if="definition.export_available" class="flex gap-3 text-sm"
            ><input v-model="exportForm.confirmed" type="checkbox" />I confirm a
            private export using the displayed filters and disclosed
            coverage.</label
        >
        <p
            v-for="(error, key) in exportForm.errors"
            :key="key"
            class="text-destructive text-sm"
        >
            {{ error }}
        </p>
        <p id="export-reason" class="text-muted-foreground text-sm">
            {{ definition.export_reason }}
        </p>
        <form class="space-y-4" @submit.prevent="apply">
            <div class="flex flex-row flex-wrap gap-4">
                <div v-if="definition.activity" class="w-fit space-y-2">
                    <Label for="report-from">From</Label
                    ><DatePicker id="report-from" v-model="filters.from" />
                </div>
                <div v-if="definition.activity" class="w-fit space-y-2">
                    <Label for="report-to">To</Label
                    ><DatePicker id="report-to" v-model="filters.to" />
                </div>
                <div
                    v-for="field in availableFilters"
                    :key="field"
                    class="w-fit space-y-2"
                >
                    <Label :for="`report-${field}`">{{
                        field.replaceAll('_', ' ')
                    }}</Label>
                    <select
                        v-if="field === 'customer_status'"
                        :id="`report-${field}`"
                        v-model="filters.customer_status"
                        class="border-input bg-background h-11 rounded-md border px-3 text-sm"
                    >
                        <option value="">All statuses</option>
                        <option
                            v-for="value in statuses"
                            :key="value"
                            :value="value"
                        >
                            {{ value }}
                        </option>
                    </select>
                    <select
                        v-else-if="field === 'plan_status'"
                        :id="`report-${field}`"
                        v-model="filters.plan_status"
                        class="border-input bg-background h-11 rounded-md border px-3 text-sm"
                    >
                        <option value="">All lifecycle states</option>
                        <option
                            v-for="value in planStatuses"
                            :key="value"
                            :value="value"
                        >
                            {{ value }}
                        </option>
                    </select>
                    <select
                        v-else-if="field === 'state'"
                        :id="`report-${field}`"
                        v-model="filters.state"
                        class="border-input bg-background h-11 rounded-md border px-3 text-sm"
                    >
                        <option value="">All workflow states</option>
                        <option
                            v-for="value in workflowStates"
                            :key="value"
                            :value="value"
                        >
                            {{ value }}
                        </option>
                    </select>
                    <select
                        v-else-if="field === 'agent_basis'"
                        :id="`report-${field}`"
                        v-model="filters.agent_basis"
                        class="border-input bg-background h-11 rounded-md border px-3 text-sm"
                    >
                        <option value="">Choose attribution</option>
                        <option
                            v-for="value in agentBases"
                            :key="value"
                            :value="value"
                        >
                            {{ value }}
                        </option>
                    </select>
                    <Input
                        v-else-if="field === 'customer'"
                        :id="`report-${field}`"
                        v-model="filters.customer"
                        placeholder="Customer public ID"
                    />
                    <Input
                        v-else-if="field === 'plan'"
                        :id="`report-${field}`"
                        v-model="filters.plan"
                        placeholder="Plan public ID"
                    />
                    <Input
                        v-else-if="field === 'agent'"
                        :id="`report-${field}`"
                        v-model="filters.agent"
                        placeholder="Agent public ID"
                    />
                </div>
                <div v-if="definition.groups.length" class="w-fit space-y-2">
                    <Label for="report-group">Group by</Label
                    ><select
                        id="report-group"
                        v-model="filters.group"
                        class="border-input bg-background h-11 rounded-md border px-3 text-sm"
                    >
                        <option value="">No grouping</option>
                        <option
                            v-for="value in definition.groups"
                            :key="value"
                            :value="value"
                        >
                            {{ value.replaceAll('_', ' ') }}
                        </option>
                    </select>
                </div>
                <div class="w-fit space-y-2">
                    <Label for="report-size">Rows per page</Label
                    ><select
                        id="report-size"
                        v-model.number="filters.page_size"
                        class="border-input bg-background h-11 rounded-md border px-3 text-sm"
                    >
                        <option
                            v-for="size in [25, 50, 100]"
                            :key="size"
                            :value="size"
                        >
                            {{ size }}
                        </option>
                    </select>
                </div>
                <Button type="submit" class="self-end" :disabled="pending"
                    >Apply filters</Button
                >
            </div>
            <div
                v-if="Object.keys(errors).length"
                role="alert"
                class="text-destructive text-sm"
            >
                <p v-for="(error, field) in errors" :key="field">{{ error }}</p>
            </div>
        </form>
        <p v-if="notice" role="status" class="text-muted-foreground text-sm">
            {{ notice }}
        </p>
        <div
            v-if="pending"
            role="status"
            aria-live="polite"
            class="animate-pulse space-y-3"
        >
            <p>Loading report…</p>
            <div class="bg-muted h-24 rounded-lg" />
        </div>
        <template v-else-if="visible">
            <Card>
                <CardContent class="space-y-2 pt-6 text-sm">
                    <p>
                        As of {{ visible.manifest.cutoff }} ·
                        {{ visible.manifest.timezone }} ·
                        {{ visible.manifest.currency }}
                    </p>
                    <p v-if="definition.activity">
                        UTC boundaries: {{ visible.manifest.utc_start }} through
                        {{ visible.manifest.utc_end_exclusive }} (exclusive).
                    </p>
                    <p>
                        Definition version
                        {{ visible.manifest.definition_version }} · Schema
                        {{ visible.manifest.schema_version }} · Ledger
                        {{ visible.manifest.owner_watermarks.ledger?.status }},
                        version
                        {{ visible.manifest.owner_watermarks.ledger?.version }},
                        watermark
                        {{
                            visible.manifest.owner_watermarks.ledger?.watermark
                        }}
                    </p>
                    <p class="text-muted-foreground">
                        {{ visible.manifest.drill_down_note }}
                    </p>
                </CardContent>
            </Card>
            <Card v-for="(section, code) in visible.sections" :key="code">
                <CardHeader
                    ><CardTitle class="flex items-center justify-between gap-3"
                        >{{
                            definition.code === 'fees' && code === 'primary'
                                ? 'Obligation activity'
                                : (secondaryTitles[code] ?? code)
                        }}
                        <Badge variant="secondary">{{
                            section.status
                        }}</Badge></CardTitle
                    ></CardHeader
                >
                <CardContent class="space-y-5">
                    <p class="text-muted-foreground text-sm">
                        {{ section.reason }}
                    </p>
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        <div
                            v-for="metric in section.metrics"
                            :key="metric.code"
                            class="rounded-lg border p-4"
                        >
                            <p class="text-muted-foreground text-sm">
                                {{ metric.title }}
                            </p>
                            <p class="mt-2 text-2xl font-medium tabular-nums">
                                {{ metric.display }}
                            </p>
                            <details class="text-muted-foreground mt-2 text-xs">
                                <summary class="cursor-pointer">
                                    Definition and basis
                                </summary>
                                <p class="mt-2">
                                    {{ metric.definition }}
                                    {{ metric.scope_note }}
                                </p>
                                <p>
                                    {{ metric.source }} ·
                                    {{ metric.date_basis }}
                                </p>
                            </details>
                        </div>
                    </div>
                    <p
                        v-if="section.total !== null"
                        class="text-muted-foreground text-sm"
                    >
                        {{ section.total }} records across the full authorized
                        result. Totals include all pages.
                    </p>
                    <div
                        v-if="section.rows.length"
                        class="overflow-x-auto rounded-lg border"
                        tabindex="0"
                        :aria-label="`${definition.title} results, scroll horizontally if needed`"
                    >
                        <table class="w-full text-left text-sm">
                            <caption class="sr-only">
                                {{
                                    definition.title
                                }}
                                —
                                {{
                                    definition.basis
                                }}
                            </caption>
                            <thead class="bg-muted/50">
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
                                        Owner record
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
                                    <td class="p-3">
                                        <Link
                                            v-if="row.href"
                                            :href="row.href"
                                            class="underline underline-offset-4"
                                            >View record<span class="sr-only">
                                                {{
                                                    row.reference ??
                                                    row.customer
                                                }}</span
                                            ></Link
                                        ><span
                                            v-else
                                            class="text-muted-foreground"
                                            >Unavailable</span
                                        >
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p
                        v-else-if="section.total === 0"
                        role="status"
                        class="text-muted-foreground text-sm"
                    >
                        No matching records in your authorized scope.
                    </p>
                    <div
                        v-if="section.groups.length"
                        class="overflow-x-auto rounded-lg border"
                        tabindex="0"
                        aria-label="Full-result grouped totals"
                    >
                        <table class="w-full text-left text-sm">
                            <caption class="sr-only">
                                Grouped totals across all pages
                            </caption>
                            <thead class="bg-muted/50">
                                <tr>
                                    <th scope="col" class="p-3">Group</th>
                                    <th scope="col" class="p-3">Records</th>
                                    <th
                                        v-for="metric in section.metrics"
                                        :key="metric.code"
                                        scope="col"
                                        class="p-3"
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
                            >Start a new run</Button
                        >
                    </div>
                </CardContent>
            </Card>
        </template>
    </div>
</template>
