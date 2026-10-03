<script setup lang="ts">
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
        <p class="font-medium">Recorded cycle fees</p>
        <template v-if="summary.totals">
            <p>
                Unpaid: <strong>{{ summary.totals.outstanding }}</strong>
            </p>
            <p>
                Settled: {{ summary.totals.settled }} · waived:
                {{ summary.totals.waived }}
            </p>
            <details>
                <summary class="cursor-pointer underline underline-offset-4">
                    Fee totals and timestamp
                </summary>
                <dl class="mt-2 space-y-1">
                    <div>
                        <dt>Original assessments</dt>
                        <dd>{{ summary.totals.original_assessed }}</dd>
                    </div>
                    <div>
                        <dt>Assessments after corrections</dt>
                        <dd>{{ summary.totals.assessed }}</dd>
                    </div>
                </dl>
                <p class="text-muted-foreground mt-2">{{ summary.message }}</p>
                <p v-if="summary.as_of" class="text-muted-foreground mt-1">
                    As of {{ dateTime(summary.as_of) }} UTC
                </p>
            </details>
        </template>
        <p v-else class="text-muted-foreground">
            Actual fee amounts unavailable. Open the plan to reload.
        </p>
    </div>
</template>
