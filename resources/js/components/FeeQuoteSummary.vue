<script setup lang="ts">
import type { FeeDisclosure } from '@/types/fee-disclosure';

defineProps<{ disclosure: FeeDisclosure | null }>();

const fields = [
    { key: 'new_fee', label: 'New fee assessed on payment' },
    {
        key: 'existing_fee_included',
        label: 'Existing fee collected by this payout',
    },
    { key: 'already_assessed', label: 'Already assessed cycle fees' },
    { key: 'already_settled', label: 'Recorded fee settlements' },
    { key: 'already_waived', label: 'Already waived cycle fees' },
    {
        key: 'existing_unpaid',
        label: 'Existing unpaid fees before this payout',
    },
] as const;
</script>

<template>
    <section
        class="space-y-3 rounded-md border p-4 text-sm"
        aria-label="Cycle fees at this quote"
    >
        <h3 class="font-medium">Cycle fees at this quote</h3>
        <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div v-for="field in fields" :key="field.key">
                <dt class="text-muted-foreground">{{ field.label }}</dt>
                <dd class="font-medium">
                    {{ disclosure?.[field.key] ?? 'Unavailable' }}
                </dd>
            </div>
        </dl>
        <p class="text-muted-foreground">
            {{
                disclosure?.message ??
                'Fee history is unavailable. Review the quote again.'
            }}
        </p>
        <p class="text-muted-foreground">
            Recorded settlements keep the original fee payments after a
            concession. Fee history shows refunds and corrections.
        </p>
    </section>
</template>
