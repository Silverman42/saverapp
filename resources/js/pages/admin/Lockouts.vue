<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { unlock } from '@/actions/App/Http/Controllers/Admin/LockoutController';
import { dashboard } from '@/routes';
import { index as lockoutsIndex } from '@/routes/admin/lockouts';
import { useVuelidate } from '@vuelidate/core';
import { minLength, required } from '@vuelidate/validators';
import {
    ChevronLeft,
    ChevronRight,
    RefreshCw,
    ShieldCheck,
    Unlock,
} from '@lucide/vue';
import DirectoryPanel from '@/components/directory/DirectoryPanel.vue';
import DirectoryRow from '@/components/directory/DirectoryRow.vue';
import EmptyState from '@/components/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import MoreDetails from '@/components/MoreDetails.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { showToast } from '@/lib/flashToast';

export type LockItem = {
    id: number;
    user_id: number | null;
    user_name?: string | null;
    user_type?: string | null;
    email: string;
    lock_category: string;
    reason: string;
    failed_attempts_count: number;
    masked_ip: string;
    device_context: string;
    locked_at?: string | null;
    locked_until?: string | null;
    requires_review: boolean;
    notification_status: 'not_sent' | 'queued' | 'sent' | 'failed';
    notification_status_label: string;
    is_active: boolean;
    is_expired: boolean;
    unlocked_at?: string | null;
    unlocked_by?: string | null;
    unlock_reason?: string | null;
    unlock_verification_method?: string | null;
    unlock_verification_method_label?: string | null;
    can_unlock: boolean;
    restriction_token: string | null;
};

export type VerificationMethodOption = {
    value: string;
    label: string;
};

export type PaginatedLocks = {
    data: LockItem[];
    current_page: number;
    last_page: number;
    total: number;
    next_page_url: string | null;
    prev_page_url: string | null;
};

type LockFilters = {
    search: string;
    category: string;
    state: string;
    per_page: number;
};

const props = defineProps<{
    locks: PaginatedLocks;
    verification_methods: VerificationMethodOption[];
    filters: LockFilters;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
            {
                title: 'Lockouts',
                href: lockoutsIndex(),
            },
        ],
    },
});

const isUnlockDialogOpen = ref(false);
const selectedLock = ref<LockItem | null>(null);
const isSubmitting = ref(false);
const serverErrors = ref<Record<string, string>>({});
const filtersOpen = ref(false);
const filterForm = reactive<LockFilters>({ ...props.filters });

watch(
    () => props.filters,
    (filters) => Object.assign(filterForm, filters),
);

const formState = reactive({
    reason: '',
    category: '',
    verification_method: '',
});

const rules = computed(() => ({
    reason: {
        required,
        minLength: minLength(5),
    },
    category: {
        required,
    },
    verification_method: {
        required,
    },
}));

const v$ = useVuelidate(rules, formState);

const openUnlockDialog = (lock: LockItem) => {
    selectedLock.value = lock;
    formState.category = lock.lock_category;
    formState.verification_method =
        props.verification_methods?.[0]?.value ?? 'in_person';
    formState.reason = '';
    serverErrors.value = {};
    v$.value.$reset();
    isUnlockDialogOpen.value = true;
};

const closeUnlockDialog = () => {
    isUnlockDialogOpen.value = false;
    selectedLock.value = null;
    serverErrors.value = {};
    v$.value.$reset();
};

const submitUnlock = async () => {
    const isValid = await v$.value.$validate();
    if (!isValid || !selectedLock.value || !selectedLock.value.user_id) {
        return;
    }

    isSubmitting.value = true;
    serverErrors.value = {};

    router.post(
        unlock(selectedLock.value.user_id).url,
        {
            category: formState.category,
            verification_method: formState.verification_method,
            reason: formState.reason.trim(),
            restriction_token: selectedLock.value.restriction_token,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                closeUnlockDialog();
            },
            onError: (errors) => {
                serverErrors.value = errors as Record<string, string>;
                const message =
                    errors.user ||
                    errors.category ||
                    errors.verification_method ||
                    errors.reason ||
                    Object.values(errors)[0] ||
                    'Could not unlock the account. Please try again.';
                showToast({
                    type: 'error',
                    title: 'Account not unlocked',
                    description: message as string,
                });
            },
            onFinish: () => {
                isSubmitting.value = false;
            },
        },
    );
};

const formatDateTime = (isoString?: string | null): string => {
    if (!isoString) {
        return '-';
    }
    try {
        const date = new Date(isoString);
        return date.toLocaleString(undefined, {
            dateStyle: 'medium',
            timeStyle: 'short',
        });
    } catch {
        return isoString;
    }
};

const getCategoryBadgeVariant = (category: string) => {
    switch (category) {
        case 'password':
            return 'secondary';
        case 'mfa':
            return 'outline';
        case 'recovery_code':
            return 'outline';
        default:
            return 'secondary';
    }
};

const refreshData = () => {
    router.reload();
};

const activeFilterCount = computed(
    () =>
        [filterForm.category, filterForm.state].filter(
            (value) => value && value !== 'all',
        ).length,
);

const applyFilters = (): void => {
    const query: Record<string, string | number> = {};
    if (filterForm.search) query.search = filterForm.search;
    if (filterForm.category && filterForm.category !== 'all')
        query.category = filterForm.category;
    if (filterForm.state && filterForm.state !== 'all')
        query.state = filterForm.state;
    if (filterForm.per_page !== 15) query.per_page = filterForm.per_page;
    router.get(
        lockoutsIndex.url({ query }),
        {},
        { preserveScroll: true, preserveState: true, replace: true },
    );
};

const resetFilters = (): void => {
    Object.assign(filterForm, {
        search: '',
        category: '',
        state: '',
        per_page: 15,
    });
    applyFilters();
};

const categoryLabels: Record<string, string> = {
    password: 'Wrong password',
    mfa: 'Wrong sign-in code',
    recovery_code: 'Wrong recovery code',
};
const categoryLabel = (category: string): string =>
    categoryLabels[category] ?? category.replaceAll('_', ' ');

const restrictionState = (lock: LockItem): string => {
    if (lock.is_active) return 'Active';
    if (lock.unlocked_at) return 'Unlocked';
    return 'Ended';
};
</script>

<template>
    <div class="flex flex-1 flex-col gap-6">
        <Head title="Lockouts" />

        <PageHeader
            title="Lockouts"
            description="People who are blocked from signing in after too many wrong tries."
        >
            <template #actions>
                <Button variant="outline" @click="refreshData">
                    <RefreshCw class="size-4" />
                    Refresh
                </Button>
            </template>
        </PageHeader>

        <DirectoryPanel
            title="Locked accounts"
            :description="`${locks.total} record${locks.total === 1 ? '' : 's'}`"
            :search-value="filterForm.search"
            search-placeholder="Search name or email"
            :filters-open="filtersOpen"
            :active-filter-count="activeFilterCount"
            @update:search-value="filterForm.search = $event"
            @submit-search="applyFilters"
            @toggle-filters="filtersOpen = !filtersOpen"
            @reset-filters="resetFilters"
        >
            <template #filters>
                <div class="w-fit space-y-1.5">
                    <Label for="lock-category" class="text-xs">Reason</Label
                    ><Select
                        v-model="filterForm.category"
                        @update:model-value="applyFilters"
                        ><SelectTrigger id="lock-category"
                            ><SelectValue
                                placeholder="All reasons" /></SelectTrigger
                        ><SelectContent
                            ><SelectItem value="all">All reasons</SelectItem
                            ><SelectItem value="password"
                                >Wrong password</SelectItem
                            ><SelectItem value="mfa"
                                >Wrong sign-in code</SelectItem
                            ><SelectItem value="recovery_code"
                                >Wrong recovery code</SelectItem
                            ></SelectContent
                        ></Select
                    >
                </div>
                <div class="w-fit space-y-1.5">
                    <Label for="lock-state" class="text-xs">Status</Label
                    ><Select
                        v-model="filterForm.state"
                        @update:model-value="applyFilters"
                        ><SelectTrigger id="lock-state"
                            ><SelectValue
                                placeholder="All statuses" /></SelectTrigger
                        ><SelectContent
                            ><SelectItem value="all">All statuses</SelectItem
                            ><SelectItem value="active">Locked now</SelectItem
                            ><SelectItem value="review">Needs review</SelectItem
                            ><SelectItem value="unlocked"
                                >Unlocked by an admin</SelectItem
                            ><SelectItem value="expired"
                                >Ended</SelectItem
                            ></SelectContent
                        ></Select
                    >
                </div>
            </template>
            <template #filter-summary
                ><p class="text-muted-foreground text-xs">
                    {{ locks.total }} found
                </p></template
            >

            <EmptyState
                v-if="locks.data.length === 0"
                :icon="ShieldCheck"
                title="No lockouts found"
                description="No one is locked out right now, or nothing matches your filters."
            />
            <div v-else class="space-y-3">
                <DirectoryRow v-for="lock in locks.data" :key="lock.id">
                    <div class="flex flex-wrap items-center gap-4">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">
                                {{ lock.user_name || lock.email }}
                            </p>
                            <p class="text-muted-foreground truncate text-xs">
                                {{ lock.email }}
                            </p>
                            <p class="text-muted-foreground mt-0.5 text-xs">
                                {{ categoryLabel(lock.lock_category) }} ·
                                {{ lock.failed_attempts_count }} tries ·
                                {{ formatDateTime(lock.locked_at) }}
                            </p>
                        </div>
                        <Badge
                            :variant="
                                lock.is_active ? 'destructive' : 'secondary'
                            "
                            >{{ restrictionState(lock) }}</Badge
                        >
                        <Button
                            v-if="lock.can_unlock"
                            variant="outline"
                            size="sm"
                            @click="openUnlockDialog(lock)"
                            ><Unlock class="size-3.5" /> Unlock</Button
                        >
                    </div>
                    <MoreDetails label="Details" class="mt-2">
                        <dl
                            class="text-muted-foreground grid gap-2 text-xs sm:grid-cols-2"
                        >
                            <div class="sm:col-span-2">
                                <dt class="text-foreground font-medium">Why</dt>
                                <dd>{{ lock.reason }}</dd>
                            </div>
                            <div>
                                <dt class="text-foreground font-medium">
                                    Locked until
                                </dt>
                                <dd>{{ formatDateTime(lock.locked_until) }}</dd>
                            </div>
                            <div>
                                <dt class="text-foreground font-medium">
                                    Device and network
                                </dt>
                                <dd>
                                    {{ lock.device_context }} ·
                                    {{ lock.masked_ip }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-foreground font-medium">
                                    Owner notice
                                </dt>
                                <dd>{{ lock.notification_status_label }}</dd>
                            </div>
                            <div v-if="lock.unlocked_at" class="sm:col-span-2">
                                <dt class="text-foreground font-medium">
                                    Unlocked
                                </dt>
                                <dd>
                                    {{ formatDateTime(lock.unlocked_at) }}
                                    <template v-if="lock.unlocked_by">
                                        by {{ lock.unlocked_by }}</template
                                    ><template v-if="lock.unlock_reason"
                                        >: {{ lock.unlock_reason }}</template
                                    >
                                </dd>
                            </div>
                        </dl>
                    </MoreDetails>
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
                            >Page {{ locks.current_page }} of
                            {{ locks.last_page }}</span
                        >
                        <div class="flex gap-2">
                            <Link
                                v-if="locks.prev_page_url"
                                :href="locks.prev_page_url"
                                preserve-state
                                preserve-scroll
                                ><Button variant="outline" size="sm"
                                    ><ChevronLeft /> Prev</Button
                                ></Link
                            ><Button v-else variant="outline" size="sm" disabled
                                ><ChevronLeft /> Prev</Button
                            ><Link
                                v-if="locks.next_page_url"
                                :href="locks.next_page_url"
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

        <Dialog
            :open="isUnlockDialogOpen"
            @update:open="
                (val) => {
                    if (!val) closeUnlockDialog();
                }
            "
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Unlock account</DialogTitle>
                    <DialogDescription>
                        Let
                        <strong>{{
                            selectedLock?.user_name || selectedLock?.email
                        }}</strong>
                        sign in again. Their password and settings stay the
                        same.
                    </DialogDescription>
                </DialogHeader>

                <form class="space-y-4 py-2" @submit.prevent="submitUnlock">
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="text-muted-foreground">Locked for:</span>
                        <Badge
                            :variant="
                                getCategoryBadgeVariant(formState.category)
                            "
                        >
                            {{ categoryLabel(formState.category) }}
                        </Badge>
                        <InputError :message="serverErrors.category" />
                    </div>

                    <div class="space-y-2">
                        <Label for="verification-method"
                            >How did you check it was them?</Label
                        >
                        <Select v-model="formState.verification_method">
                            <SelectTrigger
                                id="verification-method"
                                class="w-full"
                                aria-label="Verification method"
                            >
                                <SelectValue placeholder="Choose one" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="method in props.verification_methods"
                                    :key="method.value"
                                    :value="method.value"
                                >
                                    {{ method.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError
                            v-if="v$.verification_method.$error"
                            :message="
                                v$.verification_method.$errors[0]
                                    ?.$message as string
                            "
                        />
                        <InputError
                            v-else-if="serverErrors.verification_method"
                            :message="serverErrors.verification_method"
                        />
                    </div>

                    <div class="space-y-2">
                        <Label for="unlock-reason">What did you do?</Label>
                        <Input
                            id="unlock-reason"
                            v-model="formState.reason"
                            placeholder="e.g. Called them on their registered phone"
                            maxlength="255"
                            aria-describedby="unlock-reason-help"
                            :class="{
                                'border-destructive':
                                    v$.reason.$error || serverErrors.reason,
                            }"
                        />
                        <p
                            id="unlock-reason-help"
                            class="text-muted-foreground text-xs"
                        >
                            At least 5 characters. Never write passwords or
                            codes.
                        </p>
                        <InputError
                            v-if="v$.reason.$error"
                            :message="v$.reason.$errors[0]?.$message as string"
                        />
                        <InputError
                            v-else-if="serverErrors.reason"
                            :message="serverErrors.reason"
                        />
                    </div>

                    <DialogFooter class="mt-4 gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="isSubmitting"
                            @click="closeUnlockDialog"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            :disabled="isSubmitting || v$.$invalid"
                        >
                            <Unlock class="size-4" />
                            Unlock
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
