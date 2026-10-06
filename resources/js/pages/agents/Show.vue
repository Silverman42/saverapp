<script setup lang="ts">
import ManagementDeliveryPanel from '@/components/ManagementDeliveryPanel.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { dashboard } from '@/routes';
import {
    edit as editAgent,
    index as agentsIndex,
    show as agentsShow,
} from '@/routes/agents';
import { edit as editAgentStatus } from '@/routes/agents/status';
import { show as showLifecycle } from '@/actions/App/Http/Controllers/AgentLifecycleController';
import { create as createRecovery } from '@/routes/admin/staff-recoveries';
import {
    cancel as cancelInvitation,
    correctEmail as correctEmailInvitation,
    resend as resendInvitation,
} from '@/routes/agents/invitations';
import { show as customersShow } from '@/routes/customers';
import {
    ChevronRight,
    Mail,
    MoreHorizontal,
    Pencil,
    Phone,
    RefreshCw,
    Users,
    XCircle,
} from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import MoreDetails from '@/components/MoreDetails.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import DirectoryPanel from '@/components/directory/DirectoryPanel.vue';
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
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

export type CapabilityCheck = {
    eligible: boolean;
    reason: string | null;
    explanation: string | null;
};

export type AssignedCustomer = {
    id: string;
    name: string;
    operational_status: string;
    operational_status_label: string;
    account_state: string | null;
    assigned_since: string | null;
};

export type AgentDetail = {
    id: string;
    name: string;
    first_name: string;
    last_name: string;
    email: string;
    phone: string;
    address: string | null;
    employment_date: string | null;
    photo_url: string | null;
    operational_status: string;
    operational_status_label: string;
    account_state: string;
    account_state_label: string;
    registered_at: string;
    registered_at_iso: string;
    readiness: {
        can_read_assigned: CapabilityCheck;
        can_perform_work: CapabilityCheck;
        can_receive_assignment: CapabilityCheck;
    };
    assignments_summary: {
        active_count: number;
        inactive_count: number;
        restricted_count: number;
        archived_count: number;
        total_active_workload: number;
    };
    invitation_and_access: {
        account_state: string;
        mfa_confirmed: boolean;
    };
    collections_and_reconciliation: {
        status: string;
        message: string;
    };
    invitation?: {
        status: string;
        status_label: string;
        delivery_status: string;
        delivery_status_label: string;
        generation: number;
        can_resend: boolean;
        sent_at: string | null;
        opened_at: string | null;
        expires_at: string | null;
        delivery_error: string | null;
    } | null;
    notes?: string | null;
    lifecycle?: {
        operational_status: string;
        created_at: string;
        updated_at: string;
    };
    actions: {
        can_edit: boolean;
        edit_message?: string | null;
        can_reassign_customers: boolean;
        reassign_message: string;
        can_manage_lifecycle: boolean;
        lifecycle_message: string;
        can_manage_invitation?: boolean;
        can_request_recovery?: boolean;
        recovery_user_id?: number;
    };
};

export type PaginatedAssignedCustomers = {
    data: AssignedCustomer[];
    current_page: number;
    last_page: number;
    total: number;
    next_page_url: string | null;
    prev_page_url: string | null;
};

type AssignmentFilters = {
    search: string;
    operational_status: string;
    per_page: number;
};

const props = defineProps<{
    agent: AgentDetail;
    viewer_type: string;
    assigned_customers: PaginatedAssignedCustomers;
    assignment_filters: AssignmentFilters;
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
                href: agentsIndex(),
            },
        ],
    },
});

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

const getInvitationBadgeVariant = (status: string) => {
    switch (status) {
        case 'sent':
        case 'opened':
            return 'outline';
        case 'activated':
            return 'default';
        case 'delivery_failed':
        case 'expired':
        case 'cancelled':
            return 'destructive';
        default:
            return 'secondary';
    }
};

const hasMoreActions = computed(
    () =>
        props.agent.actions.can_manage_lifecycle ||
        (props.agent.actions.can_request_recovery &&
            !!props.agent.actions.recovery_user_id),
);

const abilities = computed(() => [
    {
        key: 'read',
        label: 'See their customers',
        check: props.agent.readiness.can_read_assigned,
        fallback: 'Can view the customers assigned to them.',
    },
    {
        key: 'work',
        label: 'Work with customers',
        check: props.agent.readiness.can_perform_work,
        fallback: 'Can record collections for their customers.',
    },
    {
        key: 'assign',
        label: 'Take new customers',
        check: props.agent.readiness.can_receive_assignment,
        fallback: 'Can be given new customers.',
    },
]);

const isResending = ref(false);
const handleResendInvitation = () => {
    if (!props.agent.invitation?.can_resend) return;
    isResending.value = true;
    router.post(
        resendInvitation(props.agent.id).url,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                isResending.value = false;
            },
        },
    );
};

const showCorrectEmailModal = ref(false);
const correctEmailForm = reactive({
    email: '',
    reason: '',
    processing: false,
    error: '',
});

const openCorrectEmailModal = () => {
    correctEmailForm.email = props.agent.email || '';
    correctEmailForm.reason = '';
    correctEmailForm.error = '';
    showCorrectEmailModal.value = true;
};

const submitCorrectEmail = () => {
    if (!correctEmailForm.email || !correctEmailForm.reason) {
        correctEmailForm.error = 'Enter the new email and a reason.';
        return;
    }
    correctEmailForm.processing = true;
    router.post(
        correctEmailInvitation(props.agent.id).url,
        {
            email: correctEmailForm.email,
            reason: correctEmailForm.reason,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                showCorrectEmailModal.value = false;
            },
            onError: (errors) => {
                correctEmailForm.error = Object.values(errors)[0] as string;
            },
            onFinish: () => {
                correctEmailForm.processing = false;
            },
        },
    );
};

const showCancelModal = ref(false);
const cancelForm = reactive({
    reason: '',
    processing: false,
    error: '',
});

const openCancelModal = () => {
    cancelForm.reason = '';
    cancelForm.error = '';
    showCancelModal.value = true;
};

const submitCancelInvitation = () => {
    if (!cancelForm.reason) {
        cancelForm.error = 'Add a reason for cancelling.';
        return;
    }
    cancelForm.processing = true;
    router.post(
        cancelInvitation(props.agent.id).url,
        {
            reason: cancelForm.reason,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                showCancelModal.value = false;
            },
            onError: (errors) => {
                cancelForm.error = Object.values(errors)[0] as string;
            },
            onFinish: () => {
                cancelForm.processing = false;
            },
        },
    );
};

const assignmentFiltersOpen = ref(false);
const assignmentFilterForm = reactive<AssignmentFilters>({
    ...props.assignment_filters,
});

watch(
    () => props.assignment_filters,
    (filters) => Object.assign(assignmentFilterForm, filters),
);

const activeAssignmentFilterCount = computed(() => {
    return assignmentFilterForm.operational_status &&
        assignmentFilterForm.operational_status !== 'all'
        ? 1
        : 0;
});

const applyAssignmentFilters = (): void => {
    const query: Record<string, string | number> = {};
    if (assignmentFilterForm.search)
        query.assignments_search = assignmentFilterForm.search;
    if (
        assignmentFilterForm.operational_status &&
        assignmentFilterForm.operational_status !== 'all'
    )
        query.assignments_operational_status =
            assignmentFilterForm.operational_status;
    if (assignmentFilterForm.per_page !== 10)
        query.assignments_per_page = assignmentFilterForm.per_page;
    router.get(
        agentsShow(props.agent.id, { query }).url,
        {},
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const resetAssignmentFilters = (): void => {
    Object.assign(assignmentFilterForm, {
        search: '',
        operational_status: '',
        per_page: 10,
    });
    applyAssignmentFilters();
};
</script>

<template>
    <Head :title="agent.name" />

    <div class="space-y-6">
        <PageHeader :title="agent.name">
            <template v-if="agent.actions.can_edit || hasMoreActions" #actions>
                <Button v-if="agent.actions.can_edit" as-child>
                    <Link :href="editAgent(agent.id).url">
                        <Pencil class="size-4" /> Edit profile
                    </Link>
                </Button>
                <DropdownMenu :modal="false" v-if="hasMoreActions">
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline">
                            <MoreHorizontal class="size-4" /> More
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-52">
                        <template v-if="agent.actions.can_manage_lifecycle">
                            <DropdownMenuItem as-child>
                                <Link :href="editAgentStatus(agent.id).url"
                                    >Change status</Link
                                >
                            </DropdownMenuItem>
                            <DropdownMenuItem as-child>
                                <Link :href="showLifecycle(agent.id).url"
                                    >Account access</Link
                                >
                            </DropdownMenuItem>
                        </template>
                        <DropdownMenuItem
                            v-if="
                                agent.actions.can_request_recovery &&
                                agent.actions.recovery_user_id
                            "
                            as-child
                        >
                            <Link
                                :href="
                                    createRecovery(
                                        agent.actions.recovery_user_id,
                                    ).url
                                "
                                >Recover account</Link
                            >
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </template>
        </PageHeader>

        <!-- Profile summary -->
        <Card>
            <CardContent class="space-y-5">
                <div class="flex flex-col gap-5 sm:flex-row sm:items-center">
                    <Avatar class="size-16 shrink-0">
                        <AvatarImage
                            v-if="agent.photo_url"
                            :src="agent.photo_url"
                            :alt="agent.name"
                        />
                        <AvatarFallback class="text-lg font-semibold">{{
                            getInitials(agent.name)
                        }}</AvatarFallback>
                    </Avatar>
                    <div class="min-w-0 space-y-3">
                        <div class="flex flex-wrap gap-2">
                            <Badge
                                :variant="
                                    getOperationalBadgeVariant(
                                        agent.operational_status,
                                    )
                                "
                                >{{ agent.operational_status_label }}</Badge
                            >
                            <Badge
                                :variant="
                                    getAccountBadgeVariant(agent.account_state)
                                "
                                >Account: {{ agent.account_state_label }}</Badge
                            >
                        </div>
                        <ul
                            class="text-muted-foreground flex flex-col gap-x-5 gap-y-1.5 text-sm sm:flex-row sm:flex-wrap"
                        >
                            <li class="flex items-center gap-1.5">
                                <Phone class="size-4" />
                                <span class="text-foreground">{{
                                    agent.phone
                                }}</span>
                            </li>
                            <li class="flex min-w-0 items-center gap-1.5">
                                <Mail class="size-4 shrink-0" />
                                <span class="text-foreground truncate">{{
                                    agent.email
                                }}</span>
                            </li>
                            <li
                                v-if="agent.address"
                                class="flex min-w-0 items-center gap-1.5"
                            >
                                <span class="text-foreground">{{
                                    agent.address
                                }}</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <MoreDetails>
                    <dl
                        class="divide-border grid divide-y rounded-xl border text-sm"
                    >
                        <div class="flex justify-between gap-4 px-4 py-2.5">
                            <dt class="text-muted-foreground">Agent ID</dt>
                            <dd class="font-mono text-xs">{{ agent.id }}</dd>
                        </div>
                        <div class="flex justify-between gap-4 px-4 py-2.5">
                            <dt class="text-muted-foreground">Joined</dt>
                            <dd>{{ agent.registered_at }}</dd>
                        </div>
                        <div class="flex justify-between gap-4 px-4 py-2.5">
                            <dt class="text-muted-foreground">Start date</dt>
                            <dd>
                                {{ agent.employment_date || 'Not recorded' }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4 px-4 py-2.5">
                            <dt class="text-muted-foreground">
                                Two-step sign-in
                            </dt>
                            <dd>
                                {{
                                    agent.invitation_and_access.mfa_confirmed
                                        ? 'Set up'
                                        : 'Not set up yet'
                                }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4 px-4 py-2.5">
                            <dt class="text-muted-foreground">Collections</dt>
                            <dd class="max-w-xs text-right">
                                {{
                                    agent.collections_and_reconciliation.message
                                }}
                            </dd>
                        </div>
                    </dl>
                </MoreDetails>
            </CardContent>
        </Card>

        <!-- What the agent can do -->
        <Card>
            <CardHeader>
                <CardTitle>What they can do</CardTitle>
            </CardHeader>
            <CardContent>
                <ul class="divide-border -my-3 divide-y">
                    <li
                        v-for="ability in abilities"
                        :key="ability.key"
                        class="flex items-start justify-between gap-4 py-3"
                    >
                        <div class="min-w-0">
                            <p class="text-sm font-medium">
                                {{ ability.label }}
                            </p>
                            <p class="text-muted-foreground mt-0.5 text-xs">
                                {{
                                    ability.check.explanation ||
                                    ability.fallback
                                }}
                            </p>
                        </div>
                        <Badge
                            :variant="
                                ability.check.eligible ? 'default' : 'secondary'
                            "
                            class="shrink-0"
                            >{{ ability.check.eligible ? 'Yes' : 'No' }}</Badge
                        >
                    </li>
                </ul>
            </CardContent>
        </Card>

        <!-- Invitation (admins only) -->
        <Card v-if="agent.invitation">
            <CardHeader
                class="flex flex-row flex-wrap items-start justify-between gap-3"
            >
                <div class="space-y-1.5">
                    <CardTitle class="flex items-center gap-2">
                        Invitation
                        <Badge
                            :variant="
                                getInvitationBadgeVariant(
                                    agent.invitation.status,
                                )
                            "
                            >{{ agent.invitation.status_label }}</Badge
                        >
                    </CardTitle>
                    <CardDescription>
                        Sent {{ agent.invitation.sent_at || 'not yet' }}
                        <template v-if="agent.invitation.expires_at">
                            · Expires {{ agent.invitation.expires_at }}
                        </template>
                    </CardDescription>
                </div>
                <div
                    v-if="agent.actions.can_manage_invitation"
                    class="flex items-center gap-2"
                >
                    <Button
                        v-if="agent.invitation.can_resend"
                        variant="outline"
                        size="sm"
                        :disabled="isResending"
                        @click="handleResendInvitation"
                    >
                        <RefreshCw
                            class="size-4"
                            :class="{ 'animate-spin': isResending }"
                        />
                        Resend
                    </Button>
                    <DropdownMenu :modal="false">
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="outline"
                                size="sm"
                                aria-label="More invitation actions"
                            >
                                <MoreHorizontal class="size-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-48">
                            <DropdownMenuItem @select="openCorrectEmailModal">
                                <Pencil /> Change email
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                variant="destructive"
                                @select="openCancelModal"
                            >
                                <XCircle /> Cancel invitation
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </CardHeader>
            <CardContent class="space-y-4">
                <div
                    v-if="agent.invitation.delivery_error"
                    role="alert"
                    class="bg-destructive/10 text-destructive rounded-lg p-3 text-sm"
                >
                    <p class="font-medium">We couldn't deliver the invite</p>
                    <p class="mt-0.5 text-xs">
                        {{ agent.invitation.delivery_error }}
                    </p>
                </div>
                <MoreDetails>
                    <dl class="grid gap-3 text-sm sm:grid-cols-3">
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                Delivery
                            </dt>
                            <dd class="mt-1">
                                {{ agent.invitation.delivery_status_label }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                Opened
                            </dt>
                            <dd class="mt-1">
                                {{ agent.invitation.opened_at || 'Not yet' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                Invite number
                            </dt>
                            <dd class="mt-1">
                                #{{ agent.invitation.generation }}
                            </dd>
                        </div>
                    </dl>
                </MoreDetails>
            </CardContent>
        </Card>

        <DirectoryPanel
            title="Customers"
            :description="`${agent.assignments_summary.active_count} active · ${agent.assignments_summary.inactive_count} inactive · ${agent.assignments_summary.restricted_count} restricted · ${agent.assignments_summary.archived_count} archived`"
            :search-value="assignmentFilterForm.search"
            search-placeholder="Search customers"
            :filters-open="assignmentFiltersOpen"
            :active-filter-count="activeAssignmentFilterCount"
            @update:search-value="assignmentFilterForm.search = $event"
            @submit-search="applyAssignmentFilters"
            @toggle-filters="assignmentFiltersOpen = !assignmentFiltersOpen"
            @reset-filters="resetAssignmentFilters"
        >
            <template #filters>
                <div class="w-fit space-y-1.5">
                    <Label for="assignment-status" class="text-xs">Status</Label
                    ><Select
                        v-model="assignmentFilterForm.operational_status"
                        @update:model-value="applyAssignmentFilters"
                        ><SelectTrigger id="assignment-status"
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
            </template>
            <template #filter-summary
                ><p class="text-muted-foreground text-xs">
                    {{ assigned_customers.total }} customer{{
                        assigned_customers.total === 1 ? '' : 's'
                    }}
                    found
                </p></template
            >

            <EmptyState
                v-if="assigned_customers.data.length === 0"
                :icon="Users"
                title="No customers to show"
                description="Customers assigned to this agent will show up here."
            />
            <div v-else class="divide-border -my-2 divide-y">
                <Link
                    v-for="customer in assigned_customers.data"
                    :key="customer.id"
                    :href="customersShow(customer.id).url"
                    class="hover:bg-accent/35 focus-visible:ring-ring -mx-3 flex flex-wrap items-center gap-x-6 gap-y-2 rounded-xl px-3 py-3.5 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                >
                    <div class="min-w-0 flex-1 basis-48">
                        <p class="truncate text-sm font-medium">
                            {{ customer.name }}
                        </p>
                        <p class="text-muted-foreground text-xs">
                            <template v-if="customer.assigned_since"
                                >Since {{ customer.assigned_since }}</template
                            >
                            <template v-else>{{ customer.id }}</template>
                        </p>
                    </div>
                    <Badge
                        :variant="
                            getOperationalBadgeVariant(
                                customer.operational_status,
                            )
                        "
                        >{{ customer.operational_status_label }}</Badge
                    >
                    <ChevronRight
                        class="text-muted-foreground hidden size-4 sm:block"
                    />
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
                            v-model="assignmentFilterForm.per_page"
                            @update:model-value="applyAssignmentFilters"
                            ><SelectTrigger
                                class="h-9 w-20"
                                aria-label="Customers per page"
                                ><SelectValue /></SelectTrigger
                            ><SelectContent
                                ><SelectItem :value="10">10</SelectItem
                                ><SelectItem :value="25">25</SelectItem
                                ><SelectItem :value="50"
                                    >50</SelectItem
                                ></SelectContent
                            ></Select
                        >
                        per page
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-muted-foreground text-xs"
                            >Page {{ assigned_customers.current_page }} of
                            {{ assigned_customers.last_page }}</span
                        >
                        <div class="flex gap-2">
                            <Button
                                v-if="assigned_customers.prev_page_url"
                                as-child
                                variant="outline"
                                size="sm"
                                ><Link
                                    :href="assigned_customers.prev_page_url"
                                    preserve-state
                                    preserve-scroll
                                    >Previous</Link
                                ></Button
                            ><Button v-else variant="outline" size="sm" disabled
                                >Previous</Button
                            ><Button
                                v-if="assigned_customers.next_page_url"
                                as-child
                                variant="outline"
                                size="sm"
                                ><Link
                                    :href="assigned_customers.next_page_url"
                                    preserve-state
                                    preserve-scroll
                                    >Next</Link
                                ></Button
                            ><Button v-else variant="outline" size="sm" disabled
                                >Next</Button
                            >
                        </div>
                    </div>
                </div></template
            >
        </DirectoryPanel>

        <!-- Admin-only notes (omitted from agent self-service) -->
        <Card v-if="agent.notes !== undefined">
            <CardHeader>
                <CardTitle>Notes</CardTitle>
                <CardDescription>Only admins can see these.</CardDescription>
            </CardHeader>
            <CardContent>
                <p
                    v-if="agent.notes"
                    class="bg-muted/50 rounded-lg p-4 text-sm whitespace-pre-wrap"
                >
                    {{ agent.notes }}
                </p>
                <p v-else class="text-muted-foreground text-sm">
                    No notes yet.
                </p>
            </CardContent>
        </Card>

        <ManagementDeliveryPanel subject="agent" :reference="agent.id" />

        <!-- Change email dialog -->
        <Dialog v-model:open="showCorrectEmailModal">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Change email</DialogTitle>
                    <DialogDescription>
                        We'll send a new invite to this address. The old invite
                        will stop working.
                    </DialogDescription>
                </DialogHeader>
                <form
                    id="correct-email-form"
                    class="space-y-4 py-2"
                    @submit.prevent="submitCorrectEmail"
                >
                    <div class="space-y-1.5">
                        <Label for="correct-email">New email</Label>
                        <Input
                            id="correct-email"
                            v-model="correctEmailForm.email"
                            type="email"
                            required
                            placeholder="agent@example.ng"
                        />
                    </div>
                    <div class="space-y-1.5">
                        <Label for="correct-reason">Reason</Label>
                        <Input
                            id="correct-reason"
                            v-model="correctEmailForm.reason"
                            required
                            placeholder="e.g. Typo in the first email"
                        />
                    </div>
                    <p
                        v-if="correctEmailForm.error"
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        {{ correctEmailForm.error }}
                    </p>
                </form>
                <DialogFooter>
                    <Button
                        variant="outline"
                        @click="showCorrectEmailModal = false"
                        >Cancel</Button
                    >
                    <Button
                        type="submit"
                        form="correct-email-form"
                        :disabled="correctEmailForm.processing"
                    >
                        Save and resend
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Cancel invitation dialog -->
        <Dialog v-model:open="showCancelModal">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Cancel invitation?</DialogTitle>
                    <DialogDescription>
                        {{ agent.name }} won't be able to use the invite link to
                        set up their account.
                    </DialogDescription>
                </DialogHeader>
                <form
                    id="cancel-invitation-form"
                    class="space-y-4 py-2"
                    @submit.prevent="submitCancelInvitation"
                >
                    <div class="space-y-1.5">
                        <Label for="cancel-reason">Reason</Label>
                        <Input
                            id="cancel-reason"
                            v-model="cancelForm.reason"
                            required
                            placeholder="e.g. No longer joining"
                        />
                    </div>
                    <p
                        v-if="cancelForm.error"
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        {{ cancelForm.error }}
                    </p>
                </form>
                <DialogFooter>
                    <Button variant="outline" @click="showCancelModal = false"
                        >Keep invitation</Button
                    >
                    <Button
                        type="submit"
                        form="cancel-invitation-form"
                        variant="destructive"
                        :disabled="cancelForm.processing"
                    >
                        Cancel invitation
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
