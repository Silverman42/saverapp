<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import {
    AlertCircle,
    FileText,
    History,
    MoreHorizontal,
    Pencil,
    Plus,
    Receipt,
} from '@lucide/vue';
import CustomerFeesCard from '@/components/CustomerFeesCard.vue';
import type {
    FeeSnapshot,
    PlanFeeObligation,
} from '@/components/CustomerFeesCard.vue';
import CustomerInvitationCard from '@/components/CustomerInvitationCard.vue';
import type { CustomerInvitation } from '@/components/CustomerInvitationCard.vue';
import ManagementDeliveryPanel from '@/components/ManagementDeliveryPanel.vue';
import MoreDetails from '@/components/MoreDetails.vue';
import NameCorrectionReview from '@/components/NameCorrectionReview.vue';
import PageHeader from '@/components/PageHeader.vue';
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
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { dashboard } from '@/routes';
import { create as createCustomerPlan } from '@/routes/customers/plans';
import { create as createCollection } from '@/routes/customers/collections';
import { index as plansIndex, show as showPlan } from '@/routes/plans';
import { index as transactionsIndex } from '@/routes/transactions';
import { preview as statementPreview } from '@/routes/customers/statements';
import {
    edit as editCustomer,
    index as customersIndex,
} from '@/routes/customers';
import { edit as reassignCustomer } from '@/routes/customers/reassignment';
import { show as recoverCustomer } from '@/routes/customers/recovery';
import { edit as manageCustomerStatus } from '@/routes/customers/status';

export type { CustomerInvitation, FeeSnapshot };

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
        liability: string | null;
        reserved: string | null;
        available: string | null;
    };
    plans: {
        status: string;
        message: string;
        current_plan: {
            id: string;
            name: string;
            status: string;
            status_label: string;
            formatted_contribution_amount: string;
            start_date: string;
            scheduled_end_date: string;
        } | null;
        can_create: boolean;
        can_record_cash: boolean;
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
        can_recover?: boolean;
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
    fee_obligations: PlanFeeObligation[];
}>();

const planFeeObligations = computed(() =>
    props.fee_obligations.filter(
        (obligation) => obligation.kind !== 'registration',
    ),
);

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

const nameCorrectionOpen = ref(false);

const hasMoreActions = computed(
    () =>
        props.customer.actions.can_reassign ||
        props.customer.actions.can_recover ||
        props.customer.actions.can_manage_status ||
        props.customer.actions.can_archive,
);

const getInitials = (name: string): string => {
    const parts = name.trim().split(/\s+/);
    if (parts.length === 0 || !parts[0]) {
        return 'CU';
    }
    if (parts.length === 1) {
        return parts[0].substring(0, 2).toUpperCase();
    }
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
};

const getOperationalBadgeVariant = (
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' => {
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

const getAccountBadgeVariant = (
    state: string,
): 'default' | 'secondary' | 'destructive' | 'outline' => {
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
        <div class="space-y-3">
            <PageHeader
                :title="customer.name"
                :description="`Customer since ${customer.registered_at}`"
            >
                <template #actions>
                    <Button v-if="customer.plans.can_record_cash" as-child>
                        <Link :href="createCollection(customer.id).url"
                            >Record cash</Link
                        >
                    </Button>
                    <Button v-else-if="customer.plans.can_create" as-child>
                        <Link :href="createCustomerPlan(customer.id).url"
                            ><Plus class="size-4" /> New plan</Link
                        >
                    </Button>
                    <Button
                        v-if="customer.actions.can_edit"
                        as-child
                        variant="outline"
                    >
                        <Link :href="editCustomer(customer.id).url"
                            ><Pencil class="size-4" /> Edit</Link
                        >
                    </Button>
                    <DropdownMenu :modal="false" v-if="hasMoreActions">
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="More actions"
                            >
                                <MoreHorizontal class="size-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-52">
                            <DropdownMenuItem
                                v-if="customer.actions.can_reassign"
                                as-child
                            >
                                <Link
                                    class="w-full cursor-pointer"
                                    :href="reassignCustomer.url(customer.id)"
                                    >Change agent</Link
                                >
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="customer.actions.can_recover"
                                as-child
                            >
                                <Link
                                    class="w-full cursor-pointer"
                                    :href="recoverCustomer.url(customer.id)"
                                    >Account access help</Link
                                >
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="customer.actions.can_manage_status"
                                as-child
                            >
                                <Link
                                    class="w-full cursor-pointer"
                                    :href="
                                        manageCustomerStatus(customer.id).url
                                    "
                                    >{{
                                        customer.operational_status ===
                                        'archived'
                                            ? 'Restore customer'
                                            : 'Change status'
                                    }}</Link
                                >
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="customer.actions.can_archive"
                                as-child
                            >
                                <Link
                                    class="text-destructive w-full cursor-pointer"
                                    :href="
                                        manageCustomerStatus(customer.id).url
                                    "
                                    >Archive customer</Link
                                >
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </template>
            </PageHeader>
            <div class="flex flex-wrap items-center gap-2">
                <Badge
                    :variant="
                        getOperationalBadgeVariant(customer.operational_status)
                    "
                >
                    {{ customer.operational_status_label }}
                </Badge>
                <Badge
                    :variant="getAccountBadgeVariant(customer.account_state)"
                >
                    Account: {{ customer.account_state_label }}
                </Badge>
            </div>
        </div>

        <div
            v-if="customer.status_explanation"
            role="status"
            class="bg-muted/50 rounded-xl p-4 text-sm"
        >
            <p class="font-medium">
                Status changed to {{ customer.status_explanation.status }}
                <span
                    v-if="customer.status_explanation.effective_at"
                    class="text-muted-foreground font-normal"
                >
                    on {{ customer.status_explanation.effective_at }}
                </span>
            </p>
            <p class="text-muted-foreground mt-1">
                {{ customer.status_explanation.explanation }}
            </p>
        </div>

        <div
            v-if="customer.pending_name_correction"
            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4"
        >
            <div class="text-sm">
                <p class="font-medium">A name change is waiting</p>
                <p class="text-muted-foreground mt-0.5">
                    {{
                        customer.pending_name_correction.can_review
                            ? 'Please check the new name and confirm it.'
                            : 'Waiting for the customer to confirm.'
                    }}
                </p>
            </div>
            <Button
                v-if="
                    customer.pending_name_correction.can_review ||
                    customer.pending_name_correction.can_cancel
                "
                variant="outline"
                @click="nameCorrectionOpen = true"
                >{{
                    customer.pending_name_correction.can_review
                        ? 'Review'
                        : 'View'
                }}</Button
            >
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Savings</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-5">
                        <div
                            v-if="customer.financial_summary.status === 'ready'"
                            class="grid gap-3 sm:grid-cols-3"
                        >
                            <div class="bg-muted/40 rounded-xl p-4">
                                <p class="text-muted-foreground text-sm">
                                    Total saved
                                </p>
                                <p class="mt-2 text-2xl font-semibold">
                                    {{ customer.financial_summary.liability }}
                                </p>
                            </div>
                            <div class="bg-muted/40 rounded-xl p-4">
                                <p class="text-muted-foreground text-sm">
                                    Held for withdrawals
                                </p>
                                <p class="mt-2 text-2xl font-semibold">
                                    {{ customer.financial_summary.reserved }}
                                </p>
                            </div>
                            <div class="bg-muted/40 rounded-xl p-4">
                                <p class="text-muted-foreground text-sm">
                                    Available
                                </p>
                                <p class="mt-2 text-2xl font-semibold">
                                    {{ customer.financial_summary.available }}
                                </p>
                            </div>
                        </div>
                        <div
                            v-else
                            role="status"
                            class="flex items-start gap-3 rounded-xl bg-amber-500/10 p-4 text-sm text-amber-600 dark:text-amber-400"
                        >
                            <AlertCircle class="mt-0.5 size-4 shrink-0" />
                            <div>
                                <p class="font-medium">
                                    Balance not available right now
                                </p>
                                <p class="mt-0.5 text-xs">
                                    Please check again later.
                                </p>
                            </div>
                        </div>

                        <div class="border-t pt-5">
                            <div
                                v-if="customer.plans.current_plan"
                                class="flex flex-wrap items-start justify-between gap-3"
                            >
                                <div class="min-w-0">
                                    <p class="text-muted-foreground text-sm">
                                        Current plan
                                    </p>
                                    <Link
                                        :href="
                                            showPlan(
                                                customer.plans.current_plan.id,
                                            ).url
                                        "
                                        class="mt-0.5 inline-block font-medium underline-offset-4 hover:underline"
                                    >
                                        {{ customer.plans.current_plan.name }}
                                    </Link>
                                    <p class="text-muted-foreground text-sm">
                                        {{
                                            customer.plans.current_plan
                                                .formatted_contribution_amount
                                        }}
                                        a day ·
                                        {{
                                            customer.plans.current_plan
                                                .start_date
                                        }}
                                        to
                                        {{
                                            customer.plans.current_plan
                                                .scheduled_end_date
                                        }}
                                    </p>
                                </div>
                                <Badge variant="secondary">{{
                                    customer.plans.current_plan.status_label
                                }}</Badge>
                            </div>
                            <div v-else class="text-sm">
                                <p class="font-medium">No open plan</p>
                                <p class="text-muted-foreground mt-0.5">
                                    {{
                                        customer.plans.can_create
                                            ? 'Start a plan to begin saving.'
                                            : 'This customer has no plan running.'
                                    }}
                                </p>
                            </div>

                            <div class="mt-4 flex flex-wrap gap-2">
                                <Button as-child variant="outline" size="sm">
                                    <Link
                                        :href="
                                            plansIndex({
                                                query: { search: customer.id },
                                            }).url
                                        "
                                        ><History class="size-3.5" /> All
                                        plans</Link
                                    >
                                </Button>
                                <Button
                                    v-if="
                                        customer.transactions.status === 'ready'
                                    "
                                    as-child
                                    variant="outline"
                                    size="sm"
                                >
                                    <Link
                                        :href="
                                            transactionsIndex({
                                                query: {
                                                    customer: customer.id,
                                                },
                                            })
                                        "
                                        ><Receipt class="size-3.5" />
                                        Transactions</Link
                                    >
                                </Button>
                                <Button
                                    v-if="
                                        customer.statements.status === 'ready'
                                    "
                                    as-child
                                    variant="outline"
                                    size="sm"
                                >
                                    <Link :href="statementPreview(customer.id)"
                                        ><FileText class="size-3.5" />
                                        Statement</Link
                                    >
                                </Button>
                            </div>
                            <p
                                v-if="
                                    customer.transactions.status !== 'ready' ||
                                    customer.statements.status !== 'ready'
                                "
                                class="text-muted-foreground mt-3 text-xs"
                            >
                                Transactions and statements are not available
                                right now.
                            </p>
                        </div>

                        <MoreDetails>
                            <ul
                                class="text-muted-foreground space-y-1.5 text-xs"
                            >
                                <li>
                                    {{ customer.financial_summary.message }}
                                </li>
                                <li>{{ customer.plans.message }}</li>
                                <li>{{ customer.transactions.message }}</li>
                                <li>{{ customer.statements.message }}</li>
                            </ul>
                        </MoreDetails>
                    </CardContent>
                </Card>

                <CustomerFeesCard
                    v-if="customer.fee_snapshot || planFeeObligations.length"
                    :fee-snapshot="customer.fee_snapshot"
                    :plan-fees="planFeeObligations"
                />

                <CustomerInvitationCard
                    v-if="customer.invitation"
                    :customer-id="customer.id"
                    :customer-name="customer.name"
                    :customer-email="customer.email"
                    :invitation="customer.invitation"
                    :can-manage="!!customer.actions.can_manage_invitation"
                />
            </div>

            <div class="space-y-6">
                <Card>
                    <CardContent class="space-y-5">
                        <div class="flex items-center gap-4">
                            <Avatar class="size-14">
                                <AvatarImage
                                    v-if="customer.photo_url"
                                    :src="customer.photo_url"
                                    :alt="customer.name"
                                />
                                <AvatarFallback class="font-medium">{{
                                    getInitials(customer.name)
                                }}</AvatarFallback>
                            </Avatar>
                            <div class="min-w-0">
                                <p class="truncate font-medium">
                                    {{ customer.name }}
                                </p>
                                <p class="text-muted-foreground text-sm">
                                    {{ customer.id }}
                                </p>
                            </div>
                        </div>

                        <dl class="divide-y text-sm">
                            <div class="flex justify-between gap-4 py-2.5">
                                <dt class="text-muted-foreground">Phone</dt>
                                <dd class="text-right font-medium">
                                    {{ customer.phone }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4 py-2.5">
                                <dt class="text-muted-foreground">Email</dt>
                                <dd class="text-right font-medium break-all">
                                    {{ customer.email || 'Not added' }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4 py-2.5">
                                <dt class="text-muted-foreground">Address</dt>
                                <dd class="text-right">
                                    {{ customer.address || 'Not added' }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4 py-2.5">
                                <dt class="text-muted-foreground">Agent</dt>
                                <dd class="text-right">
                                    <template v-if="customer.assigned_agent">
                                        <span class="font-medium">{{
                                            customer.assigned_agent.name
                                        }}</span>
                                        <span
                                            v-if="customer.assigned_agent.phone"
                                            class="text-muted-foreground block text-xs"
                                            >{{
                                                customer.assigned_agent.phone
                                            }}</span
                                        >
                                    </template>
                                    <span v-else class="text-muted-foreground"
                                        >No agent yet</span
                                    >
                                </dd>
                            </div>
                        </dl>

                        <MoreDetails>
                            <dl class="divide-y text-sm">
                                <div class="flex justify-between gap-4 py-2">
                                    <dt class="text-muted-foreground">
                                        Gender
                                    </dt>
                                    <dd class="capitalize">
                                        {{
                                            customer.gender?.replaceAll(
                                                '_',
                                                ' ',
                                            ) || 'Not added'
                                        }}
                                    </dd>
                                </div>
                                <div class="flex justify-between gap-4 py-2">
                                    <dt class="text-muted-foreground">
                                        Occupation
                                    </dt>
                                    <dd>
                                        {{ customer.occupation || 'Not added' }}
                                    </dd>
                                </div>
                                <div class="flex justify-between gap-4 py-2">
                                    <dt class="text-muted-foreground">
                                        Reference
                                    </dt>
                                    <dd>
                                        {{
                                            customer.internal_reference ||
                                            'None'
                                        }}
                                    </dd>
                                </div>
                                <div class="py-2">
                                    <dt class="text-muted-foreground">
                                        Next of kin
                                    </dt>
                                    <dd
                                        v-if="customer.next_of_kin"
                                        class="mt-1"
                                    >
                                        <p class="font-medium">
                                            {{ customer.next_of_kin.full_name }}
                                            <span
                                                v-if="
                                                    customer.next_of_kin
                                                        .relationship
                                                "
                                                class="text-muted-foreground font-normal"
                                                >({{
                                                    customer.next_of_kin
                                                        .relationship
                                                }})</span
                                            >
                                        </p>
                                        <p>{{ customer.next_of_kin.phone }}</p>
                                        <p
                                            v-if="customer.next_of_kin.address"
                                            class="text-muted-foreground"
                                        >
                                            {{ customer.next_of_kin.address }}
                                        </p>
                                    </dd>
                                    <dd
                                        v-else
                                        class="text-muted-foreground mt-1"
                                    >
                                        Not added
                                    </dd>
                                </div>
                                <template v-if="customer.assigned_agent">
                                    <div
                                        v-if="customer.assigned_agent.id"
                                        class="flex justify-between gap-4 py-2"
                                    >
                                        <dt class="text-muted-foreground">
                                            Agent ID
                                        </dt>
                                        <dd>
                                            {{ customer.assigned_agent.id }}
                                        </dd>
                                    </div>
                                    <div
                                        v-if="customer.assigned_agent.email"
                                        class="flex justify-between gap-4 py-2"
                                    >
                                        <dt class="text-muted-foreground">
                                            Agent email
                                        </dt>
                                        <dd class="break-all">
                                            {{ customer.assigned_agent.email }}
                                        </dd>
                                    </div>
                                    <div
                                        v-if="
                                            customer.relationship_history
                                                .assignment_started_at
                                        "
                                        class="flex justify-between gap-4 py-2"
                                    >
                                        <dt class="text-muted-foreground">
                                            Agent since
                                        </dt>
                                        <dd>
                                            {{
                                                customer.relationship_history
                                                    .assignment_started_at
                                            }}
                                        </dd>
                                    </div>
                                </template>
                                <p class="text-muted-foreground py-2 text-xs">
                                    Times are Lagos time.
                                </p>
                            </dl>
                        </MoreDetails>
                    </CardContent>
                </Card>

                <Card v-if="customer.notes !== undefined">
                    <CardHeader>
                        <CardTitle class="text-base">Staff notes</CardTitle>
                        <CardDescription
                            >The customer can't see these.</CardDescription
                        >
                    </CardHeader>
                    <CardContent>
                        <p
                            v-if="customer.notes"
                            class="bg-muted/50 rounded-lg p-3 text-sm whitespace-pre-wrap"
                        >
                            {{ customer.notes }}
                        </p>
                        <p v-else class="text-muted-foreground text-sm">
                            No notes yet.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </div>

        <ManagementDeliveryPanel subject="customer" :reference="customer.id" />

        <Dialog
            v-if="customer.pending_name_correction"
            :open="nameCorrectionOpen"
            @update:open="nameCorrectionOpen = $event"
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Name change</DialogTitle>
                    <DialogDescription>
                        Check the new name before you confirm it.
                    </DialogDescription>
                </DialogHeader>
                <NameCorrectionReview
                    :customer-id="customer.id"
                    :correction="customer.pending_name_correction"
                    @done="nameCorrectionOpen = false"
                />
            </DialogContent>
        </Dialog>
    </div>
</template>
