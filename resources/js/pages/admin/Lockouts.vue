<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { unlock } from '@/actions/App/Http/Controllers/Admin/LockoutController';
import { dashboard } from '@/routes';
import { index as lockoutsIndex } from '@/routes/admin/lockouts';
import { useVuelidate } from '@vuelidate/core';
import { minLength, required } from '@vuelidate/validators';
import { ChevronLeft, ChevronRight, RefreshCw, ShieldCheck, Unlock } from '@lucide/vue';
import { toast } from 'vue-sonner';
import DirectoryPanel from '@/components/directory/DirectoryPanel.vue';
import DirectoryRow from '@/components/directory/DirectoryRow.vue';
import InputError from '@/components/InputError.vue';
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
    is_active: boolean;
    is_expired: boolean;
    unlocked_at?: string | null;
    unlocked_by?: string | null;
    unlock_reason?: string | null;
    unlock_verification_method?: string | null;
    unlock_verification_method_label?: string | null;
    can_unlock: boolean;
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
                title: 'Security lockouts',
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

watch(() => props.filters, (filters) => Object.assign(filterForm, filters));

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
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                closeUnlockDialog();
                toast.success('Account restriction cleared successfully.');
            },
            onError: (errors) => {
                serverErrors.value = errors as Record<string, string>;
                const message =
                    errors.user ||
                    errors.category ||
                    errors.verification_method ||
                    errors.reason ||
                    Object.values(errors)[0] ||
                    'Failed to unlock account.';
                toast.error(message as string);
            },
            onFinish: () => {
                isSubmitting.value = false;
            },
        },
    );
};

const formatDateTime = (isoString?: string | null): string => {
    if (!isoString) {
        return '—';
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

const activeFilterCount = computed(() => [filterForm.category, filterForm.state].filter((value) => value && value !== 'all').length);

const applyFilters = (): void => {
    const query: Record<string, string | number> = {};
    if (filterForm.search) query.search = filterForm.search;
    if (filterForm.category && filterForm.category !== 'all') query.category = filterForm.category;
    if (filterForm.state && filterForm.state !== 'all') query.state = filterForm.state;
    if (filterForm.per_page !== 15) query.per_page = filterForm.per_page;
    router.get(lockoutsIndex.url({ query }), {}, { preserveScroll: true, preserveState: true, replace: true });
};

const resetFilters = (): void => {
    Object.assign(filterForm, { search: '', category: '', state: '', per_page: 15 });
    applyFilters();
};

const restrictionState = (lock: LockItem): string => {
    if (lock.is_active) return 'Active';
    if (lock.unlocked_at) return 'Unlocked';
    return 'Expired';
};
</script>

<template>
    <div class="flex flex-1 flex-col gap-6 font-sans">
        <Head title="Security Lockouts & Abuse Monitoring" />

        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
        >
            <div>
                <!-- heading -->
                <h1 class="text-[25px] font-medium tracking-tight">
                    Security Lockouts
                </h1>
                <!-- heading end  -->
                <!-- Subtext  -->
                <p class="text-muted-foreground mt-1.5 text-sm">
                    Monitor temporary authentication restrictions, sliding abuse
                    counters, and perform authorized manual unlocks.
                </p>
                <!-- Subtext end -->
            </div>

            <div class="flex items-center gap-2">
                <Button variant="outline" size="sm" @click="refreshData">
                    <RefreshCw class="size-4" />
                    Refresh
                </Button>
            </div>
        </div>

        <DirectoryPanel
            title="Lockout records"
            :description="`${locks.total} lockout record${locks.total === 1 ? '' : 's'} matching the current view.`"
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
                <div class="w-fit space-y-1.5"><Label for="lock-category" class="text-xs">Category</Label><Select v-model="filterForm.category" @update:model-value="applyFilters"><SelectTrigger id="lock-category"><SelectValue placeholder="All categories" /></SelectTrigger><SelectContent><SelectItem value="all">All categories</SelectItem><SelectItem value="password">Password</SelectItem><SelectItem value="mfa">MFA</SelectItem><SelectItem value="recovery_code">Recovery code</SelectItem></SelectContent></Select></div>
                <div class="w-fit space-y-1.5"><Label for="lock-state" class="text-xs">Restriction state</Label><Select v-model="filterForm.state" @update:model-value="applyFilters"><SelectTrigger id="lock-state"><SelectValue placeholder="All states" /></SelectTrigger><SelectContent><SelectItem value="all">All states</SelectItem><SelectItem value="active">Active</SelectItem><SelectItem value="review">Review required</SelectItem><SelectItem value="unlocked">Manually unlocked</SelectItem><SelectItem value="expired">Expired</SelectItem></SelectContent></Select></div>
            </template>
            <template #filter-summary><p class="text-muted-foreground text-xs">{{ locks.total }} record{{ locks.total === 1 ? '' : 's' }} match the current filters.</p></template>

            <div v-if="locks.data.length === 0" class="py-14 text-center"><div class="bg-muted text-muted-foreground mx-auto flex size-12 items-center justify-center rounded-2xl"><ShieldCheck class="size-5" /></div><h3 class="mt-4 text-sm font-semibold">No lockouts found</h3><p class="text-muted-foreground mt-1 text-sm">There are no authentication restrictions matching this view.</p></div>
            <div v-else class="space-y-3"><DirectoryRow v-for="lock in locks.data" :key="lock.id"><div class="hidden items-center gap-5 lg:grid lg:grid-cols-[minmax(14rem,1.4fr)_minmax(8rem,.7fr)_minmax(8rem,.7fr)_minmax(8rem,.7fr)_minmax(11rem,1fr)_auto]"><div class="min-w-0"><p class="truncate text-sm font-semibold">{{ lock.user_name || lock.email }}</p><p class="text-muted-foreground truncate text-xs">{{ lock.email }}</p></div><div><p class="text-muted-foreground text-[11px] font-medium uppercase">Category</p><Badge :variant="getCategoryBadgeVariant(lock.lock_category)" class="mt-1 capitalize">{{ lock.lock_category.replace('_', ' ') }}</Badge></div><div><p class="text-muted-foreground text-[11px] font-medium uppercase">State</p><Badge :variant="lock.is_active ? 'destructive' : 'secondary'" class="mt-1">{{ restrictionState(lock) }}</Badge></div><div><p class="text-muted-foreground text-[11px] font-medium uppercase">Attempts</p><p class="mt-1 text-sm">{{ lock.failed_attempts_count }}</p></div><div><p class="text-muted-foreground text-[11px] font-medium uppercase">Locked at</p><p class="mt-1 text-sm">{{ formatDateTime(lock.locked_at) }}</p></div><Button v-if="lock.can_unlock" variant="outline" size="sm" @click="openUnlockDialog(lock)"><Unlock class="size-3.5" /> Manual unlock</Button><span v-else class="text-muted-foreground text-xs">{{ lock.is_active ? 'Cannot unlock' : '—' }}</span></div><div class="lg:hidden"><div class="flex items-start justify-between gap-3"><div class="min-w-0"><p class="truncate text-sm font-semibold">{{ lock.user_name || lock.email }}</p><p class="text-muted-foreground truncate text-xs">{{ lock.email }}</p></div><Button v-if="lock.can_unlock" variant="outline" size="sm" @click="openUnlockDialog(lock)">Unlock</Button></div><div class="mt-4 grid grid-cols-2 gap-4 text-sm"><div><p class="text-muted-foreground text-[10px] font-medium uppercase">Category</p><Badge :variant="getCategoryBadgeVariant(lock.lock_category)" class="mt-1 capitalize">{{ lock.lock_category.replace('_', ' ') }}</Badge></div><div><p class="text-muted-foreground text-[10px] font-medium uppercase">State</p><Badge :variant="lock.is_active ? 'destructive' : 'secondary'" class="mt-1">{{ restrictionState(lock) }}</Badge></div><div><p class="text-muted-foreground text-[10px] font-medium uppercase">Attempts</p><p class="mt-1">{{ lock.failed_attempts_count }}</p></div><div><p class="text-muted-foreground text-[10px] font-medium uppercase">Locked at</p><p class="mt-1">{{ formatDateTime(lock.locked_at) }}</p></div><div class="col-span-2"><p class="text-muted-foreground text-[10px] font-medium uppercase">Reason</p><p class="mt-1">{{ lock.reason }}</p></div></div></div></DirectoryRow></div>

            <template #footer><div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"><div class="text-muted-foreground flex items-center gap-2 text-sm">Display <Select v-model="filterForm.per_page" @update:model-value="applyFilters"><SelectTrigger class="h-9 w-20"><SelectValue /></SelectTrigger><SelectContent><SelectItem :value="15">15</SelectItem><SelectItem :value="25">25</SelectItem><SelectItem :value="50">50</SelectItem></SelectContent></Select> per page</div><div class="flex items-center justify-between gap-3 sm:justify-end"><span class="text-muted-foreground text-xs">Page {{ locks.current_page }} of {{ locks.last_page }}</span><div class="flex gap-2"><Link v-if="locks.prev_page_url" :href="locks.prev_page_url" preserve-state preserve-scroll><Button variant="outline" size="sm"><ChevronLeft /> Prev</Button></Link><Button v-else variant="outline" size="sm" disabled><ChevronLeft /> Prev</Button><Link v-if="locks.next_page_url" :href="locks.next_page_url" preserve-state preserve-scroll><Button size="sm">Next <ChevronRight /></Button></Link><Button v-else size="sm" disabled>Next <ChevronRight /></Button></div></div></div></template>
        </DirectoryPanel>

        <!-- Manual Unlock Confirmation Dialog -->
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
                    <DialogTitle class="flex items-center gap-2">
                        <Unlock class="text-primary size-5" />
                        Confirm Manual Unlock
                    </DialogTitle>
                    <DialogDescription>
                        Manually clear the temporary restriction for
                        <strong>{{
                            selectedLock?.user_name || selectedLock?.email
                        }}</strong
                        >. Per security policy, this does not change passwords,
                        MFA, permissions, or account status.
                    </DialogDescription>
                </DialogHeader>

                <form @submit.prevent="submitUnlock" class="space-y-4 py-2">
                    <div class="space-y-2">
                        <Label>Restriction Category</Label>
                        <div class="flex items-center gap-2">
                            <Badge
                                :variant="
                                    getCategoryBadgeVariant(formState.category)
                                "
                                class="font-medium capitalize"
                            >
                                {{ formState.category.replace('_', ' ') }}
                            </Badge>
                            <span class="text-muted-foreground text-xs">
                                Only this specific restriction will be cleared.
                            </span>
                        </div>
                        <InputError :message="serverErrors.category" />
                    </div>

                    <div class="space-y-2">
                        <Label for="verification-method"
                            >Verification Method
                            <span class="text-destructive">*</span></Label
                        >
                        <Select v-model="formState.verification_method">
                            <SelectTrigger
                                id="verification-method"
                                class="w-full"
                                aria-label="Verification method"
                            >
                                <SelectValue
                                    placeholder="Select verified method"
                                />
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
                        <Label for="unlock-reason"
                            >Verification Detail & Reason
                            <span class="text-destructive">*</span></Label
                        >
                        <Input
                            id="unlock-reason"
                            v-model="formState.reason"
                            placeholder="e.g. Identity verified via registered phone callback with customer"
                            maxlength="255"
                            :class="{
                                'border-destructive':
                                    v$.reason.$error || serverErrors.reason,
                            }"
                        />
                        <p class="text-muted-foreground text-xs">
                            Detail the completed verification protocol (5–255
                            characters). Do not record secrets, credentials, or
                            document contents.
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

                    <DialogFooter class="mt-4 gap-2 sm:gap-0">
                        <Button
                            type="button"
                            variant="outline"
                            @click="closeUnlockDialog"
                            :disabled="isSubmitting"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            :disabled="isSubmitting || v$.$invalid"
                        >
                            <Unlock class="mr-1.5 size-4" />
                            Confirm Unlock
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
