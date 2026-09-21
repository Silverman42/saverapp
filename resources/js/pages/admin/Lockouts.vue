<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { unlock } from '@/actions/App/Http/Controllers/Admin/LockoutController';
import { dashboard } from '@/routes';
import { useVuelidate } from '@vuelidate/core';
import { minLength, required } from '@vuelidate/validators';
import {
    AlertTriangle,
    CheckCircle2,
    Clock,
    KeyRound,
    Lock,
    RefreshCw,
    ShieldAlert,
    ShieldCheck,
    Unlock,
    User as UserIcon,
} from '@lucide/vue';
import { toast } from 'vue-sonner';
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

const props = defineProps<{
    locks: PaginatedLocks;
    verification_methods: VerificationMethodOption[];
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
                href: '/admin/lockouts',
            },
        ],
    },
});

const isUnlockDialogOpen = ref(false);
const selectedLock = ref<LockItem | null>(null);
const isSubmitting = ref(false);
const serverErrors = ref<Record<string, string>>({});

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

        <div
            v-if="props.locks.data.length === 0"
            class="flex min-h-64 flex-col items-center justify-center rounded-xl border border-dashed p-8 text-center"
        >
            <div
                class="bg-muted text-muted-foreground flex size-12 items-center justify-center rounded-full"
            >
                <ShieldCheck
                    class="size-6 text-green-600 dark:text-green-400"
                />
            </div>
            <h3 class="mt-4 text-base font-semibold">
                No Authentication Locks
            </h3>
            <p class="text-muted-foreground mt-1.5 max-w-md text-sm">
                There are currently no active temporary locks or recent abuse
                incidents recorded in the system.
            </p>
        </div>

        <div v-else class="space-y-4">
            <div
                v-for="lock in props.locks.data"
                :key="lock.id"
                class="bg-card text-card-foreground hover:border-border/80 rounded-xl border p-5 shadow-sm transition-colors"
            >
                <div
                    class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div class="space-y-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-base font-semibold">{{
                                lock.user_name || lock.email
                            }}</span>
                            <span
                                v-if="lock.user_name"
                                class="text-muted-foreground text-xs"
                                >({{ lock.email }})</span
                            >
                            <Badge
                                v-if="lock.user_type"
                                variant="outline"
                                class="text-xs capitalize"
                            >
                                {{ lock.user_type }}
                            </Badge>
                            <Badge
                                :variant="
                                    getCategoryBadgeVariant(lock.lock_category)
                                "
                                class="text-xs capitalize"
                            >
                                {{ lock.lock_category.replace('_', ' ') }} lock
                            </Badge>
                            <Badge
                                v-if="lock.requires_review"
                                variant="destructive"
                                class="text-xs"
                            >
                                Review Required
                            </Badge>
                            <Badge
                                v-if="lock.is_active"
                                variant="destructive"
                                class="text-xs"
                            >
                                Active restriction
                            </Badge>
                            <Badge
                                v-else-if="lock.unlocked_at"
                                variant="outline"
                                class="border-green-500/30 text-xs text-green-700 dark:text-green-400"
                            >
                                Manually unlocked
                            </Badge>
                            <Badge v-else variant="secondary" class="text-xs">
                                Expired
                            </Badge>
                        </div>

                        <p class="text-foreground/90 text-sm font-medium">
                            {{ lock.reason }}
                        </p>

                        <div
                            class="text-muted-foreground flex flex-wrap items-center gap-x-4 gap-y-1 text-xs"
                        >
                            <span
                                >Attempts:
                                <strong class="text-foreground">{{
                                    lock.failed_attempts_count
                                }}</strong></span
                            >
                            <span
                                >Source IP:
                                <strong class="text-foreground font-mono">{{
                                    lock.masked_ip
                                }}</strong></span
                            >
                            <span
                                >Device:
                                <strong class="text-foreground">{{
                                    lock.device_context
                                }}</strong></span
                            >
                            <span
                                >Locked at:
                                <strong class="text-foreground">{{
                                    formatDateTime(lock.locked_at)
                                }}</strong></span
                            >
                            <span v-if="lock.locked_until"
                                >Expires:
                                <strong class="text-foreground">{{
                                    formatDateTime(lock.locked_until)
                                }}</strong></span
                            >
                        </div>

                        <div
                            v-if="lock.unlocked_at"
                            class="bg-muted/50 text-muted-foreground rounded-md p-2 text-xs"
                        >
                            Unlocked by
                            <strong>{{
                                lock.unlocked_by || 'Administrator'
                            }}</strong>
                            on {{ formatDateTime(lock.unlocked_at) }}
                            <span v-if="lock.unlock_verification_method_label">
                                via
                                <strong>{{
                                    lock.unlock_verification_method_label
                                }}</strong>
                            </span>
                            <span v-if="lock.unlock_reason">
                                — Detail: {{ lock.unlock_reason }}</span
                            >
                        </div>
                    </div>

                    <div class="flex shrink-0 items-end gap-2 sm:flex-col">
                        <Button
                            v-if="lock.can_unlock"
                            variant="outline"
                            size="sm"
                            class="border-primary/30 hover:bg-accent gap-1.5"
                            @click="openUnlockDialog(lock)"
                        >
                            <Unlock class="size-3.5" />
                            Manual Unlock
                        </Button>
                        <span
                            v-else-if="lock.is_active"
                            class="text-muted-foreground text-xs italic"
                        >
                            Cannot unlock
                        </span>
                    </div>
                </div>
            </div>

            <!-- Pagination controls -->
            <div
                v-if="props.locks.last_page > 1"
                class="flex items-center justify-between pt-4"
            >
                <p class="text-muted-foreground text-xs">
                    Showing page {{ props.locks.current_page }} of
                    {{ props.locks.last_page }} ({{ props.locks.total }} total)
                </p>
                <div class="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="!props.locks.prev_page_url"
                        as-child
                    >
                        <Link
                            v-if="props.locks.prev_page_url"
                            :href="props.locks.prev_page_url"
                            >Previous</Link
                        >
                        <span v-else>Previous</span>
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="!props.locks.next_page_url"
                        as-child
                    >
                        <Link
                            v-if="props.locks.next_page_url"
                            :href="props.locks.next_page_url"
                            >Next</Link
                        >
                        <span v-else>Next</span>
                    </Button>
                </div>
            </div>
        </div>

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
