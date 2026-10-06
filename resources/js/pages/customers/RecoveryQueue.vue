<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { dashboard } from '@/routes';
import { show } from '@/routes/customers/recovery';
import { ShieldCheck } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
defineProps<{
    recoveries: {
        data: Array<{
            reference: string;
            customer_reference: string;
            state: string;
            version: number;
        }>;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Customer recovery', href: '#' },
        ],
    },
});
</script>
<template>
    <div class="space-y-6">
        <Head title="Customer recovery review" />
        <PageHeader
            title="Account recovery requests"
            description="Check each request before giving new account access."
        />
        <EmptyState
            v-if="!recoveries.data.length"
            :icon="ShieldCheck"
            title="No requests"
            description="New account recovery requests will show up here."
        />
        <Card v-else>
            <CardContent>
                <ul class="divide-y">
                    <li
                        v-for="recovery in recoveries.data"
                        :key="recovery.reference"
                        class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
                    >
                        <div class="min-w-0">
                            <p class="font-medium">
                                {{ recovery.customer_reference }}
                            </p>
                            <Badge variant="secondary" class="mt-1 capitalize">
                                {{ recovery.state.replaceAll('_', ' ') }}
                            </Badge>
                        </div>
                        <Button as-child variant="outline" size="sm">
                            <Link :href="show.url(recovery.customer_reference)"
                                >Review</Link
                            >
                        </Button>
                    </li>
                </ul>
            </CardContent>
        </Card>
        <nav
            v-if="recoveries.links.length > 3"
            aria-label="Recovery pages"
            class="flex flex-wrap gap-2"
        >
            <template
                v-for="(link, position) in recoveries.links"
                :key="position"
                ><Link
                    v-if="link.url"
                    :href="link.url"
                    :aria-current="link.active ? 'page' : undefined"
                    class="rounded-md border px-3 py-2 text-sm"
                    :class="link.active ? 'bg-accent font-medium' : ''"
                    >{{
                        link.label.replace(/&laquo;|&raquo;/g, '').trim()
                    }}</Link
                ></template
            >
        </nav>
    </div>
</template>
