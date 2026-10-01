<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { preview as recoveryPreview } from '@/routes/cash-recoveries';
import CashRecoveryPanel from '@/components/CashRecoveryPanel.vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
            { title: 'Cash payments', href: index() },
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
        },
    });
}
const money = (amount: number): string => `NGN ${(amount / 100).toFixed(2)}`;
</script>
<template>
    <div class="flex flex-col gap-6">
        <Head title="Cash refunds and earnings draws" />
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">
                Cash refunds and earnings draws
            </h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                Entitlements, controlled cash handoff and receipt confirmation
                are separate steps.
            </p>
        </div>
        <p v-if="!enabled" class="text-muted-foreground text-sm">
            Cash handoff awaits integrated acceptance.
        </p>
        <Card v-if="can_refund"
            ><CardHeader
                ><CardTitle>Authorize a fee concession</CardTitle></CardHeader
            ><CardContent>
                <form class="grid max-w-xl gap-3" @submit.prevent="entitlement">
                    <Label for="refund-obligation">Original paid fee</Label
                    ><select
                        id="refund-obligation"
                        v-model="obligation"
                        required
                        class="border-input rounded-md border p-2"
                    >
                        <option value="">Choose retained paid fee</option>
                        <option
                            v-for="item in refundable_obligations"
                            :key="item.id"
                            :value="String(item.id)"
                        >
                            {{ item.description }} · paid
                            {{ money(item.settled_kobo) }}
                        </option>
                    </select>
                    <Label for="refund-kind">Refund destination</Label
                    ><select
                        id="refund-kind"
                        v-model="refund.kind"
                        class="border-input rounded-md border p-2"
                    >
                        <option value="external">
                            External receipt: cash payable
                        </option>
                        <option value="savings">
                            Savings-funded fee: restore savings
                        </option>
                    </select>
                    <Label for="refund-amount">Amount (NGN)</Label
                    ><Input
                        id="refund-amount"
                        v-model="refund.amount_ngn"
                        required
                        inputmode="decimal"
                    />
                    <Label for="refund-purpose">Concession reason</Label
                    ><Input
                        id="refund-purpose"
                        v-model="refund.reason"
                        required
                        maxlength="500"
                    />
                    <p
                        v-for="(error, key) in refund.errors"
                        :key="key"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ error }}
                    </p>
                    <label class="flex items-center gap-2 text-sm"
                        ><input
                            v-model="refund.confirmed"
                            type="checkbox"
                        />Confirm a concession of retained earnings, backed by
                        free cash</label
                    ><Button
                        :disabled="
                            !refund_enabled ||
                            refund.processing ||
                            !refund.confirmed
                        "
                        >Authorize entitlement</Button
                    >
                </form>
            </CardContent></Card
        >
        <Card v-if="can_execute"
            ><CardHeader><CardTitle>Start a cash payment</CardTitle></CardHeader
            ><CardContent>
                <form
                    class="grid max-w-xl gap-3"
                    @submit.prevent="begin(false)"
                >
                    <Label for="cash-refund">Approved refund payable</Label
                    ><select
                        id="cash-refund"
                        v-model="refundReference"
                        class="border-input rounded-md border p-2"
                    >
                        <option value="">Choose approved payable</option>
                        <option
                            v-for="item in refunds"
                            :key="item.refund_reference"
                            :value="item.refund_reference"
                        >
                            {{ item.refund_reference }} ·
                            {{ money(item.amount_kobo) }}
                        </option>
                    </select>
                    <Label for="disbursement-proof"
                        >Controlled cash source and recipient
                        verification</Label
                    ><Input
                        id="disbursement-proof"
                        v-model="start.evidence"
                        required
                        maxlength="1000"
                    />
                    <template v-if="can_draw"
                        ><Label for="draw-amount"
                            >Business earnings draw amount (NGN)</Label
                        ><Input
                            id="draw-amount"
                            v-model="start.amount_ngn"
                            inputmode="decimal"
                        />
                        <p class="text-muted-foreground text-sm">
                            Draws require both permissions and cannot exceed
                            undrawn earnings or free cash after liabilities and
                            encumbrances.
                        </p></template
                    >
                    <p
                        v-for="(error, key) in start.errors"
                        :key="key"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ error }}
                    </p>
                    <label class="flex items-center gap-2 text-sm"
                        ><input
                            v-model="start.confirmed"
                            type="checkbox"
                        />Confirm the exact amount and recipient</label
                    >
                    <div class="flex flex-wrap gap-3">
                        <Button
                            :disabled="
                                !enabled ||
                                start.processing ||
                                !start.confirmed ||
                                !refundReference
                            "
                            >Start refund cash handoff</Button
                        ><Button
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
                            >Start business earnings draw</Button
                        >
                    </div>
                </form>
            </CardContent></Card
        >
        <Card
            ><CardHeader><CardTitle>Cash attempts</CardTitle></CardHeader
            ><CardContent class="grid gap-3">
                <p
                    v-if="!executions.length"
                    class="text-muted-foreground text-sm"
                >
                    No cash attempts in your current scope.
                </p>
                <div
                    v-for="execution in executions"
                    :key="execution.execution_reference"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-md border p-3 text-sm"
                >
                    <div>
                        <p>
                            {{ execution.kind.replaceAll('_', ' ') }} ·
                            {{ money(execution.amount_kobo) }} ·
                            {{ execution.status.replaceAll('_', ' ') }}
                        </p>
                        <p class="text-muted-foreground break-all">
                            {{ execution.execution_reference }}
                        </p>
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
                        @click="choose(execution)"
                        >Open exact attempt</Button
                    >
                </div>
                <div v-if="selected" class="grid gap-3 rounded-md border p-4">
                    <p class="text-sm">
                        {{ selected.execution_reference }} ·
                        {{ money(selected.amount_kobo) }}
                    </p>
                    <form
                        v-if="
                            selected.can_attest &&
                            selected.status === 'processing'
                        "
                        class="grid gap-3"
                        @submit.prevent="record(true)"
                    >
                        <Label for="handoff-proof"
                            >Custodian handoff evidence</Label
                        ><Input
                            id="handoff-proof"
                            v-model="proof.evidence"
                            required
                            maxlength="1000"
                        /><label class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="proof.confirmed"
                                type="checkbox"
                            />Confirm the recorded result for this exact
                            attempt</label
                        >
                        <div class="flex flex-wrap gap-3">
                            <Button
                                :disabled="proof.processing || !proof.confirmed"
                                >Record cash delivered</Button
                            ><Button
                                type="button"
                                variant="outline"
                                :disabled="proof.processing || !proof.confirmed"
                                @click="record(false)"
                                >Confirm definitive non-delivery</Button
                            >
                        </div>
                        <p
                            v-for="(error, key) in proof.errors"
                            :key="key"
                            role="alert"
                            class="text-destructive text-sm"
                        >
                            {{ error }}
                        </p>
                    </form>
                    <form
                        v-if="
                            selected.can_acknowledge &&
                            selected.status === 'outcome_unknown'
                        "
                        class="grid gap-3"
                        @submit.prevent="confirm"
                    >
                        <label class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="receipt.confirmed"
                                type="checkbox"
                            />I personally received exactly
                            {{ money(selected.amount_kobo) }} for this
                            attempt</label
                        ><Button
                            :disabled="receipt.processing || !receipt.confirmed"
                            >Confirm receipt and post</Button
                        >
                        <p
                            v-for="(error, key) in receipt.errors"
                            :key="key"
                            role="alert"
                            class="text-destructive text-sm"
                        >
                            {{ error }}
                        </p>
                    </form>
                    <CashRecoveryPanel
                        v-if="
                            ['posted', 'outcome_unknown'].includes(
                                selected.status,
                            )
                        "
                        :key="selected.execution_reference"
                        :preview-url="
                            recoveryPreview.url({
                                kind: 'disbursement',
                                execution: selected.execution_reference,
                            })
                        "
                        :record-url="
                            recordReturn.url(selected.execution_reference)
                        "
                        :recoveries="selected.recoveries"
                        :can-record="selected.can_attest"
                        :can-confirm="selected.can_acknowledge"
                    />
                    <p class="text-muted-foreground text-sm">
                        Missing or disputed handoff proof preserves this cash
                        reservation and blocks another payment.
                    </p>
                </div>
            </CardContent></Card
        >
    </div>
</template>
