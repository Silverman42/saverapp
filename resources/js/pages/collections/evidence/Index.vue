<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronRight, FileCheck2 } from '@lucide/vue';
import { ref } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { dashboard } from '@/routes';
import { index, view } from '@/routes/collection-evidence';
import { index as collections } from '@/routes/collections';

const props = defineProps<{
    evidence: {
        data: Array<{
            evidence_reference: string;
            customer_id: string;
            customer_name: string;
            method_label: string;
            received_date: string;
            amount_kobo: number;
            status: string;
        }>;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: { status: string };
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Collections', href: collections() },
            { title: 'Payment evidence', href: index() },
        ],
    },
});
const status = ref(props.filters.status);
const money = (amount: number) =>
    `₦${(amount / 100).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
function filter(): void {
    router.get(index.url({ query: { status: status.value } }));
}
</script>

<template>
    <Head title="Payment evidence" />
    <div class="flex flex-col gap-6">
        <PageHeader
            title="Payment evidence"
            description="Proof of transfers and other payments. Checking proof does not record a payment."
        />
        <div class="flex flex-row flex-wrap items-end gap-4">
            <div class="grid w-fit gap-2">
                <Label for="evidence-status">Status</Label
                ><Select v-model="status" @update:model-value="filter"
                    ><SelectTrigger id="evidence-status" class="h-11 w-fit"
                        ><SelectValue /></SelectTrigger
                    ><SelectContent>
                        <SelectItem value="all">All</SelectItem>
                        <SelectItem value="pending"
                            >Waiting for check</SelectItem
                        >
                        <SelectItem value="verified"
                            >Checked, not used yet</SelectItem
                        >
                        <SelectItem value="rejected">Rejected</SelectItem>
                        <SelectItem value="consumed"
                            >Used for a payment</SelectItem
                        >
                    </SelectContent></Select
                >
            </div>
        </div>
        <EmptyState
            v-if="evidence.data.length === 0"
            :icon="FileCheck2"
            title="No payment evidence"
            description="Nothing matches this status. Try another status."
        />
        <Card v-else>
            <CardContent class="py-2">
                <ul class="divide-y">
                    <li
                        v-for="proof in evidence.data"
                        :key="proof.evidence_reference"
                    >
                        <Link
                            :href="view(proof.evidence_reference)"
                            :aria-label="`Review evidence for ${proof.customer_name}`"
                            class="hover:bg-muted/40 focus-visible:ring-ring -mx-2 flex flex-wrap items-center justify-between gap-3 rounded-lg px-2 py-4 focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <span class="grid min-w-0 gap-0.5 text-sm">
                                <span class="font-medium">{{
                                    proof.customer_name
                                }}</span>
                                <span class="text-muted-foreground text-xs"
                                    >{{ proof.method_label }} ·
                                    {{ proof.received_date }}</span
                                >
                            </span>
                            <span class="flex items-center gap-3">
                                <span class="text-sm font-medium">{{
                                    money(proof.amount_kobo)
                                }}</span>
                                <Badge variant="secondary">{{
                                    proof.status
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
            v-if="evidence.prev_page_url || evidence.next_page_url"
            aria-label="Evidence pages"
            class="flex gap-4 text-sm"
        >
            <Link
                v-if="evidence.prev_page_url"
                :href="evidence.prev_page_url"
                class="underline-offset-4 hover:underline"
                >Previous</Link
            ><Link
                v-if="evidence.next_page_url"
                :href="evidence.next_page_url"
                class="underline-offset-4 hover:underline"
                >Next</Link
            >
        </nav>
    </div>
</template>
