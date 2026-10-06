<script setup lang="ts">
import MoreDetails from '@/components/MoreDetails.vue';
import type { PlanFeeHistory } from '@/types/plan-fee-history';

defineProps<{
    summary: Pick<
        PlanFeeHistory,
        'status' | 'totals' | 'as_of' | 'message' | 'source_version'
    >;
}>();

function dateTime(value: string) {
    return new Intl.DateTimeFormat('en-GB', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'UTC',
    }).format(new Date(value));
}
</script>

<template>
    <div class="space-y-1 text-xs">
        <p class="font-medium">Fees</p>
        <template v-if="summary.totals">
            <p>
                Unpaid: <strong>{{ summary.totals.outstanding }}</strong>
            </p>
            <p>
                Paid: {{ summary.totals.settled }} · Waived:
                {{ summary.totals.waived }}
            </p>
            <MoreDetails label="Fee details">
                <dl class="space-y-1">
                    <div>
                        <dt>Fees charged</dt>
                        <dd>{{ summary.totals.original_assessed }}</dd>
                    </div>
                    <div>
                        <dt>Fees after corrections</dt>
                        <dd>{{ summary.totals.assessed }}</dd>
                    </div>
                </dl>
                <p class="text-muted-foreground mt-2">{{ summary.message }}</p>
                <p v-if="summary.as_of" class="text-muted-foreground mt-1">
                    Updated {{ dateTime(summary.as_of) }} UTC
                </p>
            </MoreDetails>
        </template>
        <p v-else class="text-muted-foreground">
            Fee amounts are not available. Open the plan to try again.
        </p>
    </div>
</template>
