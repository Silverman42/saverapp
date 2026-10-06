<script setup lang="ts">
import { Head, useForm, usePoll } from '@inertiajs/vue3';
import { computed, ref, watchEffect } from 'vue';
import MoreDetails from '@/components/MoreDetails.vue';
import PageHeader from '@/components/PageHeader.vue';
import WithdrawalStatusBadge from '@/components/WithdrawalStatusBadge.vue';
import { Badge } from '@/components/ui/badge';
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
    approved_at: string | null;
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
const cashOpen = ref(false);
const bankOpen = ref(false);
const decisionOpen = computed({
    get: () => action.value !== null,
    set: (open: boolean) => {
        if (!open) action.value = null;
    },
});
const canPayOut = computed(
    () =>
        ['approved', 'payment_failed'].includes(props.withdrawal.state) &&
        !props.withdrawal.held,
);
const decisionCopy = {
    approve: {
        title: 'Approve this withdrawal?',
        description:
            'The money stays set aside for payout. Approving does not pay the customer.',
        button: 'Approve',
    },
    reject: {
        title: 'Reject this withdrawal?',
        description:
            'This stops the request and frees up the money that was set aside.',
        button: 'Reject',
    },
    cancel: {
        title: 'Cancel this withdrawal?',
        description:
            'This stops the request and frees up the money that was set aside.',
        button: 'Cancel request',
    },
    revoke: {
        title: 'Revoke the approval?',
        description:
            'This stops the request and frees up the money that was set aside.',
        button: 'Revoke approval',
    },
} as const;
function startTransfer(): void {
    bankForm.version = props.withdrawal.version;
    bankForm.post(startBank.url(props.withdrawal.id), {
        onSuccess: () => {
            bankForm.attempt_reference = crypto.randomUUID();
            bankForm.confirmed = false;
            bankOpen.value = false;
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
    cashForm.post(startCash.url(props.withdrawal.id), {
        onSuccess: () => {
            cashOpen.value = false;
        },
    });
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
        <PageHeader
            :title="`Withdrawal ${withdrawal.id}`"
            :description="`${withdrawal.customer_name} · ${withdrawal.plan_id} · ${withdrawal.type.replaceAll('_', ' ')}`"
        >
            <template
                v-if="
                    (can_review || can_cancel) &&
                    ['pending_review', 'approved'].includes(withdrawal.state)
                "
                #actions
            >
                <Button
                    v-if="
                        can_review &&
                        withdrawal.state === 'pending_review' &&
                        !withdrawal.held
                    "
                    type="button"
                    @click="choose('approve')"
                    >Approve</Button
                ><Button
                    v-if="can_review && withdrawal.state === 'pending_review'"
                    type="button"
                    variant="outline"
                    @click="choose('reject')"
                    >Reject</Button
                ><Button
                    v-if="can_cancel && withdrawal.state === 'pending_review'"
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
            </template>
        </PageHeader>

        <Card>
            <CardContent class="grid gap-6">
                <div class="flex flex-wrap items-center gap-2">
                    <WithdrawalStatusBadge :state="withdrawal.state" />
                    <Badge v-if="withdrawal.held" variant="outline"
                        >On hold<template v-if="withdrawal.hold_reason"
                            >:
                            {{
                                withdrawal.hold_reason.replaceAll('_', ' ')
                            }}</template
                        ></Badge
                    >
                </div>
                <p
                    v-if="withdrawal.state === 'approved'"
                    class="text-muted-foreground -mt-3 text-sm"
                >
                    Approved. The customer has not been paid yet.
                </p>
                <p
                    v-if="withdrawal.customer_explanation"
                    class="bg-muted rounded-xl p-4 text-sm"
                >
                    {{ withdrawal.customer_explanation }}
                </p>
                <div
                    class="grid gap-6 sm:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]"
                >
                    <div class="bg-muted/40 rounded-xl p-4">
                        <p class="text-muted-foreground text-sm">
                            Customer receives
                        </p>
                        <p class="text-2xl font-semibold">
                            {{ money(withdrawal.net_kobo) }}
                        </p>
                        <p class="text-muted-foreground mt-1 text-sm">
                            {{ withdrawal.method.replaceAll('_', ' ') }} ·
                            {{ withdrawal.destination_mask }}
                        </p>
                    </div>
                    <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-muted-foreground">
                                Taken from savings
                            </dt>
                            <dd class="font-medium">
                                {{ money(withdrawal.gross_kobo) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                Fees and charges
                            </dt>
                            <dd class="font-medium">
                                {{ money(withdrawal.fee_kobo) }} fee ·
                                {{ money(withdrawal.deduction_kobo) }} charge
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Submitted</dt>
                            <dd class="font-medium">
                                {{ withdrawal.submitted_at }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                {{
                                    withdrawal.approved_at
                                        ? 'Approved'
                                        : 'Review by'
                                }}
                            </dt>
                            <dd class="font-medium">
                                {{
                                    withdrawal.approved_at
                                        ? when(withdrawal.approved_at)
                                        : withdrawal.deadline_at
                                }}
                            </dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="text-muted-foreground">Reason</dt>
                            <dd class="font-medium">{{ withdrawal.reason }}</dd>
                        </div>
                    </dl>
                </div>
                <p
                    v-if="!position"
                    role="status"
                    class="bg-muted rounded-xl p-4 text-sm"
                >
                    The savings balance can't be shown right now. Decisions are
                    paused until it is checked.
                </p>
                <MoreDetails v-else label="Savings balance and notes">
                    <div class="grid gap-4 text-sm">
                        <dl class="grid gap-3 sm:grid-cols-4">
                            <div>
                                <dt class="text-muted-foreground">
                                    Total savings
                                </dt>
                                <dd class="font-medium">
                                    {{ money(position.liability_kobo) }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">Set aside</dt>
                                <dd class="font-medium">
                                    {{ money(position.reservations_kobo) }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">Available</dt>
                                <dd class="font-medium">
                                    {{ money(position.available_kobo) }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">
                                    Available in this plan
                                </dt>
                                <dd class="font-medium">
                                    {{ money(position.cycle_available_kobo) }}
                                </dd>
                            </div>
                        </dl>
                        <dl class="grid gap-3 sm:grid-cols-2">
                            <div v-if="withdrawal.approved_at">
                                <dt class="text-muted-foreground">
                                    Review deadline
                                </dt>
                                <dd>{{ withdrawal.deadline_at }}</dd>
                            </div>
                            <div v-if="withdrawal.internal_notes">
                                <dt class="text-muted-foreground">
                                    Private note
                                </dt>
                                <dd>{{ withdrawal.internal_notes }}</dd>
                            </div>
                        </dl>
                    </div>
                </MoreDetails>
            </CardContent>
        </Card>

        <Card
            v-if="(can_execute || can_execute_bank) && canPayOut"
            aria-labelledby="payout-title"
        >
            <CardHeader
                class="flex flex-row flex-wrap items-center justify-between gap-3"
            >
                <div>
                    <CardTitle id="payout-title">Pay the customer</CardTitle>
                    <p class="text-muted-foreground mt-1.5 text-sm">
                        Ready to pay {{ money(withdrawal.net_kobo) }}.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="can_execute"
                        type="button"
                        @click="cashOpen = true"
                        >Pay cash</Button
                    >
                    <Button
                        v-if="can_execute_bank"
                        type="button"
                        :variant="can_execute ? 'outline' : 'default'"
                        @click="bankOpen = true"
                        >Send transfer</Button
                    >
                </div>
            </CardHeader>
        </Card>

        <Card v-if="bank_attempts.length > 0">
            <CardHeader><CardTitle>Bank transfers</CardTitle></CardHeader>
            <CardContent class="grid gap-4 text-sm" aria-live="polite">
                <p
                    v-if="withdrawal.state === 'outcome_unknown'"
                    class="bg-muted rounded-xl p-4"
                >
                    The bank has not confirmed this transfer yet. The money
                    stays set aside, and no second transfer can be sent.
                </p>
                <ul class="divide-border divide-y">
                    <li
                        v-for="attempt in bank_attempts"
                        :key="attempt.reference"
                        class="grid gap-2 py-3"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-3"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium"
                                    >Attempt {{ attempt.number }}</span
                                >
                                <Badge variant="secondary">{{
                                    attempt.status.replaceAll('_', ' ')
                                }}</Badge>
                                <span>{{ money(attempt.amount_kobo) }}</span>
                            </div>
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
                                size="sm"
                                :disabled="checkForm.processing"
                                @click="checkNow(attempt.reference)"
                                >Check status</Button
                            >
                        </div>
                        <p v-if="attempt.failure_code" class="text-destructive">
                            {{ attempt.failure_code.replaceAll('_', ' ') }}
                        </p>
                        <p class="text-muted-foreground">
                            Started {{ when(attempt.initiated_at) }}
                        </p>
                        <MoreDetails label="Details">
                            <dl
                                class="text-muted-foreground grid gap-1 text-xs"
                            >
                                <div>
                                    Bank confirmed
                                    {{ when(attempt.provider_occurred_at) }}
                                </div>
                                <div>
                                    Recorded {{ when(attempt.posted_at)
                                    }}<span v-if="attempt.occurred_on">
                                        (happened on
                                        {{ attempt.occurred_on }})</span
                                    >
                                </div>
                                <div>
                                    Settled {{ when(attempt.settled_at) }}
                                </div>
                                <div>Reference {{ attempt.reference }}</div>
                            </dl>
                        </MoreDetails>
                    </li>
                </ul>
                <div v-if="bank_returns.length > 0" class="grid gap-2">
                    <p class="font-medium">Returned transfers</p>
                    <ul class="divide-border divide-y">
                        <li
                            v-for="item in bank_returns"
                            :key="item.reference"
                            class="flex flex-wrap justify-between gap-3 py-2"
                        >
                            <span>{{ item.status }}</span>
                            <span
                                >{{ money(item.amount_kobo) }} ·
                                {{ when(item.recorded_at) }}</span
                            >
                        </li>
                    </ul>
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

        <Dialog v-model:open="decisionOpen">
            <DialogContent class="sm:max-w-md">
                <form v-if="action" class="grid gap-5" @submit.prevent="submit">
                    <DialogHeader>
                        <DialogTitle>{{
                            decisionCopy[action].title
                        }}</DialogTitle>
                        <DialogDescription>{{
                            decisionCopy[action].description
                        }}</DialogDescription>
                    </DialogHeader>
                    <div v-if="action === 'approve'" class="grid gap-2">
                        <Label for="decision-note">Note</Label
                        ><Input
                            id="decision-note"
                            v-model="form.decision_note"
                            maxlength="500"
                        />
                    </div>
                    <div v-else class="grid gap-2">
                        <Label for="internal-reason">Reason (staff only)</Label
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
                            >Message to the customer</Label
                        ><Input
                            id="customer-explanation"
                            v-model="form.customer_explanation"
                            maxlength="500"
                        />
                    </div>
                    <label class="flex items-center gap-3 text-sm"
                        ><input v-model="form.confirmed" type="checkbox" /> I
                        confirm this decision.</label
                    >
                    <div
                        v-if="Object.keys(form.errors).length"
                        role="alert"
                        class="text-destructive grid gap-1 text-sm"
                    >
                        <p v-for="(error, key) in form.errors" :key="key">
                            {{ error }}
                        </p>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="decisionOpen = false"
                            >Go back</Button
                        >
                        <Button
                            type="submit"
                            :variant="
                                action === 'approve' ? 'default' : 'destructive'
                            "
                            :disabled="form.processing || !form.confirmed"
                            >{{ decisionCopy[action].button }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="cashOpen">
            <DialogContent class="sm:max-w-md">
                <form class="grid gap-5" @submit.prevent="startPayment">
                    <DialogHeader>
                        <DialogTitle>Pay cash</DialogTitle>
                        <DialogDescription>
                            Pay {{ money(withdrawal.net_kobo) }} in cash to
                            {{ withdrawal.customer_name }}.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-2">
                        <Label for="cash-custody"
                            >Where the cash comes from and how you checked the
                            customer's ID</Label
                        >
                        <Input
                            id="cash-custody"
                            v-model="cashForm.evidence"
                            maxlength="500"
                            required
                        />
                    </div>
                    <label class="flex items-start gap-3 text-sm"
                        ><input
                            v-model="cashForm.confirmed"
                            type="checkbox"
                            class="mt-0.5"
                        />I confirm I am paying exactly
                        {{ money(withdrawal.net_kobo) }} in cash to this
                        customer.</label
                    >
                    <div
                        v-if="Object.keys(cashForm.errors).length"
                        role="alert"
                        class="text-destructive grid gap-1 text-sm"
                    >
                        <p v-for="(error, key) in cashForm.errors" :key="key">
                            {{ error }}
                        </p>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="cashOpen = false"
                            >Go back</Button
                        >
                        <Button
                            type="submit"
                            :disabled="
                                cashForm.processing || !cashForm.confirmed
                            "
                            >Start cash payment</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="bankOpen">
            <DialogContent class="sm:max-w-md">
                <form class="grid gap-5" @submit.prevent="startTransfer">
                    <DialogHeader>
                        <DialogTitle>Send bank transfer</DialogTitle>
                        <DialogDescription>
                            Send {{ money(withdrawal.net_kobo) }} to
                            {{ withdrawal.destination_mask }}. It is only
                            complete once the bank confirms it.
                        </DialogDescription>
                    </DialogHeader>
                    <label class="flex items-start gap-3 text-sm"
                        ><input
                            v-model="bankForm.confirmed"
                            type="checkbox"
                            class="mt-0.5"
                        />I confirm sending exactly
                        {{ money(withdrawal.net_kobo) }} to this checked
                        account.</label
                    >
                    <div
                        v-if="Object.keys(bankForm.errors).length"
                        role="alert"
                        class="text-destructive grid gap-1 text-sm"
                    >
                        <p v-for="(error, key) in bankForm.errors" :key="key">
                            {{ error }}
                        </p>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="bankOpen = false"
                            >Go back</Button
                        >
                        <Button
                            type="submit"
                            :disabled="
                                bankForm.processing || !bankForm.confirmed
                            "
                            >Send transfer</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
