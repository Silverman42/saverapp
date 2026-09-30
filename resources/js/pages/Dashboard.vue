<script setup lang="ts">
import { Head, Link, router, usePage, usePoll } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { RefreshCw } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { DatePicker } from '@/components/ui/date-picker';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
const titles: Record<string, string> = {
    portfolio: 'Current portfolio',
    savings: 'Savings position',
    collections: 'Receipt activity',
    schedule: 'Plan progress and today’s schedule',
    requests: 'Requests awaiting review',
    activity: 'Recent posted activity',
    custody: 'Cash custody and reconciliation',
    financial_movements: 'Posted financial movements',
    financial_cash_position: 'Business cash and encumbrances',
    gated: 'Additional metrics',
};
const heading = computed(
    () =>
        ({
            customer: 'Your savings overview',
            agent: 'Your collection overview',
            admin: 'Business operations overview',
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
function resetFilters(): void {
    filters.value = filterValues({
        period: 'today',
        from: props.filters.from,
        to: props.filters.to,
        page_size: 25,
    });
    applyFilters();
}
</script>

<template>
    <Head title="Dashboard" />
    <div class="flex flex-1 flex-col gap-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <Badge variant="outline" class="mb-3"
                    >{{ dashboard.role }} dashboard</Badge
                >
                <h1 class="text-[25px] font-medium tracking-tight">
                    {{ heading }}
                </h1>
                <p class="text-muted-foreground mt-1.5 text-sm">
                    Savings, activity and current work from their owning
                    records.
                </p>
            </div>
            <Button variant="outline" :disabled="pending" @click="refresh"
                ><RefreshCw class="size-4" /> Refresh</Button
            >
        </div>
        <form
            class="flex flex-row flex-wrap items-end gap-4"
            aria-label="Dashboard filters"
            @submit.prevent="applyFilters"
        >
            <div class="w-fit space-y-2">
                <Label for="period">Activity period</Label>
                <select
                    id="period"
                    v-model="filters.period"
                    class="border-input bg-card h-11 rounded-xl border px-3 text-sm"
                >
                    <option value="today">Today</option>
                    <option value="week">This week</option>
                    <option value="month">This month</option>
                    <option value="custom">Custom dates</option>
                </select>
            </div>
            <template v-if="filters.period === 'custom'">
                <div class="w-fit space-y-2">
                    <Label for="from">From</Label
                    ><DatePicker id="from" v-model="filters.from" />
                </div>
                <div class="w-fit space-y-2">
                    <Label for="to">To (up to 366 dates)</Label
                    ><DatePicker id="to" v-model="filters.to" />
                </div>
            </template>
            <div v-if="dashboard.role !== 'customer'" class="w-fit space-y-2">
                <Label for="customer-status">Customer status</Label>
                <select
                    id="customer-status"
                    v-model="filters.customer_status"
                    class="border-input bg-card h-11 rounded-xl border px-3 text-sm"
                >
                    <option value="">Non-archived</option>
                    <option
                        v-for="status in [
                            'active',
                            'inactive',
                            'restricted',
                            'archived',
                        ]"
                        :key="status"
                        :value="status"
                    >
                        {{ status }}
                    </option>
                </select>
            </div>
            <div class="w-fit space-y-2">
                <Label for="plan-status">Plan status</Label>
                <select
                    id="plan-status"
                    v-model="filters.plan_status"
                    class="border-input bg-card h-11 rounded-xl border px-3 text-sm"
                >
                    <option value="">All plan states</option>
                    <option
                        v-for="status in [
                            'active',
                            'paused',
                            'completed',
                            'closed',
                            'cancelled',
                        ]"
                        :key="status"
                        :value="status"
                    >
                        {{ status }}
                    </option>
                </select>
            </div>
            <template v-if="dashboard.role === 'admin'">
                <div class="w-fit space-y-2">
                    <Label for="agent-basis">Agent attribution</Label>
                    <select
                        id="agent-basis"
                        v-model="filters.agent_basis"
                        class="border-input bg-card h-11 rounded-xl border px-3 text-sm"
                    >
                        <option value="">Business-wide</option>
                        <option value="current">Current Agent</option>
                        <option value="recording">Recording Agent</option>
                    </select>
                </div>
                <div v-if="filters.agent_basis" class="w-fit space-y-2">
                    <Label for="agent-ref">Agent reference</Label
                    ><Input
                        id="agent-ref"
                        v-model="filters.agent"
                        placeholder="AGT-…"
                    />
                </div>
            </template>
            <div class="w-fit space-y-2">
                <Label for="page-size">Rows shown</Label
                ><select
                    id="page-size"
                    v-model="filters.page_size"
                    class="border-input bg-card h-11 rounded-xl border px-3 text-sm"
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
            <Button type="submit" :disabled="pending">Apply</Button
            ><Button
                type="button"
                variant="outline"
                :disabled="pending"
                @click="resetFilters"
                >Reset</Button
            >
        </form>
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
            class="border-border rounded-xl border p-4 text-sm"
        >
            Your account is read-only for collection work. You can view
            permitted records.
        </p>
        <div
            role="status"
            aria-live="polite"
            class="text-muted-foreground text-xs"
        >
            <template v-if="visible"
                >{{ pending ? 'Refreshing…' : 'As of' }}
                {{ visible.manifest.cutoff }} ·
                {{ visible.manifest.timezone }} · Activity
                {{ props.filters.from }}–{{ props.filters.to }}</template
            >
            <template v-else>Refreshing your permitted scope…</template>
        </div>
        <div
            v-if="!visible"
            class="bg-muted min-h-40 animate-pulse rounded-2xl motion-reduce:animate-none"
            aria-label="Dashboard loading"
        />
        <template v-else>
            <section
                v-for="(section, code) in visible.sections"
                :key="code"
                :aria-labelledby="`section-${code}`"
            >
                <Card>
                    <CardHeader
                        class="flex flex-row flex-wrap items-start justify-between gap-3"
                    >
                        <div>
                            <CardTitle :id="`section-${code}`">{{
                                titles[code]
                            }}</CardTitle
                            ><CardDescription
                                v-if="section.note"
                                class="mt-1.5 max-w-3xl"
                                >{{ section.note }}</CardDescription
                            >
                        </div>
                        <Badge
                            :variant="
                                section.status === 'Current'
                                    ? 'outline'
                                    : 'secondary'
                            "
                            >{{ section.status }}</Badge
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
                            class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"
                        >
                            <div
                                v-for="metric in section.metrics"
                                :key="metric.code"
                                class="border-border rounded-xl border p-4"
                            >
                                <p class="text-muted-foreground text-sm">
                                    {{ metric.title }}
                                </p>
                                <p
                                    class="mt-2 text-2xl font-semibold break-words"
                                    :aria-label="`${metric.display} ${metric.unit === 'NGN' ? 'NGN' : ''}`"
                                >
                                    {{ metric.display }}
                                </p>
                                <p
                                    class="text-muted-foreground mt-2 text-xs leading-5"
                                >
                                    {{ metric.definition }}
                                </p>
                                <p class="text-muted-foreground mt-2 text-xs">
                                    {{ metric.source }} ·
                                    {{ metric.date_basis }}
                                </p>
                            </div>
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
                                <div>
                                    <Link
                                        :href="row.href"
                                        class="text-primary text-sm font-medium underline-offset-4 hover:underline"
                                        >{{
                                            row.reference ??
                                            row.name ??
                                            row.plan_id
                                        }}</Link
                                    >
                                    <p
                                        class="text-muted-foreground mt-1 text-xs"
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
                                    }}</span
                                    ><Link
                                        v-if="
                                            code === 'schedule' &&
                                            scopeSummary.can_collect &&
                                            row.can_record_cash &&
                                            row.customer_id
                                        "
                                        :href="
                                            createCollection(row.customer_id)
                                                .url
                                        "
                                        class="text-primary text-sm underline"
                                        >Record cash</Link
                                    >
                                </div>
                            </div>
                        </div>
                        <p
                            v-else-if="
                                section.rows && section.status !== 'Unavailable'
                            "
                            class="text-muted-foreground text-sm"
                        >
                            No matching records at this cutoff.
                        </p>
                        <div v-if="section.trend" class="space-y-3">
                            <h3 class="text-sm font-medium">
                                Savings received trend ·
                                {{ section.trend_from }}–{{ section.trend_to }}
                            </h3>
                            <p class="text-muted-foreground text-xs">
                                Received dates retained by the owner; exact NGN
                                values. Dates with no posted receipt are
                                omitted.
                            </p>
                            <div
                                class="max-h-64 overflow-y-auto rounded-xl border"
                            >
                                <table class="w-full text-left text-sm">
                                    <caption class="sr-only">
                                        Savings received by receipt date, last
                                        30 days
                                    </caption>
                                    <thead>
                                        <tr>
                                            <th scope="col" class="p-3">
                                                Received date
                                            </th>
                                            <th
                                                scope="col"
                                                class="p-3 text-right"
                                            >
                                                Savings (NGN)
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
                                                No posted receipts in this trend
                                                period.
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div v-if="section.href" class="space-y-1">
                            <Link
                                :href="section.href"
                                class="text-primary text-sm underline"
                                >View all {{ section.total }} transactions</Link
                            >
                            <p class="text-muted-foreground text-xs">
                                {{ section.link_note }}
                            </p>
                        </div>
                        <p class="text-muted-foreground text-xs">
                            As of {{ section.cutoff }} · Ledger watermark
                            {{ section.ledger_watermark }} · Projection
                            {{ section.projection_version }}
                        </p>
                    </CardContent>
                </Card>
            </section>
        </template>
    </div>
</template>
