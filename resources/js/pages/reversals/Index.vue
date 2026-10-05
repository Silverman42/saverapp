<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Card, CardContent } from '@/components/ui/card';
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
</script>

<template>
    <Head title="Reversals" />
    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">Reversals</h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                This list shows correction requests and posted outcomes in your
                permitted Customer scope.
            </p>
        </div>
        <Card>
            <CardContent class="pt-6 text-sm">
                You cannot make new reversal requests now. The team must first
                verify the accounting, custody, and evidence contracts.
            </CardContent>
        </Card>
        <div class="flex flex-row flex-wrap gap-4">
            <div class="grid w-fit gap-2">
                <label for="reversal-state" class="text-sm font-medium"
                    >State</label
                >
                <Select
                    :model-value="state || '__all'"
                    @update:model-value="
                        state = $event === '__all' ? '' : String($event ?? '')
                    "
                    ><SelectTrigger id="reversal-state" class="h-11 w-fit"
                        ><SelectValue /></SelectTrigger
                    ><SelectContent
                        ><SelectItem value="__all">All states</SelectItem
                        ><SelectItem value="pending_review"
                            >Pending review</SelectItem
                        ><SelectItem value="approved_posted"
                            >Approved and posted</SelectItem
                        ><SelectItem value="approved_no_money"
                            >Approved, no money movement</SelectItem
                        ><SelectItem value="rejected">Rejected</SelectItem
                        ><SelectItem value="cancelled"
                            >Cancelled</SelectItem
                        ></SelectContent
                    ></Select
                >
            </div>
            <Button
                type="button"
                variant="outline"
                class="self-end"
                @click="applyFilter"
                >Apply filter</Button
            >
        </div>
        <Card>
            <CardContent class="pt-6">
                <p
                    v-if="requests.data.length === 0"
                    class="text-muted-foreground text-sm"
                >
                    No reversal requests match this scope and filter.
                </p>
                <ul v-else class="divide-y">
                    <li
                        v-for="item in requests.data"
                        :key="item.id"
                        class="flex flex-wrap items-center justify-between gap-3 py-4 first:pt-0 last:pb-0"
                    >
                        <div class="grid gap-1">
                            <Link
                                :href="showReversal(item.id)"
                                class="font-medium underline"
                                >{{ item.id }}</Link
                            >
                            <span class="text-muted-foreground text-sm"
                                >{{ item.customer_name }} · original
                                {{ item.original_reference }}</span
                            >
                            <span class="text-sm"
                                >{{ item.state.replaceAll('_', ' ') }} ·
                                {{ item.requested_at }}</span
                            >
                        </div>
                        <strong class="text-sm">{{
                            money(item.original_amount_kobo)
                        }}</strong>
                    </li>
                </ul>
            </CardContent>
        </Card>
        <div class="flex gap-4 text-sm">
            <Link
                v-if="requests.prev_page_url"
                :href="requests.prev_page_url"
                class="underline"
                >Previous</Link
            >
            <Link
                v-if="requests.next_page_url"
                :href="requests.next_page_url"
                class="underline"
                >Next</Link
            >
        </div>
    </div>
</template>
