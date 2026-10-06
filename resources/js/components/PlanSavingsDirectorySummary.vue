<script setup lang="ts">
import MoreDetails from '@/components/MoreDetails.vue';
import PlanSavingsSummary from '@/components/PlanSavingsSummary.vue';
import type { PlanSavings } from '@/types/plan-savings';

defineProps<{ summary: PlanSavings }>();
</script>

<template>
    <div class="space-y-1 text-xs">
        <p class="font-medium">Savings</p>
        <p>
            Available in this plan:
            <strong>{{ summary.cycle.available ?? 'Not available' }}</strong>
        </p>
        <p>
            Available across all plans:
            {{ summary.customer.available ?? 'Not available' }}
        </p>
        <MoreDetails label="Savings breakdown">
            <PlanSavingsSummary :summary="summary" />
        </MoreDetails>
    </div>
</template>
