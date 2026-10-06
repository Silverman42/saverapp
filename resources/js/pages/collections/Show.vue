<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import MoreDetails from '@/components/MoreDetails.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
    method_reference: string | null;
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
        <PageHeader
            :title="`Receipt ${receipt.id}`"
            :description="`${receipt.customer_name} paid on ${receipt.received_date}.`"
        >
            <template v-if="receipt.plan_id" #actions>
                <Button variant="outline" as-child
                    ><Link :href="planCard(receipt.plan_id)"
                        >View thrift card <ArrowRight class="size-4" /></Link
                ></Button>
            </template>
        </PageHeader>

        <Card>
            <CardHeader
                class="flex flex-row flex-wrap items-center justify-between gap-3"
            >
                <CardTitle>Payment</CardTitle>
                <Badge variant="secondary">{{ receipt.method }}</Badge>
            </CardHeader>
            <CardContent class="space-y-5">
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="bg-muted/40 rounded-xl p-4">
                        <p class="text-muted-foreground text-sm">Total paid</p>
                        <p class="mt-1 text-2xl font-semibold">
                            {{ money(receipt.tender_kobo) }}
                        </p>
                    </div>
                    <div class="bg-muted/40 rounded-xl p-4">
                        <p class="text-muted-foreground text-sm">To savings</p>
                        <p class="mt-1 text-lg font-medium">
                            {{ money(receipt.savings_kobo) }}
                        </p>
                    </div>
                    <div class="bg-muted/40 rounded-xl p-4">
                        <p class="text-muted-foreground text-sm">Fees</p>
                        <p class="mt-1 text-lg font-medium">
                            {{ money(receipt.fees_kobo) }}
                        </p>
                    </div>
                </div>
                <div>
                    <h2 class="text-sm font-medium">Days paid for</h2>
                    <p
                        v-if="receipt.allocations.length === 0"
                        class="text-muted-foreground mt-2 text-sm"
                    >
                        This payment only covered fees.
                    </p>
                    <ul v-else class="mt-2 divide-y text-sm">
                        <li
                            v-for="slot in receipt.allocations"
                            :key="slot.date"
                            class="flex flex-wrap items-center justify-between gap-2 py-2"
                        >
                            <span
                                >{{ slot.date }}
                                <Badge
                                    v-if="slot.is_advance"
                                    variant="outline"
                                    class="ml-2"
                                    >Paid early</Badge
                                ></span
                            >
                            <span class="font-medium">{{
                                money(slot.amount_kobo)
                            }}</span>
                        </li>
                    </ul>
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardHeader><CardTitle>Customer balance</CardTitle></CardHeader>
            <CardContent>
                <dl class="grid gap-3 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-muted-foreground">Total saved</dt>
                        <dd class="mt-1 font-medium">
                            {{ money(receipt.position.liability_kobo) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Set aside</dt>
                        <dd class="mt-1 font-medium">
                            {{ money(receipt.position.reservations_kobo) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Available</dt>
                        <dd class="mt-1 font-medium">
                            {{ money(receipt.position.available_kobo) }}
                        </dd>
                    </div>
                </dl>
            </CardContent>
        </Card>

        <MoreDetails>
            <dl class="text-muted-foreground grid gap-2 text-sm">
                <div v-if="receipt.method_reference" class="break-all">
                    <dt class="text-foreground inline font-medium">
                        Payment reference:
                    </dt>
                    <dd class="inline">{{ receipt.method_reference }}</dd>
                </div>
                <div v-if="receipt.received_at_utc">
                    <dt class="text-foreground inline font-medium">
                        Received at:
                    </dt>
                    <dd class="inline">{{ receipt.received_at_utc }} UTC</dd>
                </div>
                <div>
                    <dt class="text-foreground inline font-medium">
                        Recorded at:
                    </dt>
                    <dd class="inline">{{ receipt.recorded_at }}</dd>
                </div>
                <div>
                    <dt class="text-foreground inline font-medium">
                        Time zone:
                    </dt>
                    <dd class="inline">{{ receipt.timezone }}</dd>
                </div>
                <div>
                    <dt class="text-foreground inline font-medium">
                        Customer ID:
                    </dt>
                    <dd class="inline">{{ receipt.customer_id }}</dd>
                </div>
            </dl>
        </MoreDetails>
    </div>
</template>
