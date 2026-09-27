<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { dashboard } from '@/routes';
import { index as agentsIndex, show as agentShow } from '@/routes/agents';
import { show as showLifecycle } from '@/actions/App/Http/Controllers/AgentLifecycleController';
import { update as updateAgentStatus } from '@/routes/agents/status';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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

const submit = (): void => {
    form.patch(updateAgentStatus(props.agent.id).url, { preserveScroll: true });
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Agents', href: agentsIndex() },
            { title: 'Manage status', href: '#' },
        ],
    },
});
</script>

<template>
    <Head :title="`Manage ${agent.name} status`" />
    <div class="mx-auto w-full max-w-4xl space-y-6">
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">
                Manage Agent status
            </h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                {{ agent.name }} · {{ agent.id }}
            </p>
        </div>

        <Link :href="showLifecycle(agent.id)"
            ><Button variant="outline"
                >Manage account access and offboarding</Button
            ></Link
        >

        <div class="grid gap-4 sm:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>Current state</CardTitle>
                    <CardDescription
                        >Operational readiness and account access are
                        separate.</CardDescription
                    >
                </CardHeader>
                <CardContent class="flex flex-wrap gap-2">
                    <Badge variant="secondary"
                        >Agent: {{ agent.operational_status }}</Badge
                    >
                    <Badge variant="outline"
                        >Account: {{ agent.account_state }}</Badge
                    >
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle>Readiness</CardTitle>
                    <CardDescription>{{
                        agent.readiness.reason ??
                        'Eligible for assigned Customer work.'
                    }}</CardDescription>
                </CardHeader>
                <CardContent class="space-y-1 text-sm">
                    <p>
                        New assignments:
                        {{
                            agent.assignment_readiness.reason ??
                            'Eligible to receive.'
                        }}
                    </p>
                    <p>
                        Email verified:
                        {{ agent.email_verified ? 'Yes' : 'No' }}
                    </p>
                    <p>
                        MFA confirmed: {{ agent.mfa_confirmed ? 'Yes' : 'No' }}
                    </p>
                    <p>
                        Open offboarding case:
                        {{ agent.has_open_offboarding_case ? 'Yes' : 'No' }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Current assignments</CardTitle>
                <CardDescription
                    >Assignments stay in place when operational status
                    changes.</CardDescription
                >
            </CardHeader>
            <CardContent class="flex flex-wrap gap-2 text-sm">
                <Badge variant="outline"
                    >Active: {{ agent.assignment_counts.active }}</Badge
                >
                <Badge variant="outline"
                    >Inactive: {{ agent.assignment_counts.inactive }}</Badge
                >
                <Badge variant="outline"
                    >Restricted: {{ agent.assignment_counts.restricted }}</Badge
                >
                <Badge variant="outline"
                    >Archived: {{ agent.assignment_counts.archived }}</Badge
                >
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Change operational status</CardTitle>
                <CardDescription
                    >Inactivity stops new Customer work immediately. Login
                    access and assignments remain; suspension is a separate
                    action.</CardDescription
                >
            </CardHeader>
            <CardContent>
                <form class="space-y-5" @submit.prevent="submit">
                    <div class="space-y-2">
                        <Label for="agent-target-status">New status</Label>
                        <select
                            id="agent-target-status"
                            v-model="form.target_status"
                            class="border-input bg-background focus-visible:ring-ring h-11 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <option
                                v-for="target in allowed_targets"
                                :key="target.value"
                                :value="target.value"
                            >
                                {{ target.label }}
                            </option>
                        </select>
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
                        <Label for="agent-status-reason">Internal reason</Label>
                        <textarea
                            id="agent-status-reason"
                            v-model="form.reason"
                            maxlength="500"
                            rows="3"
                            required
                            class="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                        />
                        <p
                            v-if="form.errors.reason"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.reason }}
                        </p>
                    </div>
                    <div class="space-y-2">
                        <Label for="agent-explanation"
                            >Explanation shown to the Agent</Label
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
                            >I confirm the {{ form.target_status }} transition
                            and its effect on Customer work.</span
                        >
                    </label>
                    <p
                        v-if="form.errors.confirmed"
                        class="text-destructive text-sm"
                    >
                        {{ form.errors.confirmed }}
                    </p>
                    <Alert>
                        <AlertTitle
                            >Financial responsibilities unavailable</AlertTitle
                        >
                        <AlertDescription
                            >{{ financial_responsibilities.collections }}
                            {{
                                financial_responsibilities.requests
                            }}</AlertDescription
                        >
                    </Alert>
                    <div class="flex flex-wrap gap-3">
                        <Button type="submit" :disabled="form.processing">{{
                            form.processing
                                ? 'Saving…'
                                : 'Confirm status change'
                        }}</Button>
                        <Link :href="agentShow(agent.id)"
                            ><Button type="button" variant="outline"
                                >Cancel</Button
                            ></Link
                        >
                    </div>
                </form>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Status history</CardTitle>
                <CardDescription
                    >Internal reasons and delivery outcomes are visible only to
                    authorized management.</CardDescription
                >
            </CardHeader>
            <CardContent>
                <p
                    v-if="history.length === 0"
                    class="text-muted-foreground text-sm"
                >
                    No status changes have been recorded.
                </p>
                <ol v-else class="space-y-5">
                    <li
                        v-for="(entry, index) in history"
                        :key="`${entry.effective_at}-${index}`"
                        class="border-border border-l-2 pl-4"
                    >
                        <p class="text-sm font-medium">
                            {{ entry.from_status }} → {{ entry.to_status }}
                        </p>
                        <p class="text-muted-foreground mt-1 text-xs">
                            {{ entry.effective_at }} · {{ entry.changed_by }}
                        </p>
                        <p class="mt-2 text-sm">
                            Internal reason: {{ entry.reason }}
                        </p>
                        <p v-if="entry.agent_explanation" class="mt-1 text-sm">
                            Agent explanation: {{ entry.agent_explanation }}
                        </p>
                        <p
                            v-for="(notice, noticeIndex) in entry.notifications"
                            :key="noticeIndex"
                            class="text-muted-foreground mt-1 text-xs"
                        >
                            {{ notice.audience }} · {{ notice.channel }} ·
                            {{ notice.status
                            }}<span v-if="notice.failure_reason"
                                >: {{ notice.failure_reason }}</span
                            >
                        </p>
                    </li>
                </ol>
            </CardContent>
        </Card>
    </div>
</template>
