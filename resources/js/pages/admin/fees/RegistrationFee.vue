<script setup lang="ts">
import { Head, useForm, useHttp } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import type { AcceptableValue } from 'reka-ui';
import { Coins, Loader2, MoreHorizontal, Plus, ShieldAlert } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import MoreDetails from '@/components/MoreDetails.vue';
import PageHeader from '@/components/PageHeader.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { dashboard } from '@/routes';
import {
    index as feesRegistrationIndex,
    store as feesRegistrationStore,
    retire as retireFeeRule,
    retirementPreview as previewFeeRuleRetirement,
    preview as previewFeeRulePublication,
} from '@/routes/admin/fees/registration';

export type FeeRuleItem = {
    id: number;
    version: number;
    name: string;
    model: string;
    model_label: string;
    kind?: string;
    rule_key?: string;
    timing?: string;
    basis?: string;
    basis_points?: number | null;
    amount_kobo: number;
    formatted_amount: string;
    currency: string;
    customer_description: string;
    publication_reason: string;
    effective_at: string;
    retired_at?: string | null;
    is_active?: boolean;
    published_by: string;
};

const props = defineProps<{
    current_rule: FeeRuleItem | null;
    rules: FeeRuleItem[];
    plan_options: FeeRuleItem[];
    plan_rules: FeeRuleItem[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Fee rules', href: feesRegistrationIndex() },
        ],
    },
});

const showPublishModal = ref(false);

const timingLabels: Record<string, string> = {
    registration: 'At registration',
    first_contribution: 'On first payment',
    cycle_completion: 'At end of plan cycle',
    withdrawal: 'On withdrawal',
};
function timingLabel(timing?: string): string {
    if (!timing) return '';
    return timingLabels[timing] ?? timing.replaceAll('_', ' ');
}

type RetirementReview = {
    rule: FeeRuleItem;
    reason: string;
    impact: string;
    preview_fingerprint: string;
};
const retiringRule = ref<FeeRuleItem | null>(null);
const retirementQuote = ref<RetirementReview | null>(null);
const retirementError = ref('');
let retirementReviewSequence = 0;
const retirementReview = useHttp<{ reason: string }, RetirementReview>({
    reason: '',
});
const retirementForm = useForm({
    reason: '',
    preview_fingerprint: '',
    confirmed: false,
});
watch(
    () => retirementReview.reason,
    () => {
        retirementReviewSequence++;
        retirementQuote.value = null;
        retirementForm.reset();
        retirementForm.clearErrors();
    },
);
function openRetirement(rule: FeeRuleItem): void {
    retirementReviewSequence++;
    retirementReview.reset();
    retirementReview.clearErrors();
    retirementForm.reset();
    retirementForm.clearErrors();
    retirementQuote.value = null;
    retirementError.value = '';
    retiringRule.value = rule;
}
async function reviewRetirement(): Promise<void> {
    if (!retiringRule.value) return;
    const ruleId = retiringRule.value.id;
    const sequence = ++retirementReviewSequence;
    retirementQuote.value = null;
    retirementError.value = '';
    retirementForm.reset();
    retirementForm.clearErrors();
    try {
        const reviewed = await retirementReview.post(
            previewFeeRuleRetirement.url(ruleId),
        );
        if (
            sequence !== retirementReviewSequence ||
            retiringRule.value?.id !== ruleId
        )
            return;
        retirementQuote.value = reviewed;
        retirementForm.reason = retirementQuote.value.reason;
        retirementForm.preview_fingerprint =
            retirementQuote.value.preview_fingerprint;
    } catch {
        if (
            sequence !== retirementReviewSequence ||
            retiringRule.value?.id !== ruleId
        )
            return;
        retirementError.value =
            'We could not check this right now. Check the reason, then reload the page if the fee has changed.';
    }
}
function confirmRetirement(): void {
    if (
        !retiringRule.value ||
        !retirementQuote.value ||
        !retirementForm.confirmed
    )
        return;
    retirementForm.post(retireFeeRule.url(retiringRule.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            retiringRule.value = null;
        },
        onError: () => {
            retirementQuote.value = null;
            retirementForm.confirmed = false;
            retirementForm.preview_fingerprint = '';
        },
    });
}

const form = useForm({
    kind: 'registration',
    rule_key: '',
    name: '',
    model: 'fixed',
    timing: 'first_contribution',
    basis: 'none',
    basis_points: '',
    amount_ngn: '',
    effective_at: '',
    customer_description: '',
    publication_reason: '',
    confirmed: false,
    preview_fingerprint: '',
});

type PublicationReview = {
    terms: {
        name: string;
        model_label: string;
        formatted_amount: string;
        timing: string;
        basis: string;
        settlement_source: string;
        effective_at: string;
        customer_description: string;
        publication_reason: string;
        current_catalogue_version: number;
        next_version: number;
    };
    example: { label: string; formatted_fee: string };
    impact: string;
    preview_fingerprint: string;
};
const publicationQuote = ref<PublicationReview | null>(null);
const publicationError = ref('');
const publicationReview = useHttp<
    ReturnType<typeof form.data>,
    PublicationReview
>(form.data());
let publicationReviewSequence = 0;
watch(
    () => [
        form.kind,
        form.rule_key,
        form.name,
        form.model,
        form.timing,
        form.basis,
        form.basis_points,
        form.amount_ngn,
        form.effective_at,
        form.customer_description,
        form.publication_reason,
    ],
    () => {
        publicationReviewSequence++;
        publicationQuote.value = null;
        publicationError.value = '';
        form.confirmed = false;
        form.preview_fingerprint = '';
    },
    { flush: 'sync' },
);
function closePublication(open: boolean): void {
    if (form.processing || publicationReview.processing) return;
    showPublishModal.value = open;
    if (!open) publicationReviewSequence++;
}

const openPublishModal = (kind: 'registration' | 'plan'): void => {
    form.reset();
    form.clearErrors();
    form.kind = kind;
    form.model = 'fixed';
    form.timing =
        kind === 'registration' ? 'registration' : 'first_contribution';
    showPublishModal.value = true;
};

const handleModelChange = (value: AcceptableValue): void => {
    if (typeof value !== 'string') {
        return;
    }

    const model = value;
    form.model = model;
    if (model === 'percentage' && form.timing === 'first_contribution') {
        form.timing = 'cycle_completion';
    } else if (model === 'one_day' && form.timing === 'withdrawal') {
        form.timing = 'cycle_completion';
    }
};

const submitPublish = async (): Promise<void> => {
    if (publicationQuote.value) {
        if (!form.confirmed) return;
        form.post(feesRegistrationStore().url, {
            preserveScroll: true,
            onSuccess: () => {
                showPublishModal.value = false;
                form.reset();
            },
            onError: () => {
                publicationQuote.value = null;
                form.confirmed = false;
                form.preview_fingerprint = '';
                publicationError.value =
                    'The fee was not saved. Please review the details again.';
            },
        });
        return;
    }
    if (form.model !== 'fixed') {
        form.amount_ngn = '';
    }
    if (form.model !== 'percentage') {
        form.basis_points = '';
    }

    if (form.kind === 'registration') {
        form.timing = 'registration';
        form.basis = 'none';
    } else if (form.model === 'one_day') {
        form.basis = 'contractual_daily_contribution';
    } else if (form.model === 'percentage') {
        form.basis =
            form.timing === 'withdrawal'
                ? 'gross_withdrawal_debit'
                : 'net_cycle_contributions';
    } else {
        form.basis = 'none';
    }

    form.clearErrors();
    publicationError.value = '';
    const sequence = ++publicationReviewSequence;
    Object.assign(publicationReview, form.data());
    try {
        const reviewed = await publicationReview.post(
            previewFeeRulePublication.url(),
        );
        if (sequence !== publicationReviewSequence || !showPublishModal.value)
            return;
        publicationQuote.value = reviewed;
        form.preview_fingerprint = reviewed.preview_fingerprint;
    } catch {
        if (sequence !== publicationReviewSequence || !showPublishModal.value)
            return;
        form.setError(publicationReview.errors);
        publicationError.value =
            'We could not check these details. Fix any errors, then reload the page if fees have changed.';
    }
};
</script>

<template>
    <div>
        <Head title="Fee rules" />

        <div class="space-y-6">
            <PageHeader
                title="Fee rules"
                description="Set what customers pay to register and on their plans."
            >
                <template #actions>
                    <Button @click="openPublishModal('registration')">
                        <Plus class="size-4" />
                        {{
                            current_rule
                                ? 'Change registration fee'
                                : 'Set registration fee'
                        }}
                    </Button>
                </template>
            </PageHeader>

            <Alert v-if="!current_rule" variant="destructive">
                <ShieldAlert class="size-4" />
                <AlertTitle>New customers cannot register</AlertTitle>
                <AlertDescription>
                    There is no registration fee yet. Set one (it can be zero)
                    to turn on registration.
                </AlertDescription>
            </Alert>

            <Card v-else>
                <CardHeader
                    class="flex flex-row flex-wrap items-start justify-between gap-3"
                >
                    <div class="min-w-0">
                        <CardTitle>Registration fee</CardTitle>
                        <CardDescription class="mt-1.5">
                            Paid once when a customer joins.
                        </CardDescription>
                    </div>
                    <DropdownMenu :modal="false">
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="More actions for the registration fee"
                            >
                                <MoreHorizontal class="size-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem
                                @select="openRetirement(current_rule)"
                                >Stop this fee</DropdownMenuItem
                            >
                        </DropdownMenuContent>
                    </DropdownMenu>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="flex flex-wrap items-end gap-x-4 gap-y-2">
                        <p class="text-3xl font-semibold tracking-tight">
                            {{ current_rule.formatted_amount }}
                        </p>
                        <Badge variant="outline">{{
                            current_rule.model_label
                        }}</Badge>
                    </div>
                    <div class="space-y-1 text-sm">
                        <p class="font-medium">{{ current_rule.name }}</p>
                        <p class="text-muted-foreground">
                            {{ current_rule.customer_description }}
                        </p>
                        <p class="text-muted-foreground text-xs">
                            In use since {{ current_rule.effective_at }}
                        </p>
                    </div>
                    <MoreDetails>
                        <dl class="grid gap-3 text-sm sm:grid-cols-3">
                            <div>
                                <dt class="text-muted-foreground text-xs">
                                    Version
                                </dt>
                                <dd>{{ current_rule.version }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground text-xs">
                                    Set by
                                </dt>
                                <dd>{{ current_rule.published_by }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground text-xs">
                                    Reason
                                </dt>
                                <dd>{{ current_rule.publication_reason }}</dd>
                            </div>
                        </dl>
                    </MoreDetails>
                </CardContent>
            </Card>

            <Card>
                <CardHeader
                    class="flex flex-row flex-wrap items-start justify-between gap-3"
                >
                    <div class="min-w-0">
                        <CardTitle>Plan fees</CardTitle>
                        <CardDescription class="mt-1.5">
                            Fee options you can pick when creating a plan.
                        </CardDescription>
                    </div>
                    <Button variant="outline" @click="openPublishModal('plan')">
                        <Plus class="size-4" /> Add plan fee
                    </Button>
                </CardHeader>
                <CardContent>
                    <EmptyState
                        v-if="plan_options.length === 0"
                        :icon="Coins"
                        title="No plan fees yet"
                        description="Add a plan fee to offer it on new plans."
                    />
                    <div v-else class="divide-border divide-y">
                        <div
                            v-for="option in plan_options"
                            :key="option.id"
                            class="flex flex-wrap items-center justify-between gap-3 py-3"
                        >
                            <div class="min-w-0">
                                <p class="font-medium">{{ option.name }}</p>
                                <p class="text-muted-foreground text-xs">
                                    {{ option.model_label }} ·
                                    {{ timingLabel(option.timing) }}
                                </p>
                                <p
                                    v-if="option.customer_description"
                                    class="text-muted-foreground mt-0.5 text-xs"
                                >
                                    {{ option.customer_description }}
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="font-semibold">{{
                                    option.formatted_amount
                                }}</span>
                                <DropdownMenu :modal="false">
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            :aria-label="`More actions for ${option.name}`"
                                        >
                                            <MoreHorizontal class="size-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem
                                            @select="openRetirement(option)"
                                            >Stop this fee</DropdownMenuItem
                                        >
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <section aria-labelledby="fee-history-heading" class="space-y-3">
                <h2 id="fee-history-heading" class="text-base font-medium">
                    History
                </h2>
                <MoreDetails label="Show all past fee changes">
                    <div class="space-y-6">
                        <div class="space-y-2">
                            <h3 class="text-sm font-medium">
                                Registration fees
                            </h3>
                            <p
                                v-if="rules.length === 0"
                                class="text-muted-foreground text-sm"
                            >
                                No registration fees yet.
                            </p>
                            <div
                                v-else
                                class="divide-border divide-y rounded-xl border"
                            >
                                <div
                                    v-for="rule in rules"
                                    :key="rule.id"
                                    class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div class="min-w-0 space-y-1">
                                        <div
                                            class="flex flex-wrap items-center gap-2"
                                        >
                                            <span class="text-sm font-medium">{{
                                                rule.name
                                            }}</span>
                                            <Badge
                                                :variant="
                                                    rule.is_active
                                                        ? 'default'
                                                        : 'secondary'
                                                "
                                                >{{
                                                    rule.is_active
                                                        ? 'Active'
                                                        : 'Stopped'
                                                }}</Badge
                                            >
                                        </div>
                                        <p
                                            class="text-muted-foreground text-xs"
                                        >
                                            Version {{ rule.version }} · Set by
                                            {{ rule.published_by }} on
                                            {{ rule.effective_at }}
                                            <template v-if="rule.retired_at">
                                                · Stopped
                                                {{ rule.retired_at }}</template
                                            >
                                        </p>
                                        <p
                                            class="text-muted-foreground text-xs"
                                        >
                                            Reason:
                                            {{ rule.publication_reason }}
                                        </p>
                                    </div>
                                    <div
                                        class="flex shrink-0 items-center gap-3"
                                    >
                                        <span class="font-semibold">{{
                                            rule.formatted_amount
                                        }}</span>
                                        <Button
                                            v-if="rule.is_active"
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            @click="openRetirement(rule)"
                                            >Stop</Button
                                        >
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <h3 class="text-sm font-medium">Plan fees</h3>
                            <p
                                v-if="plan_rules.length === 0"
                                class="text-muted-foreground text-sm"
                            >
                                No plan fees yet.
                            </p>
                            <div
                                v-else
                                class="divide-border divide-y rounded-xl border"
                            >
                                <div
                                    v-for="rule in plan_rules"
                                    :key="rule.id"
                                    class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div class="min-w-0 space-y-1">
                                        <div
                                            class="flex flex-wrap items-center gap-2"
                                        >
                                            <span class="text-sm font-medium">{{
                                                rule.name
                                            }}</span>
                                            <Badge
                                                :variant="
                                                    rule.is_active
                                                        ? 'default'
                                                        : 'secondary'
                                                "
                                                >{{
                                                    rule.is_active
                                                        ? 'Active'
                                                        : 'Stopped'
                                                }}</Badge
                                            >
                                        </div>
                                        <p
                                            class="text-muted-foreground text-xs"
                                        >
                                            {{ rule.model_label }} ·
                                            {{ timingLabel(rule.timing) }} ·
                                            from {{ rule.effective_at }}
                                        </p>
                                        <p
                                            class="text-muted-foreground text-xs"
                                        >
                                            Code {{ rule.rule_key }} · Version
                                            {{ rule.version }}
                                        </p>
                                    </div>
                                    <div
                                        class="flex shrink-0 items-center gap-3"
                                    >
                                        <span class="font-semibold">{{
                                            rule.formatted_amount
                                        }}</span>
                                        <Button
                                            v-if="rule.is_active"
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            @click="openRetirement(rule)"
                                            >Stop</Button
                                        >
                                    </div>
                                </div>
                            </div>
                        </div>
                        <p class="text-muted-foreground text-xs">
                            Saved fees cannot be edited. To change a fee, set a
                            new one.
                        </p>
                    </div>
                </MoreDetails>
            </section>

            <Dialog
                :open="retiringRule !== null"
                @update:open="if (!$event) retiringRule = null;"
            >
                <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Stop this fee?</DialogTitle>
                        <DialogDescription
                            >New agreements will no longer use it. Fees
                            customers already owe stay the
                            same.</DialogDescription
                        >
                    </DialogHeader>
                    <form
                        class="space-y-4"
                        @submit.prevent="
                            retirementQuote
                                ? confirmRetirement()
                                : reviewRetirement()
                        "
                    >
                        <p v-if="retiringRule" class="text-sm font-medium">
                            {{ retiringRule.name }} ·
                            {{ retiringRule.formatted_amount }}
                        </p>
                        <div class="space-y-2">
                            <Label for="retirement-reason">Reason</Label>
                            <Input
                                id="retirement-reason"
                                v-model="retirementReview.reason"
                                maxlength="500"
                                required
                                :disabled="
                                    retirementReview.processing ||
                                    retirementForm.processing
                                "
                                :aria-invalid="
                                    Boolean(
                                        retirementReview.errors.reason ||
                                        retirementForm.errors.reason,
                                    )
                                "
                                aria-describedby="retirement-reason-error"
                            />
                            <p
                                id="retirement-reason-error"
                                class="text-destructive text-sm"
                            >
                                {{
                                    retirementReview.errors.reason ||
                                    retirementForm.errors.reason
                                }}
                            </p>
                        </div>
                        <p
                            v-if="retirementError"
                            role="alert"
                            class="text-destructive text-sm"
                        >
                            {{ retirementError }}
                        </p>
                        <div
                            v-if="retirementQuote"
                            class="bg-muted space-y-3 rounded-lg p-4 text-sm"
                            aria-live="polite"
                        >
                            <p>{{ retirementQuote.impact }}</p>
                            <div class="flex items-start gap-2">
                                <Checkbox
                                    id="confirm-retirement"
                                    v-model="retirementForm.confirmed"
                                    :disabled="retirementForm.processing"
                                />
                                <Label
                                    for="confirm-retirement"
                                    class="leading-5"
                                    >I understand. Stop this fee.</Label
                                >
                            </div>
                        </div>
                        <p
                            v-if="
                                retirementForm.errors.confirmed ||
                                retirementForm.errors.preview_fingerprint
                            "
                            role="alert"
                            class="text-destructive text-sm"
                        >
                            {{
                                retirementForm.errors.confirmed ||
                                retirementForm.errors.preview_fingerprint
                            }}
                        </p>
                        <p class="text-muted-foreground text-xs">
                            You will need your password and authenticator code
                            to confirm.
                        </p>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="
                                    retirementReview.processing ||
                                    retirementForm.processing
                                "
                                @click="retiringRule = null"
                                >Cancel</Button
                            >
                            <Button
                                type="submit"
                                :variant="
                                    retirementQuote ? 'destructive' : 'default'
                                "
                                :disabled="
                                    retirementReview.processing ||
                                    retirementForm.processing ||
                                    Boolean(
                                        retirementQuote &&
                                        !retirementForm.confirmed,
                                    )
                                "
                            >
                                <Loader2
                                    v-if="
                                        retirementReview.processing ||
                                        retirementForm.processing
                                    "
                                    class="mr-2 size-4 animate-spin"
                                />
                                {{ retirementQuote ? 'Stop fee' : 'Continue' }}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog :open="showPublishModal" @update:open="closePublication">
                <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{{
                            form.kind === 'registration'
                                ? 'Set registration fee'
                                : 'Add plan fee'
                        }}</DialogTitle>
                        <DialogDescription>
                            Once saved, a fee cannot be edited. You can replace
                            or stop it later.
                        </DialogDescription>
                    </DialogHeader>

                    <form @submit.prevent="submitPublish" class="space-y-4">
                        <div class="space-y-1.5">
                            <Label for="rule-name"
                                >Fee name
                                <span class="text-destructive">*</span></Label
                            >
                            <Input
                                id="rule-name"
                                v-model="form.name"
                                :placeholder="
                                    form.kind === 'registration'
                                        ? 'e.g. Registration fee 2026'
                                        : 'e.g. Standard plan fee'
                                "
                                required
                                :class="{
                                    'border-destructive': form.errors.name,
                                }"
                            />
                            <p
                                v-if="form.errors.name"
                                class="text-destructive text-xs"
                            >
                                {{ form.errors.name }}
                            </p>
                        </div>

                        <div v-if="form.kind === 'plan'" class="space-y-1.5">
                            <Label for="rule-key"
                                >Short code
                                <span class="text-destructive">*</span></Label
                            >
                            <Input
                                id="rule-key"
                                v-model="form.rule_key"
                                placeholder="e.g. standard_plan"
                                required
                            />
                            <p class="text-muted-foreground text-xs">
                                Use the same code to replace an existing plan
                                fee.
                            </p>
                            <p
                                v-if="form.errors.rule_key"
                                class="text-destructive text-xs"
                            >
                                {{ form.errors.rule_key }}
                            </p>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-1.5">
                                <Label for="rule-model"
                                    >How it is charged
                                    <span class="text-destructive"
                                        >*</span
                                    ></Label
                                >
                                <Select
                                    :model-value="form.model"
                                    @update:model-value="handleModelChange"
                                >
                                    <SelectTrigger id="rule-model">
                                        <SelectValue placeholder="Choose" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="fixed"
                                            >Fixed amount (NGN)</SelectItem
                                        >
                                        <SelectItem value="no_fee"
                                            >No fee</SelectItem
                                        >
                                        <SelectItem
                                            v-if="form.kind === 'plan'"
                                            value="one_day"
                                            >One day's savings</SelectItem
                                        >
                                        <SelectItem
                                            v-if="form.kind === 'plan'"
                                            value="percentage"
                                            >Percentage</SelectItem
                                        >
                                    </SelectContent>
                                </Select>
                                <p
                                    v-if="form.errors.model"
                                    class="text-destructive text-xs"
                                >
                                    {{ form.errors.model }}
                                </p>
                            </div>

                            <div
                                v-if="form.kind === 'plan'"
                                class="space-y-1.5"
                            >
                                <Label for="rule-timing"
                                    >When it is charged
                                    <span class="text-destructive"
                                        >*</span
                                    ></Label
                                >
                                <Select v-model="form.timing">
                                    <SelectTrigger id="rule-timing"
                                        ><SelectValue placeholder="Choose"
                                    /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-if="form.model !== 'percentage'"
                                            value="first_contribution"
                                            >On first payment</SelectItem
                                        >
                                        <SelectItem value="cycle_completion"
                                            >At end of plan cycle</SelectItem
                                        >
                                        <SelectItem
                                            v-if="form.model !== 'one_day'"
                                            value="withdrawal"
                                            >On withdrawal</SelectItem
                                        >
                                    </SelectContent>
                                </Select>
                                <p
                                    v-if="form.errors.timing"
                                    class="text-destructive text-xs"
                                >
                                    {{ form.errors.timing }}
                                </p>
                            </div>

                            <div
                                v-if="
                                    form.model === 'fixed' &&
                                    (form.kind === 'registration' ||
                                        form.kind === 'plan')
                                "
                                class="space-y-1.5"
                            >
                                <Label for="rule-amount"
                                    >Amount (NGN)
                                    <span class="text-destructive"
                                        >*</span
                                    ></Label
                                >
                                <Input
                                    id="rule-amount"
                                    v-model="form.amount_ngn"
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    placeholder="e.g. 500.00"
                                    required
                                    :class="{
                                        'border-destructive':
                                            form.errors.amount_ngn,
                                    }"
                                />
                                <p
                                    v-if="form.errors.amount_ngn"
                                    class="text-destructive text-xs"
                                >
                                    {{ form.errors.amount_ngn }}
                                </p>
                            </div>

                            <div
                                v-if="form.model === 'percentage'"
                                class="space-y-1.5"
                            >
                                <Label for="rule-rate"
                                    >Rate (basis points)
                                    <span class="text-destructive"
                                        >*</span
                                    ></Label
                                >
                                <Input
                                    id="rule-rate"
                                    v-model="form.basis_points"
                                    type="number"
                                    step="1"
                                    min="0"
                                    max="10000"
                                    required
                                    placeholder="e.g. 250"
                                />
                                <p class="text-muted-foreground text-xs">
                                    100 = 1%. Charged on
                                    {{
                                        form.timing === 'withdrawal'
                                            ? 'the amount withdrawn'
                                            : 'what was saved in the cycle'
                                    }}.
                                </p>
                                <p
                                    v-if="form.errors.basis_points"
                                    class="text-destructive text-xs"
                                >
                                    {{ form.errors.basis_points }}
                                </p>
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <Label for="rule-effective-at">Starts on</Label>
                            <DatePicker
                                id="rule-effective-at"
                                v-model="form.effective_at"
                                with-time
                            />
                            <p
                                v-if="form.errors.effective_at"
                                class="text-destructive text-xs"
                            >
                                {{ form.errors.effective_at }}
                            </p>
                            <p class="text-muted-foreground text-xs">
                                Leave blank to start now.
                            </p>
                        </div>

                        <div class="space-y-1.5">
                            <Label for="rule-customer-description">
                                What customers see
                                <span class="text-destructive">*</span>
                            </Label>
                            <textarea
                                id="rule-customer-description"
                                v-model="form.customer_description"
                                rows="3"
                                required
                                :placeholder="
                                    form.kind === 'registration'
                                        ? 'e.g. One-time fee to open your account.'
                                        : 'Say what the fee is and when it is charged.'
                                "
                                class="border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                                :class="{
                                    'border-destructive':
                                        form.errors.customer_description,
                                }"
                            />
                            <p
                                v-if="form.errors.customer_description"
                                class="text-destructive text-xs"
                            >
                                {{ form.errors.customer_description }}
                            </p>
                        </div>

                        <div class="space-y-1.5">
                            <Label for="rule-reason">
                                Reason (staff only)
                                <span class="text-destructive">*</span>
                            </Label>
                            <textarea
                                id="rule-reason"
                                v-model="form.publication_reason"
                                rows="2"
                                required
                                placeholder="Why are you setting this fee?"
                                class="border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                                :class="{
                                    'border-destructive':
                                        form.errors.publication_reason,
                                }"
                            />
                            <p
                                v-if="form.errors.publication_reason"
                                class="text-destructive text-xs"
                            >
                                {{ form.errors.publication_reason }}
                            </p>
                        </div>

                        <Alert
                            v-if="publicationError"
                            variant="destructive"
                            role="alert"
                        >
                            <AlertDescription>{{
                                publicationError
                            }}</AlertDescription>
                        </Alert>
                        <div
                            v-if="publicationQuote"
                            class="space-y-3 rounded-lg border p-4"
                            aria-live="polite"
                        >
                            <p class="font-medium">Check before saving</p>
                            <dl class="space-y-2 text-sm">
                                <div>
                                    <dt class="text-muted-foreground">Fee</dt>
                                    <dd>
                                        {{ publicationQuote.terms.name }} ·
                                        {{
                                            publicationQuote.terms
                                                .formatted_amount
                                        }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-muted-foreground">
                                        Customers see
                                    </dt>
                                    <dd>
                                        {{
                                            publicationQuote.terms
                                                .customer_description
                                        }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-muted-foreground">
                                        Example
                                    </dt>
                                    <dd>
                                        {{ publicationQuote.example.label }}:
                                        {{
                                            publicationQuote.example
                                                .formatted_fee
                                        }}
                                    </dd>
                                </div>
                            </dl>
                            <p class="text-muted-foreground text-sm">
                                {{ publicationQuote.impact }}
                            </p>
                            <MoreDetails>
                                <dl class="space-y-2 text-xs">
                                    <div>
                                        <dt class="text-muted-foreground">
                                            How it is charged
                                        </dt>
                                        <dd>
                                            {{
                                                publicationQuote.terms
                                                    .model_label
                                            }}
                                            ·
                                            {{ publicationQuote.terms.timing }}
                                            ·
                                            {{ publicationQuote.terms.basis }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-muted-foreground">
                                            Paid from
                                        </dt>
                                        <dd>
                                            {{
                                                publicationQuote.terms
                                                    .settlement_source
                                            }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-muted-foreground">
                                            Starts (UTC)
                                        </dt>
                                        <dd>
                                            {{
                                                publicationQuote.terms
                                                    .effective_at
                                            }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-muted-foreground">
                                            Version
                                        </dt>
                                        <dd>
                                            {{
                                                publicationQuote.terms
                                                    .current_catalogue_version
                                            }}
                                            to
                                            {{
                                                publicationQuote.terms
                                                    .next_version
                                            }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-muted-foreground">
                                            Reason
                                        </dt>
                                        <dd>
                                            {{
                                                publicationQuote.terms
                                                    .publication_reason
                                            }}
                                        </dd>
                                    </div>
                                </dl>
                            </MoreDetails>
                            <div class="flex items-start gap-2">
                                <Checkbox
                                    id="confirm-publication"
                                    v-model="form.confirmed"
                                    :disabled="form.processing"
                                />
                                <Label
                                    for="confirm-publication"
                                    class="leading-5"
                                    >These details are correct.</Label
                                >
                            </div>
                        </div>

                        <p class="text-muted-foreground text-xs">
                            You will need your password and authenticator code
                            to confirm.
                        </p>

                        <DialogFooter class="gap-2 sm:gap-0">
                            <Button
                                type="button"
                                variant="outline"
                                @click="closePublication(false)"
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                :disabled="
                                    form.processing ||
                                    publicationReview.processing ||
                                    Boolean(publicationQuote && !form.confirmed)
                                "
                            >
                                <Loader2
                                    v-if="
                                        form.processing ||
                                        publicationReview.processing
                                    "
                                    class="mr-2 size-4 animate-spin"
                                />
                                {{ publicationQuote ? 'Save fee' : 'Continue' }}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    </div>
</template>
