<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { dashboard } from '@/routes';
import {
    edit as editCustomer,
    index as customersIndex,
} from '@/routes/customers';
import { edit as manageCustomerStatus } from '@/routes/customers/status';
import {
    show as showNameCorrection,
    cancel as cancelNameCorrection,
} from '@/routes/customers/name-corrections';
import {
    cancel as cancelInvitation,
    correctEmail as correctEmailInvitation,
    resend as resendInvitation,
} from '@/routes/customers/invitations';
import {
    AlertCircle,
    ArrowLeft,
    CheckCircle2,
    Coins,
    CreditCard,
    FileText,
    History,
    Loader2,
    Lock,
    Mail,
    Phone,
    Receipt,
    RotateCw,
    Shield,
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

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

export type FeeSnapshot = {
    name: string;
    model: string;
    amount_kobo: number;
    formatted_amount: string;
    currency: string;
    customer_description: string;
    is_zero: boolean;
    acknowledged_at: string | null;
};

export type CustomerInvitation = {
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
    status_explanation: {
        status: string;
        explanation: string;
        effective_at: string | null;
    } | null;
    assigned_agent: AssignedAgent | null;
    fee_snapshot?: FeeSnapshot | null;
    invitation?: CustomerInvitation | null;
    pending_name_correction?: {
        id: number;
        proposed_name: string | null;
        expires_at: string;
        can_review: boolean;
        can_cancel: boolean;
    };
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
        can_manage_invitation?: boolean;
        can_manage_status?: boolean;
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
                href: customersIndex(),
            },
        ],
    },
});

const showCorrectEmailModal = ref(false);
const showCancelModal = ref(false);

const emailForm = useForm({
    email: '',
    reason: '',
});

const cancelForm = useForm({
    reason: '',
});

const handleResendInvitation = (): void => {
    if (!props.customer.invitation?.can_resend) return;

    router.post(
        resendInvitation(props.customer.id).url,
        {},
        {
            preserveScroll: true,
        },
    );
};

const openCorrectEmailModal = (): void => {
    emailForm.reset();
    emailForm.clearErrors();
    emailForm.email = props.customer.email || '';
    showCorrectEmailModal.value = true;
};

const submitCorrectEmail = (): void => {
    emailForm.post(correctEmailInvitation(props.customer.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            showCorrectEmailModal.value = false;
            emailForm.reset();
        },
    });
};

const openCancelModal = (): void => {
    cancelForm.reset();
    cancelForm.clearErrors();
    showCancelModal.value = true;
};

const submitCancelInvitation = (): void => {
    cancelForm.post(cancelInvitation(props.customer.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            showCancelModal.value = false;
            cancelForm.reset();
        },
    });
};

const cancelNameProposal = (): void => {
    const proposal = props.customer.pending_name_correction;
    if (!proposal?.can_cancel) return;
    router.post(
        cancelNameCorrection({
            customer: props.customer.id,
            correction: proposal.id,
        }).url,
        {},
        { preserveScroll: true },
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

const getInvitationBadgeVariant = (
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' => {
    switch (status) {
        case 'activated':
            return 'default';
        case 'opened':
        case 'sent':
            return 'secondary';
        case 'expired':
        case 'cancelled':
            return 'destructive';
        default:
            return 'outline';
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
                <Link
                    v-if="viewer_type !== 'customer'"
                    :href="customersIndex().url"
                >
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
                <Link
                    v-if="customer.actions.can_edit"
                    :href="editCustomer(customer.id).url"
                >
                    <Button variant="outline">Edit profile</Button>
                </Link>
                <Link
                    v-if="customer.actions.can_manage_status"
                    :href="manageCustomerStatus(customer.id).url"
                >
                    <Button variant="outline">Manage status</Button>
                </Link>
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

        <Card v-if="customer.status_explanation">
            <CardContent class="space-y-1.5 p-4">
                <p class="text-sm font-medium">
                    {{ customer.status_explanation.status }} status update
                    <span
                        v-if="customer.status_explanation.effective_at"
                        class="text-muted-foreground font-normal"
                    >
                        · {{ customer.status_explanation.effective_at }}
                        (Africa/Lagos)
                    </span>
                </p>
                <p class="text-muted-foreground text-sm">
                    {{ customer.status_explanation.explanation }}
                </p>
            </CardContent>
        </Card>

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

        <Card v-if="customer.pending_name_correction">
            <CardHeader>
                <CardTitle>Pending name correction</CardTitle>
                <CardDescription
                    >Expires
                    {{ customer.pending_name_correction.expires_at }}
                    (Africa/Lagos).</CardDescription
                >
            </CardHeader>
            <CardContent
                class="flex flex-wrap items-center justify-between gap-3"
            >
                <p
                    v-if="customer.pending_name_correction.can_review"
                    class="text-sm"
                >
                    Proposed name:
                    <strong>{{
                        customer.pending_name_correction.proposed_name
                    }}</strong>
                </p>
                <p v-else class="text-muted-foreground text-sm">
                    Waiting for the Customer to review the proposed name.
                </p>
                <div class="flex flex-wrap gap-2">
                    <Link
                        v-if="
                            customer.pending_name_correction.can_review ||
                            customer.pending_name_correction.can_cancel
                        "
                        :href="
                            showNameCorrection({
                                customer: customer.id,
                                correction: customer.pending_name_correction.id,
                            }).url
                        "
                    >
                        <Button variant="outline">{{
                            customer.pending_name_correction.can_review
                                ? 'Review correction'
                                : 'View proposal'
                        }}</Button>
                    </Link>
                    <Button
                        v-if="customer.pending_name_correction.can_cancel"
                        variant="destructive"
                        @click="cancelNameProposal"
                        >Cancel proposal</Button
                    >
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

        <!-- Registration Fee Snapshot Card -->
        <Card v-if="customer.fee_snapshot">
            <CardHeader class="pb-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <Coins class="text-primary h-4 w-4" />
                        <CardTitle class="text-base font-semibold"
                            >Registration Fee Terms</CardTitle
                        >
                    </div>
                    <Badge
                        :variant="
                            customer.fee_snapshot.acknowledged_at
                                ? 'default'
                                : 'secondary'
                        "
                    >
                        {{
                            customer.fee_snapshot.acknowledged_at
                                ? 'Terms Acknowledged'
                                : 'Pending Activation'
                        }}
                    </Badge>
                </div>
                <CardDescription>
                    Snapshotted registration terms established at customer
                    record creation.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div class="grid gap-4 text-sm sm:grid-cols-3">
                    <div class="space-y-1">
                        <p
                            class="text-muted-foreground text-xs font-medium uppercase"
                        >
                            Rule Name & Amount
                        </p>
                        <p class="text-foreground font-semibold">
                            {{ customer.fee_snapshot.name }}
                        </p>
                        <p class="text-primary font-mono text-lg font-bold">
                            {{ customer.fee_snapshot.formatted_amount }}
                        </p>
                    </div>
                    <div class="space-y-1">
                        <p
                            class="text-muted-foreground text-xs font-medium uppercase"
                        >
                            Acknowledgement
                        </p>
                        <p
                            v-if="customer.fee_snapshot.acknowledged_at"
                            class="text-foreground text-xs"
                        >
                            Acknowledged on
                            {{ customer.fee_snapshot.acknowledged_at }}
                        </p>
                        <p v-else class="text-muted-foreground text-xs italic">
                            Awaiting customer acceptance during activation.
                        </p>
                    </div>
                    <div class="space-y-1">
                        <p
                            class="text-muted-foreground text-xs font-medium uppercase"
                        >
                            Customer Disclosure
                        </p>
                        <p class="text-muted-foreground text-xs">
                            {{ customer.fee_snapshot.customer_description }}
                        </p>
                    </div>
                </div>
            </CardContent>
        </Card>

        <!-- Invitation Lifecycle & Delivery Card (Admin & Assigned Agent) -->
        <Card v-if="customer.invitation">
            <CardHeader class="pb-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <Mail class="text-primary h-4 w-4" />
                        <CardTitle class="text-base font-semibold"
                            >Invitation & Delivery Lifecycle</CardTitle
                        >
                    </div>
                    <Badge
                        :variant="
                            getInvitationBadgeVariant(
                                customer.invitation.status,
                            )
                        "
                    >
                        {{ customer.invitation.status_label }}
                    </Badge>
                </div>
                <CardDescription>
                    Track account activation invitation state, delivery
                    attempts, and manage resends or address corrections.
                </CardDescription>
            </CardHeader>
            <CardContent class="space-y-4">
                <div class="grid gap-4 text-sm sm:grid-cols-4">
                    <div>
                        <p
                            class="text-muted-foreground text-xs font-medium uppercase"
                        >
                            Delivery Status
                        </p>
                        <Badge variant="outline" class="mt-1">{{
                            customer.invitation.delivery_status_label
                        }}</Badge>
                    </div>
                    <div>
                        <p
                            class="text-muted-foreground text-xs font-medium uppercase"
                        >
                            Generation
                        </p>
                        <p class="mt-1 font-mono text-sm font-semibold">
                            #{{ customer.invitation.generation }}
                        </p>
                    </div>
                    <div>
                        <p
                            class="text-muted-foreground text-xs font-medium uppercase"
                        >
                            Dispatched At
                        </p>
                        <p class="mt-1 text-sm">
                            {{ customer.invitation.sent_at || '—' }}
                        </p>
                    </div>
                    <div>
                        <p
                            class="text-muted-foreground text-xs font-medium uppercase"
                        >
                            Expires At
                        </p>
                        <p class="mt-1 text-sm">
                            {{ customer.invitation.expires_at || '—' }}
                        </p>
                    </div>
                </div>

                <div
                    v-if="customer.invitation.delivery_error"
                    class="bg-destructive/10 text-destructive rounded-lg p-3 text-xs"
                >
                    <p class="font-medium">Delivery Error Encountered:</p>
                    <p class="mt-0.5">
                        {{ customer.invitation.delivery_error }}
                    </p>
                </div>

                <!-- Invitation Management Actions -->
                <div
                    v-if="customer.actions.can_manage_invitation"
                    class="flex flex-wrap items-center gap-3 border-t pt-2"
                >
                    <Button
                        v-if="customer.invitation.can_resend"
                        variant="outline"
                        size="sm"
                        @click="handleResendInvitation"
                    >
                        <RotateCw class="mr-1.5 size-3.5" />
                        Resend Invitation
                    </Button>
                    <Button
                        v-else
                        variant="outline"
                        size="sm"
                        disabled
                        title="Cooldown active or daily limit reached"
                    >
                        <RotateCw class="mr-1.5 size-3.5" />
                        Resend Cooldown
                    </Button>

                    <Button
                        variant="outline"
                        size="sm"
                        @click="openCorrectEmailModal"
                    >
                        <Mail class="mr-1.5 size-3.5" />
                        Correct Email
                    </Button>

                    <Button
                        variant="destructive"
                        size="sm"
                        @click="openCancelModal"
                    >
                        <XCircle class="mr-1.5 size-3.5" />
                        Cancel Invitation
                    </Button>
                </div>
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

        <!-- Correct Email Dialog -->
        <Dialog
            :open="showCorrectEmailModal"
            @update:open="showCorrectEmailModal = $event"
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Correct Customer Email</DialogTitle>
                    <DialogDescription>
                        Update the email address for {{ customer.name }}.
                        Outstanding invitations will be invalidated and a fresh
                        invitation will be dispatched.
                    </DialogDescription>
                </DialogHeader>
                <form @submit.prevent="submitCorrectEmail" class="space-y-4">
                    <div class="space-y-1.5">
                        <Label for="correct-customer-email"
                            >New Email Address
                            <span class="text-destructive">*</span></Label
                        >
                        <Input
                            id="correct-customer-email"
                            v-model="emailForm.email"
                            type="email"
                            required
                            placeholder="customer@example.ng"
                            :class="{
                                'border-destructive': emailForm.errors.email,
                            }"
                        />
                        <p
                            v-if="emailForm.errors.email"
                            class="text-destructive text-xs"
                        >
                            {{ emailForm.errors.email }}
                        </p>
                    </div>

                    <div class="space-y-1.5">
                        <Label for="correct-customer-email-reason"
                            >Justification Reason
                            <span class="text-destructive">*</span></Label
                        >
                        <textarea
                            id="correct-customer-email-reason"
                            v-model="emailForm.reason"
                            rows="2"
                            required
                            placeholder="Reason for updating email address (e.g. Typo in initial registration address)"
                            class="border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                            :class="{
                                'border-destructive': emailForm.errors.reason,
                            }"
                        />
                        <p
                            v-if="emailForm.errors.reason"
                            class="text-destructive text-xs"
                        >
                            {{ emailForm.errors.reason }}
                        </p>
                    </div>

                    <DialogFooter class="gap-2 sm:gap-0">
                        <Button
                            type="button"
                            variant="outline"
                            @click="showCorrectEmailModal = false"
                            >Cancel</Button
                        >
                        <Button type="submit" :disabled="emailForm.processing">
                            <Loader2
                                v-if="emailForm.processing"
                                class="mr-2 size-4 animate-spin"
                            />
                            Update Email & Resend
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Cancel Invitation Dialog -->
        <Dialog :open="showCancelModal" @update:open="showCancelModal = $event">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Cancel Customer Invitation</DialogTitle>
                    <DialogDescription>
                        Invalidate outstanding invitation and activation links
                        for {{ customer.name }}. The account will not be able to
                        activate with cancelled links.
                    </DialogDescription>
                </DialogHeader>
                <form
                    @submit.prevent="submitCancelInvitation"
                    class="space-y-4"
                >
                    <div class="space-y-1.5">
                        <Label for="cancel-customer-invitation-reason"
                            >Cancellation Reason
                            <span class="text-destructive">*</span></Label
                        >
                        <textarea
                            id="cancel-customer-invitation-reason"
                            v-model="cancelForm.reason"
                            rows="2"
                            required
                            placeholder="Reason for cancelling invitation (e.g. Customer requested onboarding withdrawal)"
                            class="border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                            :class="{
                                'border-destructive': cancelForm.errors.reason,
                            }"
                        />
                        <p
                            v-if="cancelForm.errors.reason"
                            class="text-destructive text-xs"
                        >
                            {{ cancelForm.errors.reason }}
                        </p>
                    </div>

                    <DialogFooter class="gap-2 sm:gap-0">
                        <Button
                            type="button"
                            variant="outline"
                            @click="showCancelModal = false"
                            >Keep Invitation</Button
                        >
                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="cancelForm.processing"
                        >
                            <Loader2
                                v-if="cancelForm.processing"
                                class="mr-2 size-4 animate-spin"
                            />
                            Confirm Cancellation
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
