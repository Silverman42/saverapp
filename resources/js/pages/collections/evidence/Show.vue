<script setup lang="ts">
import {
    isOperationReference,
    newOperationReference,
} from '@/lib/operation-reference';
import { HttpResponseError } from '@inertiajs/core';
import { Head, Link, router, useHttp } from '@inertiajs/vue3';
import { onMounted, ref, watch } from 'vue';
import FormSheet from '@/components/FormSheet.vue';
import MoreDetails from '@/components/MoreDetails.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { dashboard } from '@/routes';
import { index, review } from '@/routes/collection-evidence';
import { link } from '@/routes/collection-evidence/files';
import { show as reviewResult } from '@/routes/collection-evidence/reviews';
import { index as collections } from '@/routes/collections';
import { create } from '@/routes/customers/collections';
import type { PaymentEvidence } from '@/types/collection-evidence';

const props = defineProps<{
    evidence: PaymentEvidence;
    can_review: boolean;
    can_record: boolean;
    can_check_review: boolean;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Collections', href: collections() },
            { title: 'Payment evidence', href: index() },
            { title: 'Evidence detail', href: '#' },
        ],
    },
});
const form = useHttp({
    operation_reference: '',
    expected_version: props.evidence.review_version,
    outcome: 'verified',
    reason: '',
    verified_reference: '',
    verified_amount_ngn: '',
    verified_destination_key: '',
});
const confirmed = ref(false);
const uncertain = ref(false);
const message = ref('');
const sheetOpen = ref(false);
const statusLabels: Record<PaymentEvidence['status'], string> = {
    pending: 'Waiting for check',
    verified: 'Checked',
    rejected: 'Rejected',
};
const key = `collection-evidence-review:${props.evidence.evidence_reference}`;
const resultRequest = useHttp<
    Record<string, never>,
    { status: string; outcome: string; version: number }
>({});
const fileRequest = useHttp<Record<string, never>, { url: string }>({});
const money = (amount: number) =>
    `₦${(amount / 100).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
onMounted(() => {
    try {
        const saved = sessionStorage.getItem(key);
        form.operation_reference = isOperationReference(saved) ? saved : '';
    } catch {
        /* Tab storage is optional. */
    }
    uncertain.value = form.operation_reference !== '';
    if (!form.operation_reference)
        form.operation_reference = newOperationReference();
});
watch(
    () => props.evidence.review_version,
    (version) => {
        if (!uncertain.value) {
            form.expected_version = version;
            form.operation_reference = newOperationReference();
            confirmed.value = false;
        }
    },
);
function clearAttempt(): void {
    try {
        sessionStorage.removeItem(key);
    } catch {
        /* The result is durable. */
    }
}
async function check(): Promise<void> {
    message.value = '';
    try {
        const result = await resultRequest.get(
            reviewResult.url({
                reference: props.evidence.evidence_reference,
                operation: form.operation_reference,
            }),
        );
        message.value = `Your last review was saved as ${result.outcome}.`;
        clearAttempt();
        uncertain.value = false;
        form.operation_reference = newOperationReference();
        confirmed.value = false;
        router.reload();
    } catch (error) {
        if (
            error instanceof HttpResponseError &&
            error.response.status === 404
        ) {
            uncertain.value = false;
            message.value =
                'Your last review was not saved. Check the details and try again.';
            clearAttempt();
            router.reload();
        } else
            message.value =
                'We could not check right now. Try again in a moment.';
    }
}
async function submit(): Promise<void> {
    if (
        !props.can_review ||
        !confirmed.value ||
        uncertain.value ||
        form.processing
    )
        return;
    message.value = '';
    try {
        sessionStorage.setItem(key, form.operation_reference);
    } catch {
        /* The open page retains the attempt. */
    }
    try {
        await form.post(review.url(props.evidence.evidence_reference));
        clearAttempt();
        form.operation_reference = newOperationReference();
        confirmed.value = false;
        sheetOpen.value = false;
        message.value = 'Review saved.';
        router.reload();
    } catch (error) {
        if (
            error instanceof HttpResponseError &&
            [403, 422, 429, 503].includes(error.response.status)
        ) {
            clearAttempt();
            message.value =
                'Your review was not saved. Fix the errors below, or sign in again if asked.';
        } else {
            uncertain.value = true;
            message.value =
                'We are not sure your review was saved. Check the result before trying again.';
        }
    }
}
async function download(id: number): Promise<void> {
    try {
        window.location.assign(
            (
                await fileRequest.get(
                    link.url({
                        reference: props.evidence.evidence_reference,
                        file: id,
                    }),
                )
            ).url,
        );
    } catch {
        message.value =
            'This file is not available right now. Refresh the page and try again.';
    }
}
</script>

<template>
    <Head title="Payment evidence detail" />
    <div class="flex flex-col gap-6">
        <PageHeader
            title="Payment proof"
            :description="`From ${evidence.customer_name}.`"
        >
            <template
                v-if="
                    (can_record &&
                        evidence.status === 'verified' &&
                        !evidence.consumed) ||
                    can_review
                "
                #actions
            >
                <Button
                    v-if="
                        can_record &&
                        evidence.status === 'verified' &&
                        !evidence.consumed
                    "
                    as-child
                    ><Link
                        :href="
                            create(evidence.customer_id, {
                                query: {
                                    evidence: evidence.evidence_reference,
                                },
                            })
                        "
                        >Record payment</Link
                    ></Button
                >
                <Button
                    v-if="can_review"
                    type="button"
                    :variant="
                        can_record &&
                        evidence.status === 'verified' &&
                        !evidence.consumed
                            ? 'outline'
                            : 'default'
                    "
                    @click="sheetOpen = true"
                    >Check payment</Button
                >
            </template>
        </PageHeader>

        <p
            v-if="message && !sheetOpen"
            role="status"
            aria-live="polite"
            class="bg-muted rounded-xl p-4 text-sm"
        >
            {{ message }}
        </p>
        <div
            v-if="uncertain && can_check_review"
            class="bg-muted flex flex-wrap items-center justify-between gap-3 rounded-xl p-4 text-sm"
        >
            <p>Check your last review before saving another.</p>
            <Button
                type="button"
                variant="outline"
                :disabled="resultRequest.processing"
                @click="check"
                >Check result</Button
            >
        </div>

        <Card>
            <CardHeader
                class="flex flex-row flex-wrap items-center justify-between gap-3"
            >
                <CardTitle>{{ evidence.method_label }}</CardTitle>
                <Badge variant="secondary">{{
                    evidence.consumed
                        ? 'Used for a payment'
                        : statusLabels[evidence.status]
                }}</Badge>
            </CardHeader>
            <CardContent class="space-y-5 text-sm">
                <div>
                    <p class="text-muted-foreground">Amount claimed</p>
                    <p class="mt-1 text-2xl font-semibold">
                        {{ money(evidence.amount_kobo) }}
                    </p>
                </div>
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-muted-foreground">Date paid</dt>
                        <dd class="mt-1 font-medium">
                            {{ evidence.received_date }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Payment reference</dt>
                        <dd class="mt-1 font-medium break-all">
                            {{ evidence.method_reference }}
                        </dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-muted-foreground">Note from sender</dt>
                        <dd class="mt-1 whitespace-pre-line">
                            {{ evidence.source_attestation }}
                        </dd>
                    </div>
                    <div v-if="evidence.review_reason" class="sm:col-span-2">
                        <dt class="text-muted-foreground">Review note</dt>
                        <dd class="mt-1 whitespace-pre-line">
                            {{ evidence.review_reason }}
                        </dd>
                    </div>
                </dl>
                <div v-if="evidence.files.length" class="flex flex-wrap gap-2">
                    <Button
                        v-for="(file, index) in evidence.files"
                        :key="file.id"
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="fileRequest.processing"
                        @click="download(file.id)"
                        >Download file
                        {{ evidence.files.length > 1 ? index + 1 : '' }} ({{
                            Math.ceil(file.byte_size / 1024)
                        }}
                        KB)</Button
                    >
                </div>
                <MoreDetails>
                    <dl
                        class="text-muted-foreground grid gap-1 text-xs break-all"
                    >
                        <div>
                            <dt class="text-foreground inline">Customer ID:</dt>
                            <dd class="inline">{{ evidence.customer_id }}</dd>
                        </div>
                        <div>
                            <dt class="text-foreground inline">
                                Proof number:
                            </dt>
                            <dd class="inline">
                                {{ evidence.evidence_reference }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-foreground inline">Paid into:</dt>
                            <dd class="inline">
                                {{ evidence.destination_key }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-foreground inline">
                                Times reviewed:
                            </dt>
                            <dd class="inline">
                                {{ evidence.review_version }}
                            </dd>
                        </div>
                        <div v-for="file in evidence.files" :key="file.id">
                            <dt class="text-foreground inline">File type:</dt>
                            <dd class="inline">{{ file.mime_type }}</dd>
                        </div>
                    </dl>
                </MoreDetails>
            </CardContent>
        </Card>

        <FormSheet
            v-if="can_review"
            v-model:open="sheetOpen"
            title="Check payment"
            description="Compare with the bank or POS record. This does not record the payment."
        >
            <form id="review-form" class="grid gap-5" @submit.prevent="submit">
                <p
                    v-if="message"
                    role="status"
                    aria-live="polite"
                    class="bg-muted rounded-xl p-3 text-sm"
                >
                    {{ message }}
                </p>
                <fieldset
                    :disabled="form.processing || uncertain"
                    class="grid gap-5"
                >
                    <legend class="sr-only">Payment check</legend>
                    <div class="grid gap-2">
                        <Label for="review-outcome">Result</Label
                        ><Select v-model="form.outcome"
                            ><SelectTrigger
                                id="review-outcome"
                                class="h-11 w-full"
                                ><SelectValue /></SelectTrigger
                            ><SelectContent>
                                <SelectItem value="verified"
                                    >Payment is correct</SelectItem
                                >
                                <SelectItem value="rejected"
                                    >Reject this proof</SelectItem
                                >
                            </SelectContent></Select
                        >
                    </div>
                    <template v-if="form.outcome === 'verified'">
                        <p class="text-muted-foreground text-xs">
                            Enter what you see on the bank or POS record.
                        </p>
                        <div class="grid gap-2">
                            <Label for="matched-reference">Reference</Label
                            ><Input
                                id="matched-reference"
                                v-model="form.verified_reference"
                                maxlength="120"
                                required
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="matched-amount">Amount (NGN)</Label
                            ><Input
                                id="matched-amount"
                                v-model="form.verified_amount_ngn"
                                inputmode="decimal"
                                required
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="matched-destination"
                                >Account it was paid into</Label
                            ><Input
                                id="matched-destination"
                                v-model="form.verified_destination_key"
                                maxlength="100"
                                required
                            />
                        </div>
                    </template>
                    <div class="grid gap-2">
                        <Label for="review-reason">Note</Label
                        ><textarea
                            id="review-reason"
                            v-model="form.reason"
                            class="bg-background min-h-24 rounded-md border p-3 text-sm"
                            minlength="10"
                            maxlength="2000"
                            required
                        />
                    </div>
                    <label class="flex items-start gap-2 text-sm"
                        ><input
                            v-model="confirmed"
                            type="checkbox"
                            class="mt-1"
                            required
                        />I checked this payment myself.</label
                    >
                </fieldset>
                <div
                    v-if="form.hasErrors"
                    role="alert"
                    class="text-destructive text-sm"
                >
                    <p v-for="(error, field) in form.errors" :key="field">
                        {{ error }}
                    </p>
                </div>
            </form>
            <template #footer>
                <Button
                    type="button"
                    variant="outline"
                    @click="sheetOpen = false"
                    >Cancel</Button
                >
                <Button
                    type="submit"
                    form="review-form"
                    :disabled="form.processing || uncertain || !confirmed"
                    >{{ form.processing ? 'Saving…' : 'Save review' }}</Button
                >
            </template>
        </FormSheet>
    </div>
</template>
