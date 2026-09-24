<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { index as reversalsIndex, cancel, reject } from '@/routes/reversals';

type Reversal = {
    id: string;
    customer_name: string | null;
    original_reference: string;
    original_amount_kobo: number;
    state: string;
    requested_at: string;
    reviewed_at: string | null;
    version: number;
    customer_explanation: string | null;
    internal_reason: string | null;
    evidence_text: string | null;
    dependency_snapshot: {
        summary: Record<string, unknown>;
        dependencies: Array<Record<string, unknown>>;
    } | null;
};

const props = defineProps<{
    reversal: Reversal;
    can_review: boolean;
    can_cancel: boolean;
    can_approve: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Reversals', href: reversalsIndex() },
            { title: 'Request', href: '#' },
        ],
    },
});

const action = ref<'cancel' | 'reject' | null>(null);
const form = useForm({
    attempt_reference: crypto.randomUUID(),
    version: props.reversal.version,
    decision_reason: '',
    confirmed: false,
});

const money = (kobo: number): string =>
    `₦${(kobo / 100).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

function choose(next: 'cancel' | 'reject'): void {
    action.value = next;
    form.reset();
    form.attempt_reference = crypto.randomUUID();
    form.version = props.reversal.version;
}

function submit(): void {
    if (!action.value || !form.confirmed) return;
    form.post(
        (action.value === 'cancel' ? cancel : reject).url(props.reversal.id),
        {
            onSuccess: () => {
                action.value = null;
            },
        },
    );
}
</script>

<template>
    <Head :title="`Reversal ${reversal.id}`" />
    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">
                Reversal {{ reversal.id }}
            </h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                {{ reversal.customer_name }} · original
                {{ reversal.original_reference }}
            </p>
        </div>
        <Card>
            <CardHeader><CardTitle>Request state</CardTitle></CardHeader>
            <CardContent class="grid gap-2 text-sm">
                <p>{{ reversal.state.replaceAll('_', ' ') }}</p>
                <p>
                    Original amount {{ money(reversal.original_amount_kobo) }}
                </p>
                <p>Requested {{ reversal.requested_at }}</p>
                <p v-if="reversal.reviewed_at">
                    Decided {{ reversal.reviewed_at }}
                </p>
                <p v-if="reversal.customer_explanation">
                    {{ reversal.customer_explanation }}
                </p>
                <p
                    v-if="reversal.state === 'pending_review'"
                    class="text-muted-foreground"
                >
                    The original transaction is still effective. No correction
                    has posted.
                </p>
            </CardContent>
        </Card>
        <Card v-if="reversal.internal_reason || reversal.evidence_text">
            <CardHeader><CardTitle>Staff evidence</CardTitle></CardHeader>
            <CardContent class="grid gap-2 text-sm">
                <p v-if="reversal.internal_reason">
                    {{ reversal.internal_reason }}
                </p>
                <p v-if="reversal.evidence_text">
                    {{ reversal.evidence_text }}
                </p>
            </CardContent>
        </Card>
        <Card v-if="can_review || can_cancel">
            <CardHeader><CardTitle>Next action</CardTitle></CardHeader>
            <CardContent class="grid gap-4">
                <p
                    v-if="!can_approve && can_review"
                    class="text-muted-foreground text-sm"
                >
                    Approval is unavailable until the complete compensation
                    contract is verified.
                </p>
                <div class="flex gap-3">
                    <Button
                        v-if="can_review"
                        type="button"
                        variant="outline"
                        @click="choose('reject')"
                        >Reject request</Button
                    >
                    <Button
                        v-if="can_cancel"
                        type="button"
                        variant="outline"
                        @click="choose('cancel')"
                        >Cancel request</Button
                    >
                </div>
                <form v-if="action" class="grid gap-3" @submit.prevent="submit">
                    <Label for="reversal-decision-reason">{{
                        action === 'reject'
                            ? 'Rejection reason'
                            : 'Cancellation reason'
                    }}</Label>
                    <Input
                        id="reversal-decision-reason"
                        v-model="form.decision_reason"
                        required
                        maxlength="500"
                    />
                    <p
                        v-if="form.errors.decision_reason"
                        class="text-destructive text-sm"
                    >
                        {{ form.errors.decision_reason }}
                    </p>
                    <label class="flex items-center gap-2 text-sm"
                        ><input v-model="form.confirmed" type="checkbox" />
                        Confirm {{ action }}</label
                    >
                    <Button
                        type="submit"
                        :disabled="form.processing || !form.confirmed"
                        >Save decision</Button
                    >
                </form>
            </CardContent>
        </Card>
        <Link
            :href="reversalsIndex()"
            class="text-primary w-fit text-sm underline"
            >Back to reversals</Link
        >
    </div>
</template>
