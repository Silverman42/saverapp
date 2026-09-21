<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { dashboard } from '@/routes';
import {
    AlertCircle,
    ArrowLeft,
    Briefcase,
    Calendar,
    CheckCircle2,
    Clock,
    Lock,
    Phone,
    Shield,
    ShieldCheck,
    StickyNote,
    User as UserIcon,
    Users,
    Wallet,
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
    account_state: string;
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
        customers: AssignedCustomer[];
    };
    invitation_and_access: {
        account_state: string;
        mfa_confirmed: boolean;
    };
    collections_and_reconciliation: {
        status: string;
        message: string;
    };
    notes?: string | null;
    lifecycle?: {
        operational_status: string;
        created_at: string;
        updated_at: string;
    };
    actions: {
        can_reassign_customers: boolean;
        reassign_message: string;
        can_manage_lifecycle: boolean;
        lifecycle_message: string;
    };
};

const props = defineProps<{
    agent: AgentDetail;
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
    <Head :title="`${agent.name} - Agent Profile`" />

    <div class="space-y-6">
        <!-- Back Navigation & Header -->
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="flex items-center gap-4">
                <Link v-if="viewer_type === 'admin'" href="/agents">
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
                                'Permitted to view assigned customer accounts.'
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
                                'Permitted to record customer collections and transactions.'
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
                                'Satisfies all criteria to take on new customer assignments.'
                            }}
                        </p>
                    </div>
                </div>
            </CardContent>
        </Card>

        <!-- Current Assignments & Workload Breakdown -->
        <Card>
            <CardHeader class="pb-3">
                <div
                    class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div>
                        <CardTitle
                            class="flex items-center gap-2 text-base font-semibold"
                        >
                            <Users class="h-4 w-4" /> Assigned Customer Workload
                        </CardTitle>
                        <CardDescription>
                            Summary of customers currently assigned to this
                            agent.
                        </CardDescription>
                    </div>
                    <!-- Workload Metrics -->
                    <div class="flex flex-wrap gap-2 text-xs">
                        <span
                            class="bg-primary/10 text-primary rounded px-2.5 py-1 font-medium"
                        >
                            {{ agent.assignments_summary.active_count }} Active
                        </span>
                        <span
                            class="bg-muted text-muted-foreground rounded px-2.5 py-1"
                        >
                            {{ agent.assignments_summary.inactive_count }}
                            Inactive
                        </span>
                        <span
                            class="rounded bg-amber-500/10 px-2.5 py-1 font-medium text-amber-600"
                        >
                            {{ agent.assignments_summary.restricted_count }}
                            Restricted
                        </span>
                        <span
                            class="bg-destructive/10 text-destructive rounded px-2.5 py-1 font-medium"
                        >
                            {{ agent.assignments_summary.archived_count }}
                            Archived (Separated)
                        </span>
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <div
                    v-if="agent.assignments_summary.customers.length === 0"
                    class="text-muted-foreground py-6 text-center text-sm italic"
                >
                    No customers currently assigned to this agent.
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead
                            class="bg-muted/40 text-muted-foreground border-b text-xs uppercase"
                        >
                            <tr>
                                <th class="px-4 py-2.5">Customer</th>
                                <th class="px-4 py-2.5">Operational Status</th>
                                <th class="px-4 py-2.5">Account State</th>
                                <th class="px-4 py-2.5">Assigned Since</th>
                                <th class="px-4 py-2.5 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y text-xs">
                            <tr
                                v-for="cust in agent.assignments_summary
                                    .customers"
                                :key="cust.id"
                                class="hover:bg-muted/50 transition-colors"
                            >
                                <td class="px-4 py-2.5">
                                    <div class="text-foreground font-medium">
                                        {{ cust.name }}
                                    </div>
                                    <div
                                        class="text-muted-foreground font-mono text-[11px]"
                                    >
                                        {{ cust.id }}
                                    </div>
                                </td>
                                <td class="px-4 py-2.5">
                                    <Badge
                                        :variant="
                                            getOperationalBadgeVariant(
                                                cust.operational_status,
                                            )
                                        "
                                        class="text-[11px]"
                                    >
                                        {{ cust.operational_status_label }}
                                    </Badge>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span
                                        class="text-muted-foreground capitalize"
                                        >{{
                                            cust.account_state
                                                ? cust.account_state.replace(
                                                      '_',
                                                      ' ',
                                                  )
                                                : 'Unknown'
                                        }}</span
                                    >
                                </td>
                                <td class="text-muted-foreground px-4 py-2.5">
                                    {{ cust.assigned_since || 'N/A' }}
                                </td>
                                <td class="px-4 py-2.5 text-right">
                                    <Link :href="`/customers/${cust.id}`">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            class="h-7 text-xs"
                                        >
                                            <span>View</span>
                                        </Button>
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>

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

        <!-- Internal Notes Card (Admin Only, strictly omitted from Agent self-service) -->
        <Card v-if="agent.notes !== undefined">
            <CardHeader class="pb-3">
                <CardTitle
                    class="flex items-center gap-2 text-base font-semibold"
                >
                    <StickyNote class="h-4 w-4" /> Internal Administrative Notes
                </CardTitle>
                <CardDescription>
                    Visible only to administrators. Strictly omitted from agent
                    self-service views.
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
    </div>
</template>
