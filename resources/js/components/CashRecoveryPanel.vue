<script setup lang="ts">
import { useForm, useHttp } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
            'The original attempt could not be verified. Reload and review again.';
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
        class="grid gap-4 rounded-md border p-4"
        aria-label="Cash recovery"
    >
        <h2 class="font-medium">Cash recovery</h2>
        <p class="text-muted-foreground text-sm">
            Confirm each counted return separately. Savings remain unchanged
            until full recovery and reviewed compensation. Unresolved evidence
            stays with the original payment.
        </p>
        <Button
            v-if="canRecord"
            class="w-fit"
            variant="outline"
            :disabled="http.processing"
            @click="review"
            >Review original recovery attempt</Button
        >
        <p v-if="previewError" role="alert" class="text-destructive text-sm">
            {{ previewError }}
        </p>
        <p v-if="preview" role="status" class="text-sm">
            Unclaimed return balance: {{ money(preview.remaining_kobo) }}.
            Original attempt: {{ preview.status.replaceAll('_', ' ') }}.
        </p>
        <form
            v-if="canRecord && preview"
            class="grid gap-3"
            @submit.prevent="submit"
        >
            <Label :for="`recovery-kind-${form.recovery_reference}`"
                >Evidence type</Label
            >
            <select
                :id="`recovery-kind-${form.recovery_reference}`"
                v-model="form.event_type"
                class="border-input rounded-md border p-2"
            >
                <option value="return">Counted cash return</option>
                <option value="dispute">Recipient dispute</option>
                <option value="custody_uncertain">Uncertain custody</option>
            </select>
            <template v-if="form.event_type === 'return'"
                ><Label :for="`recovery-amount-${form.recovery_reference}`"
                    >Exact returned amount (NGN)</Label
                ><Input
                    :id="`recovery-amount-${form.recovery_reference}`"
                    v-model="form.amount_ngn"
                    required
                    inputmode="decimal"
            /></template>
            <Label :for="`recovery-evidence-${form.recovery_reference}`"
                >Evidence for the original attempt</Label
            ><Input
                :id="`recovery-evidence-${form.recovery_reference}`"
                v-model="form.evidence"
                required
                maxlength="1000"
            />
            <label class="flex items-center gap-2 text-sm"
                ><input v-model="form.confirmed" type="checkbox" />I confirm
                this amount and evidence.</label
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
                >Record recovery evidence</Button
            >
        </form>
        <div
            v-for="recovery in recoveries"
            :key="recovery.recovery_reference"
            class="grid gap-2 border-t pt-3 text-sm"
        >
            <p>
                {{ recovery.event_type.replaceAll('_', ' ') }} ·
                {{ money(recovery.amount_kobo) }} ·
                {{ recovery.status.replaceAll('_', ' ') }}
            </p>
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
                    ><input v-model="confirmation.confirmed" type="checkbox" />I
                    personally returned exactly
                    {{ money(recovery.amount_kobo) }}.</label
                >
                <Button
                    :disabled="
                        confirmation.processing || !confirmation.confirmed
                    "
                    class="w-fit"
                    >Confirm exact return</Button
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
        </div>
    </section>
</template>
