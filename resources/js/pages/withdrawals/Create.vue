<script setup lang="ts">
import { Head, Link, useForm, useHttp } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { index as withdrawalsIndex } from '@/routes/withdrawals';
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
    method_available: boolean;
    cash_destination_reference: string;
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
    method: 'cash',
    destination_reference: props.cash_destination_reference,
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
                Create a review request for {{ customer.name }} from one savings
                cycle.
            </p>
        </div>
        <Card v-if="!method_available"
            ><CardContent class="pt-6"
                ><p class="text-sm">
                    Requests are unavailable until a payout method has approved
                    execution, destination, custody, and evidence controls.
                </p></CardContent
            ></Card
        >
        <Card v-else
            ><CardHeader><CardTitle>Request terms</CardTitle></CardHeader
            ><CardContent class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="withdrawal-plan">Source cycle</Label
                    ><select
                        id="withdrawal-plan"
                        v-model="form.plan_id"
                        class="border-input bg-background h-11 rounded-md border px-3 text-sm"
                    >
                        <option
                            v-for="plan in plans"
                            :key="plan.id"
                            :value="plan.id"
                        >
                            {{ plan.id }} · {{ plan.status }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label for="withdrawal-type">Type</Label
                    ><select
                        id="withdrawal-type"
                        v-model="form.type"
                        class="border-input bg-background h-11 rounded-md border px-3 text-sm"
                    >
                        <option value="partial">Partial</option>
                        <option value="full">Full cycle savings</option>
                        <option value="end_of_cycle">End of cycle</option>
                    </select>
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
                    ><select
                        id="withdrawal-method"
                        v-model="form.method"
                        class="border-input bg-background h-11 rounded-md border px-3 text-sm"
                    >
                        <option value="cash">Cash</option>
                        <option value="bank_transfer">Bank transfer</option>
                    </select>
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="withdrawal-destination"
                        >Verified destination reference</Label
                    ><Input
                        id="withdrawal-destination"
                        v-model="form.destination_reference"
                        maxlength="200"
                    />
                </div>
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
                ><div class="grid gap-3 sm:grid-cols-3">
                    <p>
                        Gross savings debit<br /><strong>{{
                            money(quote.gross_kobo)
                        }}</strong>
                    </p>
                    <p>
                        Included fee<br /><strong>{{
                            money(quote.fee_kobo)
                        }}</strong>
                    </p>
                    <p>
                        Net payout<br /><strong>{{
                            money(quote.net_kobo)
                        }}</strong>
                    </p>
                </div>
                <p>
                    Current liability
                    {{ money(quote.position.liability_kobo) }} · reserved
                    {{ money(quote.position.reservations_kobo) }} · available
                    {{ money(quote.position.available_kobo) }}. Source cycle
                    available {{ money(quote.position.cycle_available_kobo) }}.
                </p>
                <p>
                    Destination {{ quote.destination_mask }}. This request
                    reserves gross savings for review; it does not pay the
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
