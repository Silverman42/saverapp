<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import {
    Check,
    LifeBuoy,
    MoreHorizontal,
    RefreshCw,
    X,
    XCircle,
} from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import {
    decide,
    index as recoveriesIndex,
} from '@/routes/admin/staff-recoveries';

type Recovery = {
    reference: string;
    user: { id: number; name: string; type: string };
    requested_by: string;
    state: string;
    version: number;
    approvals: number;
    required_approvals: number;
    created_at: string | null;
    request_expires_at: string;
    activation_expires_at: string | null;
    can_decide: boolean;
    can_cancel: boolean;
    can_reissue: boolean;
};
type Action = 'approve' | 'reject' | 'cancel' | 'reissue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Account recoveries', href: recoveriesIndex() },
        ],
    },
});

defineProps<{ recoveries: Recovery[] }>();

const active = ref<{
    reference: string;
    name: string;
    action: Action;
} | null>(null);
const dialogOpen = ref(false);
const form = useForm({ version: 0, reason: '' });

const actionCopy: Record<
    Action,
    { title: string; description: string; button: string; destructive: boolean }
> = {
    approve: {
        title: 'Approve recovery',
        description:
            'When enough admins approve, they get a link to sign in again.',
        button: 'Approve',
        destructive: false,
    },
    reject: {
        title: 'Reject recovery',
        description: 'The request will close and nothing changes.',
        button: 'Reject',
        destructive: true,
    },
    cancel: {
        title: 'Cancel recovery',
        description: 'The request will close and any link stops working.',
        button: 'Cancel request',
        destructive: true,
    },
    reissue: {
        title: 'Send a new link',
        description: 'The old link stops working.',
        button: 'Send new link',
        destructive: false,
    },
};
const activeCopy = computed(() =>
    active.value ? actionCopy[active.value.action] : null,
);

const open = (recovery: Recovery, action: Action): void => {
    active.value = {
        reference: recovery.reference,
        name: recovery.user.name,
        action,
    };
    form.version = recovery.version;
    form.reason = '';
    form.clearErrors();
    dialogOpen.value = true;
};

const submit = (): void => {
    if (!active.value) return;
    form.post(
        decide.url({
            recovery: active.value.reference,
            action: active.value.action,
        }),
        {
            preserveScroll: true,
            onSuccess: () => {
                dialogOpen.value = false;
                active.value = null;
            },
        },
    );
};

const label = (value: string): string => value.replaceAll('_', ' ');
const formatDate = (value: string | null): string =>
    value ? new Date(value).toLocaleString() : '-';
</script>

<template>
    <Head title="Account recoveries" />

    <div class="space-y-6">
        <PageHeader
            title="Account recoveries"
            description="Approve or reject requests to help staff get back in."
        />

        <EmptyState
            v-if="recoveries.length === 0"
            :icon="LifeBuoy"
            title="No requests right now"
            description="New recovery requests will show up here."
        />

        <Card v-else>
            <CardContent>
                <ul class="divide-border divide-y">
                    <li
                        v-for="recovery in recoveries"
                        :key="recovery.reference"
                        class="flex flex-wrap items-center justify-between gap-4 py-4 first:pt-0 last:pb-0"
                    >
                        <div class="min-w-0 space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="font-medium">
                                    {{ recovery.user.name }}
                                </p>
                                <span
                                    class="text-muted-foreground text-sm capitalize"
                                    >{{ recovery.user.type }}</span
                                >
                                <Badge variant="outline" class="capitalize">{{
                                    label(recovery.state)
                                }}</Badge>
                            </div>
                            <p class="text-muted-foreground text-xs">
                                Requested by {{ recovery.requested_by }} on
                                {{ formatDate(recovery.created_at) }}
                            </p>
                            <p class="text-muted-foreground text-xs">
                                {{ recovery.approvals }} of
                                {{ recovery.required_approvals }} approvals ·
                                Request ends
                                {{ formatDate(recovery.request_expires_at) }}
                                <template v-if="recovery.activation_expires_at">
                                    · Link ends
                                    {{
                                        formatDate(
                                            recovery.activation_expires_at,
                                        )
                                    }}
                                </template>
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <Button
                                v-if="recovery.can_decide"
                                size="sm"
                                @click="open(recovery, 'approve')"
                            >
                                <Check class="size-4" />
                                Approve
                            </Button>
                            <Button
                                v-else-if="recovery.can_reissue"
                                size="sm"
                                variant="outline"
                                @click="open(recovery, 'reissue')"
                            >
                                <RefreshCw class="size-4" />
                                Send new link
                            </Button>
                            <DropdownMenu
                                :modal="false"
                                v-if="
                                    recovery.can_decide || recovery.can_cancel
                                "
                            >
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        variant="outline"
                                        size="icon"
                                        class="size-8"
                                        :aria-label="`More actions for ${recovery.user.name}`"
                                    >
                                        <MoreHorizontal class="size-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        v-if="recovery.can_decide"
                                        @select="open(recovery, 'reject')"
                                    >
                                        <X class="size-4" />
                                        Reject
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="
                                            recovery.can_decide &&
                                            recovery.can_reissue
                                        "
                                        @select="open(recovery, 'reissue')"
                                    >
                                        <RefreshCw class="size-4" />
                                        Send new link
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="recovery.can_cancel"
                                        class="text-destructive"
                                        @select="open(recovery, 'cancel')"
                                    >
                                        <XCircle class="size-4" />
                                        Cancel request
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </li>
                </ul>
            </CardContent>
        </Card>

        <p class="text-muted-foreground text-xs">
            You cannot approve a request you made, or one for your own account.
            Admin recoveries need two approvals when two admins are available.
        </p>

        <Dialog v-model:open="dialogOpen">
            <DialogContent v-if="active && activeCopy" class="sm:max-w-md">
                <form class="space-y-5" @submit.prevent="submit">
                    <DialogHeader>
                        <DialogTitle>{{ activeCopy.title }}</DialogTitle>
                        <DialogDescription>
                            For {{ active.name }}.
                            {{ activeCopy.description }}
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-2">
                        <Label :for="`reason-${active.reference}`"
                            >Reason</Label
                        >
                        <Input
                            :id="`reason-${active.reference}`"
                            v-model="form.reason"
                            required
                            maxlength="500"
                        />
                    </div>
                    <ul
                        v-if="form.hasErrors"
                        role="alert"
                        class="text-destructive space-y-1 text-xs"
                    >
                        <li v-for="(error, field) in form.errors" :key="field">
                            {{ error }}
                        </li>
                    </ul>
                    <DialogFooter class="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            @click="dialogOpen = false"
                            >Close</Button
                        >
                        <Button
                            type="submit"
                            :variant="
                                activeCopy.destructive
                                    ? 'destructive'
                                    : 'default'
                            "
                            :disabled="form.processing"
                        >
                            {{ activeCopy.button }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
