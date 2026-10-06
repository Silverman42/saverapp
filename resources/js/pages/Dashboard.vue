<script setup lang="ts">
import { Head, Link, router, usePage, usePoll } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { ArrowRight, Inbox, RefreshCw, SlidersHorizontal } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import FormSheet from '@/components/FormSheet.vue';
import MoreDetails from '@/components/MoreDetails.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
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
import { dashboard as dashboardRoute } from '@/routes';
import { dashboard as customerDashboard } from '@/routes/customer';
import { dashboard as agentDashboard } from '@/routes/agent';
import { dashboard as adminDashboard } from '@/routes/admin';
import { create as createCollection } from '@/routes/customers/collections';

type Filters = {
    period: string;
    from: string;
    to: string;
    customer_status?: string;
    plan_status?: string;
    agent?: string;
    agent_basis?: string;
    page_size: number;
};
type Metric = {
    code: string;
    title: string;
    unit: string;
    display: string;
    source: string;
    date_basis: string;
    definition: string;
    drill_down: string | null;
    drill_down_reason: string;
};
type Row = {
    reference?: string;
    type?: string;
    state?: string;
    name?: string;
    customer_id?: string;
    plan_id?: string;
    remaining?: string;
    amount?: string;
    occurred_on?: string;
    committed_at?: string;
    href: string;
    can_record_cash?: boolean;
};
type Manifest = {
    cutoff: string;
    generated_at: string;
    timezone: string;
    timezone_version: number;
    ledger_watermark: number;
    projection_version: number;
    scope_hash: string;
};
type Section = Manifest & {
    status: 'Current' | 'Stale' | 'Rebuilding' | 'Unavailable' | 'Partial';
    metrics: Metric[];
    rows?: Row[];
    reason?: string;
    note?: string;
    href?: string;
    link_note?: string;
    total?: number;
    trend?: { date: string; display: string }[];
    trend_from?: string;
    trend_to?: string;
};
type Dashboard = {
    role: 'customer' | 'agent' | 'admin';
    manifest: Manifest;
    sections: Record<string, Section>;
};

const props = defineProps<{
    dashboard: Dashboard;
    filters: Filters;
    scopeSummary: { fingerprint: string; can_collect: boolean };
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: dashboardRoute() }] },
});
const page = usePage();
const visible = ref<Dashboard | null>(props.dashboard);
const pending = ref(false);
function filterValues(value: Filters): Filters {
    return {
        customer_status: '',
        plan_status: '',
        agent_basis: '',
        agent: '',
        ...value,
    };
}
const filters = ref(filterValues(props.filters));
const allOption = '__all';
const fromAllOption = (value: unknown): string =>
    value === allOption ? '' : String(value ?? '');
const titles: Record<string, string> = {
    portfolio: 'Customers and plans',
    savings: 'Savings',
    collections: 'Payments received',
    schedule: 'Today’s schedule',
    requests: 'Waiting for review',
    activity: 'Recent activity',
    custody: 'Cash on hand',
    financial_movements: 'Money in and out',
    financial_cash_position: 'Business cash',
    incidents: 'Issues to check',
    gated: 'More numbers',
};
const heading = computed(
    () =>
        ({
            customer: 'Your savings',
            agent: 'Your collections',
            admin: 'Business overview',
        })[props.dashboard.role],
);
const route = computed(
    () =>
        ({
            customer: customerDashboard,
            agent: agentDashboard,
            admin: adminDashboard,
        })[props.dashboard.role],
);
watch(
    () => props.dashboard,
    (value) => {
        visible.value = value;
    },
);
watch(
    () => props.filters,
    (value) => {
        filters.value = filterValues(value);
    },
);
watch(
    () => props.scopeSummary.fingerprint,
    (value, previous) => {
        if (value !== previous) {
            visible.value = null;
            refresh();
        }
    },
);
function clearOnFailure(response: { status: number }): void {
    if (response.status >= 400) visible.value = null;
}
function clearOnNetworkFailure(): void {
    visible.value = null;
}
// Both pollers are scoped to this page and stop when it unmounts.
usePoll(5000, {
    only: ['scopeSummary'],
    onHttpException: clearOnFailure,
    onNetworkError: clearOnNetworkFailure,
});
usePoll(60000, {
    only: ['dashboard', 'scopeSummary'],
    onHttpException: clearOnFailure,
    onNetworkError: clearOnNetworkFailure,
});
function refresh(): void {
    router.reload({
        only: ['dashboard', 'scopeSummary'],
        onStart: () => {
            pending.value = true;
        },
        onFinish: () => {
            pending.value = false;
        },
        onHttpException: clearOnFailure,
        onNetworkError: clearOnNetworkFailure,
    });
}
function applyFilters(): void {
    const query = {
        period: filters.value.period,
        ...(filters.value.period === 'custom'
            ? { from: filters.value.from, to: filters.value.to }
            : {}),
        customer_status: filters.value.customer_status || undefined,
        plan_status: filters.value.plan_status || undefined,
        agent: filters.value.agent_basis
            ? filters.value.agent || undefined
            : undefined,
        agent_basis: filters.value.agent_basis || undefined,
        page_size: filters.value.page_size,
    };
    router.get(
        route.value.url({ query }),
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
            onHttpException: clearOnFailure,
        },
    );
}
const filtersOpen = ref(false);
const activeFilterCount = computed(
    () =>
        [
            filters.value.customer_status,
            filters.value.plan_status,
            filters.value.agent_basis,
        ].filter(Boolean).length + (filters.value.page_size !== 25 ? 1 : 0),
);
const statusLabels: Record<Section['status'], string> = {
    Current: 'Up to date',
    Stale: 'May be out of date',
    Rebuilding: 'Updating',
    Unavailable: 'Not available',
    Partial: 'Partly loaded',
};
function applyFromSheet(): void {
    filtersOpen.value = false;
    applyFilters();
}
function resetFilters(): void {
    filters.value = filterValues({
        period: 'today',
        from: props.filters.from,
        to: props.filters.to,
        page_size: 25,
    });
    filtersOpen.value = false;
    applyFilters();
}
</script>

<template>
    <Head title="Dashboard" />
    <div class="flex flex-1 flex-col gap-6">
        <PageHeader
            :title="heading"
            :description="
                dashboard.role === 'customer'
                    ? 'See how your savings are growing.'
                    : 'A quick look at what is happening today.'
            "
        >
            <template #actions>
                <Button variant="outline" :disabled="pending" @click="refresh"
                    ><RefreshCw
                        class="size-4"
                        :class="pending ? 'animate-spin' : ''"
                    />
                    Refresh</Button
                >
            </template>
        </PageHeader>

        <form
            class="flex flex-row flex-wrap items-end gap-3"
            aria-label="Dashboard filters"
            @submit.prevent="applyFilters"
        >
            <div class="w-fit space-y-2">
                <Label for="period">Period</Label>
                <Select
                    v-model="filters.period"
                    @update:model-value="
                        $event !== 'custom' ? applyFilters() : undefined
                    "
                >
                    <SelectTrigger id="period"><SelectValue /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="today">Today</SelectItem>
                        <SelectItem value="week">This week</SelectItem>
                        <SelectItem value="month">This month</SelectItem>
                        <SelectItem value="custom">Pick dates</SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <template v-if="filters.period === 'custom'">
                <div class="w-fit space-y-2">
                    <Label for="from">From</Label
                    ><DatePicker id="from" v-model="filters.from" />
                </div>
                <div class="w-fit space-y-2">
                    <Label for="to">To</Label
                    ><DatePicker id="to" v-model="filters.to" />
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
            description="Narrow down what the dashboard shows."
        >
            <div class="grid gap-5">
                <div v-if="dashboard.role !== 'customer'" class="grid gap-2">
                    <Label for="customer-status">Customer status</Label>
                    <Select
                        :model-value="filters.customer_status || allOption"
                        @update:model-value="
                            filters.customer_status = fromAllOption($event)
                        "
                    >
                        <SelectTrigger id="customer-status" class="w-full"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="allOption"
                                >All except archived</SelectItem
                            >
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Inactive</SelectItem>
                            <SelectItem value="restricted"
                                >Restricted</SelectItem
                            >
                            <SelectItem value="archived">Archived</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div class="grid gap-2">
                    <Label for="plan-status">Plan status</Label>
                    <Select
                        :model-value="filters.plan_status || allOption"
                        @update:model-value="
                            filters.plan_status = fromAllOption($event)
                        "
                    >
                        <SelectTrigger id="plan-status" class="w-full"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="allOption"
                                >All plans</SelectItem
                            >
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="paused">Paused</SelectItem>
                            <SelectItem value="completed">Completed</SelectItem>
                            <SelectItem value="closed">Closed</SelectItem>
                            <SelectItem value="cancelled">Cancelled</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <template v-if="dashboard.role === 'admin'">
                    <div class="grid gap-2">
                        <Label for="agent-basis">Agent</Label>
                        <Select
                            :model-value="filters.agent_basis || allOption"
                            @update:model-value="
                                filters.agent_basis = fromAllOption($event)
                            "
                        >
                            <SelectTrigger id="agent-basis" class="w-full"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="allOption"
                                    >All agents</SelectItem
                                >
                                <SelectItem value="current"
                                    >Customer’s current agent</SelectItem
                                >
                                <SelectItem value="recording"
                                    >Agent who recorded it</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </div>
                    <div v-if="filters.agent_basis" class="grid gap-2">
                        <Label for="agent-ref">Agent ID</Label
                        ><Input
                            id="agent-ref"
                            v-model="filters.agent"
                            placeholder="AGT-…"
                        />
                    </div>
                </template>
                <div class="grid gap-2">
                    <Label for="page-size">Rows per list</Label
                    ><Select
                        :model-value="String(filters.page_size)"
                        @update:model-value="filters.page_size = Number($event)"
                    >
                        <SelectTrigger id="page-size" class="w-full"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="size in [25, 50, 100]"
                                :key="size"
                                :value="String(size)"
                            >
                                {{ size }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>
            <template #footer>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="pending"
                    @click="resetFilters"
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

        <div
            v-if="Object.keys(page.props.errors).length"
            role="alert"
            class="text-destructive text-sm"
        >
            <p v-for="(error, key) in page.props.errors" :key="key">
                {{ error }}
            </p>
        </div>
        <p
            v-if="dashboard.role === 'agent' && !scopeSummary.can_collect"
            class="bg-muted rounded-xl p-4 text-sm"
        >
            You can view records, but you can’t record payments right now.
        </p>
        <p
            role="status"
            aria-live="polite"
            class="text-muted-foreground -mt-2 text-xs"
        >
            <template v-if="visible"
                >{{ pending ? 'Refreshing…' : 'Updated' }}
                {{ visible.manifest.cutoff }}</template
            >
            <template v-else>Loading…</template>
        </p>
        <div
            v-if="!visible"
            class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"
            aria-label="Dashboard loading"
        >
            <div
                v-for="n in 3"
                :key="n"
                class="bg-muted h-32 animate-pulse rounded-2xl motion-reduce:animate-none"
            />
        </div>
        <template v-else>
            <section
                v-for="(section, code) in visible.sections"
                :key="code"
                :aria-labelledby="`section-${code}`"
            >
                <Card>
                    <CardHeader
                        class="flex flex-row flex-wrap items-center justify-between gap-3"
                    >
                        <CardTitle :id="`section-${code}`">{{
                            titles[code]
                        }}</CardTitle>
                        <Badge
                            v-if="section.status !== 'Current'"
                            variant="secondary"
                            >{{ statusLabels[section.status] }}</Badge
                        >
                    </CardHeader>
                    <CardContent class="space-y-5">
                        <p
                            v-if="section.reason"
                            class="text-muted-foreground text-sm"
                        >
                            {{ section.reason }}
                        </p>
                        <div
                            v-if="section.metrics.length"
                            class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
                        >
                            <component
                                :is="metric.drill_down ? Link : 'div'"
                                v-for="metric in section.metrics"
                                :key="metric.code"
                                :href="metric.drill_down ?? undefined"
                                :aria-label="
                                    metric.drill_down
                                        ? `View report for ${metric.title}`
                                        : undefined
                                "
                                class="bg-muted/40 group flex flex-col justify-between gap-3 rounded-xl p-4 transition-colors"
                                :class="
                                    metric.drill_down
                                        ? 'hover:bg-accent/60 focus-visible:ring-ring focus-visible:ring-2 focus-visible:outline-none'
                                        : ''
                                "
                            >
                                <p class="text-muted-foreground text-sm">
                                    {{ metric.title }}
                                </p>
                                <div
                                    class="flex items-end justify-between gap-2"
                                >
                                    <p
                                        class="text-2xl font-semibold break-words"
                                        :aria-label="`${metric.display} ${metric.unit === 'NGN' ? 'NGN' : ''}`"
                                    >
                                        {{ metric.display }}
                                    </p>
                                    <ArrowRight
                                        v-if="metric.drill_down"
                                        class="text-muted-foreground group-hover:text-foreground size-4 shrink-0 transition-transform group-hover:translate-x-0.5"
                                    />
                                </div>
                            </component>
                        </div>
                        <div
                            v-if="section.rows?.length"
                            class="divide-border divide-y"
                        >
                            <div
                                v-for="row in section.rows"
                                :key="row.reference ?? row.plan_id"
                                class="flex flex-wrap items-center justify-between gap-3 py-3"
                            >
                                <div class="min-w-0">
                                    <Link
                                        :href="row.href"
                                        class="text-sm font-medium underline-offset-4 hover:underline"
                                        >{{
                                            row.reference ??
                                            row.name ??
                                            row.plan_id
                                        }}</Link
                                    >
                                    <p
                                        class="text-muted-foreground mt-0.5 text-xs"
                                    >
                                        {{ row.type ?? row.customer_id }} ·
                                        {{
                                            row.state ??
                                            row.occurred_on ??
                                            row.plan_id
                                        }}
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center gap-3">
                                    <span class="text-sm font-medium">{{
                                        row.amount ?? row.remaining
                                    }}</span>
                                    <Button
                                        v-if="
                                            code === 'schedule' &&
                                            scopeSummary.can_collect &&
                                            row.can_record_cash &&
                                            row.customer_id
                                        "
                                        as-child
                                        size="sm"
                                        variant="outline"
                                        ><Link
                                            :href="
                                                createCollection(
                                                    row.customer_id,
                                                ).url
                                            "
                                            >Record cash</Link
                                        ></Button
                                    >
                                </div>
                            </div>
                        </div>
                        <EmptyState
                            v-else-if="
                                section.rows && section.status !== 'Unavailable'
                            "
                            :icon="Inbox"
                            title="Nothing here yet"
                            description="Items will show up here when there is activity."
                        />
                        <div v-if="section.trend" class="space-y-3">
                            <h3 class="text-sm font-medium">
                                Savings received, last 30 days
                            </h3>
                            <div
                                class="max-h-64 overflow-y-auto rounded-xl border"
                            >
                                <table class="w-full text-left text-sm">
                                    <caption class="sr-only">
                                        Savings received by date, last 30 days
                                    </caption>
                                    <thead
                                        class="text-muted-foreground text-xs"
                                    >
                                        <tr>
                                            <th
                                                scope="col"
                                                class="p-3 font-medium"
                                            >
                                                Date
                                            </th>
                                            <th
                                                scope="col"
                                                class="p-3 text-right font-medium"
                                            >
                                                Amount (NGN)
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="point in section.trend"
                                            :key="point.date"
                                            class="border-t"
                                        >
                                            <th
                                                scope="row"
                                                class="p-3 font-normal"
                                            >
                                                {{ point.date }}
                                            </th>
                                            <td class="p-3 text-right">
                                                {{ point.display }}
                                            </td>
                                        </tr>
                                        <tr v-if="!section.trend.length">
                                            <td
                                                colspan="2"
                                                class="text-muted-foreground p-3"
                                            >
                                                No savings received in the last
                                                30 days.
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <Link
                            v-if="section.href"
                            :href="section.href"
                            class="inline-flex items-center gap-1 text-sm font-medium underline-offset-4 hover:underline"
                            >See all {{ section.total }} transactions
                            <ArrowRight class="size-4"
                        /></Link>
                        <MoreDetails
                            v-if="section.metrics.length || section.note"
                            label="About these numbers"
                        >
                            <div
                                class="text-muted-foreground space-y-3 text-xs leading-5"
                            >
                                <p v-if="section.note">{{ section.note }}</p>
                                <dl class="space-y-2">
                                    <div
                                        v-for="metric in section.metrics"
                                        :key="metric.code"
                                    >
                                        <dt class="text-foreground font-medium">
                                            {{ metric.title }}
                                        </dt>
                                        <dd>{{ metric.definition }}</dd>
                                    </div>
                                </dl>
                            </div>
                        </MoreDetails>
                    </CardContent>
                </Card>
            </section>
        </template>
    </div>
</template>
