<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, Inbox } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import MoreDetails from '@/components/MoreDetails.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { create as createCollection } from '@/routes/customers/collections';
import type { DashboardSection } from '@/types/dashboard';

defineProps<{
    code: string;
    title: string;
    section: DashboardSection;
    canCollect: boolean;
}>();

const statusLabels: Record<DashboardSection['status'], string> = {
    Current: 'Up to date',
    Stale: 'May be out of date',
    Rebuilding: 'Updating',
    Unavailable: 'Not available',
    Partial: 'Partly loaded',
};
</script>

<template>
    <section :aria-labelledby="`section-${code}`">
        <Card>
            <CardHeader
                class="flex flex-row flex-wrap items-center justify-between gap-3"
            >
                <CardTitle :id="`section-${code}`">{{ title }}</CardTitle>
                <Badge
                    v-if="section.status !== 'Current'"
                    variant="secondary"
                    >{{ statusLabels[section.status] }}</Badge
                >
            </CardHeader>
            <CardContent class="space-y-5">
                <p v-if="section.reason" class="text-muted-foreground text-sm">
                    {{ section.reason }}
                </p>
                <div
                    v-if="section.metrics.length"
                    class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
                >
                    <component
                        :is="metric.drill_down ? Link : 'div'"
                        v-for="metric in section.metrics"
                        :key="metric.code"
                        :href="metric.drill_down ?? undefined"
                        :aria-label="
                            metric.drill_down
                                ? `View report for ${metric.title}`
                                : undefined
                        "
                        class="bg-muted/40 group flex flex-col justify-between gap-3 rounded-xl p-4 transition-colors"
                        :class="
                            metric.drill_down
                                ? 'hover:bg-accent/60 focus-visible:ring-ring focus-visible:ring-2 focus-visible:outline-none'
                                : ''
                        "
                    >
                        <p class="text-muted-foreground text-sm">
                            {{ metric.title }}
                        </p>
                        <div class="flex items-end justify-between gap-2">
                            <p
                                class="text-2xl font-semibold break-words"
                                :aria-label="`${metric.display} ${metric.unit === 'NGN' ? 'NGN' : ''}`"
                            >
                                {{ metric.display }}
                            </p>
                            <ArrowRight
                                v-if="metric.drill_down"
                                class="text-muted-foreground group-hover:text-foreground size-4 shrink-0 transition-transform group-hover:translate-x-0.5"
                            />
                        </div>
                    </component>
                </div>
                <div v-if="section.rows?.length" class="divide-border divide-y">
                    <div
                        v-for="row in section.rows"
                        :key="row.reference ?? row.plan_id"
                        class="flex flex-wrap items-center justify-between gap-3 py-3"
                    >
                        <div class="min-w-0">
                            <Link
                                :href="row.href"
                                class="text-sm font-medium underline-offset-4 hover:underline"
                                >{{
                                    row.reference ?? row.name ?? row.plan_id
                                }}</Link
                            >
                            <p class="text-muted-foreground mt-0.5 text-xs">
                                {{ row.type ?? row.customer_id }} ·
                                {{
                                    row.state ?? row.occurred_on ?? row.plan_id
                                }}
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="text-sm font-medium">{{
                                row.amount ?? row.remaining
                            }}</span>
                            <Button
                                v-if="
                                    code === 'schedule' &&
                                    canCollect &&
                                    row.can_record_cash &&
                                    row.customer_id
                                "
                                as-child
                                size="sm"
                                variant="outline"
                                ><Link
                                    :href="
                                        createCollection(row.customer_id).url
                                    "
                                    >Record cash</Link
                                ></Button
                            >
                        </div>
                    </div>
                </div>
                <EmptyState
                    v-else-if="section.rows && section.status !== 'Unavailable'"
                    :icon="Inbox"
                    title="Nothing here yet"
                    description="Items will show up here when there is activity."
                />
                <div v-if="section.trend" class="space-y-3">
                    <h3 class="text-sm font-medium">
                        Savings received, last 30 days
                    </h3>
                    <div class="max-h-64 overflow-y-auto rounded-xl border">
                        <table class="w-full text-left text-sm">
                            <caption class="sr-only">
                                Savings received by date, last 30 days
                            </caption>
                            <thead class="text-muted-foreground text-xs">
                                <tr>
                                    <th scope="col" class="p-3 font-medium">
                                        Date
                                    </th>
                                    <th
                                        scope="col"
                                        class="p-3 text-right font-medium"
                                    >
                                        Amount (NGN)
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="point in section.trend"
                                    :key="point.date"
                                    class="border-t"
                                >
                                    <th scope="row" class="p-3 font-normal">
                                        {{ point.date }}
                                    </th>
                                    <td class="p-3 text-right">
                                        {{ point.display }}
                                    </td>
                                </tr>
                                <tr v-if="!section.trend.length">
                                    <td
                                        colspan="2"
                                        class="text-muted-foreground p-3"
                                    >
                                        No savings received in the last 30 days.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <Link
                    v-if="section.href"
                    :href="section.href"
                    class="inline-flex items-center gap-1 text-sm font-medium underline-offset-4 hover:underline"
                    >See all {{ section.total }} transactions
                    <ArrowRight class="size-4"
                /></Link>
                <MoreDetails
                    v-if="section.metrics.length || section.note"
                    label="About these numbers"
                >
                    <div
                        class="text-muted-foreground space-y-3 text-xs leading-5"
                    >
                        <p v-if="section.note">{{ section.note }}</p>
                        <dl class="space-y-2">
                            <div
                                v-for="metric in section.metrics"
                                :key="metric.code"
                            >
                                <dt class="text-foreground font-medium">
                                    {{ metric.title }}
                                </dt>
                                <dd>{{ metric.definition }}</dd>
                            </div>
                        </dl>
                    </div>
                </MoreDetails>
            </CardContent>
        </Card>
    </section>
</template>
