<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { dashboard } from '@/routes';
import {
    Briefcase,
    CheckCircle2,
    ChevronRight,
    Filter,
    RotateCcw,
    Search,
    ShieldAlert,
    UserCheck,
    Users,
    XCircle,
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

export type AgentEligibility = {
    is_eligible: boolean;
    reason: string | null;
    explanation: string | null;
};

export type AgentItem = {
    id: string;
    name: string;
    photo_url: string | null;
    email: string;
    phone: string;
    operational_status: string;
    operational_status_label: string;
    account_state: string;
    account_state_label: string;
    eligibility: AgentEligibility;
    current_customers_count: number;
    archived_customers_count: number;
    registered_at: string;
    registered_at_iso: string;
};

export type PaginatedAgents = {
    data: AgentItem[];
    current_page: number;
    last_page: number;
    total: number;
    next_page_url: string | null;
    prev_page_url: string | null;
};

const props = defineProps<{
    agents: PaginatedAgents;
    filters: {
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
                title: 'Agents',
                href: '/agents',
            },
        ],
    },
});

const filterForm = reactive({
    search: props.filters.search ?? '',
    operational_status: props.filters.operational_status ?? '',
    account_state: props.filters.account_state ?? '',
    eligibility: props.filters.eligibility ?? '',
    min_customers: props.filters.min_customers ?? '',
    max_customers: props.filters.max_customers ?? '',
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
    if (filterForm.eligibility) params.eligibility = filterForm.eligibility;
    if (filterForm.min_customers !== '')
        params.min_customers = filterForm.min_customers;
    if (filterForm.max_customers !== '')
        params.max_customers = filterForm.max_customers;
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

    router.get('/agents', params, {
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
    filterForm.eligibility = '';
    filterForm.min_customers = '';
    filterForm.max_customers = '';
    filterForm.registered_from = '';
    filterForm.registered_to = '';
    filterForm.sort = 'created_at';
    filterForm.direction = 'desc';
    filterForm.per_page = 25;

    router.get(
        '/agents',
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
    if (parts.length === 0 || !parts[0]) return 'AG';
    if (parts.length === 1) return parts[0].substring(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
};

const getOperationalBadgeVariant = (status: string) => {
    switch (status) {
        case 'active':
            return 'default';
        case 'inactive':
            return 'secondary';
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
    <Head title="Agent Directory" />

    <div class="space-y-6">
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">
                Agent Directory
            </h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                Directory of registered field agents, operational status,
                eligibility, and assigned customer workload.
            </p>
        </div>

        <!-- Filter Controls -->
        <Card>
            <CardHeader class="pb-3">
                <div class="flex items-center justify-between">
                    <CardTitle
                        class="flex items-center gap-2 text-base font-medium"
                    >
                        <Filter class="h-4 w-4" /> Filter & Search Agents
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
                                    placeholder="Name, ID, phone, email"
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
                                        placeholder="All operational statuses"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all"
                                        >All operational statuses</SelectItem
                                    >
                                    <SelectItem value="active"
                                        >Active</SelectItem
                                    >
                                    <SelectItem value="inactive"
                                        >Inactive</SelectItem
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
                                        placeholder="Non-deactivated (Default)"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all"
                                        >All states (incl.
                                        Deactivated)</SelectItem
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

                        <!-- Eligibility -->
                        <div class="w-fit space-y-1.5">
                            <Label for="eligibility" class="text-xs"
                                >Assignment Eligibility</Label
                            >
                            <Select
                                v-model="filterForm.eligibility"
                                @update:model-value="applyFilters"
                            >
                                <SelectTrigger id="eligibility" class="text-sm">
                                    <SelectValue
                                        placeholder="All eligibility states"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all"
                                        >All eligibility states</SelectItem
                                    >
                                    <SelectItem value="eligible"
                                        >Eligible to Receive
                                        Assignments</SelectItem
                                    >
                                    <SelectItem value="ineligible"
                                        >Ineligible</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>

                        <!-- Min Customers -->
                        <div class="w-fit space-y-1.5">
                            <Label for="min_customers" class="text-xs"
                                >Min Customers</Label
                            >
                            <Input
                                id="min_customers"
                                type="number"
                                min="0"
                                v-model="filterForm.min_customers"
                                placeholder="0"
                                class="text-sm"
                                @change="applyFilters"
                            />
                        </div>

                        <!-- Max Customers -->
                        <div class="w-fit space-y-1.5">
                            <Label for="max_customers" class="text-xs"
                                >Max Customers</Label
                            >
                            <Input
                                id="max_customers"
                                type="number"
                                min="0"
                                v-model="filterForm.max_customers"
                                placeholder="Any"
                                class="text-sm"
                                @change="applyFilters"
                            />
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
                    </div>

                    <div
                        class="text-muted-foreground flex items-center justify-between border-t pt-2 text-xs"
                    >
                        <div>
                            Matching
                            <strong class="text-foreground">{{
                                agents.total
                            }}</strong>
                            agent{{ agents.total === 1 ? '' : 's' }}.
                        </div>
                        <Button type="submit" size="sm" variant="default"
                            >Apply filters</Button
                        >
                    </div>
                </form>
            </CardContent>
        </Card>

        <!-- Agent List Card -->
        <Card>
            <CardHeader class="pb-3">
                <div class="flex items-center justify-between">
                    <div>
                        <CardTitle>Agents</CardTitle>
                        <CardDescription>
                            Showing {{ agents.data.length }} of
                            {{ agents.total }} registered agents.
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <!-- Empty State -->
                <div v-if="agents.data.length === 0" class="py-12 text-center">
                    <div
                        class="bg-muted text-muted-foreground mx-auto flex h-12 w-12 items-center justify-center rounded-full"
                    >
                        <Briefcase class="h-6 w-6" />
                    </div>
                    <h3 class="text-foreground mt-4 text-sm font-semibold">
                        No agents found
                    </h3>
                    <p
                        class="text-muted-foreground mx-auto mt-1 max-w-sm text-sm"
                    >
                        <template
                            v-if="
                                filterForm.search ||
                                filterForm.operational_status ||
                                filterForm.account_state ||
                                filterForm.eligibility
                            "
                        >
                            No agents match the current filter criteria.
                        </template>
                        <template v-else>
                            No agent records are currently registered in the
                            system.
                        </template>
                    </p>
                    <div
                        v-if="
                            filterForm.search ||
                            filterForm.operational_status ||
                            filterForm.account_state ||
                            filterForm.eligibility
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
                                <th class="px-4 py-3">Agent</th>
                                <th class="px-4 py-3">Operational Status</th>
                                <th class="px-4 py-3">Account State</th>
                                <th class="px-4 py-3">Eligibility</th>
                                <th class="px-4 py-3">Assigned Customers</th>
                                <th class="px-4 py-3">Registered</th>
                                <th class="px-4 py-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="agent in agents.data"
                                :key="agent.id"
                                class="hover:bg-muted/50 transition-colors"
                            >
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <Avatar class="h-9 w-9">
                                            <AvatarImage
                                                v-if="agent.photo_url"
                                                :src="agent.photo_url"
                                                :alt="agent.name"
                                            />
                                            <AvatarFallback>{{
                                                getInitials(agent.name)
                                            }}</AvatarFallback>
                                        </Avatar>
                                        <div>
                                            <div
                                                class="text-foreground flex items-center gap-2 font-medium"
                                            >
                                                <span>{{ agent.name }}</span>
                                                <span
                                                    class="text-muted-foreground font-mono text-xs"
                                                    >({{ agent.id }})</span
                                                >
                                            </div>
                                            <div
                                                class="text-muted-foreground text-xs"
                                            >
                                                {{ agent.phone }} ·
                                                {{ agent.email }}
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <Badge
                                        :variant="
                                            getOperationalBadgeVariant(
                                                agent.operational_status,
                                            )
                                        "
                                        class="text-xs"
                                    >
                                        {{ agent.operational_status_label }}
                                    </Badge>
                                </td>
                                <td class="px-4 py-3">
                                    <Badge
                                        :variant="
                                            getAccountBadgeVariant(
                                                agent.account_state,
                                            )
                                        "
                                        class="text-xs"
                                    >
                                        {{ agent.account_state_label }}
                                    </Badge>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-1.5">
                                        <Badge
                                            :variant="
                                                agent.eligibility.is_eligible
                                                    ? 'default'
                                                    : 'secondary'
                                            "
                                            class="flex items-center gap-1 text-xs"
                                        >
                                            <CheckCircle2
                                                v-if="
                                                    agent.eligibility
                                                        .is_eligible
                                                "
                                                class="h-3 w-3 text-emerald-400"
                                            />
                                            <XCircle
                                                v-else
                                                class="h-3 w-3 text-amber-500"
                                            />
                                            <span>{{
                                                agent.eligibility.is_eligible
                                                    ? 'Ready'
                                                    : 'Ineligible'
                                            }}</span>
                                        </Badge>
                                    </div>
                                    <div
                                        v-if="
                                            !agent.eligibility.is_eligible &&
                                            agent.eligibility.explanation
                                        "
                                        class="text-muted-foreground mt-0.5 max-w-xs text-[11px]"
                                    >
                                        {{ agent.eligibility.explanation }}
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-baseline gap-1.5">
                                        <span
                                            class="text-foreground font-medium"
                                            >{{
                                                agent.current_customers_count
                                            }}</span
                                        >
                                        <span
                                            class="text-muted-foreground text-xs"
                                            >active</span
                                        >
                                        <span
                                            v-if="
                                                agent.archived_customers_count >
                                                0
                                            "
                                            class="text-muted-foreground text-[11px]"
                                        >
                                            (+{{
                                                agent.archived_customers_count
                                            }}
                                            archived)
                                        </span>
                                    </div>
                                </td>
                                <td
                                    class="text-muted-foreground px-4 py-3 text-xs whitespace-nowrap"
                                >
                                    {{ agent.registered_at }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <Link :href="`/agents/${agent.id}`">
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
                <div v-if="agents.data.length > 0" class="divide-y md:hidden">
                    <div
                        v-for="agent in agents.data"
                        :key="agent.id"
                        class="space-y-3 py-4"
                    >
                        <div class="flex items-start justify-between">
                            <div class="flex items-center gap-3">
                                <Avatar class="h-9 w-9">
                                    <AvatarImage
                                        v-if="agent.photo_url"
                                        :src="agent.photo_url"
                                        :alt="agent.name"
                                    />
                                    <AvatarFallback>{{
                                        getInitials(agent.name)
                                    }}</AvatarFallback>
                                </Avatar>
                                <div>
                                    <div
                                        class="text-foreground text-sm font-medium"
                                    >
                                        {{ agent.name }}
                                    </div>
                                    <div
                                        class="text-muted-foreground font-mono text-xs"
                                    >
                                        {{ agent.id }}
                                    </div>
                                </div>
                            </div>
                            <Link :href="`/agents/${agent.id}`">
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
                                <span>{{ agent.phone }}</span>
                            </div>
                            <div>
                                <span class="text-muted-foreground"
                                    >Assigned:
                                </span>
                                <span class="font-medium"
                                    >{{
                                        agent.current_customers_count
                                    }}
                                    customers</span
                                >
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <Badge
                                :variant="
                                    getOperationalBadgeVariant(
                                        agent.operational_status,
                                    )
                                "
                                class="text-xs"
                            >
                                {{ agent.operational_status_label }}
                            </Badge>
                            <Badge
                                :variant="
                                    getAccountBadgeVariant(agent.account_state)
                                "
                                class="text-xs"
                            >
                                {{ agent.account_state_label }}
                            </Badge>
                            <Badge
                                :variant="
                                    agent.eligibility.is_eligible
                                        ? 'default'
                                        : 'secondary'
                                "
                                class="text-xs"
                            >
                                {{
                                    agent.eligibility.is_eligible
                                        ? 'Eligible'
                                        : 'Ineligible'
                                }}
                            </Badge>
                        </div>
                    </div>
                </div>

                <!-- Pagination -->
                <div
                    v-if="agents.last_page > 1"
                    class="text-muted-foreground mt-4 flex items-center justify-between border-t pt-4 text-xs"
                >
                    <div>
                        Showing page {{ agents.current_page }} of
                        {{ agents.last_page }}
                    </div>
                    <div class="flex items-center gap-2">
                        <Link
                            v-if="agents.prev_page_url"
                            :href="agents.prev_page_url"
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
                            v-if="agents.next_page_url"
                            :href="agents.next_page_url"
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
