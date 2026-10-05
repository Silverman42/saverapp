<script setup lang="ts">
import ManagementDeliveryPanel from '@/components/ManagementDeliveryPanel.vue';
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { dashboard } from '@/routes';
import { index as agentsIndex } from '@/routes/agents';
import { edit as editStatus } from '@/routes/agents/status';
import {
    show,
    suspend,
    restore,
    startOffboarding,
    transferOwner,
    cancelOffboarding,
    completeOffboarding,
    returnToService,
} from '@/actions/App/Http/Controllers/AgentLifecycleController';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardHeader,
    CardTitle,
    CardDescription,
    CardContent,
} from '@/components/ui/card';
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/alert';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type Action =
    | 'suspend'
    | 'restore'
    | 'start-offboarding'
    | 'transfer-owner'
    | 'cancel-offboarding'
    | 'complete-offboarding'
    | 'return';
type Case = {
    id: number;
    version: number;
    status: string;
    owner_user_id: number | null;
    started_at: string | null;
    completed_at: string | null;
    cancelled_at: string | null;
};
type Check = {
    key: string;
    label: string;
    status: string;
    message: string;
    url: string | null;
};
type History = {
    id: number;
    event_type: string;
    from_account_state: string;
    to_account_state: string;
    from_operational_status: string;
    to_operational_status: string;
    reason: string;
    agent_explanation: string;
    case_id: number | null;
    effective_at: string;
    notifications: Array<{
        audience_type: string;
        channel: string;
        status: string;
        failure_reason: string | null;
    }>;
};
const props = defineProps<{
    agent: {
        id: string;
        name: string;
        version: number;
        account_state: string;
        operational_status: string;
        assignment_counts: Record<string, number>;
    };
    case: Case | null;
    completion: { eligible: boolean; checks: Check[] };
    restoration_state: string | null;
    restoration_blocker: string | null;
    fresh_authentication: boolean;
    allowed_actions: Action[];
    owners: Array<{ id: number; name: string }>;
    history: History[];
}>();
const labels: Record<Action, string> = {
    suspend: 'Suspend account',
    restore: 'Restore account access',
    'start-offboarding': 'Start offboarding',
    'transfer-owner': 'Transfer case ownership',
    'cancel-offboarding': 'Cancel offboarding',
    'complete-offboarding': 'Complete offboarding',
    return: 'Reactivate existing Agent',
};
const endpoints = {
    suspend,
    restore,
    'start-offboarding': startOffboarding,
    'transfer-owner': transferOwner,
    'cancel-offboarding': cancelOffboarding,
    'complete-offboarding': completeOffboarding,
    return: returnToService,
};
const action = ref<Action>(props.allowed_actions[0] ?? 'suspend');
const form = useForm({
    attempt_reference: crypto.randomUUID(),
    version: props.agent.version,
    case_id: props.case?.id ?? null,
    case_version: props.case?.version ?? null,
    owner_user_id: props.case?.owner_user_id ?? null,
    reason: '',
    agent_explanation: '',
    confirmed: false,
});
const consequences = computed(() => {
    switch (action.value) {
        case 'suspend':
            return 'This action stops all application access immediately. Operational status, assignments, credentials, savings and financial history do not change. The system revokes all old sessions and trusted devices.';
        case 'start-offboarding':
            return 'This action creates one offboarding case. It sets readiness to Inactive and suspends account access. Current assignments and financial responsibilities stay for authorized review.';
        case 'restore':
            return `This action sets account access to ${props.restoration_state ?? 'unavailable'}. Operational status stays ${props.agent.operational_status}. If both states permit Customer work, the Agent can work on current assignments again. Old sessions stay revoked.`;
        case 'return':
            return `This action keeps the Agent identity and prior cases. Account access changes to ${props.restoration_state ?? 'unavailable'}. Readiness stays Inactive until a separate approval. Former Customers do not return to this Agent.`;
        case 'transfer-owner':
            return 'This action changes the accountable case owner. It does not transfer Customers. It does not settle money or approve pending requests.';
        case 'cancel-offboarding':
            return 'This action closes the case as Cancelled. Account access stays Suspended. Readiness stays Inactive. Completed handovers and financial resolutions do not change.';
        case 'complete-offboarding':
            return 'This action deactivates account access and completes the case. All authoritative gates must pass again first. Identity, archived assignments and historical attribution do not change.';
    }
});
const disabled = computed(
    () =>
        form.processing ||
        !props.fresh_authentication ||
        !props.allowed_actions.includes(action.value) ||
        (action.value === 'complete-offboarding' && !props.completion.eligible),
);
watch(action, () => {
    form.attempt_reference = crypto.randomUUID();
    form.confirmed = false;
    form.clearErrors();
});
watch(
    () => [props.agent.version, props.case?.version],
    () => {
        form.version = props.agent.version;
        form.case_id = props.case?.id ?? null;
        form.case_version = props.case?.version ?? null;
        form.attempt_reference = crypto.randomUUID();
        form.confirmed = false;
        action.value = props.allowed_actions.includes(action.value)
            ? action.value
            : (props.allowed_actions[0] ?? 'suspend');
    },
);
const submit = (): void => {
    form.post(endpoints[action.value](props.agent.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('reason', 'agent_explanation', 'confirmed');
            form.attempt_reference = crypto.randomUUID();
        },
    });
};
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Agents', href: agentsIndex() },
            { title: 'Access and offboarding', href: '#' },
        ],
    },
});
</script>

<template>
    <Head :title="`Manage ${agent.name} access`" />
    <div class="mx-auto w-full max-w-4xl space-y-6">
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">
                Agent access and offboarding
            </h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                {{ agent.name }} · {{ agent.id }}
            </p>
        </div>
        <Card
            ><CardHeader
                ><CardTitle>Current state</CardTitle
                ><CardDescription
                    >Account access and operational readiness use different
                    procedures.</CardDescription
                ></CardHeader
            ><CardContent class="space-y-4"
                ><div class="flex flex-wrap gap-2">
                    <Badge variant="outline"
                        >Account: {{ agent.account_state }}</Badge
                    ><Badge variant="secondary"
                        >Readiness: {{ agent.operational_status }}</Badge
                    ><Badge v-if="props.case" variant="outline"
                        >Case #{{ props.case.id }}:
                        {{ props.case.status }}</Badge
                    >
                </div>
                <p class="text-muted-foreground text-sm">
                    Assigned Customers: Active
                    {{ agent.assignment_counts.active ?? 0 }} · Inactive
                    {{ agent.assignment_counts.inactive ?? 0 }} · Restricted
                    {{ agent.assignment_counts.restricted ?? 0 }} · Archived
                    {{ agent.assignment_counts.archived ?? 0 }}
                </p>
                <Link :href="editStatus(agent.id)"
                    ><Button variant="outline"
                        >Manage operational readiness</Button
                    ></Link
                ></CardContent
            ></Card
        >
        <Alert v-if="restoration_blocker"
            ><AlertTitle>Access restoration blocked</AlertTitle
            ><AlertDescription>{{
                restoration_blocker
            }}</AlertDescription></Alert
        >
        <Card
            ><CardHeader
                ><CardTitle>Lifecycle action</CardTitle
                ><CardDescription
                    >You must verify your password and authenticator again. Do
                    this within ten minutes.</CardDescription
                ></CardHeader
            ><CardContent>
                <Link
                    v-if="!fresh_authentication"
                    :href="show(agent.id, { query: { verify: 1 } })"
                    ><Button variant="outline" class="mb-5"
                        >Verify password and authenticator</Button
                    ></Link
                >
                <form class="space-y-5" @submit.prevent="submit">
                    <div class="space-y-2">
                        <Label for="lifecycle-action">Action</Label>
                        <Select v-model="action">
                            <SelectTrigger id="lifecycle-action" class="w-full"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="item in allowed_actions"
                                    :key="item"
                                    :value="item"
                                >
                                    {{ labels[item] }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <Alert
                        ><AlertTitle>{{ labels[action] }}</AlertTitle
                        ><AlertDescription>{{
                            consequences
                        }}</AlertDescription></Alert
                    >
                    <div v-if="action === 'transfer-owner'" class="space-y-2">
                        <Label for="case-owner">Accountable owner</Label>
                        <Select
                            :model-value="
                                form.owner_user_id === null
                                    ? undefined
                                    : String(form.owner_user_id)
                            "
                            required
                            @update:model-value="
                                (value) => (form.owner_user_id = Number(value))
                            "
                        >
                            <SelectTrigger id="case-owner" class="w-full"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="owner in owners"
                                    :key="owner.id"
                                    :value="String(owner.id)"
                                >
                                    {{ owner.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="space-y-2">
                        <Label for="lifecycle-reason">Internal reason</Label
                        ><textarea
                            id="lifecycle-reason"
                            v-model="form.reason"
                            required
                            maxlength="500"
                            rows="3"
                            class="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="lifecycle-explanation"
                            >Explanation shown to the Agent</Label
                        ><textarea
                            id="lifecycle-explanation"
                            v-model="form.agent_explanation"
                            required
                            maxlength="500"
                            rows="3"
                            class="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2"
                        />
                    </div>
                    <label class="flex items-start gap-3 text-sm"
                        ><input
                            v-model="form.confirmed"
                            type="checkbox"
                            required
                            class="mt-0.5 size-4"
                        /><span
                            >I confirm this action and the consequences
                            displayed above.</span
                        ></label
                    >
                    <div
                        v-if="Object.keys(form.errors).length"
                        role="alert"
                        class="text-destructive space-y-1 text-sm"
                    >
                        <p v-for="(message, key) in form.errors" :key="key">
                            {{ message }}
                        </p>
                    </div>
                    <Button type="submit" :disabled="disabled">{{
                        form.processing ? 'Saving…' : labels[action]
                    }}</Button>
                </form>
            </CardContent></Card
        >
        <Card
            ><CardHeader
                ><CardTitle>Offboarding completion checks</CardTitle
                ><CardDescription
                    >If evidence is not available, the case stays open. Account
                    access stays suspended. Archived assignments can
                    stay.</CardDescription
                ></CardHeader
            ><CardContent
                ><ul class="space-y-4">
                    <li
                        v-for="check in completion.checks"
                        :key="check.key"
                        class="border-border rounded-md border p-4"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-2"
                        >
                            <p class="text-sm font-medium">{{ check.label }}</p>
                            <Badge
                                :variant="
                                    check.status === 'passed'
                                        ? 'secondary'
                                        : 'outline'
                                "
                                >{{ check.status }}</Badge
                            >
                        </div>
                        <p class="text-muted-foreground mt-2 text-sm">
                            {{ check.message }}
                        </p>
                        <Link
                            v-if="check.url"
                            :href="check.url"
                            class="text-primary mt-2 inline-block text-sm underline"
                            >Open authorized workflow</Link
                        >
                    </li>
                </ul></CardContent
            ></Card
        >
        <Card
            ><CardHeader
                ><CardTitle>Lifecycle history</CardTitle
                ><CardDescription
                    >This list shows the latest 50 events. Only authorized
                    managers can see internal reasons and delivery
                    outcomes.</CardDescription
                ></CardHeader
            ><CardContent
                ><p
                    v-if="!history.length"
                    class="text-muted-foreground text-sm"
                >
                    No lifecycle events recorded.
                </p>
                <ol v-else class="space-y-5">
                    <li
                        v-for="entry in history"
                        :key="entry.id"
                        class="border-border border-l-2 pl-4"
                    >
                        <p class="text-sm font-medium">
                            {{
                                entry.event_type
                                    .replace('agent.', '')
                                    .replaceAll('_', ' ')
                            }}
                        </p>
                        <p class="text-muted-foreground mt-1 text-xs">
                            {{ entry.effective_at }} · Case
                            {{ entry.case_id ?? '—' }}
                        </p>
                        <p class="mt-2 text-sm">
                            Account: {{ entry.from_account_state }} →
                            {{ entry.to_account_state }} · Readiness:
                            {{ entry.from_operational_status }} →
                            {{ entry.to_operational_status }}
                        </p>
                        <p class="mt-2 text-sm">
                            Internal reason: {{ entry.reason }}
                        </p>
                        <p class="mt-1 text-sm">
                            Agent explanation: {{ entry.agent_explanation }}
                        </p>
                        <p
                            v-for="(notice, index) in entry.notifications"
                            :key="index"
                            class="text-muted-foreground mt-1 text-xs"
                        >
                            {{ notice.audience_type }} · {{ notice.channel }} ·
                            {{ notice.status
                            }}<span v-if="notice.failure_reason"
                                >: {{ notice.failure_reason }}</span
                            >
                        </p>
                    </li>
                </ol></CardContent
            ></Card
        >
        <ManagementDeliveryPanel subject="agent" :reference="agent.id" />
    </div>
</template>
