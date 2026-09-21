<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { dashboard } from '@/routes';
import { useVuelidate } from '@vuelidate/core';
import { maxLength, minLength, required } from '@vuelidate/validators';
import {
    AlertCircle,
    AlertTriangle,
    ArrowLeft,
    Check,
    CheckCircle2,
    Clock,
    History,
    MinusCircle,
    PlusCircle,
    RefreshCw,
    Shield,
    ShieldAlert,
    ShieldCheck,
    User as UserIcon,
} from '@lucide/vue';
import InputError from '@/components/InputError.vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

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

const props = defineProps<{
    admin: AdminDetail;
    catalogue: CatalogueItem[];
    history: PaginatedHistory;
    canManage: boolean;
    isFresh: boolean;
    isSelf: boolean;
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
                href: '/admin/access',
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

// Keep selected in sync if props reload
watch(
    () => props.admin.direct_permissions,
    (newDirects) => {
        selectedPermissions.value = [...newDirects];
        concurrencyError.value = null;
    },
);

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
            <Link href="/admin/access">
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

        <!-- History Log -->
        <Card>
            <CardHeader class="pb-3">
                <div class="flex items-center justify-between">
                    <div>
                        <CardTitle class="flex items-center gap-2">
                            <History class="h-4 w-4" />
                            Permission Change History
                        </CardTitle>
                        <CardDescription>
                            Append-only chronological record of grants and
                            revocations.
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <div v-if="history.data.length > 0" class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead
                            class="bg-muted/40 text-muted-foreground border-b uppercase"
                        >
                            <tr>
                                <th class="px-3 py-2">Timestamp</th>
                                <th class="px-3 py-2">Action</th>
                                <th class="px-3 py-2">Permission</th>
                                <th class="px-3 py-2">Actor</th>
                                <th class="px-3 py-2">Reason</th>
                                <th class="px-3 py-2">Version</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="h in history.data"
                                :key="h.id"
                                class="hover:bg-muted/30"
                            >
                                <td
                                    class="text-muted-foreground px-3 py-2 whitespace-nowrap"
                                >
                                    {{
                                        h.created_at
                                            ? new Date(
                                                  h.created_at,
                                              ).toLocaleString()
                                            : '-'
                                    }}
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    <Badge
                                        :variant="
                                            h.action === 'grant'
                                                ? 'default'
                                                : 'destructive'
                                        "
                                        class="px-1.5 py-0 font-mono text-[10px] uppercase"
                                    >
                                        {{ h.action }}
                                    </Badge>
                                </td>
                                <td class="px-3 py-2 font-mono">
                                    {{ h.permission_code }}
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    {{ h.actor_name }}
                                </td>
                                <td
                                    class="max-w-xs truncate px-3 py-2"
                                    :title="h.reason || ''"
                                >
                                    {{ h.reason || '-' }}
                                </td>
                                <td
                                    class="text-muted-foreground px-3 py-2 font-mono"
                                >
                                    v{{ h.permission_version }}
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- History Pagination -->
                    <div
                        v-if="history.last_page > 1"
                        class="text-muted-foreground mt-3 flex items-center justify-between border-t pt-3 text-xs"
                    >
                        <div>
                            Page {{ history.current_page }} of
                            {{ history.last_page }}
                        </div>
                        <div class="flex gap-2">
                            <Link
                                v-if="history.prev_page_url"
                                :href="history.prev_page_url"
                            >
                                <Button variant="outline" size="sm"
                                    >Previous</Button
                                >
                            </Link>
                            <Link
                                v-if="history.next_page_url"
                                :href="history.next_page_url"
                            >
                                <Button variant="outline" size="sm"
                                    >Next</Button
                                >
                            </Link>
                        </div>
                    </div>
                </div>
                <div
                    v-else
                    class="text-muted-foreground py-6 text-center text-sm italic"
                >
                    No permission change history recorded for this
                    administrator.
                </div>
            </CardContent>
        </Card>

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
