<script setup lang="ts">
import FeeQuoteSummary from '@/components/FeeQuoteSummary.vue';
import type { FeeDisclosure } from '@/types/fee-disclosure';
import { Head, Link, useForm, useHttp } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">
                Request withdrawal
            </h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                Make a review request for {{ customer.name }} from one savings
                cycle.
            </p>
        </div>
        <Card v-if="methods.length === 0"
            ><CardContent class="pt-6"
                ><p class="text-sm">
                    You cannot make requests now. A payout method must first
                    have approved execution, destination, custody, and evidence
                    controls.
                </p></CardContent
            ></Card
        >
        <Card v-else
            ><CardHeader><CardTitle>Request terms</CardTitle></CardHeader
            ><CardContent class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="withdrawal-plan">Source cycle</Label
                    ><Select v-model="form.plan_id"
                        ><SelectTrigger id="withdrawal-plan" class="h-11 w-full"
                            ><SelectValue /></SelectTrigger
                        ><SelectContent
                            ><SelectItem
                                v-for="plan in plans"
                                :key="plan.id"
                                :value="plan.id"
                                >{{ plan.id }} · {{ plan.status }}</SelectItem
                            >
                            ></SelectContent
                        ></Select
                    >
                </div>
                <div class="grid gap-2">
                    <Label for="withdrawal-type">Type</Label
                    ><Select v-model="form.type"
                        ><SelectTrigger id="withdrawal-type" class="h-11 w-full"
                            ><SelectValue /></SelectTrigger
                        ><SelectContent
                            ><SelectItem value="partial">Partial</SelectItem
                            ><SelectItem value="full"
                                >Full cycle savings</SelectItem
                            ><SelectItem value="end_of_cycle"
                                >End of cycle</SelectItem
                            ></SelectContent
                        ></Select
                    >
                </div>
                <div class="grid gap-2">
                    <Label for="withdrawal-gross"
                        >Gross savings debit (NGN)</Label
                    ><Input
                        id="withdrawal-gross"
                        v-model="form.gross_ngn"
                        inputmode="decimal"
                        placeholder="1000.00"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="withdrawal-method">Method</Label
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
                            >
                            ></SelectContent
                        ></Select
                    >
                </div>
                <div
                    v-if="form.method === 'bank_transfer'"
                    class="grid gap-2 sm:col-span-2"
                >
                    <Label for="withdrawal-destination"
                        >Verified bank destination</Label
                    >
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
                            >
                            ></SelectContent
                        ></Select
                    >
                    <p v-else class="text-sm">
                        This Customer has no verified bank destination.
                        <Link
                            :href="payoutDestinations(customer.id)"
                            class="text-primary underline"
                            >Register one for review</Link
                        >.
                    </p>
                </div>
                <p v-else class="text-sm sm:col-span-2">
                    The Customer gets the cash personally. The Customer then
                    acknowledges the payment.
                </p>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="withdrawal-reason"
                        >Customer-visible reason</Label
                    ><Input
                        id="withdrawal-reason"
                        v-model="form.reason"
                        maxlength="500"
                    />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="withdrawal-notes"
                        >Internal notes (optional)</Label
                    ><Input
                        id="withdrawal-notes"
                        v-model="form.internal_notes"
                        maxlength="1000"
                    />
                </div>
                <Button
                    type="button"
                    class="w-fit"
                    :disabled="previewHttp.processing"
                    @click="review"
                    >Review request</Button
                >
                <p
                    v-for="(error, key) in previewHttp.errors"
                    :key="key"
                    class="text-destructive text-sm"
                >
                    {{ error }}
                </p>
            </CardContent></Card
        >
        <Card v-if="quote"
            ><CardHeader
                ><CardTitle
                    >Confirm gross debit and net payout</CardTitle
                ></CardHeader
            ><CardContent class="grid gap-4 text-sm"
                ><div class="grid gap-3 sm:grid-cols-4">
                    <p>
                        Gross savings debit<br /><strong>{{
                            money(quote.gross_kobo)
                        }}</strong>
                    </p>
                    <p>
                        Withdrawal deduction<br /><strong>{{
                            money(quote.deduction_kobo)
                        }}</strong
                        ><span
                            v-if="quote.deduction_description"
                            class="text-muted-foreground block"
                            >{{ quote.deduction_description }}</span
                        >
                    </p>
                    <p>
                        Total fee included in this payout<br /><strong>{{
                            money(quote.fee_kobo)
                        }}</strong>
                    </p>
                    <p>
                        Net payout<br /><strong>{{
                            money(quote.net_kobo)
                        }}</strong>
                    </p>
                </div>
                <FeeQuoteSummary :disclosure="quote.fee_disclosure" />
                <p>
                    Current liability
                    {{ money(quote.position.liability_kobo) }} · reserved
                    {{ money(quote.position.reservations_kobo) }} · available
                    {{ money(quote.position.available_kobo) }}. Source cycle
                    available {{ money(quote.position.cycle_available_kobo) }}.
                </p>
                <p>
                    Destination {{ quote.destination_mask }}. This request
                    reserves gross savings for review. It does not pay the
                    Customer.
                </p>
                <label class="flex gap-3"
                    ><input
                        v-model="form.instruction_attested"
                        type="checkbox"
                    />
                    I attest that the Customer instructed this amount, method,
                    and destination.</label
                ><label class="flex gap-3"
                    ><input v-model="form.confirmed" type="checkbox" /> I
                    confirm the request terms above.</label
                ><Button
                    type="button"
                    class="w-fit"
                    :disabled="
                        form.processing ||
                        !form.confirmed ||
                        !form.instruction_attested
                    "
                    @click="submit"
                    >Submit for review</Button
                >
                <p
                    v-for="(error, key) in form.errors"
                    :key="key"
                    class="text-destructive"
                >
                    {{ error }}
                </p></CardContent
            ></Card
        >
        <Link
            :href="withdrawalsIndex()"
            class="text-primary w-fit text-sm underline"
            >Back to withdrawals</Link
        >
    </div>
</template>
