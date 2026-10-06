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
import MoreDetails from '@/components/MoreDetails.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardHeader,
    CardTitle,
    CardDescription,
    CardContent,
} from '@/components/ui/card';
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/alert';
import { ShieldCheck } from '@lucide/vue';
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
    suspend: 'Suspend access',
    restore: 'Restore access',
    'start-offboarding': 'Start offboarding',
    'transfer-owner': 'Change case owner',
    'cancel-offboarding': 'Cancel offboarding',
    'complete-offboarding': 'Finish offboarding',
    return: 'Bring back agent',
};
const summaries: Record<Action, string> = {
    suspend: 'Stop them signing in right away.',
    restore: 'Let them sign in again.',
    'start-offboarding': 'Begin the process for an agent who is leaving.',
    'transfer-owner': 'Hand this case to another admin.',
    'cancel-offboarding': 'Stop the offboarding process.',
    'complete-offboarding': 'Close the case and turn off the account.',
    return: 'Rehire an agent who left before.',
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
            return 'They can’t sign in or use the app until access is restored. All their sessions and trusted devices are signed out. Their status, customers, savings records and history stay the same.';
        case 'start-offboarding':
            return 'This opens an offboarding case. Their status becomes Inactive and their sign-in is suspended. Their customers and money responsibilities stay in place for review.';
        case 'restore':
            return `Their account access becomes ${props.restoration_state ?? 'unavailable'}. Their status stays ${props.agent.operational_status}. If both allow it, they can work with their customers again. They will need to sign in again.`;
        case 'return':
            return `They come back with the same profile and past cases. Account access becomes ${props.restoration_state ?? 'unavailable'}. Their status stays Inactive until it is approved separately. Their former customers don’t come back to them.`;
        case 'transfer-owner':
            return 'This changes who is responsible for the case. It doesn’t move customers, settle money or approve waiting requests.';
        case 'cancel-offboarding':
            return 'This closes the case as cancelled. Sign-in stays suspended and status stays Inactive. Finished handovers and money settlements stay as they are.';
        case 'complete-offboarding':
            return 'This turns off their account and closes the case. All checks must pass again first. Their profile, past customers and the records they made stay unchanged.';
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
const dialogOpen = ref(false);
const openAction = (item: Action): void => {
    action.value = item;
    form.confirmed = false;
    form.clearErrors();
    dialogOpen.value = true;
};
const isActionBlocked = (item: Action): boolean =>
    !props.fresh_authentication ||
    (item === 'complete-offboarding' && !props.completion.eligible);
const hasOpenCase = computed(() =>
    props.allowed_actions.includes('complete-offboarding'),
);
const readable = (value: string): string => {
    const text = value.replace('agent.', '').replaceAll('_', ' ');
    return text.charAt(0).toUpperCase() + text.slice(1);
};
const submit = (): void => {
    form.post(endpoints[action.value](props.agent.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
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
            { title: 'Account access', href: '#' },
        ],
    },
});
</script>

<template>
    <Head :title="`Account access: ${agent.name}`" />
    <div class="mx-auto w-full max-w-3xl space-y-6">
        <PageHeader
            title="Account access"
            :description="`Suspend, restore or offboard ${agent.name}.`"
        >
            <template #actions>
                <Button as-child variant="outline">
                    <Link :href="editStatus(agent.id)">Change status</Link>
                </Button>
            </template>
        </PageHeader>

        <div
            v-if="!fresh_authentication"
            role="status"
            class="bg-muted flex flex-col gap-3 rounded-xl p-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="flex items-start gap-3">
                <ShieldCheck
                    class="text-muted-foreground mt-0.5 size-5 shrink-0"
                />
                <div>
                    <p class="text-sm font-medium">Confirm it’s you first</p>
                    <p class="text-muted-foreground text-sm">
                        Enter your password and authenticator code. This lasts
                        10 minutes.
                    </p>
                </div>
            </div>
            <Button as-child variant="outline" class="shrink-0">
                <Link :href="show(agent.id, { query: { verify: 1 } })"
                    >Confirm identity</Link
                >
            </Button>
        </div>

        <Alert v-if="restoration_blocker"
            ><AlertTitle>Access can’t be restored yet</AlertTitle
            ><AlertDescription>{{
                restoration_blocker
            }}</AlertDescription></Alert
        >

        <Card>
            <CardHeader><CardTitle>Right now</CardTitle></CardHeader>
            <CardContent class="space-y-3">
                <div class="flex flex-wrap gap-2">
                    <Badge variant="outline"
                        >Account: {{ readable(agent.account_state) }}</Badge
                    ><Badge variant="secondary"
                        >Status: {{ readable(agent.operational_status) }}</Badge
                    ><Badge v-if="props.case" variant="outline"
                        >Offboarding: {{ readable(props.case.status) }}</Badge
                    >
                </div>
                <p class="text-muted-foreground text-sm">
                    Customers: {{ agent.assignment_counts.active ?? 0 }} active
                    · {{ agent.assignment_counts.inactive ?? 0 }} inactive ·
                    {{ agent.assignment_counts.restricted ?? 0 }} restricted ·
                    {{ agent.assignment_counts.archived ?? 0 }} archived
                </p>
            </CardContent>
        </Card>

        <Card>
            <CardHeader
                ><CardTitle>What do you want to do?</CardTitle></CardHeader
            >
            <CardContent>
                <p
                    v-if="!allowed_actions.length"
                    class="text-muted-foreground text-sm"
                >
                    There is nothing you can change right now.
                </p>
                <ul v-else class="divide-border -my-3 divide-y">
                    <li
                        v-for="item in allowed_actions"
                        :key="item"
                        class="flex flex-wrap items-center justify-between gap-3 py-3"
                    >
                        <div class="min-w-0">
                            <p class="text-sm font-medium">
                                {{ labels[item] }}
                            </p>
                            <p class="text-muted-foreground text-xs">
                                <template
                                    v-if="
                                        item === 'complete-offboarding' &&
                                        !completion.eligible
                                    "
                                    >Finish the checks below first.</template
                                >
                                <template v-else>{{
                                    summaries[item]
                                }}</template>
                            </p>
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="isActionBlocked(item)"
                            @click="openAction(item)"
                            >{{ labels[item] }}</Button
                        >
                    </li>
                </ul>
            </CardContent>
        </Card>

        <Card v-if="hasOpenCase">
            <CardHeader
                ><CardTitle>Before offboarding can finish</CardTitle
                ><CardDescription
                    >Until every check passes, the case stays open and sign-in
                    stays suspended.</CardDescription
                ></CardHeader
            >
            <CardContent>
                <ul class="divide-border -my-3 divide-y">
                    <li
                        v-for="check in completion.checks"
                        :key="check.key"
                        class="space-y-1 py-3"
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
                                >{{ readable(check.status) }}</Badge
                            >
                        </div>
                        <p class="text-muted-foreground text-sm">
                            {{ check.message }}
                        </p>
                        <Link
                            v-if="check.url"
                            :href="check.url"
                            class="text-primary inline-block text-sm underline-offset-4 hover:underline"
                            >Open</Link
                        >
                    </li>
                </ul>
            </CardContent>
        </Card>

        <Card>
            <CardHeader><CardTitle>History</CardTitle></CardHeader>
            <CardContent class="space-y-4">
                <p v-if="!history.length" class="text-muted-foreground text-sm">
                    No changes yet.
                </p>
                <ol v-else class="divide-border -my-3 divide-y">
                    <li
                        v-for="entry in history"
                        :key="entry.id"
                        class="space-y-1 py-3"
                    >
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-2"
                        >
                            <p class="text-sm font-medium">
                                {{ readable(entry.event_type) }}
                            </p>
                            <p class="text-muted-foreground text-xs">
                                {{ entry.effective_at }}
                            </p>
                        </div>
                        <p class="text-sm">Reason: {{ entry.reason }}</p>
                        <p class="text-sm">
                            Message to agent: {{ entry.agent_explanation }}
                        </p>
                        <MoreDetails label="Details" class="pt-1">
                            <div
                                class="text-muted-foreground space-y-1 text-xs"
                            >
                                <p>
                                    Account:
                                    {{ readable(entry.from_account_state) }} →
                                    {{ readable(entry.to_account_state) }}
                                </p>
                                <p>
                                    Status:
                                    {{
                                        readable(entry.from_operational_status)
                                    }}
                                    →
                                    {{ readable(entry.to_operational_status) }}
                                </p>
                                <p v-if="entry.case_id">
                                    Case #{{ entry.case_id }}
                                </p>
                                <p
                                    v-for="(
                                        notice, index
                                    ) in entry.notifications"
                                    :key="index"
                                >
                                    {{ notice.audience_type }} ·
                                    {{ notice.channel }} · {{ notice.status
                                    }}<span v-if="notice.failure_reason"
                                        >: {{ notice.failure_reason }}</span
                                    >
                                </p>
                            </div>
                        </MoreDetails>
                    </li>
                </ol>
                <MoreDetails v-if="!hasOpenCase" label="Offboarding checks">
                    <ul class="divide-border divide-y text-sm">
                        <li
                            v-for="check in completion.checks"
                            :key="check.key"
                            class="flex flex-wrap justify-between gap-2 py-2"
                        >
                            <span>{{ check.label }}</span>
                            <span class="text-muted-foreground">{{
                                readable(check.status)
                            }}</span>
                        </li>
                    </ul>
                </MoreDetails>
            </CardContent>
        </Card>

        <ManagementDeliveryPanel subject="agent" :reference="agent.id" />

        <Dialog v-model:open="dialogOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{ labels[action] }}</DialogTitle>
                    <DialogDescription>{{ consequences }}</DialogDescription>
                </DialogHeader>
                <form
                    id="lifecycle-form"
                    class="space-y-5"
                    @submit.prevent="submit"
                >
                    <div v-if="action === 'transfer-owner'" class="space-y-2">
                        <Label for="case-owner">New case owner</Label>
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
                        <Label for="lifecycle-reason">Reason</Label
                        ><textarea
                            id="lifecycle-reason"
                            v-model="form.reason"
                            required
                            maxlength="500"
                            rows="3"
                            class="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2"
                        />
                        <p class="text-muted-foreground text-xs">
                            Only managers see this.
                        </p>
                    </div>
                    <div class="space-y-2">
                        <Label for="lifecycle-explanation"
                            >Message to the agent</Label
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
                        /><span>I understand what this will do.</span></label
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
                </form>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="dialogOpen = false"
                        >Cancel</Button
                    >
                    <Button
                        type="submit"
                        form="lifecycle-form"
                        :variant="
                            ['suspend', 'complete-offboarding'].includes(action)
                                ? 'destructive'
                                : 'default'
                        "
                        :disabled="disabled"
                        >{{
                            form.processing ? 'Saving…' : labels[action]
                        }}</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
