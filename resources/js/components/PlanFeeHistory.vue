<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { show as showPlan } from '@/routes/plans';
import type { PlanFeeHistory } from '@/types/plan-fee-history';

const props = defineProps<{ planId: string; summary: PlanFeeHistory }>();
const fields = [
    { key: 'original_assessed', label: 'Original assessments' },
    { key: 'assessed', label: 'Assessments after corrections' },
    { key: 'settled', label: 'Recorded settlements after reversals' },
    { key: 'waived', label: 'Waived fees' },
    { key: 'outstanding', label: 'Unpaid fees' },
] as const;
const labels: Record<string, string> = {
    assessment: 'Original assessment',
    settlement: 'Fee settlement',
    waiver: 'Fee waiver',
    assessment_correction: 'Assessment reduction',
    assessment_correction_increase: 'Assessment increase',
    settlement_reversal: 'Settlement reversal',
    savings_refund: 'Savings returned',
    external_refund_entitlement: 'External refund entitlement',
};
const history = computed(() => props.summary.history);
function historyLink(page: number, perPage = history.value?.per_page ?? 25) {
    return showPlan(props.planId, {
        mergeQuery: { fee_page: page, fee_per_page: perPage },
    });
}
function dateTime(value: string | null) {
    return value === null
        ? 'Unavailable'
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
            class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
        >
            <div v-for="field in fields" :key="field.key">
                <dt class="text-muted-foreground">{{ field.label }}</dt>
                <dd class="font-medium">{{ summary.totals[field.key] }}</dd>
            </div>
        </dl>
        <p v-else class="font-medium">Unavailable</p>
        <p class="text-muted-foreground">{{ summary.message }}</p>
        <p v-if="summary.as_of" class="text-muted-foreground text-xs">
            As of {{ dateTime(summary.as_of) }} UTC
        </p>
        <template v-if="history">
            <p v-if="history.total === 0" class="text-muted-foreground">
                No recorded cycle fee entries.
            </p>
            <p
                v-else-if="history.data.length === 0"
                class="text-muted-foreground"
            >
                No entries on this page.
                <Link
                    :href="historyLink(1)"
                    preserve-scroll
                    class="underline underline-offset-4"
                    >Return to the first fee history page</Link
                >
            </p>
            <ol
                v-else
                class="space-y-3"
                aria-label="Recorded cycle fee entries"
            >
                <li
                    v-for="entry in history.data"
                    :key="entry.id"
                    class="rounded-md border p-3"
                >
                    <div class="flex flex-wrap justify-between gap-2">
                        <p class="font-medium">
                            {{ labels[entry.type] ?? 'Fee entry' }} ·
                            {{ entry.fee_name }}
                        </p>
                        <p class="font-medium">{{ entry.amount }}</p>
                    </div>
                    <p class="text-muted-foreground mt-1">
                        {{ entry.kind_label }}
                    </p>
                    <p
                        v-if="entry.description"
                        class="text-muted-foreground mt-1"
                    >
                        {{ entry.description }}
                    </p>
                    <p class="text-muted-foreground mt-2 text-xs">
                        Recorded {{ dateTime(entry.recorded_at) }} UTC
                    </p>
                    <p
                        v-if="entry.reference"
                        class="text-muted-foreground mt-1 text-xs break-all"
                    >
                        Posting reference: {{ entry.reference }}
                    </p>
                </li>
            </ol>
            <nav
                class="flex flex-wrap items-center gap-3"
                aria-label="Cycle fee history pages"
            >
                <Button
                    v-if="history.current_page > 1"
                    variant="outline"
                    as-child
                >
                    <Link
                        :href="historyLink(history.current_page - 1)"
                        preserve-scroll
                        >Previous fee entries</Link
                    >
                </Button>
                <span
                    >Page {{ history.current_page }} of
                    {{ history.last_page }} · {{ history.total }} entries</span
                >
                <Button
                    v-if="history.current_page < history.last_page"
                    variant="outline"
                    as-child
                >
                    <Link
                        :href="historyLink(history.current_page + 1)"
                        preserve-scroll
                        >Next fee entries</Link
                    >
                </Button>
                <span>Entries per page:</span>
                <Link
                    v-for="size in [25, 50, 100]"
                    :key="size"
                    :href="historyLink(1, size)"
                    preserve-scroll
                    class="underline underline-offset-4"
                    :aria-current="
                        history.per_page === size ? 'true' : undefined
                    "
                    >{{ size }}</Link
                >
            </nav>
        </template>
        <Button v-else variant="outline" as-child>
            <Link :href="showPlan(planId)" preserve-scroll
                >Reload fee history</Link
            >
        </Button>
    </div>
</template>
