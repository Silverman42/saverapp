<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Coins } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import FormSheet from '@/components/FormSheet.vue';
import MoreDetails from '@/components/MoreDetails.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { preview as recoveryPreview } from '@/routes/cash-recoveries';
import CashRecoveryPanel from '@/components/CashRecoveryPanel.vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
    index,
    handoff,
    acknowledge,
    returnMethod as recordReturn,
} from '@/routes/cash-disbursements';
import { cash as startRefund } from '@/routes/fee-refunds';
import { start as startDraw } from '@/routes/earnings-draws';
import { store as authorizeRefund } from '@/routes/admin/fees/refunds';

type Execution = {
    execution_reference: string;
    kind: string;
    amount_kobo: number;
    status: string;
    recoveries: {
        recovery_reference: string;
        status: string;
        amount_kobo: number;
        event_type: string;
    }[];
    can_attest: boolean;
    can_acknowledge: boolean;
};
const props = defineProps<{
    executions: Execution[];
    refunds: { refund_reference: string; amount_kobo: number }[];
    refundable_obligations: {
        id: number;
        description: string;
        settled_kobo: number;
    }[];
    refund_enabled: boolean;
    can_refund: boolean;
    can_execute: boolean;
    can_draw: boolean;
    enabled: boolean;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Cash payouts', href: index() },
        ],
    },
});
const start = useForm({
    execution_reference: crypto.randomUUID(),
    amount_ngn: '',
    evidence: '',
    confirmed: false,
});
const refund = useForm({
    refund_reference: crypto.randomUUID(),
    kind: 'external',
    amount_ngn: '',
    reason: '',
    confirmed: false,
});
const obligation = ref('');
const refundReference = ref('');
const selectedReference = ref<string | null>(null);
const selected = computed(
    () =>
        props.executions.find(
            (execution) =>
                execution.execution_reference === selectedReference.value,
        ) ?? null,
);
const refundOpen = ref(false);
const startOpen = ref(false);
const attemptOpen = computed({
    get: () => selected.value !== null,
    set: (open: boolean) => {
        if (!open) selectedReference.value = null;
    },
});
const statusLabels: Record<string, string> = {
    processing: 'In progress',
    outcome_unknown: 'Waiting for receipt',
    posted: 'Paid',
    payment_failed: 'Not delivered',
};
const kindLabel = (kind: string): string =>
    ({ fee_refund: 'Fee refund', earnings_draw: 'Earnings draw' })[kind] ??
    kind.replaceAll('_', ' ');
const statusLabel = (status: string): string =>
    statusLabels[status] ?? status.replaceAll('_', ' ');
const proof = useForm({ evidence: '', delivered: true, confirmed: false });
const receipt = useForm({ confirmed: false });
function choose(execution: Execution): void {
    selectedReference.value = execution.execution_reference;
    proof.reset();
    receipt.reset();
}
function begin(draw: boolean): void {
    const onSuccess = (): void => {
        start.execution_reference = crypto.randomUUID();
        start.confirmed = false;
        refundReference.value = '';
        startOpen.value = false;
    };
    if (draw) start.post(startDraw.url(), { onSuccess });
    else
        start
            .transform(({ amount_ngn: _amount, ...data }) => data)
            .post(startRefund.url(refundReference.value), {
                onSuccess,
                onFinish: () => start.transform((data) => data),
            });
}
function record(delivered: boolean): void {
    if (!selected.value) return;
    proof.delivered = delivered;
    proof.post(handoff.url(selected.value.execution_reference));
}
function confirm(): void {
    if (selected.value)
        receipt.post(acknowledge.url(selected.value.execution_reference));
}
function entitlement(): void {
    refund.post(authorizeRefund.url(Number(obligation.value)), {
        onSuccess: () => {
            refund.refund_reference = crypto.randomUUID();
            refund.confirmed = false;
            refundOpen.value = false;
        },
    });
}
const money = (amount: number): string => `NGN ${(amount / 100).toFixed(2)}`;
</script>
<template>
    <div class="flex flex-col gap-6">
        <Head title="Cash payouts" />
        <PageHeader
            title="Cash payouts"
            description="Pay fee refunds and earnings in cash, then confirm receipt."
        >
            <template v-if="can_execute || can_refund" #actions>
                <Button
                    v-if="can_refund"
                    type="button"
                    :variant="can_execute ? 'outline' : 'default'"
                    @click="refundOpen = true"
                    >Approve refund</Button
                >
                <Button
                    v-if="can_execute"
                    type="button"
                    :disabled="!enabled"
                    @click="startOpen = true"
                    >Start payout</Button
                >
            </template>
        </PageHeader>
        <p
            v-if="!enabled"
            role="status"
            class="bg-muted rounded-xl p-4 text-sm"
        >
            Cash payouts are not switched on yet.
        </p>

        <EmptyState
            v-if="!executions.length"
            :icon="Coins"
            title="No cash payouts yet"
            description="Payouts you start will show up here."
        />
        <Card v-else class="py-2">
            <CardContent>
                <ul class="divide-border divide-y" aria-label="Cash payouts">
                    <li
                        v-for="execution in executions"
                        :key="execution.execution_reference"
                        class="flex flex-wrap items-center justify-between gap-3 py-4 text-sm"
                    >
                        <div class="min-w-0 space-y-1">
                            <p class="font-medium">
                                {{ kindLabel(execution.kind) }} ·
                                {{ money(execution.amount_kobo) }}
                            </p>
                            <Badge variant="secondary">{{
                                statusLabel(execution.status)
                            }}</Badge>
                        </div>
                        <Button
                            v-if="
                                [
                                    'processing',
                                    'outcome_unknown',
                                    'posted',
                                ].includes(execution.status)
                            "
                            variant="outline"
                            size="sm"
                            @click="choose(execution)"
                            >Open</Button
                        >
                    </li>
                </ul>
            </CardContent>
        </Card>

        <FormSheet
            v-model:open="refundOpen"
            title="Approve a fee refund"
            description="Refund a fee the customer has already paid."
        >
            <form
                id="refund-form"
                class="grid gap-5"
                @submit.prevent="entitlement"
            >
                <p
                    v-if="!refund_enabled"
                    role="status"
                    class="bg-muted rounded-xl p-4 text-sm"
                >
                    Fee refunds are not switched on yet.
                </p>
                <div class="grid gap-2">
                    <Label for="refund-obligation">Fee to refund</Label
                    ><Select v-model="obligation" required
                        ><SelectTrigger
                            id="refund-obligation"
                            class="h-11 w-full"
                            ><SelectValue
                                placeholder="Choose a paid fee" /></SelectTrigger
                        ><SelectContent
                            ><SelectItem
                                v-for="item in refundable_obligations"
                                :key="item.id"
                                :value="String(item.id)"
                                >{{ item.description }} · paid
                                {{ money(item.settled_kobo) }}</SelectItem
                            ></SelectContent
                        ></Select
                    >
                </div>
                <div class="grid gap-2">
                    <Label for="refund-kind">Refund to</Label
                    ><Select v-model="refund.kind"
                        ><SelectTrigger id="refund-kind" class="h-11 w-full"
                            ><SelectValue /></SelectTrigger
                        ><SelectContent
                            ><SelectItem value="external"
                                >Cash (fee was paid from outside
                                savings)</SelectItem
                            ><SelectItem value="savings"
                                >Savings (fee was paid from savings)</SelectItem
                            ></SelectContent
                        ></Select
                    >
                </div>
                <div class="grid gap-2">
                    <Label for="refund-amount">Amount (NGN)</Label
                    ><Input
                        id="refund-amount"
                        v-model="refund.amount_ngn"
                        required
                        inputmode="decimal"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="refund-purpose">Reason</Label
                    ><Input
                        id="refund-purpose"
                        v-model="refund.reason"
                        required
                        maxlength="500"
                    />
                </div>
                <label class="flex items-start gap-3 text-sm"
                    ><input
                        v-model="refund.confirmed"
                        type="checkbox"
                        class="mt-0.5"
                    />I confirm this refund comes from business earnings and
                    there is cash to cover it.</label
                >
                <div
                    v-if="Object.keys(refund.errors).length"
                    role="alert"
                    class="text-destructive grid gap-1 text-sm"
                >
                    <p v-for="(error, key) in refund.errors" :key="key">
                        {{ error }}
                    </p>
                </div>
            </form>
            <template #footer>
                <Button
                    type="button"
                    variant="outline"
                    @click="refundOpen = false"
                    >Cancel</Button
                >
                <Button
                    type="submit"
                    form="refund-form"
                    :disabled="
                        !refund_enabled ||
                        refund.processing ||
                        !refund.confirmed
                    "
                    >Approve refund</Button
                >
            </template>
        </FormSheet>

        <FormSheet
            v-model:open="startOpen"
            title="Start a cash payout"
            description="Pay an approved refund or draw business earnings."
        >
            <form
                id="start-form"
                class="grid gap-5"
                @submit.prevent="begin(false)"
            >
                <div class="grid gap-2">
                    <Label for="cash-refund">Approved refund</Label
                    ><Select v-model="refundReference"
                        ><SelectTrigger id="cash-refund" class="h-11 w-full"
                            ><SelectValue
                                placeholder="Choose an approved refund" /></SelectTrigger
                        ><SelectContent
                            ><SelectItem
                                v-for="item in refunds"
                                :key="item.refund_reference"
                                :value="item.refund_reference"
                                >{{ item.refund_reference }} ·
                                {{ money(item.amount_kobo) }}</SelectItem
                            ></SelectContent
                        ></Select
                    >
                </div>
                <div v-if="can_draw" class="grid gap-2">
                    <Label for="draw-amount"
                        >Or draw earnings: amount (NGN)</Label
                    ><Input
                        id="draw-amount"
                        v-model="start.amount_ngn"
                        inputmode="decimal"
                        aria-describedby="draw-amount-help"
                    />
                    <p
                        id="draw-amount-help"
                        class="text-muted-foreground text-xs"
                    >
                        Can't be more than earnings not yet drawn, or spare cash
                        after what the business owes.
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="disbursement-proof"
                        >Where the cash comes from and how you checked the
                        recipient</Label
                    ><Input
                        id="disbursement-proof"
                        v-model="start.evidence"
                        required
                        maxlength="1000"
                    />
                </div>
                <label class="flex items-start gap-3 text-sm"
                    ><input
                        v-model="start.confirmed"
                        type="checkbox"
                        class="mt-0.5"
                    />I confirm the amount and the recipient.</label
                >
                <div
                    v-if="Object.keys(start.errors).length"
                    role="alert"
                    class="text-destructive grid gap-1 text-sm"
                >
                    <p v-for="(error, key) in start.errors" :key="key">
                        {{ error }}
                    </p>
                </div>
            </form>
            <template #footer>
                <Button
                    v-if="can_draw"
                    type="button"
                    variant="outline"
                    :disabled="
                        !enabled ||
                        start.processing ||
                        !start.confirmed ||
                        !start.amount_ngn
                    "
                    @click="begin(true)"
                    >Draw earnings</Button
                >
                <Button
                    type="submit"
                    form="start-form"
                    :disabled="
                        !enabled ||
                        start.processing ||
                        !start.confirmed ||
                        !refundReference
                    "
                    >Pay refund</Button
                >
            </template>
        </FormSheet>

        <FormSheet
            v-model:open="attemptOpen"
            title="Cash payout"
            :description="
                selected
                    ? `${kindLabel(selected.kind)} · ${money(selected.amount_kobo)}`
                    : undefined
            "
        >
            <div v-if="selected" class="grid gap-6">
                <Badge variant="secondary">{{
                    statusLabel(selected.status)
                }}</Badge>
                <form
                    v-if="
                        selected.can_attest && selected.status === 'processing'
                    "
                    class="grid gap-4"
                    @submit.prevent="record(true)"
                >
                    <div class="grid gap-2">
                        <Label for="handoff-proof"
                            >How the cash was handed over</Label
                        ><Input
                            id="handoff-proof"
                            v-model="proof.evidence"
                            required
                            maxlength="1000"
                        />
                    </div>
                    <label class="flex items-start gap-3 text-sm"
                        ><input
                            v-model="proof.confirmed"
                            type="checkbox"
                            class="mt-0.5"
                        />I confirm what happened with this payout.</label
                    >
                    <div class="flex flex-wrap gap-3">
                        <Button :disabled="proof.processing || !proof.confirmed"
                            >Cash delivered</Button
                        ><Button
                            type="button"
                            variant="outline"
                            :disabled="proof.processing || !proof.confirmed"
                            @click="record(false)"
                            >Not delivered</Button
                        >
                    </div>
                    <div
                        v-if="Object.keys(proof.errors).length"
                        role="alert"
                        class="text-destructive grid gap-1 text-sm"
                    >
                        <p v-for="(error, key) in proof.errors" :key="key">
                            {{ error }}
                        </p>
                    </div>
                </form>
                <form
                    v-if="
                        selected.can_acknowledge &&
                        selected.status === 'outcome_unknown'
                    "
                    class="grid gap-4"
                    @submit.prevent="confirm"
                >
                    <label class="flex items-start gap-3 text-sm"
                        ><input
                            v-model="receipt.confirmed"
                            type="checkbox"
                            class="mt-0.5"
                        />I received exactly
                        {{ money(selected.amount_kobo) }} for this
                        payout.</label
                    ><Button
                        class="w-fit"
                        :disabled="receipt.processing || !receipt.confirmed"
                        >Confirm receipt</Button
                    >
                    <div
                        v-if="Object.keys(receipt.errors).length"
                        role="alert"
                        class="text-destructive grid gap-1 text-sm"
                    >
                        <p v-for="(error, key) in receipt.errors" :key="key">
                            {{ error }}
                        </p>
                    </div>
                </form>
                <CashRecoveryPanel
                    v-if="
                        ['posted', 'outcome_unknown'].includes(selected.status)
                    "
                    :key="selected.execution_reference"
                    :preview-url="
                        recoveryPreview.url({
                            kind: 'disbursement',
                            execution: selected.execution_reference,
                        })
                    "
                    :record-url="recordReturn.url(selected.execution_reference)"
                    :recoveries="selected.recoveries"
                    :can-record="selected.can_attest"
                    :can-confirm="selected.can_acknowledge"
                />
                <p class="text-muted-foreground text-sm">
                    If proof of handover is missing or disputed, the cash stays
                    set aside and no other payout can start.
                </p>
                <MoreDetails>
                    <p class="text-muted-foreground text-xs break-all">
                        Reference {{ selected.execution_reference }}
                    </p>
                </MoreDetails>
            </div>
        </FormSheet>
    </div>
</template>
