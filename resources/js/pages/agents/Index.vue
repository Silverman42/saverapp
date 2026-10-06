<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { Briefcase, ChevronLeft, ChevronRight, UserPlus } from '@lucide/vue';
import DirectoryPanel from '@/components/directory/DirectoryPanel.vue';
import ModuleOverview from '@/components/directory/ModuleOverview.vue';
import EmptyState from '@/components/EmptyState.vue';
import FormSheet from '@/components/FormSheet.vue';
import PageHeader from '@/components/PageHeader.vue';
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
        label: 'Agents',
        value: props.overview.total,
        description: '',
    },
    {
        label: 'Active',
        value: props.overview.active,
        description: '',
    },
    {
        label: 'Ready for new customers',
        value: props.overview.eligible,
        description: '',
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
const applyFromSheet = (): void => {
    filtersOpen.value = false;
    applyFilters();
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
    filtersOpen.value = false;
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
    <Head title="Agents" />

    <div class="space-y-6">
        <PageHeader
            title="Agents"
            description="Your field agents and the customers they look after."
        >
            <template v-if="canRegister" #actions>
                <Button as-child>
                    <Link :href="agentsCreate().url">
                        <UserPlus class="size-4" /> Add agent
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <ModuleOverview title="Overview" :metrics="overviewMetrics">
            <template #actions>
                <Select
                    :model-value="overviewPeriod"
                    @update:model-value="updateOverviewPeriod"
                >
                    <SelectTrigger class="w-40" aria-label="Overview period"
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
            title="All agents"
            :description="`${agents.total} agent${agents.total === 1 ? '' : 's'}`"
            :search-value="filterForm.search"
            search-placeholder="Search by name, email or phone"
            :filters-open="false"
            :active-filter-count="activeFilterCount"
            @update:search-value="filterForm.search = $event"
            @submit-search="applyFilters"
            @toggle-filters="filtersOpen = true"
            @reset-filters="resetFilters"
        >
            <EmptyState
                v-if="agents.data.length === 0"
                :icon="Briefcase"
                title="No agents found"
                description="Try a different search or clear the filters."
            >
                <Button
                    v-if="activeFilterCount > 0 || filterForm.search"
                    variant="outline"
                    @click="resetFilters"
                    >Clear filters</Button
                >
            </EmptyState>
            <div v-else class="divide-border -my-2 divide-y">
                <Link
                    v-for="agent in agents.data"
                    :key="agent.id"
                    :href="agentsShow(agent.id).url"
                    class="hover:bg-accent/35 focus-visible:ring-ring -mx-3 flex flex-wrap items-center gap-x-6 gap-y-3 rounded-xl px-3 py-4 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                >
                    <div
                        class="flex min-w-0 flex-1 basis-60 items-center gap-3"
                    >
                        <Avatar class="size-10 shrink-0"
                            ><AvatarImage
                                v-if="agent.photo_url"
                                :src="agent.photo_url"
                                :alt="agent.name"
                            /><AvatarFallback>{{
                                getInitials(agent.name)
                            }}</AvatarFallback></Avatar
                        >
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">
                                {{ agent.name }}
                            </p>
                            <p class="text-muted-foreground truncate text-xs">
                                {{ agent.email || agent.phone }}
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge
                            :variant="
                                getOperationalBadgeVariant(
                                    agent.operational_status,
                                )
                            "
                            >{{ agent.operational_status_label }}</Badge
                        >
                        <Badge
                            v-if="agent.account_state !== 'active'"
                            :variant="
                                getAccountBadgeVariant(agent.account_state)
                            "
                            >{{ agent.account_state_label }}</Badge
                        >
                        <Badge
                            v-if="!agent.eligibility.is_eligible"
                            variant="outline"
                            >Can't take new customers</Badge
                        >
                    </div>
                    <div
                        class="flex items-center gap-3 text-sm sm:w-36 sm:justify-end"
                    >
                        <span
                            >{{ agent.current_customers_count }} customer{{
                                agent.current_customers_count === 1 ? '' : 's'
                            }}</span
                        >
                        <ChevronRight
                            class="text-muted-foreground hidden size-4 sm:block"
                        />
                    </div>
                </Link>
            </div>

            <template #footer
                ><div
                    class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div
                        class="text-muted-foreground flex items-center gap-2 text-sm"
                    >
                        Show
                        <Select
                            v-model="filterForm.per_page"
                            @update:model-value="applyFilters"
                            ><SelectTrigger
                                class="h-9 w-20"
                                aria-label="Agents per page"
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
                            <Button
                                v-if="agents.prev_page_url"
                                as-child
                                variant="outline"
                                size="sm"
                                ><Link
                                    :href="agents.prev_page_url"
                                    preserve-state
                                    preserve-scroll
                                    ><ChevronLeft /> Previous</Link
                                ></Button
                            ><Button v-else variant="outline" size="sm" disabled
                                ><ChevronLeft /> Previous</Button
                            ><Button
                                v-if="agents.next_page_url"
                                as-child
                                variant="outline"
                                size="sm"
                                ><Link
                                    :href="agents.next_page_url"
                                    preserve-state
                                    preserve-scroll
                                    >Next <ChevronRight /></Link></Button
                            ><Button v-else variant="outline" size="sm" disabled
                                >Next <ChevronRight
                            /></Button>
                        </div>
                    </div></div
            ></template>
        </DirectoryPanel>

        <FormSheet
            v-model:open="filtersOpen"
            title="Filters"
            description="Narrow down the agent list."
        >
            <div class="grid gap-5">
                <div class="grid gap-2">
                    <Label for="agent-status">Status</Label>
                    <Select v-model="filterForm.operational_status"
                        ><SelectTrigger id="agent-status" class="w-full"
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
                <div class="grid gap-2">
                    <Label for="agent-account-state">Account</Label>
                    <Select v-model="filterForm.account_state"
                        ><SelectTrigger id="agent-account-state" class="w-full"
                            ><SelectValue
                                placeholder="All accounts" /></SelectTrigger
                        ><SelectContent
                            ><SelectItem value="all">All accounts</SelectItem
                            ><SelectItem value="active">Active</SelectItem
                            ><SelectItem value="invited">Invited</SelectItem
                            ><SelectItem value="mfa_setup"
                                >Setting up sign-in</SelectItem
                            ><SelectItem value="suspended">Suspended</SelectItem
                            ><SelectItem value="deactivated"
                                >Deactivated</SelectItem
                            ></SelectContent
                        ></Select
                    >
                </div>
                <div class="grid gap-2">
                    <Label for="agent-eligibility">New customers</Label>
                    <Select v-model="filterForm.eligibility"
                        ><SelectTrigger id="agent-eligibility" class="w-full"
                            ><SelectValue placeholder="Any" /></SelectTrigger
                        ><SelectContent
                            ><SelectItem value="all">Any</SelectItem
                            ><SelectItem value="eligible"
                                >Can take new customers</SelectItem
                            ><SelectItem value="ineligible"
                                >Can't take new customers</SelectItem
                            ></SelectContent
                        ></Select
                    >
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="agent-min-customers">Min. customers</Label
                        ><Input
                            id="agent-min-customers"
                            v-model="filterForm.min_customers"
                            type="number"
                            min="0"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="agent-max-customers">Max. customers</Label
                        ><Input
                            id="agent-max-customers"
                            v-model="filterForm.max_customers"
                            type="number"
                            min="0"
                        />
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="agent-registered-from">Joined from</Label
                    ><DatePicker
                        id="agent-registered-from"
                        v-model="filterForm.registered_from"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="agent-registered-to">Joined to</Label
                    ><DatePicker
                        id="agent-registered-to"
                        v-model="filterForm.registered_to"
                    />
                </div>
            </div>
            <template #footer>
                <Button type="button" variant="outline" @click="resetFilters"
                    >Clear</Button
                >
                <Button type="button" @click="applyFromSheet"
                    >Show results</Button
                >
            </template>
        </FormSheet>
    </div>
</template>
