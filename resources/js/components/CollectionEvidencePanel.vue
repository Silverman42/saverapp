<script setup lang="ts">
import {
    isOperationReference,
    newOperationReference,
} from '@/lib/operation-reference';
import { HttpResponseError } from '@inertiajs/core';
import { Link, useHttp } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref } from 'vue';
import MoreDetails from '@/components/MoreDetails.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { show, view } from '@/routes/collection-evidence';
import { store } from '@/routes/customers/collection-evidence';
import type {
    CollectionMethod,
    PaymentEvidence,
} from '@/types/collection-evidence';

const props = defineProps<{
    customer: {
        id: string;
        resource_id: number;
        version: number;
        assignment_version: number | null;
    };
    methods: CollectionMethod[];
    today: string;
    disabled: boolean;
    initialReference?: string | null;
}>();
const emit = defineEmits<{ selected: [PaymentEvidence | null] }>();
const form = useHttp({
    evidence_reference: '',
    customer_version: props.customer.version,
    assignment_version: props.customer.assignment_version ?? 0,
    collection_method_version_id: props.methods[0]?.id ?? 0,
    method_reference: '',
    received_date: props.today,
    amount_ngn: '',
    source_attestation: '',
    files: [] as File[],
});
const lookup = useHttp<Record<string, never>, PaymentEvidence>({});
const reference = ref('');
const proof = ref<PaymentEvidence | null>(null);
const uncertain = ref(false);
const errorSummary = ref<HTMLElement | null>(null);
const notice = ref('');
const key = `collection-evidence-attempt:${props.customer.id}`;
const lookupOpen = computed(
    () => uncertain.value || Boolean(props.initialReference),
);
const statusLabels: Record<PaymentEvidence['status'], string> = {
    pending: 'Waiting for check',
    verified: 'Checked',
    rejected: 'Rejected',
};
const money = (amount: number) =>
    `₦${(amount / 100).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
function remember(): void {
    try {
        sessionStorage.setItem(key, form.evidence_reference);
    } catch {
        /* The open page retains the attempt. */
    }
}
onMounted(() => {
    try {
        const saved = sessionStorage.getItem(key);
        reference.value = isOperationReference(saved) ? saved : '';
    } catch {
        /* Tab storage is optional. */
    }
    reference.value = props.initialReference || reference.value;
    form.evidence_reference = reference.value || newOperationReference();
    uncertain.value = reference.value !== '';
    if (reference.value) void check();
});
function accept(result: PaymentEvidence): void {
    if (result.customer_id !== props.customer.id) {
        notice.value =
            'This proof is for a different customer. Use this customer’s proof.';
        emit('selected', null);
        return;
    }
    proof.value = result;
    reference.value = result.evidence_reference;
    form.evidence_reference = result.evidence_reference;
    remember();
    uncertain.value = false;
    const usable =
        result.status === 'verified' &&
        !result.consumed &&
        props.methods.some(
            (method) => method.id === result.collection_method_version_id,
        );
    emit('selected', usable ? result : null);
    notice.value = result.consumed
        ? 'This proof was already used for a payment. It cannot be used again.'
        : usable
          ? 'Proof selected. Use the same date and total amount below.'
          : result.status === 'pending'
            ? 'Proof saved. An admin needs to check it before you can record the payment.'
            : result.status === 'rejected'
              ? 'This proof was rejected. Read the reason before recording any money.'
              : 'This payment method is not available for new payments.';
}
async function check(): Promise<void> {
    if (props.disabled || !reference.value) return;
    notice.value = '';
    if (!isOperationReference(reference.value.trim())) {
        notice.value = 'Enter the full proof number.';
        return;
    }
    try {
        accept(await lookup.get(show.url(reference.value.trim())));
    } catch (error) {
        if (
            error instanceof HttpResponseError &&
            error.response.status === 404
        ) {
            uncertain.value = false;
            notice.value =
                'No saved proof found. Enter the details and files again.';
        } else {
            notice.value =
                'We could not check this proof. Keep the number and try again.';
        }
    }
}
async function upload(): Promise<void> {
    if (props.disabled || uncertain.value || proof.value || form.processing)
        return;
    reference.value = form.evidence_reference;
    remember();
    notice.value = '';
    try {
        const result = (await form.post(
            store.url(props.customer.resource_id),
        )) as PaymentEvidence | undefined;
        if (result === undefined) {
            notice.value =
                'Proof was not saved. Fix the errors below and try again.';
            await nextTick();
            errorSummary.value?.focus();
            return;
        }
        accept(result);
    } catch (error) {
        uncertain.value = !(
            error instanceof HttpResponseError &&
            [403, 409, 422, 429, 503].includes(error.response.status)
        );
        notice.value = uncertain.value
            ? 'We are not sure the upload worked. Check the proof number before uploading again.'
            : 'Proof was not saved. Fix the errors below and try again.';
    }
}
function newProof(): void {
    if (props.disabled || uncertain.value || form.processing) return;
    proof.value = null;
    reference.value = '';
    form.evidence_reference = newOperationReference();
    form.method_reference = '';
    form.amount_ngn = '';
    form.source_attestation = '';
    form.files = [];
    form.clearErrors();
    notice.value = '';
    emit('selected', null);
    remember();
}
function filesChanged(event: Event): void {
    form.files = Array.from((event.target as HTMLInputElement).files ?? []);
}
</script>

<template>
    <section class="grid gap-5" aria-labelledby="evidence-heading">
        <div>
            <h3 id="evidence-heading" class="text-sm font-medium">
                Payment proof
            </h3>
            <p class="text-muted-foreground mt-1 text-sm">
                Add proof of the transfer or POS payment. An admin checks it
                before you record the payment.
            </p>
        </div>
        <p
            v-if="notice"
            role="status"
            aria-live="polite"
            class="bg-muted rounded-xl p-3 text-sm"
        >
            {{ notice }}
        </p>
        <div v-if="proof" class="grid gap-3 rounded-xl border p-4 text-sm">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="font-medium">
                    {{ money(proof.amount_kobo) }} · {{ proof.method_label }}
                </p>
                <Badge variant="secondary">{{
                    proof.consumed ? 'Already used' : statusLabels[proof.status]
                }}</Badge>
            </div>
            <p class="text-muted-foreground text-xs">
                Paid {{ proof.received_date }}
            </p>
            <p v-if="proof.review_reason" class="text-sm">
                Review note: {{ proof.review_reason }}
            </p>
            <div class="flex flex-wrap items-center gap-3">
                <Link
                    :href="view(proof.evidence_reference)"
                    class="text-sm font-medium underline-offset-4 hover:underline"
                    >View proof</Link
                >
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="disabled"
                    @click="newProof"
                    >Use different proof</Button
                >
            </div>
        </div>
        <form v-else class="grid gap-4" @submit.prevent="upload">
            <fieldset
                :disabled="disabled || uncertain || form.processing"
                class="grid gap-4 sm:grid-cols-2"
            >
                <legend class="sr-only">New payment proof</legend>
                <div class="grid gap-2">
                    <Label for="evidence-method">Paid by</Label
                    ><Select
                        :model-value="String(form.collection_method_version_id)"
                        :disabled="disabled || uncertain || form.processing"
                        @update:model-value="
                            form.collection_method_version_id = Number($event)
                        "
                    >
                        <SelectTrigger id="evidence-method" class="w-full"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="method in methods"
                                :key="method.id"
                                :value="String(method.id)"
                            >
                                {{ method.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div class="grid gap-2">
                    <Label for="evidence-reference">Payment reference</Label
                    ><Input
                        id="evidence-reference"
                        v-model="form.method_reference"
                        maxlength="120"
                        required
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="evidence-date">Date paid</Label
                    ><DatePicker
                        id="evidence-date"
                        v-model="form.received_date"
                        :disabled="disabled || uncertain || form.processing"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="evidence-amount">Exact amount (NGN)</Label
                    ><Input
                        id="evidence-amount"
                        v-model="form.amount_ngn"
                        inputmode="decimal"
                        required
                    />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="evidence-source"
                        >How do you know it was paid?</Label
                    ><textarea
                        id="evidence-source"
                        v-model="form.source_attestation"
                        class="bg-background min-h-20 rounded-md border p-3 text-sm"
                        minlength="10"
                        maxlength="2000"
                        placeholder="For example: customer showed me the bank alert"
                        required
                    />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="evidence-files">Photos or files</Label
                    ><input
                        id="evidence-files"
                        type="file"
                        accept="image/jpeg,image/png,image/webp,application/pdf"
                        multiple
                        class="text-sm"
                        @change="filesChanged"
                    />
                    <p class="text-muted-foreground text-xs">
                        Up to 3 photos or PDFs, 5 MB each. Some methods need a
                        file.
                    </p>
                </div>
            </fieldset>
            <div
                v-if="form.hasErrors"
                ref="errorSummary"
                role="alert"
                tabindex="-1"
                class="text-destructive text-sm"
            >
                <p v-for="(error, field) in form.errors" :key="field">
                    {{ error }}
                </p>
            </div>
            <progress
                v-if="form.progress"
                :value="form.progress.percentage"
                max="100"
                aria-label="Proof upload progress"
            />
            <Button
                type="submit"
                variant="outline"
                class="w-fit"
                :disabled="
                    disabled ||
                    uncertain ||
                    form.processing ||
                    methods.length === 0
                "
                >{{
                    form.processing
                        ? 'Uploading and checking files…'
                        : 'Save proof'
                }}</Button
            >
        </form>
        <MoreDetails
            :key="String(lookupOpen)"
            :default-open="lookupOpen"
            label="Use a saved proof number"
        >
            <fieldset
                :disabled="disabled || form.processing || lookup.processing"
                class="grid gap-3"
            >
                <legend class="sr-only">Saved proof</legend>
                <Label for="existing-evidence">Proof number</Label>
                <Input
                    id="existing-evidence"
                    v-model="reference"
                    maxlength="36"
                    :readonly="uncertain || !!proof"
                />
                <Button
                    type="button"
                    variant="outline"
                    class="w-fit"
                    :disabled="!reference"
                    @click="check"
                    >Check proof</Button
                >
            </fieldset>
        </MoreDetails>
    </section>
</template>
