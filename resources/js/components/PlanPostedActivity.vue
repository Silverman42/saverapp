<script setup lang="ts">
import { computed } from 'vue';
import type { PlanPostedActivity } from '@/types/plan-posted-activity';

const props = defineProps<{ summary: PlanPostedActivity }>();
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
    <div class="space-y-3 text-sm">
        <dl
            v-if="summary.status === 'available'"
            class="grid gap-4 sm:grid-cols-2"
        >
            <div v-for="metric in summary.metrics" :key="metric.code">
                <dt class="text-muted-foreground">{{ metric.title }}</dt>
                <dd class="font-medium">{{ metric.display }}</dd>
            </div>
        </dl>
        <p v-else class="font-medium">Not available</p>
        <p class="text-muted-foreground">{{ summary.message }}</p>
        <p v-if="asOf" class="text-muted-foreground text-xs">
            Updated {{ asOf }} UTC
        </p>
    </div>
</template>
