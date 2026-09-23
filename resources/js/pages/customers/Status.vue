<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
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

type StatusValue = 'active' | 'inactive' | 'restricted';

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
    <Head :title="`Manage ${customer.name} status`" />
    <div class="mx-auto w-full max-w-4xl space-y-6">
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
                                ? 'Eligible to service this Customer.'
                                : (customer.current_agent
                                      ?.eligibility_message ??
                                  'A current eligible Agent is required before activating this Customer.')
                        }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <Card>
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
                        <select
                            id="target-status"
                            v-model="form.target_status"
                            class="border-input bg-background ring-offset-background focus-visible:ring-ring h-11 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
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
