<script setup lang="ts">
import ManagementDeliveryPanel from '@/components/ManagementDeliveryPanel.vue';
import { Head, Link, router, useHttp } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { dashboard } from '@/routes';
import { index, show } from '@/routes/customers';
import { preview, store, operation } from '@/routes/customers/reassignment';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    CardDescription,
} from '@/components/ui/card';
const props = defineProps<{
    customer: {
        reference: string;
        name: string;
        status: string;
        account_state: string;
        version: number;
        assignment_version: number;
        agent_name: string | null;
    };
    agents: Array<{ id: number; reference: string; name: string }>;
}>();
type Preview = {
    version: number;
    assignment_version: number;
    preview_token: string;
    pending_withdrawals: number;
    pending_reversals: number;
    pending_recovery: boolean;
    name_proposals: number;
    message: string;
};
const review = ref<Preview | null>(null);
const message = ref('');
const key = `reassignment-reference:${props.customer.reference}`;
const retained =
    typeof window !== 'undefined' ? sessionStorage.getItem(key) : null;
const uncertain = ref(!!retained);
const canRetryOriginal = ref(false);
const form = useHttp({
    attempt_reference: retained ?? crypto.randomUUID(),
    target_agent_id: props.agents[0]?.id ?? 0,
    version: props.customer.version,
    assignment_version: props.customer.assignment_version,
    preview_token: '',
    reason: '',
    customer_explanation: '',
    confirmed: false,
});
const lookup = useHttp({});
watch(
    () => form.target_agent_id,
    () => {
        review.value = null;
        form.confirmed = false;
    },
);
async function loadPreview(): Promise<void> {
    message.value = '';
    try {
        review.value = (await form.post(
            preview.url(props.customer.reference),
        )) as Preview;
        form.preview_token = review.value.preview_token;
        form.version = review.value.version;
        form.assignment_version = review.value.assignment_version;
    } catch {
        review.value = null;
        message.value =
            'Preview unavailable. Refresh and review current eligibility.';
    }
}
async function submit(retryOriginal = false): Promise<void> {
    if (!review.value || !form.confirmed || (uncertain.value && !retryOriginal))
        return;
    canRetryOriginal.value = true;
    sessionStorage.setItem(key, form.attempt_reference);
    uncertain.value = true;
    try {
        const result = (await form.post(
            store.url(props.customer.reference),
        )) as { status: string };
        if (!result || form.hasErrors) {
            uncertain.value = false;
            sessionStorage.removeItem(key);
            message.value = 'Review the validation errors before confirming.';
            return;
        }
        sessionStorage.removeItem(key);
        uncertain.value = false;
        message.value =
            result.status === 'unchanged'
                ? 'The current assignment is unchanged.'
                : 'Customer reassignment committed.';
        form.attempt_reference = crypto.randomUUID();
        review.value = null;
        router.reload();
    } catch (error) {
        const status = (error as { response?: { status?: number } }).response
            ?.status;
        if (status && status >= 400 && status < 500) {
            uncertain.value = false;
            sessionStorage.removeItem(key);
        }
        message.value = uncertain.value
            ? 'The outcome is uncertain. Check the original reference before submitting again.'
            : 'Reassignment was not committed. Review the errors and refresh the preview.';
    }
}
async function resolveOutcome(): Promise<void> {
    try {
        const result = (await lookup.get(
            operation.url({
                customer: props.customer.reference,
                attempt_reference: form.attempt_reference,
            }),
        )) as { status: string };
        message.value = `Original operation: ${result.status}.`;
        uncertain.value = false;
        sessionStorage.removeItem(key);
        form.attempt_reference = crypto.randomUUID();
        review.value = null;
        router.reload();
    } catch {
        message.value =
            'No committed result could be confirmed. Retain this reference and check again before creating another operation.';
    }
}
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Customers', href: index() },
            { title: 'Reassign Customer', href: '#' },
        ],
    },
});
</script>
<template>
    <div class="mx-auto max-w-3xl space-y-6">
        <Head title="Reassign Customer" />
        <header>
            <h1 class="text-[25px] font-medium tracking-tight">
                Reassign Customer
            </h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                {{ customer.name }} · {{ customer.reference }}
            </p>
        </header>
        <Card
            ><CardHeader
                ><CardTitle>Current service relationship</CardTitle
                ><CardDescription
                    >Customer participation and account access remain
                    separate.</CardDescription
                ></CardHeader
            ><CardContent
                ><dl class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <dt class="text-muted-foreground text-sm">
                            Current Agent
                        </dt>
                        <dd>{{ customer.agent_name ?? 'Unavailable' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-sm">
                            Customer status
                        </dt>
                        <dd>{{ customer.status }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-sm">
                            Account access
                        </dt>
                        <dd>{{ customer.account_state }}</dd>
                    </div>
                </dl></CardContent
            ></Card
        >
        <p
            v-if="message"
            role="status"
            aria-live="polite"
            class="rounded-lg border p-4 text-sm"
        >
            {{ message }}
        </p>
        <Card v-if="uncertain"
            ><CardHeader
                ><CardTitle>Verify the original outcome</CardTitle></CardHeader
            ><CardContent class="space-y-4"
                ><p class="text-sm break-all">
                    Reference: {{ form.attempt_reference }}
                </p>
                <Button :disabled="lookup.processing" @click="resolveOutcome"
                    >Check operation</Button
                >
                <Button
                    v-if="canRetryOriginal"
                    variant="outline"
                    :disabled="form.processing"
                    @click="submit(true)"
                    >Retry original operation</Button
                ></CardContent
            ></Card
        >
        <Card v-else
            ><CardContent class="pt-6"
                ><form class="space-y-5" @submit.prevent="submit()">
                    <div>
                        <Label for="replacement">Replacement Agent</Label
                        ><select
                            id="replacement"
                            v-model="form.target_agent_id"
                            class="border-input bg-background mt-2 h-11 w-full rounded-md border px-3"
                        >
                            <option
                                v-for="agent in agents"
                                :key="agent.id"
                                :value="agent.id"
                            >
                                {{ agent.name }} · {{ agent.reference }}
                            </option>
                        </select>
                        <p
                            v-if="!agents.length"
                            class="text-muted-foreground mt-2 text-sm"
                        >
                            No eligible replacement Agent is available.
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="form.processing || !agents.length"
                        @click="loadPreview"
                        >Review handover</Button
                    >
                    <div>
                        <Label for="reason">Internal reason</Label
                        ><Input
                            id="reason"
                            v-model="form.reason"
                            required
                            maxlength="500"
                            class="mt-2"
                        />
                    </div>
                    <div>
                        <Label for="explanation"
                            >Customer-facing explanation</Label
                        ><textarea
                            id="explanation"
                            v-model="form.customer_explanation"
                            required
                            maxlength="500"
                            class="border-input bg-background mt-2 min-h-24 w-full rounded-md border p-3"
                        />
                    </div>
                    <div
                        v-if="review"
                        class="bg-muted space-y-3 rounded-lg p-4 text-sm"
                    >
                        <p>{{ review.message }}</p>
                        <p>
                            Pending withdrawals:
                            {{ review.pending_withdrawals }} · Corrections:
                            {{ review.pending_reversals }} · Recovery:
                            {{ review.pending_recovery ? 'Present' : 'None' }} ·
                            Name proposals: {{ review.name_proposals }}
                        </p>
                        <label class="flex items-start gap-3"
                            ><input
                                v-model="form.confirmed"
                                type="checkbox"
                                class="mt-1"
                            />I confirm the immediate access change and
                            handover.</label
                        >
                    </div>
                    <ul
                        v-if="form.hasErrors"
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        <li v-for="(error, field) in form.errors" :key="field">
                            {{ error }}
                        </li>
                    </ul>
                    <div class="flex flex-wrap gap-3">
                        <Button
                            :disabled="
                                form.processing || !review || !form.confirmed
                            "
                            >Confirm reassignment</Button
                        ><Link :href="show.url(customer.reference)"
                            ><Button type="button" variant="outline"
                                >Back to Customer</Button
                            ></Link
                        >
                    </div>
                </form></CardContent
            ></Card
        >
        <ManagementDeliveryPanel
            subject="customer"
            :reference="customer.reference"
        />
    </div>
</template>
