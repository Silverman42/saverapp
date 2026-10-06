<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import MoreDetails from '@/components/MoreDetails.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dashboard } from '@/routes';
import { index, show } from '@/routes/reports';
import type { ReportDefinition } from '@/types/reports';

defineProps<{ catalogue: ReportDefinition[]; timezone: string }>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Reports', href: index() },
        ],
    },
});

const summaries: Record<string, string> = {
    'customer-summary': 'Each customer’s current savings.',
    contributions: 'Payments received, by date.',
    withdrawals: 'Withdrawal requests and payouts.',
    fees: 'Fees charged, paid and still owed.',
    'collection-performance': 'How much was collected, by date.',
    reconciliation: 'Cash held by agents and the business.',
    'agent-performance': 'Each agent’s customers and collections.',
    plans: 'Plans, their status and progress.',
    exceptions: 'Items that need attention.',
};
</script>

<template>
    <div class="space-y-6">
        <Head title="Reports" />
        <PageHeader
            title="Reports"
            description="See totals and lists for your business. Most show this month."
        />
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <Card v-for="report in catalogue" :key="report.code">
                <CardHeader
                    class="flex flex-row items-start justify-between gap-3"
                >
                    <CardTitle
                        ><Link
                            :href="show(report.code)"
                            class="focus-visible:ring-ring rounded-sm underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:outline-none"
                            >{{ report.title }}</Link
                        ></CardTitle
                    >
                    <Badge v-if="report.code === 'fees'" variant="secondary"
                        >Not available yet</Badge
                    >
                </CardHeader>
                <CardContent class="flex flex-1 flex-col gap-4 text-sm">
                    <p class="text-muted-foreground">
                        {{ summaries[report.code] ?? report.basis }}
                    </p>
                    <Link
                        :href="show(report.code)"
                        class="inline-flex w-fit items-center gap-1 font-medium underline-offset-4 hover:underline"
                        :aria-label="`Open ${report.title}`"
                        >Open report <ArrowRight class="size-4"
                    /></Link>
                    <MoreDetails label="About this report" class="mt-auto">
                        <div
                            class="text-muted-foreground space-y-2 text-xs leading-5"
                        >
                            <p>{{ report.basis }}.</p>
                            <p>
                                Some data may not be complete yet.
                                {{ report.reason }}
                            </p>
                            <p>
                                <template v-if="!report.export_available"
                                    >Downloads are not available.
                                </template>
                                {{ report.export_reason }}
                            </p>
                            <p>Dates use {{ timezone }}.</p>
                        </div>
                    </MoreDetails>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
