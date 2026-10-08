<script setup lang="ts">
import { HttpResponseError } from '@inertiajs/core';
import { Head, Link, router, useForm, useHttp, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import { Plus, Tags } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import FormSheet from '@/components/FormSheet.vue';
import MoreDetails from '@/components/MoreDetails.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { isOperationReference } from '@/lib/operation-reference';
import { dashboard } from '@/routes';
import {
    index,
    publish,
    assess,
    preview,
    status,
} from '@/routes/admin/charges';

type Category = {
    id: number;
    category_key: string;
    version: number;
    kind: string;
    purpose: string;
    customer_description: string;
    amount_kobo: number;
};
const props = defineProps<{
    categories: Category[];
    can_fees: boolean;
    can_deductions: boolean;
    can_apply_savings: boolean;
    enabled: boolean;
    customer: { customer_id: string; name: string; version: number } | null;
    plans: { plan_id: string; version: number }[];
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Manual charges', href: index() },
        ],
    },
});
const customerSearch = ref(props.customer?.customer_id ?? '');
const catalogue = useForm({
    publication_reference: crypto.randomUUID(),
    category_key: '',
    kind: props.can_fees ? 'manual_fee' : 'deduction',
    purpose: '',
    customer_description: '',
    amount_ngn: '',
    confirmed: false,
});
const charge = useForm({
    operation_reference: crypto.randomUUID(),
    customer_id: props.customer?.customer_id ?? '',
    plan_id: '',
    category_id: '',
    customer_version: props.customer?.version ?? 0,
    plan_version: 0,
    reason: '',
    mode: 'assessment_only',
    preview_fingerprint: '',
    quote_expires_at: '',
    confirmed: false,
});
const selected = computed(() =>
    props.categories.find(
        (category) => String(category.id) === charge.category_id,
    ),
);
const categorySheetOpen = ref(false);
function formatNaira(amountKobo: number): string {
    return `NGN ${(amountKobo / 100).toFixed(2)}`;
}
function publishCategory(): void {
    catalogue.post(publish.url(), {
        onSuccess: () => {
            catalogue.publication_reference = crypto.randomUUID();
            catalogue.confirmed = false;
            categorySheetOpen.value = false;
        },
    });
}
type Instructions = Pick<
    ReturnType<typeof charge.data>,
    | 'customer_id'
    | 'plan_id'
    | 'category_id'
    | 'customer_version'
    | 'plan_version'
    | 'reason'
    | 'mode'
>;
type Review = {
    preview_fingerprint: string;
    quote_expires_at: string;
    amount: string;
    purpose: string;
    customer_description: string;
    destination: string;
    posted_savings: string;
    reserved_savings: string;
    available_savings: string;
    remaining_savings: string;
    remaining_available: string;
    remaining_fee: string;
    occurred_on: string;
    business_timezone: string;
};
type Outcome = { status: string; charge_reference: string };
const page = usePage();
const canPost = computed(
    () =>
        props.enabled &&
        ['normal', 'degraded'].includes(page.props.platform.mode),
);
const review = ref<Review | null>(null);
const pendingReference = ref<string | null>(null);
const submitted = ref<ReturnType<typeof charge.data> | null>(null);
const message = ref('');
const storageBlocked = ref(false);
const storageKey = `manual-charge-attempt-${page.props.auth.user.id}`;
const reviewRequest = useHttp<Instructions, Review>({
    customer_id: charge.customer_id,
    plan_id: '',
    category_id: '',
    customer_version: charge.customer_version,
    plan_version: 0,
    reason: '',
    mode: 'assessment_only',
});
const commitRequest = useHttp<ReturnType<typeof charge.data>, Outcome>(
    charge.data(),
);
const statusRequest = useHttp<Record<string, never>, Outcome>({});
const busy = computed(
    () =>
        reviewRequest.processing ||
        commitRequest.processing ||
        statusRequest.processing,
);
let sequence = 0;
watch(
    () => props.customer,
    (customer) => {
        if (customer && !pendingReference.value) {
            charge.customer_id = customer.customer_id;
            charge.customer_version = customer.version;
        }
    },
);
watch(
    () => charge.category_id,
    () => {
        charge.mode =
            selected.value?.kind === 'deduction'
                ? 'deduction'
                : 'assessment_only';
    },
);
watch(
    () => [charge.plan_id, charge.category_id, charge.reason, charge.mode],
    () => {
        sequence++;
        review.value = null;
        charge.confirmed = false;
    },
    { flush: 'sync' },
);
onMounted(() => {
    try {
        const stored = sessionStorage.getItem(storageKey);
        if (stored && isOperationReference(stored))
            pendingReference.value = stored;
    } catch {
        storageBlocked.value = true;
        message.value =
            'Your browser cannot save charges right now, so charging is turned off. Try another browser or turn off private mode.';
    }
});
function chargeError(error: unknown): string {
    if (error instanceof HttpResponseError) {
        try {
            const body: unknown = JSON.parse(error.response.data);
            if (
                body &&
                typeof body === 'object' &&
                'message' in body &&
                typeof body.message === 'string'
            )
                return body.message;
        } catch {
            /* Keep the recoverable outcome message. */
        }
    }
    return 'We do not know if the charge went through. Check its status before charging again.';
}
function clearAttempt(): boolean {
    try {
        sessionStorage.removeItem(storageKey);
        pendingReference.value = null;
        submitted.value = null;
        return true;
    } catch {
        message.value =
            'Your browser could not clear the last charge. Check its status before charging again.';
        return false;
    }
}
function savedOutcome(outcome: Outcome): void {
    if (clearAttempt()) {
        charge.operation_reference = crypto.randomUUID();
        review.value = null;
        charge.confirmed = false;
        message.value = `Charge saved. Reference ${outcome.charge_reference}.`;
        router.reload({ only: ['customer', 'plans', 'categories'] });
    }
}
async function checkOutcome(): Promise<void> {
    if (!pendingReference.value || busy.value) return;
    try {
        savedOutcome(
            await statusRequest.get(status.url(pendingReference.value)),
        );
    } catch (error) {
        if (
            error instanceof HttpResponseError &&
            error.response.status === 404
        ) {
            if (clearAttempt()) {
                charge.operation_reference = crypto.randomUUID();
                review.value = null;
                charge.confirmed = false;
                message.value =
                    'The charge did not go through. Review it again to try once more.';
            }
        } else message.value = chargeError(error);
    }
}
async function reviewCharge(): Promise<void> {
    if (busy.value || pendingReference.value) return;
    charge.plan_version =
        props.plans.find((plan) => plan.plan_id === charge.plan_id)?.version ??
        0;
    const generation = ++sequence;
    message.value = '';
    const inputs: Instructions = {
        customer_id: charge.customer_id,
        plan_id: charge.plan_id,
        category_id: charge.category_id,
        customer_version: charge.customer_version,
        plan_version: charge.plan_version,
        reason: charge.reason,
        mode: charge.mode,
    };
    Object.assign(reviewRequest, inputs);
    try {
        const response = await reviewRequest.post(preview.url());
        if (generation === sequence) review.value = response;
    } catch (error) {
        message.value = chargeError(error);
    }
}
async function sendAttempt(): Promise<void> {
    if (!submitted.value || busy.value) return;
    Object.assign(commitRequest, submitted.value);
    try {
        savedOutcome(await commitRequest.post(assess.url()));
    } catch (error) {
        message.value = chargeError(error);
    }
}
async function submitCharge(): Promise<void> {
    if (
        !review.value ||
        !charge.confirmed ||
        !canPost.value ||
        storageBlocked.value ||
        pendingReference.value ||
        busy.value
    )
        return;
    const payload = {
        ...charge.data(),
        preview_fingerprint: review.value.preview_fingerprint,
        quote_expires_at: review.value.quote_expires_at,
    };
    try {
        sessionStorage.setItem(storageKey, payload.operation_reference);
    } catch {
        storageBlocked.value = true;
        message.value =
            'Your browser cannot save charges right now, so nothing was sent. Try another browser or turn off private mode.';
        return;
    }
    pendingReference.value = payload.operation_reference;
    submitted.value = payload;
    await sendAttempt();
}
</script>
<template>
    <div class="flex flex-col gap-6">
        <Head title="Manual charges" />
        <PageHeader
            title="Manual charges"
            description="Charge a customer a one-off fee or take it from their savings."
        >
            <template #actions>
                <Button variant="outline" @click="categorySheetOpen = true">
                    <Plus class="size-4" /> New charge type
                </Button>
            </template>
        </PageHeader>
        <p
            v-if="!enabled"
            role="status"
            class="bg-muted rounded-xl p-4 text-sm"
        >
            Charging customers is not turned on yet. You can still set up charge
            types.
        </p>

        <Card>
            <CardHeader>
                <CardTitle>Charge a customer</CardTitle>
                <CardDescription class="mt-1.5">
                    Find the customer, then pick a plan and charge type.
                </CardDescription>
            </CardHeader>
            <CardContent class="grid max-w-xl gap-4">
                <form
                    class="flex flex-wrap items-end gap-2"
                    @submit.prevent="
                        router.get(index.url(), { customer: customerSearch })
                    "
                >
                    <div class="grid w-fit gap-2">
                        <Label for="charge-customer">Customer ID</Label
                        ><Input
                            id="charge-customer"
                            v-model="customerSearch"
                            placeholder="Customer ID"
                            required
                            class="w-56"
                        />
                    </div>
                    <Button type="submit" variant="outline">Find</Button>
                </form>
                <form
                    v-if="customer"
                    class="grid gap-4"
                    @submit.prevent="submitCharge"
                >
                    <fieldset
                        class="grid gap-4"
                        :disabled="busy || pendingReference !== null"
                    >
                        <div class="bg-muted/40 rounded-xl p-3 text-sm">
                            <p class="font-medium">{{ customer.name }}</p>
                            <p class="text-muted-foreground text-xs">
                                {{ customer.customer_id }}
                            </p>
                        </div>
                        <div class="grid gap-2">
                            <Label for="charge-plan">Plan</Label
                            ><Select v-model="charge.plan_id" required>
                                <SelectTrigger id="charge-plan" class="w-full"
                                    ><SelectValue placeholder="Choose a plan"
                                /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="plan in plans"
                                        :key="plan.plan_id"
                                        :value="plan.plan_id"
                                    >
                                        {{ plan.plan_id }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="grid gap-2">
                            <Label for="charge-category">Charge type</Label
                            ><Select v-model="charge.category_id" required>
                                <SelectTrigger
                                    id="charge-category"
                                    class="w-full"
                                    ><SelectValue
                                        placeholder="Choose a charge type"
                                /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="category in categories"
                                        :key="category.id"
                                        :value="String(category.id)"
                                    >
                                        {{ category.purpose }} ·
                                        {{ formatNaira(category.amount_kobo) }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p
                                v-if="selected"
                                class="text-muted-foreground text-xs"
                            >
                                {{
                                    selected.kind === 'manual_fee'
                                        ? 'Adds a fee the customer owes.'
                                        : 'Takes the amount from savings on this plan.'
                                }}
                                Customer sees: "{{
                                    selected.customer_description
                                }}"
                            </p>
                        </div>
                        <div
                            v-if="selected?.kind === 'manual_fee'"
                            class="grid gap-2"
                        >
                            <Label for="charge-mode">Payment</Label>
                            <Select v-model="charge.mode">
                                <SelectTrigger id="charge-mode" class="w-full"
                                    ><SelectValue
                                /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="assessment_only">
                                        Customer pays later
                                    </SelectItem>
                                    <SelectItem
                                        value="assess_and_apply"
                                        :disabled="!can_apply_savings"
                                    >
                                        Pay now from this plan's savings
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="grid gap-2">
                            <Label for="charge-reason">Reason</Label
                            ><Input
                                id="charge-reason"
                                v-model="charge.reason"
                                required
                                maxlength="500"
                            />
                        </div>
                        <p
                            v-for="(error, field) in {
                                ...reviewRequest.errors,
                                ...commitRequest.errors,
                            }"
                            :key="field"
                            class="text-destructive text-sm"
                            role="alert"
                        >
                            {{ error }}
                        </p>
                        <Button
                            v-if="!review"
                            type="button"
                            class="w-fit"
                            :disabled="!enabled || busy"
                            @click="reviewCharge"
                            >Review charge</Button
                        >
                        <div
                            v-if="review"
                            class="grid gap-3 rounded-xl border p-4 text-sm"
                            aria-live="polite"
                        >
                            <p class="font-medium">Check before charging</p>
                            <dl class="grid grid-cols-2 gap-2">
                                <dt class="text-muted-foreground">Charge</dt>
                                <dd class="font-medium">{{ review.amount }}</dd>
                                <dt class="text-muted-foreground">
                                    Savings available now
                                </dt>
                                <dd>{{ review.available_savings }}</dd>
                                <dt class="text-muted-foreground">
                                    Savings available after
                                </dt>
                                <dd>{{ review.remaining_available }}</dd>
                                <dt class="text-muted-foreground">
                                    Unpaid fee after
                                </dt>
                                <dd>{{ review.remaining_fee }}</dd>
                            </dl>
                            <p
                                v-if="charge.mode === 'assess_and_apply'"
                                class="text-muted-foreground text-xs"
                            >
                                The fee is added and paid in one step. If the
                                payment fails, no fee is added.
                            </p>
                            <MoreDetails>
                                <dl
                                    class="text-muted-foreground grid grid-cols-2 gap-2 text-xs"
                                >
                                    <dt>Purpose</dt>
                                    <dd>{{ review.purpose }}</dd>
                                    <dt>Customer sees</dt>
                                    <dd>{{ review.customer_description }}</dd>
                                    <dt>Total savings on plan</dt>
                                    <dd>{{ review.posted_savings }}</dd>
                                    <dt>Savings on hold</dt>
                                    <dd>{{ review.reserved_savings }}</dd>
                                    <dt>Total savings after</dt>
                                    <dd>{{ review.remaining_savings }}</dd>
                                    <dt>Money goes to</dt>
                                    <dd class="break-all">
                                        {{ review.destination }}
                                    </dd>
                                    <dt>Date</dt>
                                    <dd>
                                        {{ review.occurred_on }} ({{
                                            review.business_timezone
                                        }})
                                    </dd>
                                    <dt>Review valid until</dt>
                                    <dd>{{ review.quote_expires_at }}</dd>
                                </dl>
                            </MoreDetails>
                            <label class="flex items-center gap-2 text-sm"
                                ><input
                                    v-model="charge.confirmed"
                                    type="checkbox"
                                />The customer, plan and amount are
                                correct</label
                            >
                            <Button
                                type="submit"
                                class="w-fit"
                                :disabled="
                                    !canPost ||
                                    busy ||
                                    !review ||
                                    !charge.confirmed ||
                                    storageBlocked
                                "
                                >{{
                                    charge.mode === 'assessment_only'
                                        ? 'Add fee'
                                        : charge.mode === 'assess_and_apply'
                                          ? 'Add and pay fee'
                                          : 'Deduct from savings'
                                }}</Button
                            >
                        </div>
                    </fieldset>
                </form>
                <p
                    v-if="message"
                    class="bg-muted rounded-xl p-3 text-sm"
                    role="status"
                >
                    {{ message }}
                </p>
                <div
                    v-if="pendingReference"
                    class="grid gap-3 rounded-xl border p-4 text-sm"
                >
                    <p>
                        You have a charge that is not confirmed yet. Check its
                        status before charging again.
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            :disabled="busy"
                            @click="checkOutcome"
                            >Check status</Button
                        >
                        <Button
                            v-if="submitted && canPost"
                            type="button"
                            variant="outline"
                            :disabled="busy"
                            @click="sendAttempt"
                            >Try again</Button
                        >
                    </div>
                    <MoreDetails>
                        <p class="text-muted-foreground text-xs">
                            Reference {{ pendingReference }}
                        </p>
                    </MoreDetails>
                </div>
                <p v-if="!canPost" class="text-muted-foreground text-sm">
                    {{ page.props.platform.message }}
                </p>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Charge types</CardTitle>
                <CardDescription class="mt-1.5">
                    Set amounts you can charge customers.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <EmptyState
                    v-if="categories.length === 0"
                    :icon="Tags"
                    title="No charge types yet"
                    description="Create a charge type before you charge a customer."
                >
                    <Button variant="outline" @click="categorySheetOpen = true"
                        >New charge type</Button
                    >
                </EmptyState>
                <div v-else class="divide-border divide-y">
                    <div
                        v-for="category in categories"
                        :key="category.id"
                        class="flex flex-wrap items-center justify-between gap-3 py-3"
                    >
                        <div class="min-w-0">
                            <p class="text-sm font-medium">
                                {{ category.purpose }}
                            </p>
                            <p class="text-muted-foreground text-xs">
                                {{ category.customer_description }}
                            </p>
                            <p class="text-muted-foreground text-xs">
                                {{ category.category_key }} · version
                                {{ category.version }}
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <Badge variant="outline">{{
                                category.kind === 'deduction'
                                    ? 'From savings'
                                    : 'Fee'
                            }}</Badge>
                            <span class="text-sm font-medium">{{
                                formatNaira(category.amount_kobo)
                            }}</span>
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>

        <FormSheet
            v-model:open="categorySheetOpen"
            title="New charge type"
            description="Once saved, the amount cannot be changed."
        >
            <form
                id="category-form"
                class="grid gap-4"
                @submit.prevent="publishCategory"
            >
                <div class="grid gap-2">
                    <Label for="category-kind">Type</Label
                    ><Select v-model="catalogue.kind">
                        <SelectTrigger id="category-kind" class="w-full"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem v-if="can_fees" value="manual_fee">
                                Fee the customer owes
                            </SelectItem>
                            <SelectItem v-if="can_deductions" value="deduction">
                                Take from savings
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div class="grid gap-2">
                    <Label for="category-purpose">Name</Label
                    ><Input
                        id="category-purpose"
                        v-model="catalogue.purpose"
                        required
                        maxlength="500"
                        placeholder="e.g. Card replacement"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="category-description">What customers see</Label
                    ><Input
                        id="category-description"
                        v-model="catalogue.customer_description"
                        required
                        maxlength="500"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="category-amount">Amount (NGN)</Label
                    ><Input
                        id="category-amount"
                        v-model="catalogue.amount_ngn"
                        required
                        inputmode="decimal"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="category-key">Short code</Label
                    ><Input
                        id="category-key"
                        v-model="catalogue.category_key"
                        required
                        maxlength="80"
                        placeholder="e.g. card_replacement"
                    />
                    <p class="text-muted-foreground text-xs">
                        Use the same code to replace an existing charge type.
                    </p>
                </div>
                <p
                    v-for="(error, field) in catalogue.errors"
                    :key="field"
                    class="text-destructive text-sm"
                    role="alert"
                >
                    {{ error }}
                </p>
                <label class="flex items-center gap-2 text-sm"
                    ><input v-model="catalogue.confirmed" type="checkbox" />I
                    understand this amount cannot be changed later</label
                >
            </form>
            <template #footer>
                <Button
                    type="button"
                    variant="outline"
                    @click="categorySheetOpen = false"
                    >Cancel</Button
                >
                <Button
                    type="submit"
                    form="category-form"
                    :disabled="catalogue.processing || !catalogue.confirmed"
                    >Save</Button
                >
            </template>
        </FormSheet>
    </div>
</template>
