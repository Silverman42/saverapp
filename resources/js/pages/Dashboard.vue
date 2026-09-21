<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Activity,
    Banknote,
    CircleDashed,
    Clock3,
    RefreshCw,
    ShieldCheck,
    UsersRound,
    WalletCards,
} from '@lucide/vue';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

const period = ref('today');

const metrics = [
    {
        label: 'Customer savings liability',
        description: 'Authoritative ledger total at the current cutoff',
        icon: WalletCards,
    },
    {
        label: 'Available savings',
        description: 'Liability less live payout reservations',
        icon: Banknote,
    },
    {
        label: 'Active customers',
        description: 'Current permitted customer scope',
        icon: UsersRound,
    },
    {
        label: 'Collection activity',
        description: 'Posted receipts for the selected period',
        icon: Activity,
    },
    {
        label: 'Pending withdrawals',
        description: 'Live requests awaiting their owning workflow',
        icon: Clock3,
    },
    {
        label: 'Open exceptions',
        description: 'Current reconciliation and operational issues',
        icon: ShieldCheck,
    },
];

const refreshDashboard = (): void => {
    router.reload();
};
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-1 flex-col gap-6">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
        >
            <div>
                <Badge
                    variant="outline"
                    class="border-primary/20 bg-accent text-accent-foreground mb-3"
                >
                    Operations overview
                </Badge>
                <h1 class="text-[25px] font-medium tracking-tight">
                    Dashboard
                </h1>
                <p class="text-muted-foreground mt-1.5 text-sm">
                    A source-aware view of savings operations and account
                    activity.
                </p>
            </div>

            <div class="flex items-center gap-2">
                <Select v-model="period">
                    <SelectTrigger class="w-36" aria-label="Dashboard period">
                        <SelectValue placeholder="Select period" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="today">Today</SelectItem>
                        <SelectItem value="week">This week</SelectItem>
                        <SelectItem value="month">This month</SelectItem>
                    </SelectContent>
                </Select>
                <Button variant="outline" @click="refreshDashboard">
                    <RefreshCw class="size-4" />
                    Refresh
                </Button>
            </div>
        </div>

        <section aria-labelledby="dashboard-metrics-title">
            <h2 id="dashboard-metrics-title" class="sr-only">
                Dashboard metrics
            </h2>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <Card
                    v-for="metric in metrics"
                    :key="metric.label"
                    class="min-h-44"
                >
                    <CardHeader class="flex-row items-start justify-between">
                        <div class="space-y-1.5">
                            <CardDescription>{{
                                metric.label
                            }}</CardDescription>
                            <CardTitle class="text-2xl"
                                >Not available</CardTitle
                            >
                        </div>
                        <div
                            class="bg-accent text-accent-foreground flex size-10 items-center justify-center rounded-xl"
                        >
                            <component :is="metric.icon" class="size-5" />
                        </div>
                    </CardHeader>
                    <CardContent class="mt-auto">
                        <p class="text-muted-foreground text-xs leading-5">
                            {{ metric.description }}. Live projection data is
                            not connected yet.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </section>

        <div
            class="grid gap-4 xl:grid-cols-[minmax(0,1.18fr)_minmax(22rem,0.82fr)]"
        >
            <Card>
                <CardHeader class="border-border border-b">
                    <CardTitle>Recent activity</CardTitle>
                    <CardDescription>
                        Posted savings, payouts, corrections, and account
                        events.
                    </CardDescription>
                </CardHeader>
                <CardContent
                    class="flex min-h-72 items-center justify-center py-10"
                >
                    <div class="max-w-sm text-center">
                        <div
                            class="bg-muted text-muted-foreground mx-auto flex size-12 items-center justify-center rounded-2xl"
                        >
                            <CircleDashed class="size-5" />
                        </div>
                        <p class="mt-4 text-sm font-semibold">
                            No activity projection available
                        </p>
                        <p class="text-muted-foreground mt-1 text-xs leading-5">
                            Activity will appear here after an authoritative
                            dashboard source is connected.
                        </p>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="border-border border-b">
                    <CardTitle>Data readiness</CardTitle>
                    <CardDescription>
                        Each summary keeps unavailable data separate from a
                        confirmed zero.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-3">
                    <div
                        v-for="source in [
                            'Ledger and balances',
                            'Collections and activity',
                            'Requests and exceptions',
                        ]"
                        :key="source"
                        class="border-border flex items-center justify-between gap-4 rounded-xl border px-4 py-3.5"
                    >
                        <div class="flex items-center gap-3">
                            <span
                                class="bg-muted flex size-8 items-center justify-center rounded-lg"
                            >
                                <CircleDashed
                                    class="text-muted-foreground size-4"
                                />
                            </span>
                            <span class="text-sm font-medium">{{
                                source
                            }}</span>
                        </div>
                        <Badge variant="secondary">Not connected</Badge>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
