<script setup lang="ts">
import {
    isOperationReference,
    newOperationReference,
} from '@/lib/operation-reference';
import { router, useHttp } from '@inertiajs/vue3';
import { HttpResponseError } from '@inertiajs/core';
import { onMounted, ref, watch } from 'vue';
import FormSheet from '@/components/FormSheet.vue';
import MoreDetails from '@/components/MoreDetails.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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
const sheetOpen = ref(false);
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
        sheetOpen.value = false;
        message.value = 'Bank deposit saved. Review the batch again.';
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
            message.value = 'Please fix the fields below and try again.';
        } else {
            uncertain.value = true;
            message.value =
                'We could not confirm this was saved. Check its status before you try again.';
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
        if (posted.value) {
            sheetOpen.value = false;
        }
        message.value = `Saved deposit found: ${money(result.amount_kobo)}. Refresh the batch before adding another.`;
        router.reload();
    } catch {
        message.value =
            'We could not check this deposit. Keep the reference and bank proof, sign in again if asked, then retry.';
    }
}
function startAnother(): void {
    form.reset();
    form.settlement_reference = newOperationReference();
    try {
        sessionStorage.setItem(key, form.settlement_reference);
    } catch {
        message.value = 'Keep the saved reference if you need to retry.';
    }
    form.batch_version = props.batchVersion;
    form.confirmed = false;
    posted.value = false;
    uncertain.value = false;
    message.value = '';
    sheetOpen.value = true;
}
async function download(reference: string, file: number): Promise<void> {
    try {
        const result = await fileRequest.get(link.url([reference, file]));
        window.location.assign(result.url);
    } catch {
        message.value =
            'This file is not available right now. Refresh the page and try again.';
    }
}
</script>
<template>
    <section class="grid gap-4" aria-labelledby="settlement-heading">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 id="settlement-heading" class="font-medium">
                    Bank deposits
                </h2>
                <p class="text-muted-foreground mt-1 text-sm">
                    Still to reach the bank: {{ money(outstandingKobo) }}
                </p>
            </div>
            <Button
                v-if="canRecord && banks.length && !posted"
                type="button"
                @click="sheetOpen = true"
                >{{ uncertain ? 'Retry deposit' : 'Record deposit' }}</Button
            >
            <Button
                v-if="posted && canRecord"
                type="button"
                variant="outline"
                @click="startAnother"
                >Record another</Button
            >
        </div>
        <p
            v-if="canRecord && !banks.length"
            role="status"
            class="bg-muted rounded-xl p-4 text-sm"
        >
            Add a bank account in collection methods before you record a
            deposit.
        </p>
        <p
            v-if="message && !sheetOpen"
            role="status"
            aria-live="polite"
            class="text-sm"
        >
            {{ message }}
        </p>
        <ul v-if="settlements.length" class="divide-y text-sm">
            <li
                v-for="settlement in settlements"
                :key="settlement.reference"
                class="flex flex-wrap items-center justify-between gap-3 py-3"
            >
                <div class="min-w-0">
                    <p class="font-medium">
                        {{ money(settlement.amount_kobo) }}
                    </p>
                    <p class="text-muted-foreground mt-0.5 text-xs break-all">
                        {{ settlement.date }} · Bank ref
                        {{ settlement.bank_reference }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-for="(file, index) in settlement.files"
                        :key="file.id"
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="fileRequest.processing"
                        @click="download(settlement.reference, file.id)"
                        >Download proof
                        {{
                            settlement.files.length > 1 ? index + 1 : ''
                        }}</Button
                    >
                </div>
            </li>
        </ul>
        <p v-else class="text-muted-foreground text-sm">
            No bank deposits recorded yet.
        </p>
        <MoreDetails v-if="settlements.length" label="About bank deposits">
            <div class="text-muted-foreground grid gap-2 text-xs leading-5">
                <p>
                    Recording a deposit does not change customer savings or fee
                    income. Bank charges stay open until a charge rule is
                    approved.
                </p>
                <p
                    v-for="settlement in settlements"
                    :key="settlement.reference"
                    class="break-all"
                >
                    {{ settlement.date }}: reference
                    {{ settlement.reference }}
                </p>
            </div>
        </MoreDetails>

        <FormSheet
            v-model:open="sheetOpen"
            title="Record bank deposit"
            description="Enter what the bank actually received."
        >
            <form
                id="settlement-form"
                class="grid gap-5"
                @submit.prevent="submit"
            >
                <p
                    v-if="message"
                    role="status"
                    aria-live="polite"
                    class="bg-muted rounded-xl p-3 text-sm"
                >
                    {{ message }}
                </p>
                <div class="grid gap-2">
                    <Label for="settlement-bank">Bank account</Label>
                    <Select
                        :model-value="String(form.bank_method_version_id)"
                        :disabled="uncertain || form.processing"
                        @update:model-value="
                            form.bank_method_version_id = Number($event)
                        "
                    >
                        <SelectTrigger id="settlement-bank" class="w-full"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="bank in banks"
                                :key="bank.id"
                                :value="String(bank.id)"
                            >
                                {{ bank.label }} · {{ bank.destination_key }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="settlement-amount">Amount (NGN)</Label>
                        <Input
                            id="settlement-amount"
                            v-model="form.amount_ngn"
                            inputmode="decimal"
                            :disabled="uncertain || form.processing"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="settlement-date">Date received</Label>
                        <DatePicker
                            id="settlement-date"
                            v-model="form.settled_date"
                            :disabled="uncertain || form.processing"
                        />
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="settlement-reference">Bank reference</Label>
                    <Input
                        id="settlement-reference"
                        v-model="form.bank_reference"
                        :disabled="uncertain || form.processing"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="settlement-source">How you checked it</Label>
                    <Input
                        id="settlement-source"
                        v-model="form.source_attestation"
                        placeholder="For example: matched on bank statement"
                        :disabled="uncertain || form.processing"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="settlement-reason">Note</Label>
                    <Input
                        id="settlement-reason"
                        v-model="form.reason"
                        :disabled="uncertain || form.processing"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="settlement-files">Bank proof</Label>
                    <Input
                        id="settlement-files"
                        type="file"
                        multiple
                        accept="image/jpeg,image/png,image/webp,application/pdf"
                        :disabled="uncertain || form.processing"
                        @change="chooseFiles"
                    />
                    <p class="text-muted-foreground text-xs">
                        1 to 3 photos or PDFs, up to 5 MB each.
                    </p>
                </div>
                <label class="flex items-start gap-2 text-sm"
                    ><input
                        v-model="form.confirmed"
                        type="checkbox"
                        class="mt-0.5"
                        :disabled="uncertain || form.processing"
                    />
                    I checked the amount and reference against this bank
                    account.</label
                >
                <progress
                    v-if="form.progress"
                    :value="form.progress.percentage"
                    max="100"
                    aria-label="Bank proof upload"
                />
                <ul
                    v-if="form.hasErrors"
                    class="text-destructive grid gap-1 text-sm"
                    role="alert"
                >
                    <li v-for="(error, field) in form.errors" :key="field">
                        {{ error }}
                    </li>
                </ul>
                <MoreDetails :default-open="uncertain" label="Saved reference">
                    <div class="grid gap-2">
                        <Label for="settlement-attempt">Reference</Label>
                        <Input
                            id="settlement-attempt"
                            :model-value="form.settlement_reference"
                            readonly
                        />
                        <Button
                            type="button"
                            variant="outline"
                            class="w-fit"
                            :disabled="
                                statusRequest.processing || form.processing
                            "
                            @click="checkStatus"
                            >Check status</Button
                        >
                    </div>
                </MoreDetails>
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
                    form="settlement-form"
                    :disabled="
                        form.processing ||
                        !form.confirmed ||
                        !form.settlement_reference
                    "
                    >{{ uncertain ? 'Retry' : 'Save deposit' }}</Button
                >
            </template>
        </FormSheet>
    </section>
</template>
