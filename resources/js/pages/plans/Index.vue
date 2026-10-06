<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { Search, SlidersHorizontal, WalletCards } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import FormSheet from '@/components/FormSheet.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { DatePicker } from '@/components/ui/date-picker';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { PlanPostedActivity } from '@/types/plan-posted-activity';
import type { PlanSavings } from '@/types/plan-savings';
import type { PlanFeeHistory } from '@/types/plan-fee-history';
import type { PlanFundingSummary as FundingSummary } from '@/types/plan-funding';
import { dashboard } from '@/routes';
import { show as showCustomer } from '@/routes/customers';
import { index as plansIndex, show as showPlan } from '@/routes/plans';

type PlanRow = {
    id: string;
    name: string;
    customer: { id: string; name: string; status: string };
    agent: { id: string; name: string } | null;
    timezone: string | null;
    currency: string | null;
    fee: { name: string; amount: string; description: string } | null;
    fee_actuals: Pick<
        PlanFeeHistory,
        'status' | 'totals' | 'as_of' | 'message' | 'source_version'
    >;
    updated_at: string | null;
    status: string;
    status_label: string;
    terms_revision: number;
    contribution_amount: string | null;
    start_date: string | null;
    scheduled_end_date: string | null;
    financials: FundingSummary;
    savings_summary: PlanSavings;
    posted_activity: PlanPostedActivity;
    can_manage: boolean;
    created_at: string | null;
};

type Pagination = {
    data: PlanRow[];
    current_page: number;
    last_page: number;
    total: number;
    next_page_url: string | null;
    prev_page_url: string | null;
};

const props = defineProps<{
    plans: Pagination;
    filters: {
        search: string;
        status: string;
        per_page: number;
        start_from: string;
        start_to: string;
        agent: string;
    };
    viewer_type: string;
    directory_context: Record<string, string | number>;
}>();

const filters = reactive({ ...props.filters });
const page = usePage();
const filtersOpen = ref(false);
watch(
    () => props.filters,
    (value) => Object.assign(filters, value),
);

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Plans', href: plansIndex() },
        ],
    },
});

const activeFilterCount = computed(
    () =>
        [
            props.filters.start_from,
            props.filters.start_to,
            props.viewer_type === 'admin' ? props.filters.agent : '',
        ].filter((value) => value !== '').length +
        (props.filters.per_page !== 25 ? 1 : 0),
);

const sheetHasErrors = computed(() =>
    ['start_from', 'start_to', 'agent'].some((key) => key in page.props.errors),
);

const applyFilters = (): void => {
    const query: Record<string, string | number> = {
        per_page: filters.per_page,
    };
    if (filters.search.trim() !== '') query.search = filters.search.trim();
    if (filters.status !== '') query.status = filters.status;
    if (filters.start_from !== '') query.start_from = filters.start_from;
    if (filters.start_to !== '') query.start_to = filters.start_to;
    if (props.viewer_type === 'admin' && filters.agent.trim() !== '')
        query.agent = filters.agent.trim();
    router.get(
        plansIndex.url({ query }),
        {},
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const applyFromSheet = (): void => {
    filtersOpen.value = false;
    applyFilters();
};

const clearSheetFilters = (): void => {
    filters.start_from = '';
    filters.start_to = '';
    filters.agent = '';
    filters.per_page = 25;
    filtersOpen.value = false;
    applyFilters();
};

const planUrl = (id: string): string =>
    showPlan(id, { query: { directory: props.directory_context } }).url;

const statusVariant = (
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' => {
    if (status === 'active') return 'default';
    if (status === 'paused') return 'secondary';
    if (status === 'cancelled') return 'destructive';
    return 'outline';
};
</script>

<template>
    <Head title="Thrift plans" />

    <div class="space-y-6">
        <PageHeader
            title="Thrift plans"
            description="See each plan's daily amount, progress and status."
        />

        <form
            class="flex flex-row flex-wrap items-end gap-4"
            aria-label="Plan filters"
            @submit.prevent="applyFilters"
        >
            <div class="grid w-fit gap-2">
                <Label for="plan-search">Search</Label>
                <div class="relative">
                    <Search
                        class="text-muted-foreground absolute top-3.5 left-3 size-4"
                    />
                    <Input
                        id="plan-search"
                        v-model="filters.search"
                        class="w-64 pl-9"
                        placeholder="Plan, customer name or ID"
                    />
                </div>
            </div>
            <div class="grid w-fit gap-2">
                <Label for="plan-status">Status</Label>
                <Select
                    :model-value="filters.status || '__recent'"
                    @update:model-value="
                        (value) => {
                            filters.status =
                                value === '__recent' ? '' : String(value ?? '');
                            applyFilters();
                        }
                    "
                    ><SelectTrigger id="plan-status" class="h-11 w-fit min-w-40"
                        ><SelectValue /></SelectTrigger
                    ><SelectContent
                        ><SelectItem value="__recent">Recent plans</SelectItem
                        ><SelectItem value="all">All statuses</SelectItem
                        ><SelectItem value="active">Active</SelectItem
                        ><SelectItem value="paused">Paused</SelectItem
                        ><SelectItem value="completed">Completed</SelectItem
                        ><SelectItem value="closed">Closed</SelectItem
                        ><SelectItem value="cancelled"
                            >Cancelled</SelectItem
                        ></SelectContent
                    ></Select
                >
            </div>
            <Button type="submit" variant="outline">Search</Button>
            <Button type="button" variant="outline" @click="filtersOpen = true">
                <SlidersHorizontal class="size-4" />
                Filters
                <span
                    v-if="activeFilterCount > 0"
                    class="bg-primary text-primary-foreground inline-flex size-5 items-center justify-center rounded-full text-[11px]"
                    >{{ activeFilterCount }}</span
                >
            </Button>
        </form>

        <p
            v-if="sheetHasErrors && !filtersOpen"
            role="alert"
            class="text-destructive text-sm"
        >
            Some filters need fixing. Open Filters to check them.
        </p>

        <FormSheet
            v-model:open="filtersOpen"
            title="Filters"
            description="Narrow down the plans you see."
        >
            <div class="grid gap-5">
                <div class="grid gap-2">
                    <Label for="plan-start-from">Starts on or after</Label>
                    <DatePicker
                        id="plan-start-from"
                        aria-label="Starts on or after"
                        v-model="filters.start_from"
                        :error-message="page.props.errors.start_from"
                    />
                    <InputError
                        :message="page.props.errors.start_from"
                        role="alert"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="plan-start-to">Starts on or before</Label>
                    <DatePicker
                        id="plan-start-to"
                        aria-label="Starts on or before"
                        v-model="filters.start_to"
                        :error-message="page.props.errors.start_to"
                    />
                    <InputError
                        :message="page.props.errors.start_to"
                        role="alert"
                    />
                </div>
                <div v-if="viewer_type === 'admin'" class="grid gap-2">
                    <Label for="plan-agent">Agent ID</Label>
                    <Input
                        id="plan-agent"
                        v-model="filters.agent"
                        placeholder="AGT-…"
                    />
                    <InputError :message="page.props.errors.agent" />
                </div>
                <div class="grid gap-2">
                    <Label for="plan-page-size">Rows per page</Label>
                    <Select
                        :model-value="String(filters.per_page)"
                        @update:model-value="filters.per_page = Number($event)"
                        ><SelectTrigger id="plan-page-size" class="w-full"
                            ><SelectValue /></SelectTrigger
                        ><SelectContent
                            ><SelectItem value="25">25</SelectItem
                            ><SelectItem value="50">50</SelectItem
                            ><SelectItem value="100"
                                >100</SelectItem
                            ></SelectContent
                        ></Select
                    >
                </div>
            </div>
            <template #footer>
                <Button
                    type="button"
                    variant="outline"
                    @click="clearSheetFilters"
                    >Clear</Button
                >
                <Button type="button" @click="applyFromSheet"
                    >Show results</Button
                >
            </template>
        </FormSheet>

        <Card>
            <CardContent class="space-y-4">
                <p class="text-muted-foreground text-sm">
                    {{ plans.total }} plan{{ plans.total === 1 ? '' : 's' }}
                </p>
                <div v-if="plans.data.length" class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-left text-sm">
                        <thead class="text-muted-foreground border-b text-xs">
                            <tr>
                                <th scope="col" class="px-3 py-3 font-medium">
                                    Plan
                                </th>
                                <th scope="col" class="px-3 py-3 font-medium">
                                    Customer
                                </th>
                                <th
                                    v-if="viewer_type !== 'customer'"
                                    scope="col"
                                    class="px-3 py-3 font-medium"
                                >
                                    Agent
                                </th>
                                <th scope="col" class="px-3 py-3 font-medium">
                                    Daily amount
                                </th>
                                <th scope="col" class="px-3 py-3 font-medium">
                                    Paid so far
                                </th>
                                <th scope="col" class="px-3 py-3 font-medium">
                                    Status
                                </th>
                                <th scope="col" class="px-3 py-3">
                                    <span class="sr-only">Open</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="plan in plans.data" :key="plan.id">
                                <td class="px-3 py-4">
                                    <Link
                                        :href="planUrl(plan.id)"
                                        class="font-medium hover:underline"
                                        >{{ plan.name }}</Link
                                    >
                                    <div
                                        class="text-muted-foreground mt-0.5 text-xs"
                                    >
                                        {{ plan.id }}
                                    </div>
                                </td>
                                <td class="px-3 py-4">
                                    <Link
                                        :href="
                                            showCustomer(plan.customer.id).url
                                        "
                                        class="hover:underline"
                                        >{{ plan.customer.name }}</Link
                                    >
                                    <div
                                        class="text-muted-foreground mt-0.5 text-xs"
                                    >
                                        {{ plan.customer.id }}
                                    </div>
                                </td>
                                <td
                                    v-if="viewer_type !== 'customer'"
                                    class="px-3 py-4"
                                >
                                    <template v-if="plan.agent">{{
                                        plan.agent.name
                                    }}</template>
                                    <span v-else class="text-muted-foreground"
                                        >Not assigned</span
                                    >
                                </td>
                                <td class="px-3 py-4">
                                    {{ plan.contribution_amount ?? 'Not set' }}
                                    <div
                                        v-if="plan.start_date"
                                        class="text-muted-foreground mt-0.5 text-xs"
                                    >
                                        {{ plan.start_date }} to
                                        {{ plan.scheduled_end_date }}
                                    </div>
                                </td>
                                <td class="px-3 py-4">
                                    <template
                                        v-if="
                                            plan.financials.funded_principal !==
                                            null
                                        "
                                    >
                                        {{ plan.financials.funded_principal }}
                                        <div
                                            class="text-muted-foreground mt-0.5 text-xs"
                                        >
                                            {{
                                                plan.financials
                                                    .fully_funded_slots
                                            }}
                                            of
                                            {{ plan.financials.required_slots }}
                                            days paid
                                        </div>
                                    </template>
                                    <span v-else class="text-muted-foreground"
                                        >Not available</span
                                    >
                                </td>
                                <td class="px-3 py-4">
                                    <Badge
                                        :variant="statusVariant(plan.status)"
                                        >{{ plan.status_label }}</Badge
                                    >
                                </td>
                                <td class="px-3 py-4 text-right">
                                    <Button as-child variant="outline" size="sm"
                                        ><Link :href="planUrl(plan.id)"
                                            >View</Link
                                        ></Button
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <EmptyState
                    v-else
                    :icon="WalletCards"
                    title="No plans found"
                    description="Try a different search or clear your filters."
                />

                <div
                    v-if="plans.last_page > 1"
                    class="flex items-center justify-between border-t pt-4"
                >
                    <p class="text-muted-foreground text-sm">
                        Page {{ plans.current_page }} of {{ plans.last_page }}
                    </p>
                    <div class="flex gap-2">
                        <Button
                            v-if="plans.prev_page_url"
                            as-child
                            variant="outline"
                            size="sm"
                            ><Link :href="plans.prev_page_url"
                                >Previous</Link
                            ></Button
                        >
                        <Button v-else variant="outline" size="sm" disabled
                            >Previous</Button
                        >
                        <Button
                            v-if="plans.next_page_url"
                            as-child
                            variant="outline"
                            size="sm"
                            ><Link :href="plans.next_page_url"
                                >Next</Link
                            ></Button
                        >
                        <Button v-else variant="outline" size="sm" disabled
                            >Next</Button
                        >
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
