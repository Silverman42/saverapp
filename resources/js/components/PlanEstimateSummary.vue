<script setup lang="ts">
import type { PlanEstimate } from '@/types/plan-estimate';

defineProps<{ estimate: PlanEstimate | null }>();
</script>

<template>
    <div class="space-y-3 text-sm">
        <dl class="grid gap-3 sm:grid-cols-3">
            <div>
                <dt class="text-muted-foreground">Expected gross</dt>
                <dd class="font-medium">
                    {{ estimate?.expected_gross ?? 'Unavailable' }}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Estimated agreed fee</dt>
                <dd class="font-medium">
                    {{ estimate?.estimated_fee ?? 'Unavailable' }}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Estimated payout</dt>
                <dd class="font-medium">
                    {{ estimate?.estimated_payout ?? 'Unavailable' }}
                </dd>
            </div>
        </dl>
        <p class="text-muted-foreground">
            {{
                estimate?.message ??
                'The contractual estimate cannot be verified.'
            }}
        </p>
    </div>
</template>
