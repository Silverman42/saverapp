<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';

const props = defineProps<{ state: string }>();

const labels: Record<string, string> = {
    pending_review: 'Waiting for review',
    approved_posted: 'Approved, money corrected',
    approved_no_money: 'Approved, no money moved',
    rejected: 'Rejected',
    cancelled: 'Cancelled',
};

const label = computed(
    () => labels[props.state] ?? props.state.replaceAll('_', ' '),
);
const variant = computed(() =>
    ['rejected', 'cancelled'].includes(props.state)
        ? 'outline'
        : props.state.startsWith('approved')
          ? 'default'
          : 'secondary',
);
</script>

<template>
    <Badge :variant="variant">{{ label }}</Badge>
</template>
