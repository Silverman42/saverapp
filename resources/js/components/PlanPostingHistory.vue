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
        <h3 class="font-medium">Recorded payout and deduction components</h3>
        <p class="text-muted-foreground">
            Each posting can have separate savings, cash and fee components.
            These amounts must not be added together. Pending reservations are
            shown separately.
        </p>
        <p v-if="summary.as_of" class="text-muted-foreground text-xs">
            History as of {{ committedAt(summary.as_of) }} UTC
        </p>
        <template v-if="summary.history">
            <p v-if="summary.history.total === 0" class="text-muted-foreground">
                No recorded payout or deduction components.
            </p>
            <p
                v-else-if="summary.history.data.length === 0"
                class="text-muted-foreground"
            >
                No components on this page.
                <Link
                    :href="historyLink(1)"
                    preserve-scroll
                    class="underline underline-offset-4"
                    >Return to the first page</Link
                >
            </p>
            <ol
                v-else
                class="space-y-3"
                aria-label="Recorded payout and deduction components"
            >
                <li
                    v-for="entry in summary.history.data"
                    :key="entry.key"
                    class="rounded-md border p-3"
                >
                    <div class="flex flex-wrap justify-between gap-2">
                        <p class="font-medium">{{ entry.title }}</p>
                        <p class="font-medium">{{ entry.amount }}</p>
                    </div>
                    <p class="text-muted-foreground mt-2 text-xs">
                        Occurred {{ entry.occurred_on ?? 'date unavailable'
                        }}<template v-if="entry.timezone">
                            · {{ entry.timezone }}</template
                        >
                    </p>
                    <p class="text-muted-foreground mt-1 text-xs">
                        Recorded {{ committedAt(entry.committed_at) }} UTC
                    </p>
                    <p class="text-muted-foreground mt-1 text-xs break-all">
                        Posting reference: {{ entry.reference }}
                    </p>
                </li>
            </ol>
            <nav
                class="flex flex-wrap items-center gap-3"
                aria-label="Payout and deduction history pages"
            >
                <Button
                    v-if="summary.history.current_page > 1"
                    variant="outline"
                    as-child
                    ><Link
                        :href="historyLink(summary.history.current_page - 1)"
                        preserve-scroll
                        >Previous components</Link
                    ></Button
                >
                <span
                    >Page {{ summary.history.current_page }} of
                    {{ summary.history.last_page }} ·
                    {{ summary.history.total }} components</span
                >
                <Button
                    v-if="
                        summary.history.current_page < summary.history.last_page
                    "
                    variant="outline"
                    as-child
                    ><Link
                        :href="historyLink(summary.history.current_page + 1)"
                        preserve-scroll
                        >Next components</Link
                    ></Button
                >
                <span>Components per page:</span>
                <Link
                    v-for="size in [25, 50, 100]"
                    :key="size"
                    :href="historyLink(1, size)"
                    preserve-scroll
                    class="underline underline-offset-4"
                    :aria-current="
                        summary.history.per_page === size ? 'true' : undefined
                    "
                    >{{ size }}</Link
                >
            </nav>
        </template>
        <template v-else>
            <p class="font-medium">Verified posting history unavailable.</p>
            <Button variant="outline" as-child
                ><Link :href="showPlan(planId)" preserve-scroll
                    >Reload posting history</Link
                ></Button
            >
        </template>
    </div>
</template>
