<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { show, settlement } from '@/routes/plans';
import { confirm } from '@/routes/plans/settlement';

const props = defineProps<{
    plan: { id: string; status: string };
    preview: {
        action: string;
        can_close: boolean;
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
            { title: 'Plan settlement', href: '#' },
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
function submit(): void {
    form.post(
        confirm.url({ plan: props.plan.id, action: props.preview.action }),
    );
}
</script>

<template>
    <div class="space-y-6">
        <Head title="Plan settlement" />
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">
                Plan settlement
            </h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                Review outstanding obligations before confirming a separate plan
                action.
            </p>
        </div>
        <nav aria-label="Settlement actions" class="flex flex-wrap gap-3">
            <Link
                :href="settlement.url(plan.id, { query: { action: 'close' } })"
                >Review closure</Link
            >
            <Link
                v-if="['active', 'paused'].includes(plan.status)"
                :href="
                    settlement.url(plan.id, {
                        query: { action: 'prepare_termination' },
                    })
                "
                >Prepare early termination</Link
            >
            <Link
                v-if="plan.status === 'closed'"
                :href="
                    settlement.url(plan.id, {
                        query: { action: 'resolve_exception' },
                    })
                "
                >Resolve settled exception</Link
            >
        </nav>
        <Card
            ><CardHeader
                ><CardTitle
                    >Authoritative settlement preview</CardTitle
                ></CardHeader
            ><CardContent class="space-y-4">
                <p>
                    Savings liability: ₦{{
                        (preview.position.cycle_liability_kobo / 100).toFixed(
                            2,
                        )
                    }}. Reserved savings: ₦{{
                        (
                            preview.position.cycle_reservations_kobo / 100
                        ).toFixed(2)
                    }}.
                </p>
                <ul
                    v-if="preview.blockers.length"
                    role="status"
                    class="list-inside list-disc"
                >
                    <li v-for="blocker in preview.blockers" :key="blocker">
                        {{ blocker }}
                    </li>
                </ul>
                <p v-else role="status">
                    Financial settlement gates are clear.
                </p>
                <p v-if="preview.action === 'prepare_termination'">
                    Preparation confirms the agreed fee outcome. It does not pay
                    or close the cycle.
                </p>
                <form class="grid gap-4" @submit.prevent="submit">
                    <div>
                        <Label for="settlement-reason">Internal reason</Label
                        ><textarea
                            id="settlement-reason"
                            v-model="form.reason"
                            required
                            maxlength="1000"
                            class="border-input mt-2 min-h-24 w-full rounded-md border p-3"
                        />
                    </div>
                    <div>
                        <Label for="settlement-explanation"
                            >Customer-facing explanation</Label
                        ><textarea
                            id="settlement-explanation"
                            v-model="form.customer_explanation"
                            required
                            maxlength="500"
                            class="border-input mt-2 min-h-24 w-full rounded-md border p-3"
                        />
                    </div>
                    <label class="flex items-center gap-2"
                        ><input v-model="form.confirmed" type="checkbox" />I
                        confirm this preview and the selected action.</label
                    >
                    <p
                        v-for="(error, key) in form.errors"
                        :key="key"
                        role="alert"
                        class="text-destructive"
                    >
                        {{ error }}
                    </p>
                    <Button
                        class="w-fit"
                        :disabled="
                            form.processing ||
                            !form.confirmed ||
                            (preview.action !== 'prepare_termination' &&
                                !preview.can_close)
                        "
                        >{{
                            form.processing ? 'Confirming…' : 'Confirm action'
                        }}</Button
                    >
                </form>
                <Link :href="show.url(plan.id)">Back to plan</Link>
            </CardContent></Card
        >
    </div>
</template>
