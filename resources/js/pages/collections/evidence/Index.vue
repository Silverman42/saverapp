<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
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
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">
                Payment evidence
            </h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                Protected payment claims in your current Customer scope.
                Verification remains separate from receipt posting.
            </p>
        </div>
        <div class="flex flex-row flex-wrap items-end gap-4">
            <div class="grid w-fit gap-2">
                <Label for="evidence-status">Status</Label
                ><select
                    id="evidence-status"
                    v-model="status"
                    class="bg-background h-11 w-fit rounded-md border px-3 text-sm"
                >
                    <option value="all">All</option>
                    <option value="pending">Pending verification</option>
                    <option value="verified">Verified, unconsumed</option>
                    <option value="rejected">Rejected</option>
                    <option value="consumed">Consumed by receipt</option>
                </select>
            </div>
            <Button type="button" variant="outline" @click="filter"
                >Apply filter</Button
            >
        </div>
        <Card
            ><CardContent class="grid gap-4 pt-6">
                <p
                    v-if="evidence.data.length === 0"
                    class="text-muted-foreground text-sm"
                >
                    No payment evidence matches this scope and status.
                </p>
                <div
                    v-for="proof in evidence.data"
                    :key="proof.evidence_reference"
                    class="flex flex-wrap items-center justify-between gap-3 border-b pb-4 last:border-0"
                >
                    <div class="grid min-w-0 gap-1 text-sm">
                        <p class="font-medium">
                            {{ proof.customer_name }} · {{ proof.customer_id }}
                        </p>
                        <p>
                            {{ proof.method_label }} ·
                            {{ money(proof.amount_kobo) }} ·
                            {{ proof.received_date }}
                        </p>
                        <p>{{ proof.status }}</p>
                    </div>
                    <Link
                        :href="view(proof.evidence_reference)"
                        class="text-sm underline"
                        >Review evidence</Link
                    >
                </div>
            </CardContent></Card
        >
        <nav aria-label="Evidence pages" class="flex gap-4">
            <Link
                v-if="evidence.prev_page_url"
                :href="evidence.prev_page_url"
                class="underline"
                >Previous</Link
            ><Link
                v-if="evidence.next_page_url"
                :href="evidence.next_page_url"
                class="underline"
                >Next</Link
            >
        </nav>
    </div>
</template>
