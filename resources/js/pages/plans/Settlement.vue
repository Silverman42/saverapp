<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { ArrowLeft, CircleCheck } from '@lucide/vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { index as plansIndex, show, settlement } from '@/routes/plans';
import { confirm } from '@/routes/plans/settlement';

const props = defineProps<{
    plan: { id: string; status: string };
    preview: {
        action: string;
        can_close: boolean;
        can_prepare: boolean;
        preparation_blockers: string[];
        termination_fee: {
            description: string | null;
            principal_kobo: number;
            target_kobo: number;
            assessment_delta_kobo: number;
            unpaid_after_preparation_kobo: number;
            insufficient_savings: boolean;
        } | null;
        blockers: string[];
        preview_fingerprint: string;
        position: {
            cycle_liability_kobo: number;
            cycle_reservations_kobo: number;
        };
    };
    attempt_reference: string;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Plans', href: plansIndex() },
            { title: 'Settlement', href: '#' },
        ],
    },
});
const form = useForm({
    attempt_reference: props.attempt_reference,
    preview_fingerprint: props.preview.preview_fingerprint,
    reason: '',
    customer_explanation: '',
    confirmed: false,
});
const actionOptions = computed(() => [
    { value: 'close', label: 'Close plan', visible: true },
    {
        value: 'prepare_termination',
        label: 'End early',
        visible: ['active', 'paused'].includes(props.plan.status),
    },
    {
        value: 'resolve_exception',
        label: 'Resolve issue',
        visible: props.plan.status === 'closed',
    },
]);
const submitLabels: Record<string, string> = {
    close: 'Close plan',
    prepare_termination: 'Confirm early end',
    resolve_exception: 'Resolve issue',
};
const submitLabel = computed(
    () => submitLabels[props.preview.action] ?? 'Confirm',
);
const canSubmit = computed(() =>
    props.preview.action === 'prepare_termination'
        ? props.preview.can_prepare
        : props.preview.can_close,
);
function naira(kobo: number): string {
    return `₦${(kobo / 100).toLocaleString('en-NG', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;
}
function submit(): void {
    form.post(
        confirm.url({ plan: props.plan.id, action: props.preview.action }),
    );
}
</script>

<template>
    <div class="mx-auto w-full max-w-3xl space-y-6">
        <Head title="Plan settlement" />
        <PageHeader
            title="Settle plan"
            description="Check what is owed, then close or end the plan."
        />
        <nav
            aria-label="Settlement actions"
            class="bg-muted/40 inline-flex w-fit flex-wrap gap-1 rounded-xl p-1"
        >
            <template v-for="option in actionOptions" :key="option.value">
                <Link
                    v-if="option.visible"
                    :href="
                        settlement.url(plan.id, {
                            query: { action: option.value },
                        })
                    "
                    :aria-current="
                        preview.action === option.value ? 'page' : undefined
                    "
                    class="focus-visible:ring-ring rounded-lg px-3 py-1.5 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:outline-none"
                    :class="
                        preview.action === option.value
                            ? 'bg-card text-foreground shadow-sm'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    >{{ option.label }}</Link
                >
            </template>
        </nav>

        <Card>
            <CardHeader><CardTitle>Where things stand</CardTitle></CardHeader>
            <CardContent class="space-y-5">
                <dl class="grid gap-3 sm:grid-cols-2">
                    <div class="bg-muted/40 rounded-xl p-4">
                        <dt class="text-muted-foreground text-sm">
                            Savings balance
                        </dt>
                        <dd class="mt-1 text-lg font-semibold">
                            {{ naira(preview.position.cycle_liability_kobo) }}
                        </dd>
                    </div>
                    <div class="bg-muted/40 rounded-xl p-4">
                        <dt class="text-muted-foreground text-sm">
                            Set aside for withdrawals
                        </dt>
                        <dd class="mt-1 text-lg font-semibold">
                            {{
                                naira(preview.position.cycle_reservations_kobo)
                            }}
                        </dd>
                    </div>
                </dl>

                <div v-if="preview.termination_fee" class="space-y-3 text-sm">
                    <p v-if="preview.termination_fee.description">
                        {{ preview.termination_fee.description }}
                    </p>
                    <dl class="divide-y rounded-xl border">
                        <div class="flex justify-between gap-4 p-3">
                            <dt class="text-muted-foreground">
                                Paid in this cycle, before withdrawals
                            </dt>
                            <dd class="font-medium">
                                {{
                                    naira(
                                        preview.termination_fee.principal_kobo,
                                    )
                                }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4 p-3">
                            <dt class="text-muted-foreground">Early end fee</dt>
                            <dd class="font-medium">
                                {{ naira(preview.termination_fee.target_kobo) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4 p-3">
                            <dt class="text-muted-foreground">
                                Fee still unpaid after this step
                            </dt>
                            <dd class="font-medium">
                                {{
                                    naira(
                                        preview.termination_fee
                                            .unpaid_after_preparation_kobo,
                                    )
                                }}
                            </dd>
                        </div>
                    </dl>
                    <p
                        v-if="preview.termination_fee.insufficient_savings"
                        role="status"
                        class="text-sm text-amber-700 dark:text-amber-400"
                    >
                        The savings are not enough to pay this fee. To close the
                        plan, record a fee payment or waive the fee.
                    </p>
                </div>

                <div
                    v-if="preview.preparation_blockers.length"
                    role="status"
                    class="bg-muted/40 rounded-xl p-4 text-sm"
                >
                    <p class="font-medium">Fix these first</p>
                    <ul class="mt-2 list-inside list-disc space-y-1">
                        <li
                            v-for="blocker in preview.preparation_blockers"
                            :key="blocker"
                        >
                            {{ blocker }}
                        </li>
                    </ul>
                </div>
                <div
                    v-if="preview.blockers.length"
                    role="status"
                    class="bg-muted/40 rounded-xl p-4 text-sm"
                >
                    <p class="font-medium">
                        {{
                            preview.action === 'prepare_termination'
                                ? 'Before the plan can be closed'
                                : 'Fix these first'
                        }}
                    </p>
                    <ul class="mt-2 list-inside list-disc space-y-1">
                        <li v-for="blocker in preview.blockers" :key="blocker">
                            {{ blocker }}
                        </li>
                    </ul>
                </div>
                <p v-else role="status" class="flex items-center gap-2 text-sm">
                    <CircleCheck class="text-primary size-4" />
                    Nothing is blocking this.
                </p>
                <p
                    v-if="preview.action === 'prepare_termination'"
                    class="text-muted-foreground text-sm"
                >
                    This step only confirms the early end fee. It does not pay
                    anything or close the plan.
                </p>
            </CardContent>
        </Card>

        <Card>
            <CardHeader><CardTitle>Confirm</CardTitle></CardHeader>
            <CardContent>
                <form class="grid gap-5" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="settlement-reason"
                            >Reason
                            <span class="text-muted-foreground font-normal"
                                >(staff only)</span
                            ></Label
                        ><textarea
                            id="settlement-reason"
                            v-model="form.reason"
                            required
                            maxlength="1000"
                            class="border-input bg-background focus-visible:ring-ring/30 min-h-24 w-full rounded-xl border px-3 py-2 text-sm shadow-sm outline-none focus-visible:ring-2"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="settlement-explanation"
                            >Message for the customer</Label
                        ><textarea
                            id="settlement-explanation"
                            v-model="form.customer_explanation"
                            required
                            maxlength="500"
                            class="border-input bg-background focus-visible:ring-ring/30 min-h-24 w-full rounded-xl border px-3 py-2 text-sm shadow-sm outline-none focus-visible:ring-2"
                        />
                    </div>
                    <div
                        class="bg-muted/40 flex items-start gap-3 rounded-xl p-4"
                    >
                        <Checkbox
                            id="settlement-confirmed"
                            v-model="form.confirmed"
                        />
                        <Label for="settlement-confirmed" class="leading-5"
                            >I have checked the amounts above and want to
                            continue.</Label
                        >
                    </div>
                    <p
                        v-for="(error, key) in form.errors"
                        :key="key"
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        {{ error }}
                    </p>
                    <div
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <Button as-child variant="ghost"
                            ><Link :href="show.url(plan.id)"
                                ><ArrowLeft class="size-4" />Back to plan</Link
                            ></Button
                        >
                        <Button
                            type="submit"
                            :disabled="
                                form.processing || !form.confirmed || !canSubmit
                            "
                            >{{
                                form.processing ? 'Saving…' : submitLabel
                            }}</Button
                        >
                    </div>
                </form>
            </CardContent>
        </Card>
    </div>
</template>
