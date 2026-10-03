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
    AlertCircle,
    ArrowLeft,
    CalendarDays,
    History,
    PencilLine,
    WalletCards,
} from '@lucide/vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import PlanFundingSummary from '@/components/PlanFundingSummary.vue';
import type { PlanFundingSummary as FundingSummary } from '@/types/plan-funding';
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

const visibleSlots = computed(() =>
    showAllSlots.value ? props.plan.slots : props.plan.slots.slice(0, 10),
);
const remainingSlots = computed(() =>
    Math.max(props.plan.slots.length - visibleSlots.value.length, 0),
);
const confirmationTitle = computed(() => {
    if (confirmationAction.value === 'pause') return 'Pause this plan?';
    if (confirmationAction.value === 'resume') return 'Resume this plan?';
    return 'Cancel this unused plan?';
});

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
                'The current plan could not be verified. Reload again before confirming another action.';
        },
        onHttpException: (response) => {
            actionMessage.value =
                response.status === 403 || response.status === 404
                    ? 'The current plan is unavailable or your access has changed. Your previous action remains unverified.'
                    : 'The current plan could not be verified. Reload again before confirming another action.';
            return false;
        },
        onNetworkError: () => {
            actionMessage.value =
                'The current plan could not be verified because the connection failed. Reload again before confirming another action.';
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
                        ? 'The plan or Customer changed, or this action is no longer available. Reload the current plan and review it before confirming another action.'
                        : response.status === 403 || response.status === 404
                          ? 'This action was rejected because the plan is unavailable or your access has changed. Reload to check your current access before confirming another action.'
                          : 'The action outcome could not be confirmed. Reload the current plan to check its status before confirming another action.';
                return false;
            },
            onNetworkError: () => {
                actionRequiresReload.value = true;
                actionMessage.value =
                    'The action outcome could not be confirmed because the connection failed. Reload the current plan to check whether it was saved before confirming another action.';
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
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-[25px] font-medium tracking-tight">
                    {{ plan.current_terms?.name ?? 'Daily thrift plan' }}
                </h1>
                <p class="text-muted-foreground mt-1.5 text-sm">
                    <Link
                        :href="showCustomer(customer.id).url"
                        class="hover:underline"
                        >{{ customer.name }} · {{ customer.id }}</Link
                    >
                    <span>
                        · {{ plan.id }} · revision
                        {{ plan.terms_revision }}</span
                    >
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="page.props.features.collections"
                    as-child
                    variant="outline"
                    ><Link :href="planCard(plan.id).url"
                        >View thrift card</Link
                    ></Button
                >
                <Button
                    v-if="
                        page.props.features.collections &&
                        actions.can_manage &&
                        plan.status === 'active'
                    "
                    as-child
                    ><Link :href="createCollection(customer.id).url"
                        >Record cash</Link
                    ></Button
                >
                <Button v-if="actions.can_edit" as-child variant="outline"
                    ><Link :href="editPlan(plan.id).url"
                        ><PencilLine class="mr-2 size-4" />Amend terms</Link
                    ></Button
                >
                <Button
                    v-if="actions.can_pause"
                    variant="outline"
                    @click="openAction('pause')"
                    >Pause</Button
                >
                <Button v-if="actions.can_resume" @click="openAction('resume')"
                    >Resume</Button
                >
                <Button v-if="actions.can_settle" as-child
                    ><Link :href="planSettlement.url(plan.id)"
                        >Review settlement</Link
                    ></Button
                >
                <Button
                    v-if="
                        actions.can_manage &&
                        ['active', 'paused'].includes(plan.status)
                    "
                    variant="destructive"
                    :disabled="!actions.can_cancel"
                    :aria-describedby="
                        actions.can_cancel
                            ? undefined
                            : 'plan-cancellation-blocker'
                    "
                    @click="openAction('cancel')"
                    >Cancel unused plan</Button
                >
                <Button v-if="actions.can_renew" as-child
                    ><Link
                        :href="
                            createCustomerPlan(customer.id, {
                                query: { predecessor_plan_id: plan.id },
                            }).url
                        "
                        >Create renewal</Link
                    ></Button
                >
            </div>
        </div>

        <p
            v-if="
                actions.can_manage &&
                ['active', 'paused'].includes(plan.status) &&
                !actions.can_cancel
            "
            id="plan-cancellation-blocker"
            class="text-muted-foreground text-sm"
        >
            This cycle has fee or financial activity and cannot use unused
            cancellation. Review its recorded fees and payments before choosing
            a settlement action.
        </p>

        <div
            class="grid gap-4 lg:grid-cols-[minmax(0,1.6fr)_minmax(280px,0.9fr)]"
        >
            <Card>
                <CardHeader class="flex-row items-start justify-between">
                    <div>
                        <CardTitle>Agreed terms</CardTitle>
                        <CardDescription
                            >Revision {{ plan.terms_revision }} · created
                            {{
                                plan.created_at ?? 'date unavailable'
                            }}</CardDescription
                        >
                    </div>
                    <Badge :variant="statusVariant(plan.status)">{{
                        plan.status_label
                    }}</Badge>
                </CardHeader>
                <CardContent
                    v-if="plan.current_terms"
                    class="grid gap-5 sm:grid-cols-2"
                >
                    <div>
                        <p class="text-muted-foreground text-xs">
                            Daily contribution
                        </p>
                        <p class="mt-1 text-lg font-semibold">
                            {{
                                plan.current_terms.formatted_contribution_amount
                            }}
                        </p>
                    </div>
                    <div>
                        <p class="text-muted-foreground text-xs">Frequency</p>
                        <p class="mt-1 font-medium">
                            Daily ·
                            {{ plan.current_terms.contribution_days }} scheduled
                            days
                        </p>
                    </div>
                    <div>
                        <p class="text-muted-foreground text-xs">Schedule</p>
                        <p class="mt-1 font-medium">
                            {{ plan.current_terms.start_date }} –
                            {{ plan.current_terms.scheduled_end_date }}
                        </p>
                    </div>
                    <div>
                        <p class="text-muted-foreground text-xs">Timezone</p>
                        <p class="mt-1 font-medium">
                            {{ plan.current_terms.timezone }}
                        </p>
                    </div>
                    <div>
                        <p class="text-muted-foreground text-xs">
                            Expected gross
                        </p>
                        <p class="mt-1 font-medium">
                            {{ plan.current_terms.formatted_expected_gross }}
                        </p>
                        <p class="text-muted-foreground mt-1 text-xs">
                            Contractual estimate only
                        </p>
                    </div>
                    <div
                        v-if="plan.current_terms.customer_visible_notes"
                        class="sm:col-span-2"
                    >
                        <p class="text-muted-foreground text-xs">
                            Customer-visible notes
                        </p>
                        <p class="mt-1 text-sm whitespace-pre-wrap">
                            {{ plan.current_terms.customer_visible_notes }}
                        </p>
                    </div>
                </CardContent>
                <CardContent v-else
                    ><p class="text-muted-foreground text-sm">
                        Agreed terms are unavailable.
                    </p></CardContent
                >
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2"
                        ><WalletCards class="size-4" /> Plan funding</CardTitle
                    >
                    <CardDescription
                        >Recorded allocation coverage of the agreed
                        schedule.</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <PlanFundingSummary :summary="plan.financial_summary" />
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader
                ><CardTitle>Contractual estimates</CardTitle></CardHeader
            >
            <CardContent
                ><PlanEstimateSummary :estimate="plan.estimate"
            /></CardContent>
        </Card>

        <Card>
            <CardHeader
                ><CardTitle
                    >Actual savings and reservations</CardTitle
                ></CardHeader
            >
            <CardContent
                ><PlanSavingsSummary :summary="plan.savings_summary"
            /></CardContent>
        </Card>

        <Card>
            <CardHeader
                ><CardTitle
                    >Posted payouts and deductions</CardTitle
                ></CardHeader
            >
            <CardContent class="space-y-6">
                <PlanPostedActivity :summary="plan.posted_activity" />
                <PlanPostingHistory
                    :plan-id="plan.id"
                    :summary="plan.posting_history"
                />
            </CardContent>
        </Card>

        <Card>
            <CardHeader><CardTitle>Recorded cycle fees</CardTitle></CardHeader>
            <CardContent
                ><PlanFeeHistory :plan-id="plan.id" :summary="plan.fee_history"
            /></CardContent>
        </Card>

        <Card v-if="plan.fee">
            <CardHeader>
                <CardTitle>Agreed fee terms</CardTitle>
                <CardDescription>{{ plan.fee.name }}</CardDescription>
            </CardHeader>
            <CardContent class="space-y-2">
                <p class="font-medium">{{ plan.fee.formatted_amount }}</p>
                <p class="text-muted-foreground text-sm">
                    {{ plan.fee.description }}
                </p>
                <p
                    v-if="plan.fee.early_termination_description"
                    class="text-muted-foreground text-sm"
                >
                    Early termination:
                    {{ plan.fee.early_termination_description }}
                </p>
                <p
                    v-if="plan.fee.estimate_available"
                    class="text-muted-foreground text-xs"
                >
                    The fee snapshot is contractual; any assessment waits for
                    the financial workflow that owns it.
                </p>
                <p v-else class="text-muted-foreground text-xs">
                    The amount is calculated when a withdrawal is quoted.
                </p>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <div class="flex items-start gap-3">
                    <CalendarDays class="text-primary mt-0.5 size-5" />
                    <div>
                        <CardTitle>Expected contribution dates</CardTitle
                        ><CardDescription
                            >Agreed dates and verified allocated contributions.
                            Unavailable funding retains the agreed
                            target.</CardDescription
                        >
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <div
                    v-if="plan.slots.length"
                    class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <div
                        v-for="slot in visibleSlots"
                        :key="slot.ordinal"
                        class="flex items-center justify-between rounded-xl border px-3 py-2.5"
                    >
                        <div>
                            <p class="text-xs font-medium">
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
                            <p class="text-muted-foreground text-[11px]">
                                Target
                            </p>
                            <template
                                v-if="slot.formatted_funded_amount !== null"
                            >
                                <p class="mt-1 text-xs">
                                    Allocated {{ slot.formatted_funded_amount }}
                                </p>
                                <p class="text-muted-foreground text-xs">
                                    Remaining
                                    {{ slot.formatted_remaining_amount }}
                                </p>
                            </template>
                            <p
                                class="text-muted-foreground mt-1 text-xs capitalize"
                            >
                                {{ slot.collection_status }}
                                <span v-if="slot.advance"> · Advance</span>
                            </p>
                        </div>
                    </div>
                </div>
                <p v-else class="text-muted-foreground text-sm">
                    No schedule slots are available.
                </p>
                <Button
                    v-if="remainingSlots > 0 || showAllSlots"
                    class="mt-4"
                    variant="outline"
                    size="sm"
                    @click="showAllSlots = !showAllSlots"
                >
                    {{
                        showAllSlots
                            ? 'Show fewer dates'
                            : `Show all ${plan.slots.length} dates`
                    }}
                </Button>
            </CardContent>
        </Card>

        <div class="grid gap-4 lg:grid-cols-2">
            <Card>
                <CardHeader
                    ><CardTitle>Revision history</CardTitle
                    ><CardDescription
                        >Each accepted version of the plan terms is
                        retained.</CardDescription
                    ></CardHeader
                >
                <CardContent>
                    <ol class="space-y-4">
                        <li
                            v-for="revision in plan.revisions"
                            :key="revision.revision"
                            class="border-l-2 pl-4"
                        >
                            <p class="font-medium">
                                Revision {{ revision.revision }} ·
                                {{ revision.name }}
                            </p>
                            <p class="text-muted-foreground mt-1 text-sm">
                                {{ revision.formatted_contribution_amount }}
                                daily · {{ revision.contribution_days }} days
                                from {{ revision.start_date }}
                            </p>
                            <p
                                v-if="revision.reason"
                                class="text-muted-foreground mt-1 text-xs"
                            >
                                Reason: {{ revision.reason }}
                            </p>
                            <p class="text-muted-foreground mt-1 text-xs">
                                {{ revision.created_at ?? 'Date unavailable' }}
                            </p>
                        </li>
                    </ol>
                </CardContent>
            </Card>
            <Card>
                <CardHeader
                    ><CardTitle class="flex items-center gap-2"
                        ><History class="size-4" /> Plan history</CardTitle
                    ><CardDescription
                        >Lifecycle actions are recorded with their
                        reasons.</CardDescription
                    ></CardHeader
                >
                <CardContent>
                    <ol v-if="plan.history.length" class="space-y-4">
                        <li
                            v-for="(event, index) in plan.history"
                            :key="`${event.event}-${index}`"
                            class="border-l-2 pl-4"
                        >
                            <p class="font-medium">
                                {{ event.event
                                }}<span v-if="event.status">
                                    · {{ event.status }}</span
                                >
                            </p>
                            <p v-if="event.explanation" class="mt-1 text-sm">
                                {{ event.explanation }}
                            </p>
                            <p
                                v-if="event.reason"
                                class="text-muted-foreground mt-1 text-xs"
                            >
                                Reason: {{ event.reason }}
                            </p>
                            <p
                                v-if="event.actor"
                                class="text-muted-foreground mt-1 text-xs"
                            >
                                Recorded by {{ event.actor }}
                            </p>
                            <p class="text-muted-foreground mt-1 text-xs">
                                {{ event.effective_at ?? 'Date unavailable' }}
                            </p>
                        </li>
                    </ol>
                    <p v-else class="text-muted-foreground text-sm">
                        No lifecycle events are available.
                    </p>
                </CardContent>
            </Card>
        </div>

        <div class="flex justify-start">
            <Button as-child variant="ghost"
                ><Link :href="directoryLink"
                    ><ArrowLeft class="mr-2 size-4" />Back to plans</Link
                ></Button
            >
        </div>
    </div>

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
                        >Cancellation is available only for a plan with no
                        recorded activity and no fee obligation. The server
                        rechecks both conditions.</template
                    >
                    <template v-else-if="confirmationAction === 'pause'"
                        >The agreed schedule remains in history. No financial
                        activity is recorded by this action.</template
                    >
                    <template v-else
                        >The plan returns to Active only if the Customer is
                        currently Active.</template
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
                        >Explanation for Customer</Label
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
                    >Keep current status</Button
                >
                <Button
                    v-if="actionRequiresReload"
                    variant="outline"
                    :disabled="transitionForm.processing || reloadingAction"
                    aria-describedby="plan-action-notice"
                    @click="reloadAction"
                    >{{
                        reloadingAction ? 'Reloading…' : 'Reload current plan'
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
                    {{
                        transitionForm.processing ? 'Saving…' : 'Confirm action'
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
