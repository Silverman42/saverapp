<script setup lang="ts">
import { Head, Link, router, useForm, useHttp } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { archive, restore, operation } from '@/routes/customers/lifecycle';
import { dashboard } from '@/routes';
import {
    index as customersIndex,
    show as customerShow,
} from '@/routes/customers';
import { update as updateCustomerStatus } from '@/routes/customers/status';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type StatusValue = 'active' | 'inactive' | 'restricted' | 'archived';

type HistoryEntry = {
    from_status: string;
    to_status: string;
    reason: string;
    customer_explanation: string | null;
    changed_by: string;
    effective_at: string;
    notifications: Array<{
        channel: string;
        audience: string;
        status: string;
        failure_reason: string | null;
    }>;
};

const props = defineProps<{
    customer: {
        id: string;
        name: string;
        operational_status: StatusValue;
        operational_status_label: string;
        account_state: string | null;
        account_state_label: string;
        version: number;
        assignment_version: number | null;
        current_agent: {
            name: string;
            operational_status: string;
            account_state: string | null;
            is_eligible: boolean;
            eligibility_message: string | null;
        } | null;
    };
    allowed_targets: Array<{ value: StatusValue; label: string }>;
    financial_sections: {
        summary: string;
        plans: string;
        collections: string;
        withdrawals: string;
    };
    history: HistoryEntry[];
    lifecycle: {
        eligible: boolean;
        checks: Array<{
            key: string;
            label: string;
            status: 'passed' | 'blocked' | 'unavailable';
            message: string;
            url: string | null;
        }>;
    };
}>();

const form = useForm({
    target_status: props.customer.operational_status,
    version: props.customer.version,
    confirmed: false,
    reason: '',
    customer_explanation: '',
});

const submit = (): void => {
    form.patch(updateCustomerStatus(props.customer.id).url, {
        preserveScroll: true,
    });
};

const lifecycleStorageKey = `customer-lifecycle:${props.customer.id}`;
let savedLifecycle: {
    data?: Record<string, unknown>;
    uncertain?: boolean;
    action?: 'archive' | 'restore';
} = {};
try {
    savedLifecycle = JSON.parse(
        sessionStorage.getItem(lifecycleStorageKey) ?? '{}',
    );
} catch {
    /* Ignore an invalid local draft. */
}
const lifecycleForm = useForm({
    attempt_reference: crypto.randomUUID(),
    version: props.customer.version,
    assignment_version: props.customer.assignment_version,
    confirmed: false,
    reason: '',
    customer_explanation: '',
    lifecycle: '',
    target_status: '',
});
if (savedLifecycle.data) {
    Object.assign(lifecycleForm, savedLifecycle.data);
}
if (!savedLifecycle.uncertain) {
    lifecycleForm.version = props.customer.version;
    lifecycleForm.assignment_version = props.customer.assignment_version;
    lifecycleForm.confirmed = false;
}
const outcomeUnknown = ref(savedLifecycle.uncertain === true);
const lifecycleAction = ref(
    savedLifecycle.action ??
        (props.customer.operational_status === 'archived'
            ? 'restore'
            : 'archive'),
);
function persistLifecycleDraft(): void {
    sessionStorage.setItem(
        lifecycleStorageKey,
        JSON.stringify({
            data: lifecycleForm.data(),
            uncertain: outcomeUnknown.value,
            action: lifecycleAction.value,
        }),
    );
}
watch(
    () => [lifecycleForm.data(), outcomeUnknown.value, lifecycleAction.value],
    persistLifecycleDraft,
    { deep: true },
);
const lookupNotice = ref('');
const lookup = useHttp({});
const isRestoration = computed(
    () => props.customer.operational_status === 'archived',
);
const lifecycleAllowed = computed(() =>
    isRestoration.value
        ? props.customer.current_agent?.is_eligible === true
        : ['active', 'inactive'].includes(props.customer.operational_status) &&
          props.lifecycle.eligible,
);
watch(
    () => props.customer.version,
    () => {
        if (!outcomeUnknown.value) {
            form.version = props.customer.version;
            form.target_status = props.customer.operational_status;
            lifecycleForm.version = props.customer.version;
            lifecycleForm.assignment_version =
                props.customer.assignment_version;
            lifecycleForm.confirmed = false;
        }
    },
);
function submitLifecycle(retryOriginal = false): void {
    if (!retryOriginal && (!lifecycleAllowed.value || outcomeUnknown.value))
        return;
    if (!retryOriginal)
        lifecycleAction.value = isRestoration.value ? 'restore' : 'archive';
    outcomeUnknown.value = true;
    persistLifecycleDraft();
    lookupNotice.value = '';
    lifecycleForm.post(
        (lifecycleAction.value === 'restore' ? restore : archive).url(
            props.customer.id,
        ),
        {
            preserveScroll: true,
            onError: () => {
                outcomeUnknown.value = false;
            },
            onSuccess: () => {
                outcomeUnknown.value = false;
                lifecycleForm.version = props.customer.version;
                lifecycleForm.assignment_version =
                    props.customer.assignment_version;
                lifecycleForm.reset(
                    'reason',
                    'customer_explanation',
                    'confirmed',
                );
                lifecycleForm.attempt_reference = crypto.randomUUID();
            },
            onFinish: () => {
                if (!lifecycleForm.hasErrors && !lifecycleForm.wasSuccessful)
                    outcomeUnknown.value = true;
            },
        },
    );
}
async function lookupLifecycle(): Promise<void> {
    try {
        const result = (await lookup.get(
            operation.url({
                customer: props.customer.id,
                attempt_reference: lifecycleForm.attempt_reference,
            }),
        )) as { status: string; version: number };
        lookupNotice.value = `Confirmed original result: ${result.status}, version ${result.version}.`;
        outcomeUnknown.value = false;
        lifecycleForm.attempt_reference = crypto.randomUUID();
        lifecycleForm.reset('reason', 'customer_explanation', 'confirmed');
        router.reload();
    } catch {
        lookupNotice.value =
            'No committed result could be verified. Retry the original operation with its retained reference and unchanged details.';
    }
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Customers', href: customersIndex() },
            { title: 'Manage status', href: '#' },
        ],
    },
});
</script>

<template>
    <div class="mx-auto w-full max-w-4xl space-y-6">
        <Head :title="`Manage ${customer.name} status`" />
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">
                Manage Customer status
            </h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                {{ customer.name }} · {{ customer.id }}
            </p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>Current state</CardTitle>
                    <CardDescription>
                        Operational status and account access are managed
                        separately.
                    </CardDescription>
                </CardHeader>
                <CardContent class="flex flex-wrap gap-2">
                    <Badge variant="secondary">
                        Customer: {{ customer.operational_status_label }}
                    </Badge>
                    <Badge variant="outline">
                        Account: {{ customer.account_state_label }}
                    </Badge>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Current Agent</CardTitle>
                    <CardDescription v-if="customer.current_agent">
                        {{ customer.current_agent.name }} ·
                        {{ customer.current_agent.operational_status }} ·
                        account
                        {{ customer.current_agent.account_state ?? 'unknown' }}
                    </CardDescription>
                    <CardDescription v-else
                        >No current Agent is assigned.</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <p
                        :class="
                            customer.current_agent?.is_eligible
                                ? 'text-emerald-700 dark:text-emerald-300'
                                : 'text-muted-foreground'
                        "
                        class="text-sm"
                    >
                        {{
                            customer.current_agent?.is_eligible
                                ? 'This Agent can service this Customer.'
                                : (customer.current_agent
                                      ?.eligibility_message ??
                                  'Assign an eligible Agent before you activate this Customer.')
                        }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>{{
                    isRestoration ? 'Restore to Inactive' : 'Archive Customer'
                }}</CardTitle>
                <CardDescription>
                    {{
                        isRestoration
                            ? 'Restore operational participation to Inactive. Identity, account access, assignment and all prior archive history are retained.'
                            : 'End operational participation after every financial check is verified clear. Identity, login access, assignment and history remain retained.'
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent class="space-y-5">
                <ul
                    v-if="!isRestoration"
                    class="space-y-3"
                    aria-label="Archival checks"
                >
                    <li
                        v-for="check in lifecycle.checks"
                        :key="check.key"
                        class="rounded-lg border p-3"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-2"
                        >
                            <span class="font-medium">{{ check.label }}</span>
                            <Badge variant="outline">{{ check.status }}</Badge>
                        </div>
                        <p class="text-muted-foreground mt-1 text-sm">
                            {{ check.message }}
                        </p>
                        <Link
                            v-if="check.url && check.status !== 'passed'"
                            :href="check.url"
                            class="mt-2 inline-block text-sm underline"
                            >Review owning workflow</Link
                        >
                    </li>
                </ul>
                <p
                    v-else-if="!customer.current_agent?.is_eligible"
                    role="status"
                    class="text-sm"
                >
                    An eligible current Agent is required. An Admin with
                    reassignment permission must resolve the assignment
                    separately.
                </p>
                <p
                    v-if="customer.operational_status === 'restricted'"
                    role="status"
                    class="text-sm"
                >
                    Resolve the Customer restriction before archival.
                </p>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="lifecycleForm.processing || outcomeUnknown"
                    @click="router.reload({ only: ['customer', 'lifecycle'] })"
                    >Refresh checks</Button
                >
                <form class="space-y-4" @submit.prevent="submitLifecycle()">
                    <div class="space-y-2">
                        <Label for="lifecycle-reason">Internal reason</Label>
                        <textarea
                            id="lifecycle-reason"
                            v-model="lifecycleForm.reason"
                            required
                            maxlength="500"
                            :disabled="outcomeUnknown"
                            class="border-input bg-background min-h-24 w-full rounded-md border px-3 py-2 text-sm"
                        />
                        <p
                            v-if="lifecycleForm.errors.reason"
                            class="text-destructive text-sm"
                        >
                            {{ lifecycleForm.errors.reason }}
                        </p>
                    </div>
                    <div class="space-y-2">
                        <Label for="lifecycle-explanation"
                            >Customer-facing explanation</Label
                        >
                        <textarea
                            id="lifecycle-explanation"
                            v-model="lifecycleForm.customer_explanation"
                            required
                            maxlength="500"
                            :disabled="outcomeUnknown"
                            class="border-input bg-background min-h-24 w-full rounded-md border px-3 py-2 text-sm"
                        />
                        <p
                            v-if="lifecycleForm.errors.customer_explanation"
                            class="text-destructive text-sm"
                        >
                            {{ lifecycleForm.errors.customer_explanation }}
                        </p>
                    </div>
                    <label class="flex items-start gap-3 text-sm">
                        <input
                            v-model="lifecycleForm.confirmed"
                            type="checkbox"
                            required
                            :disabled="!lifecycleAllowed || outcomeUnknown"
                            class="mt-1"
                        />
                        <span
                            >I confirm {{ customer.operational_status_label }} →
                            {{ isRestoration ? 'Inactive' : 'Archived' }}.
                            Account access and financial history are
                            retained.</span
                        >
                    </label>
                    <p
                        v-for="(error, field) in lifecycleForm.errors"
                        :key="field"
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        {{ error }}
                    </p>
                    <p v-if="outcomeUnknown" role="alert" class="text-sm">
                        The result is uncertain. Check the original operation
                        before submitting again.
                    </p>
                    <p class="text-muted-foreground text-xs break-all">
                        Operation reference:
                        {{ lifecycleForm.attempt_reference }}
                    </p>
                    <p v-if="lookupNotice" role="status" class="text-sm">
                        {{ lookupNotice }}
                    </p>
                    <div class="flex flex-wrap gap-3">
                        <Button
                            type="submit"
                            :disabled="
                                !lifecycleAllowed ||
                                !lifecycleForm.confirmed ||
                                lifecycleForm.processing ||
                                outcomeUnknown
                            "
                            >{{
                                lifecycleForm.processing
                                    ? 'Saving…'
                                    : isRestoration
                                      ? 'Restore to Inactive'
                                      : 'Archive Customer'
                            }}</Button
                        >
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="
                                lookup.processing || lifecycleForm.processing
                            "
                            @click="lookupLifecycle"
                            >Check original operation</Button
                        >
                        <Button
                            v-if="outcomeUnknown"
                            type="button"
                            variant="outline"
                            :disabled="
                                lifecycleForm.processing || lookup.processing
                            "
                            @click="submitLifecycle(true)"
                        >
                            Retry original {{ lifecycleAction }} operation
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>

        <Card v-if="customer.operational_status !== 'archived'">
            <CardHeader>
                <CardTitle>Change operational status</CardTitle>
                <CardDescription>
                    The status change does not change account access,
                    credentials, sessions, assignment, or financial history.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form class="space-y-5" @submit.prevent="submit">
                    <div class="space-y-2">
                        <Label for="target-status">New status</Label>
                        <Select v-model="form.target_status"
                            ><SelectTrigger
                                id="target-status"
                                class="h-11 w-full"
                                ><SelectValue /></SelectTrigger
                            ><SelectContent>
                                <SelectItem
                                    v-for="target in allowed_targets"
                                    :key="target.value"
                                    :value="target.value"
                                >
                                    {{ target.label }}
                                </SelectItem>
                            </SelectContent></Select
                        >
                        <p
                            v-if="form.errors.target_status"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.target_status }}
                        </p>
                    </div>

                    <div class="space-y-2">
                        <Label for="status-reason">Internal reason</Label>
                        <textarea
                            id="status-reason"
                            v-model="form.reason"
                            maxlength="500"
                            rows="3"
                            required
                            class="border-input bg-background ring-offset-background focus-visible:ring-ring placeholder:text-muted-foreground w-full rounded-md border px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                        />
                        <p
                            v-if="form.errors.reason"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.reason }}
                        </p>
                    </div>

                    <div class="space-y-2">
                        <Label for="customer-explanation"
                            >Explanation shown to the Customer</Label
                        >
                        <textarea
                            id="customer-explanation"
                            v-model="form.customer_explanation"
                            maxlength="500"
                            rows="3"
                            required
                            class="border-input bg-background ring-offset-background focus-visible:ring-ring placeholder:text-muted-foreground w-full rounded-md border px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                        />
                        <p
                            v-if="form.errors.customer_explanation"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.customer_explanation }}
                        </p>
                    </div>

                    <label class="flex items-start gap-3 text-sm">
                        <input
                            v-model="form.confirmed"
                            type="checkbox"
                            class="border-input text-primary focus-visible:ring-ring mt-0.5 size-4 rounded"
                        />
                        <span>
                            I confirm the {{ form.target_status }} status change
                            and understand its effect on Customer activity.
                        </span>
                    </label>
                    <p
                        v-if="form.errors.confirmed"
                        class="text-destructive text-sm"
                    >
                        {{ form.errors.confirmed }}
                    </p>

                    <Alert>
                        <AlertTitle
                            >Financial details are unavailable</AlertTitle
                        >
                        <AlertDescription>
                            <ul class="list-disc space-y-1 pl-5">
                                <li>{{ financial_sections.summary }}</li>
                                <li>{{ financial_sections.plans }}</li>
                                <li>{{ financial_sections.collections }}</li>
                                <li>{{ financial_sections.withdrawals }}</li>
                            </ul>
                        </AlertDescription>
                    </Alert>

                    <div class="flex flex-wrap gap-3">
                        <Button type="submit" :disabled="form.processing">
                            {{
                                form.processing
                                    ? 'Saving…'
                                    : 'Confirm status change'
                            }}
                        </Button>
                        <Link :href="customerShow(customer.id)">
                            <Button type="button" variant="outline"
                                >Cancel</Button
                            >
                        </Link>
                    </div>
                </form>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Status history</CardTitle>
                <CardDescription>
                    Internal reasons are visible only to Admins with Customer
                    management access.
                </CardDescription>
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
                            {{ entry.effective_at }} (Africa/Lagos) ·
                            {{ entry.changed_by }}
                        </p>
                        <p class="mt-2 text-sm">
                            Internal reason: {{ entry.reason }}
                        </p>
                        <p
                            v-if="entry.customer_explanation"
                            class="text-muted-foreground mt-1 text-sm"
                        >
                            Customer explanation:
                            {{ entry.customer_explanation }}
                        </p>
                        <ul
                            v-if="entry.notifications.length > 0"
                            class="mt-2 space-y-1 text-xs"
                        >
                            <li
                                v-for="(
                                    notice, noticeIndex
                                ) in entry.notifications"
                                :key="noticeIndex"
                            >
                                {{ notice.audience }} · {{ notice.channel }} ·
                                {{ notice.status }}
                                <span
                                    v-if="notice.failure_reason"
                                    class="text-destructive"
                                >
                                    · {{ notice.failure_reason }}
                                </span>
                            </li>
                        </ul>
                    </li>
                </ol>
            </CardContent>
        </Card>
    </div>
</template>
