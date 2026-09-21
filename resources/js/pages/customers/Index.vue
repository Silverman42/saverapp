<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import { dashboard } from '@/routes';
import {
    ChevronRight,
    Filter,
    RotateCcw,
    Search,
    ShieldAlert,
    User as UserIcon,
    Users,
} from '@lucide/vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export type AssignedAgent = {
    id: string;
    name: string;
    is_eligible: boolean;
};

export type CustomerItem = {
    id: string;
    name: string;
    photo_url: string | null;
    phone: string;
    email: string | null;
    assigned_agent: AssignedAgent | null;
    operational_status: string;
    operational_status_label: string;
    account_state: string;
    account_state_label: string;
    current_plan: {
        status: string;
        message: string;
    };
    registered_at: string;
    registered_at_iso: string;
};

export type PaginatedCustomers = {
    data: CustomerItem[];
    current_page: number;
    last_page: number;
    total: number;
    next_page_url: string | null;
    prev_page_url: string | null;
};

export type AgentOption = {
    id: string;
    name: string;
};

const props = defineProps<{
    customers: PaginatedCustomers;
    filters: {
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
    available_agents: AgentOption[];
    viewer_type: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
            {
                title: 'Customers',
                href: '/customers',
            },
        ],
    },
});

const filterForm = reactive({
    search: props.filters.search ?? '',
    operational_status: props.filters.operational_status ?? '',
    account_state: props.filters.account_state ?? '',
    assigned_agent: props.filters.assigned_agent ?? '',
    agent_eligibility: props.filters.agent_eligibility ?? '',
    registered_from: props.filters.registered_from ?? '',
    registered_to: props.filters.registered_to ?? '',
    sort: props.filters.sort ?? 'created_at',
    direction: props.filters.direction ?? 'desc',
    per_page: props.filters.per_page ?? 25,
});

const applyFilters = () => {
    const params: Record<string, any> = {};
    if (filterForm.search) params.search = filterForm.search;
    if (filterForm.operational_status)
        params.operational_status = filterForm.operational_status;
    if (filterForm.account_state)
        params.account_state = filterForm.account_state;
    if (filterForm.assigned_agent && filterForm.assigned_agent !== 'all')
        params.assigned_agent = filterForm.assigned_agent;
    if (filterForm.agent_eligibility)
        params.agent_eligibility = filterForm.agent_eligibility;
    if (filterForm.registered_from)
        params.registered_from = filterForm.registered_from;
    if (filterForm.registered_to)
        params.registered_to = filterForm.registered_to;
    if (filterForm.sort && filterForm.sort !== 'created_at')
        params.sort = filterForm.sort;
    if (filterForm.direction && filterForm.direction !== 'desc')
        params.direction = filterForm.direction;
    if (filterForm.per_page && filterForm.per_page !== 25)
        params.per_page = filterForm.per_page;

    router.get('/customers', params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const updateDateFilter = (
    field: 'registered_from' | 'registered_to',
    value: string,
): void => {
    filterForm[field] = value;
    applyFilters();
};

const resetFilters = () => {
    filterForm.search = '';
    filterForm.operational_status = '';
    filterForm.account_state = '';
    filterForm.assigned_agent = '';
    filterForm.agent_eligibility = '';
    filterForm.registered_from = '';
    filterForm.registered_to = '';
    filterForm.sort = 'created_at';
    filterForm.direction = 'desc';
    filterForm.per_page = 25;

    router.get(
        '/customers',
        {},
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
};

const getInitials = (name: string) => {
    const parts = name.trim().split(/\s+/);
    if (parts.length === 0 || !parts[0]) return 'CU';
    if (parts.length === 1) return parts[0].substring(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
};

const getOperationalBadgeVariant = (status: string) => {
    switch (status) {
        case 'active':
            return 'default';
        case 'inactive':
            return 'secondary';
        case 'restricted':
        case 'archived':
            return 'destructive';
        default:
            return 'outline';
    }
};

const getAccountBadgeVariant = (state: string) => {
    switch (state) {
        case 'active':
            return 'outline';
        case 'suspended':
        case 'deactivated':
            return 'destructive';
        default:
            return 'secondary';
    }
};
</script>

<template>
    <Head title="Customer Directory" />

    <div class="space-y-6">
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">
                Customer Directory
            </h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                Scoped directory of registered customer accounts, statuses, and
                assignment records.
            </p>
        </div>

        <!-- Filter Controls -->
        <Card>
            <CardHeader class="pb-3">
                <div class="flex items-center justify-between">
                    <CardTitle
                        class="flex items-center gap-2 text-base font-medium"
                    >
                        <Filter class="h-4 w-4" /> Filter & Search Customers
                    </CardTitle>
                    <Button
                        variant="ghost"
                        size="sm"
                        @click="resetFilters"
                        class="text-xs"
                    >
                        <RotateCcw class="mr-1.5 h-3.5 w-3.5" /> Clear filters
                    </Button>
                </div>
            </CardHeader>
            <CardContent>
                <form @submit.prevent="applyFilters" class="space-y-4">
                    <div class="flex flex-row flex-wrap gap-4">
                        <!-- Search input -->
                        <div class="w-fit space-y-1.5">
                            <Label for="search" class="text-xs">Search</Label>
                            <div class="relative">
                                <Search
                                    class="text-muted-foreground absolute top-2.5 left-2.5 h-4 w-4"
                                />
                                <Input
                                    id="search"
                                    v-model="filterForm.search"
                                    placeholder="Name, ID, phone, email, ref"
                                    class="pl-8 text-sm"
                                    @keyup.enter="applyFilters"
                                />
                            </div>
                        </div>

                        <!-- Operational Status -->
                        <div class="w-fit space-y-1.5">
                            <Label for="operational_status" class="text-xs"
                                >Operational Status</Label
                            >
                            <Select
                                v-model="filterForm.operational_status"
                                @update:model-value="applyFilters"
                            >
                                <SelectTrigger
                                    id="operational_status"
                                    class="text-sm"
                                >
                                    <SelectValue
                                        placeholder="Non-archived (Default)"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="active"
                                        >Active</SelectItem
                                    >
                                    <SelectItem value="inactive"
                                        >Inactive</SelectItem
                                    >
                                    <SelectItem value="restricted"
                                        >Restricted</SelectItem
                                    >
                                    <SelectItem value="archived"
                                        >Archived</SelectItem
                                    >
                                    <SelectItem value="all"
                                        >All statuses</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>

                        <!-- Account State -->
                        <div class="w-fit space-y-1.5">
                            <Label for="account_state" class="text-xs"
                                >Account State</Label
                            >
                            <Select
                                v-model="filterForm.account_state"
                                @update:model-value="applyFilters"
                            >
                                <SelectTrigger
                                    id="account_state"
                                    class="text-sm"
                                >
                                    <SelectValue
                                        placeholder="All account states"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all"
                                        >All states</SelectItem
                                    >
                                    <SelectItem value="active"
                                        >Active</SelectItem
                                    >
                                    <SelectItem value="invited"
                                        >Invited</SelectItem
                                    >
                                    <SelectItem value="mfa_setup"
                                        >MFA Setup</SelectItem
                                    >
                                    <SelectItem value="suspended"
                                        >Suspended</SelectItem
                                    >
                                    <SelectItem value="deactivated"
                                        >Deactivated</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>

                        <!-- Assigned Agent (Admin Only) -->
                        <div
                            v-if="viewer_type === 'admin'"
                            class="w-fit space-y-1.5"
                        >
                            <Label for="assigned_agent" class="text-xs"
                                >Assigned Agent</Label
                            >
                            <Select
                                v-model="filterForm.assigned_agent"
                                @update:model-value="applyFilters"
                            >
                                <SelectTrigger
                                    id="assigned_agent"
                                    class="text-sm"
                                >
                                    <SelectValue placeholder="All agents" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all"
                                        >All agents</SelectItem
                                    >
                                    <SelectItem
                                        v-for="agent in available_agents"
                                        :key="agent.id"
                                        :value="agent.id"
                                    >
                                        {{ agent.name }} ({{ agent.id }})
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <!-- Agent Eligibility (Admin Only) -->
                        <div
                            v-if="viewer_type === 'admin'"
                            class="w-fit space-y-1.5"
                        >
                            <Label for="agent_eligibility" class="text-xs"
                                >Agent Eligibility</Label
                            >
                            <Select
                                v-model="filterForm.agent_eligibility"
                                @update:model-value="applyFilters"
                            >
                                <SelectTrigger
                                    id="agent_eligibility"
                                    class="text-sm"
                                >
                                    <SelectValue
                                        placeholder="Any eligibility"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all"
                                        >Any eligibility</SelectItem
                                    >
                                    <SelectItem value="eligible"
                                        >Assigned Agent Eligible</SelectItem
                                    >
                                    <SelectItem value="ineligible"
                                        >Assigned Agent Ineligible</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>

                        <!-- Registered From -->
                        <div class="w-fit space-y-1.5">
                            <Label for="registered_from" class="text-xs"
                                >Registered From</Label
                            >
                            <DatePicker
                                id="registered_from"
                                :model-value="filterForm.registered_from"
                                @update:model-value="
                                    updateDateFilter('registered_from', $event)
                                "
                            />
                        </div>

                        <!-- Registered To -->
                        <div class="w-fit space-y-1.5">
                            <Label for="registered_to" class="text-xs"
                                >Registered To</Label
                            >
                            <DatePicker
                                id="registered_to"
                                :model-value="filterForm.registered_to"
                                @update:model-value="
                                    updateDateFilter('registered_to', $event)
                                "
                            />
                        </div>

                        <!-- Per Page -->
                        <div class="w-fit space-y-1.5">
                            <Label for="per_page" class="text-xs"
                                >Rows Per Page</Label
                            >
                            <Select
                                v-model="filterForm.per_page"
                                @update:model-value="applyFilters"
                            >
                                <SelectTrigger id="per_page" class="text-sm">
                                    <SelectValue placeholder="25" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem :value="25"
                                        >25 records</SelectItem
                                    >
                                    <SelectItem :value="50"
                                        >50 records</SelectItem
                                    >
                                    <SelectItem :value="100"
                                        >100 records</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <div
                        class="text-muted-foreground flex items-center justify-between border-t pt-2 text-xs"
                    >
                        <div>
                            Matching
                            <strong class="text-foreground">{{
                                customers.total
                            }}</strong>
                            customer{{ customers.total === 1 ? '' : 's' }} in
                            scope.
                        </div>
                        <Button type="submit" size="sm" variant="default"
                            >Apply filters</Button
                        >
                    </div>
                </form>
            </CardContent>
        </Card>

        <!-- Customer List Card -->
        <Card>
            <CardHeader class="pb-3">
                <div class="flex items-center justify-between">
                    <div>
                        <CardTitle>Customers</CardTitle>
                        <CardDescription>
                            Showing {{ customers.data.length }} of
                            {{ customers.total }} matching records.
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <!-- Empty State -->
                <div
                    v-if="customers.data.length === 0"
                    class="py-12 text-center"
                >
                    <div
                        class="bg-muted text-muted-foreground mx-auto flex h-12 w-12 items-center justify-center rounded-full"
                    >
                        <Users class="h-6 w-6" />
                    </div>
                    <h3 class="text-foreground mt-4 text-sm font-semibold">
                        No customers found
                    </h3>
                    <p
                        class="text-muted-foreground mx-auto mt-1 max-w-sm text-sm"
                    >
                        <template
                            v-if="
                                filterForm.search ||
                                filterForm.operational_status ||
                                filterForm.account_state ||
                                filterForm.registered_from
                            "
                        >
                            No customers match the current filter criteria.
                        </template>
                        <template v-else-if="viewer_type === 'agent'">
                            No customers are currently assigned to your account.
                        </template>
                        <template v-else>
                            No customers are currently available in the system
                            scope.
                        </template>
                    </p>
                    <div
                        v-if="
                            filterForm.search ||
                            filterForm.operational_status ||
                            filterForm.account_state
                        "
                        class="mt-4"
                    >
                        <Button
                            variant="outline"
                            size="sm"
                            @click="resetFilters"
                            >Clear filters</Button
                        >
                    </div>
                </div>

                <!-- Desktop Table View -->
                <div v-else class="hidden overflow-x-auto md:block">
                    <table class="w-full text-left text-sm">
                        <thead
                            class="bg-muted/40 text-muted-foreground border-b text-xs uppercase"
                        >
                            <tr>
                                <th class="px-4 py-3">Customer</th>
                                <th
                                    v-if="viewer_type === 'admin'"
                                    class="px-4 py-3"
                                >
                                    Assigned Agent
                                </th>
                                <th class="px-4 py-3">Operational Status</th>
                                <th class="px-4 py-3">Account State</th>
                                <th class="px-4 py-3">Plan</th>
                                <th class="px-4 py-3">Registered</th>
                                <th class="px-4 py-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="customer in customers.data"
                                :key="customer.id"
                                class="hover:bg-muted/50 transition-colors"
                            >
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <Avatar class="h-9 w-9">
                                            <AvatarImage
                                                v-if="customer.photo_url"
                                                :src="customer.photo_url"
                                                :alt="customer.name"
                                            />
                                            <AvatarFallback>{{
                                                getInitials(customer.name)
                                            }}</AvatarFallback>
                                        </Avatar>
                                        <div>
                                            <div
                                                class="text-foreground flex items-center gap-2 font-medium"
                                            >
                                                <span>{{ customer.name }}</span>
                                                <span
                                                    class="text-muted-foreground font-mono text-xs"
                                                    >({{ customer.id }})</span
                                                >
                                            </div>
                                            <div
                                                class="text-muted-foreground text-xs"
                                            >
                                                {{ customer.phone }}
                                                <span v-if="customer.email"
                                                    >·
                                                    {{ customer.email }}</span
                                                >
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td
                                    v-if="viewer_type === 'admin'"
                                    class="px-4 py-3"
                                >
                                    <div
                                        v-if="customer.assigned_agent"
                                        class="space-y-0.5"
                                    >
                                        <div class="text-xs font-medium">
                                            {{ customer.assigned_agent.name }}
                                        </div>
                                        <div
                                            class="text-muted-foreground font-mono text-[11px]"
                                        >
                                            {{ customer.assigned_agent.id }}
                                        </div>
                                    </div>
                                    <span
                                        v-else
                                        class="text-muted-foreground text-xs italic"
                                        >Unassigned</span
                                    >
                                </td>
                                <td class="px-4 py-3">
                                    <Badge
                                        :variant="
                                            getOperationalBadgeVariant(
                                                customer.operational_status,
                                            )
                                        "
                                        class="text-xs"
                                    >
                                        {{ customer.operational_status_label }}
                                    </Badge>
                                </td>
                                <td class="px-4 py-3">
                                    <Badge
                                        :variant="
                                            getAccountBadgeVariant(
                                                customer.account_state,
                                            )
                                        "
                                        class="text-xs"
                                    >
                                        {{ customer.account_state_label }}
                                    </Badge>
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="text-muted-foreground bg-muted/60 rounded px-2 py-0.5 text-xs italic"
                                    >
                                        Unavailable
                                    </span>
                                </td>
                                <td
                                    class="text-muted-foreground px-4 py-3 text-xs whitespace-nowrap"
                                >
                                    {{ customer.registered_at }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <Link :href="`/customers/${customer.id}`">
                                        <Button variant="outline" size="sm">
                                            <span>View</span>
                                            <ChevronRight
                                                class="ml-1 h-3.5 w-3.5"
                                            />
                                        </Button>
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Card View -->
                <div
                    v-if="customers.data.length > 0"
                    class="divide-y md:hidden"
                >
                    <div
                        v-for="customer in customers.data"
                        :key="customer.id"
                        class="space-y-3 py-4"
                    >
                        <div class="flex items-start justify-between">
                            <div class="flex items-center gap-3">
                                <Avatar class="h-9 w-9">
                                    <AvatarImage
                                        v-if="customer.photo_url"
                                        :src="customer.photo_url"
                                        :alt="customer.name"
                                    />
                                    <AvatarFallback>{{
                                        getInitials(customer.name)
                                    }}</AvatarFallback>
                                </Avatar>
                                <div>
                                    <div
                                        class="text-foreground text-sm font-medium"
                                    >
                                        {{ customer.name }}
                                    </div>
                                    <div
                                        class="text-muted-foreground font-mono text-xs"
                                    >
                                        {{ customer.id }}
                                    </div>
                                </div>
                            </div>
                            <Link :href="`/customers/${customer.id}`">
                                <Button variant="outline" size="sm">
                                    <span>View</span>
                                    <ChevronRight class="ml-1 h-3.5 w-3.5" />
                                </Button>
                            </Link>
                        </div>

                        <div class="grid grid-cols-2 gap-2 text-xs">
                            <div>
                                <span class="text-muted-foreground"
                                    >Phone:
                                </span>
                                <span>{{ customer.phone }}</span>
                            </div>
                            <div>
                                <span class="text-muted-foreground"
                                    >Registered:
                                </span>
                                <span>{{ customer.registered_at }}</span>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <Badge
                                :variant="
                                    getOperationalBadgeVariant(
                                        customer.operational_status,
                                    )
                                "
                                class="text-xs"
                            >
                                {{ customer.operational_status_label }}
                            </Badge>
                            <Badge
                                :variant="
                                    getAccountBadgeVariant(
                                        customer.account_state,
                                    )
                                "
                                class="text-xs"
                            >
                                {{ customer.account_state_label }}
                            </Badge>
                            <span
                                v-if="
                                    customer.assigned_agent &&
                                    viewer_type === 'admin'
                                "
                                class="text-muted-foreground text-xs"
                            >
                                Agent: {{ customer.assigned_agent.name }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Pagination -->
                <div
                    v-if="customers.last_page > 1"
                    class="text-muted-foreground mt-4 flex items-center justify-between border-t pt-4 text-xs"
                >
                    <div>
                        Showing page {{ customers.current_page }} of
                        {{ customers.last_page }}
                    </div>
                    <div class="flex items-center gap-2">
                        <Link
                            v-if="customers.prev_page_url"
                            :href="customers.prev_page_url"
                            preserve-state
                            preserve-scroll
                        >
                            <Button variant="outline" size="sm"
                                >Previous</Button
                            >
                        </Link>
                        <Button v-else variant="outline" size="sm" disabled
                            >Previous</Button
                        >

                        <Link
                            v-if="customers.next_page_url"
                            :href="customers.next_page_url"
                            preserve-state
                            preserve-scroll
                        >
                            <Button variant="outline" size="sm">Next</Button>
                        </Link>
                        <Button v-else variant="outline" size="sm" disabled
                            >Next</Button
                        >
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
