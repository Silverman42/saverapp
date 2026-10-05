<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Mail, Pencil, RefreshCw, XCircle } from '@lucide/vue';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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

const mode = ref<'idle' | 'correct' | 'cancel'>('idle');
const resendForm = useForm<{ resend?: string }>({});
const correctForm = useForm({ email: props.currentEmail, reason: '' });
const cancelForm = useForm({ reason: '' });

const formatDate = (value: string | null): string =>
    value ? new Date(value).toLocaleString() : '—';
const statusLabel = (value: string): string => value.replaceAll('_', ' ');

const resend = (): void => {
    resendForm.post(resendInvitation(props.adminId).url, {
        preserveScroll: true,
    });
};
const submitCorrection = (): void => {
    correctForm.post(correctInvitationEmail(props.adminId).url, {
        preserveScroll: true,
        onSuccess: () => (mode.value = 'idle'),
    });
};
const submitCancel = (): void => {
    cancelForm.post(cancelInvitation(props.adminId).url, {
        preserveScroll: true,
        onSuccess: () => (mode.value = 'idle'),
    });
};
</script>

<template>
    <Card>
        <CardHeader class="pb-3">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <CardTitle
                        class="flex items-center gap-2 text-base font-semibold"
                    >
                        <Mail class="size-4" /> Invitation
                    </CardTitle>
                    <CardDescription>
                        This Administrator has not activated yet. If you resend
                        the invitation or correct the email, all earlier links
                        stop working.
                    </CardDescription>
                </div>
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
            </div>
        </CardHeader>
        <CardContent class="space-y-4">
            <dl class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-muted-foreground text-xs font-medium">
                        Delivery
                    </dt>
                    <dd class="mt-1 capitalize">
                        {{ statusLabel(invitation.delivery_status) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-xs font-medium">
                        Generation
                    </dt>
                    <dd class="mt-1 font-mono">#{{ invitation.generation }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-xs font-medium">
                        Issued
                    </dt>
                    <dd class="mt-1">{{ formatDate(invitation.issued_at) }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-xs font-medium">
                        Expires
                    </dt>
                    <dd class="mt-1">
                        {{ formatDate(invitation.expires_at) }}
                    </dd>
                </div>
            </dl>

            <p v-if="resendForm.errors.resend" class="text-destructive text-xs">
                {{ resendForm.errors.resend }}
            </p>

            <div class="flex flex-wrap items-center gap-3 border-t pt-4">
                <Button
                    v-if="invitation.can_resend"
                    variant="outline"
                    size="sm"
                    :disabled="resendForm.processing"
                    @click="resend"
                >
                    <RefreshCw
                        class="mr-1.5 size-3.5"
                        :class="{ 'animate-spin': resendForm.processing }"
                    />
                    Resend invitation
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    @click="mode = mode === 'correct' ? 'idle' : 'correct'"
                >
                    <Pencil class="mr-1.5 size-3.5" />
                    Correct email
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    class="text-destructive hover:bg-destructive/10"
                    @click="mode = mode === 'cancel' ? 'idle' : 'cancel'"
                >
                    <XCircle class="mr-1.5 size-3.5" />
                    Cancel invitation
                </Button>
            </div>

            <form
                v-if="mode === 'correct'"
                class="grid gap-3 sm:grid-cols-2"
                @submit.prevent="submitCorrection"
            >
                <div class="space-y-1.5">
                    <Label for="invitation-email">Corrected email</Label>
                    <Input
                        id="invitation-email"
                        v-model="correctForm.email"
                        type="email"
                        required
                        maxlength="254"
                    />
                    <p
                        v-if="correctForm.errors.email"
                        class="text-destructive text-xs"
                    >
                        {{ correctForm.errors.email }}
                    </p>
                </div>
                <div class="space-y-1.5">
                    <Label for="correction-reason">Reason</Label>
                    <Input
                        id="correction-reason"
                        v-model="correctForm.reason"
                        required
                        maxlength="500"
                    />
                    <p
                        v-if="correctForm.errors.reason"
                        class="text-destructive text-xs"
                    >
                        {{ correctForm.errors.reason }}
                    </p>
                </div>
                <Button
                    type="submit"
                    size="sm"
                    class="w-fit"
                    :disabled="correctForm.processing"
                >
                    Correct and resend
                </Button>
            </form>

            <form
                v-if="mode === 'cancel'"
                class="space-y-3"
                @submit.prevent="submitCancel"
            >
                <div class="space-y-1.5">
                    <Label for="cancel-reason">Reason for cancelling</Label>
                    <Input
                        id="cancel-reason"
                        v-model="cancelForm.reason"
                        required
                        maxlength="500"
                    />
                    <p
                        v-if="cancelForm.errors.reason"
                        class="text-destructive text-xs"
                    >
                        {{ cancelForm.errors.reason }}
                    </p>
                </div>
                <p class="text-muted-foreground text-xs">
                    The account stays Invited. It cannot activate until you send
                    a new invitation.
                </p>
                <Button
                    type="submit"
                    variant="destructive"
                    size="sm"
                    :disabled="cancelForm.processing"
                >
                    Cancel invitation
                </Button>
            </form>
        </CardContent>
    </Card>
</template>
