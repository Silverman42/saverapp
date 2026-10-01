<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
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
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">Cash batches</h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                Daily cash received by the recording Agent, with separate late
                supplements.
            </p>
        </div>
        <Card
            ><CardContent class="pt-6"
                ><p
                    v-if="batches.data.length === 0"
                    class="text-muted-foreground text-sm"
                >
                    No cash batches yet.
                </p>
                <ul v-else class="divide-y">
                    <li
                        v-for="batch in batches.data"
                        :key="batch.id"
                        class="flex flex-wrap items-center justify-between gap-3 py-4 first:pt-0 last:pb-0"
                    >
                        <Link
                            :href="showBatch(batch.id)"
                            class="font-medium underline"
                            >{{ batch.date }} · revision
                            {{ batch.revision }}</Link
                        ><span
                            class="text-muted-foreground text-sm capitalize"
                            >{{ batch.status.replaceAll('_', ' ') }}</span
                        >
                    </li>
                </ul></CardContent
            ></Card
        >
        <div class="flex gap-4 text-sm">
            <Link
                v-if="batches.prev_page_url"
                :href="batches.prev_page_url"
                class="underline"
                >Previous</Link
            ><Link
                v-if="batches.next_page_url"
                :href="batches.next_page_url"
                class="underline"
                >Next</Link
            >
        </div>
    </div>
</template>
