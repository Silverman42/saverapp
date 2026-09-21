<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { dashboard } from '@/routes';
import {
    AlertCircle,
    ArrowLeft,
    CreditCard,
    FileText,
    History,
    Lock,
    Phone,
    Receipt,
    Shield,
    StickyNote,
    User as UserIcon,
    Users,
    Wallet,
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

export type NextOfKin = {
    full_name: string | null;
    relationship: string | null;
    phone: string | null;
    address: string | null;
};

export type AssignedAgent = {
    id?: string;
    name: string;
    phone?: string;
    email?: string | null;
    operational_status?: string;
    is_eligible?: boolean;
};

export type CustomerDetail = {
    id: string;
    name: string;
    first_name: string;
    last_name: string;
    email: string | null;
    phone: string;
    address: string | null;
    gender: string | null;
    occupation: string | null;
    internal_reference: string | null;
    next_of_kin: NextOfKin | null;
    photo_url: string | null;
    operational_status: string;
    operational_status_label: string;
    account_state: string;
    account_state_label: string;
    registered_at: string;
    registered_at_iso: string;
    assigned_agent: AssignedAgent | null;
    notes?: string | null;
    relationship_history: {
        registered_at: string;
        assignment_started_at: string | null;
    };
    financial_summary: {
        status: string;
        message: string;
    };
    plans: {
        status: string;
        message: string;
    };
    transactions: {
        status: string;
        message: string;
    };
    statements: {
        status: string;
        message: string;
    };
    actions: {
        can_edit: boolean;
        edit_message: string;
        can_reassign: boolean;
        reassign_message: string;
        can_archive: boolean;
        archive_message: string;
    };
};

const props = defineProps<{
    customer: CustomerDetail;
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
    <Head :title="`${customer.name} - Profile`" />

    <div class="space-y-6">
        <!-- Back Navigation & Header -->
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="flex items-center gap-4">
                <Link v-if="viewer_type !== 'customer'" href="/customers">
                    <Button variant="outline" size="icon" class="h-9 w-9">
                        <ArrowLeft class="h-4 w-4" />
                    </Button>
                </Link>
                <div>
                    <div class="flex items-center gap-3">
                        <h1 class="text-[25px] font-medium tracking-tight">
                            {{ customer.name }}
                        </h1>
                        <span
                            class="bg-muted text-muted-foreground rounded-md px-2.5 py-0.5 font-mono text-sm"
                        >
                            {{ customer.id }}
                        </span>
                    </div>
                    <p class="text-muted-foreground mt-1.5 text-sm">
                        Registered on
                        {{ customer.registered_at }} (Africa/Lagos)
                    </p>
                </div>
            </div>

            <!-- Status Badges -->
            <div class="flex items-center gap-2">
                <Badge
                    :variant="
                        getOperationalBadgeVariant(customer.operational_status)
                    "
                    class="px-3 py-1 text-sm"
                >
                    {{ customer.operational_status_label }}
                </Badge>
                <Badge
                    :variant="getAccountBadgeVariant(customer.account_state)"
                    class="px-3 py-1 text-sm"
                >
                    Account: {{ customer.account_state_label }}
                </Badge>
            </div>
        </div>

        <!-- Profile Photo & Primary Identity Banner -->
        <Card>
            <CardContent class="p-6">
                <div class="flex flex-col items-center gap-6 sm:flex-row">
                    <Avatar class="border-border h-24 w-24 border-2 shadow-sm">
                        <AvatarImage
                            v-if="customer.photo_url"
                            :src="customer.photo_url"
                            :alt="customer.name"
                        />
                        <AvatarFallback class="text-xl font-bold">{{
                            getInitials(customer.name)
                        }}</AvatarFallback>
                    </Avatar>
                    <div class="space-y-1.5 text-center sm:text-left">
                        <div class="text-lg font-semibold">
                            {{ customer.name }}
                        </div>
                        <div
                            class="text-muted-foreground flex flex-wrap justify-center gap-4 text-sm sm:justify-start"
                        >
                            <span
                                >Phone:
                                <strong class="text-foreground">{{
                                    customer.phone
                                }}</strong></span
                            >
                            <span v-if="customer.email"
                                >Email:
                                <strong class="text-foreground">{{
                                    customer.email
                                }}</strong></span
                            >
                            <span v-if="customer.gender"
                                >Gender:
                                <strong class="text-foreground capitalize">{{
                                    customer.gender
                                }}</strong></span
                            >
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>

        <!-- Main Grid -->
        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
            <!-- Personal Details -->
            <Card>
                <CardHeader class="pb-3">
                    <CardTitle
                        class="flex items-center gap-2 text-base font-semibold"
                    >
                        <UserIcon class="h-4 w-4" /> Personal Details
                    </CardTitle>
                </CardHeader>
                <CardContent class="space-y-3 text-sm">
                    <div class="flex justify-between border-b py-1.5">
                        <span class="text-muted-foreground">Full Name</span>
                        <span class="font-medium">{{ customer.name }}</span>
                    </div>
                    <div class="flex justify-between border-b py-1.5">
                        <span class="text-muted-foreground">Phone Number</span>
                        <span class="font-medium">{{ customer.phone }}</span>
                    </div>
                    <div class="flex justify-between border-b py-1.5">
                        <span class="text-muted-foreground">Email Address</span>
                        <span class="font-medium">{{
                            customer.email || 'Not provided'
                        }}</span>
                    </div>
                    <div class="flex justify-between border-b py-1.5">
                        <span class="text-muted-foreground"
                            >Residential Address</span
                        >
                        <span class="max-w-xs text-right font-medium">{{
                            customer.address || 'Not provided'
                        }}</span>
                    </div>
                    <div class="flex justify-between border-b py-1.5">
                        <span class="text-muted-foreground">Occupation</span>
                        <span class="font-medium">{{
                            customer.occupation || 'Not provided'
                        }}</span>
                    </div>
                    <div class="flex justify-between py-1.5">
                        <span class="text-muted-foreground"
                            >Internal Reference</span
                        >
                        <span class="font-mono text-xs">{{
                            customer.internal_reference || 'None'
                        }}</span>
                    </div>
                </CardContent>
            </Card>

            <!-- Assigned Agent & Next of Kin -->
            <div class="space-y-6">
                <!-- Assigned Agent -->
                <Card>
                    <CardHeader class="pb-3">
                        <CardTitle
                            class="flex items-center gap-2 text-base font-semibold"
                        >
                            <Users class="h-4 w-4" /> Assigned Agent
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="text-sm">
                        <div v-if="customer.assigned_agent" class="space-y-3">
                            <div class="flex justify-between border-b py-1.5">
                                <span class="text-muted-foreground"
                                    >Agent Name</span
                                >
                                <span class="font-medium">{{
                                    customer.assigned_agent.name
                                }}</span>
                            </div>
                            <div
                                v-if="customer.assigned_agent.id"
                                class="flex justify-between border-b py-1.5"
                            >
                                <span class="text-muted-foreground"
                                    >Agent ID</span
                                >
                                <span class="font-mono text-xs">{{
                                    customer.assigned_agent.id
                                }}</span>
                            </div>
                            <div
                                v-if="customer.assigned_agent.phone"
                                class="flex justify-between border-b py-1.5"
                            >
                                <span class="text-muted-foreground"
                                    >Contact Phone</span
                                >
                                <span>{{ customer.assigned_agent.phone }}</span>
                            </div>
                            <div
                                v-if="customer.assigned_agent.email"
                                class="flex justify-between border-b py-1.5"
                            >
                                <span class="text-muted-foreground"
                                    >Contact Email</span
                                >
                                <span>{{ customer.assigned_agent.email }}</span>
                            </div>
                            <div
                                v-if="
                                    customer.relationship_history
                                        .assignment_started_at
                                "
                                class="flex justify-between py-1.5"
                            >
                                <span class="text-muted-foreground"
                                    >Assigned Since</span
                                >
                                <span>{{
                                    customer.relationship_history
                                        .assignment_started_at
                                }}</span>
                            </div>
                        </div>
                        <div v-else class="text-muted-foreground py-2 italic">
                            No agent currently assigned.
                        </div>
                    </CardContent>
                </Card>

                <!-- Next of Kin -->
                <Card>
                    <CardHeader class="pb-3">
                        <CardTitle class="text-base font-semibold"
                            >Next of Kin</CardTitle
                        >
                    </CardHeader>
                    <CardContent class="text-sm">
                        <div v-if="customer.next_of_kin" class="space-y-2">
                            <div class="flex justify-between border-b py-1.5">
                                <span class="text-muted-foreground">Name</span>
                                <span class="font-medium">{{
                                    customer.next_of_kin.full_name
                                }}</span>
                            </div>
                            <div class="flex justify-between border-b py-1.5">
                                <span class="text-muted-foreground"
                                    >Relationship</span
                                >
                                <span>{{
                                    customer.next_of_kin.relationship
                                }}</span>
                            </div>
                            <div class="flex justify-between border-b py-1.5">
                                <span class="text-muted-foreground">Phone</span>
                                <span>{{ customer.next_of_kin.phone }}</span>
                            </div>
                            <div
                                v-if="customer.next_of_kin.address"
                                class="flex justify-between py-1.5"
                            >
                                <span class="text-muted-foreground"
                                    >Address</span
                                >
                                <span class="max-w-xs text-right">{{
                                    customer.next_of_kin.address
                                }}</span>
                            </div>
                        </div>
                        <div v-else class="text-muted-foreground py-2 italic">
                            No next of kin information provided.
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>

        <!-- Internal Notes Card (Admin & Current Agent Only, omitted from Customer) -->
        <Card v-if="customer.notes !== undefined">
            <CardHeader class="pb-3">
                <CardTitle
                    class="flex items-center gap-2 text-base font-semibold"
                >
                    <StickyNote class="h-4 w-4" /> Internal Operational Notes
                </CardTitle>
                <CardDescription>
                    Visible only to authorized staff and assigned agents. Never
                    disclosed to customer.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div
                    v-if="customer.notes"
                    class="bg-muted/50 rounded-lg p-4 font-sans text-sm whitespace-pre-wrap"
                >
                    {{ customer.notes }}
                </div>
                <p v-else class="text-muted-foreground text-sm italic">
                    No internal operational notes recorded.
                </p>
            </CardContent>
        </Card>

        <!-- Explicit Unavailable Dependency Sections -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <!-- Financial Summary -->
            <Card class="bg-muted/20 border-dashed">
                <CardHeader class="pb-2">
                    <CardTitle
                        class="text-muted-foreground flex items-center gap-2 text-sm font-medium"
                    >
                        <Wallet class="h-4 w-4" /> Financial Summary
                    </CardTitle>
                </CardHeader>
                <CardContent class="space-y-2 text-xs">
                    <div
                        class="flex items-start gap-2 rounded-md bg-amber-500/10 p-2.5 text-amber-600 dark:text-amber-400"
                    >
                        <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" />
                        <div>
                            <div class="font-medium">Balance unavailable</div>
                            <p class="mt-0.5 text-[11px]">
                                {{ customer.financial_summary.message }}
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Plans and Thrift Cards -->
            <Card class="bg-muted/20 border-dashed">
                <CardHeader class="pb-2">
                    <CardTitle
                        class="text-muted-foreground flex items-center gap-2 text-sm font-medium"
                    >
                        <CreditCard class="h-4 w-4" /> Thrift Plans
                    </CardTitle>
                </CardHeader>
                <CardContent class="space-y-2 text-xs">
                    <div
                        class="bg-muted text-muted-foreground rounded-md p-2.5"
                    >
                        <div class="text-foreground font-medium">
                            Plans unavailable
                        </div>
                        <p class="mt-0.5 text-[11px]">
                            {{ customer.plans.message }}
                        </p>
                    </div>
                </CardContent>
            </Card>

            <!-- Recent Transactions -->
            <Card class="bg-muted/20 border-dashed">
                <CardHeader class="pb-2">
                    <CardTitle
                        class="text-muted-foreground flex items-center gap-2 text-sm font-medium"
                    >
                        <Receipt class="h-4 w-4" /> Transactions
                    </CardTitle>
                </CardHeader>
                <CardContent class="space-y-2 text-xs">
                    <div
                        class="bg-muted text-muted-foreground rounded-md p-2.5"
                    >
                        <div class="text-foreground font-medium">
                            Ledger unavailable
                        </div>
                        <p class="mt-0.5 text-[11px]">
                            {{ customer.transactions.message }}
                        </p>
                    </div>
                </CardContent>
            </Card>

            <!-- Requests and Statements -->
            <Card class="bg-muted/20 border-dashed">
                <CardHeader class="pb-2">
                    <CardTitle
                        class="text-muted-foreground flex items-center gap-2 text-sm font-medium"
                    >
                        <FileText class="h-4 w-4" /> Statements
                    </CardTitle>
                </CardHeader>
                <CardContent class="space-y-2 text-xs">
                    <div
                        class="bg-muted text-muted-foreground rounded-md p-2.5"
                    >
                        <div class="text-foreground font-medium">
                            Statements unavailable
                        </div>
                        <p class="mt-0.5 text-[11px]">
                            {{ customer.statements.message }}
                        </p>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Blocked Actions Notice -->
        <Card class="bg-muted/30">
            <CardContent
                class="text-muted-foreground flex items-center justify-between p-4 text-xs"
            >
                <div class="flex items-center gap-2">
                    <Lock class="text-muted-foreground h-4 w-4" />
                    <span
                        >Profile mutations (editing, reassignment, archival) are
                        read-only until owning tasks are completed.</span
                    >
                </div>
            </CardContent>
        </Card>
    </div>
</template>
