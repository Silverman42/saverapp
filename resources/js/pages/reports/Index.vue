<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
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
</script>

<template>
    <div class="space-y-6">
        <Head title="Reports" />
        <header>
            <h1 class="text-[25px] font-medium tracking-tight">Reports</h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                View the records that you can access. Activity shows this month
                in {{ timezone }} by default.
            </p>
        </header>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <Card v-for="report in catalogue" :key="report.code">
                <CardHeader>
                    <CardTitle
                        ><Link
                            :href="show(report.code)"
                            class="rounded-sm underline-offset-4 hover:underline focus-visible:outline-2"
                            >{{ report.title }}</Link
                        ></CardTitle
                    >
                </CardHeader>
                <CardContent class="space-y-3 text-sm">
                    <p>{{ report.basis }}</p>
                    <Badge variant="secondary">{{
                        report.code === 'fees'
                            ? 'Unavailable'
                            : 'Partial coverage'
                    }}</Badge>
                    <p class="text-muted-foreground">{{ report.reason }}</p>
                    <p class="text-muted-foreground">
                        CSV/PDF unavailable. {{ report.export_reason }}
                    </p>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
