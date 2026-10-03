<script setup lang="ts">
import {
    isOperationReference,
    newOperationReference,
} from '@/lib/operation-reference';
import { router, useHttp } from '@inertiajs/vue3';
import { HttpResponseError } from '@inertiajs/core';
import { onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { DatePicker } from '@/components/ui/date-picker';
import { store, show } from '@/routes/collection-batches/settlements';
import { link } from '@/routes/collection-settlements/files';

const props = defineProps<{
    batchId: number;
    batchVersion: number;
    date: string;
    timezone: string;
    outstandingKobo: number;
    canRecord: boolean;
    banks: {
        id: number;
        label: string;
        version: number;
        destination_key: string;
    }[];
    settlements: {
        reference: string;
        amount_kobo: number;
        date: string;
        bank_reference: string;
        files: { id: number }[];
    }[];
}>();
const key = `clearing-settlement-attempt-${props.batchId}`;
const form = useHttp({
    settlement_reference: '',
    batch_version: props.batchVersion,
    bank_method_version_id: props.banks[0]?.id ?? 0,
    bank_reference: '',
    amount_ngn: '',
    settled_date: props.date,
    source_attestation: '',
    reason: '',
    confirmed: false,
    files: [] as File[],
});
onMounted(() => {
    try {
        const saved = sessionStorage.getItem(key);
        form.settlement_reference = isOperationReference(saved)
            ? saved
            : newOperationReference();
        sessionStorage.setItem(key, form.settlement_reference);
    } catch {
        form.settlement_reference = newOperationReference();
    }
});
const statusRequest = useHttp<
    Record<string, never>,
    { status: string; amount_kobo: number }
>({});
const fileRequest = useHttp<Record<string, never>, { url: string }>({});
const message = ref('');
const uncertain = ref(false);
const posted = ref(false);
watch(
    () => props.batchVersion,
    (version) => {
        if (!uncertain.value && !posted.value) {
            form.batch_version = version;
        }
    },
);
const money = (kobo: number): string => `NGN ${(kobo / 100).toFixed(2)}`;
function chooseFiles(event: Event): void {
    form.files = Array.from((event.target as HTMLInputElement).files ?? []);
}
async function submit(): Promise<void> {
    message.value = '';
    uncertain.value = false;
    try {
        await form.post(store.url(props.batchId));
        posted.value = true;
        message.value =
            'Bank settlement recorded. Review the updated batch separately.';
        router.reload();
    } catch (error) {
        if (
            error instanceof HttpResponseError &&
            error.response.status === 409
        ) {
            try {
                const rejection = JSON.parse(error.response.data);
                if (rejection.status === 'rejected') {
                    uncertain.value = false;
                    message.value = rejection.message;
                    router.reload();
                    return;
                }
            } catch {
                uncertain.value = true;
            }
        }
        if (form.hasErrors) {
            message.value =
                'Correct the highlighted fields and review the bank evidence again.';
        } else {
            uncertain.value = true;
            message.value =
                'Settlement is not confirmed. Check this saved reference before retrying.';
        }
    }
}
async function checkStatus(): Promise<void> {
    try {
        const result = await statusRequest.get(
            show.url([props.batchId, form.settlement_reference]),
        );
        posted.value = result.status === 'posted';
        uncertain.value = !posted.value;
        message.value = `Verified saved settlement: ${money(result.amount_kobo)}. Refresh the batch before another settlement.`;
        router.reload();
    } catch {
        message.value =
            'The saved settlement could not be confirmed. Keep this reference and the original bank evidence; reauthenticate or retry the same request.';
    }
}
function startAnother(): void {
    form.reset();
    form.settlement_reference = newOperationReference();
    try {
        sessionStorage.setItem(key, form.settlement_reference);
    } catch {
        message.value = 'Keep the saved settlement reference when retrying.';
    }
    form.batch_version = props.batchVersion;
    form.confirmed = false;
    posted.value = false;
    uncertain.value = false;
    message.value = '';
}
async function download(reference: string, file: number): Promise<void> {
    try {
        const result = await fileRequest.get(link.url([reference, file]));
        window.location.assign(result.url);
    } catch {
        message.value =
            'Settlement evidence is unavailable or your current access needs renewal.';
    }
}
</script>
<template>
    <section class="grid gap-4" aria-label="Clearing settlement">
        <h2 class="font-medium">Bank settlement</h2>
        <p class="text-muted-foreground text-sm">
            Confirm money actually credited to the selected bank. Outstanding
            clearing:
            {{ money(outstandingKobo) }}. Settlement preserves Customer savings
            and fee income. Processor deductions remain unresolved until their
            separate expense policy is approved.
        </p>
        <form
            v-if="canRecord && banks.length && !posted"
            class="grid gap-4 sm:grid-cols-2"
            @submit.prevent="submit"
        >
            <div class="grid gap-2 sm:col-span-2">
                <Label for="settlement-attempt"
                    >Saved settlement reference</Label
                >
                <Input
                    id="settlement-attempt"
                    :model-value="form.settlement_reference"
                    readonly
                />
                <Button
                    type="button"
                    variant="outline"
                    class="w-fit"
                    :disabled="statusRequest.processing || form.processing"
                    @click="checkStatus"
                    >Check saved status</Button
                >
            </div>
            <div class="grid gap-2">
                <Label for="settlement-bank">Verified bank destination</Label>
                <select
                    id="settlement-bank"
                    v-model="form.bank_method_version_id"
                    class="bg-background min-h-11 rounded-md border p-2 text-sm"
                    :disabled="uncertain || form.processing"
                >
                    <option
                        v-for="bank in banks"
                        :key="bank.id"
                        :value="bank.id"
                    >
                        {{ bank.label }} · v{{ bank.version }} ·
                        {{ bank.destination_key }}
                    </option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="settlement-reference">Bank credit reference</Label>
                <Input
                    id="settlement-reference"
                    v-model="form.bank_reference"
                    :disabled="uncertain || form.processing"
                />
            </div>
            <div class="grid gap-2">
                <Label for="settlement-amount">Actual bank amount (NGN)</Label>
                <Input
                    id="settlement-amount"
                    v-model="form.amount_ngn"
                    inputmode="decimal"
                    :disabled="uncertain || form.processing"
                />
            </div>
            <div class="grid gap-2">
                <Label for="settlement-date"
                    >Settlement date ({{ timezone }})</Label
                >
                <DatePicker
                    id="settlement-date"
                    v-model="form.settled_date"
                    :disabled="uncertain || form.processing"
                />
            </div>
            <div class="grid gap-2 sm:col-span-2">
                <Label for="settlement-source"
                    >Independent bank credit verification</Label
                >
                <Input
                    id="settlement-source"
                    v-model="form.source_attestation"
                    :disabled="uncertain || form.processing"
                />
            </div>
            <div class="grid gap-2 sm:col-span-2">
                <Label for="settlement-reason">Review reason</Label>
                <Input
                    id="settlement-reason"
                    v-model="form.reason"
                    :disabled="uncertain || form.processing"
                />
            </div>
            <div class="grid gap-2 sm:col-span-2">
                <Label for="settlement-files">Private bank evidence</Label>
                <Input
                    id="settlement-files"
                    type="file"
                    multiple
                    accept="image/jpeg,image/png,image/webp,application/pdf"
                    :disabled="uncertain || form.processing"
                    @change="chooseFiles"
                />
                <p class="text-muted-foreground text-sm">
                    One to three JPEG, PNG, WebP or PDF files, up to 5 MB each.
                    Uploads require a clean scan.
                </p>
            </div>
            <label class="flex items-start gap-2 text-sm sm:col-span-2"
                ><input
                    v-model="form.confirmed"
                    type="checkbox"
                    :disabled="uncertain || form.processing"
                />
                I independently matched the actual credit, reference and amount
                to this bank destination.</label
            >
            <progress
                v-if="form.progress"
                :value="form.progress.percentage"
                max="100"
                aria-label="Settlement evidence upload"
            />
            <Button
                type="submit"
                class="w-fit"
                :disabled="
                    form.processing ||
                    !form.confirmed ||
                    !form.settlement_reference
                "
                >{{
                    uncertain
                        ? 'Retry the same settlement'
                        : 'Record actual settlement'
                }}</Button
            >
            <ul
                v-if="form.hasErrors"
                class="text-destructive grid gap-1 text-sm sm:col-span-2"
                role="alert"
            >
                <li v-for="(error, field) in form.errors" :key="field">
                    {{ field }}: {{ error }}
                </li>
            </ul>
        </form>
        <p
            v-if="canRecord && !banks.length"
            role="status"
            class="text-muted-foreground text-sm"
        >
            A configured bank destination is required before settlement can be
            recorded.
        </p>
        <p v-if="message" role="status" aria-live="polite" class="text-sm">
            {{ message }}
        </p>
        <Button
            v-if="posted && canRecord"
            type="button"
            variant="outline"
            class="w-fit"
            @click="startAnother"
            >Prepare another settlement</Button
        >
        <ul class="grid gap-3">
            <li
                v-for="settlement in settlements"
                :key="settlement.reference"
                class="grid gap-2 rounded-md border p-3 text-sm"
            >
                <span
                    >{{ settlement.date }} ·
                    {{ money(settlement.amount_kobo) }} ·
                    {{ settlement.bank_reference }}</span
                >
                <span class="text-muted-foreground break-all">{{
                    settlement.reference
                }}</span>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-for="file in settlement.files"
                        :key="file.id"
                        type="button"
                        variant="outline"
                        :disabled="fileRequest.processing"
                        @click="download(settlement.reference, file.id)"
                        >Download evidence {{ file.id }}</Button
                    >
                </div>
            </li>
        </ul>
    </section>
</template>
