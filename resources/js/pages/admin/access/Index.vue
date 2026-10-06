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
import EmptyState from '@/components/EmptyState.vue';
import FormSheet from '@/components/FormSheet.vue';
import InviteAdminForm from '@/components/InviteAdminForm.vue';
import type { GrantablePermission } from '@/components/InviteAdminForm.vue';
import PageHeader from '@/components/PageHeader.vue';
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
type InviteFormData = {
    attempt_reference: string;
    permissions: GrantablePermission[];
};

const props = defineProps<{
    admins: PaginatedAdmins;
    canManage: boolean;
    isFresh?: boolean;
    inviteForm?: InviteFormData | null;
    filters: Filters;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Admin team', href: adminAccessIndex() },
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
const stateLabels: Record<string, string> = {
    active: 'Active',
    invited: 'Invited',
    mfa_setup: 'Setting up',
    suspended: 'Suspended',
    deactivated: 'Deactivated',
};
const stateLabel = (state: string): string =>
    stateLabels[state] ?? state.replaceAll('_', ' ');

const inviteOpen = ref(false);
const inviteLoading = ref(false);
const openInvite = (): void => {
    inviteOpen.value = true;
    if (props.inviteForm) {
        return;
    }
    router.reload({
        only: ['inviteForm'],
        onStart: () => {
            inviteLoading.value = true;
        },
        onFinish: () => {
            inviteLoading.value = false;
        },
    });
};
</script>

<template>
    <Head title="Admin team" />
    <div class="space-y-6">
        <PageHeader
            title="Admin team"
            description="See who has admin access and what they can do."
        >
            <template v-if="canManage" #actions>
                <Button v-if="isFresh" @click="openInvite">
                    <MailPlus class="size-4" />
                    Invite admin
                </Button>
                <Button v-else as-child>
                    <Link :href="invitationsCreate()">
                        <MailPlus class="size-4" />
                        Invite admin
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <DirectoryPanel
            title="Admins"
            :description="`${admins.total} admin${admins.total === 1 ? '' : 's'}`"
            :search-value="filterForm.search"
            search-placeholder="Search by name or email"
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
                        >Status</Label
                    ><Select
                        v-model="filterForm.account_state"
                        @update:model-value="applyFilters"
                        ><SelectTrigger id="admin-account-state"
                            ><SelectValue
                                placeholder="All statuses" /></SelectTrigger
                        ><SelectContent
                            ><SelectItem value="all">All statuses</SelectItem
                            ><SelectItem value="active">Active</SelectItem
                            ><SelectItem value="invited">Invited</SelectItem
                            ><SelectItem value="mfa_setup"
                                >Setting up</SelectItem
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
                    {{ admins.total }} found
                </p></template
            >
            <EmptyState
                v-if="admins.data.length === 0"
                :icon="UsersRound"
                title="No admins found"
                description="Try a different search or clear the filters."
            />
            <div v-else class="space-y-3">
                <DirectoryRow v-for="admin in admins.data" :key="admin.id">
                    <div class="flex flex-wrap items-center gap-4">
                        <div class="flex min-w-0 flex-1 items-center gap-3">
                            <span
                                class="bg-accent text-accent-foreground flex size-10 shrink-0 items-center justify-center rounded-full"
                                ><UserIcon class="size-4"
                            /></span>
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <p class="truncate text-sm font-medium">
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
                                <p
                                    class="text-muted-foreground mt-0.5 truncate text-xs"
                                >
                                    {{
                                        admin.summary.join(', ') ||
                                        'Basic access'
                                    }}
                                </p>
                            </div>
                        </div>
                        <Badge :variant="getBadgeVariant(admin.account_state)">
                            {{ stateLabel(admin.account_state) }}
                        </Badge>
                        <Button variant="outline" size="sm" as-child>
                            <Link :href="adminAccessShow(admin.id).url"
                                >{{ admin.can_manage ? 'Manage' : 'View' }}
                                <ChevronRight class="size-3.5"
                            /></Link>
                        </Button>
                    </div>
                </DirectoryRow>
            </div>
            <template #footer
                ><div
                    class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div
                        class="text-muted-foreground flex items-center gap-2 text-sm"
                    >
                        Show
                        <Select
                            v-model="filterForm.per_page"
                            @update:model-value="applyFilters"
                            ><SelectTrigger
                                class="h-9 w-20"
                                aria-label="Rows per page"
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
                                ><Button variant="outline" size="sm"
                                    >Next <ChevronRight /></Button></Link
                            ><Button v-else variant="outline" size="sm" disabled
                                >Next <ChevronRight
                            /></Button>
                        </div>
                    </div></div
            ></template>
        </DirectoryPanel>

        <FormSheet
            v-if="canManage && isFresh"
            v-model:open="inviteOpen"
            title="Invite admin"
            description="They set their own password and sign-in code."
        >
            <div
                v-if="inviteLoading || !inviteForm"
                class="space-y-3"
                aria-label="Loading invite form"
            >
                <div
                    v-for="n in 4"
                    :key="n"
                    class="bg-muted h-10 animate-pulse rounded-lg motion-reduce:animate-none"
                />
            </div>
            <InviteAdminForm
                v-else
                :key="inviteForm.attempt_reference"
                :attempt-reference="inviteForm.attempt_reference"
                :permissions="inviteForm.permissions"
            />
        </FormSheet>
    </div>
</template>
