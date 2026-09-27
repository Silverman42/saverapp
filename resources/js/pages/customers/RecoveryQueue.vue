<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { dashboard } from '@/routes';
import { show } from '@/routes/customers/recovery';
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
        <header>
            <h1 class="text-[25px] font-medium tracking-tight">
                Customer recovery review
            </h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                Review verification before approving new account access.
            </p>
        </header>
        <Card
            ><CardContent class="pt-6"
                ><p
                    v-if="!recoveries.data.length"
                    class="text-muted-foreground text-sm"
                >
                    No Customer recovery requests.
                </p>
                <ul class="divide-y">
                    <li
                        v-for="recovery in recoveries.data"
                        :key="recovery.reference"
                        class="flex flex-wrap items-center justify-between gap-3 py-4"
                    >
                        <div>
                            <p class="font-medium">
                                {{ recovery.customer_reference }}
                            </p>
                            <p class="text-muted-foreground text-sm">
                                {{ recovery.state.replaceAll('_', ' ') }} ·
                                version {{ recovery.version }}
                            </p>
                        </div>
                        <Link
                            :href="show.url(recovery.customer_reference)"
                            class="text-sm underline"
                            >Review recovery</Link
                        >
                    </li>
                </ul></CardContent
            ></Card
        >
        <nav aria-label="Recovery pages" class="flex flex-wrap gap-3">
            <template
                v-for="(link, position) in recoveries.links"
                :key="position"
                ><Link
                    v-if="link.url"
                    :href="link.url"
                    :aria-current="link.active ? 'page' : undefined"
                    class="rounded-md border px-3 py-2 text-sm"
                    >{{
                        link.label.replace(/&laquo;|&raquo;/g, '').trim()
                    }}</Link
                ></template
            >
        </nav>
    </div>
</template>
