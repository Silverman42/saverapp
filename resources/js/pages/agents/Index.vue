<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { Briefcase, ChevronLeft, ChevronRight, UserPlus } from '@lucide/vue';
import DirectoryPanel from '@/components/directory/DirectoryPanel.vue';
import DirectoryRow from '@/components/directory/DirectoryRow.vue';
import ModuleOverview from '@/components/directory/ModuleOverview.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
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
import {
    create as agentsCreate,
    index as agentsIndex,
    show as agentsShow,
} from '@/routes/agents';
import { dashboard } from '@/routes';

type Agent = {
    id: string;
    name: string;
    photo_url: string | null;
    email: string | null;
    phone: string;
    operational_status: string;
    operational_status_label: string;
    account_state: string | null;
    account_state_label: string;
    eligibility: { is_eligible: boolean; explanation: string | null };
    current_customers_count: number;
    archived_customers_count: number;
    registered_at: string | null;
};

type PaginatedAgents = {
    data: Agent[];
    current_page: number;
    last_page: number;
    total: number;
    next_page_url: string | null;
    prev_page_url: string | null;
};
type DirectoryFilters = {
    search: string;
    operational_status: string;
    account_state: string;
    eligibility: string;
    min_customers: string | number;
    max_customers: string | number;
    registered_from: string;
    registered_to: string;
    sort: string;
    direction: string;
    per_page: number;
};

const props = defineProps<{
    agents: PaginatedAgents;
    filters: DirectoryFilters;
    overview: { total: number; active: number; eligible: number };
    overview_period: 'all' | 'today' | 'week' | 'month';
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Agents', href: agentsIndex() },
        ],
    },
});

const filtersOpen = ref(false);
const overviewPeriod = ref(props.overview_period);
const filterForm = reactive<DirectoryFilters>({ ...props.filters });

watch(
    () => props.filters,
    (filters) => Object.assign(filterForm, filters),
);
watch(
    () => props.overview_period,
    (period) => {
        overviewPeriod.value = period;
    },
);

const activeFilterCount = computed(
    () =>
        [
            filterForm.operational_status,
            filterForm.account_state,
            filterForm.eligibility,
            filterForm.min_customers,
            filterForm.max_customers,
            filterForm.registered_from,
            filterForm.registered_to,
        ].filter((value) => value !== '' && value !== 'all').length,
);
const overviewMetrics = computed(() => [
    {
        label: 'Total agents',
        value: props.overview.total,
        description: 'Registered in the selected period',
    },
    {
        label: 'Active agents',
        value: props.overview.active,
        description: 'Currently active operationally',
    },
    {
        label: 'Eligible agents',
        value: props.overview.eligible,
        description: 'Ready to receive assignments',
    },
]);

const query = (): Record<string, string | number> => {
    const params: Record<string, string | number> = {
        overview_period: overviewPeriod.value,
    };
    const defaults: Partial<DirectoryFilters> = {
        sort: 'created_at',
        direction: 'desc',
        per_page: 25,
    };
    (Object.keys(filterForm) as Array<keyof DirectoryFilters>).forEach(
        (key) => {
            const value = filterForm[key];
            if (value !== '' && value !== defaults[key] && value !== 'all')
                params[key] = value;
        },
    );
    return params;
};

const applyFilters = (): void => {
    router.get(
        agentsIndex.url({ query: query() }),
        {},
        { preserveScroll: true, preserveState: true, replace: true },
    );
};
const updateOverviewPeriod = (value: unknown): void => {
    if (typeof value !== 'string') return;
    overviewPeriod.value = value as typeof overviewPeriod.value;
    applyFilters();
};
const resetFilters = (): void => {
    Object.assign(filterForm, {
        search: '',
        operational_status: '',
        account_state: '',
        eligibility: '',
        min_customers: '',
        max_customers: '',
        registered_from: '',
        registered_to: '',
        sort: 'created_at',
        direction: 'desc',
        per_page: 25,
    });
    applyFilters();
};
const updateDateFilter = (
    field: 'registered_from' | 'registered_to',
    value: string,
): void => {
    filterForm[field] = value;
    applyFilters();
};
const canRegister = computed(() => {
    const auth = usePage().props.auth as { permissions?: string[] } | undefined;

    return auth?.permissions?.includes('agents.manage') || false;
});

const getInitials = (name: string): string => {
    const parts = name.trim().split(/\s+/);
    return parts.length > 1
        ? `${parts[0][0]}${parts.at(-1)?.[0] ?? ''}`.toUpperCase()
        : (parts[0]?.slice(0, 2).toUpperCase() ?? 'AG');
};
const getOperationalBadgeVariant = (
    status: string,
): 'default' | 'secondary' | 'outline' =>
    status === 'active'
        ? 'default'
        : status === 'inactive'
          ? 'secondary'
          : 'outline';
const getAccountBadgeVariant = (
    state: string | null,
): 'default' | 'secondary' | 'destructive' | 'outline' =>
    state === 'active'
        ? 'outline'
        : ['suspended', 'deactivated'].includes(state ?? '')
          ? 'destructive'
          : 'secondary';
</script>

<template>
    <Head title="Agent Directory" />

    <div class="space-y-6">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <div>
                <h1 class="text-[25px] font-medium tracking-tight">Agents</h1>
                <p class="text-muted-foreground mt-1.5 text-sm">
                    Manage agent profiles, invitations, and operational
                    readiness.
                </p>
            </div>
            <Link v-if="canRegister" :href="agentsCreate().url">
                <Button>
                    <UserPlus class="mr-1.5 size-4" /> Create Agent
                </Button>
            </Link>
        </div>

        <ModuleOverview
            title="Agent Overview"
            description="Monitor agent activity, who can take assignments, and customer coverage."
            :metrics="overviewMetrics"
        >
            <template #actions>
                <Select
                    :model-value="overviewPeriod"
                    @update:model-value="updateOverviewPeriod"
                >
                    <SelectTrigger
                        class="w-40"
                        aria-label="Agent overview period"
                        ><SelectValue
                    /></SelectTrigger>
                    <SelectContent
                        ><SelectItem value="all">All time</SelectItem
                        ><SelectItem value="today">Today</SelectItem
                        ><SelectItem value="week">This week</SelectItem
                        ><SelectItem value="month"
                            >This month</SelectItem
                        ></SelectContent
                    >
                </Select>
            </template>
        </ModuleOverview>

        <DirectoryPanel
            title="Agents List"
            :description="`${agents.total} agent${agents.total === 1 ? '' : 's'} matching the current directory view.`"
            :search-value="filterForm.search"
            search-placeholder="Search agents"
            :filters-open="filtersOpen"
            :active-filter-count="activeFilterCount"
            @update:search-value="filterForm.search = $event"
            @submit-search="applyFilters"
            @toggle-filters="filtersOpen = !filtersOpen"
            @reset-filters="resetFilters"
        >
            <template #filters>
                <div class="w-fit space-y-1.5">
                    <Label for="agent-status" class="text-xs"
                        >Operational status</Label
                    ><Select
                        v-model="filterForm.operational_status"
                        @update:model-value="applyFilters"
                        ><SelectTrigger id="agent-status"
                            ><SelectValue
                                placeholder="All statuses" /></SelectTrigger
                        ><SelectContent
                            ><SelectItem value="all">All statuses</SelectItem
                            ><SelectItem value="active">Active</SelectItem
                            ><SelectItem value="inactive"
                                >Inactive</SelectItem
                            ></SelectContent
                        ></Select
                    >
                </div>
                <div class="w-fit space-y-1.5">
                    <Label for="agent-account-state" class="text-xs"
                        >Account state</Label
                    ><Select
                        v-model="filterForm.account_state"
                        @update:model-value="applyFilters"
                        ><SelectTrigger id="agent-account-state"
                            ><SelectValue
                                placeholder="All account states" /></SelectTrigger
                        ><SelectContent
                            ><SelectItem value="all">All states</SelectItem
                            ><SelectItem value="active">Active</SelectItem
                            ><SelectItem value="invited">Invited</SelectItem
                            ><SelectItem value="mfa_setup">MFA setup</SelectItem
                            ><SelectItem value="suspended">Suspended</SelectItem
                            ><SelectItem value="deactivated"
                                >Deactivated</SelectItem
                            ></SelectContent
                        ></Select
                    >
                </div>
                <div class="w-fit space-y-1.5">
                    <Label for="agent-eligibility" class="text-xs"
                        >Assignment eligibility</Label
                    ><Select
                        v-model="filterForm.eligibility"
                        @update:model-value="applyFilters"
                        ><SelectTrigger id="agent-eligibility"
                            ><SelectValue
                                placeholder="Any eligibility" /></SelectTrigger
                        ><SelectContent
                            ><SelectItem value="all">Any eligibility</SelectItem
                            ><SelectItem value="eligible">Eligible</SelectItem
                            ><SelectItem value="ineligible"
                                >Ineligible</SelectItem
                            ></SelectContent
                        ></Select
                    >
                </div>
                <div class="w-fit space-y-1.5">
                    <Label for="agent-min-customers" class="text-xs"
                        >Minimum customers</Label
                    ><Input
                        id="agent-min-customers"
                        v-model="filterForm.min_customers"
                        class="w-36"
                        type="number"
                        min="0"
                        @change="applyFilters"
                    />
                </div>
                <div class="w-fit space-y-1.5">
                    <Label for="agent-max-customers" class="text-xs"
                        >Maximum customers</Label
                    ><Input
                        id="agent-max-customers"
                        v-model="filterForm.max_customers"
                        class="w-36"
                        type="number"
                        min="0"
                        @change="applyFilters"
                    />
                </div>
                <div class="w-fit space-y-1.5">
                    <Label for="agent-registered-from" class="text-xs"
                        >Registered from</Label
                    ><DatePicker
                        id="agent-registered-from"
                        :model-value="filterForm.registered_from"
                        @update:model-value="
                            updateDateFilter('registered_from', $event)
                        "
                    />
                </div>
                <div class="w-fit space-y-1.5">
                    <Label for="agent-registered-to" class="text-xs"
                        >Registered to</Label
                    ><DatePicker
                        id="agent-registered-to"
                        :model-value="filterForm.registered_to"
                        @update:model-value="
                            updateDateFilter('registered_to', $event)
                        "
                    />
                </div>
            </template>
            <template #filter-summary
                ><p class="text-muted-foreground text-xs">
                    {{ agents.total }} agent{{ agents.total === 1 ? '' : 's' }}
                    match the current filters.
                </p></template
            >

            <div v-if="agents.data.length === 0" class="py-14 text-center">
                <div
                    class="bg-muted text-muted-foreground mx-auto flex size-12 items-center justify-center rounded-2xl"
                >
                    <Briefcase class="size-5" />
                </div>
                <h3 class="mt-4 text-sm font-semibold">No agents found</h3>
                <p class="text-muted-foreground mt-1 text-sm">
                    Adjust the search or filters to find an agent record.
                </p>
            </div>
            <div v-else class="space-y-3">
                <Link
                    v-for="agent in agents.data"
                    :key="agent.id"
                    :href="agentsShow(agent.id).url"
                    class="group block rounded-2xl focus-visible:outline-none"
                    ><DirectoryRow
                        class="group-focus-visible:border-primary group-focus-visible:bg-accent/35"
                        ><div
                            class="hidden items-center gap-5 lg:grid lg:grid-cols-[minmax(13rem,1.5fr)_repeat(5,minmax(0,1fr))]"
                        >
                            <div class="flex min-w-0 items-center gap-3">
                                <Avatar class="size-11 shrink-0"
                                    ><AvatarImage
                                        v-if="agent.photo_url"
                                        :src="agent.photo_url"
                                        :alt="agent.name"
                                    /><AvatarFallback>{{
                                        getInitials(agent.name)
                                    }}</AvatarFallback></Avatar
                                >
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold">
                                        {{ agent.name }}
                                    </p>
                                    <p
                                        class="text-muted-foreground truncate text-xs"
                                    >
                                        {{ agent.email || agent.id }}
                                    </p>
                                </div>
                            </div>
                            <div>
                                <p
                                    class="text-muted-foreground text-[11px] font-medium uppercase"
                                >
                                    Operational status
                                </p>
                                <Badge
                                    :variant="
                                        getOperationalBadgeVariant(
                                            agent.operational_status,
                                        )
                                    "
                                    class="mt-1"
                                    >{{ agent.operational_status_label }}</Badge
                                >
                            </div>
                            <div>
                                <p
                                    class="text-muted-foreground text-[11px] font-medium uppercase"
                                >
                                    Account state
                                </p>
                                <Badge
                                    :variant="
                                        getAccountBadgeVariant(
                                            agent.account_state,
                                        )
                                    "
                                    class="mt-1"
                                    >{{ agent.account_state_label }}</Badge
                                >
                            </div>
                            <div>
                                <p
                                    class="text-muted-foreground text-[11px] font-medium uppercase"
                                >
                                    Eligibility
                                </p>
                                <Badge
                                    :variant="
                                        agent.eligibility.is_eligible
                                            ? 'default'
                                            : 'secondary'
                                    "
                                    class="mt-1"
                                    >{{
                                        agent.eligibility.is_eligible
                                            ? 'Eligible'
                                            : 'Ineligible'
                                    }}</Badge
                                >
                            </div>
                            <div>
                                <p
                                    class="text-muted-foreground text-[11px] font-medium uppercase"
                                >
                                    Assigned customers
                                </p>
                                <p class="mt-1 text-sm">
                                    {{ agent.current_customers_count }}
                                </p>
                            </div>
                            <div>
                                <p
                                    class="text-muted-foreground text-[11px] font-medium uppercase"
                                >
                                    Registered
                                </p>
                                <p class="mt-1 text-sm">
                                    {{ agent.registered_at || '—' }}
                                </p>
                            </div>
                        </div>
                        <div class="lg:hidden">
                            <div class="flex min-w-0 items-center gap-3">
                                <Avatar class="size-11 shrink-0"
                                    ><AvatarImage
                                        v-if="agent.photo_url"
                                        :src="agent.photo_url"
                                        :alt="agent.name"
                                    /><AvatarFallback>{{
                                        getInitials(agent.name)
                                    }}</AvatarFallback></Avatar
                                >
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold">
                                        {{ agent.name }}
                                    </p>
                                    <p
                                        class="text-muted-foreground truncate text-xs"
                                    >
                                        {{ agent.email || agent.id }}
                                    </p>
                                </div>
                            </div>
                            <div
                                class="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 text-sm"
                            >
                                <div>
                                    <p
                                        class="text-muted-foreground text-[10px] font-medium uppercase"
                                    >
                                        Status
                                    </p>
                                    <Badge
                                        :variant="
                                            getOperationalBadgeVariant(
                                                agent.operational_status,
                                            )
                                        "
                                        class="mt-1"
                                        >{{
                                            agent.operational_status_label
                                        }}</Badge
                                    >
                                </div>
                                <div>
                                    <p
                                        class="text-muted-foreground text-[10px] font-medium uppercase"
                                    >
                                        Eligibility
                                    </p>
                                    <Badge
                                        :variant="
                                            agent.eligibility.is_eligible
                                                ? 'default'
                                                : 'secondary'
                                        "
                                        class="mt-1"
                                        >{{
                                            agent.eligibility.is_eligible
                                                ? 'Eligible'
                                                : 'Ineligible'
                                        }}</Badge
                                    >
                                </div>
                                <div>
                                    <p
                                        class="text-muted-foreground text-[10px] font-medium uppercase"
                                    >
                                        Assigned
                                    </p>
                                    <p class="mt-1">
                                        {{ agent.current_customers_count }}
                                        customers
                                    </p>
                                </div>
                                <div>
                                    <p
                                        class="text-muted-foreground text-[10px] font-medium uppercase"
                                    >
                                        Registered
                                    </p>
                                    <p class="mt-1">
                                        {{ agent.registered_at || '—' }}
                                    </p>
                                </div>
                            </div>
                        </div></DirectoryRow
                    ></Link
                >
            </div>

            <template #footer
                ><div
                    class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div
                        class="text-muted-foreground flex items-center gap-2 text-sm"
                    >
                        Display
                        <Select
                            v-model="filterForm.per_page"
                            @update:model-value="applyFilters"
                            ><SelectTrigger class="h-9 w-20"
                                ><SelectValue /></SelectTrigger
                            ><SelectContent
                                ><SelectItem :value="25">25</SelectItem
                                ><SelectItem :value="50">50</SelectItem
                                ><SelectItem :value="100"
                                    >100</SelectItem
                                ></SelectContent
                            ></Select
                        >
                        per page
                    </div>
                    <div
                        class="flex items-center justify-between gap-3 sm:justify-end"
                    >
                        <span class="text-muted-foreground text-xs"
                            >Page {{ agents.current_page }} of
                            {{ agents.last_page }}</span
                        >
                        <div class="flex gap-2">
                            <Link
                                v-if="agents.prev_page_url"
                                :href="agents.prev_page_url"
                                preserve-state
                                preserve-scroll
                                ><Button variant="outline" size="sm"
                                    ><ChevronLeft /> Prev</Button
                                ></Link
                            ><Button v-else variant="outline" size="sm" disabled
                                ><ChevronLeft /> Prev</Button
                            ><Link
                                v-if="agents.next_page_url"
                                :href="agents.next_page_url"
                                preserve-state
                                preserve-scroll
                                ><Button size="sm"
                                    >Next <ChevronRight /></Button></Link
                            ><Button v-else size="sm" disabled
                                >Next <ChevronRight
                            /></Button>
                        </div>
                    </div></div
            ></template>
        </DirectoryPanel>
    </div>
</template>
