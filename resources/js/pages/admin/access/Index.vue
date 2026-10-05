<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import {
    ChevronLeft,
    ChevronRight,
    MailPlus,
    User as UserIcon,
    UsersRound,
} from '@lucide/vue';
import DirectoryPanel from '@/components/directory/DirectoryPanel.vue';
import DirectoryRow from '@/components/directory/DirectoryRow.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    index as adminAccessIndex,
    show as adminAccessShow,
} from '@/routes/admin/access';
import { create as invitationsCreate } from '@/routes/admin/access/invitations';
import { dashboard } from '@/routes';

type Admin = {
    id: number;
    name: string;
    email: string;
    account_state: string;
    permission_version: number;
    is_self: boolean;
    can_manage: boolean;
    summary: string[];
};
type PaginatedAdmins = {
    data: Admin[];
    current_page: number;
    last_page: number;
    total: number;
    next_page_url: string | null;
    prev_page_url: string | null;
};
type Filters = { search: string; account_state: string; per_page: number };

const props = defineProps<{
    admins: PaginatedAdmins;
    canManage: boolean;
    filters: Filters;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Admin access', href: adminAccessIndex() },
        ],
    },
});

const filtersOpen = ref(false);
const filterForm = reactive<Filters>({ ...props.filters });
watch(
    () => props.filters,
    (filters) => Object.assign(filterForm, filters),
);
const activeFilterCount = computed(() =>
    filterForm.account_state && filterForm.account_state !== 'all' ? 1 : 0,
);
const query = (): Record<string, string | number> => {
    const params: Record<string, string | number> = {};
    if (filterForm.search) params.search = filterForm.search;
    if (filterForm.account_state && filterForm.account_state !== 'all')
        params.account_state = filterForm.account_state;
    if (filterForm.per_page !== 15) params.per_page = filterForm.per_page;
    return params;
};
const applyFilters = (): void => {
    router.get(
        adminAccessIndex.url({ query: query() }),
        {},
        { preserveState: true, preserveScroll: true, replace: true },
    );
};
const resetFilters = (): void => {
    Object.assign(filterForm, { search: '', account_state: '', per_page: 15 });
    applyFilters();
};
const getBadgeVariant = (
    state: string,
): 'default' | 'secondary' | 'destructive' | 'outline' =>
    state === 'active'
        ? 'default'
        : ['suspended', 'deactivated'].includes(state)
          ? 'destructive'
          : 'secondary';
</script>

<template>
    <Head title="Administrator Access" />
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-[25px] font-medium tracking-tight">
                    Administrator Access
                </h1>
                <p class="text-muted-foreground mt-1.5 text-sm">
                    Directory of registered system administrators and their
                    active responsibilities.
                </p>
            </div>
            <Link v-if="canManage" :href="invitationsCreate()">
                <Button>
                    <MailPlus class="mr-2 size-4" />
                    Invite Administrator
                </Button>
            </Link>
        </div>
        <DirectoryPanel
            title="Administrators"
            :description="`${admins.total} administrator${admins.total === 1 ? '' : 's'} matching the current directory view.`"
            :search-value="filterForm.search"
            search-placeholder="Search administrators"
            :filters-open="filtersOpen"
            :active-filter-count="activeFilterCount"
            @update:search-value="filterForm.search = $event"
            @submit-search="applyFilters"
            @toggle-filters="filtersOpen = !filtersOpen"
            @reset-filters="resetFilters"
        >
            <template #filters
                ><div class="w-fit space-y-1.5">
                    <Label for="admin-account-state" class="text-xs"
                        >Account state</Label
                    ><Select
                        v-model="filterForm.account_state"
                        @update:model-value="applyFilters"
                        ><SelectTrigger id="admin-account-state"
                            ><SelectValue
                                placeholder="All account states" /></SelectTrigger
                        ><SelectContent
                            ><SelectItem value="all">All states</SelectItem
                            ><SelectItem value="active">Active</SelectItem
                            ><SelectItem value="invited">Invited</SelectItem
                            ><SelectItem value="mfa_setup">MFA setup</SelectItem
                            ><SelectItem value="suspended">Suspended</SelectItem
                            ><SelectItem value="deactivated"
                                >Deactivated</SelectItem
                            ></SelectContent
                        ></Select
                    >
                </div></template
            >
            <template #filter-summary
                ><p class="text-muted-foreground text-xs">
                    {{ admins.total }} administrator{{
                        admins.total === 1 ? '' : 's'
                    }}
                    match the current filters.
                </p></template
            >
            <div v-if="admins.data.length === 0" class="py-14 text-center">
                <div
                    class="bg-muted text-muted-foreground mx-auto flex size-12 items-center justify-center rounded-2xl"
                >
                    <UsersRound class="size-5" />
                </div>
                <h3 class="mt-4 text-sm font-semibold">
                    No administrators found
                </h3>
                <p class="text-muted-foreground mt-1 text-sm">
                    Adjust the search or filters to find an administrator.
                </p>
            </div>
            <div v-else class="space-y-3">
                <DirectoryRow v-for="admin in admins.data" :key="admin.id"
                    ><div
                        class="hidden items-center gap-5 lg:grid lg:grid-cols-[minmax(15rem,1.5fr)_minmax(8rem,.7fr)_minmax(6rem,.55fr)_minmax(16rem,1.3fr)_auto]"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <span
                                class="bg-accent text-accent-foreground flex size-11 shrink-0 items-center justify-center rounded-full"
                                ><UserIcon class="size-4"
                            /></span>
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <p class="truncate text-sm font-semibold">
                                        {{ admin.name }}
                                    </p>
                                    <Badge
                                        v-if="admin.is_self"
                                        variant="outline"
                                        class="px-1.5 py-0 text-[10px]"
                                        >You</Badge
                                    >
                                </div>
                                <p
                                    class="text-muted-foreground truncate text-xs"
                                >
                                    {{ admin.email }}
                                </p>
                            </div>
                        </div>
                        <div>
                            <p
                                class="text-muted-foreground text-[11px] font-medium uppercase"
                            >
                                Status
                            </p>
                            <Badge
                                :variant="getBadgeVariant(admin.account_state)"
                                class="mt-1 capitalize"
                                >{{
                                    admin.account_state.replace('_', ' ')
                                }}</Badge
                            >
                        </div>
                        <div>
                            <p
                                class="text-muted-foreground text-[11px] font-medium uppercase"
                            >
                                Version
                            </p>
                            <p class="mt-1 text-sm">
                                v{{ admin.permission_version }}
                            </p>
                        </div>
                        <div>
                            <p
                                class="text-muted-foreground text-[11px] font-medium uppercase"
                            >
                                Responsibilities
                            </p>
                            <p class="mt-1 truncate text-sm">
                                {{ admin.summary.join(', ') || 'None' }}
                            </p>
                        </div>
                        <Link :href="adminAccessShow(admin.id).url"
                            ><Button variant="outline" size="sm"
                                >{{ admin.can_manage ? 'Manage' : 'View' }}
                                <ChevronRight class="size-3.5" /></Button
                        ></Link>
                    </div>
                    <div class="lg:hidden">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex min-w-0 items-center gap-3">
                                <span
                                    class="bg-accent text-accent-foreground flex size-11 shrink-0 items-center justify-center rounded-full"
                                    ><UserIcon class="size-4"
                                /></span>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold">
                                        {{ admin.name }}
                                    </p>
                                    <p
                                        class="text-muted-foreground truncate text-xs"
                                    >
                                        {{ admin.email }}
                                    </p>
                                </div>
                            </div>
                            <Link :href="adminAccessShow(admin.id).url"
                                ><Button variant="outline" size="sm">{{
                                    admin.can_manage ? 'Manage' : 'View'
                                }}</Button></Link
                            >
                        </div>
                        <div class="mt-4 grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <p
                                    class="text-muted-foreground text-[10px] font-medium uppercase"
                                >
                                    Status
                                </p>
                                <Badge
                                    :variant="
                                        getBadgeVariant(admin.account_state)
                                    "
                                    class="mt-1 capitalize"
                                    >{{
                                        admin.account_state.replace('_', ' ')
                                    }}</Badge
                                >
                            </div>
                            <div>
                                <p
                                    class="text-muted-foreground text-[10px] font-medium uppercase"
                                >
                                    Version
                                </p>
                                <p class="mt-1">
                                    v{{ admin.permission_version }}
                                </p>
                            </div>
                            <div class="col-span-2">
                                <p
                                    class="text-muted-foreground text-[10px] font-medium uppercase"
                                >
                                    Responsibilities
                                </p>
                                <p class="mt-1">
                                    {{ admin.summary.join(', ') || 'None' }}
                                </p>
                            </div>
                        </div>
                    </div></DirectoryRow
                >
            </div>
            <template #footer
                ><div
                    class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div
                        class="text-muted-foreground flex items-center gap-2 text-sm"
                    >
                        Display
                        <Select
                            v-model="filterForm.per_page"
                            @update:model-value="applyFilters"
                            ><SelectTrigger class="h-9 w-20"
                                ><SelectValue /></SelectTrigger
                            ><SelectContent
                                ><SelectItem :value="15">15</SelectItem
                                ><SelectItem :value="25">25</SelectItem
                                ><SelectItem :value="50"
                                    >50</SelectItem
                                ></SelectContent
                            ></Select
                        >
                        per page
                    </div>
                    <div
                        class="flex items-center justify-between gap-3 sm:justify-end"
                    >
                        <span class="text-muted-foreground text-xs"
                            >Page {{ admins.current_page }} of
                            {{ admins.last_page }}</span
                        >
                        <div class="flex gap-2">
                            <Link
                                v-if="admins.prev_page_url"
                                :href="admins.prev_page_url"
                                preserve-state
                                preserve-scroll
                                ><Button variant="outline" size="sm"
                                    ><ChevronLeft /> Prev</Button
                                ></Link
                            ><Button v-else variant="outline" size="sm" disabled
                                ><ChevronLeft /> Prev</Button
                            ><Link
                                v-if="admins.next_page_url"
                                :href="admins.next_page_url"
                                preserve-state
                                preserve-scroll
                                ><Button size="sm"
                                    >Next <ChevronRight /></Button></Link
                            ><Button v-else size="sm" disabled
                                >Next <ChevronRight
                            /></Button>
                        </div>
                    </div></div
            ></template>
        </DirectoryPanel>
    </div>
</template>
