<script setup lang="ts">
import { computed } from 'vue';
import MoreDetails from '@/components/MoreDetails.vue';
import PlanPostedActivity from '@/components/PlanPostedActivity.vue';
import type { PlanPostedActivity as PostedActivity } from '@/types/plan-posted-activity';

const props = defineProps<{ summary: PostedActivity }>();
const movements = computed(() =>
    [
        { label: 'Cash paid out', code: 'net_cash_payouts' },
        {
            label: 'Cash paid out after returns',
            code: 'effective_withdrawal_cash_paid',
        },
        { label: 'Taken from savings', code: 'effective_deductions' },
    ].map((item) => ({
        ...item,
        display:
            props.summary.metrics.find((metric) => metric.code === item.code)
                ?.display ?? 'Not available',
    })),
);
</script>

<template>
    <div class="space-y-1 text-xs">
        <p class="font-medium">Payouts and deductions</p>
        <template v-if="summary.status === 'available'">
            <p v-for="movement in movements" :key="movement.code">
                {{ movement.label }}: {{ movement.display }}
            </p>
            <MoreDetails label="All totals">
                <PlanPostedActivity :summary="summary" />
            </MoreDetails>
        </template>
        <p v-else class="text-muted-foreground">
            Payout totals are not available right now.
        </p>
    </div>
</template>
