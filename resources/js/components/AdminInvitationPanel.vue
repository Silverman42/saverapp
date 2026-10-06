<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Mail, MoreHorizontal, Pencil, RefreshCw, XCircle } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import MoreDetails from '@/components/MoreDetails.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import {
    cancel as cancelInvitation,
    correctEmail as correctInvitationEmail,
    resend as resendInvitation,
} from '@/routes/admin/access/invitations';

export type AdminInvitationSummary = {
    status: string;
    delivery_status: string;
    generation: number;
    issued_at: string | null;
    expires_at: string;
    is_expired: boolean;
    can_resend: boolean;
};

const props = defineProps<{
    adminId: number;
    currentEmail: string;
    invitation: AdminInvitationSummary;
}>();

const correctOpen = ref(false);
const cancelOpen = ref(false);
const resendForm = useForm<{ resend?: string }>({});
const correctForm = useForm({ email: props.currentEmail, reason: '' });
const cancelForm = useForm({ reason: '' });

const formatDate = (value: string | null): string =>
    value ? new Date(value).toLocaleString() : '-';
const statusLabel = (value: string): string => value.replaceAll('_', ' ');

const resend = (): void => {
    resendForm.post(resendInvitation(props.adminId).url, {
        preserveScroll: true,
    });
};
const submitCorrection = (): void => {
    correctForm.post(correctInvitationEmail(props.adminId).url, {
        preserveScroll: true,
        onSuccess: () => (correctOpen.value = false),
    });
};
const submitCancel = (): void => {
    cancelForm.post(cancelInvitation(props.adminId).url, {
        preserveScroll: true,
        onSuccess: () => (cancelOpen.value = false),
    });
};
</script>

<template>
    <Card>
        <CardHeader
            class="flex flex-row flex-wrap items-start justify-between gap-3"
        >
            <div class="space-y-1">
                <CardTitle class="flex items-center gap-2 text-base">
                    <Mail class="size-4" /> Invite not accepted yet
                </CardTitle>
                <p class="text-muted-foreground text-sm">
                    {{
                        invitation.is_expired
                            ? 'The link has expired. Send a new one.'
                            : `The link works until ${formatDate(invitation.expires_at)}.`
                    }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Badge
                    :variant="invitation.is_expired ? 'destructive' : 'outline'"
                    class="capitalize"
                >
                    {{
                        invitation.is_expired
                            ? 'expired'
                            : statusLabel(invitation.status)
                    }}
                </Badge>
                <Button
                    v-if="invitation.can_resend"
                    size="sm"
                    :disabled="resendForm.processing"
                    @click="resend"
                >
                    <RefreshCw
                        class="size-3.5"
                        :class="{ 'animate-spin': resendForm.processing }"
                    />
                    Resend
                </Button>
                <DropdownMenu :modal="false">
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="outline"
                            size="icon"
                            class="size-8"
                            aria-label="More invite actions"
                        >
                            <MoreHorizontal class="size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem @select="correctOpen = true">
                            <Pencil class="size-4" />
                            Change email
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            class="text-destructive"
                            @select="cancelOpen = true"
                        >
                            <XCircle class="size-4" />
                            Cancel invite
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </CardHeader>
        <CardContent class="space-y-3">
            <p class="text-muted-foreground text-xs">
                Sending again or changing the email stops older links from
                working.
            </p>
            <InputError :message="resendForm.errors.resend" />
            <MoreDetails>
                <dl class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-muted-foreground text-xs">Email</dt>
                        <dd class="mt-1 capitalize">
                            {{ statusLabel(invitation.delivery_status) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">Sent</dt>
                        <dd class="mt-1">
                            {{ formatDate(invitation.issued_at) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">
                            Times sent
                        </dt>
                        <dd class="mt-1">{{ invitation.generation }}</dd>
                    </div>
                </dl>
            </MoreDetails>
        </CardContent>

        <Dialog v-model:open="correctOpen">
            <DialogContent class="sm:max-w-md">
                <form class="space-y-5" @submit.prevent="submitCorrection">
                    <DialogHeader>
                        <DialogTitle>Change email</DialogTitle>
                        <DialogDescription>
                            We will send a new invite to this address.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-2">
                        <Label for="invitation-email">New email</Label>
                        <Input
                            id="invitation-email"
                            v-model="correctForm.email"
                            type="email"
                            required
                            maxlength="254"
                        />
                        <InputError :message="correctForm.errors.email" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="correction-reason">Reason</Label>
                        <Input
                            id="correction-reason"
                            v-model="correctForm.reason"
                            required
                            maxlength="500"
                        />
                        <InputError :message="correctForm.errors.reason" />
                    </div>
                    <DialogFooter class="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            @click="correctOpen = false"
                            >Close</Button
                        >
                        <Button type="submit" :disabled="correctForm.processing"
                            >Save and resend</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="cancelOpen">
            <DialogContent class="sm:max-w-md">
                <form class="space-y-5" @submit.prevent="submitCancel">
                    <DialogHeader>
                        <DialogTitle>Cancel invite?</DialogTitle>
                        <DialogDescription>
                            The link will stop working. They cannot join until
                            you send a new invite.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-2">
                        <Label for="cancel-reason">Reason</Label>
                        <Input
                            id="cancel-reason"
                            v-model="cancelForm.reason"
                            required
                            maxlength="500"
                        />
                        <InputError :message="cancelForm.errors.reason" />
                    </div>
                    <DialogFooter class="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            @click="cancelOpen = false"
                            >Keep invite</Button
                        >
                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="cancelForm.processing"
                            >Cancel invite</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </Card>
</template>
