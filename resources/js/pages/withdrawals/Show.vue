<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { start as startCash } from '@/routes/withdrawals/cash';
import { returnMethod as recordReturn } from '@/routes/cash-executions';
import { acknowledge as acknowledgeReturn } from '@/routes/cash-recoveries';
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
    method: string;
    destination_mask: string;
    state: string;
    held: boolean;
    submitted_at: string;
    deadline_at: string;
    reason: string;
    customer_explanation: string | null;
    internal_notes: string | null;
    version: number;
};
const props = defineProps<{
    withdrawal: Withdrawal;
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
const recoveryForm = useForm({
    recovery_reference: crypto.randomUUID(),
    evidence: '',
    confirmed: false,
});
const returnAcknowledgement = useForm({ confirmed: false });
function returnCash(): void {
    if (!props.cash_execution) return;
    recoveryForm.post(
        recordReturn.url(props.cash_execution.execution_reference),
    );
}
function confirmReturn(): void {
    if (!props.cash_recovery) return;
    returnAcknowledgement.post(
        acknowledgeReturn.url(props.cash_recovery.recovery_reference),
    );
}
const cashForm = useForm({
    execution_reference: crypto.randomUUID(),
    version: props.withdrawal.version,
    evidence: '',
    confirmed: false,
});
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
                    }}<span v-if="withdrawal.held"> · On hold</span>
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
                cash_execution &&
                ['processing', 'outcome_unknown'].includes(
                    cash_execution.status,
                )
            "
        >
            <CardHeader
                ><CardTitle
                    >Cash handoff
                    {{ cash_execution.execution_reference }}</CardTitle
                ></CardHeader
            >
            <CardContent class="grid gap-4">
                <p class="text-sm">
                    {{ money(cash_execution.amount_kobo) }} is reserved. A
                    claimed handoff requires the Customer's confirmation before
                    financial posting.
                </p>
                <form
                    v-if="can_execute && cash_execution.status === 'processing'"
                    class="grid gap-4"
                    @submit.prevent="recordPayment(true)"
                >
                    <Label for="handoff-evidence"
                        >Contemporaneous handoff or non-delivery record</Label
                    >
                    <Input
                        id="handoff-evidence"
                        v-model="handoffForm.evidence"
                        maxlength="500"
                        required
                    />
                    <label class="flex gap-3 text-sm"
                        ><input
                            v-model="handoffForm.confirmed"
                            type="checkbox"
                        />I confirm this custody record.</label
                    >
                    <div class="flex flex-wrap gap-3">
                        <Button
                            :disabled="
                                handoffForm.processing || !handoffForm.confirmed
                            "
                            >Record cash handoff</Button
                        >
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="
                                handoffForm.processing || !handoffForm.confirmed
                            "
                            @click="recordPayment(false)"
                            >Confirm no cash was handed over</Button
                        >
                    </div>
                    <p
                        v-for="(error, key) in handoffForm.errors"
                        :key="key"
                        class="text-destructive text-sm"
                    >
                        {{ error }}
                    </p>
                </form>
                <form
                    v-if="
                        is_customer &&
                        cash_execution.status === 'outcome_unknown'
                    "
                    class="grid gap-4"
                    @submit.prevent="confirmReceipt"
                >
                    <label class="flex gap-3 text-sm"
                        ><input
                            v-model="acknowledgementForm.confirmed"
                            type="checkbox"
                        />I personally received exactly
                        {{ money(cash_execution.amount_kobo) }} for this
                        withdrawal.</label
                    >
                    <Button
                        class="w-fit"
                        :disabled="
                            acknowledgementForm.processing ||
                            !acknowledgementForm.confirmed
                        "
                        >Confirm cash received</Button
                    >
                    <p
                        v-for="(error, key) in acknowledgementForm.errors"
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
                cash_execution &&
                ['posted', 'outcome_unknown'].includes(cash_execution.status)
            "
        >
            <CardHeader><CardTitle>Full cash return</CardTitle></CardHeader>
            <CardContent class="grid gap-4">
                <p class="text-sm">
                    Partial or disputed returns remain unresolved. A full return
                    of {{ money(cash_execution.amount_kobo) }} requires both the
                    original custodian's evidence and the Customer's
                    confirmation.
                </p>
                <form
                    v-if="can_execute && !cash_recovery"
                    class="grid gap-3"
                    @submit.prevent="returnCash"
                >
                    <Label for="cash-return-evidence"
                        >Verified full cash return to controlled custody</Label
                    ><Input
                        id="cash-return-evidence"
                        v-model="recoveryForm.evidence"
                        required
                        maxlength="1000"
                    />
                    <label class="flex items-center gap-2 text-sm"
                        ><input
                            v-model="recoveryForm.confirmed"
                            type="checkbox"
                        />I counted the complete original net amount back into
                        controlled cash.</label
                    >
                    <Button
                        :disabled="
                            recoveryForm.processing || !recoveryForm.confirmed
                        "
                        >Record full return</Button
                    >
                    <p
                        v-for="(error, key) in recoveryForm.errors"
                        :key="key"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ error }}
                    </p>
                </form>
                <form
                    v-if="
                        is_customer &&
                        cash_recovery?.status === 'awaiting_customer'
                    "
                    class="grid gap-3"
                    @submit.prevent="confirmReturn"
                >
                    <label class="flex items-center gap-2 text-sm"
                        ><input
                            v-model="returnAcknowledgement.confirmed"
                            type="checkbox"
                        />I personally returned exactly
                        {{ money(cash_recovery.amount_kobo) }} from this cash
                        attempt.</label
                    >
                    <Button
                        :disabled="
                            returnAcknowledgement.processing ||
                            !returnAcknowledgement.confirmed
                        "
                        >Confirm full cash return</Button
                    >
                    <p
                        v-for="(error, key) in returnAcknowledgement.errors"
                        :key="key"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ error }}
                    </p>
                </form>
                <p
                    v-if="cash_recovery?.status === 'confirmed'"
                    class="text-muted-foreground text-sm"
                >
                    Full return evidence is confirmed. The original posted
                    payout remains effective until an authorized correction is
                    approved.
                </p>
            </CardContent>
        </Card>
        <Link
            :href="withdrawalsIndex()"
            class="text-primary w-fit text-sm underline"
            >Back to withdrawals</Link
        >
    </div>
</template>
