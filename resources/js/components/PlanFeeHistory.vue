<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { show as showPlan } from '@/routes/plans';
import type { PlanFeeHistory } from '@/types/plan-fee-history';

const props = defineProps<{ planId: string; summary: PlanFeeHistory }>();
const fields = [
    { key: 'outstanding', label: 'Unpaid' },
    { key: 'settled', label: 'Paid' },
    { key: 'waived', label: 'Waived' },
    { key: 'original_assessed', label: 'Charged' },
    { key: 'assessed', label: 'Charged after corrections' },
] as const;
const labels: Record<string, string> = {
    assessment: 'Fee charged',
    settlement: 'Fee paid',
    waiver: 'Fee waived',
    assessment_correction: 'Fee reduced',
    assessment_correction_increase: 'Fee increased',
    settlement_reversal: 'Fee payment reversed',
    savings_refund: 'Savings returned',
    external_refund_entitlement: 'Refund owed',
};
const history = computed(() => props.summary.history);
function historyLink(page: number, perPage = history.value?.per_page ?? 25) {
    return showPlan(props.planId, {
        mergeQuery: { fee_page: page, fee_per_page: perPage },
    });
}
function dateTime(value: string | null) {
    return value === null
        ? 'Unknown'
        : new Intl.DateTimeFormat('en-GB', {
              dateStyle: 'medium',
              timeStyle: 'short',
              timeZone: 'UTC',
          }).format(new Date(value));
}
</script>

<template>
    <div class="space-y-5 text-sm">
        <dl
            v-if="summary.totals"
            class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5"
        >
            <div v-for="field in fields" :key="field.key">
                <dt class="text-muted-foreground">{{ field.label }}</dt>
                <dd class="font-medium">{{ summary.totals[field.key] }}</dd>
            </div>
        </dl>
        <p v-else class="font-medium">Not available</p>
        <p class="text-muted-foreground text-xs">
            {{ summary.message }}
            <template v-if="summary.as_of">
                Updated {{ dateTime(summary.as_of) }} UTC.</template
            >
        </p>
        <template v-if="history">
            <p v-if="history.total === 0" class="text-muted-foreground">
                No fees recorded yet.
            </p>
            <p
                v-else-if="history.data.length === 0"
                class="text-muted-foreground"
            >
                Nothing on this page.
                <Link
                    :href="historyLink(1)"
                    preserve-scroll
                    class="underline underline-offset-4"
                    >Go to the first page</Link
                >
            </p>
            <ol
                v-else
                class="divide-y rounded-xl border"
                aria-label="Fee history"
            >
                <li
                    v-for="entry in history.data"
                    :key="entry.id"
                    class="space-y-1 p-3"
                >
                    <div class="flex flex-wrap justify-between gap-2">
                        <p class="font-medium">
                            {{ labels[entry.type] ?? 'Fee entry'
                            }}<span v-if="entry.fee_name" class="font-normal">
                                · {{ entry.fee_name }}</span
                            >
                        </p>
                        <p class="font-medium">{{ entry.amount }}</p>
                    </div>
                    <p v-if="entry.description" class="text-muted-foreground">
                        {{ entry.description }}
                    </p>
                    <p class="text-muted-foreground text-xs break-all">
                        {{ dateTime(entry.recorded_at) }} UTC ·
                        {{ entry.kind_label
                        }}<template v-if="entry.reference">
                            · Ref {{ entry.reference }}</template
                        >
                    </p>
                </li>
            </ol>
            <nav
                v-if="history.total > 0"
                class="text-muted-foreground flex flex-wrap items-center gap-3 text-xs"
                aria-label="Fee history pages"
            >
                <Button
                    v-if="history.current_page > 1"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link
                        :href="historyLink(history.current_page - 1)"
                        preserve-scroll
                        >Previous</Link
                    >
                </Button>
                <span
                    >Page {{ history.current_page }} of
                    {{ history.last_page }} · {{ history.total }} entries</span
                >
                <Button
                    v-if="history.current_page < history.last_page"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link
                        :href="historyLink(history.current_page + 1)"
                        preserve-scroll
                        >Next</Link
                    >
                </Button>
                <span class="ml-auto">Show</span>
                <Link
                    v-for="size in [25, 50, 100]"
                    :key="size"
                    :href="historyLink(1, size)"
                    preserve-scroll
                    class="underline-offset-4 hover:underline"
                    :class="
                        history.per_page === size
                            ? 'text-foreground font-medium'
                            : ''
                    "
                    :aria-current="
                        history.per_page === size ? 'true' : undefined
                    "
                    >{{ size }}</Link
                >
            </nav>
        </template>
        <Button v-else variant="outline" size="sm" as-child>
            <Link :href="showPlan(planId)" preserve-scroll>Reload fees</Link>
        </Button>
    </div>
</template>
