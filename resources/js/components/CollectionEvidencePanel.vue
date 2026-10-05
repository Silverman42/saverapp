<script setup lang="ts">
import {
    isOperationReference,
    newOperationReference,
} from '@/lib/operation-reference';
import { HttpResponseError } from '@inertiajs/core';
import { Link, useHttp } from '@inertiajs/vue3';
import { nextTick, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
            'This proof belongs to another Customer. Choose this Customer’s evidence.';
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
        ? 'This proof already funds a receipt. It cannot fund another.'
        : usable
          ? 'Verified proof selected. Match its received date and exact total in the receipt below.'
          : result.status === 'pending'
            ? 'Evidence saved. Wait for independent Admin verification before recording this receipt.'
            : result.status === 'rejected'
              ? 'Evidence was rejected. Review the reason before recording money.'
              : 'This method is currently unavailable for new receipts.';
}
async function check(): Promise<void> {
    if (props.disabled || !reference.value) return;
    notice.value = '';
    if (!isOperationReference(reference.value.trim())) {
        notice.value =
            'Enter the complete evidence reference from its saved record.';
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
                'No saved evidence was found. Re-enter the original details and files to retry this reference.';
        } else {
            notice.value =
                'The evidence could not be checked. Keep its reference and try again.';
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
                'Evidence was not accepted. Check the errors, current assignment, method availability and scanner status before retrying.';
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
            ? 'Upload outcome unknown. Check this reference before uploading again.'
            : 'Evidence was not accepted. Check the errors, current assignment, method availability and scanner status before retrying.';
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
    <Card>
        <CardHeader
            ><CardTitle>Protected payment evidence</CardTitle></CardHeader
        >
        <CardContent class="grid gap-5">
            <p class="text-muted-foreground text-sm">
                Upload proof of the actual payment. Upload and verification do
                not post savings or fees.
            </p>
            <fieldset
                :disabled="disabled || form.processing || lookup.processing"
                class="grid gap-3"
            >
                <Label for="existing-evidence"
                    >Existing evidence reference</Label
                >
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
                    >Check evidence status</Button
                >
            </fieldset>
            <p v-if="notice" role="status" aria-live="polite" class="text-sm">
                {{ notice }}
            </p>
            <div v-if="proof" class="grid gap-2 text-sm">
                <p>
                    {{ proof.method_label }} · {{ money(proof.amount_kobo) }} ·
                    received {{ proof.received_date }} ·
                    {{ proof.consumed ? 'consumed' : proof.status }}
                </p>
                <p v-if="proof.review_reason">
                    Review reason: {{ proof.review_reason }}
                </p>
                <Link :href="view(proof.evidence_reference)" class="underline"
                    >Open protected evidence</Link
                >
                <Button
                    type="button"
                    variant="outline"
                    class="w-fit"
                    :disabled="disabled"
                    @click="newProof"
                    >Upload a different payment</Button
                >
            </div>
            <form v-else @submit.prevent="upload" class="grid gap-4">
                <fieldset
                    :disabled="disabled || uncertain || form.processing"
                    class="grid gap-4 sm:grid-cols-2"
                >
                    <div class="grid gap-2">
                        <Label for="evidence-method">Configured method</Label
                        ><Select
                            :model-value="
                                String(form.collection_method_version_id)
                            "
                            :disabled="disabled || uncertain || form.processing"
                            @update:model-value="
                                form.collection_method_version_id =
                                    Number($event)
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
                                    {{ method.label }} ·
                                    {{ method.destination_key }}
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
                        <Label for="evidence-date">Actual received date</Label
                        ><DatePicker
                            id="evidence-date"
                            v-model="form.received_date"
                            :disabled="disabled || uncertain || form.processing"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="evidence-amount"
                            >Exact payment amount, NGN</Label
                        ><Input
                            id="evidence-amount"
                            v-model="form.amount_ngn"
                            inputmode="decimal"
                            required
                        />
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="evidence-source">Source attestation</Label
                        ><textarea
                            id="evidence-source"
                            v-model="form.source_attestation"
                            class="bg-background min-h-24 rounded-md border p-3 text-sm"
                            minlength="10"
                            maxlength="2000"
                            required
                        />
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="evidence-files">Protected proof files</Label
                        ><input
                            id="evidence-files"
                            type="file"
                            accept="image/jpeg,image/png,image/webp,application/pdf"
                            multiple
                            class="text-sm"
                            @change="filesChanged"
                        />
                        <p class="text-muted-foreground text-sm">
                            Attach up to three JPEG, PNG, WebP or PDF files.
                            Each file can be up to 5 MB. Some methods require
                            attachments.
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
                    aria-label="Evidence upload progress"
                />
                <Button
                    type="submit"
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
                            : 'Save evidence for independent review'
                    }}</Button
                >
            </form>
        </CardContent>
    </Card>
</template>
