<script setup lang="ts">
import { computed } from 'vue';
import type { PlanFundingSummary } from '@/types/plan-funding';

const props = withDefaults(
    defineProps<{ summary: PlanFundingSummary; compact?: boolean }>(),
    { compact: false },
);
const asOf = computed(() =>
    props.summary.as_of === null
        ? null
        : new Intl.DateTimeFormat('en-GB', {
              dateStyle: 'medium',
              timeStyle: 'short',
              timeZone: 'UTC',
          }).format(new Date(props.summary.as_of)),
);
</script>

<template>
    <div
        role="status"
        :class="compact ? 'space-y-1 text-xs' : 'space-y-3 text-sm'"
    >
        <template v-if="summary.funded_principal !== null">
            <dl
                :class="
                    compact ? 'space-y-1' : 'grid grid-cols-2 gap-x-4 gap-y-3'
                "
            >
                <div>
                    <dt class="text-muted-foreground">Paid toward plan</dt>
                    <dd class="font-medium">{{ summary.funded_principal }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Days fully paid</dt>
                    <dd class="font-medium">
                        {{ summary.fully_funded_slots }} /
                        {{ summary.required_slots }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Days partly paid</dt>
                    <dd class="font-medium">
                        {{ summary.partially_funded_slots }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Still to pay</dt>
                    <dd class="font-medium">
                        {{ summary.remaining_scheduled_target }}
                    </dd>
                </div>
            </dl>
            <p v-if="!compact" class="text-muted-foreground">
                Based on payments matched to each plan day.
            </p>
            <p v-if="asOf" class="text-muted-foreground text-xs">
                Updated {{ asOf }} UTC
            </p>
        </template>
        <template v-else>
            <p class="font-medium">Not available</p>
            <p class="text-muted-foreground">{{ summary.message }}</p>
        </template>
    </div>
</template>
