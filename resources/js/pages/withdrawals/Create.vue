<script setup lang="ts">
import FeeQuoteSummary from '@/components/FeeQuoteSummary.vue';
import MoreDetails from '@/components/MoreDetails.vue';
import PageHeader from '@/components/PageHeader.vue';
import type { FeeDisclosure } from '@/types/fee-disclosure';
import { Head, Link, useForm, useHttp } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
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
import { index as withdrawalsIndex } from '@/routes/withdrawals';
import { index as payoutDestinations } from '@/routes/customers/payout-destinations';
import {
    preview as previewWithdrawal,
    store as storeWithdrawal,
} from '@/routes/customers/withdrawals';

type Quote = {
    preview_fingerprint: string;
    quote_expires_at: string;
    customer_version: number;
    assignment_version: number;
    plan_version: number;
    business_version: number;
    gross_kobo: number;
    fee_kobo: number;
    fee_disclosure: FeeDisclosure;
    deduction_kobo: number;
    deduction_description: string | null;
    net_kobo: number;
    position: {
        liability_kobo: number;
        reservations_kobo: number;
        available_kobo: number;
        cycle_available_kobo: number;
    };
    destination_mask: string;
};
const props = defineProps<{
    customer: { id: string; name: string };
    plans: Array<{ id: string; status: string }>;
    methods: string[];
    cash_destination_reference: string;
    bank_destinations: Array<{ reference: string; label: string }>;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Withdrawals', href: withdrawalsIndex() },
            { title: 'Request', href: '#' },
        ],
    },
});
const form = useForm({
    attempt_reference: crypto.randomUUID(),
    plan_id: props.plans[0]?.id ?? '',
    type: 'partial',
    gross_ngn: '',
    method: props.methods[0] ?? 'cash',
    destination_reference:
        props.methods[0] === 'bank_transfer'
            ? (props.bank_destinations[0]?.reference ?? '')
            : props.cash_destination_reference,
    reason: '',
    internal_notes: '',
    preview_fingerprint: '',
    quote_expires_at: '',
    customer_version: 0,
    assignment_version: 0,
    plan_version: 0,
    business_version: 0,
    instruction_attested: false,
    confirmed: false,
});
const previewHttp = useHttp({
    plan_id: '',
    type: '',
    gross_ngn: '',
    method: '',
    destination_reference: '',
    reason: '',
    internal_notes: '',
});
const quote = ref<Quote | null>(null);
const reviewOpen = ref(false);
const money = (kobo: number): string =>
    `₦${(kobo / 100).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
watch(
    () => form.method,
    (method) => {
        form.destination_reference =
            method === 'bank_transfer'
                ? (props.bank_destinations[0]?.reference ?? '')
                : props.cash_destination_reference;
    },
);
watch(
    () => [
        form.plan_id,
        form.type,
        form.gross_ngn,
        form.method,
        form.destination_reference,
        form.reason,
        form.internal_notes,
    ],
    () => {
        quote.value = null;
        form.confirmed = false;
    },
);
async function review(): Promise<void> {
    previewHttp.plan_id = form.plan_id;
    previewHttp.type = form.type;
    previewHttp.gross_ngn = form.gross_ngn;
    previewHttp.method = form.method;
    previewHttp.destination_reference = form.destination_reference;
    previewHttp.reason = form.reason;
    previewHttp.internal_notes = form.internal_notes;
    try {
        const result = (await previewHttp.post(
            previewWithdrawal.url(props.customer.id),
        )) as Quote;
        quote.value = result;
        form.preview_fingerprint = result.preview_fingerprint;
        form.quote_expires_at = result.quote_expires_at;
        form.customer_version = result.customer_version;
        form.assignment_version = result.assignment_version;
        form.plan_version = result.plan_version;
        form.business_version = result.business_version;
        reviewOpen.value = true;
    } catch {
        quote.value = null;
    }
}
function submit(): void {
    if (quote.value && form.confirmed && form.instruction_attested)
        form.post(storeWithdrawal.url(props.customer.id));
}
</script>

<template>
    <Head title="Request withdrawal" />
    <div class="flex flex-col gap-6">
        <PageHeader
            title="Request withdrawal"
            :description="`Ask for a withdrawal from ${customer.name}'s savings.`"
        />
        <p
            v-if="methods.length === 0"
            role="status"
            class="bg-muted rounded-xl p-4 text-sm"
        >
            You can't request withdrawals yet. No payout method is set up.
        </p>
        <Card v-else class="max-w-3xl">
            <CardHeader><CardTitle>Withdrawal details</CardTitle></CardHeader>
            <CardContent>
                <form
                    class="grid gap-5 sm:grid-cols-2"
                    @submit.prevent="review"
                >
                    <div class="grid gap-2">
                        <Label for="withdrawal-plan">Savings plan</Label
                        ><Select v-model="form.plan_id"
                            ><SelectTrigger
                                id="withdrawal-plan"
                                class="h-11 w-full"
                                ><SelectValue /></SelectTrigger
                            ><SelectContent
                                ><SelectItem
                                    v-for="plan in plans"
                                    :key="plan.id"
                                    :value="plan.id"
                                    >{{ plan.id }} ·
                                    {{ plan.status }}</SelectItem
                                ></SelectContent
                            ></Select
                        >
                    </div>
                    <div class="grid gap-2">
                        <Label for="withdrawal-type">Type</Label
                        ><Select v-model="form.type"
                            ><SelectTrigger
                                id="withdrawal-type"
                                class="h-11 w-full"
                                ><SelectValue /></SelectTrigger
                            ><SelectContent
                                ><SelectItem value="partial"
                                    >Part of the savings</SelectItem
                                ><SelectItem value="full"
                                    >All savings in this cycle</SelectItem
                                ><SelectItem value="end_of_cycle"
                                    >End of cycle</SelectItem
                                ></SelectContent
                            ></Select
                        >
                    </div>
                    <div class="grid gap-2">
                        <Label for="withdrawal-gross">Amount (NGN)</Label
                        ><Input
                            id="withdrawal-gross"
                            v-model="form.gross_ngn"
                            inputmode="decimal"
                            placeholder="1000.00"
                            aria-describedby="withdrawal-gross-help"
                        />
                        <p
                            id="withdrawal-gross-help"
                            class="text-muted-foreground text-xs"
                        >
                            Any fees come out of this amount.
                        </p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="withdrawal-method">Pay by</Label
                        ><Select v-model="form.method"
                            ><SelectTrigger
                                id="withdrawal-method"
                                class="h-11 w-full"
                                ><SelectValue /></SelectTrigger
                            ><SelectContent
                                ><SelectItem
                                    v-if="methods.includes('cash')"
                                    value="cash"
                                    >Cash</SelectItem
                                ><SelectItem
                                    v-if="methods.includes('bank_transfer')"
                                    value="bank_transfer"
                                    >Bank transfer</SelectItem
                                ></SelectContent
                            ></Select
                        >
                    </div>
                    <div
                        v-if="form.method === 'bank_transfer'"
                        class="grid gap-2 sm:col-span-2"
                    >
                        <Label for="withdrawal-destination">Bank account</Label>
                        <Select
                            v-if="bank_destinations.length > 0"
                            v-model="form.destination_reference"
                            ><SelectTrigger
                                id="withdrawal-destination"
                                class="h-11 w-full"
                                ><SelectValue /></SelectTrigger
                            ><SelectContent
                                ><SelectItem
                                    v-for="destination in bank_destinations"
                                    :key="destination.reference"
                                    :value="destination.reference"
                                    >{{ destination.label }}</SelectItem
                                ></SelectContent
                            ></Select
                        >
                        <p v-else class="text-sm">
                            This customer has no checked bank account yet.
                            <Link
                                :href="payoutDestinations(customer.id)"
                                class="text-primary underline"
                                >Add one</Link
                            >.
                        </p>
                    </div>
                    <p
                        v-else
                        class="text-muted-foreground text-sm sm:col-span-2"
                    >
                        The customer collects the cash in person, then confirms
                        they got it.
                    </p>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="withdrawal-reason"
                            >Reason (the customer will see this)</Label
                        ><Input
                            id="withdrawal-reason"
                            v-model="form.reason"
                            maxlength="500"
                        />
                    </div>
                    <div class="sm:col-span-2">
                        <MoreDetails label="Add a private note">
                            <div class="grid gap-2">
                                <Label for="withdrawal-notes"
                                    >Private note (optional)</Label
                                ><Input
                                    id="withdrawal-notes"
                                    v-model="form.internal_notes"
                                    maxlength="1000"
                                />
                                <p class="text-muted-foreground text-xs">
                                    Only staff can see this.
                                </p>
                            </div>
                        </MoreDetails>
                    </div>
                    <div
                        v-if="
                            Object.keys(previewHttp.errors).length ||
                            (!reviewOpen && Object.keys(form.errors).length)
                        "
                        role="alert"
                        class="text-destructive grid gap-1 text-sm sm:col-span-2"
                    >
                        <p
                            v-for="(error, key) in previewHttp.errors"
                            :key="key"
                        >
                            {{ error }}
                        </p>
                        <template v-if="!reviewOpen">
                            <p
                                v-for="(error, key) in form.errors"
                                :key="`form-${key}`"
                            >
                                {{ error }}
                            </p>
                        </template>
                    </div>
                    <div class="sm:col-span-2">
                        <Button type="submit" :disabled="previewHttp.processing"
                            >Review request</Button
                        >
                    </div>
                </form>
            </CardContent>
        </Card>

        <Dialog v-model:open="reviewOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Check and submit</DialogTitle>
                    <DialogDescription>
                        Submitting sets this money aside while it is reviewed.
                        The customer is not paid yet.
                    </DialogDescription>
                </DialogHeader>
                <div v-if="quote" class="grid gap-5 text-sm">
                    <div class="bg-muted/40 rounded-xl p-4">
                        <p class="text-muted-foreground">Customer receives</p>
                        <p class="text-2xl font-semibold">
                            {{ money(quote.net_kobo) }}
                        </p>
                        <p class="text-muted-foreground mt-1">
                            To {{ quote.destination_mask }}
                        </p>
                    </div>
                    <dl class="divide-border divide-y">
                        <div class="flex justify-between gap-3 py-2">
                            <dt class="text-muted-foreground">
                                Taken from savings
                            </dt>
                            <dd class="font-medium">
                                {{ money(quote.gross_kobo) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3 py-2">
                            <dt class="text-muted-foreground">Fees</dt>
                            <dd class="font-medium">
                                {{ money(quote.fee_kobo) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3 py-2">
                            <dt class="text-muted-foreground">
                                Withdrawal charge
                                <span
                                    v-if="quote.deduction_description"
                                    class="block text-xs"
                                    >{{ quote.deduction_description }}</span
                                >
                            </dt>
                            <dd class="font-medium">
                                {{ money(quote.deduction_kobo) }}
                            </dd>
                        </div>
                    </dl>
                    <MoreDetails label="Fees and balance">
                        <div class="grid gap-4">
                            <FeeQuoteSummary
                                :disclosure="quote.fee_disclosure"
                            />
                            <dl class="grid gap-2 sm:grid-cols-2">
                                <div>
                                    <dt class="text-muted-foreground">
                                        Total savings
                                    </dt>
                                    <dd class="font-medium">
                                        {{
                                            money(quote.position.liability_kobo)
                                        }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-muted-foreground">
                                        Already set aside
                                    </dt>
                                    <dd class="font-medium">
                                        {{
                                            money(
                                                quote.position
                                                    .reservations_kobo,
                                            )
                                        }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-muted-foreground">
                                        Available
                                    </dt>
                                    <dd class="font-medium">
                                        {{
                                            money(quote.position.available_kobo)
                                        }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-muted-foreground">
                                        Available in this plan
                                    </dt>
                                    <dd class="font-medium">
                                        {{
                                            money(
                                                quote.position
                                                    .cycle_available_kobo,
                                            )
                                        }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </MoreDetails>
                    <div class="grid gap-3">
                        <label class="flex items-start gap-3"
                            ><input
                                v-model="form.instruction_attested"
                                type="checkbox"
                                class="mt-0.5"
                            />
                            The customer asked for this amount, payment method
                            and account.</label
                        ><label class="flex items-start gap-3"
                            ><input
                                v-model="form.confirmed"
                                type="checkbox"
                                class="mt-0.5"
                            />
                            I have checked the details above.</label
                        >
                    </div>
                    <div
                        v-if="Object.keys(form.errors).length"
                        role="alert"
                        class="text-destructive grid gap-1"
                    >
                        <p v-for="(error, key) in form.errors" :key="key">
                            {{ error }}
                        </p>
                    </div>
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="reviewOpen = false"
                        >Go back</Button
                    >
                    <Button
                        type="button"
                        :disabled="
                            !quote ||
                            form.processing ||
                            !form.confirmed ||
                            !form.instruction_attested
                        "
                        @click="submit"
                        >Submit request</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
