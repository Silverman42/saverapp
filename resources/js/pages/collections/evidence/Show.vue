<script setup lang="ts">
import {
    isOperationReference,
    newOperationReference,
} from '@/lib/operation-reference';
import { HttpResponseError } from '@inertiajs/core';
import { Head, Link, router, useHttp } from '@inertiajs/vue3';
import { onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
        message.value = `Original review posted as ${result.outcome}, version ${result.version}.`;
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
                'No posted review was found. Review the refreshed current details before retrying this reference.';
            clearAttempt();
            router.reload();
        } else
            message.value =
                'The result remains unavailable. Keep this reference and check again.';
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
        router.reload();
    } catch (error) {
        if (
            error instanceof HttpResponseError &&
            [403, 422, 429, 503].includes(error.response.status)
        ) {
            clearAttempt();
            message.value =
                'Review was not accepted. Check the errors, current authority, fresh authentication and protected file availability.';
        } else {
            uncertain.value = true;
            message.value =
                'Review outcome is uncertain or the version changed. Check this original operation before trying again.';
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
            'The protected file is unavailable or your current access changed.';
    }
}
</script>

<template>
    <Head title="Payment evidence detail" />
    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">
                Payment evidence detail
            </h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                {{ evidence.customer_name }} · {{ evidence.customer_id }}
            </p>
        </div>
        <Card
            ><CardHeader
                ><CardTitle>{{ evidence.method_label }}</CardTitle></CardHeader
            ><CardContent class="grid gap-3 text-sm">
                <p class="break-all">
                    Evidence {{ evidence.evidence_reference }}
                </p>
                <p>
                    {{
                        evidence.consumed
                            ? 'Consumed by receipt'
                            : evidence.status
                    }}
                    · review version {{ evidence.review_version }}
                </p>
                <p>
                    Claimed payment {{ money(evidence.amount_kobo) }} · received
                    {{ evidence.received_date }}
                </p>
                <p class="break-all">
                    Payment reference: {{ evidence.method_reference }}
                </p>
                <p>Configured destination: {{ evidence.destination_key }}</p>
                <p class="whitespace-pre-line">
                    Source attestation: {{ evidence.source_attestation }}
                </p>
                <p v-if="evidence.review_reason" class="whitespace-pre-line">
                    Review reason: {{ evidence.review_reason }}
                </p>
                <Button
                    v-for="file in evidence.files"
                    :key="file.id"
                    type="button"
                    variant="outline"
                    class="w-fit"
                    :disabled="fileRequest.processing"
                    @click="download(file.id)"
                    >Download protected {{ file.mime_type }} ·
                    {{ Math.ceil(file.byte_size / 1024) }} KB</Button
                >
                <Link
                    v-if="
                        can_record &&
                        evidence.status === 'verified' &&
                        !evidence.consumed
                    "
                    :href="
                        create(evidence.customer_id, {
                            query: { evidence: evidence.evidence_reference },
                        })
                    "
                    class="underline"
                    >Record receipt using this verified proof</Link
                >
            </CardContent></Card
        >
        <p v-if="message" role="status" aria-live="polite">{{ message }}</p>
        <div v-if="uncertain && can_check_review" class="grid gap-3">
            <p class="break-all">
                Check operation {{ form.operation_reference }} before another
                review.
            </p>
            <Button
                type="button"
                variant="outline"
                class="w-fit"
                :disabled="resultRequest.processing"
                @click="check"
                >Check original review result</Button
            >
        </div>
        <Card v-if="can_review"
            ><CardHeader
                ><CardTitle
                    >Independent payment verification</CardTitle
                ></CardHeader
            ><CardContent>
                <form @submit.prevent="submit" class="grid gap-4">
                    <p class="text-muted-foreground text-sm">
                        Independently confirm the bank or terminal record. Enter
                        the actual reference, amount and destination you
                        matched. Verification does not post money.
                    </p>
                    <fieldset
                        :disabled="form.processing || uncertain"
                        class="grid gap-4 sm:grid-cols-2"
                    >
                        <div class="grid gap-2">
                            <Label for="review-outcome">Outcome</Label
                            ><select
                                id="review-outcome"
                                v-model="form.outcome"
                                class="bg-background h-11 rounded-md border px-3 text-sm"
                            >
                                <option value="verified">
                                    Verified payment
                                </option>
                                <option value="rejected">Rejected claim</option>
                            </select>
                        </div>
                        <template v-if="form.outcome === 'verified'">
                            <div class="grid gap-2">
                                <Label for="matched-reference"
                                    >Independently matched reference</Label
                                ><Input
                                    id="matched-reference"
                                    v-model="form.verified_reference"
                                    maxlength="120"
                                    required
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="matched-amount"
                                    >Independently matched amount, NGN</Label
                                ><Input
                                    id="matched-amount"
                                    v-model="form.verified_amount_ngn"
                                    inputmode="decimal"
                                    required
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="matched-destination"
                                    >Independently matched destination</Label
                                ><Input
                                    id="matched-destination"
                                    v-model="form.verified_destination_key"
                                    maxlength="100"
                                    required
                                />
                            </div>
                        </template>
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="review-reason">Review reason</Label
                            ><textarea
                                id="review-reason"
                                v-model="form.reason"
                                class="bg-background min-h-24 rounded-md border p-3 text-sm"
                                minlength="10"
                                maxlength="2000"
                                required
                            />
                        </div>
                        <label
                            class="flex items-start gap-2 text-sm sm:col-span-2"
                            ><input
                                v-model="confirmed"
                                type="checkbox"
                                class="mt-1"
                                required
                            />I independently checked the payment claim and
                            confirm this outcome.</label
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
                    <Button
                        type="submit"
                        class="w-fit"
                        :disabled="form.processing || uncertain || !confirmed"
                        >{{
                            form.processing
                                ? 'Saving review…'
                                : 'Save independent review'
                        }}</Button
                    >
                </form>
            </CardContent></Card
        >
    </div>
</template>
