<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { dashboard } from '@/routes';
import {
    index as adminAccessIndex,
    show as adminAccessShow,
} from '@/routes/admin/access';
import { useVuelidate } from '@vuelidate/core';
import { maxLength, minLength, required } from '@vuelidate/validators';
import {
    AlertCircle,
    AlertTriangle,
    History,
    LifeBuoy,
    MoreHorizontal,
    Search,
    ShieldCheck,
} from '@lucide/vue';
import AdminInvitationPanel from '@/components/AdminInvitationPanel.vue';
import type { AdminInvitationSummary } from '@/components/AdminInvitationPanel.vue';
import AdminStatusPanel from '@/components/AdminStatusPanel.vue';
import type { AdminStatusSummary } from '@/components/AdminStatusPanel.vue';
import EmptyState from '@/components/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import MoreDetails from '@/components/MoreDetails.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { create as createRecovery } from '@/routes/admin/staff-recoveries';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export type CatalogueItem = {
    name: string;
    display_name: string;
    description: string;
    is_highest_risk: boolean;
};

export type HistoryItem = {
    id: number;
    batch_id: string;
    permission_code: string;
    action: string;
    source: string;
    actor_name: string;
    reason: string | null;
    permission_version: number;
    created_at: string | null;
};

export type PaginatedHistory = {
    data: HistoryItem[];
    current_page: number;
    last_page: number;
    total: number;
    next_page_url: string | null;
    prev_page_url: string | null;
};

export type AdminDetail = {
    id: number;
    name: string;
    email: string;
    account_state: string;
    permission_version: number;
    is_self: boolean;
    direct_permissions: string[];
    effective_permissions: string[];
    restrictions: Array<{
        id: number;
        restriction_type: string;
        permission_code: string;
        source: string;
        started_at: string | null;
        expires_at: string | null;
    }>;
    created_at: string | null;
};

type HistoryFilters = {
    search: string;
    action: string;
    per_page: number;
};

const props = defineProps<{
    admin: AdminDetail;
    catalogue: CatalogueItem[];
    history: PaginatedHistory;
    canManage: boolean;
    invitation: AdminInvitationSummary | null;
    status: AdminStatusSummary;
    canRequestRecovery: boolean;
    isSelf: boolean;
    history_filters: HistoryFilters;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
            {
                title: 'Admin team',
                href: adminAccessIndex(),
            },
            {
                title: 'Admin details',
                href: '#',
            },
        ],
    },
});

// Editor state
const selectedPermissions = ref<string[]>([...props.admin.direct_permissions]);
const reason = ref<string>('');
const isConfirmed = ref<boolean>(false);
const isConfirmDialogOpen = ref<boolean>(false);
const concurrencyError = ref<string | null>(null);
const saveErrors = ref<string[]>([]);
const historyFilterForm = reactive<HistoryFilters>({
    ...props.history_filters,
});

// Keep selected in sync if props reload
watch(
    () => props.admin.direct_permissions,
    (newDirects) => {
        selectedPermissions.value = [...newDirects];
        concurrencyError.value = null;
    },
);

watch(
    () => props.history_filters,
    (filters) => Object.assign(historyFilterForm, filters),
);

const applyHistoryFilters = (): void => {
    const query: Record<string, string | number> = {};
    if (historyFilterForm.search)
        query.history_search = historyFilterForm.search;
    if (historyFilterForm.action && historyFilterForm.action !== 'all')
        query.history_action = historyFilterForm.action;
    if (historyFilterForm.per_page !== 10)
        query.history_per_page = historyFilterForm.per_page;
    router.get(
        adminAccessShow(props.admin.id, { query }).url,
        {},
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

// Form validations
const rules = computed(() => ({
    reason: {
        required,
        minLength: minLength(1),
        maxLength: maxLength(500),
    },
    isConfirmed: {
        mustBeTrue: (val: boolean) => val === true,
    },
}));

const v$ = useVuelidate(rules, { reason, isConfirmed });

// Diff computations
const grants = computed(() => {
    return selectedPermissions.value.filter(
        (p) => !props.admin.direct_permissions.includes(p),
    );
});

const revocations = computed(() => {
    return props.admin.direct_permissions.filter(
        (p) => !selectedPermissions.value.includes(p),
    );
});

const hasChanges = computed(() => {
    return grants.value.length > 0 || revocations.value.length > 0;
});

const changeCount = computed(
    () => grants.value.length + revocations.value.length,
);

const touchesAdminManagement = computed(
    () =>
        grants.value.includes('admins.manage') ||
        revocations.value.includes('admins.manage'),
);

const setPermission = (
    code: string,
    checked: boolean | 'indeterminate',
): void => {
    const isSelected = selectedPermissions.value.includes(code);
    if (checked === true && !isSelected) {
        selectedPermissions.value = [...selectedPermissions.value, code];
    } else if (checked !== true && isSelected) {
        selectedPermissions.value = selectedPermissions.value.filter(
            (p) => p !== code,
        );
    }
};

const resetSelection = () => {
    selectedPermissions.value = [...props.admin.direct_permissions];
    reason.value = '';
    isConfirmed.value = false;
    v$.value.$reset();
    concurrencyError.value = null;
    saveErrors.value = [];
};

const openReviewDialog = () => {
    if (!hasChanges.value) {
        return;
    }
    saveErrors.value = [];
    isConfirmDialogOpen.value = true;
};

const isSubmitting = ref(false);

const confirmAndApply = async () => {
    const isValid = await v$.value.$validate();
    if (!isValid || !hasChanges.value) {
        return;
    }
    applyChanges();
};

const applyChanges = () => {
    isSubmitting.value = true;
    concurrencyError.value = null;
    saveErrors.value = [];

    router.put(
        `/admin/access/${props.admin.id}/permissions`,
        {
            permissions: selectedPermissions.value,
            reason: reason.value,
            expected_permission_version: props.admin.permission_version,
            confirmed: true,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                isConfirmDialogOpen.value = false;
                isConfirmed.value = false;
                reason.value = '';
                v$.value.$reset();
            },
            onError: (errors) => {
                if (errors.expected_permission_version) {
                    isConfirmDialogOpen.value = false;
                    concurrencyError.value =
                        errors.expected_permission_version +
                        ' We loaded the latest permissions. Please check them again.';
                    selectedPermissions.value = [
                        ...props.admin.direct_permissions,
                    ];
                    return;
                }
                saveErrors.value = Object.values(errors);
            },
            onFinish: () => {
                isSubmitting.value = false;
            },
        },
    );
};

const getPermissionDetails = (name: string) => {
    return props.catalogue.find((c) => c.name === name);
};

const permissionLabel = (name: string): string =>
    getPermissionDetails(name)?.display_name || name;

const formatDate = (value: string | null): string =>
    value ? new Date(value).toLocaleString() : '-';

const stateLabels: Record<string, string> = {
    active: 'Active',
    invited: 'Invited',
    mfa_setup: 'Setting up',
    mfa_setup_required: 'Setting up',
    temporarily_locked: 'Locked',
    suspended: 'Suspended',
    deactivated: 'Deactivated',
};
</script>

<template>
    <Head :title="admin.name" />

    <div class="space-y-6">
        <PageHeader :title="admin.name" :description="admin.email">
            <template #actions>
                <Badge
                    :variant="
                        admin.account_state === 'active'
                            ? 'default'
                            : 'secondary'
                    "
                >
                    {{
                        stateLabels[admin.account_state] ?? admin.account_state
                    }}
                </Badge>
                <DropdownMenu :modal="false" v-if="canRequestRecovery">
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline" size="sm">
                            <MoreHorizontal class="size-4" />
                            More
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem as-child>
                            <Link :href="createRecovery(admin.id).url">
                                <LifeBuoy class="size-4" />
                                Help them get back in
                            </Link>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </template>
        </PageHeader>

        <AdminInvitationPanel
            v-if="invitation"
            :admin-id="admin.id"
            :current-email="admin.email"
            :invitation="invitation"
        />

        <AdminStatusPanel
            v-if="status.actions.length > 0"
            :key="status.version"
            :admin-id="admin.id"
            :admin-name="admin.name"
            :status="status"
        />

        <div
            v-if="isSelf"
            class="bg-muted flex items-start gap-3 rounded-xl p-4 text-sm"
        >
            <ShieldCheck class="mt-0.5 size-4 shrink-0" />
            <p>
                This is your account. Another admin has to change your
                permissions.
            </p>
        </div>

        <div
            v-if="admin.restrictions.length > 0"
            class="rounded-xl border border-amber-500/20 bg-amber-500/10 p-4 text-sm text-amber-700 dark:text-amber-300"
        >
            <div class="flex items-start gap-3">
                <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                <div>
                    <p class="font-medium">Some permissions are on hold</p>
                    <ul class="mt-1 space-y-1 text-xs">
                        <li v-for="res in admin.restrictions" :key="res.id">
                            {{ permissionLabel(res.permission_code) }}:
                            {{ res.restriction_type.replaceAll('_', ' ') }},
                            {{
                                res.expires_at
                                    ? `until ${formatDate(res.expires_at)}`
                                    : 'no end date'
                            }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div
            v-if="concurrencyError"
            role="alert"
            class="border-destructive/20 bg-destructive/10 text-destructive flex items-start gap-3 rounded-xl border p-4 text-sm"
        >
            <AlertCircle class="mt-0.5 size-4 shrink-0" />
            <div>
                <p class="font-medium">
                    Someone else changed these permissions
                </p>
                <p class="mt-1 text-xs">{{ concurrencyError }}</p>
            </div>
        </div>

        <!-- Permission editor (for admins who can manage other admins) -->
        <Card v-if="canManage">
            <CardHeader
                class="flex flex-row flex-wrap items-start justify-between gap-3"
            >
                <div class="space-y-1">
                    <CardTitle>Permissions</CardTitle>
                    <CardDescription>
                        Tick to give a permission. Untick to take it away.
                    </CardDescription>
                </div>
            </CardHeader>
            <CardContent class="space-y-4">
                <div class="divide-border divide-y rounded-xl border">
                    <div
                        v-for="item in catalogue"
                        :key="item.name"
                        class="flex items-start gap-3 p-3"
                        :class="
                            selectedPermissions.includes(item.name)
                                ? 'bg-primary/5'
                                : ''
                        "
                    >
                        <Checkbox
                            :id="`perm-${item.name}`"
                            class="mt-0.5"
                            :model-value="
                                selectedPermissions.includes(item.name)
                            "
                            @update:model-value="
                                setPermission(item.name, $event)
                            "
                        />
                        <Label
                            :for="`perm-${item.name}`"
                            class="min-w-0 flex-1 cursor-pointer flex-col items-start gap-0.5 font-normal"
                        >
                            <span
                                class="flex flex-wrap items-center gap-2 text-sm font-medium"
                            >
                                {{ item.display_name }}
                                <Badge
                                    v-if="item.is_highest_risk"
                                    variant="destructive"
                                    class="px-1.5 py-0 text-[10px]"
                                    >High risk</Badge
                                >
                            </span>
                            <span
                                class="text-muted-foreground line-clamp-2 text-xs"
                                :title="item.description"
                                >{{ item.description }}</span
                            >
                        </Label>
                        <span
                            v-if="grants.includes(item.name)"
                            class="shrink-0 text-xs font-medium text-emerald-600 dark:text-emerald-400"
                            >Adding</span
                        >
                        <span
                            v-else-if="revocations.includes(item.name)"
                            class="shrink-0 text-xs font-medium text-rose-600 dark:text-rose-400"
                            >Removing</span
                        >
                    </div>
                </div>

                <div
                    v-if="hasChanges"
                    class="bg-card sticky bottom-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border p-3 shadow-sm"
                    role="status"
                >
                    <p class="text-sm">
                        {{ changeCount }} unsaved change{{
                            changeCount === 1 ? '' : 's'
                        }}
                    </p>
                    <div class="flex gap-2">
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="resetSelection"
                            >Undo</Button
                        >
                        <Button
                            size="sm"
                            :disabled="isSubmitting"
                            @click="openReviewDialog"
                            >Review changes</Button
                        >
                    </div>
                </div>
            </CardContent>
        </Card>

        <!-- Read-only view (own account, or no permission to manage) -->
        <Card v-else>
            <CardHeader>
                <CardTitle>Permissions</CardTitle>
            </CardHeader>
            <CardContent>
                <div
                    v-if="admin.direct_permissions.length > 0"
                    class="divide-border divide-y rounded-xl border"
                >
                    <div
                        v-for="perm in admin.direct_permissions"
                        :key="perm"
                        class="p-3"
                    >
                        <p class="text-sm font-medium">
                            {{ permissionLabel(perm) }}
                        </p>
                        <p
                            class="text-muted-foreground mt-0.5 line-clamp-2 text-xs"
                            :title="getPermissionDetails(perm)?.description"
                        >
                            {{ getPermissionDetails(perm)?.description }}
                        </p>
                    </div>
                </div>
                <p v-else class="text-muted-foreground text-sm">
                    Basic admin access only. No extra permissions.
                </p>
            </CardContent>
        </Card>

        <!-- History -->
        <Card>
            <CardHeader class="space-y-4">
                <div>
                    <CardTitle>Change history</CardTitle>
                    <CardDescription
                        >{{ history.total }} change{{
                            history.total === 1 ? '' : 's'
                        }}</CardDescription
                    >
                </div>
                <form
                    class="flex flex-row flex-wrap gap-3"
                    aria-label="History filters"
                    @submit.prevent="applyHistoryFilters"
                >
                    <div class="relative w-full sm:w-72">
                        <Search
                            class="text-muted-foreground pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2"
                        />
                        <Input
                            v-model="historyFilterForm.search"
                            class="pl-10"
                            placeholder="Search permission or person"
                            aria-label="Search history"
                        />
                    </div>
                    <Select
                        v-model="historyFilterForm.action"
                        @update:model-value="applyHistoryFilters"
                    >
                        <SelectTrigger
                            id="history-action"
                            class="w-fit"
                            aria-label="Type of change"
                            ><SelectValue placeholder="All changes"
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All changes</SelectItem>
                            <SelectItem value="grant">Given</SelectItem>
                            <SelectItem value="revoke">Removed</SelectItem>
                        </SelectContent>
                    </Select>
                </form>
            </CardHeader>
            <CardContent class="space-y-4">
                <EmptyState
                    v-if="history.data.length === 0"
                    :icon="History"
                    title="No changes found"
                    description="Changes to this admin's permissions will show here."
                />
                <ul v-else class="divide-border divide-y">
                    <li
                        v-for="entry in history.data"
                        :key="entry.id"
                        class="flex flex-wrap items-start justify-between gap-3 py-3"
                    >
                        <div class="min-w-0">
                            <p class="text-sm font-medium">
                                {{ permissionLabel(entry.permission_code) }}
                            </p>
                            <p class="text-muted-foreground mt-0.5 text-xs">
                                By {{ entry.actor_name }} ·
                                {{ formatDate(entry.created_at) }}
                            </p>
                            <p
                                v-if="entry.reason"
                                class="text-muted-foreground mt-1 text-xs break-words"
                            >
                                “{{ entry.reason }}”
                            </p>
                        </div>
                        <Badge
                            :variant="
                                entry.action === 'grant'
                                    ? 'default'
                                    : 'destructive'
                            "
                            >{{
                                entry.action === 'grant' ? 'Given' : 'Removed'
                            }}</Badge
                        >
                    </li>
                </ul>
                <div
                    class="flex flex-col gap-4 border-t pt-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div
                        class="text-muted-foreground flex items-center gap-2 text-sm"
                    >
                        Show
                        <Select
                            v-model="historyFilterForm.per_page"
                            @update:model-value="applyHistoryFilters"
                            ><SelectTrigger
                                class="h-9 w-20"
                                aria-label="Rows per page"
                                ><SelectValue /></SelectTrigger
                            ><SelectContent
                                ><SelectItem :value="10">10</SelectItem
                                ><SelectItem :value="25">25</SelectItem
                                ><SelectItem :value="50"
                                    >50</SelectItem
                                ></SelectContent
                            ></Select
                        >
                        per page
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-muted-foreground text-xs"
                            >Page {{ history.current_page }} of
                            {{ history.last_page }}</span
                        >
                        <div class="flex gap-2">
                            <Link
                                v-if="history.prev_page_url"
                                :href="history.prev_page_url"
                                preserve-state
                                preserve-scroll
                                ><Button variant="outline" size="sm"
                                    >Previous</Button
                                ></Link
                            ><Button v-else variant="outline" size="sm" disabled
                                >Previous</Button
                            ><Link
                                v-if="history.next_page_url"
                                :href="history.next_page_url"
                                preserve-state
                                preserve-scroll
                                ><Button variant="outline" size="sm"
                                    >Next</Button
                                ></Link
                            ><Button v-else variant="outline" size="sm" disabled
                                >Next</Button
                            >
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>

        <MoreDetails>
            <dl class="grid gap-4 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-muted-foreground text-xs">
                        Permission version
                    </dt>
                    <dd class="mt-1">v{{ admin.permission_version }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-xs">Added on</dt>
                    <dd class="mt-1">{{ formatDate(admin.created_at) }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-xs">
                        Permissions in use
                    </dt>
                    <dd class="mt-1">
                        {{ admin.effective_permissions.length }}
                    </dd>
                </div>
            </dl>
        </MoreDetails>

        <!-- Save changes dialog -->
        <Dialog
            :open="isConfirmDialogOpen"
            @update:open="isConfirmDialogOpen = $event"
        >
            <DialogContent class="sm:max-w-lg">
                <form class="space-y-5" @submit.prevent="confirmAndApply">
                    <DialogHeader>
                        <DialogTitle>Save permission changes</DialogTitle>
                        <DialogDescription>
                            For <strong>{{ admin.name }}</strong
                            >.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="space-y-3 text-sm">
                        <div v-if="grants.length > 0">
                            <p
                                class="font-medium text-emerald-600 dark:text-emerald-400"
                            >
                                Adding
                            </p>
                            <ul class="mt-1 list-disc space-y-0.5 pl-5">
                                <li v-for="g in grants" :key="g">
                                    {{ permissionLabel(g) }}
                                </li>
                            </ul>
                        </div>
                        <div v-if="revocations.length > 0">
                            <p
                                class="font-medium text-rose-600 dark:text-rose-400"
                            >
                                Removing
                            </p>
                            <ul class="mt-1 list-disc space-y-0.5 pl-5">
                                <li v-for="r in revocations" :key="r">
                                    {{ permissionLabel(r) }}
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div
                        v-if="touchesAdminManagement"
                        role="alert"
                        class="border-destructive/30 bg-destructive/10 text-destructive flex items-start gap-2 rounded-lg border p-3 text-xs"
                    >
                        <AlertTriangle class="size-4 shrink-0" />
                        <p>
                            This changes who can manage admins and security.
                            Make sure it is approved.
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="reason">Reason</Label>
                        <Input
                            id="reason"
                            v-model="reason"
                            placeholder="Why are you making this change?"
                            maxlength="500"
                            :class="
                                v$.reason.$error ? 'border-destructive' : ''
                            "
                        />
                        <div
                            class="text-muted-foreground flex justify-between text-xs"
                        >
                            <InputError
                                v-if="v$.reason.$error"
                                message="Please add a reason."
                            />
                            <span class="ml-auto">{{ reason.length }}/500</span>
                        </div>
                    </div>

                    <div class="flex items-start gap-2">
                        <Checkbox
                            id="confirm_checkbox"
                            :model-value="isConfirmed"
                            @update:model-value="
                                (val) => (isConfirmed = val === true)
                            "
                        />
                        <div class="space-y-1">
                            <Label
                                for="confirm_checkbox"
                                class="cursor-pointer text-sm font-normal"
                            >
                                I have checked these changes.
                            </Label>
                            <InputError
                                v-if="v$.isConfirmed.$error"
                                message="Tick this box to continue."
                            />
                        </div>
                    </div>

                    <div
                        v-if="saveErrors.length"
                        role="alert"
                        class="text-destructive space-y-1 text-xs"
                    >
                        <p v-for="error in saveErrors" :key="error">
                            {{ error }}
                        </p>
                    </div>

                    <DialogFooter class="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="isSubmitting"
                            @click="isConfirmDialogOpen = false"
                        >
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="isSubmitting">
                            Save changes
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
