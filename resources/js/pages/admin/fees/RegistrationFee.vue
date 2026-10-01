<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import type { AcceptableValue } from 'reka-ui';
import {
    AlertCircle,
    CheckCircle2,
    Coins,
    History,
    Loader2,
    Plus,
    ShieldAlert,
    ShieldCheck,
} from '@lucide/vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
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
} from '@/routes/admin/fees/registration';

export type FeeRuleItem = {
    id: string;
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
            { title: 'Registration Fees', href: feesRegistrationIndex() },
        ],
    },
});

const showPublishModal = ref(false);

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
});

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

const submitPublish = (): void => {
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

    form.post(feesRegistrationStore().url, {
        preserveScroll: true,
        onSuccess: () => {
            showPublishModal.value = false;
            form.reset();
        },
    });
};
</script>

<template>
    <div>
        <Head title="Registration Fee Rules" />

        <div class="space-y-6">
            <!-- Header -->
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
            >
                <div>
                    <h1 class="text-[25px] font-medium tracking-tight">
                        Fees and Plan Rules
                    </h1>
                    <p class="text-muted-foreground mt-1.5 text-sm">
                        Versioned registration terms and selectable plan fee
                        options. Posting stays gated until each owning financial
                        workflow is ready.
                    </p>
                </div>
                <Button @click="openPublishModal('registration')">
                    <Plus class="mr-1.5 size-4" /> Registration Rule
                </Button>
            </div>

            <!-- Current Active Rule Alert or Card -->
            <div v-if="!current_rule">
                <Alert variant="destructive">
                    <ShieldAlert class="size-4" />
                    <AlertTitle>Registration Disabled</AlertTitle>
                    <AlertDescription>
                        No active registration fee rule is currently published.
                        Customer registration will fail closed until a valid
                        rule is published.
                    </AlertDescription>
                </Alert>
            </div>

            <Card v-else class="border-primary/20 bg-primary/5">
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <Badge
                                variant="default"
                                class="bg-primary text-primary-foreground"
                            >
                                Active Version {{ current_rule.version }}
                            </Badge>
                            <Badge variant="outline">{{
                                current_rule.model_label
                            }}</Badge>
                        </div>
                        <span class="text-muted-foreground text-xs">
                            Effective since {{ current_rule.effective_at }}
                        </span>
                    </div>
                    <CardTitle class="mt-2 text-xl">{{
                        current_rule.name
                    }}</CardTitle>
                    <CardDescription>{{
                        current_rule.customer_description
                    }}</CardDescription>
                </CardHeader>
                <CardContent class="grid gap-4 sm:grid-cols-3">
                    <div class="bg-background rounded-lg p-4 shadow-xs">
                        <p
                            class="text-muted-foreground text-xs font-medium uppercase"
                        >
                            Registration Fee
                        </p>
                        <p
                            class="text-foreground mt-1 text-2xl font-bold tracking-tight"
                        >
                            {{ current_rule.formatted_amount }}
                        </p>
                        <p class="text-muted-foreground text-[11px]">
                            {{
                                current_rule.amount_kobo === 0
                                    ? 'No onboarding charge'
                                    : 'Snapshot and payable obligation generated'
                            }}
                        </p>
                    </div>

                    <div class="bg-background rounded-lg p-4 shadow-xs">
                        <p
                            class="text-muted-foreground text-xs font-medium uppercase"
                        >
                            Published By
                        </p>
                        <p class="text-foreground mt-1 text-base font-semibold">
                            {{ current_rule.published_by }}
                        </p>
                        <p class="text-muted-foreground text-[11px]">
                            Authorized Administrator
                        </p>
                    </div>

                    <div class="bg-background rounded-lg p-4 shadow-xs">
                        <p
                            class="text-muted-foreground text-xs font-medium uppercase"
                        >
                            Governance Justification
                        </p>
                        <p class="text-foreground mt-1 text-xs">
                            {{ current_rule.publication_reason }}
                        </p>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <div
                        class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div>
                            <CardTitle>Selectable Plan Fee Options</CardTitle>
                            <CardDescription class="mt-1.5">
                                Rule options are versioned for future plan
                                snapshots. Assessment and posting wait for
                                Module 06 contracts.
                            </CardDescription>
                        </div>
                        <Button
                            variant="outline"
                            @click="openPublishModal('plan')"
                        >
                            <Plus class="mr-1.5 size-4" /> Plan Fee Option
                        </Button>
                    </div>
                </CardHeader>
                <CardContent>
                    <div
                        v-if="plan_options.length === 0"
                        class="text-muted-foreground rounded-lg border border-dashed p-6 text-center text-sm"
                    >
                        No selectable plan fee options have been published.
                    </div>
                    <div v-else class="grid gap-3 md:grid-cols-2">
                        <div
                            v-for="option in plan_options"
                            :key="option.id"
                            class="rounded-lg border p-4"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="font-medium">{{ option.name }}</p>
                                    <p class="text-muted-foreground text-xs">
                                        {{ option.rule_key }} · v{{
                                            option.version
                                        }}
                                    </p>
                                </div>
                                <Badge variant="outline">{{
                                    option.model_label
                                }}</Badge>
                            </div>
                            <p class="mt-3 font-mono text-lg font-semibold">
                                {{ option.formatted_amount }}
                            </p>
                            <p class="text-muted-foreground mt-1 text-xs">
                                {{ option.customer_description }}
                            </p>
                            <p class="text-muted-foreground mt-2 text-[11px]">
                                Trigger:
                                {{ option.timing?.replaceAll('_', ' ') }}
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <div class="flex items-center gap-2">
                        <History class="text-muted-foreground size-5" />
                        <CardTitle>Plan Rule History</CardTitle>
                    </div>
                    <CardDescription
                        >Published versions remain immutable; only future
                        effective intervals change.</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <div
                        v-if="plan_rules.length === 0"
                        class="text-muted-foreground py-6 text-center text-sm"
                    >
                        No plan fee rules have been published yet.
                    </div>
                    <div
                        v-else
                        class="divide-border divide-y overflow-hidden rounded-lg border"
                    >
                        <div
                            v-for="rule in plan_rules"
                            :key="rule.id"
                            class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span
                                        class="font-mono text-sm font-semibold"
                                        >{{ rule.rule_key }} · v{{
                                            rule.version
                                        }}</span
                                    >
                                    <Badge
                                        :variant="
                                            rule.is_active
                                                ? 'default'
                                                : 'secondary'
                                        "
                                        >{{
                                            rule.is_active
                                                ? 'Active'
                                                : 'Retired'
                                        }}</Badge
                                    >
                                    <Badge variant="outline">{{
                                        rule.model_label
                                    }}</Badge>
                                </div>
                                <p class="mt-1 text-sm font-medium">
                                    {{ rule.name }}
                                </p>
                                <p class="text-muted-foreground text-xs">
                                    {{ rule.customer_description }}
                                </p>
                            </div>
                            <div class="text-left sm:text-right">
                                <p class="font-mono font-semibold">
                                    {{ rule.formatted_amount }}
                                </p>
                                <p class="text-muted-foreground text-[11px]">
                                    {{ rule.timing?.replaceAll('_', ' ') }} ·
                                    {{ rule.effective_at }}
                                </p>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Publication History -->
            <Card>
                <CardHeader>
                    <div class="flex items-center gap-2">
                        <History class="text-muted-foreground size-5" />
                        <CardTitle>Rule Publication History</CardTitle>
                    </div>
                    <CardDescription>
                        Immutable historical audit trail of all published
                        registration fee rules.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div
                        v-if="rules.length === 0"
                        class="text-muted-foreground py-8 text-center text-sm"
                    >
                        No registration fee rules have been published yet.
                    </div>
                    <div
                        v-else
                        class="divide-border divide-y overflow-hidden rounded-lg border"
                    >
                        <div
                            v-for="rule in rules"
                            :key="rule.id"
                            class="hover:bg-muted/40 flex flex-col gap-4 p-4 transition-colors sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div class="min-w-0 space-y-1">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="font-mono text-sm font-semibold"
                                        >v{{ rule.version }}</span
                                    >
                                    <Badge
                                        :variant="
                                            rule.is_active
                                                ? 'default'
                                                : 'secondary'
                                        "
                                    >
                                        {{
                                            rule.is_active
                                                ? 'Active'
                                                : 'Retired'
                                        }}
                                    </Badge>
                                    <Badge variant="outline">{{
                                        rule.model_label
                                    }}</Badge>
                                    <span
                                        class="text-foreground text-sm font-medium"
                                        >{{ rule.name }}</span
                                    >
                                </div>
                                <p class="text-muted-foreground text-xs">
                                    {{ rule.customer_description }}
                                </p>
                                <p class="text-muted-foreground text-[11px]">
                                    Justification:
                                    <span class="text-foreground/80 italic">{{
                                        rule.publication_reason
                                    }}</span>
                                </p>
                            </div>

                            <div
                                class="flex shrink-0 flex-col items-start gap-1 text-right sm:items-end"
                            >
                                <span class="font-mono text-base font-bold">{{
                                    rule.formatted_amount
                                }}</span>
                                <span class="text-muted-foreground text-[11px]">
                                    Published by {{ rule.published_by }} on
                                    {{ rule.effective_at }}
                                </span>
                                <span
                                    v-if="rule.retired_at"
                                    class="text-muted-foreground text-[10px]"
                                >
                                    Retired: {{ rule.retired_at }}
                                </span>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Publish Rule Modal -->
            <Dialog
                :open="showPublishModal"
                @update:open="showPublishModal = $event"
            >
                <DialogContent class="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{{
                            form.kind === 'registration'
                                ? 'Publish Registration Fee Rule'
                                : 'Publish Plan Fee Option'
                        }}</DialogTitle>
                        <DialogDescription>
                            Publishing creates an immutable version and sets its
                            effective interval. Plan options do not trigger fees
                            until their owning workflow is available.
                        </DialogDescription>
                    </DialogHeader>

                    <form @submit.prevent="submitPublish" class="space-y-4">
                        <div v-if="form.kind === 'plan'" class="space-y-1.5">
                            <Label for="rule-key"
                                >Stable Option Key
                                <span class="text-destructive">*</span></Label
                            >
                            <Input
                                id="rule-key"
                                v-model="form.rule_key"
                                placeholder="e.g. standard_plan"
                                required
                            />
                            <p
                                v-if="form.errors.rule_key"
                                class="text-destructive text-xs"
                            >
                                {{ form.errors.rule_key }}
                            </p>
                        </div>

                        <div class="space-y-1.5">
                            <Label for="rule-name"
                                >Rule Name
                                <span class="text-destructive">*</span></Label
                            >
                            <Input
                                id="rule-name"
                                v-model="form.name"
                                :placeholder="
                                    form.kind === 'registration'
                                        ? 'e.g. Standard Customer Registration Fee 2026'
                                        : 'e.g. Standard Plan Cycle Fee'
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

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-1.5">
                                <Label for="rule-model"
                                    >Fee Model
                                    <span class="text-destructive"
                                        >*</span
                                    ></Label
                                >
                                <Select
                                    :model-value="form.model"
                                    @update:model-value="handleModelChange"
                                >
                                    <SelectTrigger id="rule-model">
                                        <SelectValue
                                            placeholder="Select model"
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="fixed"
                                            >Fixed Fee (NGN)</SelectItem
                                        >
                                        <SelectItem value="no_fee"
                                            >Explicit Zero / No Fee</SelectItem
                                        >
                                        <SelectItem
                                            v-if="form.kind === 'plan'"
                                            value="one_day"
                                            >One Contractual Day</SelectItem
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
                                    >Fee Timing
                                    <span class="text-destructive"
                                        >*</span
                                    ></Label
                                >
                                <Select v-model="form.timing">
                                    <SelectTrigger id="rule-timing"
                                        ><SelectValue
                                            placeholder="Select timing"
                                    /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-if="form.model !== 'percentage'"
                                            value="first_contribution"
                                            >First contribution</SelectItem
                                        >
                                        <SelectItem value="cycle_completion"
                                            >Cycle completion</SelectItem
                                        >
                                        <SelectItem
                                            v-if="form.model !== 'one_day'"
                                            value="withdrawal"
                                            >Withdrawal</SelectItem
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
                                <p class="text-muted-foreground text-[11px]">
                                    100 basis points = 1%. Basis:
                                    {{
                                        form.timing === 'withdrawal'
                                            ? 'gross withdrawal debit'
                                            : 'net cycle contributions'
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
                            <Label for="rule-effective-at"
                                >Effective from</Label
                            >
                            <Input
                                id="rule-effective-at"
                                v-model="form.effective_at"
                                type="datetime-local"
                            />
                            <p
                                v-if="form.errors.effective_at"
                                class="text-destructive text-xs"
                            >
                                {{ form.errors.effective_at }}
                            </p>
                            <p class="text-muted-foreground text-[11px]">
                                Leave blank to make the rule effective when
                                published.
                            </p>
                        </div>

                        <div class="space-y-1.5">
                            <Label for="rule-customer-description">
                                Customer Disclosure
                                <span class="text-destructive">*</span>
                            </Label>
                            <textarea
                                id="rule-customer-description"
                                v-model="form.customer_description"
                                rows="3"
                                required
                                :placeholder="
                                    form.kind === 'registration'
                                        ? 'Disclosed during activation (e.g. One-time onboarding fee).'
                                        : 'Explain the plan fee and when it applies.'
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
                                Publication Reason / Governance Justification
                                <span class="text-destructive">*</span>
                            </Label>
                            <textarea
                                id="rule-reason"
                                v-model="form.publication_reason"
                                rows="2"
                                required
                                placeholder="Audit note explaining the governance rationale for this fee publication."
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

                        <div
                            class="bg-muted/60 text-muted-foreground rounded-lg p-3 text-xs"
                        >
                            <p class="text-foreground font-medium">
                                Step-up Authentication Requirement
                            </p>
                            <p class="mt-1">
                                Publishing fee rules requires a fresh
                                authentication session. If your session is older
                                than 15 minutes, you will be prompted for your
                                password before the change takes effect.
                            </p>
                        </div>

                        <DialogFooter class="gap-2 sm:gap-0">
                            <Button
                                type="button"
                                variant="outline"
                                @click="showPublishModal = false"
                            >
                                Cancel
                            </Button>
                            <Button type="submit" :disabled="form.processing">
                                <Loader2
                                    v-if="form.processing"
                                    class="mr-2 size-4 animate-spin"
                                />
                                Publish Rule
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    </div>
</template>
