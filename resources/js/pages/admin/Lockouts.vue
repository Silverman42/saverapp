<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
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
    can_unlock: boolean;
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
}>();

defineOptions({
    layout: {
        breadcrumbs: [
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

const formState = reactive({
    reason: '',
    category: '',
});

const rules = computed(() => ({
    reason: {
        required,
        minLength: minLength(5),
    },
}));

const v$ = useVuelidate(rules, formState);

const openUnlockDialog = (lock: LockItem) => {
    selectedLock.value = lock;
    formState.reason = 'Identity verified following security protocol';
    formState.category = lock.lock_category;
    v$.value.$reset();
    isUnlockDialogOpen.value = true;
};

const closeUnlockDialog = () => {
    isUnlockDialogOpen.value = false;
    selectedLock.value = null;
    v$.value.$reset();
};

const submitUnlock = async () => {
    const isValid = await v$.value.$validate();
    if (!isValid || !selectedLock.value || !selectedLock.value.user_id) {
        return;
    }

    isSubmitting.value = true;

    router.post(
        `/admin/lockouts/${selectedLock.value.user_id}/unlock`,
        {
            reason: formState.reason,
            category: formState.category || null,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                closeUnlockDialog();
                toast.success('Account restriction cleared successfully.');
            },
            onError: (errors) => {
                const message = errors.user || Object.values(errors)[0] || 'Failed to unlock account.';
                toast.error(message as string);
            },
            onFinish: () => {
                isSubmitting.value = false;
            },
        }
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
    <div class="flex flex-1 flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-8 font-sans">
        <Head title="Security Lockouts & Abuse Monitoring" />

        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <Badge variant="outline" class="mb-3 border-primary/20 bg-accent text-accent-foreground">
                    Security Operations
                </Badge>
                <h1 class="text-3xl font-semibold tracking-tight">Security Lockouts</h1>
                <p class="mt-1.5 text-sm text-muted-foreground">
                    Monitor temporary authentication restrictions, sliding abuse counters, and perform authorized manual unlocks.
                </p>
            </div>

            <div class="flex items-center gap-2">
                <Button variant="outline" size="sm" @click="refreshData">
                    <RefreshCw class="size-4" />
                    Refresh
                </Button>
            </div>
        </div>

        <div v-if="props.locks.data.length === 0" class="flex min-h-64 flex-col items-center justify-center rounded-xl border border-dashed p-8 text-center">
            <div class="flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
                <ShieldCheck class="size-6 text-green-600 dark:text-green-400" />
            </div>
            <h3 class="mt-4 text-base font-semibold">No Authentication Locks</h3>
            <p class="mt-1.5 text-sm text-muted-foreground max-w-md">
                There are currently no active temporary locks or recent abuse incidents recorded in the system.
            </p>
        </div>

        <div v-else class="space-y-4">
            <div
                v-for="lock in props.locks.data"
                :key="lock.id"
                class="rounded-xl border bg-card p-5 text-card-foreground shadow-sm transition-colors hover:border-border/80"
            >
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="space-y-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold text-base">{{ lock.user_name || lock.email }}</span>
                            <span v-if="lock.user_name" class="text-xs text-muted-foreground">({{ lock.email }})</span>
                            <Badge v-if="lock.user_type" variant="outline" class="capitalize text-xs">
                                {{ lock.user_type }}
                            </Badge>
                            <Badge :variant="getCategoryBadgeVariant(lock.lock_category)" class="capitalize text-xs">
                                {{ lock.lock_category.replace('_', ' ') }} lock
                            </Badge>
                            <Badge v-if="lock.requires_review" variant="destructive" class="text-xs">
                                Review Required
                            </Badge>
                            <Badge v-if="lock.is_active" variant="destructive" class="text-xs">
                                Active restriction
                            </Badge>
                            <Badge v-else-if="lock.unlocked_at" variant="outline" class="border-green-500/30 text-green-700 dark:text-green-400 text-xs">
                                Manually unlocked
                            </Badge>
                            <Badge v-else variant="secondary" class="text-xs">
                                Expired
                            </Badge>
                        </div>

                        <p class="text-sm text-foreground/90 font-medium">{{ lock.reason }}</p>

                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                            <span>Attempts: <strong class="text-foreground">{{ lock.failed_attempts_count }}</strong></span>
                            <span>Source IP: <strong class="font-mono text-foreground">{{ lock.masked_ip }}</strong></span>
                            <span>Device: <strong class="text-foreground">{{ lock.device_context }}</strong></span>
                            <span>Locked at: <strong class="text-foreground">{{ formatDateTime(lock.locked_at) }}</strong></span>
                            <span v-if="lock.locked_until">Expires: <strong class="text-foreground">{{ formatDateTime(lock.locked_until) }}</strong></span>
                        </div>

                        <div v-if="lock.unlocked_at" class="rounded-md bg-muted/50 p-2 text-xs text-muted-foreground">
                            Unlocked by <strong>{{ lock.unlocked_by || 'Administrator' }}</strong> on {{ formatDateTime(lock.unlocked_at) }}
                            <span v-if="lock.unlock_reason"> — Reason: {{ lock.unlock_reason }}</span>
                        </div>
                    </div>

                    <div class="flex sm:flex-col items-end gap-2 shrink-0">
                        <Button
                            v-if="lock.can_unlock"
                            variant="outline"
                            size="sm"
                            class="gap-1.5 border-primary/30 hover:bg-accent"
                            @click="openUnlockDialog(lock)"
                        >
                            <Unlock class="size-3.5" />
                            Manual Unlock
                        </Button>
                        <span v-else-if="lock.is_active" class="text-xs text-muted-foreground italic">
                            Cannot unlock
                        </span>
                    </div>
                </div>
            </div>

            <!-- Pagination controls -->
            <div v-if="props.locks.last_page > 1" class="flex items-center justify-between pt-4">
                <p class="text-xs text-muted-foreground">
                    Showing page {{ props.locks.current_page }} of {{ props.locks.last_page }} ({{ props.locks.total }} total)
                </p>
                <div class="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="!props.locks.prev_page_url"
                        as-child
                    >
                        <Link v-if="props.locks.prev_page_url" :href="props.locks.prev_page_url">Previous</Link>
                        <span v-else>Previous</span>
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="!props.locks.next_page_url"
                        as-child
                    >
                        <Link v-if="props.locks.next_page_url" :href="props.locks.next_page_url">Next</Link>
                        <span v-else>Next</span>
                    </Button>
                </div>
            </div>
        </div>

        <!-- Manual Unlock Confirmation Dialog -->
        <Dialog :open="isUnlockDialogOpen" @update:open="(val) => { if (!val) closeUnlockDialog(); }">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        <Unlock class="size-5 text-primary" />
                        Confirm Manual Unlock
                    </DialogTitle>
                    <DialogDescription>
                        Manually clear the temporary restriction for <strong>{{ selectedLock?.user_name || selectedLock?.email }}</strong>.
                        Per security policy, this does not change passwords, MFA, permissions, or account status.
                    </DialogDescription>
                </DialogHeader>

                <form @submit.prevent="submitUnlock" class="space-y-4 py-2">
                    <div class="space-y-2">
                        <Label for="unlock-reason">Verification Reason <span class="text-destructive">*</span></Label>
                        <Input
                            id="unlock-reason"
                            v-model="formState.reason"
                            placeholder="e.g. Identity verified via telephone verification"
                            :class="{ 'border-destructive': v$.reason.$error }"
                        />
                        <InputError :message="v$.reason.$errors[0]?.$message as string" />
                    </div>

                    <div class="space-y-2">
                        <Label for="unlock-category">Restriction Category</Label>
                        <Input
                            id="unlock-category"
                            v-model="formState.category"
                            placeholder="Leave empty to clear all temporary locks"
                        />
                        <p class="text-xs text-muted-foreground">
                            Target category: <code>password</code>, <code>mfa</code>, or <code>recovery_code</code> (leave blank for all).
                        </p>
                    </div>

                    <DialogFooter class="mt-4 gap-2 sm:gap-0">
                        <Button type="button" variant="outline" @click="closeUnlockDialog" :disabled="isSubmitting">
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="isSubmitting || v$.$invalid">
                            <Unlock class="size-4 mr-1.5" />
                            Confirm Unlock
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
