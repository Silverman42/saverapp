<script setup lang="ts">
import PlanFeeHistory from '@/components/PlanFeeHistory.vue';
import type { PlanFeeHistory as FeeHistory } from '@/types/plan-fee-history';
import PlanPostedActivity from '@/components/PlanPostedActivity.vue';
import PlanPostingHistory from '@/components/PlanPostingHistory.vue';
import type { PlanPostingHistory as PostingHistory } from '@/types/plan-posted-activity';
import type { PlanPostedActivity as PostedActivity } from '@/types/plan-posted-activity';
import PlanSavingsSummary from '@/components/PlanSavingsSummary.vue';
import type { PlanSavings } from '@/types/plan-savings';
import PlanEstimateSummary from '@/components/PlanEstimateSummary.vue';
import type { PlanEstimate } from '@/types/plan-estimate';
import {
    Head,
    Link,
    router,
    setLayoutProps,
    useForm,
    usePage,
} from '@inertiajs/vue3';
import { computed, nextTick, ref, watch, watchEffect } from 'vue';
import {
    ArrowLeft,
    CalendarDays,
    Ellipsis,
    History,
    PencilLine,
} from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import FormSheet from '@/components/FormSheet.vue';
import MoreDetails from '@/components/MoreDetails.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import PlanFundingSummary from '@/components/PlanFundingSummary.vue';
import type { PlanFundingSummary as FundingSummary } from '@/types/plan-funding';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { show as showCustomer } from '@/routes/customers';
import { create as createCustomerPlan } from '@/routes/customers/plans';
import {
    settlement as planSettlement,
    index as plansIndex,
    edit as editPlan,
    show as showPlan,
    card as planCard,
    pause as pausePlan,
    resume as resumePlan,
    cancel as cancelPlan,
} from '@/routes/plans';
import { create as createCollection } from '@/routes/customers/collections';

const page = usePage();

type PlanData = {
    fee_history: FeeHistory;
    posted_activity: PostedActivity;
    posting_history: PostingHistory;
    savings_summary: PlanSavings;
    estimate: PlanEstimate | null;
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
        early_termination_policy_version: number | null;
        early_termination_description: string | null;
        acknowledged_at: string | null;
    } | null;
    slots: Array<{
        ordinal: number;
        due_date: string;
        formatted_expected_amount: string;
        collection_status: string;
        formatted_funded_amount: string | null;
        formatted_remaining_amount: string | null;
        advance: boolean | null;
    }>;
    revisions: Array<{
        revision: number;
        name: string;
        formatted_contribution_amount: string;
        start_date: string;
        contribution_days: number;
        timezone: string;
        reason: string | null;
        created_at: string | null;
    }>;
    history: Array<{
        event: string;
        status: string | null;
        explanation: string | null;
        reason: string | null;
        actor: string | null;
        effective_at: string | null;
    }>;
    financial_summary: FundingSummary;
    created_at: string | null;
};

type LifecycleAction = 'pause' | 'resume' | 'cancel';

const props = defineProps<{
    plan: PlanData;
    customer: {
        id: string;
        name: string;
        status: string;
        version: number;
        assignment_version: number | null;
    };
    actions: {
        can_manage: boolean;
        can_settle: boolean;
        can_edit: boolean;
        can_pause: boolean;
        can_resume: boolean;
        can_cancel: boolean;
        can_renew: boolean;
    };
    attempt_reference: string;
    directory_context: Record<string, string | number>;
}>();

const confirmationAction = ref<LifecycleAction | null>(null);
const actionMessage = ref('');
const actionNotice = ref<HTMLElement | null>(null);
const actionRequiresReload = ref(false);
const reloadingAction = ref(false);
const showAllSlots = ref(false);
const historyOpen = ref(false);
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

const directoryLink = computed(() =>
    plansIndex({ query: props.directory_context }),
);
watchEffect(() => {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Plans', href: directoryLink.value },
            { title: 'Plan', href: '#' },
        ],
    });
});

const currentQuery = computed(
    () => new URL(page.url, 'http://localhost').searchParams,
);
const openFeeHistory = computed(
    () =>
        currentQuery.value.has('fee_page') ||
        currentQuery.value.has('fee_per_page'),
);
const openPostingHistory = computed(
    () =>
        currentQuery.value.has('activity_page') ||
        currentQuery.value.has('activity_per_page'),
);

const visibleSlots = computed(() =>
    showAllSlots.value ? props.plan.slots : props.plan.slots.slice(0, 10),
);
const remainingSlots = computed(() =>
    Math.max(props.plan.slots.length - visibleSlots.value.length, 0),
);
const recentHistory = computed(() => props.plan.history.slice(-3).reverse());
const fullHistory = computed(() => [...props.plan.history].reverse());
const fullRevisions = computed(() => [...props.plan.revisions].reverse());
const confirmationTitle = computed(() => {
    if (confirmationAction.value === 'pause') return 'Pause this plan?';
    if (confirmationAction.value === 'resume') return 'Resume this plan?';
    return 'Cancel this plan?';
});
const canRecordCash = computed(
    () =>
        page.props.features.collections &&
        props.actions.can_manage &&
        props.plan.status === 'active',
);
const showCancel = computed(
    () =>
        props.actions.can_manage &&
        ['active', 'paused'].includes(props.plan.status),
);
const hasMoreActions = computed(
    () =>
        props.actions.can_edit ||
        props.actions.can_pause ||
        props.actions.can_settle ||
        showCancel.value,
);

const keyFigures = computed(() => [
    {
        label: 'Daily amount',
        value:
            props.plan.current_terms?.formatted_contribution_amount ??
            'Not set',
    },
    {
        label: 'Paid so far',
        value: props.plan.financial_summary.funded_principal ?? 'Not available',
    },
    {
        label: 'Days paid',
        value:
            props.plan.financial_summary.fully_funded_slots === null
                ? 'Not available'
                : `${props.plan.financial_summary.fully_funded_slots} of ${props.plan.financial_summary.required_slots}`,
    },
    {
        label: 'Available savings',
        value: props.plan.savings_summary.cycle.available ?? 'Not available',
    },
]);

watch(
    () => [
        props.plan.version,
        props.customer.version,
        props.customer.assignment_version,
        props.attempt_reference,
    ],
    () => {
        transitionForm.plan_version = props.plan.version;
        transitionForm.customer_version = props.customer.version;
        transitionForm.assignment_version =
            props.customer.assignment_version ?? 0;
        transitionForm.attempt_reference = props.attempt_reference;
    },
);

const openAction = (action: LifecycleAction): void => {
    if (
        transitionForm.processing ||
        reloadingAction.value ||
        actionRequiresReload.value
    )
        return;
    confirmationAction.value = action;
    actionMessage.value = '';
    transitionForm.clearErrors();
};

const focusActionNotice = (): void => {
    void nextTick(() => actionNotice.value?.focus());
};

const reloadAction = (): void => {
    if (transitionForm.processing || reloadingAction.value) return;
    reloadingAction.value = true;
    router.reload({
        only: ['plan', 'customer', 'actions', 'attempt_reference'],
        onSuccess: (currentPage) => {
            const currentPlan = currentPage.props.plan as
                | { id?: string }
                | undefined;
            if (
                currentPage.component !== 'plans/Show' ||
                currentPlan?.id !== props.plan.id
            )
                return;
            actionRequiresReload.value = false;
            actionMessage.value = '';
            transitionForm.clearErrors();
            transitionForm.reset('reason', 'customer_explanation');
            confirmationAction.value = null;
        },
        onError: () => {
            actionMessage.value =
                'We could not load the latest plan. Try reloading again.';
        },
        onHttpException: (response) => {
            actionMessage.value =
                response.status === 403 || response.status === 404
                    ? 'This plan is not available, or your access has changed. We could not confirm your last action.'
                    : 'We could not load the latest plan. Try reloading again.';
            return false;
        },
        onNetworkError: () => {
            actionMessage.value =
                'The connection failed while loading the plan. Try reloading again.';
            return false;
        },
        onFinish: () => {
            reloadingAction.value = false;
            focusActionNotice();
        },
    });
};

const submitAction = (): void => {
    const action = confirmationAction.value;
    if (
        !action ||
        transitionForm.processing ||
        reloadingAction.value ||
        actionRequiresReload.value
    )
        return;
    actionMessage.value = '';
    const path =
        action === 'pause'
            ? pausePlan(props.plan.id).url
            : action === 'resume'
              ? resumePlan(props.plan.id).url
              : cancelPlan(props.plan.id).url;

    transitionForm
        .transform((data) => ({
            ...data,
            attempt_reference: props.attempt_reference,
            plan_version: props.plan.version,
            customer_version: props.customer.version,
            assignment_version: props.customer.assignment_version ?? 0,
        }))
        .post(path, {
            preserveScroll: true,
            onHttpException: (response) => {
                actionRequiresReload.value = true;
                actionMessage.value =
                    response.status === 409
                        ? 'This plan or customer changed, or this action is no longer available. Reload the plan and check it before trying again.'
                        : response.status === 403 || response.status === 404
                          ? 'This action was blocked because the plan is not available or your access changed. Reload to check.'
                          : 'We could not confirm if this worked. Reload the plan to check its status.';
                return false;
            },
            onNetworkError: () => {
                actionRequiresReload.value = true;
                actionMessage.value =
                    'The connection failed, so we could not confirm if this was saved. Reload the plan to check.';
                return false;
            },
            onSuccess: () => {
                confirmationAction.value = null;
                transitionForm.reset('reason', 'customer_explanation');
            },
            onFinish: focusActionNotice,
        });
};

const statusVariant = (
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' => {
    if (status === 'active') return 'default';
    if (status === 'paused') return 'secondary';
    if (status === 'cancelled') return 'destructive';
    return 'outline';
};
</script>

<template>
    <Head
        :title="`${plan.current_terms?.name ?? 'Thrift plan'} · ${plan.id}`"
    />

    <div class="space-y-6">
        <PageHeader
            :title="plan.current_terms?.name ?? 'Daily thrift plan'"
            :description="`Savings plan for ${customer.name}.`"
        >
            <template #actions>
                <Button
                    v-if="page.props.features.collections"
                    as-child
                    variant="outline"
                    ><Link :href="planCard(plan.id).url"
                        >View thrift card</Link
                    ></Button
                >
                <Button v-if="canRecordCash" as-child
                    ><Link :href="createCollection(customer.id).url"
                        >Record cash</Link
                    ></Button
                >
                <Button v-if="actions.can_resume" @click="openAction('resume')"
                    >Resume</Button
                >
                <Button v-if="actions.can_renew" as-child
                    ><Link
                        :href="
                            createCustomerPlan(customer.id, {
                                query: { predecessor_plan_id: plan.id },
                            }).url
                        "
                        >Start new plan</Link
                    ></Button
                >
                <DropdownMenu :modal="false" v-if="hasMoreActions">
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline" aria-label="More actions">
                            <Ellipsis class="size-4" />
                            More
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-64">
                        <DropdownMenuItem v-if="actions.can_edit" as-child>
                            <Link :href="editPlan(plan.id).url"
                                ><PencilLine class="size-4" />Change terms</Link
                            >
                        </DropdownMenuItem>
                        <DropdownMenuItem v-if="actions.can_settle" as-child>
                            <Link :href="planSettlement.url(plan.id)"
                                >Review settlement</Link
                            >
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-if="actions.can_pause"
                            @select="openAction('pause')"
                            >Pause plan</DropdownMenuItem
                        >
                        <template v-if="showCancel">
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                variant="destructive"
                                :disabled="!actions.can_cancel"
                                :aria-describedby="
                                    actions.can_cancel
                                        ? undefined
                                        : 'plan-cancellation-blocker'
                                "
                                @select="openAction('cancel')"
                                >Cancel plan</DropdownMenuItem
                            >
                            <p
                                v-if="!actions.can_cancel"
                                id="plan-cancellation-blocker"
                                class="text-muted-foreground px-3 pb-2 text-xs"
                            >
                                This plan has payments or fees, so it can't be
                                cancelled. Use settlement instead.
                            </p>
                        </template>
                    </DropdownMenuContent>
                </DropdownMenu>
            </template>
        </PageHeader>

        <Card>
            <CardHeader
                class="flex flex-row flex-wrap items-center justify-between gap-3"
            >
                <CardTitle>Summary</CardTitle>
                <Badge :variant="statusVariant(plan.status)">{{
                    plan.status_label
                }}</Badge>
            </CardHeader>
            <CardContent class="space-y-5">
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <div
                        v-for="figure in keyFigures"
                        :key="figure.label"
                        class="bg-muted/40 rounded-xl p-4"
                    >
                        <p class="text-muted-foreground text-sm">
                            {{ figure.label }}
                        </p>
                        <p class="mt-2 text-xl font-semibold break-words">
                            {{ figure.value }}
                        </p>
                    </div>
                </div>

                <dl
                    v-if="plan.current_terms"
                    class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4"
                >
                    <div>
                        <dt class="text-muted-foreground">Customer</dt>
                        <dd class="mt-0.5 font-medium">
                            <Link
                                :href="showCustomer(customer.id).url"
                                class="underline-offset-4 hover:underline"
                                >{{ customer.name }}</Link
                            >
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Dates</dt>
                        <dd class="mt-0.5 font-medium">
                            {{ plan.current_terms.start_date }} to
                            {{ plan.current_terms.scheduled_end_date }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Expected total</dt>
                        <dd class="mt-0.5 font-medium">
                            {{ plan.current_terms.formatted_expected_gross }}
                            <span
                                class="text-muted-foreground block text-xs font-normal"
                                >Over
                                {{ plan.current_terms.contribution_days }}
                                days</span
                            >
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Fee</dt>
                        <dd class="mt-0.5 font-medium">
                            <template v-if="plan.fee"
                                >{{ plan.fee.formatted_amount }}
                                <span
                                    class="text-muted-foreground block text-xs font-normal"
                                    >{{ plan.fee.name }}</span
                                ></template
                            >
                            <template v-else>No fee</template>
                        </dd>
                    </div>
                </dl>
                <p v-else class="text-muted-foreground text-sm">
                    The plan terms can't be shown right now.
                </p>

                <div
                    v-if="plan.current_terms?.customer_visible_notes"
                    class="bg-muted/40 rounded-xl p-4 text-sm"
                >
                    <p class="text-muted-foreground text-xs">
                        Notes for the customer
                    </p>
                    <p class="mt-1 whitespace-pre-wrap">
                        {{ plan.current_terms.customer_visible_notes }}
                    </p>
                </div>

                <MoreDetails label="Plan details">
                    <div class="space-y-6">
                        <dl
                            class="text-muted-foreground grid gap-3 text-xs sm:grid-cols-2 lg:grid-cols-4"
                        >
                            <div>
                                <dt>Plan ID</dt>
                                <dd class="text-foreground mt-0.5 break-all">
                                    {{ plan.id }}
                                </dd>
                            </div>
                            <div>
                                <dt>Customer ID</dt>
                                <dd class="text-foreground mt-0.5 break-all">
                                    {{ customer.id }}
                                </dd>
                            </div>
                            <div>
                                <dt>Terms version</dt>
                                <dd class="text-foreground mt-0.5">
                                    {{ plan.terms_revision }}
                                </dd>
                            </div>
                            <div>
                                <dt>Created</dt>
                                <dd class="text-foreground mt-0.5">
                                    {{ plan.created_at ?? 'Unknown' }}
                                </dd>
                            </div>
                            <div v-if="plan.current_terms">
                                <dt>Time zone</dt>
                                <dd class="text-foreground mt-0.5">
                                    {{ plan.current_terms.timezone }}
                                </dd>
                            </div>
                        </dl>
                        <section class="space-y-3">
                            <h3 class="text-sm font-medium">
                                Payment progress
                            </h3>
                            <PlanFundingSummary
                                :summary="plan.financial_summary"
                            />
                        </section>
                        <section class="space-y-3">
                            <h3 class="text-sm font-medium">Estimate</h3>
                            <PlanEstimateSummary :estimate="plan.estimate" />
                        </section>
                        <section v-if="plan.fee" class="space-y-2 text-sm">
                            <h3 class="font-medium">
                                Fee: {{ plan.fee.name }}
                            </h3>
                            <p class="text-muted-foreground">
                                {{ plan.fee.description }}
                            </p>
                            <p
                                v-if="plan.fee.early_termination_description"
                                class="text-muted-foreground"
                            >
                                If the plan ends early:
                                {{ plan.fee.early_termination_description }}
                            </p>
                            <p class="text-muted-foreground text-xs">
                                {{
                                    plan.fee.estimate_available
                                        ? 'The final fee is worked out when money is paid out.'
                                        : 'The fee is worked out when a withdrawal is requested.'
                                }}
                            </p>
                        </section>
                    </div>
                </MoreDetails>
            </CardContent>
        </Card>

        <Card>
            <CardHeader class="flex flex-row items-center gap-2">
                <CalendarDays class="text-muted-foreground size-4" />
                <CardTitle>Payment schedule</CardTitle>
            </CardHeader>
            <CardContent>
                <div
                    v-if="plan.slots.length"
                    class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <div
                        v-for="slot in visibleSlots"
                        :key="slot.ordinal"
                        class="bg-muted/40 flex items-start justify-between gap-3 rounded-xl px-3 py-2.5"
                    >
                        <div>
                            <p class="text-sm font-medium">
                                Day {{ slot.ordinal }}
                            </p>
                            <p class="text-muted-foreground text-xs">
                                {{ slot.due_date }}
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-medium">
                                {{ slot.formatted_expected_amount }}
                            </p>
                            <p
                                v-if="slot.formatted_funded_amount !== null"
                                class="text-muted-foreground text-xs"
                            >
                                Paid {{ slot.formatted_funded_amount }} · Left
                                {{ slot.formatted_remaining_amount }}
                            </p>
                            <p class="text-muted-foreground text-xs capitalize">
                                {{ slot.collection_status }}
                                <span v-if="slot.advance"> · Paid early</span>
                            </p>
                        </div>
                    </div>
                </div>
                <EmptyState
                    v-else
                    :icon="CalendarDays"
                    title="No payment days yet"
                    description="Payment days will show here once the plan has a schedule."
                />
                <Button
                    v-if="remainingSlots > 0 || showAllSlots"
                    class="mt-4"
                    variant="outline"
                    size="sm"
                    @click="showAllSlots = !showAllSlots"
                >
                    {{
                        showAllSlots
                            ? 'Show fewer days'
                            : `Show all ${plan.slots.length} days`
                    }}
                </Button>
            </CardContent>
        </Card>

        <Card>
            <CardHeader><CardTitle>Money details</CardTitle></CardHeader>
            <CardContent class="divide-y">
                <div class="pb-4">
                    <MoreDetails label="Savings breakdown">
                        <PlanSavingsSummary :summary="plan.savings_summary" />
                    </MoreDetails>
                </div>
                <div class="py-4">
                    <MoreDetails
                        label="Payouts and deductions"
                        :default-open="openPostingHistory"
                    >
                        <div class="space-y-6">
                            <PlanPostedActivity
                                :summary="plan.posted_activity"
                            />
                            <PlanPostingHistory
                                :plan-id="plan.id"
                                :summary="plan.posting_history"
                            />
                        </div>
                    </MoreDetails>
                </div>
                <div class="pt-4">
                    <MoreDetails label="Fees" :default-open="openFeeHistory">
                        <PlanFeeHistory
                            :plan-id="plan.id"
                            :summary="plan.fee_history"
                        />
                    </MoreDetails>
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardHeader
                class="flex flex-row flex-wrap items-center justify-between gap-3"
            >
                <CardTitle class="flex items-center gap-2"
                    ><History class="text-muted-foreground size-4" /> Recent
                    activity</CardTitle
                >
                <Button variant="ghost" size="sm" @click="historyOpen = true"
                    >See full history</Button
                >
            </CardHeader>
            <CardContent>
                <ol v-if="recentHistory.length" class="divide-y">
                    <li
                        v-for="(event, index) in recentHistory"
                        :key="`${event.event}-${index}`"
                        class="flex flex-wrap items-start justify-between gap-2 py-3 first:pt-0 last:pb-0"
                    >
                        <div class="min-w-0">
                            <p class="text-sm font-medium">{{ event.event }}</p>
                            <p
                                v-if="event.explanation"
                                class="text-muted-foreground mt-0.5 text-sm"
                            >
                                {{ event.explanation }}
                            </p>
                        </div>
                        <p class="text-muted-foreground text-xs">
                            {{ event.effective_at ?? 'Date unknown' }}
                        </p>
                    </li>
                </ol>
                <p v-else class="text-muted-foreground text-sm">
                    Nothing has happened on this plan yet.
                </p>
            </CardContent>
        </Card>

        <div class="flex justify-start">
            <Button as-child variant="ghost"
                ><Link :href="directoryLink"
                    ><ArrowLeft class="mr-2 size-4" />Back to plans</Link
                ></Button
            >
        </div>
    </div>

    <FormSheet
        v-model:open="historyOpen"
        title="Plan history"
        description="Everything that has changed on this plan."
    >
        <div class="space-y-8">
            <section class="space-y-3">
                <h3 class="text-sm font-medium">Activity</h3>
                <ol v-if="fullHistory.length" class="divide-y">
                    <li
                        v-for="(event, index) in fullHistory"
                        :key="`${event.event}-${index}`"
                        class="space-y-1 py-3 text-sm first:pt-0"
                    >
                        <p class="font-medium">
                            {{ event.event
                            }}<span v-if="event.status">
                                · {{ event.status }}</span
                            >
                        </p>
                        <p v-if="event.explanation">
                            {{ event.explanation }}
                        </p>
                        <p
                            v-if="event.reason"
                            class="text-muted-foreground text-xs"
                        >
                            Reason: {{ event.reason }}
                        </p>
                        <p class="text-muted-foreground text-xs">
                            {{ event.effective_at ?? 'Date unknown'
                            }}<template v-if="event.actor">
                                · By {{ event.actor }}</template
                            >
                        </p>
                    </li>
                </ol>
                <p v-else class="text-muted-foreground text-sm">
                    Nothing has happened on this plan yet.
                </p>
            </section>
            <section class="space-y-3">
                <h3 class="text-sm font-medium">Changes to terms</h3>
                <ol class="divide-y">
                    <li
                        v-for="revision in fullRevisions"
                        :key="revision.revision"
                        class="space-y-1 py-3 text-sm first:pt-0"
                    >
                        <p class="font-medium">
                            Version {{ revision.revision }} ·
                            {{ revision.name }}
                        </p>
                        <p class="text-muted-foreground">
                            {{ revision.formatted_contribution_amount }} daily
                            for {{ revision.contribution_days }} days from
                            {{ revision.start_date }}
                        </p>
                        <p
                            v-if="revision.reason"
                            class="text-muted-foreground text-xs"
                        >
                            Reason: {{ revision.reason }}
                        </p>
                        <p class="text-muted-foreground text-xs">
                            {{ revision.created_at ?? 'Date unknown' }}
                        </p>
                    </li>
                </ol>
            </section>
        </div>
    </FormSheet>

    <Dialog
        :open="confirmationAction !== null"
        @update:open="
            (open) => {
                if (
                    !open &&
                    !transitionForm.processing &&
                    !reloadingAction &&
                    !actionRequiresReload
                )
                    confirmationAction = null;
            }
        "
    >
        <DialogContent
            :show-close-button="
                !transitionForm.processing &&
                !reloadingAction &&
                !actionRequiresReload
            "
            @escape-key-down="
                (event) => {
                    if (
                        transitionForm.processing ||
                        reloadingAction ||
                        actionRequiresReload
                    )
                        event.preventDefault();
                }
            "
            @interact-outside="
                (event) => {
                    if (
                        transitionForm.processing ||
                        reloadingAction ||
                        actionRequiresReload
                    )
                        event.preventDefault();
                }
            "
        >
            <DialogHeader>
                <DialogTitle>{{ confirmationTitle }}</DialogTitle>
                <DialogDescription>
                    <template v-if="confirmationAction === 'cancel'"
                        >You can only cancel a plan with no payments and no
                        fees. We check this again before cancelling.</template
                    >
                    <template v-else-if="confirmationAction === 'pause'"
                        >Pausing does not move any money. The schedule stays in
                        the plan history.</template
                    >
                    <template v-else
                        >The plan becomes active again only if the customer is
                        active.</template
                    >
                </DialogDescription>
            </DialogHeader>
            <div class="grid gap-4">
                <p
                    v-if="actionMessage"
                    id="plan-action-notice"
                    ref="actionNotice"
                    role="alert"
                    tabindex="-1"
                    class="text-destructive border-destructive/30 focus-visible:ring-ring rounded-xl border p-3 text-sm outline-none focus-visible:ring-2"
                >
                    {{ actionMessage }}
                </p>
                <div class="grid gap-2">
                    <Label for="plan-action-reason">Reason</Label>
                    <textarea
                        id="plan-action-reason"
                        v-model="transitionForm.reason"
                        :disabled="
                            transitionForm.processing ||
                            reloadingAction ||
                            actionRequiresReload
                        "
                        :aria-invalid="!!transitionForm.errors.reason"
                        :aria-describedby="
                            transitionForm.errors.reason
                                ? 'plan-action-reason-error'
                                : undefined
                        "
                        rows="2"
                        maxlength="500"
                        class="border-input bg-background focus-visible:ring-ring/30 min-h-20 w-full rounded-xl border px-3 py-2 text-sm shadow-sm outline-none focus-visible:ring-2"
                    />
                    <p
                        v-if="transitionForm.errors.reason"
                        id="plan-action-reason-error"
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        {{ transitionForm.errors.reason }}
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="plan-action-explanation"
                        >Message for the customer</Label
                    >
                    <textarea
                        id="plan-action-explanation"
                        v-model="transitionForm.customer_explanation"
                        :disabled="
                            transitionForm.processing ||
                            reloadingAction ||
                            actionRequiresReload
                        "
                        :aria-invalid="
                            !!transitionForm.errors.customer_explanation
                        "
                        :aria-describedby="
                            transitionForm.errors.customer_explanation
                                ? 'plan-action-explanation-error'
                                : undefined
                        "
                        rows="2"
                        maxlength="500"
                        class="border-input bg-background focus-visible:ring-ring/30 min-h-20 w-full rounded-xl border px-3 py-2 text-sm shadow-sm outline-none focus-visible:ring-2"
                    />
                    <p
                        v-if="transitionForm.errors.customer_explanation"
                        id="plan-action-explanation-error"
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        {{ transitionForm.errors.customer_explanation }}
                    </p>
                </div>
                <p
                    v-if="transitionForm.errors.plan_version"
                    id="plan-action-version-error"
                    role="alert"
                    class="text-destructive text-sm"
                >
                    {{ transitionForm.errors.plan_version }}
                </p>
            </div>
            <DialogFooter>
                <Button
                    v-if="!actionRequiresReload"
                    variant="outline"
                    :disabled="transitionForm.processing || reloadingAction"
                    @click="confirmationAction = null"
                    >Go back</Button
                >
                <Button
                    v-if="actionRequiresReload"
                    variant="outline"
                    :disabled="transitionForm.processing || reloadingAction"
                    aria-describedby="plan-action-notice"
                    @click="reloadAction"
                    >{{
                        reloadingAction ? 'Reloading…' : 'Reload plan'
                    }}</Button
                >
                <Button
                    :variant="
                        confirmationAction === 'cancel'
                            ? 'destructive'
                            : 'default'
                    "
                    :disabled="
                        transitionForm.processing ||
                        reloadingAction ||
                        actionRequiresReload ||
                        transitionForm.reason.trim().length < 3 ||
                        transitionForm.customer_explanation.trim().length < 3
                    "
                    :aria-describedby="
                        transitionForm.errors.plan_version
                            ? 'plan-action-version-error'
                            : actionMessage
                              ? 'plan-action-notice'
                              : undefined
                    "
                    @click="submitAction"
                >
                    {{ transitionForm.processing ? 'Saving…' : 'Confirm' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
