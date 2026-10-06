<script setup lang="ts">
import { useForm, useHttp } from '@inertiajs/vue3';
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
import { ref } from 'vue';
import { acknowledge } from '@/routes/cash-recoveries';

const props = defineProps<{
    recordUrl: string;
    previewUrl: string;
    recoveries: {
        recovery_reference: string;
        status: string;
        amount_kobo: number;
        event_type: string;
    }[];
    canRecord: boolean;
    canConfirm: boolean;
}>();
const form = useForm({
    recovery_reference: crypto.randomUUID(),
    amount_ngn: '',
    event_type: 'return',
    evidence: '',
    confirmed: false,
    preview_fingerprint: '',
});
const confirmation = useForm({ confirmed: false });
const preview = ref<{ remaining_kobo: number; status: string } | null>(null);
const previewError = ref('');
const http = useHttp<
    Record<string, never>,
    { preview_fingerprint: string; remaining_kobo: number; status: string }
>({});
async function review(): Promise<void> {
    previewError.value = '';
    try {
        const quote = await http.get(props.previewUrl);
        preview.value = quote;
        form.preview_fingerprint = quote.preview_fingerprint;
        form.confirmed = false;
    } catch {
        previewError.value =
            'We could not check this payment. Reload the page and try again.';
        preview.value = null;
    }
}
function submit(): void {
    form.transform((data) => ({
        ...data,
        amount_ngn: data.event_type === 'return' ? data.amount_ngn : '0',
    })).post(props.recordUrl, {
        onSuccess: () => {
            form.reset();
            form.recovery_reference = crypto.randomUUID();
            preview.value = null;
        },
        onError: () => {
            preview.value = null;
        },
    });
}
const money = (amount: number): string => `NGN ${(amount / 100).toFixed(2)}`;
</script>
<template>
    <section
        class="grid gap-4 rounded-xl border p-4 sm:p-5"
        aria-label="Cash recovery"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-medium">Cash returned</h2>
                <p class="text-muted-foreground mt-1 text-sm">
                    Record each cash return on its own. Savings only change once
                    the full amount is back and checked.
                </p>
            </div>
            <Button
                v-if="canRecord"
                variant="outline"
                :disabled="http.processing"
                @click="review"
                >Check payment</Button
            >
        </div>
        <p v-if="previewError" role="alert" class="text-destructive text-sm">
            {{ previewError }}
        </p>
        <p
            v-if="preview"
            role="status"
            class="bg-muted/50 rounded-lg p-3 text-sm"
        >
            Still to return: <strong>{{ money(preview.remaining_kobo) }}</strong
            >. Payment status:
            <span class="capitalize">{{
                preview.status.replaceAll('_', ' ')
            }}</span
            >.
        </p>
        <form
            v-if="canRecord && preview"
            class="grid gap-4"
            @submit.prevent="submit"
        >
            <div class="grid gap-2">
                <Label :for="`recovery-kind-${form.recovery_reference}`"
                    >What happened?</Label
                >
                <Select v-model="form.event_type">
                    <SelectTrigger
                        :id="`recovery-kind-${form.recovery_reference}`"
                        class="w-full"
                        ><SelectValue
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="return"
                            >Cash was returned and counted</SelectItem
                        >
                        <SelectItem value="dispute"
                            >Recipient disputes it</SelectItem
                        >
                        <SelectItem value="custody_uncertain"
                            >Not sure who has the cash</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>
            <div v-if="form.event_type === 'return'" class="grid gap-2">
                <Label :for="`recovery-amount-${form.recovery_reference}`"
                    >Amount returned (NGN)</Label
                ><Input
                    :id="`recovery-amount-${form.recovery_reference}`"
                    v-model="form.amount_ngn"
                    required
                    inputmode="decimal"
                />
            </div>
            <div class="grid gap-2">
                <Label :for="`recovery-evidence-${form.recovery_reference}`"
                    >Notes</Label
                ><Input
                    :id="`recovery-evidence-${form.recovery_reference}`"
                    v-model="form.evidence"
                    required
                    maxlength="1000"
                    placeholder="Who returned it, when and where"
                />
            </div>
            <label class="flex items-center gap-2 text-sm"
                ><input v-model="form.confirmed" type="checkbox" />The amount
                and notes are correct.</label
            >
            <p
                v-for="(error, key) in form.errors"
                :key="key"
                role="alert"
                class="text-destructive text-sm"
            >
                {{ error }}
            </p>
            <Button :disabled="form.processing || !form.confirmed" class="w-fit"
                >Save</Button
            >
        </form>
        <ul v-if="recoveries.length" class="divide-y border-t">
            <li
                v-for="recovery in recoveries"
                :key="recovery.recovery_reference"
                class="grid gap-2 py-3 text-sm"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="capitalize"
                        >{{ recovery.event_type.replaceAll('_', ' ') }} ·
                        {{ money(recovery.amount_kobo) }}</span
                    >
                    <span class="text-muted-foreground text-xs capitalize">{{
                        recovery.status.replaceAll('_', ' ')
                    }}</span>
                </div>
                <form
                    v-if="canConfirm && recovery.status === 'awaiting_customer'"
                    class="grid gap-2"
                    @submit.prevent="
                        confirmation.post(
                            acknowledge.url(recovery.recovery_reference),
                            { onSuccess: () => confirmation.reset() },
                        )
                    "
                >
                    <label class="flex items-center gap-2"
                        ><input
                            v-model="confirmation.confirmed"
                            type="checkbox"
                        />I returned exactly
                        {{ money(recovery.amount_kobo) }}.</label
                    >
                    <Button
                        :disabled="
                            confirmation.processing || !confirmation.confirmed
                        "
                        class="w-fit"
                        >Confirm</Button
                    >
                    <p
                        v-for="(error, key) in confirmation.errors"
                        :key="key"
                        role="alert"
                        class="text-destructive"
                    >
                        {{ error }}
                    </p>
                </form>
            </li>
        </ul>
    </section>
</template>
