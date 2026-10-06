<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChevronRight, Inbox } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { dashboard } from '@/routes';
import {
    index as batchesIndex,
    show as showBatch,
} from '@/routes/collection-batches';

type Batch = { id: number; date: string; revision: number; status: string };
defineProps<{
    batches: {
        data: Batch[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Cash batches', href: batchesIndex() },
        ],
    },
});
</script>

<template>
    <Head title="Cash batches" />
    <div class="flex flex-col gap-6">
        <PageHeader
            title="Cash batches"
            description="Cash collected by agents, grouped by day."
        />
        <EmptyState
            v-if="batches.data.length === 0"
            :icon="Inbox"
            title="No cash batches yet"
            description="A batch appears here after an agent records cash."
        />
        <Card v-else>
            <CardContent class="py-2">
                <ul class="divide-y">
                    <li v-for="batch in batches.data" :key="batch.id">
                        <Link
                            :href="showBatch(batch.id)"
                            class="hover:bg-muted/40 focus-visible:ring-ring -mx-2 flex flex-wrap items-center justify-between gap-3 rounded-lg px-2 py-4 focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <span class="min-w-0">
                                <span class="block font-medium">{{
                                    batch.date
                                }}</span>
                                <span
                                    v-if="batch.revision > 1"
                                    class="text-muted-foreground block text-xs"
                                    >Late additions</span
                                >
                            </span>
                            <span class="flex items-center gap-2">
                                <Badge variant="secondary" class="capitalize">{{
                                    batch.status.replaceAll('_', ' ')
                                }}</Badge>
                                <ChevronRight
                                    class="text-muted-foreground size-4"
                                />
                            </span>
                        </Link>
                    </li>
                </ul>
            </CardContent>
        </Card>
        <nav
            v-if="batches.prev_page_url || batches.next_page_url"
            aria-label="Batch pages"
            class="flex gap-4 text-sm"
        >
            <Link
                v-if="batches.prev_page_url"
                :href="batches.prev_page_url"
                class="underline-offset-4 hover:underline"
                >Previous</Link
            ><Link
                v-if="batches.next_page_url"
                :href="batches.next_page_url"
                class="underline-offset-4 hover:underline"
                >Next</Link
            >
        </nav>
    </div>
</template>
