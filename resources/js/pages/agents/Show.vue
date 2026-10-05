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
import { create as createRecovery } from '@/routes/admin/staff-recoveries';
import {
    cancel as cancelInvitation,
    correctEmail as correctEmailInvitation,
    resend as resendInvitation,
} from '@/routes/agents/invitations';
import { show as customersShow } from '@/routes/customers';
import {
    AlertCircle,
    ArrowLeft,
    CheckCircle2,
    Clock,
    Lock,
    Mail,
    Pencil,
    Phone,
    RefreshCw,
    Shield,
    ShieldCheck,
    StickyNote,
    User as UserIcon,
    Wallet,
    XCircle,
} from '@lucide/vue';
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
import DirectoryPanel from '@/components/directory/DirectoryPanel.vue';
import DirectoryRow from '@/components/directory/DirectoryRow.vue';
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
        correctEmailForm.error = 'Both email and reason are required.';
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
        cancelForm.error = 'A cancellation reason is required.';
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
    <Head :title="`${agent.name} - Agent Profile`" />

    <div class="space-y-6">
        <!-- Back Navigation & Header -->
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="flex items-center gap-4">
                <Link v-if="viewer_type === 'admin'" :href="agentsIndex().url">
                    <Button variant="outline" size="icon" class="h-9 w-9">
                        <ArrowLeft class="h-4 w-4" />
                    </Button>
                </Link>
                <div>
                    <div class="flex items-center gap-3">
                        <h1 class="text-[25px] font-medium tracking-tight">
                            {{ agent.name }}
                        </h1>
                        <span
                            class="bg-muted text-muted-foreground rounded-md px-2.5 py-0.5 font-mono text-sm"
                        >
                            {{ agent.id }}
                        </span>
                    </div>
                    <p class="text-muted-foreground mt-1.5 text-sm">
                        Registered on {{ agent.registered_at }} (Africa/Lagos)
                    </p>
                </div>
            </div>

            <!-- Status Badges -->
            <div class="flex items-center gap-2">
                <Link
                    v-if="agent.actions.can_edit"
                    :href="editAgent(agent.id).url"
                >
                    <Button variant="outline">Edit profile</Button>
                </Link>
                <Link
                    v-if="agent.actions.can_manage_lifecycle"
                    :href="editAgentStatus(agent.id).url"
                >
                    <Button variant="outline">Manage status</Button>
                </Link>
                <Link
                    v-if="
                        agent.actions.can_request_recovery &&
                        agent.actions.recovery_user_id
                    "
                    :href="createRecovery(agent.actions.recovery_user_id).url"
                >
                    <Button variant="outline">Request account recovery</Button>
                </Link>
                <Badge
                    :variant="
                        getOperationalBadgeVariant(agent.operational_status)
                    "
                    class="px-3 py-1 text-sm"
                >
                    {{ agent.operational_status_label }}
                </Badge>
                <Badge
                    :variant="getAccountBadgeVariant(agent.account_state)"
                    class="px-3 py-1 text-sm"
                >
                    Account: {{ agent.account_state_label }}
                </Badge>
            </div>
        </div>

        <!-- Identity Banner -->
        <Card>
            <CardContent class="p-6">
                <div class="flex flex-col items-center gap-6 sm:flex-row">
                    <Avatar class="border-border h-24 w-24 border-2 shadow-sm">
                        <AvatarImage
                            v-if="agent.photo_url"
                            :src="agent.photo_url"
                            :alt="agent.name"
                        />
                        <AvatarFallback class="text-xl font-bold">{{
                            getInitials(agent.name)
                        }}</AvatarFallback>
                    </Avatar>
                    <div class="space-y-1.5 text-center sm:text-left">
                        <div class="text-lg font-semibold">
                            {{ agent.name }}
                        </div>
                        <div
                            class="text-muted-foreground flex flex-wrap justify-center gap-4 text-sm sm:justify-start"
                        >
                            <span
                                >Phone:
                                <strong class="text-foreground">{{
                                    agent.phone
                                }}</strong></span
                            >
                            <span
                                >Email:
                                <strong class="text-foreground">{{
                                    agent.email
                                }}</strong></span
                            >
                            <span v-if="agent.employment_date">
                                Employment Date:
                                <strong class="text-foreground">{{
                                    agent.employment_date
                                }}</strong>
                            </span>
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>

        <!-- Readiness & Eligibility Evaluation Card -->
        <Card>
            <CardHeader class="pb-3">
                <CardTitle
                    class="flex items-center gap-2 text-base font-semibold"
                >
                    <ShieldCheck class="h-4 w-4" /> Operational Readiness &
                    Capabilities
                </CardTitle>
                <CardDescription>
                    Derived eligibility status across core operational
                    responsibilities.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <!-- Read Assigned -->
                    <div class="bg-muted/20 space-y-2 rounded-lg border p-4">
                        <div class="flex items-center justify-between">
                            <span class="text-foreground text-xs font-semibold"
                                >Read Assigned Customers</span
                            >
                            <Badge
                                :variant="
                                    agent.readiness.can_read_assigned.eligible
                                        ? 'default'
                                        : 'secondary'
                                "
                                class="text-[11px]"
                            >
                                {{
                                    agent.readiness.can_read_assigned.eligible
                                        ? 'Authorized'
                                        : 'Blocked'
                                }}
                            </Badge>
                        </div>
                        <p class="text-muted-foreground text-xs">
                            {{
                                agent.readiness.can_read_assigned.explanation ||
                                'Can view assigned customer accounts.'
                            }}
                        </p>
                    </div>

                    <!-- Perform Work -->
                    <div class="bg-muted/20 space-y-2 rounded-lg border p-4">
                        <div class="flex items-center justify-between">
                            <span class="text-foreground text-xs font-semibold"
                                >Perform Customer Work</span
                            >
                            <Badge
                                :variant="
                                    agent.readiness.can_perform_work.eligible
                                        ? 'default'
                                        : 'secondary'
                                "
                                class="text-[11px]"
                            >
                                {{
                                    agent.readiness.can_perform_work.eligible
                                        ? 'Active Work'
                                        : 'Blocked'
                                }}
                            </Badge>
                        </div>
                        <p class="text-muted-foreground text-xs">
                            {{
                                agent.readiness.can_perform_work.explanation ||
                                'Can record customer collections and transactions.'
                            }}
                        </p>
                    </div>

                    <!-- Receive Assignment -->
                    <div class="bg-muted/20 space-y-2 rounded-lg border p-4">
                        <div class="flex items-center justify-between">
                            <span class="text-foreground text-xs font-semibold"
                                >Receive New Assignments</span
                            >
                            <Badge
                                :variant="
                                    agent.readiness.can_receive_assignment
                                        .eligible
                                        ? 'default'
                                        : 'secondary'
                                "
                                class="text-[11px]"
                            >
                                {{
                                    agent.readiness.can_receive_assignment
                                        .eligible
                                        ? 'Eligible'
                                        : 'Ineligible'
                                }}
                            </Badge>
                        </div>
                        <p class="text-muted-foreground text-xs">
                            {{
                                agent.readiness.can_receive_assignment
                                    .explanation ||
                                'Meets all conditions to get new customer assignments.'
                            }}
                        </p>
                    </div>
                </div>
            </CardContent>
        </Card>

        <DirectoryPanel
            title="Assigned customers"
            :description="`${assigned_customers.total} current customer${assigned_customers.total === 1 ? '' : 's'} assigned to this agent.`"
            :search-value="assignmentFilterForm.search"
            search-placeholder="Search assigned customers"
            :filters-open="assignmentFiltersOpen"
            :active-filter-count="activeAssignmentFilterCount"
            @update:search-value="assignmentFilterForm.search = $event"
            @submit-search="applyAssignmentFilters"
            @toggle-filters="assignmentFiltersOpen = !assignmentFiltersOpen"
            @reset-filters="resetAssignmentFilters"
        >
            <template #filters>
                <div class="w-fit space-y-1.5">
                    <Label for="assignment-status" class="text-xs"
                        >Operational status</Label
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
                    {{ assigned_customers.total }} current customer{{
                        assigned_customers.total === 1 ? '' : 's'
                    }}
                    match the current filters.
                </p></template
            >

            <div class="mb-5 flex flex-wrap gap-2 text-xs">
                <Badge variant="default"
                    >{{ agent.assignments_summary.active_count }} Active</Badge
                ><Badge variant="secondary"
                    >{{
                        agent.assignments_summary.inactive_count
                    }}
                    Inactive</Badge
                ><Badge variant="secondary"
                    >{{
                        agent.assignments_summary.restricted_count
                    }}
                    Restricted</Badge
                ><Badge variant="destructive"
                    >{{
                        agent.assignments_summary.archived_count
                    }}
                    Archived</Badge
                >
            </div>
            <div
                v-if="assigned_customers.data.length === 0"
                class="text-muted-foreground py-10 text-center text-sm"
            >
                No customers currently assigned to this agent match this view.
            </div>
            <div v-else class="space-y-3">
                <DirectoryRow
                    v-for="customer in assigned_customers.data"
                    :key="customer.id"
                    ><div
                        class="hidden items-center gap-5 md:grid md:grid-cols-[minmax(14rem,1.5fr)_repeat(3,minmax(0,1fr))_auto]"
                    >
                        <div>
                            <p class="text-sm font-semibold">
                                {{ customer.name }}
                            </p>
                            <p class="text-muted-foreground text-xs">
                                {{ customer.id }}
                            </p>
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
                                        customer.operational_status,
                                    )
                                "
                                class="mt-1"
                                >{{ customer.operational_status_label }}</Badge
                            >
                        </div>
                        <div>
                            <p
                                class="text-muted-foreground text-[11px] font-medium uppercase"
                            >
                                Account state
                            </p>
                            <p class="mt-1 text-sm capitalize">
                                {{
                                    customer.account_state?.replace('_', ' ') ||
                                    'Unknown'
                                }}
                            </p>
                        </div>
                        <div>
                            <p
                                class="text-muted-foreground text-[11px] font-medium uppercase"
                            >
                                Assigned since
                            </p>
                            <p class="mt-1 text-sm">
                                {{ customer.assigned_since || '—' }}
                            </p>
                        </div>
                        <Link :href="customersShow(customer.id).url"
                            ><Button variant="outline" size="sm"
                                >View</Button
                            ></Link
                        >
                    </div>
                    <div class="md:hidden">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold">
                                    {{ customer.name }}
                                </p>
                                <p class="text-muted-foreground text-xs">
                                    {{ customer.id }}
                                </p>
                            </div>
                            <Link :href="customersShow(customer.id).url"
                                ><Button variant="outline" size="sm"
                                    >View</Button
                                ></Link
                            >
                        </div>
                        <div class="mt-4 grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <p
                                    class="text-muted-foreground text-[10px] font-medium uppercase"
                                >
                                    Status
                                </p>
                                <Badge
                                    :variant="
                                        getOperationalBadgeVariant(
                                            customer.operational_status,
                                        )
                                    "
                                    class="mt-1"
                                    >{{
                                        customer.operational_status_label
                                    }}</Badge
                                >
                            </div>
                            <div>
                                <p
                                    class="text-muted-foreground text-[10px] font-medium uppercase"
                                >
                                    Account
                                </p>
                                <p class="mt-1 capitalize">
                                    {{
                                        customer.account_state?.replace(
                                            '_',
                                            ' ',
                                        ) || 'Unknown'
                                    }}
                                </p>
                            </div>
                            <div class="col-span-2">
                                <p
                                    class="text-muted-foreground text-[10px] font-medium uppercase"
                                >
                                    Assigned since
                                </p>
                                <p class="mt-1">
                                    {{ customer.assigned_since || '—' }}
                                </p>
                            </div>
                        </div>
                    </div></DirectoryRow
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
                            v-model="assignmentFilterForm.per_page"
                            @update:model-value="applyAssignmentFilters"
                            ><SelectTrigger class="h-9 w-20"
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
                            <Link
                                v-if="assigned_customers.prev_page_url"
                                :href="assigned_customers.prev_page_url"
                                preserve-state
                                preserve-scroll
                                ><Button variant="outline" size="sm"
                                    >Previous</Button
                                ></Link
                            ><Button v-else variant="outline" size="sm" disabled
                                >Previous</Button
                            ><Link
                                v-if="assigned_customers.next_page_url"
                                :href="assigned_customers.next_page_url"
                                preserve-state
                                preserve-scroll
                                ><Button size="sm">Next</Button></Link
                            ><Button v-else size="sm" disabled>Next</Button>
                        </div>
                    </div>
                </div></template
            >
        </DirectoryPanel>

        <!-- Personal & Engagement Details + Internal Notes Grid -->
        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
            <!-- Personal Details -->
            <Card>
                <CardHeader class="pb-3">
                    <CardTitle
                        class="flex items-center gap-2 text-base font-semibold"
                    >
                        <UserIcon class="h-4 w-4" /> Personal & Engagement
                        Details
                    </CardTitle>
                </CardHeader>
                <CardContent class="space-y-3 text-sm">
                    <div class="flex justify-between border-b py-1.5">
                        <span class="text-muted-foreground">Full Name</span>
                        <span class="font-medium">{{ agent.name }}</span>
                    </div>
                    <div class="flex justify-between border-b py-1.5">
                        <span class="text-muted-foreground">Phone Number</span>
                        <span class="font-medium">{{ agent.phone }}</span>
                    </div>
                    <div class="flex justify-between border-b py-1.5">
                        <span class="text-muted-foreground">Email Address</span>
                        <span class="font-medium">{{ agent.email }}</span>
                    </div>
                    <div class="flex justify-between border-b py-1.5">
                        <span class="text-muted-foreground"
                            >Residential Address</span
                        >
                        <span class="max-w-xs text-right font-medium">{{
                            agent.address || 'Not provided'
                        }}</span>
                    </div>
                    <div class="flex justify-between py-1.5">
                        <span class="text-muted-foreground"
                            >Employment Date</span
                        >
                        <span class="font-medium">{{
                            agent.employment_date || 'Not recorded'
                        }}</span>
                    </div>
                </CardContent>
            </Card>

            <!-- Invitation & Access Details -->
            <Card>
                <CardHeader class="pb-3">
                    <CardTitle
                        class="flex items-center gap-2 text-base font-semibold"
                    >
                        <Lock class="h-4 w-4" /> Account & Security Status
                    </CardTitle>
                </CardHeader>
                <CardContent class="space-y-3 text-sm">
                    <div class="flex justify-between border-b py-1.5">
                        <span class="text-muted-foreground"
                            >Authentication State</span
                        >
                        <Badge
                            :variant="
                                getAccountBadgeVariant(agent.account_state)
                            "
                            class="text-xs"
                        >
                            {{ agent.account_state_label }}
                        </Badge>
                    </div>
                    <div class="flex justify-between border-b py-1.5">
                        <span class="text-muted-foreground"
                            >Two-Factor Authentication</span
                        >
                        <span
                            v-if="agent.invitation_and_access.mfa_confirmed"
                            class="flex items-center gap-1 text-xs font-medium text-emerald-600 dark:text-emerald-400"
                        >
                            <CheckCircle2 class="h-3.5 w-3.5" /> Enrolled &
                            Confirmed
                        </span>
                        <span
                            v-else
                            class="flex items-center gap-1 text-xs font-medium text-amber-600 dark:text-amber-400"
                        >
                            <Clock class="h-3.5 w-3.5" /> Pending Setup
                        </span>
                    </div>
                    <div class="flex justify-between py-1.5">
                        <span class="text-muted-foreground">Agent ID</span>
                        <span class="font-mono text-xs font-semibold">{{
                            agent.id
                        }}</span>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Invitation Lifecycle & Delivery Card (Admin Only) -->
        <Card v-if="agent.invitation">
            <CardHeader class="pb-3">
                <div class="flex items-center justify-between">
                    <div>
                        <CardTitle
                            class="flex items-center gap-2 text-base font-semibold"
                        >
                            <Mail class="h-4 w-4" /> Invitation & Delivery
                            Lifecycle
                        </CardTitle>
                        <CardDescription>
                            Track the activation invitation and its delivery
                            attempts. Resend the invitation or correct the
                            address.
                        </CardDescription>
                    </div>
                    <Badge
                        :variant="
                            getInvitationBadgeVariant(agent.invitation.status)
                        "
                    >
                        {{ agent.invitation.status_label }}
                    </Badge>
                </div>
            </CardHeader>
            <CardContent class="space-y-4">
                <div class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                    <div>
                        <p class="text-muted-foreground text-xs font-medium">
                            Delivery Status
                        </p>
                        <Badge variant="outline" class="mt-1">{{
                            agent.invitation.delivery_status_label
                        }}</Badge>
                    </div>
                    <div>
                        <p class="text-muted-foreground text-xs font-medium">
                            Generation
                        </p>
                        <p class="mt-1 font-mono text-sm font-semibold">
                            #{{ agent.invitation.generation }}
                        </p>
                    </div>
                    <div>
                        <p class="text-muted-foreground text-xs font-medium">
                            Sent At
                        </p>
                        <p class="mt-1 text-sm">
                            {{ agent.invitation.sent_at || '—' }}
                        </p>
                    </div>
                    <div>
                        <p class="text-muted-foreground text-xs font-medium">
                            Expires At
                        </p>
                        <p class="mt-1 text-sm">
                            {{ agent.invitation.expires_at || '—' }}
                        </p>
                    </div>
                </div>

                <div
                    v-if="agent.invitation.delivery_error"
                    class="bg-destructive/10 text-destructive rounded-lg p-3 text-xs"
                >
                    <p class="font-medium">Delivery Issue</p>
                    <p class="mt-0.5">{{ agent.invitation.delivery_error }}</p>
                </div>

                <!-- Invitation Management Actions -->
                <div
                    v-if="agent.actions.can_manage_invitation"
                    class="flex flex-wrap items-center gap-3 border-t pt-2"
                >
                    <Button
                        v-if="agent.invitation.can_resend"
                        variant="outline"
                        size="sm"
                        :disabled="isResending"
                        @click="handleResendInvitation"
                    >
                        <RefreshCw
                            class="mr-1.5 h-3.5 w-3.5"
                            :class="{ 'animate-spin': isResending }"
                        />
                        Resend Invitation
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        @click="openCorrectEmailModal"
                    >
                        <Pencil class="mr-1.5 h-3.5 w-3.5" />
                        Correct Email
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        class="text-destructive hover:bg-destructive/10"
                        @click="openCancelModal"
                    >
                        <XCircle class="mr-1.5 h-3.5 w-3.5" />
                        Cancel Invitation
                    </Button>
                </div>
            </CardContent>
        </Card>

        <!-- Internal Notes Card (Admin Only, strictly omitted from Agent self-service) -->
        <Card v-if="agent.notes !== undefined">
            <CardHeader class="pb-3">
                <CardTitle
                    class="flex items-center gap-2 text-base font-semibold"
                >
                    <StickyNote class="h-4 w-4" /> Internal Administrative Notes
                </CardTitle>
                <CardDescription>
                    Only administrators can see these notes. Agents cannot see
                    them.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div
                    v-if="agent.notes"
                    class="bg-muted/50 rounded-lg p-4 font-sans text-sm whitespace-pre-wrap"
                >
                    {{ agent.notes }}
                </div>
                <p v-else class="text-muted-foreground text-sm italic">
                    No internal administrative notes recorded.
                </p>
            </CardContent>
        </Card>

        <!-- Explicit Unavailable Collections & Reconciliation Section -->
        <Card class="bg-muted/20 border-dashed">
            <CardHeader class="pb-2">
                <CardTitle
                    class="text-muted-foreground flex items-center gap-2 text-sm font-medium"
                >
                    <Wallet class="h-4 w-4" /> Collections and Cash
                    Reconciliation
                </CardTitle>
            </CardHeader>
            <CardContent class="text-xs">
                <div class="bg-muted/60 text-muted-foreground rounded-md p-3">
                    <div class="text-foreground font-medium">
                        Reconciliation summary unavailable
                    </div>
                    <p class="mt-0.5 text-[11px]">
                        {{ agent.collections_and_reconciliation.message }}
                    </p>
                </div>
            </CardContent>
        </Card>

        <!-- Blocked Actions Notice -->
        <Card class="bg-muted/30">
            <CardContent
                class="text-muted-foreground flex items-center justify-between p-4 text-xs"
            >
                <div class="flex items-center gap-2">
                    <Lock class="text-muted-foreground h-4 w-4" />
                    <span
                        >Agent management actions (registration, lifecycle
                        transitions, reassignment) are read-only until owning
                        tasks are completed.</span
                    >
                </div>
            </CardContent>
        </Card>

        <!-- Correct Email Dialog -->
        <Dialog v-model:open="showCorrectEmailModal">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Correct Agent Email</DialogTitle>
                    <DialogDescription>
                        Update the email address for {{ agent.name }}. Open
                        invitations stop working. A new invitation is sent.
                    </DialogDescription>
                </DialogHeader>
                <div class="space-y-4 py-2">
                    <div class="space-y-1.5">
                        <Label for="correct-email"
                            >New email address
                            <span class="text-destructive">*</span></Label
                        >
                        <Input
                            id="correct-email"
                            v-model="correctEmailForm.email"
                            type="email"
                            required
                            placeholder="agent@example.ng"
                        />
                    </div>
                    <div class="space-y-1.5">
                        <Label for="correct-reason"
                            >Correction reason
                            <span class="text-destructive">*</span></Label
                        >
                        <Input
                            id="correct-reason"
                            v-model="correctEmailForm.reason"
                            required
                            placeholder="e.g. Typo in original email address"
                        />
                    </div>
                    <p
                        v-if="correctEmailForm.error"
                        class="text-destructive text-xs"
                    >
                        {{ correctEmailForm.error }}
                    </p>
                </div>
                <DialogFooter>
                    <Button
                        variant="outline"
                        @click="showCorrectEmailModal = false"
                        >Cancel</Button
                    >
                    <Button
                        :disabled="correctEmailForm.processing"
                        @click="submitCorrectEmail"
                    >
                        Update Email & Resend
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Cancel Invitation Dialog -->
        <Dialog v-model:open="showCancelModal">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Cancel Agent Invitation</DialogTitle>
                    <DialogDescription>
                        Cancel the open invitation and activation links for
                        {{ agent.name }}. The account cannot activate with
                        cancelled links.
                    </DialogDescription>
                </DialogHeader>
                <div class="space-y-4 py-2">
                    <div class="space-y-1.5">
                        <Label for="cancel-reason"
                            >Cancellation reason
                            <span class="text-destructive">*</span></Label
                        >
                        <Input
                            id="cancel-reason"
                            v-model="cancelForm.reason"
                            required
                            placeholder="e.g. Onboarding cancelled or identity error"
                        />
                    </div>
                    <p v-if="cancelForm.error" class="text-destructive text-xs">
                        {{ cancelForm.error }}
                    </p>
                </div>
                <DialogFooter>
                    <Button variant="outline" @click="showCancelModal = false"
                        >Keep Invitation</Button
                    >
                    <Button
                        variant="destructive"
                        :disabled="cancelForm.processing"
                        @click="submitCancelInvitation"
                    >
                        Confirm Cancellation
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
        <ManagementDeliveryPanel subject="agent" :reference="agent.id" />
    </div>
</template>
