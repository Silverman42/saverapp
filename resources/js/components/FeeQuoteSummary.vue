<script setup lang="ts">
import type { FeeDisclosure } from '@/types/fee-disclosure';

defineProps<{ disclosure: FeeDisclosure | null }>();

const fields = [
    { key: 'new_fee', label: 'New fee charged' },
    {
        key: 'existing_fee_included',
        label: 'Earlier fee taken from this payout',
    },
    { key: 'already_assessed', label: 'Fees already charged this cycle' },
    { key: 'already_settled', label: 'Fees already paid' },
    { key: 'already_waived', label: 'Fees already waived' },
    {
        key: 'existing_unpaid',
        label: 'Unpaid fees before this payout',
    },
] as const;
</script>

<template>
    <section
        class="bg-muted/40 space-y-3 rounded-xl p-4 text-sm"
        aria-label="Fees for this cycle"
    >
        <h3 class="font-medium">Fees for this cycle</h3>
        <dl class="grid gap-3 sm:grid-cols-2">
            <div v-for="field in fields" :key="field.key">
                <dt class="text-muted-foreground">{{ field.label }}</dt>
                <dd class="font-medium">
                    {{ disclosure?.[field.key] ?? 'Not available' }}
                </dd>
            </div>
        </dl>
        <p class="text-muted-foreground">
            {{
                disclosure?.message ??
                'Fee details are not available. Review the request again.'
            }}
        </p>
        <p class="text-muted-foreground text-xs">
            Paid fees stay on record even if refunded later. Fee history shows
            refunds and corrections.
        </p>
    </section>
</template>
