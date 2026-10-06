<script setup lang="ts">
import { computed } from 'vue';
import type { PlanSavings } from '@/types/plan-savings';

const props = defineProps<{ summary: PlanSavings }>();
const positions = computed(() => [
    {
        label: 'All savings for this customer',
        position: props.summary.customer,
    },
    {
        label: 'Savings in this plan',
        position: props.summary.cycle,
    },
]);
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
    <div class="space-y-5 text-sm">
        <section v-for="item in positions" :key="item.label" class="space-y-2">
            <h3 class="font-medium">{{ item.label }}</h3>
            <dl class="grid gap-3 sm:grid-cols-3">
                <div>
                    <dt class="text-muted-foreground">Savings balance</dt>
                    <dd class="font-medium">
                        {{ item.position.liability ?? 'Not available' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">
                        Set aside for withdrawals
                    </dt>
                    <dd class="font-medium">
                        {{ item.position.reserved ?? 'Not available' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Available savings</dt>
                    <dd class="font-medium">
                        {{ item.position.available ?? 'Not available' }}
                    </dd>
                </div>
            </dl>
            <p class="text-muted-foreground text-xs">
                {{ item.position.message }}
            </p>
        </section>
        <p v-if="asOf" class="text-muted-foreground text-xs">
            Updated {{ asOf }} UTC
        </p>
    </div>
</template>
