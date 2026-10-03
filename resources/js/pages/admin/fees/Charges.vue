<script setup lang="ts">
import { HttpResponseError } from '@inertiajs/core';
import { Head, Link, router, useForm, useHttp, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { isOperationReference } from '@/lib/operation-reference';
import { dashboard, freshAuthentication } from '@/routes';
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
            { title: 'Charges', href: index() },
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
function publishCategory(): void {
    catalogue.post(publish.url(), {
        onSuccess: () => {
            catalogue.publication_reference = crypto.randomUUID();
            catalogue.confirmed = false;
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
            'Your browser cannot retain the charge attempt. Restore session storage before posting.';
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
    return 'The charge outcome is unknown. Check the saved outcome before another charge.';
}
function clearAttempt(): boolean {
    try {
        sessionStorage.removeItem(storageKey);
        pendingReference.value = null;
        submitted.value = null;
        return true;
    } catch {
        message.value =
            'Your browser could not clear the charge attempt. Check its outcome before another charge.';
        return false;
    }
}
function savedOutcome(outcome: Outcome): void {
    if (clearAttempt()) {
        charge.operation_reference = crypto.randomUUID();
        review.value = null;
        charge.confirmed = false;
        message.value = `Charge confirmed. Reference ${outcome.charge_reference}.`;
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
                    'No confirmed charge was found. Review current terms and savings again.';
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
            'Your browser cannot retain the charge attempt. Restore session storage before posting.';
        return;
    }
    pendingReference.value = payload.operation_reference;
    submitted.value = payload;
    await sendAttempt();
}
</script>
<template>
    <div class="flex flex-col gap-6">
        <Head title="Controlled charges" />
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">
                Controlled charges
            </h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                Publish fixed NGN terms, then confirm a charge for an eligible
                Customer cycle.
            </p>
        </div>
        <p v-if="!enabled" class="text-muted-foreground text-sm">
            Charge posting awaits integrated acceptance. Published categories
            remain available for review.
        </p>
        <Card
            ><CardHeader
                ><CardTitle>Publish a category version</CardTitle></CardHeader
            ><CardContent>
                <form
                    class="grid max-w-xl gap-3"
                    @submit.prevent="publishCategory"
                >
                    <Label for="category-kind">Charge kind</Label
                    ><select
                        id="category-kind"
                        v-model="catalogue.kind"
                        class="border-input rounded-md border p-2"
                    >
                        <option v-if="can_fees" value="manual_fee">
                            Manual fee obligation
                        </option>
                        <option v-if="can_deductions" value="deduction">
                            Savings deduction
                        </option>
                    </select>
                    <Label for="category-key">Category key</Label
                    ><Input
                        id="category-key"
                        v-model="catalogue.category_key"
                        required
                        maxlength="80"
                    />
                    <Label for="category-purpose">Approved purpose</Label
                    ><Input
                        id="category-purpose"
                        v-model="catalogue.purpose"
                        required
                        maxlength="500"
                    />
                    <Label for="category-description"
                        >Customer description</Label
                    ><Input
                        id="category-description"
                        v-model="catalogue.customer_description"
                        required
                        maxlength="500"
                    />
                    <Label for="category-amount">Fixed amount (NGN)</Label
                    ><Input
                        id="category-amount"
                        v-model="catalogue.amount_ngn"
                        required
                        inputmode="decimal"
                    />
                    <p class="text-muted-foreground text-sm">
                        Manual fees recognize income when settled. Deductions
                        use the configured deduction destination.
                    </p>
                    <p
                        v-for="(error, field) in catalogue.errors"
                        :key="field"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ error }}
                    </p>
                    <label class="flex items-center gap-2 text-sm"
                        ><input
                            v-model="catalogue.confirmed"
                            type="checkbox"
                        />Confirm publication of immutable terms</label
                    >
                    <Button
                        type="submit"
                        :disabled="catalogue.processing || !catalogue.confirmed"
                        >Publish version</Button
                    >
                </form>
            </CardContent></Card
        >
        <Card
            ><CardHeader><CardTitle>Assess a charge</CardTitle></CardHeader
            ><CardContent class="grid max-w-xl gap-3">
                <form
                    class="flex gap-2"
                    @submit.prevent="
                        router.get(index.url(), { customer: customerSearch })
                    "
                >
                    <Label for="charge-customer" class="sr-only"
                        >Customer ID</Label
                    ><Input
                        id="charge-customer"
                        v-model="customerSearch"
                        placeholder="Customer ID"
                        required
                    /><Button type="submit" variant="outline"
                        >Find Customer</Button
                    >
                </form>
                <form
                    v-if="customer"
                    class="grid gap-3"
                    @submit.prevent="submitCharge"
                >
                    <fieldset
                        class="grid gap-3"
                        :disabled="busy || pendingReference !== null"
                    >
                        <p>{{ customer.name }} · {{ customer.customer_id }}</p>
                        <Label for="charge-plan">Cycle</Label
                        ><select
                            id="charge-plan"
                            v-model="charge.plan_id"
                            class="border-input rounded-md border p-2"
                            required
                        >
                            <option value="">Choose cycle</option>
                            <option
                                v-for="plan in plans"
                                :key="plan.plan_id"
                                :value="plan.plan_id"
                            >
                                {{ plan.plan_id }}
                            </option>
                        </select>
                        <Label for="charge-category">Published category</Label
                        ><select
                            id="charge-category"
                            v-model="charge.category_id"
                            class="border-input rounded-md border p-2"
                            required
                        >
                            <option value="">Choose category</option>
                            <option
                                v-for="category in categories"
                                :key="category.id"
                                :value="String(category.id)"
                            >
                                {{ category.category_key }} · version
                                {{ category.version }} · NGN
                                {{ (category.amount_kobo / 100).toFixed(2) }}
                            </option>
                        </select>
                        <p v-if="selected" class="text-sm">
                            {{ selected.customer_description }} ·
                            {{
                                selected.kind === 'manual_fee'
                                    ? 'Creates an unpaid obligation'
                                    : 'Debits unreserved cycle savings'
                            }}
                        </p>
                        <template v-if="selected?.kind === 'manual_fee'">
                            <Label for="charge-mode">Assessment action</Label>
                            <select
                                id="charge-mode"
                                v-model="charge.mode"
                                class="border-input rounded-md border p-2"
                            >
                                <option value="assessment_only">
                                    Assess only, leave the fee unpaid
                                </option>
                                <option
                                    value="assess_and_apply"
                                    :disabled="!can_apply_savings"
                                >
                                    Assess and pay the full fee from this
                                    cycle's savings
                                </option>
                            </select>
                        </template>
                        <Label for="charge-reason">Reason for this charge</Label
                        ><Input
                            id="charge-reason"
                            v-model="charge.reason"
                            required
                            maxlength="500"
                        />
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
                            type="button"
                            variant="outline"
                            :disabled="!enabled || busy"
                            @click="reviewCharge"
                            >Review charge</Button
                        >
                        <div
                            v-if="review"
                            class="border-border grid gap-2 rounded-md border p-4 text-sm"
                            aria-live="polite"
                        >
                            <p>
                                {{ review.purpose }} ·
                                {{ review.customer_description }}
                            </p>
                            <dl class="grid grid-cols-2 gap-2">
                                <dt>Charge</dt>
                                <dd>{{ review.amount }}</dd>
                                <dt>Posted cycle savings</dt>
                                <dd>{{ review.posted_savings }}</dd>
                                <dt>Reserved savings</dt>
                                <dd>{{ review.reserved_savings }}</dd>
                                <dt>Available savings</dt>
                                <dd>{{ review.available_savings }}</dd>
                                <dt>Remaining savings</dt>
                                <dd>{{ review.remaining_savings }}</dd>
                                <dt>Remaining available</dt>
                                <dd>{{ review.remaining_available }}</dd>
                                <dt>Remaining unpaid fee</dt>
                                <dd>{{ review.remaining_fee }}</dd>
                                <dt>Destination</dt>
                                <dd class="break-all">
                                    {{ review.destination }}
                                </dd>
                            </dl>
                            <p>
                                {{ review.occurred_on }} ·
                                {{ review.business_timezone }}
                            </p>
                            <p>Review expires {{ review.quote_expires_at }}</p>
                            <p v-if="charge.mode === 'assess_and_apply'">
                                Assessment and full payment commit together. A
                                failed payment leaves no new fee debt.
                            </p>
                        </div>
                        <label
                            v-if="review"
                            class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="charge.confirmed"
                                type="checkbox"
                            />Confirm this Customer, cycle and exact category
                            amount</label
                        >
                        <Button
                            type="submit"
                            :disabled="
                                !canPost ||
                                busy ||
                                !review ||
                                !charge.confirmed ||
                                storageBlocked
                            "
                            >{{
                                charge.mode === 'assessment_only'
                                    ? 'Confirm assessment only'
                                    : charge.mode === 'assess_and_apply'
                                      ? 'Confirm assessment and full payment'
                                      : 'Confirm deduction'
                            }}</Button
                        >
                    </fieldset>
                </form>
                <p v-if="message" class="text-sm" role="status">
                    {{ message }}
                </p>
                <div v-if="pendingReference" class="grid gap-2 text-sm">
                    <p>
                        Pending charge reference {{ pendingReference }}. Check
                        its outcome before another charge.
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="busy"
                        @click="checkOutcome"
                        >Check saved outcome</Button
                    >
                    <Button
                        v-if="submitted && canPost"
                        type="button"
                        variant="outline"
                        :disabled="busy"
                        @click="sendAttempt"
                        >Retry the original charge</Button
                    >
                    <Link
                        :href="freshAuthentication()"
                        class="text-primary underline"
                        >Confirm password and authenticator</Link
                    >
                </div>
                <p v-if="!canPost" class="text-muted-foreground text-sm">
                    {{ page.props.platform.message }}
                </p>
            </CardContent></Card
        >
        <Link :href="dashboard()" class="text-primary w-fit text-sm underline"
            >Back to dashboard</Link
        >
    </div>
</template>
