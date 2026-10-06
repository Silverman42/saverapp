<script setup lang="ts">
import type { PlanEstimate } from '@/types/plan-estimate';

defineProps<{ estimate: PlanEstimate | null }>();
</script>

<template>
    <div class="space-y-3 text-sm">
        <dl class="grid gap-3 sm:grid-cols-3">
            <div>
                <dt class="text-muted-foreground">Expected total</dt>
                <dd class="font-medium">
                    {{ estimate?.expected_gross ?? 'Not available' }}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Estimated fee</dt>
                <dd class="font-medium">
                    {{ estimate?.estimated_fee ?? 'Not available' }}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Estimated payout</dt>
                <dd class="font-medium">
                    {{ estimate?.estimated_payout ?? 'Not available' }}
                </dd>
            </div>
        </dl>
        <p class="text-muted-foreground text-xs">
            {{ estimate?.message ?? "We can't show an estimate right now." }}
        </p>
    </div>
</template>
