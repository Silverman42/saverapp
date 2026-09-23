<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { AlertCircle, ArrowLeft, CalendarDays, History, PencilLine, WalletCards } from '@lucide/vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { show as showCustomer } from '@/routes/customers';
import { create as createCustomerPlan } from '@/routes/customers/plans';
import { index as plansIndex, edit as editPlan, show as showPlan, card as planCard, pause as pausePlan, resume as resumePlan, cancel as cancelPlan } from '@/routes/plans';
import { create as createCollection } from '@/routes/customers/collections';

const page = usePage();

type PlanData = {
    id: string;
    status: string;
    status_label: string;
    version: number;
    terms_revision: number;
    activity_started_at: string | null;
    customer: { id: string; name: string };
    predecessor: { id: string; status: string } | null;
    current_terms: {
        name: string;
        formatted_contribution_amount: string;
        currency: string;
        start_date: string;
        contribution_days: number;
        scheduled_end_date: string;
        frequency: string;
        timezone: string;
        formatted_expected_gross: string;
        customer_visible_notes: string | null;
        reason: string | null;
    } | null;
    fee: {
        name: string;
        formatted_amount: string;
        estimated_amount: string | null;
        estimate_available: boolean;
        description: string;
        acknowledged_at: string | null;
    } | null;
    slots: Array<{ ordinal: number; due_date: string; formatted_expected_amount: string; collection_status: string }>;
    revisions: Array<{ revision: number; name: string; formatted_contribution_amount: string; start_date: string; contribution_days: number; timezone: string; reason: string | null; created_at: string | null }>;
    history: Array<{ event: string; status: string | null; explanation: string | null; reason: string | null; actor: string | null; effective_at: string | null }>;
    financial_summary: { status: string; message: string };
    created_at: string | null;
};

type LifecycleAction = 'pause' | 'resume' | 'cancel';

const props = defineProps<{
    plan: PlanData;
    customer: { id: string; name: string; status: string; version: number; assignment_version: number | null };
    actions: { can_manage: boolean; can_edit: boolean; can_pause: boolean; can_resume: boolean; can_cancel: boolean; can_renew: boolean };
    attempt_reference: string;
}>();

const confirmationAction = ref<LifecycleAction | null>(null);
const showAllSlots = ref(false);
const transitionForm = useForm({
    attempt_reference: props.attempt_reference,
    plan_version: props.plan.version,
    customer_version: props.customer.version,
    assignment_version: props.customer.assignment_version ?? 0,
    reason: '',
    customer_explanation: '',
});

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Plans', href: plansIndex() },
            { title: 'Plan', href: '#' },
        ],
    },
});

const visibleSlots = computed(() => showAllSlots.value ? props.plan.slots : props.plan.slots.slice(0, 10));
const remainingSlots = computed(() => Math.max(props.plan.slots.length - visibleSlots.value.length, 0));
const confirmationTitle = computed(() => {
    if (confirmationAction.value === 'pause') return 'Pause this plan?';
    if (confirmationAction.value === 'resume') return 'Resume this plan?';
    return 'Cancel this unused plan?';
});

watch(
    () => [props.plan.version, props.customer.version, props.customer.assignment_version, props.attempt_reference],
    () => {
        transitionForm.plan_version = props.plan.version;
        transitionForm.customer_version = props.customer.version;
        transitionForm.assignment_version = props.customer.assignment_version ?? 0;
        transitionForm.attempt_reference = props.attempt_reference;
    },
);

const openAction = (action: LifecycleAction): void => {
    confirmationAction.value = action;
    transitionForm.clearErrors();
};

const submitAction = (): void => {
    const action = confirmationAction.value;
    if (!action) return;
    const path = action === 'pause'
        ? pausePlan(props.plan.id).url
        : action === 'resume'
          ? resumePlan(props.plan.id).url
          : cancelPlan(props.plan.id).url;

    transitionForm.transform((data) => ({
        ...data,
        attempt_reference: props.attempt_reference,
        plan_version: props.plan.version,
        customer_version: props.customer.version,
        assignment_version: props.customer.assignment_version ?? 0,
    })).post(path, {
        preserveScroll: true,
        onSuccess: () => {
            confirmationAction.value = null;
            transitionForm.reset('reason', 'customer_explanation');
        },
    });
};

const statusVariant = (status: string): 'default' | 'secondary' | 'destructive' | 'outline' => {
    if (status === 'active') return 'default';
    if (status === 'paused') return 'secondary';
    if (status === 'cancelled') return 'destructive';
    return 'outline';
};
</script>

<template>
    <Head :title="`${plan.current_terms?.name ?? 'Thrift plan'} · ${plan.id}`" />

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-[25px] font-medium tracking-tight">{{ plan.current_terms?.name ?? 'Daily thrift plan' }}</h1>
                <p class="text-muted-foreground mt-1.5 text-sm">
                    <Link :href="showCustomer(customer.id).url" class="hover:underline">{{ customer.name }} · {{ customer.id }}</Link>
                    <span> · {{ plan.id }} · revision {{ plan.terms_revision }}</span>
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button v-if="page.props.features.collections" as-child variant="outline"><Link :href="planCard(plan.id).url">View thrift card</Link></Button>
                <Button v-if="page.props.features.collections && actions.can_manage && plan.status === 'active'" as-child><Link :href="createCollection(customer.id).url">Record cash</Link></Button>
                <Button v-if="actions.can_edit" as-child variant="outline"><Link :href="editPlan(plan.id).url"><PencilLine class="mr-2 size-4" />Amend terms</Link></Button>
                <Button v-if="actions.can_pause" variant="outline" @click="openAction('pause')">Pause</Button>
                <Button v-if="actions.can_resume" @click="openAction('resume')">Resume</Button>
                <Button v-if="actions.can_cancel" variant="destructive" @click="openAction('cancel')">Cancel unused plan</Button>
                <Button v-if="actions.can_renew" as-child><Link :href="createCustomerPlan(customer.id, { query: { predecessor_plan_id: plan.id } }).url">Create renewal</Link></Button>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-[minmax(0,1.6fr)_minmax(280px,0.9fr)]">
            <Card>
                <CardHeader class="flex-row items-start justify-between">
                    <div>
                        <CardTitle>Agreed terms</CardTitle>
                        <CardDescription>Revision {{ plan.terms_revision }} · created {{ plan.created_at ?? 'date unavailable' }}</CardDescription>
                    </div>
                    <Badge :variant="statusVariant(plan.status)">{{ plan.status_label }}</Badge>
                </CardHeader>
                <CardContent v-if="plan.current_terms" class="grid gap-5 sm:grid-cols-2">
                    <div><p class="text-muted-foreground text-xs">Daily contribution</p><p class="mt-1 text-lg font-semibold">{{ plan.current_terms.formatted_contribution_amount }}</p></div>
                    <div><p class="text-muted-foreground text-xs">Frequency</p><p class="mt-1 font-medium">Daily · {{ plan.current_terms.contribution_days }} scheduled days</p></div>
                    <div><p class="text-muted-foreground text-xs">Schedule</p><p class="mt-1 font-medium">{{ plan.current_terms.start_date }} – {{ plan.current_terms.scheduled_end_date }}</p></div>
                    <div><p class="text-muted-foreground text-xs">Timezone</p><p class="mt-1 font-medium">{{ plan.current_terms.timezone }}</p></div>
                    <div><p class="text-muted-foreground text-xs">Expected gross</p><p class="mt-1 font-medium">{{ plan.current_terms.formatted_expected_gross }}</p><p class="text-muted-foreground mt-1 text-xs">Contractual estimate only</p></div>
                    <div v-if="plan.current_terms.customer_visible_notes" class="sm:col-span-2"><p class="text-muted-foreground text-xs">Customer-visible notes</p><p class="mt-1 whitespace-pre-wrap text-sm">{{ plan.current_terms.customer_visible_notes }}</p></div>
                </CardContent>
                <CardContent v-else><p class="text-muted-foreground text-sm">Agreed terms are unavailable.</p></CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2"><WalletCards class="size-4" /> Actual savings</CardTitle>
                    <CardDescription>Financial activity belongs to collection and ledger workflows.</CardDescription>
                </CardHeader>
                <CardContent>
                    <Alert>
                        <AlertCircle class="size-4" />
                        <AlertTitle>Unavailable</AlertTitle>
                        <AlertDescription>{{ plan.financial_summary.message }}</AlertDescription>
                    </Alert>
                </CardContent>
            </Card>
        </div>

        <Card v-if="plan.fee">
            <CardHeader>
                <CardTitle>Agreed fee terms</CardTitle>
                <CardDescription>{{ plan.fee.name }}</CardDescription>
            </CardHeader>
            <CardContent class="space-y-2">
                <p class="font-medium">{{ plan.fee.formatted_amount }}</p>
                <p class="text-muted-foreground text-sm">{{ plan.fee.description }}</p>
                <p v-if="plan.fee.estimate_available" class="text-muted-foreground text-xs">The fee snapshot is contractual; any assessment waits for the financial workflow that owns it.</p>
                <p v-else class="text-muted-foreground text-xs">The amount is calculated when a withdrawal is quoted.</p>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <div class="flex items-start gap-3">
                    <CalendarDays class="text-primary mt-0.5 size-5" />
                    <div><CardTitle>Expected contribution dates</CardTitle><CardDescription>These are expected schedule slots. Collection status is unavailable.</CardDescription></div>
                </div>
            </CardHeader>
            <CardContent>
                <div v-if="plan.slots.length" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="slot in visibleSlots" :key="slot.ordinal" class="flex items-center justify-between rounded-xl border px-3 py-2.5">
                        <div><p class="text-xs font-medium">Day {{ slot.ordinal }}</p><p class="text-muted-foreground text-xs">{{ slot.due_date }}</p></div>
                        <div class="text-right"><p class="text-sm font-medium">{{ slot.formatted_expected_amount }}</p><p class="text-muted-foreground text-[11px]">collection unavailable</p></div>
                    </div>
                </div>
                <p v-else class="text-muted-foreground text-sm">No schedule slots are available.</p>
                <Button v-if="remainingSlots > 0 || showAllSlots" class="mt-4" variant="outline" size="sm" @click="showAllSlots = !showAllSlots">
                    {{ showAllSlots ? 'Show fewer dates' : `Show all ${plan.slots.length} dates` }}
                </Button>
            </CardContent>
        </Card>

        <div class="grid gap-4 lg:grid-cols-2">
            <Card>
                <CardHeader><CardTitle>Revision history</CardTitle><CardDescription>Each accepted version of the plan terms is retained.</CardDescription></CardHeader>
                <CardContent>
                    <ol class="space-y-4">
                        <li v-for="revision in plan.revisions" :key="revision.revision" class="border-l-2 pl-4">
                            <p class="font-medium">Revision {{ revision.revision }} · {{ revision.name }}</p>
                            <p class="text-muted-foreground mt-1 text-sm">{{ revision.formatted_contribution_amount }} daily · {{ revision.contribution_days }} days from {{ revision.start_date }}</p>
                            <p v-if="revision.reason" class="text-muted-foreground mt-1 text-xs">Reason: {{ revision.reason }}</p>
                            <p class="text-muted-foreground mt-1 text-xs">{{ revision.created_at ?? 'Date unavailable' }}</p>
                        </li>
                    </ol>
                </CardContent>
            </Card>
            <Card>
                <CardHeader><CardTitle class="flex items-center gap-2"><History class="size-4" /> Plan history</CardTitle><CardDescription>Lifecycle actions are recorded with their reasons.</CardDescription></CardHeader>
                <CardContent>
                    <ol v-if="plan.history.length" class="space-y-4">
                        <li v-for="(event, index) in plan.history" :key="`${event.event}-${index}`" class="border-l-2 pl-4">
                            <p class="font-medium">{{ event.event }}<span v-if="event.status"> · {{ event.status }}</span></p>
                            <p v-if="event.explanation" class="mt-1 text-sm">{{ event.explanation }}</p>
                            <p v-if="event.reason" class="text-muted-foreground mt-1 text-xs">Reason: {{ event.reason }}</p>
                            <p v-if="event.actor" class="text-muted-foreground mt-1 text-xs">Recorded by {{ event.actor }}</p>
                            <p class="text-muted-foreground mt-1 text-xs">{{ event.effective_at ?? 'Date unavailable' }}</p>
                        </li>
                    </ol>
                    <p v-else class="text-muted-foreground text-sm">No lifecycle events are available.</p>
                </CardContent>
            </Card>
        </div>

        <div class="flex justify-start">
            <Button as-child variant="ghost"><Link :href="plansIndex()"><ArrowLeft class="mr-2 size-4" />Back to plans</Link></Button>
        </div>
    </div>

    <Dialog :open="confirmationAction !== null" @update:open="(open) => { if (!open) confirmationAction = null; }">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ confirmationTitle }}</DialogTitle>
                <DialogDescription>
                    <template v-if="confirmationAction === 'cancel'">Cancellation is available only for a plan with no recorded activity and no fee obligation. The server rechecks both conditions.</template>
                    <template v-else-if="confirmationAction === 'pause'">The agreed schedule remains in history. No financial activity is recorded by this action.</template>
                    <template v-else>The plan returns to Active only if the Customer is currently Active.</template>
                </DialogDescription>
            </DialogHeader>
            <div class="grid gap-4">
                <div class="grid gap-2">
                    <Label for="plan-action-reason">Reason</Label>
                    <textarea id="plan-action-reason" v-model="transitionForm.reason" rows="2" maxlength="500" class="min-h-20 w-full rounded-xl border border-input bg-background px-3 py-2 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring/30" />
                    <p v-if="transitionForm.errors.reason" class="text-destructive text-sm">{{ transitionForm.errors.reason }}</p>
                </div>
                <div class="grid gap-2">
                    <Label for="plan-action-explanation">Explanation for Customer</Label>
                    <textarea id="plan-action-explanation" v-model="transitionForm.customer_explanation" rows="2" maxlength="500" class="min-h-20 w-full rounded-xl border border-input bg-background px-3 py-2 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring/30" />
                    <p v-if="transitionForm.errors.customer_explanation" class="text-destructive text-sm">{{ transitionForm.errors.customer_explanation }}</p>
                </div>
                <p v-if="transitionForm.errors.plan_version" class="text-destructive text-sm">{{ transitionForm.errors.plan_version }}</p>
            </div>
            <DialogFooter>
                <Button variant="outline" :disabled="transitionForm.processing" @click="confirmationAction = null">Keep current status</Button>
                <Button :variant="confirmationAction === 'cancel' ? 'destructive' : 'default'" :disabled="transitionForm.processing || transitionForm.reason.trim().length < 3 || transitionForm.customer_explanation.trim().length < 3" @click="submitAction">
                    {{ transitionForm.processing ? 'Saving…' : 'Confirm action' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
