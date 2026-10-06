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
import { RefreshCw } from '@lucide/vue';
import FormSheet from '@/components/FormSheet.vue';
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

const statusSheetOpen = ref(false);
const lifecycleSheetOpen = ref(false);

const textareaClass =
    'border-input bg-background ring-offset-background focus-visible:ring-ring placeholder:text-muted-foreground min-h-24 w-full rounded-md border px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';

const checkLabels: Record<'passed' | 'blocked' | 'unavailable', string> = {
    passed: 'Clear',
    blocked: 'Needs action',
    unavailable: "Can't check",
};

const targetLabel = (value: string): string =>
    props.allowed_targets.find((target) => target.value === value)?.label ??
    value;

const submit = (): void => {
    form.patch(updateCustomerStatus(props.customer.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            statusSheetOpen.value = false;
        },
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
                lifecycleSheetOpen.value = false;
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
                if (outcomeUnknown.value) {
                    lifecycleSheetOpen.value = false;
                }
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
        lookupNotice.value = `Last attempt result: ${result.status.replaceAll('_', ' ')}.`;
        outcomeUnknown.value = false;
        lifecycleForm.attempt_reference = crypto.randomUUID();
        lifecycleForm.reset('reason', 'customer_explanation', 'confirmed');
        router.reload();
    } catch {
        lookupNotice.value =
            'We could not find a saved result. Try again with the same details.';
    }
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Customers', href: customersIndex() },
            { title: 'Status', href: '#' },
        ],
    },
});
</script>

<template>
    <div class="mx-auto w-full max-w-4xl space-y-6">
        <Head :title="`Manage ${customer.name} status`" />
        <PageHeader title="Customer status" :description="customer.name">
            <template #actions>
                <Button
                    v-if="customer.operational_status !== 'archived'"
                    @click="statusSheetOpen = true"
                    >Change status</Button
                >
                <Button
                    :variant="isRestoration ? 'default' : 'outline'"
                    :disabled="outcomeUnknown"
                    @click="lifecycleSheetOpen = true"
                    >{{ isRestoration ? 'Restore' : 'Archive' }}</Button
                >
            </template>
        </PageHeader>

        <Card>
            <CardContent>
                <dl class="grid gap-4 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-muted-foreground">Status</dt>
                        <dd class="mt-1">
                            <Badge variant="secondary">{{
                                customer.operational_status_label
                            }}</Badge>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Account</dt>
                        <dd class="mt-1">
                            <Badge variant="outline">{{
                                customer.account_state_label
                            }}</Badge>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Agent</dt>
                        <dd class="mt-1 font-medium">
                            {{ customer.current_agent?.name ?? 'No agent' }}
                        </dd>
                    </div>
                </dl>
                <p
                    class="mt-4 text-sm"
                    :class="
                        customer.current_agent?.is_eligible
                            ? 'text-emerald-700 dark:text-emerald-300'
                            : 'text-muted-foreground'
                    "
                >
                    {{
                        customer.current_agent?.is_eligible
                            ? 'This agent can serve this customer.'
                            : (customer.current_agent?.eligibility_message ??
                              'Assign an agent who can serve this customer before you make them active.')
                    }}
                </p>
            </CardContent>
        </Card>

        <div
            v-if="outcomeUnknown"
            role="alert"
            class="space-y-3 rounded-xl border p-4 text-sm"
        >
            <p class="font-medium">We're not sure the last change was saved</p>
            <p class="text-muted-foreground">
                Check the result before you try again.
            </p>
            <div class="flex flex-wrap gap-2">
                <Button
                    type="button"
                    :disabled="lookup.processing || lifecycleForm.processing"
                    @click="lookupLifecycle"
                    >Check result</Button
                >
                <Button
                    type="button"
                    variant="outline"
                    :disabled="lifecycleForm.processing || lookup.processing"
                    @click="submitLifecycle(true)"
                    >Try again</Button
                >
            </div>
        </div>
        <p v-if="lookupNotice" role="status" class="text-sm">
            {{ lookupNotice }}
        </p>

        <Card v-if="!isRestoration">
            <CardHeader
                class="flex flex-row flex-wrap items-start justify-between gap-3"
            >
                <div class="space-y-1.5">
                    <CardTitle class="text-base">Before archiving</CardTitle>
                    <CardDescription
                        >All of these must be clear to archive.</CardDescription
                    >
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    :disabled="lifecycleForm.processing || outcomeUnknown"
                    @click="router.reload({ only: ['customer', 'lifecycle'] })"
                    ><RefreshCw class="size-4" /> Refresh</Button
                >
            </CardHeader>
            <CardContent class="space-y-3">
                <ul class="divide-y" aria-label="Archival checks">
                    <li
                        v-for="check in lifecycle.checks"
                        :key="check.key"
                        class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0"
                    >
                        <div class="min-w-0 text-sm">
                            <p class="font-medium">{{ check.label }}</p>
                            <p
                                v-if="check.status !== 'passed'"
                                class="text-muted-foreground mt-0.5"
                            >
                                {{ check.message }}
                            </p>
                            <Link
                                v-if="check.url && check.status !== 'passed'"
                                :href="check.url"
                                class="mt-1 inline-block text-sm font-medium underline underline-offset-4"
                                >Open</Link
                            >
                        </div>
                        <Badge
                            :variant="
                                check.status === 'passed'
                                    ? 'outline'
                                    : check.status === 'blocked'
                                      ? 'destructive'
                                      : 'secondary'
                            "
                            >{{ checkLabels[check.status] }}</Badge
                        >
                    </li>
                </ul>
                <p
                    v-if="customer.operational_status === 'restricted'"
                    role="status"
                    class="text-sm"
                >
                    Remove the restriction before you archive this customer.
                </p>
            </CardContent>
        </Card>
        <p
            v-else-if="!customer.current_agent?.is_eligible"
            role="status"
            class="bg-muted rounded-xl p-4 text-sm"
        >
            To restore this customer, an admin who can change agents must first
            give them an agent who can serve them.
        </p>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">History</CardTitle>
            </CardHeader>
            <CardContent>
                <p
                    v-if="history.length === 0"
                    class="text-muted-foreground text-sm"
                >
                    No status changes yet.
                </p>
                <ol v-else class="divide-y">
                    <li
                        v-for="(entry, index) in history"
                        :key="`${entry.effective_at}-${index}`"
                        class="space-y-1 py-3 text-sm first:pt-0 last:pb-0"
                    >
                        <p class="font-medium">
                            {{ entry.from_status }} to {{ entry.to_status }}
                        </p>
                        <p class="text-muted-foreground text-xs">
                            {{ entry.effective_at }} · {{ entry.changed_by }}
                        </p>
                        <p>Reason: {{ entry.reason }}</p>
                        <p
                            v-if="entry.customer_explanation"
                            class="text-muted-foreground"
                        >
                            Told the customer: {{ entry.customer_explanation }}
                        </p>
                        <MoreDetails
                            v-if="entry.notifications.length > 0"
                            label="Messages sent"
                        >
                            <ul class="space-y-1 text-xs">
                                <li
                                    v-for="(
                                        notice, noticeIndex
                                    ) in entry.notifications"
                                    :key="noticeIndex"
                                    class="capitalize"
                                >
                                    {{ notice.audience }} ·
                                    {{ notice.channel }} · {{ notice.status }}
                                    <span
                                        v-if="notice.failure_reason"
                                        class="text-destructive normal-case"
                                    >
                                        · {{ notice.failure_reason }}
                                    </span>
                                </li>
                            </ul>
                        </MoreDetails>
                    </li>
                </ol>
                <p class="text-muted-foreground mt-4 text-xs">
                    Reasons are only shown to admins. Times are Lagos time.
                </p>
            </CardContent>
        </Card>

        <FormSheet
            v-if="customer.operational_status !== 'archived'"
            v-model:open="statusSheetOpen"
            title="Change status"
            description="This does not change their login, agent or money records."
        >
            <form id="status-form" class="space-y-5" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label for="target-status">New status</Label>
                    <Select v-model="form.target_status"
                        ><SelectTrigger id="target-status" class="h-11 w-full"
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
                    <Label for="status-reason">Reason (staff only)</Label>
                    <textarea
                        id="status-reason"
                        v-model="form.reason"
                        maxlength="500"
                        rows="3"
                        required
                        :class="textareaClass"
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
                        >Message to the customer</Label
                    >
                    <textarea
                        id="customer-explanation"
                        v-model="form.customer_explanation"
                        maxlength="500"
                        rows="3"
                        required
                        :class="textareaClass"
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
                        Change status to
                        {{ targetLabel(form.target_status) }}.
                    </span>
                </label>
                <p
                    v-if="form.errors.confirmed"
                    class="text-destructive text-sm"
                >
                    {{ form.errors.confirmed }}
                </p>

                <MoreDetails label="What stays the same">
                    <ul
                        class="text-muted-foreground list-disc space-y-1 pl-5 text-xs"
                    >
                        <li>{{ financial_sections.summary }}</li>
                        <li>{{ financial_sections.plans }}</li>
                        <li>{{ financial_sections.collections }}</li>
                        <li>{{ financial_sections.withdrawals }}</li>
                    </ul>
                </MoreDetails>
            </form>
            <template #footer>
                <Button
                    type="button"
                    variant="outline"
                    @click="statusSheetOpen = false"
                    >Cancel</Button
                >
                <Button
                    type="submit"
                    form="status-form"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'Saving…' : 'Save' }}
                </Button>
            </template>
        </FormSheet>

        <FormSheet
            v-model:open="lifecycleSheetOpen"
            :title="isRestoration ? 'Restore customer' : 'Archive customer'"
            :description="
                isRestoration
                    ? 'They will be set to Inactive. Their login, agent and history stay the same.'
                    : 'They will stop taking part. Their login, agent and history are kept.'
            "
        >
            <form
                id="lifecycle-form"
                class="space-y-5"
                @submit.prevent="submitLifecycle()"
            >
                <p
                    v-if="!lifecycleAllowed"
                    role="status"
                    class="bg-muted rounded-lg p-3 text-sm"
                >
                    {{
                        isRestoration
                            ? 'This customer needs an agent who can serve them first.'
                            : 'Clear every check on the page before you archive.'
                    }}
                </p>
                <div class="space-y-2">
                    <Label for="lifecycle-reason">Reason (staff only)</Label>
                    <textarea
                        id="lifecycle-reason"
                        v-model="lifecycleForm.reason"
                        required
                        maxlength="500"
                        :disabled="outcomeUnknown"
                        :class="textareaClass"
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
                        >Message to the customer</Label
                    >
                    <textarea
                        id="lifecycle-explanation"
                        v-model="lifecycleForm.customer_explanation"
                        required
                        maxlength="500"
                        :disabled="outcomeUnknown"
                        :class="textareaClass"
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
                        >Change from {{ customer.operational_status_label }} to
                        {{ isRestoration ? 'Inactive' : 'Archived' }}. Their
                        login and money history are kept.</span
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
                <MoreDetails>
                    <div class="space-y-3">
                        <p class="text-muted-foreground text-xs break-all">
                            Reference: {{ lifecycleForm.attempt_reference }}
                        </p>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="
                                lookup.processing || lifecycleForm.processing
                            "
                            @click="lookupLifecycle"
                            >Check last attempt</Button
                        >
                    </div>
                </MoreDetails>
            </form>
            <template #footer>
                <Button
                    type="button"
                    variant="outline"
                    @click="lifecycleSheetOpen = false"
                    >Cancel</Button
                >
                <Button
                    type="submit"
                    form="lifecycle-form"
                    :variant="isRestoration ? 'default' : 'destructive'"
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
                              ? 'Restore'
                              : 'Archive'
                    }}</Button
                >
            </template>
        </FormSheet>
    </div>
</template>
