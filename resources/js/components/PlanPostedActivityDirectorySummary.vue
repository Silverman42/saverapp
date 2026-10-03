<script setup lang="ts">
import { computed } from 'vue';
import PlanPostedActivity from '@/components/PlanPostedActivity.vue';
import type { PlanPostedActivity as PostedActivity } from '@/types/plan-posted-activity';

const props = defineProps<{ summary: PostedActivity }>();
const movements = computed(() =>
    [
        { label: 'Original cash delivered', code: 'net_cash_payouts' },
        {
            label: 'Effective cash paid after returns',
            code: 'effective_withdrawal_cash_paid',
        },
        { label: 'Effective savings deductions', code: 'effective_deductions' },
    ].map((item) => ({
        ...item,
        display:
            props.summary.metrics.find((metric) => metric.code === item.code)
                ?.display ?? 'Unavailable',
    })),
);
</script>

<template>
    <div class="space-y-1 text-xs">
        <p class="font-medium">Recorded payouts and deductions</p>
        <template v-if="summary.status === 'available'">
            <p v-for="movement in movements" :key="movement.code">
                {{ movement.label }}: {{ movement.display }}
            </p>
            <details>
                <summary class="cursor-pointer underline underline-offset-4">
                    Posted totals and timestamp
                </summary>
                <PlanPostedActivity :summary="summary" class="mt-3" />
            </details>
        </template>
        <p v-else class="text-muted-foreground">
            Verified posted amounts unavailable.
        </p>
    </div>
</template>
