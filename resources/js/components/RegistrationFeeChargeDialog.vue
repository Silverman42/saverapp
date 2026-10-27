<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Loader2 } from '@lucide/vue';
import { computed, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { store as storeRegistrationFee } from '@/routes/customers/registration-fees';

export type RegistrationFeePreview = {
    available: boolean;
    version?: number;
    formatted_amount?: string;
    is_zero?: boolean;
    message?: string;
};

/**
 * Lets staff raise a registration fee again for an existing customer.
 * Only the assigned agent can record cash collected at the same time.
 */
const props = defineProps<{
    open: boolean;
    customerId: string;
    preview: RegistrationFeePreview;
    canCollect: boolean;
}>();

const emit = defineEmits<{ (e: 'update:open', value: boolean): void }>();

const form = useForm({
    attempt_reference: crypto.randomUUID(),
    fee_rule_version: props.preview.version ?? 0,
    registration_fee_payment: 'pending' as 'paid' | 'part' | 'pending',
    registration_fee_amount_paid: '',
    reason: '',
});

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            form.reset();
            form.clearErrors();
            form.attempt_reference = crypto.randomUUID();
            form.fee_rule_version = props.preview.version ?? 0;
        }
    },
);

const canSubmit = computed(
    () => props.preview.available && !props.preview.is_zero,
);

const submit = (): void => {
    form.post(storeRegistrationFee(props.customerId).url, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
};
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Charge registration fee</DialogTitle>
                <DialogDescription v-if="canSubmit">
                    Add a new registration fee of
                    {{ preview.formatted_amount }} to this customer's account.
                </DialogDescription>
                <DialogDescription v-else>
                    {{
                        preview.message ??
                        'The current registration fee is zero, so there is nothing to charge.'
                    }}
                </DialogDescription>
            </DialogHeader>
            <form v-if="canSubmit" class="space-y-4" @submit.prevent="submit">
                <div class="space-y-1.5">
                    <Label for="charge-fee-payment">Fee payment</Label>
                    <Select v-model="form.registration_fee_payment">
                        <SelectTrigger id="charge-fee-payment" class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="pending">Pending</SelectItem>
                            <SelectItem v-if="canCollect" value="part"
                                >Part payment</SelectItem
                            >
                            <SelectItem v-if="canCollect" value="paid"
                                >Paid in full</SelectItem
                            >
                        </SelectContent>
                    </Select>
                    <p
                        v-if="form.errors.registration_fee_payment"
                        class="text-destructive text-xs"
                    >
                        {{ form.errors.registration_fee_payment }}
                    </p>
                </div>
                <div
                    v-if="form.registration_fee_payment === 'part'"
                    class="space-y-1.5"
                >
                    <Label for="charge-fee-amount">
                        Amount collected (₦)
                        <span class="text-destructive">*</span>
                    </Label>
                    <Input
                        id="charge-fee-amount"
                        v-model="form.registration_fee_amount_paid"
                        inputmode="decimal"
                        placeholder="e.g. 500.00"
                        required
                    />
                    <p
                        v-if="form.errors.registration_fee_amount_paid"
                        class="text-destructive text-xs"
                    >
                        {{ form.errors.registration_fee_amount_paid }}
                    </p>
                </div>
                <div class="space-y-1.5">
                    <Label for="charge-fee-reason">
                        Reason
                        <span class="text-destructive">*</span>
                    </Label>
                    <Input
                        id="charge-fee-reason"
                        v-model="form.reason"
                        placeholder="e.g. Returning customer"
                        required
                    />
                    <p
                        v-if="form.errors.reason"
                        class="text-destructive text-xs"
                    >
                        {{ form.errors.reason }}
                    </p>
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="emit('update:open', false)"
                    >
                        Cancel
                    </Button>
                    <Button type="submit" :disabled="form.processing">
                        <Loader2
                            v-if="form.processing"
                            class="size-4 animate-spin"
                        />
                        Charge fee
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
