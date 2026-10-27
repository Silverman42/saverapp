<script setup lang="ts">
import { Head, router, usePage, usePoll } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { RefreshCw, SlidersHorizontal } from '@lucide/vue';
import DashboardSection from '@/components/DashboardSection.vue';
import FormSheet from '@/components/FormSheet.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { dashboard as dashboardRoute } from '@/routes';
import { dashboard as customerDashboard } from '@/routes/customer';
import { dashboard as agentDashboard } from '@/routes/agent';
import { dashboard as adminDashboard } from '@/routes/admin';
import type {
    Dashboard,
    DashboardFilters as Filters,
    DashboardSection as Section,
    DashboardTab,
} from '@/types/dashboard';

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
    today: 'Today',
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
const tabLabels: Record<DashboardTab, string> = {
    today: 'Today',
    overview: 'Overview',
    collections: 'Collections',
    requests: 'Requests & activity',
    finance: 'Finance',
};
// Finance is an Admin tab; other roles see their money movements with activity.
function tabFor(code: string): DashboardTab {
    if (code === 'today') return 'today';
    if (code === 'collections' || code === 'schedule') return 'collections';
    if (code === 'requests' || code === 'activity' || code === 'custody')
        return 'requests';
    if (code.startsWith('financial_') || code === 'incidents')
        return props.dashboard.role === 'admin' ? 'finance' : 'requests';
    return 'overview';
}
const tabs = computed(() => {
    const present = new Set(
        Object.keys(visible.value?.sections ?? {}).map(tabFor),
    );

    return (Object.keys(tabLabels) as DashboardTab[])
        .filter((value) => present.has(value))
        .map((value) => ({ value, label: tabLabels[value] }));
});
function tabFromUrl(): string | null {
    return new URL(page.url, 'http://localhost').searchParams.get('tab');
}
const requestedTab = ref(tabFromUrl());
const activeTab = computed<DashboardTab>(
    () =>
        tabs.value.find((tab) => tab.value === requestedTab.value)?.value ??
        tabs.value[0]?.value ??
        'today',
);
const activeSections = computed(
    () =>
        Object.entries(visible.value?.sections ?? {}).filter(
            ([code]) => tabFor(code) === activeTab.value,
        ) as [string, Section][],
);
function dashboardTab(): DashboardTab | undefined {
    return props.dashboard.role === 'customer' ? undefined : activeTab.value;
}
// Switching tabs only updates ?tab= in place; the data is already loaded.
function selectTab(value: string): void {
    requestedTab.value = value;
    const url = new URL(page.url, window.location.origin);
    url.searchParams.set('tab', value);
    router.replace({
        url: url.pathname + url.search,
        preserveState: true,
        preserveScroll: true,
    });
}
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
        tab: dashboardTab(),
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
            <Tabs
                v-if="dashboard.role !== 'customer'"
                :model-value="activeTab"
                class="gap-6"
                @update:model-value="selectTab(String($event))"
            >
                <div class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
                    <TabsList aria-label="Dashboard sections" class="h-11">
                        <TabsTrigger
                            v-for="tab in tabs"
                            :key="tab.value"
                            :value="tab.value"
                            class="px-3"
                            >{{ tab.label }}</TabsTrigger
                        >
                    </TabsList>
                </div>
                <TabsContent :value="activeTab" class="flex flex-col gap-6">
                    <DashboardSection
                        v-for="[code, section] in activeSections"
                        :key="code"
                        :code="code"
                        :title="titles[code] ?? code"
                        :section="section"
                        :can-collect="scopeSummary.can_collect"
                    />
                </TabsContent>
            </Tabs>
            <template v-else>
                <DashboardSection
                    v-for="(section, code) in visible.sections"
                    :key="code"
                    :code="String(code)"
                    :title="titles[code] ?? String(code)"
                    :section="section"
                    :can-collect="scopeSummary.can_collect"
                />
            </template>
        </template>
    </div>
</template>
