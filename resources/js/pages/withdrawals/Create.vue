<script setup lang="ts">
import FeeQuoteSummary from "@/components/FeeQuoteSummary.vue";
import MoreDetails from "@/components/MoreDetails.vue";
import PageHeader from "@/components/PageHeader.vue";
import type { FeeDisclosure } from "@/types/fee-disclosure";
import { HttpResponseError } from "@inertiajs/core";
import { Head, Link, useForm, useHttp } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import { dashboard } from "@/routes";
import {
    index as withdrawalsIndex,
    show as showWithdrawal,
} from "@/routes/withdrawals";
import { index as payoutDestinations } from "@/routes/customers/payout-destinations";
import {
    preview as previewWithdrawal,
    store as storeWithdrawal,
} from "@/routes/customers/withdrawals";

type Quote = {
    preview_fingerprint: string;
    quote_expires_at: string;
    customer_version: number;
    assignment_version: number;
    plan_version: number | null;
    business_version: number;
    gross_kobo: number;
    fee_kobo: number;
    fee_disclosure: FeeDisclosure | null;
    deduction_kobo: number;
    deduction_description: string | null;
    net_kobo: number;
    position: {
        liability_kobo: number;
        reservations_kobo: number;
        available_kobo: number;
        cycle_available_kobo?: number;
    };
    destination_mask: string;
};
const props = defineProps<{
    customer: { id: string; name: string };
    plans: Array<{
        id: string;
        status: string;
        live_withdrawal_id: string | null;
        cycle_available_kobo: number | null;
    }>;
    methods: string[];
    cash_destination_reference: string;
    bank_destinations: Array<{ reference: string; label: string }>;
    wallet: {
        active: boolean;
        available_kobo: number | null;
        destination_reference: string;
        methods: string[];
        live_payout_id: string | null;
    };
    initial_source: "plan" | "wallet";
}>();
const source = ref<"plan" | "wallet">(props.initial_source);
const isWalletPayout = computed(() => source.value === "wallet");
const isWalletTransfer = computed(
    () => source.value === "plan" && props.wallet.active,
);
const payoutMethods = computed(() =>
    isWalletPayout.value ? props.wallet.methods : props.methods,
);
function destinationFor(method: string): string {
    if (method === "wallet") return props.wallet.destination_reference;
    return method === "bank_transfer"
        ? (props.bank_destinations[0]?.reference ?? "")
        : props.cash_destination_reference;
}
function defaultMethod(): string {
    return payoutMethods.value[0] ?? "cash";
}
defineOptions({
    layout: {
        breadcrumbs: [
            { title: "Dashboard", href: dashboard() },
            { title: "Withdrawals", href: withdrawalsIndex() },
            { title: "Request", href: "#" },
        ],
    },
});
const form = useForm({
    attempt_reference: crypto.randomUUID(),
    source: source.value as string,
    plan_id: (isWalletPayout.value ? null : (props.plans[0]?.id ?? "")) as
        string | null,
    type: isWalletPayout.value ? "wallet_payout" : "partial",
    gross_ngn: "",
    method: defaultMethod(),
    destination_reference: destinationFor(defaultMethod()),
    reason: "",
    internal_notes: "",
    preview_fingerprint: "",
    quote_expires_at: "",
    customer_version: 0,
    assignment_version: 0,
    plan_version: 0 as number | null,
    business_version: 0,
    instruction_attested: false,
    confirmed: false,
});
watch(source, (value) => {
    form.source = value;
    form.plan_id = value === "wallet" ? null : (props.plans[0]?.id ?? "");
    form.type = value === "wallet" ? "wallet_payout" : "partial";
    form.gross_ngn = "";
    form.method = defaultMethod();
    form.destination_reference = destinationFor(form.method);
});
const previewHttp = useHttp({
    source: "",
    plan_id: null as string | null,
    type: "",
    gross_ngn: "",
    method: "",
    destination_reference: "",
    reason: "",
    internal_notes: "",
});
const quote = ref<Quote | null>(null);
const previewFailure = ref<string | null>(null);
const liveWithdrawalId = computed(
    () =>
        props.plans.find((plan) => plan.id === form.plan_id)
            ?.live_withdrawal_id ?? null,
);
const reviewOpen = ref(false);
const money = (kobo: number): string =>
    `₦${(kobo / 100).toLocaleString("en-NG", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const selectedCycleKobo = computed(
    () =>
        props.plans.find((plan) => plan.id === form.plan_id)
            ?.cycle_available_kobo ?? null,
);
const amountIsFixed = computed(
    () =>
        (form.type === "full" || form.type === "end_of_cycle") &&
        selectedCycleKobo.value !== null,
);
watch(
    () => [form.type, form.plan_id],
    (_, [previousType]) => {
        if (amountIsFixed.value) {
            form.gross_ngn = ((selectedCycleKobo.value ?? 0) / 100).toFixed(2);
        } else if (previousType !== "partial" && form.type === "partial") {
            form.gross_ngn = "";
        }
    },
);
watch(
    () => form.method,
    (method) => {
        form.destination_reference = destinationFor(method);
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
        previewFailure.value = null;
        form.confirmed = false;
    },
);
async function review(): Promise<void> {
    previewHttp.source = form.source;
    previewHttp.plan_id = form.plan_id;
    previewHttp.type = form.type;
    previewHttp.gross_ngn = form.gross_ngn;
    previewHttp.method = form.method;
    previewHttp.destination_reference = form.destination_reference;
    previewHttp.reason = form.reason;
    previewHttp.internal_notes = form.internal_notes;
    previewFailure.value = null;
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
    } catch (error) {
        quote.value = null;
        previewFailure.value = failureMessage(error);
    }
}
function failureMessage(error: unknown): string | null {
    const fallback = "The quote could not be loaded. Try again.";
    if (!(error instanceof HttpResponseError)) {
        return fallback;
    }
    if (error.response.status === 422) {
        return null;
    }
    try {
        const data: unknown = JSON.parse(error.response.data);
        if (
            data &&
            typeof data === "object" &&
            "message" in data &&
            typeof data.message === "string" &&
            data.message !== ""
        ) {
            return data.message;
        }
    } catch {
        return fallback;
    }
    return fallback;
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
            :description="
                wallet.active
                    ? `Move ${customer.name}'s plan savings to their wallet, or pay out from the wallet.`
                    : `Ask for a withdrawal from ${customer.name}'s savings.`
            "
        />
        <div
            v-if="wallet.active"
            class="flex max-w-3xl flex-wrap gap-2"
            role="radiogroup"
            aria-label="Take money from"
        >
            <Button
                type="button"
                :variant="source === 'plan' ? 'default' : 'outline'"
                role="radio"
                :aria-checked="source === 'plan'"
                @click="source = 'plan'"
                >Savings plan to wallet</Button
            >
            <Button
                type="button"
                :variant="source === 'wallet' ? 'default' : 'outline'"
                role="radio"
                :aria-checked="source === 'wallet'"
                @click="source = 'wallet'"
                >Pay out from wallet</Button
            >
            <p
                v-if="isWalletPayout"
                class="text-muted-foreground w-full text-sm"
            >
                Available in wallet:
                <span class="text-foreground font-medium">{{
                    wallet.available_kobo === null
                        ? "Unavailable"
                        : money(wallet.available_kobo)
                }}</span>
                <template v-if="wallet.live_payout_id">
                    · A wallet payout is already in progress.
                    <Link
                        :href="showWithdrawal.url(wallet.live_payout_id)"
                        class="text-foreground underline"
                        >View {{ wallet.live_payout_id }}</Link
                    >
                </template>
            </p>
        </div>
        <p
            v-if="payoutMethods.length === 0"
            role="status"
            class="bg-muted rounded-xl p-4 text-sm"
        >
            You can't request withdrawals yet. No payout method is set up.
        </p>
        <Card v-else class="max-w-3xl">
            <CardHeader
                ><CardTitle>{{
                    isWalletPayout
                        ? "Wallet payout"
                        : isWalletTransfer
                          ? "Move savings to wallet"
                          : "Withdrawal details"
                }}</CardTitle></CardHeader
            >
            <CardContent>
                <form
                    class="grid gap-5 sm:grid-cols-2"
                    @submit.prevent="review"
                >
                    <div v-if="!isWalletPayout" class="grid gap-2">
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
                        <p
                            v-if="liveWithdrawalId"
                            role="status"
                            class="text-muted-foreground text-sm"
                        >
                            This plan already has a withdrawal in progress.
                            <Link
                                :href="showWithdrawal.url(liveWithdrawalId)"
                                class="text-foreground underline"
                                >View {{ liveWithdrawalId }}</Link
                            >
                        </p>
                    </div>
                    <div v-if="!isWalletPayout" class="grid gap-2">
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
                            :readonly="amountIsFixed"
                            aria-describedby="withdrawal-gross-help"
                        />
                        <p
                            id="withdrawal-gross-help"
                            class="text-muted-foreground text-xs"
                        >
                            <template
                                v-if="
                                    amountIsFixed && selectedCycleKobo !== null
                                "
                                >Filled in with all savings in this cycle ({{
                                    money(selectedCycleKobo)
                                }}).
                            </template>
                            Any fees come out of this amount.
                        </p>
                    </div>
                    <p
                        v-if="isWalletTransfer"
                        class="text-muted-foreground text-sm sm:col-span-2"
                    >
                        The money moves to the customer's wallet straight away.
                        Any plan fee comes out first. Pay the customer from the
                        wallet afterwards.
                    </p>
                    <div v-if="!isWalletTransfer" class="grid gap-2">
                        <Label for="withdrawal-method">Pay by</Label
                        ><Select v-model="form.method"
                            ><SelectTrigger
                                id="withdrawal-method"
                                class="h-11 w-full"
                                ><SelectValue /></SelectTrigger
                            ><SelectContent
                                ><SelectItem
                                    v-if="payoutMethods.includes('cash')"
                                    value="cash"
                                    >Cash</SelectItem
                                ><SelectItem
                                    v-if="
                                        payoutMethods.includes('bank_transfer')
                                    "
                                    value="bank_transfer"
                                    >Bank transfer</SelectItem
                                ><SelectItem
                                    v-if="payoutMethods.includes('offline')"
                                    value="offline"
                                    >Paid outside platform</SelectItem
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
                        v-else-if="form.method === 'offline'"
                        class="text-muted-foreground text-sm sm:col-span-2"
                    >
                        Staff pay the customer by hand or by a bank transfer
                        outside the app, then record the payment here. The
                        customer does not need to confirm.
                    </p>
                    <p
                        v-else-if="form.method === 'cash'"
                        class="text-muted-foreground text-sm sm:col-span-2"
                    >
                        The customer collects the cash in person, then confirms
                        they got it.
                    </p>
                    <p
                        v-if="form.type === 'full'"
                        class="text-muted-foreground text-sm sm:col-span-2"
                    >
                        Taking all savings before the cycle ends closes this
                        page. The next page starts fresh with its own service
                        charge.
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
                            previewFailure ||
                            Object.keys(previewHttp.errors).length ||
                            (!reviewOpen && Object.keys(form.errors).length)
                        "
                        role="alert"
                        class="text-destructive grid gap-1 text-sm sm:col-span-2"
                    >
                        <p v-if="previewFailure">{{ previewFailure }}</p>
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
                    <DialogDescription v-if="isWalletTransfer">
                        Submitting moves this money to the customer's wallet
                        now.
                    </DialogDescription>
                    <DialogDescription v-else>
                        Submitting sets this money aside while it is reviewed.
                        The customer is not paid yet.
                    </DialogDescription>
                </DialogHeader>
                <div v-if="quote" class="grid gap-5 text-sm">
                    <div class="bg-muted/40 rounded-xl p-4">
                        <p class="text-muted-foreground">
                            {{
                                isWalletTransfer
                                    ? "Wallet receives"
                                    : "Customer receives"
                            }}
                        </p>
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
                                {{
                                    isWalletPayout
                                        ? "Taken from wallet"
                                        : "Taken from savings"
                                }}
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
                                v-if="quote.fee_disclosure"
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
                                <div
                                    v-if="
                                        quote.position.cycle_available_kobo !==
                                        undefined
                                    "
                                >
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
                        >{{
                            isWalletTransfer
                                ? "Move to wallet"
                                : "Submit request"
                        }}</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
