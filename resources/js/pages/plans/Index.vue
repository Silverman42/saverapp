<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import { Search, WalletCards } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { DatePicker } from '@/components/ui/date-picker';
import InputError from '@/components/InputError.vue';
import PlanFundingSummary from '@/components/PlanFundingSummary.vue';
import PlanFeeSummary from '@/components/PlanFeeSummary.vue';
import PlanSavingsDirectorySummary from '@/components/PlanSavingsDirectorySummary.vue';
import PlanPostedActivityDirectorySummary from '@/components/PlanPostedActivityDirectorySummary.vue';
import type { PlanPostedActivity } from '@/types/plan-posted-activity';
import type { PlanSavings } from '@/types/plan-savings';
import type { PlanFeeHistory } from '@/types/plan-fee-history';
import type { PlanFundingSummary as FundingSummary } from '@/types/plan-funding';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">Thrift plans</h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                View agreed daily schedules and plan history. Open the thrift
                card to review collection activity.
            </p>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Find a plan</CardTitle>
                <CardDescription
                    >Search by plan, Customer name, or Customer
                    ID.</CardDescription
                >
            </CardHeader>
            <CardContent>
                <div class="flex flex-row flex-wrap items-end gap-4">
                    <div class="grid w-fit gap-2">
                        <Label for="plan-search">Search</Label>
                        <div class="relative">
                            <Search
                                class="text-muted-foreground absolute top-3 left-3 size-4"
                            />
                            <Input
                                id="plan-search"
                                v-model="filters.search"
                                class="w-64 pl-9"
                                placeholder="Plan or Customer"
                                @keyup.enter="applyFilters"
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
                                        value === '__recent'
                                            ? ''
                                            : String(value ?? '');
                                    applyFilters();
                                }
                            "
                            ><SelectTrigger
                                id="plan-status"
                                class="h-11 w-fit min-w-40"
                                ><SelectValue /></SelectTrigger
                            ><SelectContent
                                ><SelectItem value="__recent"
                                    >Recent plans</SelectItem
                                ><SelectItem value="all"
                                    >All statuses</SelectItem
                                ><SelectItem value="active">Active</SelectItem
                                ><SelectItem value="paused">Paused</SelectItem
                                ><SelectItem value="completed"
                                    >Completed</SelectItem
                                ><SelectItem value="closed">Closed</SelectItem
                                ><SelectItem value="cancelled"
                                    >Cancelled</SelectItem
                                ></SelectContent
                            ></Select
                        >
                    </div>
                    <div class="grid w-fit gap-2">
                        <Label for="plan-page-size">Rows</Label>
                        <Select
                            :model-value="String(filters.per_page)"
                            @update:model-value="
                                (value) => {
                                    filters.per_page = Number(value);
                                    applyFilters();
                                }
                            "
                            ><SelectTrigger
                                id="plan-page-size"
                                class="h-11 w-fit min-w-24"
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
                    <div class="grid w-fit gap-2">
                        <Label for="plan-start-from">Plan starts from</Label>
                        <DatePicker
                            id="plan-start-from"
                            aria-label="Plan starts from"
                            v-model="filters.start_from"
                            :error-message="page.props.errors.start_from"
                        />
                        <InputError
                            :message="page.props.errors.start_from"
                            role="alert"
                        />
                    </div>
                    <div class="grid w-fit gap-2">
                        <Label for="plan-start-to">Plan starts through</Label>
                        <DatePicker
                            id="plan-start-to"
                            aria-label="Plan starts through"
                            v-model="filters.start_to"
                            :error-message="page.props.errors.start_to"
                        />
                        <InputError
                            :message="page.props.errors.start_to"
                            role="alert"
                        />
                    </div>
                    <div
                        v-if="viewer_type === 'admin'"
                        class="grid w-fit gap-2"
                    >
                        <Label for="plan-agent">Current Agent ID</Label>
                        <Input
                            id="plan-agent"
                            v-model="filters.agent"
                            placeholder="Agent ID"
                            @keyup.enter="applyFilters"
                        />
                        <InputError :message="page.props.errors.agent" />
                    </div>
                    <Button variant="outline" @click="applyFilters"
                        >Apply filters</Button
                    >
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardHeader class="flex-row items-start justify-between">
                <div>
                    <CardTitle>Plans</CardTitle>
                    <CardDescription
                        >{{ plans.total }} plan{{
                            plans.total === 1 ? '' : 's'
                        }}</CardDescription
                    >
                </div>
                <WalletCards class="text-muted-foreground size-5" />
            </CardHeader>
            <CardContent>
                <div v-if="plans.data.length" class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-left text-sm">
                        <thead class="text-muted-foreground border-b text-xs">
                            <tr>
                                <th class="px-3 py-3 font-medium">Plan</th>
                                <th class="px-3 py-3 font-medium">Customer</th>
                                <th
                                    v-if="viewer_type !== 'customer'"
                                    class="px-3 py-3 font-medium"
                                >
                                    Current Agent
                                </th>
                                <th class="px-3 py-3 font-medium">Fee terms</th>
                                <th class="px-3 py-3 font-medium">Status</th>
                                <th class="px-3 py-3 font-medium">
                                    Agreed schedule
                                </th>
                                <th class="px-3 py-3 font-medium">
                                    Financial data
                                </th>
                                <th class="px-3 py-3">
                                    <span class="sr-only">Open</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="plan in plans.data"
                                :key="plan.id"
                                class="align-top"
                            >
                                <td class="px-3 py-4">
                                    <Link
                                        :href="
                                            showPlan(plan.id, {
                                                query: {
                                                    directory:
                                                        props.directory_context,
                                                },
                                            }).url
                                        "
                                        class="font-medium hover:underline"
                                        >{{ plan.name }}</Link
                                    >
                                    <div
                                        class="text-muted-foreground mt-1 text-xs"
                                    >
                                        {{ plan.id }} · revision
                                        {{ plan.terms_revision }}
                                        <div>Updated {{ plan.updated_at }}</div>
                                    </div>
                                </td>
                                <td class="px-3 py-4">
                                    <Link
                                        :href="
                                            showCustomer(plan.customer.id).url
                                        "
                                        class="font-medium hover:underline"
                                        >{{ plan.customer.name }}</Link
                                    >
                                    <div
                                        class="text-muted-foreground mt-1 text-xs"
                                    >
                                        {{ plan.customer.id }}
                                        <div>
                                            Customer {{ plan.customer.status }}
                                        </div>
                                    </div>
                                </td>
                                <td
                                    v-if="viewer_type !== 'customer'"
                                    class="px-3 py-4"
                                >
                                    <template v-if="plan.agent"
                                        >{{ plan.agent.name }}
                                        <div
                                            class="text-muted-foreground text-xs"
                                        >
                                            {{ plan.agent.id }}
                                        </div></template
                                    >
                                    <span v-else>Unassigned</span>
                                </td>
                                <td class="px-3 py-4">
                                    <template v-if="plan.fee"
                                        >Captured agreement: {{ plan.fee.name }}
                                        <div
                                            class="text-muted-foreground text-xs"
                                        >
                                            {{ plan.fee.amount }}
                                        </div></template
                                    >
                                    <span v-else>Unavailable</span>
                                    <PlanFeeSummary
                                        :summary="plan.fee_actuals"
                                        class="mt-3"
                                    />
                                </td>
                                <td class="px-3 py-4">
                                    <Badge
                                        :variant="statusVariant(plan.status)"
                                        >{{ plan.status_label }}</Badge
                                    >
                                </td>
                                <td class="px-3 py-4">
                                    <div>
                                        {{
                                            plan.contribution_amount ??
                                            'Terms unavailable'
                                        }}
                                        daily
                                    </div>
                                    <div
                                        class="text-muted-foreground mt-1 text-xs"
                                    >
                                        {{ plan.start_date }} –
                                        {{ plan.scheduled_end_date }}
                                        <div>
                                            {{ plan.timezone }} ·
                                            {{ plan.currency }}
                                        </div>
                                    </div>
                                </td>
                                <td
                                    class="text-muted-foreground px-3 py-4 text-xs"
                                >
                                    <PlanFundingSummary
                                        :summary="plan.financials"
                                        compact
                                    />
                                    <PlanSavingsDirectorySummary
                                        :summary="plan.savings_summary"
                                        class="mt-3"
                                    />
                                    <PlanPostedActivityDirectorySummary
                                        :summary="plan.posted_activity"
                                        class="mt-3"
                                    />
                                </td>
                                <td class="px-3 py-4 text-right">
                                    <Button as-child variant="outline" size="sm"
                                        ><Link
                                            :href="
                                                showPlan(plan.id, {
                                                    query: {
                                                        directory:
                                                            props.directory_context,
                                                    },
                                                }).url
                                            "
                                            >View</Link
                                        ></Button
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div
                    v-else
                    class="rounded-xl border border-dashed p-8 text-center"
                >
                    <p class="font-medium">No plans found</p>
                    <p class="text-muted-foreground mt-1 text-sm">
                        Change the search or status filter to see other plans.
                    </p>
                </div>

                <div
                    v-if="plans.last_page > 1"
                    class="mt-5 flex items-center justify-between border-t pt-4"
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
