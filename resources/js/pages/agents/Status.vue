<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { dashboard } from '@/routes';
import { index as agentsIndex, show as agentShow } from '@/routes/agents';
import { show as showLifecycle } from '@/actions/App/Http/Controllers/AgentLifecycleController';
import { update as updateAgentStatus } from '@/routes/agents/status';
import MoreDetails from '@/components/MoreDetails.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { computed } from 'vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type AgentStatus = 'active' | 'inactive';
type HistoryEntry = {
    from_status: string;
    to_status: string;
    reason: string;
    agent_explanation: string | null;
    changed_by: string;
    effective_at: string;
    notifications: Array<{
        audience: string;
        channel: string;
        status: string;
        failure_reason: string | null;
    }>;
};

const props = defineProps<{
    agent: {
        id: string;
        name: string;
        operational_status: AgentStatus;
        account_state: string;
        version: number;
        email_verified: boolean;
        mfa_confirmed: boolean;
        has_open_offboarding_case: boolean;
        readiness: { eligible: boolean; reason: string | null };
        assignment_readiness: { eligible: boolean; reason: string | null };
        assignment_counts: Record<
            'active' | 'inactive' | 'restricted' | 'archived',
            number
        >;
    };
    allowed_targets: Array<{ value: AgentStatus; label: string }>;
    financial_responsibilities: { collections: string; requests: string };
    history: HistoryEntry[];
}>();

const form = useForm({
    target_status: props.agent.operational_status,
    version: props.agent.version,
    confirmed: false,
    reason: '',
    agent_explanation: '',
});

const statusLabel = (value: string): string =>
    props.allowed_targets.find((target) => target.value === value)?.label ??
    readable(value);
const readable = (value: string): string => {
    const text = value.replaceAll('_', ' ');
    return text.charAt(0).toUpperCase() + text.slice(1);
};
const targetLabel = computed(() => statusLabel(form.target_status));

const submit = (): void => {
    form.patch(updateAgentStatus(props.agent.id).url, { preserveScroll: true });
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Agents', href: agentsIndex() },
            { title: 'Change status', href: '#' },
        ],
    },
});
</script>

<template>
    <Head :title="`Change status: ${agent.name}`" />
    <div class="mx-auto w-full max-w-3xl space-y-6">
        <PageHeader
            title="Change status"
            :description="`Choose whether ${agent.name} can work with customers.`"
        >
            <template #actions>
                <Button as-child variant="outline">
                    <Link :href="showLifecycle(agent.id)">Account access</Link>
                </Button>
            </template>
        </PageHeader>

        <Card>
            <CardHeader>
                <CardTitle>Right now</CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">
                <div class="flex flex-wrap gap-2">
                    <Badge variant="secondary">{{
                        statusLabel(agent.operational_status)
                    }}</Badge>
                    <Badge variant="outline"
                        >Account: {{ readable(agent.account_state) }}</Badge
                    >
                </div>
                <ul class="divide-border divide-y text-sm">
                    <li class="flex justify-between gap-4 py-2.5">
                        <span class="text-muted-foreground">Customer work</span>
                        <span class="text-right">{{
                            agent.readiness.reason ?? 'Can work with customers'
                        }}</span>
                    </li>
                    <li class="flex justify-between gap-4 py-2.5">
                        <span class="text-muted-foreground">New customers</span>
                        <span class="text-right">{{
                            agent.assignment_readiness.reason ??
                            'Can take new customers'
                        }}</span>
                    </li>
                    <li class="flex justify-between gap-4 py-2.5">
                        <span class="text-muted-foreground"
                            >Assigned customers</span
                        >
                        <span class="text-right"
                            >{{ agent.assignment_counts.active }} active ·
                            {{ agent.assignment_counts.inactive }} inactive ·
                            {{ agent.assignment_counts.restricted }} restricted
                            ·
                            {{ agent.assignment_counts.archived }}
                            archived</span
                        >
                    </li>
                </ul>
                <MoreDetails>
                    <dl class="grid gap-2 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">
                                Email confirmed
                            </dt>
                            <dd>{{ agent.email_verified ? 'Yes' : 'No' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">
                                Two-step sign-in set up
                            </dt>
                            <dd>{{ agent.mfa_confirmed ? 'Yes' : 'No' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">
                                Offboarding in progress
                            </dt>
                            <dd>
                                {{
                                    agent.has_open_offboarding_case
                                        ? 'Yes'
                                        : 'No'
                                }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">Agent ID</dt>
                            <dd class="font-mono text-xs">{{ agent.id }}</dd>
                        </div>
                    </dl>
                    <div class="text-muted-foreground mt-3 space-y-1 text-xs">
                        <p>{{ financial_responsibilities.collections }}</p>
                        <p>{{ financial_responsibilities.requests }}</p>
                    </div>
                </MoreDetails>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>New status</CardTitle>
                <CardDescription
                    >Inactive agents can't do customer work. They can still sign
                    in and keep their customers.</CardDescription
                >
            </CardHeader>
            <CardContent>
                <form class="space-y-5" @submit.prevent="submit">
                    <div class="space-y-2">
                        <Label for="agent-target-status">Status</Label>
                        <Select v-model="form.target_status">
                            <SelectTrigger
                                id="agent-target-status"
                                class="w-full"
                                :aria-invalid="!!form.errors.target_status"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="target in allowed_targets"
                                    :key="target.value"
                                    :value="target.value"
                                >
                                    {{ target.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p
                            v-if="form.errors.target_status"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.target_status }}
                        </p>
                        <p
                            v-if="form.errors.version"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.version }}
                        </p>
                    </div>
                    <div class="space-y-2">
                        <Label for="agent-status-reason">Reason</Label>
                        <textarea
                            id="agent-status-reason"
                            v-model="form.reason"
                            maxlength="500"
                            rows="3"
                            required
                            class="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                        />
                        <p class="text-muted-foreground text-xs">
                            Only managers see this.
                        </p>
                        <p
                            v-if="form.errors.reason"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.reason }}
                        </p>
                    </div>
                    <div class="space-y-2">
                        <Label for="agent-explanation"
                            >Message to the agent</Label
                        >
                        <textarea
                            id="agent-explanation"
                            v-model="form.agent_explanation"
                            maxlength="500"
                            rows="3"
                            required
                            class="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                        />
                        <p
                            v-if="form.errors.agent_explanation"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.agent_explanation }}
                        </p>
                    </div>
                    <label class="flex items-start gap-3 text-sm">
                        <input
                            v-model="form.confirmed"
                            type="checkbox"
                            class="border-input text-primary focus-visible:ring-ring mt-0.5 size-4 rounded"
                        />
                        <span
                            >I want to set this agent to
                            {{ targetLabel }}.</span
                        >
                    </label>
                    <p
                        v-if="form.errors.confirmed"
                        class="text-destructive text-sm"
                    >
                        {{ form.errors.confirmed }}
                    </p>
                    <div class="flex flex-wrap gap-3">
                        <Button type="submit" :disabled="form.processing">{{
                            form.processing ? 'Saving…' : 'Save status'
                        }}</Button>
                        <Button as-child type="button" variant="outline">
                            <Link :href="agentShow(agent.id)">Cancel</Link>
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>History</CardTitle>
            </CardHeader>
            <CardContent>
                <p
                    v-if="history.length === 0"
                    class="text-muted-foreground text-sm"
                >
                    No status changes yet.
                </p>
                <ol v-else class="divide-border -my-3 divide-y">
                    <li
                        v-for="(entry, index) in history"
                        :key="`${entry.effective_at}-${index}`"
                        class="space-y-1 py-3"
                    >
                        <p class="text-sm font-medium">
                            {{ statusLabel(entry.from_status) }} →
                            {{ statusLabel(entry.to_status) }}
                        </p>
                        <p class="text-muted-foreground text-xs">
                            {{ entry.effective_at }} · {{ entry.changed_by }}
                        </p>
                        <p class="text-sm">Reason: {{ entry.reason }}</p>
                        <p v-if="entry.agent_explanation" class="text-sm">
                            Message to agent: {{ entry.agent_explanation }}
                        </p>
                        <MoreDetails
                            v-if="entry.notifications.length"
                            label="Who was told"
                            class="pt-1"
                        >
                            <p
                                v-for="(
                                    notice, noticeIndex
                                ) in entry.notifications"
                                :key="noticeIndex"
                                class="text-muted-foreground text-xs"
                            >
                                {{ notice.audience }} · {{ notice.channel }} ·
                                {{ notice.status
                                }}<span v-if="notice.failure_reason"
                                    >: {{ notice.failure_reason }}</span
                                >
                            </p>
                        </MoreDetails>
                    </li>
                </ol>
            </CardContent>
        </Card>
    </div>
</template>
