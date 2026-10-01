<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dashboard } from '@/routes';
import { index as collectionsIndex } from '@/routes/collections';
import { card as planCard } from '@/routes/plans';

type Receipt = {
    id: string;
    customer_id: string;
    customer_name: string;
    plan_id: string | null;
    received_date: string;
    received_at_utc: string | null;
    recorded_at: string;
    timezone: string;
    method: string;
    tender_kobo: number;
    savings_kobo: number;
    fees_kobo: number;
    allocations: Array<{
        date: string;
        amount_kobo: number;
        is_advance: boolean;
    }>;
    position: {
        liability_kobo: number;
        reservations_kobo: number;
        available_kobo: number;
    };
};
defineProps<{ receipt: Receipt }>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Collections', href: collectionsIndex() },
            { title: 'Receipt', href: '#' },
        ],
    },
});
const money = (kobo: number): string =>
    `₦${(kobo / 100).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
</script>

<template>
    <Head :title="`Receipt ${receipt.id}`" />
    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">
                Cash receipt {{ receipt.id }}
            </h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                Posted for {{ receipt.customer_name }} on
                {{ receipt.received_date }} ({{ receipt.timezone }}).
            </p>
        </div>
        <p v-if="receipt.received_at_utc" class="text-muted-foreground text-sm">
            Actual received instant: {{ receipt.received_at_utc }} UTC. Recorded
            in the system: {{ receipt.recorded_at }}.
        </p>
        <Card
            ><CardHeader><CardTitle>Money received</CardTitle></CardHeader
            ><CardContent class="grid gap-3 sm:grid-cols-3"
                ><div>
                    Savings<br /><strong>{{
                        money(receipt.savings_kobo)
                    }}</strong>
                </div>
                <div>
                    Fees<br /><strong>{{ money(receipt.fees_kobo) }}</strong>
                </div>
                <div>
                    Total cash<br /><strong>{{
                        money(receipt.tender_kobo)
                    }}</strong>
                </div></CardContent
            ></Card
        >
        <Card
            ><CardHeader><CardTitle>Funded slots</CardTitle></CardHeader
            ><CardContent
                ><p
                    v-if="receipt.allocations.length === 0"
                    class="text-muted-foreground text-sm"
                >
                    This fee-only receipt funded no slots.
                </p>
                <ul v-else class="grid gap-2 text-sm">
                    <li v-for="slot in receipt.allocations" :key="slot.date">
                        {{ slot.date }} · {{ money(slot.amount_kobo) }}
                        <span v-if="slot.is_advance">· advance</span>
                    </li>
                </ul></CardContent
            ></Card
        >
        <Card
            ><CardHeader
                ><CardTitle>Customer savings position</CardTitle></CardHeader
            ><CardContent class="grid gap-3 sm:grid-cols-3"
                ><div>
                    Liability<br /><strong>{{
                        money(receipt.position.liability_kobo)
                    }}</strong>
                </div>
                <div>
                    Reservations<br /><strong>{{
                        money(receipt.position.reservations_kobo)
                    }}</strong>
                </div>
                <div>
                    Available<br /><strong>{{
                        money(receipt.position.available_kobo)
                    }}</strong>
                </div></CardContent
            ></Card
        >
        <Link
            v-if="receipt.plan_id"
            :href="planCard(receipt.plan_id)"
            class="text-primary w-fit text-sm underline"
            >View thrift card</Link
        >
    </div>
</template>
