<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
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
    ArrowLeft,
    Check,
    History,
    MinusCircle,
    PlusCircle,
    RefreshCw,
    Shield,
    ShieldCheck,
} from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import DirectoryPanel from '@/components/directory/DirectoryPanel.vue';
import DirectoryRow from '@/components/directory/DirectoryRow.vue';
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
import AdminInvitationPanel from '@/components/AdminInvitationPanel.vue';
import type { AdminInvitationSummary } from '@/components/AdminInvitationPanel.vue';
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
    canRequestRecovery: boolean;
    isFresh: boolean;
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
                title: 'Admin access',
                href: adminAccessIndex(),
            },
            {
                title: 'Permissions detail',
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
const historyFiltersOpen = ref(false);
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

const activeHistoryFilterCount = computed(() => {
    return historyFilterForm.action && historyFilterForm.action !== 'all'
        ? 1
        : 0;
});

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

const resetHistoryFilters = (): void => {
    Object.assign(historyFilterForm, { search: '', action: '', per_page: 10 });
    applyHistoryFilters();
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

const togglePermission = (code: string) => {
    if (selectedPermissions.value.includes(code)) {
        selectedPermissions.value = selectedPermissions.value.filter(
            (p) => p !== code,
        );
    } else {
        selectedPermissions.value.push(code);
    }
};

const resetSelection = () => {
    selectedPermissions.value = [...props.admin.direct_permissions];
    reason.value = '';
    isConfirmed.value = false;
    v$.value.$reset();
    concurrencyError.value = null;
};

const openReviewDialog = async () => {
    const isValid = await v$.value.$validate();
    if (!isValid || !hasChanges.value) {
        return;
    }
    isConfirmDialogOpen.value = true;
};

const isSubmitting = ref(false);

const applyChanges = () => {
    isSubmitting.value = true;
    concurrencyError.value = null;

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
                isConfirmDialogOpen.value = false;
                if (errors.expected_permission_version) {
                    concurrencyError.value =
                        errors.expected_permission_version +
                        ' Current permissions have been refreshed. Please review the updated state.';
                    selectedPermissions.value = [
                        ...props.admin.direct_permissions,
                    ];
                }
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
</script>

<template>
    <Head :title="`Admin Access: ${admin.name}`" />

    <div class="space-y-6">
        <!-- Back Navigation & Header -->
        <div class="flex items-center gap-4">
            <Link :href="adminAccessIndex().url">
                <Button variant="outline" size="icon" class="h-8 w-8">
                    <ArrowLeft class="h-4 w-4" />
                </Button>
            </Link>
            <div>
                <h1 class="text-[25px] font-medium tracking-tight">
                    {{ admin.name }}
                </h1>
                <p class="text-muted-foreground mt-1.5 text-sm">
                    {{ admin.email }}
                </p>
            </div>
            <div class="ml-auto flex items-center gap-2">
                <Link
                    v-if="canRequestRecovery"
                    :href="createRecovery(admin.id).url"
                >
                    <Button variant="outline" size="sm"
                        >Request account recovery</Button
                    >
                </Link>
                <Badge variant="outline" class="font-mono text-xs">
                    Version v{{ admin.permission_version }}
                </Badge>
                <Badge
                    :variant="
                        admin.account_state === 'active'
                            ? 'default'
                            : 'secondary'
                    "
                    class="text-xs capitalize"
                >
                    {{ admin.account_state }}
                </Badge>
            </div>
        </div>

        <AdminInvitationPanel
            v-if="invitation"
            :admin-id="admin.id"
            :current-email="admin.email"
            :invitation="invitation"
        />

        <!-- Self-view notice -->
        <div
            v-if="isSelf"
            class="rounded-lg border border-blue-500/20 bg-blue-500/10 p-4 text-sm text-blue-700 dark:text-blue-300"
        >
            <div class="flex items-start gap-3">
                <ShieldCheck class="mt-0.5 h-5 w-5 shrink-0" />
                <div>
                    <p class="font-semibold">Self-Management Prohibition</p>
                    <p class="mt-1 text-xs">
                        You are viewing your own permissions. Administrators
                        cannot grant or revoke their own permissions. Another
                        authorized administrator must make changes.
                    </p>
                </div>
            </div>
        </div>

        <!-- Active Restrictions banner -->
        <div
            v-if="admin.restrictions.length > 0"
            class="rounded-lg border border-amber-500/20 bg-amber-500/10 p-4 text-sm text-amber-700 dark:text-amber-300"
        >
            <div class="flex items-start gap-3">
                <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0" />
                <div>
                    <p class="font-semibold">
                        Active Authorization Restrictions
                    </p>
                    <ul class="mt-1 list-disc space-y-1 pl-4 text-xs">
                        <li v-for="res in admin.restrictions" :key="res.id">
                            <strong>{{ res.restriction_type }}</strong> on
                            <code>{{ res.permission_code }}</code> (expires:
                            {{
                                res.expires_at
                                    ? new Date(res.expires_at).toLocaleString()
                                    : 'Never'
                            }})
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Stale Concurrency Warning -->
        <div
            v-if="concurrencyError"
            class="border-destructive/20 bg-destructive/10 text-destructive rounded-lg border p-4 text-sm"
        >
            <div class="flex items-start gap-3">
                <AlertCircle class="mt-0.5 h-5 w-5 shrink-0" />
                <div>
                    <p class="font-semibold">Version Conflict</p>
                    <p class="mt-1 text-xs">{{ concurrencyError }}</p>
                </div>
            </div>
        </div>

        <!-- Permission Editor (for authorized Admins managing other Admins) -->
        <Card v-if="canManage">
            <CardHeader>
                <div class="flex items-center justify-between">
                    <div>
                        <CardTitle>Permission Assignment</CardTitle>
                        <CardDescription>
                            Select or deselect direct permissions to grant or
                            revoke. Changes are applied atomically with
                            optimistic concurrency.
                        </CardDescription>
                    </div>
                    <div v-if="hasChanges" class="flex items-center gap-2">
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="resetSelection"
                        >
                            Reset
                        </Button>
                    </div>
                </div>
            </CardHeader>
            <CardContent class="space-y-6">
                <!-- Permission Grid -->
                <div class="grid gap-3 sm:grid-cols-2">
                    <div
                        v-for="item in catalogue"
                        :key="item.name"
                        :class="[
                            'relative flex cursor-pointer flex-col justify-between rounded-lg border p-3 transition-colors',
                            selectedPermissions.includes(item.name)
                                ? 'border-primary bg-primary/5'
                                : 'border-border hover:bg-muted/40',
                            item.is_highest_risk
                                ? 'ring-destructive/40 ring-1'
                                : '',
                        ]"
                        @click="togglePermission(item.name)"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="space-y-1">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="text-foreground text-sm font-medium"
                                    >
                                        {{ item.display_name }}
                                    </span>
                                    <Badge
                                        v-if="item.is_highest_risk"
                                        variant="destructive"
                                        class="px-1 py-0 text-[10px]"
                                    >
                                        Highest Risk
                                    </Badge>
                                </div>
                                <p class="text-muted-foreground text-xs">
                                    {{ item.description }}
                                </p>
                            </div>
                            <div class="pt-0.5">
                                <Checkbox
                                    :model-value="
                                        selectedPermissions.includes(item.name)
                                    "
                                    @update:model-value="
                                        togglePermission(item.name)
                                    "
                                />
                            </div>
                        </div>

                        <div
                            class="mt-3 flex items-center justify-between text-[11px]"
                        >
                            <code class="text-muted-foreground font-mono">{{
                                item.name
                            }}</code>
                            <div>
                                <span
                                    v-if="grants.includes(item.name)"
                                    class="inline-flex items-center gap-1 font-semibold text-emerald-600 dark:text-emerald-400"
                                >
                                    <PlusCircle class="h-3.5 w-3.5" /> Will
                                    Grant
                                </span>
                                <span
                                    v-else-if="revocations.includes(item.name)"
                                    class="inline-flex items-center gap-1 font-semibold text-rose-600 dark:text-rose-400"
                                >
                                    <MinusCircle class="h-3.5 w-3.5" /> Will
                                    Revoke
                                </span>
                                <span
                                    v-else-if="
                                        selectedPermissions.includes(item.name)
                                    "
                                    class="text-muted-foreground"
                                >
                                    Active
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Changes Summary & Reason -->
                <div
                    v-if="hasChanges"
                    class="bg-muted/20 space-y-4 rounded-lg border p-4"
                >
                    <div class="text-sm font-medium">
                        Proposed Changes Summary
                    </div>

                    <div class="grid gap-3 text-xs sm:grid-cols-2">
                        <!-- Grants -->
                        <div
                            class="rounded border border-emerald-500/20 bg-emerald-50/50 p-3 dark:bg-emerald-950/20"
                        >
                            <div
                                class="mb-1 flex items-center gap-1.5 font-semibold text-emerald-700 dark:text-emerald-400"
                            >
                                <PlusCircle class="h-4 w-4" />
                                Grants ({{ grants.length }})
                            </div>
                            <ul
                                v-if="grants.length > 0"
                                class="space-y-1 text-emerald-900 dark:text-emerald-200"
                            >
                                <li v-for="g in grants" :key="g">
                                    +
                                    {{
                                        getPermissionDetails(g)?.display_name ||
                                        g
                                    }}
                                </li>
                            </ul>
                            <p v-else class="text-muted-foreground italic">
                                None
                            </p>
                        </div>

                        <!-- Revocations -->
                        <div
                            class="rounded border border-rose-500/20 bg-rose-50/50 p-3 dark:bg-rose-950/20"
                        >
                            <div
                                class="mb-1 flex items-center gap-1.5 font-semibold text-rose-700 dark:text-rose-400"
                            >
                                <MinusCircle class="h-4 w-4" />
                                Revocations ({{ revocations.length }})
                            </div>
                            <ul
                                v-if="revocations.length > 0"
                                class="space-y-1 text-rose-900 dark:text-rose-200"
                            >
                                <li v-for="r in revocations" :key="r">
                                    -
                                    {{
                                        getPermissionDetails(r)?.display_name ||
                                        r
                                    }}
                                </li>
                            </ul>
                            <p v-else class="text-muted-foreground italic">
                                None
                            </p>
                        </div>
                    </div>

                    <!-- Highest risk warning -->
                    <div
                        v-if="
                            grants.includes('admins.manage') ||
                            revocations.includes('admins.manage')
                        "
                        class="border-destructive/30 bg-destructive/10 text-destructive rounded border p-3 text-xs"
                    >
                        <p class="flex items-center gap-1.5 font-semibold">
                            <AlertTriangle class="h-4 w-4 shrink-0" />
                            CRITICAL SECURITY ACTION: admins.manage
                        </p>
                        <p class="mt-1">
                            You are modifying the
                            <code>admins.manage</code> capability. This grants
                            or revokes the power to alter administrator
                            privileges and system security rules. Ensure this
                            has been properly vetted.
                        </p>
                    </div>

                    <!-- Required Reason -->
                    <div class="space-y-2">
                        <Label for="reason">
                            Reason for modification
                            <span class="text-destructive">*</span>
                        </Label>
                        <Input
                            id="reason"
                            v-model="reason"
                            placeholder="Explain why these permissions are being granted or revoked (1–500 chars)..."
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
                                :message="
                                    v$.reason.$errors[0].$message as string
                                "
                            />
                            <span class="ml-auto">{{ reason.length }}/500</span>
                        </div>
                    </div>

                    <!-- Confirmation checkbox -->
                    <div class="flex items-start gap-2 pt-2">
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
                                class="cursor-pointer text-xs font-normal"
                            >
                                I confirm that I have reviewed the
                                before-and-after permissions and authorize this
                                change.
                            </Label>
                            <InputError
                                v-if="v$.isConfirmed.$error"
                                message="You must acknowledge and confirm the change."
                            />
                        </div>
                    </div>

                    <div class="pt-2">
                        <Button
                            type="button"
                            :disabled="!hasChanges || isSubmitting"
                            @click="openReviewDialog"
                        >
                            Review &amp; Apply Changes
                        </Button>
                    </div>
                </div>
            </CardContent>
        </Card>

        <!-- Current Permissions (Read-only view for self or when cannot manage) -->
        <Card v-else>
            <CardHeader>
                <CardTitle>Assigned Direct Permissions</CardTitle>
                <CardDescription>
                    All permissions explicitly granted to this administrator
                    account.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div
                    v-if="admin.direct_permissions.length > 0"
                    class="grid gap-3 sm:grid-cols-2"
                >
                    <div
                        v-for="perm in admin.direct_permissions"
                        :key="perm"
                        class="rounded-lg border p-3"
                    >
                        <div class="text-foreground text-sm font-medium">
                            {{
                                getPermissionDetails(perm)?.display_name || perm
                            }}
                        </div>
                        <p class="text-muted-foreground mt-1 text-xs">
                            {{ getPermissionDetails(perm)?.description }}
                        </p>
                        <code
                            class="text-muted-foreground mt-2 block font-mono text-[11px]"
                        >
                            {{ perm }}
                        </code>
                    </div>
                </div>
                <div
                    v-else
                    class="text-muted-foreground py-6 text-center text-sm"
                >
                    No granular permissions currently assigned. Operating with
                    baseline Administrator access.
                </div>
            </CardContent>
        </Card>

        <DirectoryPanel
            title="Permission change history"
            :description="`${history.total} append-only record${history.total === 1 ? '' : 's'} for this administrator.`"
            :search-value="historyFilterForm.search"
            search-placeholder="Search permission or actor"
            :filters-open="historyFiltersOpen"
            :active-filter-count="activeHistoryFilterCount"
            @update:search-value="historyFilterForm.search = $event"
            @submit-search="applyHistoryFilters"
            @toggle-filters="historyFiltersOpen = !historyFiltersOpen"
            @reset-filters="resetHistoryFilters"
        >
            <template #filters
                ><div class="w-fit space-y-1.5">
                    <Label for="history-action" class="text-xs">Action</Label
                    ><Select
                        v-model="historyFilterForm.action"
                        @update:model-value="applyHistoryFilters"
                        ><SelectTrigger id="history-action"
                            ><SelectValue
                                placeholder="All actions" /></SelectTrigger
                        ><SelectContent
                            ><SelectItem value="all">All actions</SelectItem
                            ><SelectItem value="grant">Grant</SelectItem
                            ><SelectItem value="revoke"
                                >Revoke</SelectItem
                            ></SelectContent
                        ></Select
                    >
                </div></template
            >
            <template #filter-summary
                ><p class="text-muted-foreground text-xs">
                    {{ history.total }} record{{
                        history.total === 1 ? '' : 's'
                    }}
                    match the current filters.
                </p></template
            >
            <div
                v-if="history.data.length === 0"
                class="text-muted-foreground py-10 text-center text-sm"
            >
                No permission change history matches this view.
            </div>
            <div v-else class="space-y-3">
                <DirectoryRow v-for="entry in history.data" :key="entry.id"
                    ><div
                        class="hidden items-center gap-5 md:grid md:grid-cols-[minmax(10rem,1fr)_minmax(7rem,.55fr)_minmax(14rem,1.2fr)_minmax(9rem,.75fr)_minmax(12rem,1fr)_minmax(5rem,.4fr)]"
                    >
                        <div>
                            <p
                                class="text-muted-foreground text-[11px] font-medium uppercase"
                            >
                                Timestamp
                            </p>
                            <p class="mt-1 text-sm">
                                {{
                                    entry.created_at
                                        ? new Date(
                                              entry.created_at,
                                          ).toLocaleString()
                                        : '—'
                                }}
                            </p>
                        </div>
                        <div>
                            <p
                                class="text-muted-foreground text-[11px] font-medium uppercase"
                            >
                                Action
                            </p>
                            <Badge
                                :variant="
                                    entry.action === 'grant'
                                        ? 'default'
                                        : 'destructive'
                                "
                                class="mt-1 uppercase"
                                >{{ entry.action }}</Badge
                            >
                        </div>
                        <div>
                            <p
                                class="text-muted-foreground text-[11px] font-medium uppercase"
                            >
                                Permission
                            </p>
                            <p class="mt-1 font-mono text-sm">
                                {{ entry.permission_code }}
                            </p>
                        </div>
                        <div>
                            <p
                                class="text-muted-foreground text-[11px] font-medium uppercase"
                            >
                                Actor
                            </p>
                            <p class="mt-1 text-sm">{{ entry.actor_name }}</p>
                        </div>
                        <div>
                            <p
                                class="text-muted-foreground text-[11px] font-medium uppercase"
                            >
                                Reason
                            </p>
                            <p
                                class="mt-1 truncate text-sm"
                                :title="entry.reason || ''"
                            >
                                {{ entry.reason || '—' }}
                            </p>
                        </div>
                        <div>
                            <p
                                class="text-muted-foreground text-[11px] font-medium uppercase"
                            >
                                Version
                            </p>
                            <p class="mt-1 text-sm">
                                v{{ entry.permission_version }}
                            </p>
                        </div>
                    </div>
                    <div class="md:hidden">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold">
                                    {{ entry.permission_code }}
                                </p>
                                <p class="text-muted-foreground text-xs">
                                    {{
                                        entry.created_at
                                            ? new Date(
                                                  entry.created_at,
                                              ).toLocaleString()
                                            : '—'
                                    }}
                                </p>
                            </div>
                            <Badge
                                :variant="
                                    entry.action === 'grant'
                                        ? 'default'
                                        : 'destructive'
                                "
                                class="uppercase"
                                >{{ entry.action }}</Badge
                            >
                        </div>
                        <div class="mt-4 grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <p
                                    class="text-muted-foreground text-[10px] font-medium uppercase"
                                >
                                    Actor
                                </p>
                                <p class="mt-1">{{ entry.actor_name }}</p>
                            </div>
                            <div>
                                <p
                                    class="text-muted-foreground text-[10px] font-medium uppercase"
                                >
                                    Version
                                </p>
                                <p class="mt-1">
                                    v{{ entry.permission_version }}
                                </p>
                            </div>
                            <div class="col-span-2">
                                <p
                                    class="text-muted-foreground text-[10px] font-medium uppercase"
                                >
                                    Reason
                                </p>
                                <p class="mt-1">{{ entry.reason || '—' }}</p>
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
                            v-model="historyFilterForm.per_page"
                            @update:model-value="applyHistoryFilters"
                            ><SelectTrigger class="h-9 w-20"
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
                                ><Button size="sm">Next</Button></Link
                            ><Button v-else size="sm" disabled>Next</Button>
                        </div>
                    </div>
                </div></template
            >
        </DirectoryPanel>

        <!-- Final Confirmation Modal Dialog -->
        <Dialog
            :open="isConfirmDialogOpen"
            @update:open="isConfirmDialogOpen = $event"
        >
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Confirm Permission Update</DialogTitle>
                    <DialogDescription>
                        You are modifying access rights for
                        <strong>{{ admin.name }}</strong
                        >.
                    </DialogDescription>
                </DialogHeader>

                <div class="space-y-4 py-3 text-xs">
                    <div v-if="grants.length > 0" class="space-y-1">
                        <span
                            class="font-semibold text-emerald-600 dark:text-emerald-400"
                            >Permissions to Grant:</span
                        >
                        <ul class="list-disc space-y-0.5 pl-4">
                            <li v-for="g in grants" :key="g">
                                {{ getPermissionDetails(g)?.display_name || g }}
                            </li>
                        </ul>
                    </div>

                    <div v-if="revocations.length > 0" class="space-y-1">
                        <span
                            class="font-semibold text-rose-600 dark:text-rose-400"
                            >Permissions to Revoke:</span
                        >
                        <ul class="list-disc space-y-0.5 pl-4">
                            <li v-for="r in revocations" :key="r">
                                {{ getPermissionDetails(r)?.display_name || r }}
                            </li>
                        </ul>
                    </div>

                    <div class="space-y-1 border-t pt-2">
                        <span class="font-semibold">Recorded Reason:</span>
                        <p class="text-muted-foreground italic">{{ reason }}</p>
                    </div>

                    <div
                        class="bg-muted text-muted-foreground rounded p-2 text-[11px]"
                    >
                        Target current version:
                        <code>v{{ admin.permission_version }}</code> &rarr;
                        resulting version:
                        <code>v{{ admin.permission_version + 1 }}</code
                        >.
                    </div>
                </div>

                <DialogFooter class="gap-2 sm:gap-0">
                    <Button
                        variant="outline"
                        :disabled="isSubmitting"
                        @click="isConfirmDialogOpen = false"
                    >
                        Cancel
                    </Button>
                    <Button
                        variant="default"
                        :disabled="isSubmitting"
                        @click="applyChanges"
                    >
                        Confirm &amp; Apply
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
