<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';

const props = defineProps<{ state: string }>();

const labels: Record<string, string> = {
    pending_review: 'Waiting for review',
    approved: 'Approved, not paid yet',
    payout_processing: 'Paying out',
    outcome_unknown: 'Checking payment',
    payment_failed: 'Payment failed',
    posted: 'Paid',
    needs_reconciliation: 'Needs checking',
    rejected: 'Rejected',
    cancelled: 'Cancelled',
    expired: 'Expired',
};

const label = computed(
    () => labels[props.state] ?? props.state.replaceAll('_', ' '),
);
const variant = computed(() =>
    ['rejected', 'cancelled', 'expired', 'payment_failed'].includes(props.state)
        ? 'outline'
        : props.state === 'posted'
          ? 'default'
          : 'secondary',
);
</script>

<template>
    <Badge :variant="variant">{{ label }}</Badge>
</template>
