<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
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
    name: '',
    model: 'fixed',
    amount_ngn: '',
    customer_description: '',
    publication_reason: '',
});

const openPublishModal = (): void => {
    form.reset();
    form.clearErrors();
    form.model = 'fixed';
    showPublishModal.value = true;
};

const submitPublish = (): void => {
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
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 class="text-[25px] font-medium tracking-tight">Registration Fee Rules</h1>
                    <p class="text-muted-foreground mt-1.5 text-sm">
                        Authoritative registration fee terms. All customer onboardings snapshot the active rule at registration.
                    </p>
                </div>
                <Button @click="openPublishModal">
                    <Plus class="mr-1.5 size-4" /> Publish New Rule
                </Button>
            </div>

            <!-- Current Active Rule Alert or Card -->
            <div v-if="!current_rule">
                <Alert variant="destructive">
                    <ShieldAlert class="size-4" />
                    <AlertTitle>Registration Disabled</AlertTitle>
                    <AlertDescription>
                        No active registration fee rule is currently published. Customer registration will fail closed until a valid rule is published.
                    </AlertDescription>
                </Alert>
            </div>

            <Card v-else class="border-primary/20 bg-primary/5">
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <Badge variant="default" class="bg-primary text-primary-foreground">
                                Active Version {{ current_rule.version }}
                            </Badge>
                            <Badge variant="outline">{{ current_rule.model_label }}</Badge>
                        </div>
                        <span class="text-muted-foreground text-xs">
                            Effective since {{ current_rule.effective_at }}
                        </span>
                    </div>
                    <CardTitle class="mt-2 text-xl">{{ current_rule.name }}</CardTitle>
                    <CardDescription>{{ current_rule.customer_description }}</CardDescription>
                </CardHeader>
                <CardContent class="grid gap-4 sm:grid-cols-3">
                    <div class="rounded-lg bg-background p-4 shadow-xs">
                        <p class="text-muted-foreground text-xs font-medium uppercase">Registration Fee</p>
                        <p class="mt-1 text-2xl font-bold tracking-tight text-foreground">
                            {{ current_rule.formatted_amount }}
                        </p>
                        <p class="text-muted-foreground text-[11px]">
                            {{ current_rule.amount_kobo === 0 ? 'No onboarding charge' : 'Snapshot and payable obligation generated' }}
                        </p>
                    </div>

                    <div class="rounded-lg bg-background p-4 shadow-xs">
                        <p class="text-muted-foreground text-xs font-medium uppercase">Published By</p>
                        <p class="mt-1 text-base font-semibold text-foreground">{{ current_rule.published_by }}</p>
                        <p class="text-muted-foreground text-[11px]">Authorized Administrator</p>
                    </div>

                    <div class="rounded-lg bg-background p-4 shadow-xs">
                        <p class="text-muted-foreground text-xs font-medium uppercase">Governance Justification</p>
                        <p class="mt-1 text-xs text-foreground">{{ current_rule.publication_reason }}</p>
                    </div>
                </CardContent>
            </Card>

            <!-- Publication History -->
            <Card>
                <CardHeader>
                    <div class="flex items-center gap-2">
                        <History class="size-5 text-muted-foreground" />
                        <CardTitle>Rule Publication History</CardTitle>
                    </div>
                    <CardDescription>
                        Immutable historical audit trail of all published registration fee rules.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div v-if="rules.length === 0" class="py-8 text-center text-sm text-muted-foreground">
                        No registration fee rules have been published yet.
                    </div>
                    <div v-else class="divide-y divide-border overflow-hidden rounded-lg border">
                        <div
                            v-for="rule in rules"
                            :key="rule.id"
                            class="flex flex-col gap-4 p-4 transition-colors hover:bg-muted/40 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div class="min-w-0 space-y-1">
                                <div class="flex items-center gap-2">
                                    <span class="font-mono text-sm font-semibold">v{{ rule.version }}</span>
                                    <Badge :variant="rule.is_active ? 'default' : 'secondary'">
                                        {{ rule.is_active ? 'Active' : 'Retired' }}
                                    </Badge>
                                    <Badge variant="outline">{{ rule.model_label }}</Badge>
                                    <span class="text-sm font-medium text-foreground">{{ rule.name }}</span>
                                </div>
                                <p class="text-xs text-muted-foreground">{{ rule.customer_description }}</p>
                                <p class="text-[11px] text-muted-foreground">
                                    Justification: <span class="italic text-foreground/80">{{ rule.publication_reason }}</span>
                                </p>
                            </div>

                            <div class="flex shrink-0 flex-col items-start gap-1 sm:items-end text-right">
                                <span class="font-mono text-base font-bold">{{ rule.formatted_amount }}</span>
                                <span class="text-[11px] text-muted-foreground">
                                    Published by {{ rule.published_by }} on {{ rule.effective_at }}
                                </span>
                                <span v-if="rule.retired_at" class="text-[10px] text-muted-foreground">
                                    Retired: {{ rule.retired_at }}
                                </span>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Publish Rule Modal -->
            <Dialog :open="showPublishModal" @update:open="showPublishModal = $event">
                <DialogContent class="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Publish Registration Fee Rule</DialogTitle>
                        <DialogDescription>
                            Publishing a new rule atomically retires the currently active rule. Active customer registrations immediately snapshot the new rule.
                        </DialogDescription>
                    </DialogHeader>

                    <form @submit.prevent="submitPublish" class="space-y-4">
                        <div class="space-y-1.5">
                            <Label for="rule-name">Rule Name <span class="text-destructive">*</span></Label>
                            <Input
                                id="rule-name"
                                v-model="form.name"
                                placeholder="e.g. Standard Customer Registration Fee 2026"
                                required
                                :class="{ 'border-destructive': form.errors.name }"
                            />
                            <p v-if="form.errors.name" class="text-destructive text-xs">{{ form.errors.name }}</p>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-1.5">
                                <Label for="rule-model">Fee Model <span class="text-destructive">*</span></Label>
                                <Select v-model="form.model">
                                    <SelectTrigger id="rule-model">
                                        <SelectValue placeholder="Select model" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="fixed">Fixed Fee (NGN)</SelectItem>
                                        <SelectItem value="no_fee">Explicit Zero / No Fee</SelectItem>
                                    </SelectContent>
                                </Select>
                                <p v-if="form.errors.model" class="text-destructive text-xs">{{ form.errors.model }}</p>
                            </div>

                            <div v-if="form.model === 'fixed'" class="space-y-1.5">
                                <Label for="rule-amount">Amount (NGN) <span class="text-destructive">*</span></Label>
                                <Input
                                    id="rule-amount"
                                    v-model="form.amount_ngn"
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    placeholder="e.g. 500.00"
                                    required
                                    :class="{ 'border-destructive': form.errors.amount_ngn }"
                                />
                                <p v-if="form.errors.amount_ngn" class="text-destructive text-xs">{{ form.errors.amount_ngn }}</p>
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <Label for="rule-customer-description">
                                Customer Disclosure <span class="text-destructive">*</span>
                            </Label>
                            <textarea
                                id="rule-customer-description"
                                v-model="form.customer_description"
                                rows="3"
                                required
                                placeholder="Disclosed to the customer during invitation activation (e.g. One-time onboarding and account verification fee)."
                                class="border-input placeholder:text-muted-foreground focus-visible:border-ring flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                                :class="{ 'border-destructive': form.errors.customer_description }"
                            />
                            <p v-if="form.errors.customer_description" class="text-destructive text-xs">
                                {{ form.errors.customer_description }}
                            </p>
                        </div>

                        <div class="space-y-1.5">
                            <Label for="rule-reason">
                                Publication Reason / Governance Justification <span class="text-destructive">*</span>
                            </Label>
                            <textarea
                                id="rule-reason"
                                v-model="form.publication_reason"
                                rows="2"
                                required
                                placeholder="Audit note explaining the governance rationale for this fee publication."
                                class="border-input placeholder:text-muted-foreground focus-visible:border-ring flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                                :class="{ 'border-destructive': form.errors.publication_reason }"
                            />
                            <p v-if="form.errors.publication_reason" class="text-destructive text-xs">
                                {{ form.errors.publication_reason }}
                            </p>
                        </div>

                        <div class="rounded-lg bg-muted/60 p-3 text-xs text-muted-foreground">
                            <p class="font-medium text-foreground">Step-up Authentication Requirement</p>
                            <p class="mt-1">
                                Publishing fee rules requires a fresh authentication session. If your session is older than 15 minutes, you will be prompted for your password before the change takes effect.
                            </p>
                        </div>

                        <DialogFooter class="gap-2 sm:gap-0">
                            <Button type="button" variant="outline" @click="showPublishModal = false">
                                Cancel
                            </Button>
                            <Button type="submit" :disabled="form.processing">
                                <Loader2 v-if="form.processing" class="mr-2 size-4 animate-spin" />
                                Publish Rule
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    </div>
</template>
