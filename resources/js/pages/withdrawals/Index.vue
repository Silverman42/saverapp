<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import {
    index as withdrawalsIndex,
    show as showWithdrawal,
} from '@/routes/withdrawals';

type Row = {
    id: string;
    customer_name: string;
    plan_id: string;
    type: string;
    gross_kobo: number;
    fee_kobo: number;
    net_kobo: number;
    method: string;
    state: string;
    held: boolean;
    hold_reason: string | null;
    submitted_at: string;
};
const props = defineProps<{
    requests: {
        data: Row[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    state_filter: string;
    can_review: boolean;
    role: string;
    new_requests_available: boolean;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Withdrawals', href: withdrawalsIndex() },
        ],
    },
});
const state = ref(props.state_filter);
const money = (kobo: number): string =>
    `₦${(kobo / 100).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
function applyFilter(): void {
    router.get(withdrawalsIndex.url({ query: { state: state.value } }));
}
</script>

<template>
    <Head title="Withdrawals" />
    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">Withdrawals</h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                Requests and reservations in your permitted Customer scope.
                Approval does not mean payment.
            </p>
        </div>
        <Card v-if="!new_requests_available"
            ><CardContent class="pt-6"
                ><p class="text-sm">
                    Payout methods are awaiting approved executor, custody, and
                    evidence contracts. New requests are currently unavailable.
                </p></CardContent
            ></Card
        >
        <div class="flex flex-row flex-wrap items-end gap-4">
            <div class="grid w-fit gap-2">
                <label for="withdrawal-state" class="text-sm font-medium"
                    >State</label
                ><select
                    id="withdrawal-state"
                    v-model="state"
                    class="border-input bg-background h-11 rounded-md border px-3 text-sm"
                >
                    <option value="">All states</option>
                    <option value="pending_review">Pending review</option>
                    <option value="approved">Approved, awaiting payout</option>
                    <option value="payout_processing">Payout processing</option>
                    <option value="outcome_unknown">Outcome unknown</option>
                    <option value="payment_failed">Payment failed</option>
                    <option value="posted">Posted</option>
                    <option
                        v-if="role !== 'customer'"
                        value="needs_reconciliation"
                    >
                        Needs reconciliation
                    </option>
                    <option value="rejected">Rejected</option>
                    <option value="cancelled">Cancelled</option>
                    <option value="expired">Expired</option>
                </select>
            </div>
            <Button type="button" variant="outline" @click="applyFilter"
                >Apply filter</Button
            >
        </div>
        <Card
            ><CardContent class="pt-6"
                ><p
                    v-if="requests.data.length === 0"
                    class="text-muted-foreground text-sm"
                >
                    No withdrawal requests match this scope and filter.
                </p>
                <ul v-else class="divide-y">
                    <li
                        v-for="item in requests.data"
                        :key="item.id"
                        class="flex flex-wrap items-center justify-between gap-3 py-4 first:pt-0 last:pb-0"
                    >
                        <div class="grid gap-1">
                            <Link
                                :href="showWithdrawal(item.id)"
                                class="font-medium underline"
                                >{{ item.id }}</Link
                            ><span class="text-muted-foreground text-sm"
                                >{{ item.customer_name }} · {{ item.plan_id }} ·
                                {{ item.type }} ·
                                {{ item.method.replaceAll('_', ' ') }}</span
                            ><span class="text-sm"
                                >{{ item.state.replaceAll('_', ' ')
                                }}<span v-if="item.held">
                                    · On hold<span v-if="item.hold_reason"
                                        >:
                                        {{
                                            item.hold_reason.replaceAll(
                                                '_',
                                                ' ',
                                            )
                                        }}</span
                                    ></span
                                ></span
                            >
                        </div>
                        <div class="grid gap-1 text-sm">
                            <span>Gross {{ money(item.gross_kobo) }}</span
                            ><span>Net payout {{ money(item.net_kobo) }}</span>
                        </div>
                    </li>
                </ul></CardContent
            ></Card
        >
        <div class="flex gap-4 text-sm">
            <Link
                v-if="requests.prev_page_url"
                :href="requests.prev_page_url"
                class="underline"
                >Previous</Link
            ><Link
                v-if="requests.next_page_url"
                :href="requests.next_page_url"
                class="underline"
                >Next</Link
            >
        </div>
    </div>
</template>
