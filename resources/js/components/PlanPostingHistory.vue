<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { show as showPlan } from '@/routes/plans';
import type { PlanPostingHistory } from '@/types/plan-posted-activity';

const props = defineProps<{ planId: string; summary: PlanPostingHistory }>();
function historyLink(
    page: number,
    perPage = props.summary.history?.per_page ?? 25,
) {
    return showPlan(props.planId, {
        mergeQuery: { activity_page: page, activity_per_page: perPage },
    });
}
function committedAt(value: string) {
    const timestamp = value.replace(' ', 'T');
    return new Intl.DateTimeFormat('en-GB', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'UTC',
    }).format(
        new Date(
            /(?:Z|[+-]\d{2}:\d{2})$/.test(timestamp)
                ? timestamp
                : timestamp + 'Z',
        ),
    );
}
</script>

<template>
    <div class="space-y-3 text-sm">
        <h3 class="font-medium">History</h3>
        <p class="text-muted-foreground text-xs">
            One entry can include separate savings, cash and fee amounts. Don't
            add them together. Money set aside for pending withdrawals is not
            shown here.<template v-if="summary.as_of">
                Updated {{ committedAt(summary.as_of) }} UTC.</template
            >
        </p>
        <template v-if="summary.history">
            <p v-if="summary.history.total === 0" class="text-muted-foreground">
                No payouts or deductions yet.
            </p>
            <p
                v-else-if="summary.history.data.length === 0"
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
                aria-label="Payout and deduction history"
            >
                <li
                    v-for="entry in summary.history.data"
                    :key="entry.key"
                    class="space-y-1 p-3"
                >
                    <div class="flex flex-wrap justify-between gap-2">
                        <p class="font-medium">{{ entry.title }}</p>
                        <p class="font-medium">{{ entry.amount }}</p>
                    </div>
                    <p class="text-muted-foreground text-xs break-all">
                        {{ entry.occurred_on ?? 'Date unknown' }} · Saved
                        {{ committedAt(entry.committed_at) }} UTC · Ref
                        {{ entry.reference }}
                    </p>
                </li>
            </ol>
            <nav
                v-if="summary.history.total > 0"
                class="text-muted-foreground flex flex-wrap items-center gap-3 text-xs"
                aria-label="Payout and deduction history pages"
            >
                <Button
                    v-if="summary.history.current_page > 1"
                    variant="outline"
                    size="sm"
                    as-child
                    ><Link
                        :href="historyLink(summary.history.current_page - 1)"
                        preserve-scroll
                        >Previous</Link
                    ></Button
                >
                <span
                    >Page {{ summary.history.current_page }} of
                    {{ summary.history.last_page }} ·
                    {{ summary.history.total }} entries</span
                >
                <Button
                    v-if="
                        summary.history.current_page < summary.history.last_page
                    "
                    variant="outline"
                    size="sm"
                    as-child
                    ><Link
                        :href="historyLink(summary.history.current_page + 1)"
                        preserve-scroll
                        >Next</Link
                    ></Button
                >
                <span class="ml-auto">Show</span>
                <Link
                    v-for="size in [25, 50, 100]"
                    :key="size"
                    :href="historyLink(1, size)"
                    preserve-scroll
                    class="underline-offset-4 hover:underline"
                    :class="
                        summary.history.per_page === size
                            ? 'text-foreground font-medium'
                            : ''
                    "
                    :aria-current="
                        summary.history.per_page === size ? 'true' : undefined
                    "
                    >{{ size }}</Link
                >
            </nav>
        </template>
        <template v-else>
            <p class="text-muted-foreground">
                This history is not available right now.
            </p>
            <Button variant="outline" size="sm" as-child
                ><Link :href="showPlan(planId)" preserve-scroll
                    >Reload history</Link
                ></Button
            >
        </template>
    </div>
</template>
