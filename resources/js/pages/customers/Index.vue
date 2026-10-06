<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { ChevronLeft, ChevronRight, UserPlus, Users } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import FormSheet from '@/components/FormSheet.vue';
import PageHeader from '@/components/PageHeader.vue';
import DirectoryPanel from '@/components/directory/DirectoryPanel.vue';
import DirectoryRow from '@/components/directory/DirectoryRow.vue';
import ModuleOverview from '@/components/directory/ModuleOverview.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    create as customersCreate,
    index as customersIndex,
    show as customersShow,
} from '@/routes/customers';
import { dashboard } from '@/routes';

type Customer = {
    id: string;
    name: string;
    photo_url: string | null;
    phone: string;
    email: string | null;
    assigned_agent: { id: string; name: string; is_eligible: boolean } | null;
    operational_status: string;
    operational_status_label: string;
    account_state: string | null;
    account_state_label: string;
    registered_at: string | null;
};

type PaginatedCustomers = {
    data: Customer[];
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
    assigned_agent: string;
    agent_eligibility: string;
    registered_from: string;
    registered_to: string;
    sort: string;
    direction: string;
    per_page: number;
};

const props = defineProps<{
    customers: PaginatedCustomers;
    filters: DirectoryFilters;
    available_agents: Array<{ id: string; name: string }>;
    viewer_type: string;
    can_register?: boolean;
    overview: { total: number; active: number; restricted: number };
    overview_period: 'all' | 'today' | 'week' | 'month';
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Customers', href: customersIndex() },
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
            filterForm.assigned_agent,
            filterForm.agent_eligibility,
            filterForm.registered_from,
            filterForm.registered_to,
        ].filter((value) => value && value !== 'all').length,
);

const overviewMetrics = computed(() => [
    {
        label: 'Customers',
        value: props.overview.total,
        description: 'Added in this period',
    },
    {
        label: 'Active',
        value: props.overview.active,
        description: 'Can save and withdraw',
    },
    {
        label: 'Restricted',
        value: props.overview.restricted,
        description: 'Limited for now',
    },
]);

const gridColumns = computed(() =>
    props.viewer_type === 'admin'
        ? 'lg:grid-cols-[minmax(13rem,1.6fr)_repeat(4,minmax(0,1fr))]'
        : 'lg:grid-cols-[minmax(13rem,1.6fr)_repeat(3,minmax(0,1fr))]',
);

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
            if (value !== '' && value !== defaults[key] && value !== 'all') {
                params[key] = value;
            }
        },
    );

    return params;
};

const applyFilters = (): void => {
    router.get(
        customersIndex.url({ query: query() }),
        {},
        { preserveScroll: true, preserveState: true, replace: true },
    );
};

const updateOverviewPeriod = (value: unknown): void => {
    if (typeof value !== 'string') return;
    overviewPeriod.value = value as typeof overviewPeriod.value;
    applyFilters();
};

const applyFromSheet = (): void => {
    filtersOpen.value = false;
    applyFilters();
};

const resetFilters = (): void => {
    filtersOpen.value = false;
    Object.assign(filterForm, {
        search: '',
        operational_status: '',
        account_state: '',
        assigned_agent: '',
        agent_eligibility: '',
        registered_from: '',
        registered_to: '',
        sort: 'created_at',
        direction: 'desc',
        per_page: 25,
    });
    applyFilters();
};

const getInitials = (name: string): string => {
    const parts = name.trim().split(/\s+/);
    return parts.length > 1
        ? `${parts[0][0]}${parts.at(-1)?.[0] ?? ''}`.toUpperCase()
        : (parts[0]?.slice(0, 2).toUpperCase() ?? 'CU');
};

const getOperationalBadgeVariant = (
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' => {
    const variants: Record<
        string,
        'default' | 'secondary' | 'destructive' | 'outline'
    > = {
        active: 'default',
        inactive: 'secondary',
        restricted: 'destructive',
        archived: 'destructive',
    };

    return variants[status] ?? 'outline';
};

const getAccountBadgeVariant = (
    state: string | null,
): 'default' | 'secondary' | 'destructive' | 'outline' => {
    return state === 'active'
        ? 'outline'
        : ['suspended', 'deactivated'].includes(state ?? '')
          ? 'destructive'
          : 'secondary';
};
</script>

<template>
    <Head title="Customer Directory" />

    <div class="space-y-6">
        <PageHeader
            title="Customers"
            description="Find a customer, see their status and open their profile."
        >
            <template #actions>
                <Button v-if="can_register" as-child>
                    <Link :href="customersCreate().url"
                        ><UserPlus class="size-4" /> Add customer</Link
                    >
                </Button>
            </template>
        </PageHeader>

        <ModuleOverview title="Overview" :metrics="overviewMetrics">
            <template #actions>
                <Select
                    :model-value="overviewPeriod"
                    @update:model-value="updateOverviewPeriod"
                >
                    <SelectTrigger
                        class="w-40"
                        aria-label="Customer overview period"
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
            title="All customers"
            :description="`${customers.total} customer${customers.total === 1 ? '' : 's'}`"
            :search-value="filterForm.search"
            search-placeholder="Search by name, phone or email"
            :filters-open="false"
            :active-filter-count="activeFilterCount"
            @update:search-value="filterForm.search = $event"
            @submit-search="applyFilters"
            @toggle-filters="filtersOpen = true"
            @reset-filters="resetFilters"
        >
            <EmptyState
                v-if="customers.data.length === 0"
                :icon="Users"
                title="No customers found"
                :description="
                    activeFilterCount > 0 || filterForm.search
                        ? 'Try a different search or clear the filters.'
                        : 'Customers you add will show up here.'
                "
            >
                <Button
                    v-if="activeFilterCount > 0 || filterForm.search"
                    variant="outline"
                    @click="resetFilters"
                    >Clear filters</Button
                >
            </EmptyState>

            <div v-else class="space-y-2">
                <div
                    class="text-muted-foreground hidden gap-5 px-5 text-xs lg:grid"
                    :class="gridColumns"
                    aria-hidden="true"
                >
                    <span>Customer</span>
                    <span v-if="viewer_type === 'admin'">Agent</span>
                    <span>Status</span>
                    <span>Phone</span>
                    <span>Added</span>
                </div>
                <Link
                    v-for="customer in customers.data"
                    :key="customer.id"
                    :href="customersShow(customer.id).url"
                    class="group block rounded-2xl focus-visible:outline-none"
                >
                    <DirectoryRow
                        class="group-focus-visible:border-primary group-focus-visible:bg-accent/35"
                    >
                        <div
                            class="grid grid-cols-1 gap-3 lg:items-center lg:gap-5"
                            :class="gridColumns"
                        >
                            <div class="flex min-w-0 items-center gap-3">
                                <Avatar class="size-10 shrink-0"
                                    ><AvatarImage
                                        v-if="customer.photo_url"
                                        :src="customer.photo_url"
                                        :alt="customer.name"
                                    /><AvatarFallback>{{
                                        getInitials(customer.name)
                                    }}</AvatarFallback></Avatar
                                >
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium">
                                        {{ customer.name }}
                                    </p>
                                    <p
                                        class="text-muted-foreground truncate text-xs"
                                    >
                                        {{ customer.email || customer.phone }}
                                    </p>
                                </div>
                            </div>
                            <p
                                v-if="viewer_type === 'admin'"
                                class="text-muted-foreground lg:text-foreground text-sm"
                            >
                                <span class="lg:hidden">Agent: </span
                                >{{
                                    customer.assigned_agent?.name || 'No agent'
                                }}
                            </p>
                            <div class="flex flex-wrap items-center gap-1.5">
                                <Badge
                                    :variant="
                                        getOperationalBadgeVariant(
                                            customer.operational_status,
                                        )
                                    "
                                    >{{
                                        customer.operational_status_label
                                    }}</Badge
                                >
                                <Badge
                                    v-if="customer.account_state !== 'active'"
                                    :variant="
                                        getAccountBadgeVariant(
                                            customer.account_state,
                                        )
                                    "
                                    >{{ customer.account_state_label }}</Badge
                                >
                            </div>
                            <p class="hidden text-sm lg:block">
                                {{ customer.phone }}
                            </p>
                            <p
                                class="text-muted-foreground lg:text-foreground text-xs lg:text-sm"
                            >
                                <span class="lg:hidden">Added </span
                                >{{ customer.registered_at || '-' }}
                            </p>
                        </div>
                    </DirectoryRow>
                </Link>
            </div>

            <template #footer>
                <div
                    class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <span class="text-muted-foreground text-xs"
                        >Page {{ customers.current_page }} of
                        {{ customers.last_page }}</span
                    >
                    <div class="flex gap-2">
                        <Link
                            v-if="customers.prev_page_url"
                            :href="customers.prev_page_url"
                            preserve-state
                            preserve-scroll
                            ><Button variant="outline" size="sm"
                                ><ChevronLeft /> Previous</Button
                            ></Link
                        ><Button v-else variant="outline" size="sm" disabled
                            ><ChevronLeft /> Previous</Button
                        ><Link
                            v-if="customers.next_page_url"
                            :href="customers.next_page_url"
                            preserve-state
                            preserve-scroll
                            ><Button variant="outline" size="sm"
                                >Next <ChevronRight /></Button></Link
                        ><Button v-else variant="outline" size="sm" disabled
                            >Next <ChevronRight
                        /></Button>
                    </div>
                </div>
            </template>
        </DirectoryPanel>

        <FormSheet
            v-model:open="filtersOpen"
            title="Filters"
            description="Narrow down the customer list."
        >
            <div class="grid gap-5">
                <div class="grid gap-2">
                    <Label for="customer-status">Status</Label
                    ><Select v-model="filterForm.operational_status"
                        ><SelectTrigger id="customer-status" class="w-full"
                            ><SelectValue
                                placeholder="All statuses" /></SelectTrigger
                        ><SelectContent
                            ><SelectItem value="all">All statuses</SelectItem
                            ><SelectItem value="active">Active</SelectItem
                            ><SelectItem value="inactive">Inactive</SelectItem
                            ><SelectItem value="restricted"
                                >Restricted</SelectItem
                            ><SelectItem value="archived"
                                >Archived</SelectItem
                            ></SelectContent
                        ></Select
                    >
                </div>
                <div class="grid gap-2">
                    <Label for="customer-account-state">Account</Label
                    ><Select v-model="filterForm.account_state"
                        ><SelectTrigger
                            id="customer-account-state"
                            class="w-full"
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
                <div v-if="viewer_type === 'admin'" class="grid gap-2">
                    <Label for="customer-agent">Agent</Label
                    ><Select v-model="filterForm.assigned_agent"
                        ><SelectTrigger id="customer-agent" class="w-full"
                            ><SelectValue
                                placeholder="All agents" /></SelectTrigger
                        ><SelectContent
                            ><SelectItem value="all">All agents</SelectItem
                            ><SelectItem
                                v-for="agent in available_agents"
                                :key="agent.id"
                                :value="agent.id"
                                >{{ agent.name }} ({{ agent.id }})</SelectItem
                            ></SelectContent
                        ></Select
                    >
                </div>
                <div v-if="viewer_type === 'admin'" class="grid gap-2">
                    <Label for="customer-agent-eligibility"
                        >Agent can serve them</Label
                    ><Select v-model="filterForm.agent_eligibility"
                        ><SelectTrigger
                            id="customer-agent-eligibility"
                            class="w-full"
                            ><SelectValue placeholder="Any" /></SelectTrigger
                        ><SelectContent
                            ><SelectItem value="all">Any</SelectItem
                            ><SelectItem value="eligible">Yes</SelectItem
                            ><SelectItem value="ineligible"
                                >No</SelectItem
                            ></SelectContent
                        ></Select
                    >
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-2">
                        <Label for="customer-registered-from">Added from</Label
                        ><DatePicker
                            id="customer-registered-from"
                            v-model="filterForm.registered_from"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="customer-registered-to">Added to</Label
                        ><DatePicker
                            id="customer-registered-to"
                            v-model="filterForm.registered_to"
                        />
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="customer-per-page">Rows per page</Label
                    ><Select v-model="filterForm.per_page"
                        ><SelectTrigger id="customer-per-page" class="w-full"
                            ><SelectValue /></SelectTrigger
                        ><SelectContent
                            ><SelectItem :value="25">25</SelectItem
                            ><SelectItem :value="50">50</SelectItem
                            ><SelectItem :value="100"
                                >100</SelectItem
                            ></SelectContent
                        ></Select
                    >
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
