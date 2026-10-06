<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { WalletCards } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import WithdrawalStatusBadge from '@/components/WithdrawalStatusBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
function onStateChange(value: unknown): void {
    state.value = value === '__all' ? '' : String(value ?? '');
    applyFilter();
}
</script>

<template>
    <Head title="Withdrawals" />
    <div class="flex flex-col gap-6">
        <PageHeader
            title="Withdrawals"
            description="Track withdrawal requests and where each one is."
        />
        <p
            v-if="!new_requests_available"
            role="status"
            class="bg-muted rounded-xl p-4 text-sm"
        >
            New withdrawal requests are paused until payout setup is finished.
        </p>
        <div class="flex flex-row flex-wrap items-end gap-4">
            <div class="grid w-fit gap-2">
                <Label for="withdrawal-state">Status</Label>
                <Select
                    :model-value="state || '__all'"
                    @update:model-value="onStateChange"
                    ><SelectTrigger id="withdrawal-state" class="h-11 w-fit"
                        ><SelectValue /></SelectTrigger
                    ><SelectContent
                        ><SelectItem value="__all">All statuses</SelectItem
                        ><SelectItem value="pending_review"
                            >Waiting for review</SelectItem
                        ><SelectItem value="approved"
                            >Approved, not paid yet</SelectItem
                        ><SelectItem value="payout_processing"
                            >Paying out</SelectItem
                        ><SelectItem value="outcome_unknown"
                            >Checking payment</SelectItem
                        ><SelectItem value="payment_failed"
                            >Payment failed</SelectItem
                        ><SelectItem value="posted">Paid</SelectItem
                        ><SelectItem
                            v-if="role !== 'customer'"
                            value="needs_reconciliation"
                            >Needs checking</SelectItem
                        ><SelectItem value="rejected">Rejected</SelectItem
                        ><SelectItem value="cancelled">Cancelled</SelectItem
                        ><SelectItem value="expired"
                            >Expired</SelectItem
                        ></SelectContent
                    ></Select
                >
            </div>
        </div>
        <EmptyState
            v-if="requests.data.length === 0"
            :icon="WalletCards"
            title="No withdrawals found"
            :description="
                state
                    ? 'Try a different status to see more requests.'
                    : 'Withdrawal requests will show up here.'
            "
        />
        <Card v-else class="py-2">
            <CardContent>
                <ul class="divide-border divide-y">
                    <li
                        v-for="item in requests.data"
                        :key="item.id"
                        class="flex flex-wrap items-center justify-between gap-3 py-4"
                    >
                        <div class="min-w-0 space-y-1">
                            <Link
                                :href="showWithdrawal(item.id)"
                                class="font-medium underline-offset-4 hover:underline"
                                >{{ item.customer_name }}</Link
                            >
                            <p class="text-muted-foreground text-xs">
                                {{ item.id }} · {{ item.plan_id }} ·
                                {{ item.method.replaceAll('_', ' ') }} ·
                                {{ item.submitted_at }}
                            </p>
                            <div class="flex flex-wrap items-center gap-2 pt-1">
                                <WithdrawalStatusBadge :state="item.state" />
                                <Badge v-if="item.held" variant="outline"
                                    >On hold<template v-if="item.hold_reason"
                                        >:
                                        {{
                                            item.hold_reason.replaceAll(
                                                '_',
                                                ' ',
                                            )
                                        }}</template
                                    ></Badge
                                >
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="font-medium">
                                {{ money(item.net_kobo) }}
                            </p>
                            <p class="text-muted-foreground text-xs">
                                of {{ money(item.gross_kobo) }} withdrawn
                            </p>
                        </div>
                    </li>
                </ul>
            </CardContent>
        </Card>
        <div
            v-if="requests.prev_page_url || requests.next_page_url"
            class="flex gap-3"
        >
            <Button v-if="requests.prev_page_url" variant="outline" as-child
                ><Link :href="requests.prev_page_url">Previous</Link></Button
            ><Button v-if="requests.next_page_url" variant="outline" as-child
                ><Link :href="requests.next_page_url">Next</Link></Button
            >
        </div>
    </div>
</template>
