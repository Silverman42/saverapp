<script setup lang="ts">
import ManagementDeliveryPanel from '@/components/ManagementDeliveryPanel.vue';
import { Head, Link, router, useHttp } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { dashboard } from '@/routes';
import { index, show } from '@/routes/customers';
import { preview, store, operation } from '@/routes/customers/reassignment';
import MoreDetails from '@/components/MoreDetails.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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
const targetAgentSelection = computed({
    get: () => String(form.target_agent_id),
    set: (value: string) => {
        form.target_agent_id = Number(value);
    },
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
            'We could not check the handover. Refresh the page and try again.';
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
            message.value = 'Please fix the errors below and try again.';
            return;
        }
        sessionStorage.removeItem(key);
        uncertain.value = false;
        message.value =
            result.status === 'unchanged'
                ? 'Nothing changed. The customer already has this agent.'
                : 'Agent changed.';
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
            ? 'We are not sure the change was saved. Check the result before trying again.'
            : 'The agent was not changed. Fix the errors and check the handover again.';
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
        message.value = `Result: ${result.status.replaceAll('_', ' ')}.`;
        uncertain.value = false;
        sessionStorage.removeItem(key);
        form.attempt_reference = crypto.randomUUID();
        review.value = null;
        router.reload();
    } catch {
        message.value =
            'We could not find a saved result yet. Wait a moment and check again before trying again.';
    }
}
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Customers', href: index() },
            { title: 'Change agent', href: '#' },
        ],
    },
});
</script>
<template>
    <div class="mx-auto max-w-3xl space-y-6">
        <Head title="Reassign Customer" />
        <PageHeader title="Change agent" :description="customer.name">
            <template #actions>
                <Button as-child variant="outline">
                    <Link :href="show.url(customer.reference)"
                        >Back to customer</Link
                    >
                </Button>
            </template>
        </PageHeader>

        <dl
            class="bg-muted/40 grid gap-4 rounded-xl p-4 text-sm sm:grid-cols-3"
        >
            <div>
                <dt class="text-muted-foreground">Current agent</dt>
                <dd class="mt-0.5 font-medium">
                    {{ customer.agent_name ?? 'None' }}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Customer status</dt>
                <dd class="mt-0.5 capitalize">{{ customer.status }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Account</dt>
                <dd class="mt-0.5 capitalize">{{ customer.account_state }}</dd>
            </div>
        </dl>

        <p
            v-if="message"
            role="status"
            aria-live="polite"
            class="rounded-xl border p-4 text-sm"
        >
            {{ message }}
        </p>

        <Card v-if="uncertain">
            <CardHeader>
                <CardTitle class="text-base"
                    >We're not sure the change was saved</CardTitle
                >
                <CardDescription
                    >Check the result before you try again.</CardDescription
                >
            </CardHeader>
            <CardContent class="space-y-4">
                <div class="flex flex-wrap gap-2">
                    <Button
                        :disabled="lookup.processing"
                        @click="resolveOutcome"
                        >Check result</Button
                    >
                    <Button
                        v-if="canRetryOriginal"
                        variant="outline"
                        :disabled="form.processing"
                        @click="submit(true)"
                        >Try again</Button
                    >
                </div>
                <MoreDetails>
                    <p class="text-muted-foreground text-xs break-all">
                        Reference: {{ form.attempt_reference }}
                    </p>
                </MoreDetails>
            </CardContent>
        </Card>

        <Card v-else>
            <CardContent>
                <form class="space-y-5" @submit.prevent="submit()">
                    <div class="space-y-2">
                        <Label for="replacement">New agent</Label
                        ><Select v-model="targetAgentSelection"
                            ><SelectTrigger id="replacement" class="h-11 w-full"
                                ><SelectValue /></SelectTrigger
                            ><SelectContent>
                                <SelectItem
                                    v-for="agent in agents"
                                    :key="agent.id"
                                    :value="String(agent.id)"
                                >
                                    {{ agent.name }} · {{ agent.reference }}
                                </SelectItem>
                            </SelectContent></Select
                        >
                        <p
                            v-if="!agents.length"
                            class="text-muted-foreground text-sm"
                        >
                            No other agent can take this customer right now.
                        </p>
                    </div>
                    <Button
                        v-if="!review"
                        type="button"
                        :disabled="form.processing || !agents.length"
                        @click="loadPreview"
                        >Continue</Button
                    >

                    <template v-if="review">
                        <div
                            class="bg-muted/50 space-y-3 rounded-xl p-4 text-sm"
                        >
                            <p>{{ review.message }}</p>
                            <dl class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                <div>
                                    <dt class="text-muted-foreground text-xs">
                                        Withdrawals waiting
                                    </dt>
                                    <dd class="font-medium">
                                        {{ review.pending_withdrawals }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-muted-foreground text-xs">
                                        Reversals waiting
                                    </dt>
                                    <dd class="font-medium">
                                        {{ review.pending_reversals }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-muted-foreground text-xs">
                                        Account recovery
                                    </dt>
                                    <dd class="font-medium">
                                        {{
                                            review.pending_recovery
                                                ? 'In progress'
                                                : 'None'
                                        }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-muted-foreground text-xs">
                                        Name changes
                                    </dt>
                                    <dd class="font-medium">
                                        {{ review.name_proposals }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                        <div class="space-y-2">
                            <Label for="reason">Reason (staff only)</Label
                            ><Input
                                id="reason"
                                v-model="form.reason"
                                required
                                maxlength="500"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="explanation"
                                >Message to the customer</Label
                            ><textarea
                                id="explanation"
                                v-model="form.customer_explanation"
                                required
                                maxlength="500"
                                class="border-input bg-background min-h-24 w-full rounded-md border p-3 text-sm"
                            />
                        </div>
                        <label class="flex items-start gap-3 text-sm"
                            ><input
                                v-model="form.confirmed"
                                type="checkbox"
                                class="mt-1"
                            />The new agent takes over right away and the
                            current agent loses access.</label
                        >
                    </template>
                    <ul
                        v-if="form.hasErrors"
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        <li v-for="(error, field) in form.errors" :key="field">
                            {{ error }}
                        </li>
                    </ul>
                    <div v-if="review" class="flex flex-wrap gap-2">
                        <Button
                            :disabled="
                                form.processing || !review || !form.confirmed
                            "
                            >Change agent</Button
                        >
                        <Button
                            type="button"
                            variant="ghost"
                            :disabled="form.processing"
                            @click="loadPreview"
                            >Check again</Button
                        >
                    </div>
                </form>
            </CardContent>
        </Card>
        <ManagementDeliveryPanel
            subject="customer"
            :reference="customer.reference"
        />
    </div>
</template>
