<script setup lang="ts">
import { Head, Link, useForm, usePoll } from '@inertiajs/vue3';
import { computed, ref, watchEffect } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { start as startCash } from '@/routes/withdrawals/cash';
import { start as startBank } from '@/routes/withdrawals/bank';
import { check as checkAttempt } from '@/routes/bank-payout-attempts';
import { preview as recoveryPreview } from '@/routes/cash-recoveries';
import CashRecoveryPanel from '@/components/CashRecoveryPanel.vue';
import { returnMethod as recordReturn } from '@/routes/cash-executions';
import { handoff, notDelivered, acknowledge } from '@/routes/cash-executions';
import {
    index as withdrawalsIndex,
    approve,
    reject,
    cancel,
    revoke,
} from '@/routes/withdrawals';

type Withdrawal = {
    id: string;
    customer_name: string;
    plan_id: string;
    type: string;
    gross_kobo: number;
    fee_kobo: number;
    net_kobo: number;
    deduction_kobo: number;
    method: string;
    destination_mask: string;
    state: string;
    held: boolean;
    hold_reason: string | null;
    submitted_at: string;
    deadline_at: string;
    reason: string;
    customer_explanation: string | null;
    internal_notes: string | null;
    version: number;
};
type BankAttempt = {
    reference: string;
    number: number;
    status: string;
    provider_outcome: string | null;
    failure_code: string | null;
    amount_kobo: number;
    initiated_at: string | null;
    provider_occurred_at: string | null;
    finalized_at: string | null;
    posted_at: string | null;
    occurred_on: string | null;
    settled_at: string | null;
};
const props = defineProps<{
    withdrawal: Withdrawal;
    can_execute_bank: boolean;
    timezone: string;
    bank_attempts: BankAttempt[];
    bank_returns: {
        reference: string;
        status: string;
        amount_kobo: number;
        recorded_at: string | null;
    }[];
    cash_recoveries: {
        recovery_reference: string;
        status: string;
        amount_kobo: number;
        event_type: string;
    }[];
    cash_recovery: {
        recovery_reference: string;
        status: string;
        amount_kobo: number;
    } | null;
    can_execute: boolean;
    is_customer: boolean;
    cash_execution: {
        execution_reference: string;
        status: string;
        amount_kobo: number;
    } | null;
    can_review: boolean;
    can_cancel: boolean;
    position: {
        liability_kobo: number;
        reservations_kobo: number;
        available_kobo: number;
        cycle_available_kobo: number;
    } | null;
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
const action = ref<'approve' | 'reject' | 'cancel' | 'revoke' | null>(null);
const form = useForm({
    attempt_reference: crypto.randomUUID(),
    version: props.withdrawal.version,
    confirmed: false,
    decision_note: '',
    internal_reason: '',
    customer_explanation: '',
});
const cashForm = useForm({
    execution_reference: crypto.randomUUID(),
    version: props.withdrawal.version,
    evidence: '',
    confirmed: false,
});
const bankForm = useForm({
    attempt_reference: crypto.randomUUID(),
    version: props.withdrawal.version,
    confirmed: false,
});
const checkForm = useForm({});
const poll = usePoll(
    10000,
    { only: ['withdrawal', 'bank_attempts', 'bank_returns'] },
    { autoStart: false },
);
const processing = computed(
    () =>
        !props.is_customer &&
        props.withdrawal.method === 'bank_transfer' &&
        ['payout_processing', 'outcome_unknown'].includes(
            props.withdrawal.state,
        ),
);
watchEffect(() => (processing.value ? poll.start() : poll.stop()));
function startTransfer(): void {
    bankForm.version = props.withdrawal.version;
    bankForm.post(startBank.url(props.withdrawal.id), {
        onSuccess: () => {
            bankForm.attempt_reference = crypto.randomUUID();
            bankForm.confirmed = false;
        },
    });
}
function checkNow(reference: string): void {
    checkForm.post(checkAttempt.url(reference));
}
function when(iso: string | null): string {
    return iso
        ? new Intl.DateTimeFormat('en-NG', {
              dateStyle: 'medium',
              timeStyle: 'short',
              timeZone: props.timezone,
          }).format(new Date(iso))
        : 'Not yet';
}
const handoffForm = useForm({ evidence: '', confirmed: false });
const acknowledgementForm = useForm({ confirmed: false });
function startPayment(): void {
    cashForm.version = props.withdrawal.version;
    cashForm.post(startCash.url(props.withdrawal.id));
}
function recordPayment(delivered: boolean): void {
    if (!props.cash_execution) return;
    handoffForm.post(
        (delivered ? handoff : notDelivered).url(
            props.cash_execution.execution_reference,
        ),
    );
}
function confirmReceipt(): void {
    if (!props.cash_execution) return;
    acknowledgementForm.post(
        acknowledge.url(props.cash_execution.execution_reference),
    );
}
const money = (kobo: number): string =>
    `₦${(kobo / 100).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
function choose(next: 'approve' | 'reject' | 'cancel' | 'revoke'): void {
    action.value = next;
    form.reset();
    form.attempt_reference = crypto.randomUUID();
    form.version = props.withdrawal.version;
}
function submit(): void {
    if (!action.value || !form.confirmed) return;
    const destination = { approve, reject, cancel, revoke }[action.value];
    form.post(destination.url(props.withdrawal.id), {
        onSuccess: () => {
            action.value = null;
        },
    });
}
</script>

<template>
    <Head :title="`Withdrawal ${withdrawal.id}`" />
    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">
                Withdrawal {{ withdrawal.id }}
            </h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                {{ withdrawal.customer_name }} · {{ withdrawal.plan_id }} ·
                {{ withdrawal.type.replaceAll('_', ' ') }}
            </p>
        </div>
        <Card
            ><CardHeader><CardTitle>Request state</CardTitle></CardHeader
            ><CardContent class="grid gap-2 text-sm"
                ><p>
                    {{ withdrawal.state.replaceAll('_', ' ')
                    }}<span v-if="withdrawal.held">
                        · On hold<span v-if="withdrawal.hold_reason"
                            >:
                            {{
                                withdrawal.hold_reason.replaceAll('_', ' ')
                            }}</span
                        ></span
                    >
                </p>
                <p>
                    Submitted {{ withdrawal.submitted_at }} · review deadline
                    {{ withdrawal.deadline_at }}
                </p>
                <p v-if="withdrawal.customer_explanation">
                    {{ withdrawal.customer_explanation }}
                </p>
                <p v-if="withdrawal.state === 'approved'">
                    Approval is recorded; payout has not been executed.
                </p></CardContent
            ></Card
        >
        <Card
            ><CardHeader
                ><CardTitle>Amounts and destination</CardTitle></CardHeader
            ><CardContent class="grid gap-4 text-sm sm:grid-cols-3"
                ><p>
                    Gross savings debit<br /><strong>{{
                        money(withdrawal.gross_kobo)
                    }}</strong>
                </p>
                <p>
                    Included withdrawal fee<br /><strong>{{
                        money(withdrawal.fee_kobo)
                    }}</strong>
                </p>
                <p>
                    Withdrawal deduction<br /><strong>{{
                        money(withdrawal.deduction_kobo)
                    }}</strong>
                </p>
                <p>
                    Net Customer payout<br /><strong>{{
                        money(withdrawal.net_kobo)
                    }}</strong>
                </p>
                <p>
                    Method<br /><strong>{{
                        withdrawal.method.replaceAll('_', ' ')
                    }}</strong>
                </p>
                <p>
                    Destination<br /><strong>{{
                        withdrawal.destination_mask
                    }}</strong>
                </p>
                <p>
                    Customer reason<br /><strong>{{
                        withdrawal.reason
                    }}</strong>
                </p>
                <p v-if="withdrawal.internal_notes" class="sm:col-span-3">
                    Internal notes: {{ withdrawal.internal_notes }}
                </p></CardContent
            ></Card
        >
        <Card
            ><CardHeader
                ><CardTitle>Current savings position</CardTitle></CardHeader
            ><CardContent
                v-if="position"
                class="grid gap-3 text-sm sm:grid-cols-4"
                ><p>
                    Posted liability<br /><strong>{{
                        money(position.liability_kobo)
                    }}</strong>
                </p>
                <p>
                    Live reservations<br /><strong>{{
                        money(position.reservations_kobo)
                    }}</strong>
                </p>
                <p>
                    Available savings<br /><strong>{{
                        money(position.available_kobo)
                    }}</strong>
                </p>
                <p>
                    Source cycle available<br /><strong>{{
                        money(position.cycle_available_kobo)
                    }}</strong>
                </p></CardContent
            ><CardContent v-else
                ><p class="text-muted-foreground text-sm">
                    The authoritative savings position is unavailable. Financial
                    decisions are blocked until it reconciles.
                </p></CardContent
            ></Card
        >
        <Card
            v-if="
                (can_review || can_cancel) &&
                ['pending_review', 'approved'].includes(withdrawal.state)
            "
            ><CardHeader><CardTitle>Next action</CardTitle></CardHeader
            ><CardContent class="grid gap-4"
                ><div class="flex flex-wrap gap-3">
                    <Button
                        v-if="
                            can_review &&
                            withdrawal.state === 'pending_review' &&
                            !withdrawal.held
                        "
                        type="button"
                        @click="choose('approve')"
                        >Approve request</Button
                    ><Button
                        v-if="
                            can_review && withdrawal.state === 'pending_review'
                        "
                        type="button"
                        variant="outline"
                        @click="choose('reject')"
                        >Reject request</Button
                    ><Button
                        v-if="
                            can_cancel && withdrawal.state === 'pending_review'
                        "
                        type="button"
                        variant="outline"
                        @click="choose('cancel')"
                        >Cancel request</Button
                    ><Button
                        v-if="can_review && withdrawal.state === 'approved'"
                        type="button"
                        variant="outline"
                        @click="choose('revoke')"
                        >Revoke approval</Button
                    >
                </div>
                <div v-if="action" class="grid gap-4 rounded-md border p-4">
                    <p class="font-medium">Confirm {{ action }}</p>
                    <p class="text-muted-foreground text-sm">
                        {{
                            action === 'approve'
                                ? 'Approval keeps the gross reservation. It does not prove payment.'
                                : 'This ends the unexecuted request and releases its gross reservation.'
                        }}
                    </p>
                    <div v-if="action === 'approve'" class="grid gap-2">
                        <Label for="decision-note">Decision note</Label
                        ><Input
                            id="decision-note"
                            v-model="form.decision_note"
                            maxlength="500"
                        />
                    </div>
                    <div v-else class="grid gap-2">
                        <Label for="internal-reason">Internal reason</Label
                        ><Input
                            id="internal-reason"
                            v-model="form.internal_reason"
                            maxlength="500"
                        />
                    </div>
                    <div
                        v-if="action === 'reject' || action === 'revoke'"
                        class="grid gap-2"
                    >
                        <Label for="customer-explanation"
                            >Customer explanation</Label
                        ><Input
                            id="customer-explanation"
                            v-model="form.customer_explanation"
                            maxlength="500"
                        />
                    </div>
                    <label class="flex gap-3 text-sm"
                        ><input v-model="form.confirmed" type="checkbox" /> I
                        confirm this decision.</label
                    ><Button
                        type="button"
                        class="w-fit"
                        :disabled="form.processing || !form.confirmed"
                        @click="submit"
                        >Commit decision</Button
                    >
                    <p
                        v-for="(error, key) in form.errors"
                        :key="key"
                        class="text-destructive text-sm"
                    >
                        {{ error }}
                    </p>
                </div></CardContent
            ></Card
        >
        <Card
            v-if="
                can_execute &&
                ['approved', 'payment_failed'].includes(withdrawal.state) &&
                !withdrawal.held
            "
        >
            <CardHeader><CardTitle>Start cash payment</CardTitle></CardHeader>
            <CardContent>
                <form class="grid gap-4" @submit.prevent="startPayment">
                    <Label for="cash-custody"
                        >Cash source and verified Customer identity</Label
                    >
                    <Input
                        id="cash-custody"
                        v-model="cashForm.evidence"
                        maxlength="500"
                        required
                    />
                    <label class="flex gap-3 text-sm"
                        ><input v-model="cashForm.confirmed" type="checkbox" />I
                        confirm the exact {{ money(withdrawal.net_kobo) }} cash
                        payment to this Customer.</label
                    >
                    <Button
                        class="w-fit"
                        :disabled="cashForm.processing || !cashForm.confirmed"
                        >Reserve cash and start</Button
                    >
                    <p
                        v-for="(error, key) in cashForm.errors"
                        :key="key"
                        class="text-destructive text-sm"
                    >
                        {{ error }}
                    </p>
                </form>
            </CardContent>
        </Card>
        <Card
            v-if="
                can_execute_bank &&
                ['approved', 'payment_failed'].includes(withdrawal.state) &&
                !withdrawal.held
            "
        >
            <CardHeader><CardTitle>Start bank transfer</CardTitle></CardHeader>
            <CardContent>
                <form class="grid gap-4" @submit.prevent="startTransfer">
                    <p class="text-sm">
                        Sends {{ money(withdrawal.net_kobo) }} to
                        <strong>{{ withdrawal.destination_mask }}</strong
                        >. The provider's confirmed result, not this click,
                        decides the outcome.
                    </p>
                    <label class="flex gap-3 text-sm"
                        ><input v-model="bankForm.confirmed" type="checkbox" />I
                        confirm the exact
                        {{ money(withdrawal.net_kobo) }} transfer to this
                        verified destination.</label
                    >
                    <Button
                        class="w-fit"
                        :disabled="bankForm.processing || !bankForm.confirmed"
                        >Start transfer</Button
                    >
                    <p
                        v-for="(error, key) in bankForm.errors"
                        :key="key"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ error }}
                    </p>
                </form>
            </CardContent>
        </Card>
        <Card v-if="bank_attempts.length > 0">
            <CardHeader
                ><CardTitle>Bank transfer history</CardTitle></CardHeader
            >
            <CardContent class="grid gap-4 text-sm" aria-live="polite">
                <p v-if="withdrawal.state === 'outcome_unknown'">
                    The provider has not given a final answer. Savings stay
                    reserved and no second transfer can be sent.
                </p>
                <div
                    v-for="attempt in bank_attempts"
                    :key="attempt.reference"
                    class="grid gap-1 rounded-md border p-3"
                >
                    <p>
                        <strong>Attempt {{ attempt.number }}</strong> ·
                        {{ attempt.status.replaceAll('_', ' ') }} ·
                        {{ money(attempt.amount_kobo) }}
                        <span v-if="attempt.failure_code"
                            >·
                            {{
                                attempt.failure_code.replaceAll('_', ' ')
                            }}</span
                        >
                    </p>
                    <p class="text-muted-foreground">
                        Started {{ when(attempt.initiated_at) }} · Provider
                        confirmed {{ when(attempt.provider_occurred_at) }} ·
                        Posted {{ when(attempt.posted_at)
                        }}<span v-if="attempt.occurred_on">
                            (occurred {{ attempt.occurred_on }})</span
                        >
                        · Settled {{ when(attempt.settled_at) }}
                    </p>
                    <Button
                        v-if="
                            can_execute_bank &&
                            [
                                'prepared',
                                'submitted',
                                'unknown',
                                'succeeded',
                            ].includes(attempt.status) &&
                            !attempt.posted_at
                        "
                        type="button"
                        variant="outline"
                        class="w-fit"
                        :disabled="checkForm.processing"
                        @click="checkNow(attempt.reference)"
                        >Check status now</Button
                    >
                </div>
                <div v-if="bank_returns.length > 0" class="grid gap-1">
                    <p class="font-medium">Provider returns and exceptions</p>
                    <p v-for="item in bank_returns" :key="item.reference">
                        {{ item.status }} · {{ money(item.amount_kobo) }} ·
                        {{ when(item.recorded_at) }}
                    </p>
                </div>
            </CardContent>
        </Card>
        <CashRecoveryPanel
            v-if="
                cash_execution &&
                ['posted', 'outcome_unknown'].includes(cash_execution.status)
            "
            :key="cash_execution.execution_reference"
            :preview-url="
                recoveryPreview.url({
                    kind: 'withdrawal',
                    execution: cash_execution.execution_reference,
                })
            "
            :record-url="recordReturn.url(cash_execution.execution_reference)"
            :recoveries="cash_recoveries"
            :can-record="can_execute"
            :can-confirm="is_customer"
        />
        <Link
            :href="withdrawalsIndex()"
            class="text-primary w-fit text-sm underline"
            >Back to withdrawals</Link
        >
    </div>
</template>
