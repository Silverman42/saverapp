<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { ShieldAlert } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import ReversalStatusBadge from '@/components/ReversalStatusBadge.vue';
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
    index as reversalsIndex,
    show as showReversal,
} from '@/routes/reversals';

type Row = {
    id: string;
    customer_name: string | null;
    original_reference: string;
    original_amount_kobo: number;
    state: string;
    requested_at: string;
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
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Reversals', href: reversalsIndex() },
        ],
    },
});

const state = ref(props.state_filter);
const money = (kobo: number): string =>
    `₦${(kobo / 100).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

function applyFilter(): void {
    router.get(reversalsIndex.url({ query: { state: state.value } }));
}
function onStateChange(value: unknown): void {
    state.value = value === '__all' ? '' : String(value ?? '');
    applyFilter();
}
</script>

<template>
    <Head title="Reversals" />
    <div class="flex flex-col gap-6">
        <PageHeader
            title="Reversals"
            description="Requests to undo or correct a past payment."
        />
        <p role="status" class="text-muted-foreground -mt-2 text-sm">
            New reversal requests can't be made yet.
        </p>
        <div class="flex flex-row flex-wrap gap-4">
            <div class="grid w-fit gap-2">
                <Label for="reversal-state">Status</Label>
                <Select
                    :model-value="state || '__all'"
                    @update:model-value="onStateChange"
                    ><SelectTrigger id="reversal-state" class="h-11 w-fit"
                        ><SelectValue /></SelectTrigger
                    ><SelectContent
                        ><SelectItem value="__all">All statuses</SelectItem
                        ><SelectItem value="pending_review"
                            >Waiting for review</SelectItem
                        ><SelectItem value="approved_posted"
                            >Approved, money corrected</SelectItem
                        ><SelectItem value="approved_no_money"
                            >Approved, no money moved</SelectItem
                        ><SelectItem value="rejected">Rejected</SelectItem
                        ><SelectItem value="cancelled"
                            >Cancelled</SelectItem
                        ></SelectContent
                    ></Select
                >
            </div>
        </div>
        <EmptyState
            v-if="requests.data.length === 0"
            :icon="ShieldAlert"
            title="No reversals found"
            :description="
                state
                    ? 'Try a different status to see more requests.'
                    : 'Reversal requests will show up here.'
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
                                :href="showReversal(item.id)"
                                class="font-medium underline-offset-4 hover:underline"
                                >{{ item.customer_name ?? item.id }}</Link
                            >
                            <p class="text-muted-foreground text-xs">
                                {{ item.id }} · for
                                {{ item.original_reference }} ·
                                {{ item.requested_at }}
                            </p>
                            <div class="pt-1">
                                <ReversalStatusBadge :state="item.state" />
                            </div>
                        </div>
                        <p class="font-medium">
                            {{ money(item.original_amount_kobo) }}
                        </p>
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
