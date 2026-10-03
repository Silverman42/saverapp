<script setup lang="ts">
import PlanSavingsSummary from '@/components/PlanSavingsSummary.vue';
import type { PlanSavings } from '@/types/plan-savings';

defineProps<{ summary: PlanSavings }>();
</script>

<template>
    <div class="space-y-1 text-xs">
        <p class="font-medium">Recorded savings and reservations</p>
        <p>
            Cycle available:
            <strong>{{ summary.cycle.available ?? 'Unavailable' }}</strong>
        </p>
        <p>
            Customer available across all cycles:
            {{ summary.customer.available ?? 'Unavailable' }}
        </p>
        <details>
            <summary class="cursor-pointer underline underline-offset-4">
                Savings totals and timestamp
            </summary>
            <PlanSavingsSummary :summary="summary" class="mt-3" />
        </details>
    </div>
</template>
