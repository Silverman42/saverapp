<script setup lang="ts">
import ManagementDeliveryPanel from '@/components/ManagementDeliveryPanel.vue';
import { Head, Link, router, useHttp } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { dashboard } from '@/routes';
import { index, show } from '@/routes/customers';
import { store, update, operation, review } from '@/routes/customers/recovery';
import { ShieldCheck } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import MoreDetails from '@/components/MoreDetails.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
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
import { showToast } from '@/lib/flashToast';
const props = defineProps<{
    customer: {
        reference: string;
        name: string;
        version: number;
        assignment_version: number;
    };
    recovery: {
        reference: string;
        version: number;
        state: string;
        proposed_email: string;
        request_expires_at: string;
        activation_expires_at: string | null;
    } | null;
    can_review: boolean;
    can_initiate: boolean;
    events: Array<{
        type: string;
        at: string;
        actor_id: number | null;
        details: Record<string, unknown>;
    }>;
    deliveries: Array<{
        channel: string;
        purpose: string;
        status: string;
        updated_at: string;
        failure_reason: string | null;
    }>;
}>();
const key = `recovery-reference:${props.customer.reference}`;
const retained =
    typeof window !== 'undefined' ? sessionStorage.getItem(key) : null;
const uncertain = ref(!!retained);
const canRetryOriginal = ref(false);
const message = ref('');
const action = ref('request');
const form = useHttp({
    attempt_reference: retained ?? crypto.randomUUID(),
    confirmed: false,
    version: props.customer.version,
    assignment_version: props.customer.assignment_version,
    recovery_version: props.recovery?.version ?? 1,
    email: '',
    in_person: false,
    record_compared: false,
    verified_at: new Date().toISOString(),
    procedure_reference: '',
    notes: '',
    reason: '',
});
const lookup = useHttp({});
watch(
    () => [
        props.customer.version,
        props.customer.assignment_version,
        props.recovery?.version,
    ],
    () => {
        if (!uncertain.value) {
            form.version = props.customer.version;
            form.assignment_version = props.customer.assignment_version;
            form.recovery_version = props.recovery?.version ?? 1;
        }
    },
);
const unapproved = ['awaiting_approval', 'verification_required'];
const terminal = ['rejected', 'cancelled', 'expired', 'completed'];
async function submit(
    nextAction: string,
    retryOriginal = false,
): Promise<void> {
    if ((uncertain.value && !retryOriginal) || !form.confirmed) return;
    canRetryOriginal.value = true;
    action.value = nextAction;
    sessionStorage.setItem(key, form.attempt_reference);
    uncertain.value = true;
    try {
        const url =
            nextAction === 'request'
                ? store.url(props.customer.reference)
                : update.url({
                      customer: props.customer.reference,
                      recovery: props.recovery!.reference,
                      action: nextAction,
                  });
        const result = await form.post(url);
        if (!result || form.hasErrors) {
            uncertain.value = false;
            sessionStorage.removeItem(key);
            message.value = 'Please fix the errors below and try again.';
            return;
        }
        sessionStorage.removeItem(key);
        uncertain.value = false;
        form.attempt_reference = crypto.randomUUID();
        form.confirmed = false;
        message.value = 'Saved.';
        showToast({
            type: 'success',
            title: 'Recovery updated',
            description: 'The account recovery step was saved.',
        });
        router.reload();
    } catch (error) {
        const status = (error as { response?: { status?: number } }).response
            ?.status;
        if (status && status >= 400 && status < 500) {
            uncertain.value = false;
            sessionStorage.removeItem(key);
        }
        message.value = uncertain.value
            ? 'We are not sure this was saved. Check the result before trying again.'
            : 'This was not saved. Check your access and the details, then reload the page.';
    }
}
async function resolveOutcome(): Promise<void> {
    try {
        const result = (await lookup.get(
            operation.url({
                customer: props.customer.reference,
                attempt_reference: form.attempt_reference,
            }),
        )) as { state: string };
        message.value = `Result: ${result.state.replaceAll('_', ' ')}.`;
        sessionStorage.removeItem(key);
        uncertain.value = false;
        form.attempt_reference = crypto.randomUUID();
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
            { title: 'Account access help', href: '#' },
        ],
    },
});
</script>
<template>
    <div class="mx-auto max-w-3xl space-y-6">
        <Head title="Customer assisted recovery" />
        <PageHeader
            title="Account access help"
            :description="`Give ${customer.name} new login details after checking who they are.`"
        >
            <template #actions>
                <Button as-child variant="outline">
                    <Link :href="show.url(customer.reference)"
                        >Back to customer</Link
                    >
                </Button>
            </template>
        </PageHeader>

        <p
            v-if="message"
            role="status"
            aria-live="polite"
            class="rounded-xl border p-4 text-sm"
        >
            {{ message }}
        </p>

        <Card v-if="recovery">
            <CardHeader
                class="flex flex-row flex-wrap items-start justify-between gap-3"
            >
                <div class="space-y-1.5">
                    <CardTitle class="text-base">Current request</CardTitle>
                    <CardDescription
                        >Old login details keep working until this is approved.
                        The customer picks their own password.</CardDescription
                    >
                </div>
                <Badge variant="secondary" class="capitalize">{{
                    recovery.state.replaceAll('_', ' ')
                }}</Badge>
            </CardHeader>
            <CardContent class="space-y-4">
                <dl class="grid gap-3 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-muted-foreground">New email</dt>
                        <dd class="mt-0.5 break-all">
                            {{ recovery.proposed_email }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Review by</dt>
                        <dd class="mt-0.5">
                            {{ recovery.request_expires_at }}
                        </dd>
                    </div>
                    <div v-if="recovery.activation_expires_at">
                        <dt class="text-muted-foreground">Set up by</dt>
                        <dd class="mt-0.5">
                            {{ recovery.activation_expires_at }}
                        </dd>
                    </div>
                </dl>
                <MoreDetails>
                    <p class="text-muted-foreground text-xs break-all">
                        Request reference: {{ recovery.reference }}
                    </p>
                </MoreDetails>
            </CardContent>
        </Card>

        <Card v-if="uncertain">
            <CardHeader>
                <CardTitle class="text-base"
                    >We're not sure the last step was saved</CardTitle
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
                        @click="submit(action, true)"
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
        <Card v-else-if="can_initiate || (can_review && recovery)">
            <CardContent>
                <form
                    class="space-y-5"
                    @submit.prevent="
                        submit(
                            recovery && !terminal.includes(recovery.state)
                                ? 'verify'
                                : 'request',
                        )
                    "
                >
                    <p
                        v-if="can_review"
                        class="bg-muted/50 rounded-lg p-3 text-sm"
                    >
                        Before you decide, confirm your password and
                        authenticator code.
                        <Link
                            :href="review.url(customer.reference)"
                            class="font-medium underline underline-offset-4"
                            >Confirm now</Link
                        >
                    </p>
                    <template
                        v-if="
                            can_initiate &&
                            (!recovery ||
                                terminal.includes(recovery.state) ||
                                unapproved.includes(recovery.state))
                        "
                        ><div
                            v-if="
                                !recovery || terminal.includes(recovery.state)
                            "
                            class="space-y-2"
                        >
                            <Label for="new-email">New email</Label
                            ><Input
                                id="new-email"
                                v-model="form.email"
                                type="email"
                            />
                        </div>
                        <fieldset class="space-y-4 rounded-xl border p-4">
                            <legend class="px-2 text-sm font-medium">
                                Check their identity in person
                            </legend>
                            <label class="flex gap-3 text-sm"
                                ><input
                                    v-model="form.in_person"
                                    type="checkbox"
                                />I met the customer in person.</label
                            ><label class="flex gap-3 text-sm"
                                ><input
                                    v-model="form.record_compared"
                                    type="checkbox"
                                />I checked their ID against their customer
                                record.</label
                            >
                            <div class="space-y-2">
                                <Label for="procedure"
                                    >Procedure reference</Label
                                ><Input
                                    id="procedure"
                                    v-model="form.procedure_reference"
                                    maxlength="150"
                                />
                            </div>
                            <div class="space-y-2">
                                <Label for="verification-notes"
                                    >Private notes</Label
                                ><textarea
                                    id="verification-notes"
                                    v-model="form.notes"
                                    maxlength="2000"
                                    class="border-input min-h-24 w-full rounded-md border p-3 text-sm"
                                />
                            </div>
                            <MoreDetails label="More options">
                                <div class="space-y-2">
                                    <Label for="verified-at"
                                        >When you checked</Label
                                    ><Input
                                        id="verified-at"
                                        v-model="form.verified_at"
                                    />
                                    <p class="text-muted-foreground text-xs">
                                        Filled in with the current time. Only
                                        change it if you checked earlier.
                                    </p>
                                </div>
                            </MoreDetails>
                        </fieldset></template
                    >
                    <div class="space-y-2">
                        <Label for="decision-reason">Reason</Label
                        ><Input
                            id="decision-reason"
                            v-model="form.reason"
                            maxlength="500"
                        />
                    </div>
                    <label class="flex items-start gap-3 text-sm"
                        ><input
                            v-model="form.confirmed"
                            type="checkbox"
                            class="mt-1"
                        />I understand what this step does.</label
                    >
                    <ul
                        v-if="form.hasErrors"
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        <li v-for="(error, field) in form.errors" :key="field">
                            {{ error }}
                        </li>
                    </ul>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-if="
                                can_initiate &&
                                (!recovery || terminal.includes(recovery.state))
                            "
                            :disabled="form.processing || !form.confirmed"
                            >Send request</Button
                        ><Button
                            v-if="
                                can_initiate &&
                                recovery &&
                                unapproved.includes(recovery.state)
                            "
                            :disabled="form.processing || !form.confirmed"
                            >Save check</Button
                        ><template
                            v-if="
                                can_review &&
                                recovery &&
                                unapproved.includes(recovery.state)
                            "
                            ><Button
                                type="button"
                                :disabled="
                                    form.processing ||
                                    !form.confirmed ||
                                    recovery.state !== 'awaiting_approval'
                                "
                                @click="submit('approve')"
                                >Approve</Button
                            ><Button
                                type="button"
                                variant="outline"
                                :disabled="form.processing || !form.confirmed"
                                @click="submit('reject')"
                                >Reject</Button
                            ></template
                        ><Button
                            v-if="
                                can_review &&
                                recovery &&
                                [
                                    'awaiting_activation',
                                    'activation_expired',
                                ].includes(recovery.state)
                            "
                            type="button"
                            :disabled="form.processing || !form.confirmed"
                            @click="submit('reissue')"
                            >Send new link</Button
                        ><Button
                            v-if="
                                recovery &&
                                !terminal.includes(recovery.state) &&
                                (can_review ||
                                    (can_initiate &&
                                        unapproved.includes(recovery.state)))
                            "
                            type="button"
                            variant="ghost"
                            class="text-destructive"
                            :disabled="form.processing || !form.confirmed"
                            @click="submit('cancel')"
                            >Cancel request</Button
                        >
                    </div>
                    <p
                        v-if="
                            can_review &&
                            recovery &&
                            unapproved.includes(recovery.state)
                        "
                        class="text-muted-foreground text-xs"
                    >
                        Approving stops the old login details from working.
                    </p>
                </form>
            </CardContent>
        </Card>
        <EmptyState
            v-if="!uncertain && !can_initiate && !recovery"
            :icon="ShieldCheck"
            title="No request to review"
            description="The customer's agent must check their identity in person to start one."
        />
        <Card v-if="events.length || deliveries.length">
            <CardHeader>
                <CardTitle class="text-base">History</CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">
                <MoreDetails
                    v-if="events.length"
                    :label="`Show ${events.length} step${events.length === 1 ? '' : 's'}`"
                >
                    <ol class="divide-y">
                        <li
                            v-for="(event, position) in events"
                            :key="position"
                            class="space-y-1 py-3 text-sm"
                        >
                            <p class="font-medium capitalize">
                                {{
                                    event.type
                                        .replace('auth.customer_recovery_', '')
                                        .replaceAll('_', ' ')
                                }}
                                <span class="text-muted-foreground font-normal"
                                    >· {{ event.at }}</span
                                >
                            </p>
                            <p class="text-muted-foreground">
                                By:
                                {{ event.actor_id ?? 'System or customer' }}
                            </p>
                            <dl class="mt-1 space-y-1 text-xs">
                                <div
                                    v-for="(value, field) in event.details"
                                    :key="field"
                                >
                                    <dt
                                        class="text-muted-foreground capitalize"
                                    >
                                        {{ String(field).replaceAll('_', ' ') }}
                                    </dt>
                                    <dd class="break-words whitespace-pre-wrap">
                                        {{ value }}
                                    </dd>
                                </div>
                            </dl>
                        </li>
                    </ol>
                </MoreDetails>
                <MoreDetails v-if="deliveries.length" label="Messages sent">
                    <ul class="divide-y">
                        <li
                            v-for="(delivery, position) in deliveries"
                            :key="position"
                            class="py-2 text-sm"
                        >
                            <p class="capitalize">
                                {{ delivery.purpose.replaceAll('_', ' ') }} ·
                                {{ delivery.channel }} ·
                                {{ delivery.status }}
                            </p>
                            <p
                                v-if="delivery.failure_reason"
                                class="text-muted-foreground text-xs"
                            >
                                {{ delivery.failure_reason }}
                            </p>
                        </li>
                    </ul>
                </MoreDetails>
            </CardContent>
        </Card>
        <ManagementDeliveryPanel
            subject="customer"
            :reference="customer.reference"
        />
    </div>
</template>
