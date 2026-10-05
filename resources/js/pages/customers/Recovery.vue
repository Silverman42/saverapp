<script setup lang="ts">
import ManagementDeliveryPanel from '@/components/ManagementDeliveryPanel.vue';
import { Head, Link, router, useHttp } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { dashboard } from '@/routes';
import { index, show } from '@/routes/customers';
import { store, update, operation, review } from '@/routes/customers/recovery';
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
            message.value = 'Review the validation errors before confirming.';
            return;
        }
        sessionStorage.removeItem(key);
        uncertain.value = false;
        form.attempt_reference = crypto.randomUUID();
        form.confirmed = false;
        message.value = 'Recovery operation committed.';
        router.reload();
    } catch (error) {
        const status = (error as { response?: { status?: number } }).response
            ?.status;
        if (status && status >= 400 && status < 500) {
            uncertain.value = false;
            sessionStorage.removeItem(key);
        }
        message.value = uncertain.value
            ? 'The outcome is uncertain. Check the original operation.'
            : 'The operation was not committed. Check your access, verification and current request version.';
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
        message.value = `Original operation: ${result.state}.`;
        sessionStorage.removeItem(key);
        uncertain.value = false;
        form.attempt_reference = crypto.randomUUID();
        router.reload();
    } catch {
        message.value =
            'No committed result could be confirmed. Retain the reference and check again before submitting another operation.';
    }
}
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Customers', href: index() },
            { title: 'Assisted recovery', href: '#' },
        ],
    },
});
</script>
<template>
    <div class="mx-auto max-w-3xl space-y-6">
        <Head title="Customer assisted recovery" />
        <header>
            <h1 class="text-[25px] font-medium tracking-tight">
                Customer assisted recovery
            </h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                {{ customer.name }} · {{ customer.reference }}
            </p>
        </header>
        <p
            v-if="message"
            role="status"
            aria-live="polite"
            class="rounded-lg border p-4 text-sm"
        >
            {{ message }}
        </p>
        <Card v-if="recovery"
            ><CardHeader
                ><CardTitle>{{ recovery.state.replaceAll('_', ' ') }}</CardTitle
                ><CardDescription
                    >Current credentials stop working only after approval. The
                    Customer chooses their own password.</CardDescription
                ></CardHeader
            ><CardContent class="space-y-2 text-sm"
                ><p>Proposed email: {{ recovery.proposed_email }}</p>
                <p>Review deadline: {{ recovery.request_expires_at }}</p>
                <p v-if="recovery.activation_expires_at">
                    Activation deadline: {{ recovery.activation_expires_at }}
                </p>
                <p class="break-all">
                    Request: {{ recovery.reference }}
                </p></CardContent
            ></Card
        >
        <Card v-if="uncertain"
            ><CardContent class="space-y-4 pt-6"
                ><p class="text-sm break-all">
                    Original reference: {{ form.attempt_reference }}
                </p>
                <Button :disabled="lookup.processing" @click="resolveOutcome"
                    >Check operation</Button
                >
                <Button
                    v-if="canRetryOriginal"
                    variant="outline"
                    :disabled="form.processing"
                    @click="submit(action, true)"
                    >Retry original operation</Button
                ></CardContent
            ></Card
        >
        <Card v-else-if="can_initiate || (can_review && recovery)"
            ><CardContent class="pt-6"
                ><form
                    class="space-y-5"
                    @submit.prevent="
                        submit(
                            recovery && !terminal.includes(recovery.state)
                                ? 'verify'
                                : 'request',
                        )
                    "
                >
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
                        >
                            <Label for="new-email">Proposed new email</Label
                            ><Input
                                id="new-email"
                                v-model="form.email"
                                type="email"
                                class="mt-2"
                            />
                        </div>
                        <fieldset class="space-y-3 rounded-lg border p-4">
                            <legend class="px-2 font-medium">
                                In-person identity verification
                            </legend>
                            <label class="flex gap-3"
                                ><input
                                    v-model="form.in_person"
                                    type="checkbox"
                                />I met the Customer in person.</label
                            ><label class="flex gap-3"
                                ><input
                                    v-model="form.record_compared"
                                    type="checkbox"
                                />I compared their identity with the Customer
                                record.</label
                            >
                            <div>
                                <Label for="verified-at"
                                    >Verification time (ISO date and
                                    timezone)</Label
                                ><Input
                                    id="verified-at"
                                    v-model="form.verified_at"
                                    class="mt-2"
                                />
                            </div>
                            <div>
                                <Label for="procedure"
                                    >Approved procedure reference</Label
                                ><Input
                                    id="procedure"
                                    v-model="form.procedure_reference"
                                    maxlength="150"
                                    class="mt-2"
                                />
                            </div>
                            <div>
                                <Label for="verification-notes"
                                    >Protected verification notes</Label
                                ><textarea
                                    id="verification-notes"
                                    v-model="form.notes"
                                    maxlength="2000"
                                    class="border-input mt-2 min-h-24 w-full rounded-md border p-3"
                                />
                            </div></fieldset
                    ></template>
                    <div>
                        <Label for="decision-reason"
                            >Decision or verification reason</Label
                        ><Input
                            id="decision-reason"
                            v-model="form.reason"
                            maxlength="500"
                            class="mt-2"
                        />
                    </div>
                    <label class="flex items-start gap-3"
                        ><input
                            v-model="form.confirmed"
                            type="checkbox"
                            class="mt-1"
                        />I confirm this recovery action and its stated
                        effects.</label
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
                    <div class="flex flex-wrap gap-3">
                        <Button
                            v-if="
                                can_initiate &&
                                (!recovery || terminal.includes(recovery.state))
                            "
                            :disabled="form.processing || !form.confirmed"
                            >Submit verified request</Button
                        ><Button
                            v-if="
                                can_initiate &&
                                recovery &&
                                unapproved.includes(recovery.state)
                            "
                            :disabled="form.processing || !form.confirmed"
                            >Record current verification</Button
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
                                >Approve and revoke credentials</Button
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
                            >Reissue activation link</Button
                        ><Button
                            v-if="
                                recovery &&
                                !terminal.includes(recovery.state) &&
                                (can_review ||
                                    (can_initiate &&
                                        unapproved.includes(recovery.state)))
                            "
                            type="button"
                            variant="outline"
                            :disabled="form.processing || !form.confirmed"
                            @click="submit('cancel')"
                            >Cancel recovery</Button
                        >
                    </div>
                    <p v-if="can_review" class="text-muted-foreground text-sm">
                        Security decisions require fresh password and
                        authenticator confirmation.
                        <Link
                            :href="review.url(customer.reference)"
                            class="underline"
                            >Confirm authentication</Link
                        >
                        before making a decision.
                    </p>
                </form></CardContent
            ></Card
        >
        <Card v-if="!uncertain && !can_initiate && !recovery"
            ><CardContent class="pt-6"
                ><p class="text-muted-foreground text-sm">
                    No recovery request is waiting for review. To start a
                    recovery, the current Agent must record an in-person check.
                </p></CardContent
            ></Card
        >
        <Card v-if="events.length"
            ><CardHeader><CardTitle>Protected history</CardTitle></CardHeader
            ><CardContent
                ><ol class="space-y-4">
                    <li
                        v-for="(event, position) in events"
                        :key="position"
                        class="rounded-lg border p-4 text-sm"
                    >
                        <p class="font-medium">
                            {{
                                event.type
                                    .replace('auth.customer_recovery_', '')
                                    .replaceAll('_', ' ')
                            }}
                            · {{ event.at }}
                        </p>
                        <p>
                            Actor:
                            {{
                                event.actor_id ?? 'System / Customer activation'
                            }}
                        </p>
                        <dl class="mt-2 space-y-2">
                            <div
                                v-for="(value, field) in event.details"
                                :key="field"
                            >
                                <dt class="text-muted-foreground">
                                    {{ String(field).replaceAll('_', ' ') }}
                                </dt>
                                <dd class="break-words whitespace-pre-wrap">
                                    {{ value }}
                                </dd>
                            </div>
                        </dl>
                    </li>
                </ol></CardContent
            ></Card
        >
        <Card v-if="deliveries.length"
            ><CardHeader><CardTitle>Delivery outcomes</CardTitle></CardHeader
            ><CardContent
                ><ul class="space-y-3 text-sm">
                    <li
                        v-for="(delivery, position) in deliveries"
                        :key="position"
                    >
                        <p>
                            {{ delivery.purpose.replaceAll('_', ' ') }} ·
                            {{ delivery.channel }} ·
                            {{ delivery.status }}
                        </p>
                        <p
                            v-if="delivery.failure_reason"
                            class="text-muted-foreground"
                        >
                            {{ delivery.failure_reason }}
                        </p>
                    </li>
                </ul></CardContent
            ></Card
        >
        <Link :href="show.url(customer.reference)" class="text-sm underline"
            >Back to Customer</Link
        >
        <ManagementDeliveryPanel
            subject="customer"
            :reference="customer.reference"
        />
    </div>
</template>
