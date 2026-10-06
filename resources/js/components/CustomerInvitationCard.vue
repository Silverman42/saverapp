<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Loader2, Mail, RotateCw, XCircle } from '@lucide/vue';
import { ref } from 'vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    cancel as cancelInvitation,
    correctEmail as correctEmailInvitation,
    resend as resendInvitation,
} from '@/routes/customers/invitations';

export type CustomerInvitation = {
    status: string;
    status_label: string;
    delivery_status: string;
    delivery_status_label: string;
    generation: number;
    can_resend: boolean;
    sent_at: string | null;
    opened_at: string | null;
    expires_at: string | null;
    delivery_error: string | null;
};

/**
 * Shows a customer's account invite and lets staff resend it,
 * change the email address or cancel it.
 */
const props = defineProps<{
    customerId: string;
    customerName: string;
    customerEmail: string | null;
    invitation: CustomerInvitation;
    canManage: boolean;
}>();

const showCorrectEmailModal = ref(false);
const showCancelModal = ref(false);

const emailForm = useForm({
    email: '',
    reason: '',
});

const cancelForm = useForm({
    reason: '',
});

const textareaClass =
    'border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50';

const handleResendInvitation = (): void => {
    if (!props.invitation.can_resend) {
        return;
    }

    router.post(
        resendInvitation(props.customerId).url,
        {},
        { preserveScroll: true },
    );
};

const openCorrectEmailModal = (): void => {
    emailForm.reset();
    emailForm.clearErrors();
    emailForm.email = props.customerEmail || '';
    showCorrectEmailModal.value = true;
};

const submitCorrectEmail = (): void => {
    emailForm.post(correctEmailInvitation(props.customerId).url, {
        preserveScroll: true,
        onSuccess: () => {
            showCorrectEmailModal.value = false;
            emailForm.reset();
        },
    });
};

const openCancelModal = (): void => {
    cancelForm.reset();
    cancelForm.clearErrors();
    showCancelModal.value = true;
};

const submitCancelInvitation = (): void => {
    cancelForm.post(cancelInvitation(props.customerId).url, {
        preserveScroll: true,
        onSuccess: () => {
            showCancelModal.value = false;
            cancelForm.reset();
        },
    });
};

const getInvitationBadgeVariant = (
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' => {
    switch (status) {
        case 'activated':
            return 'default';
        case 'opened':
        case 'sent':
            return 'secondary';
        case 'expired':
        case 'cancelled':
            return 'destructive';
        default:
            return 'outline';
    }
};
</script>

<template>
    <Card>
        <CardHeader
            class="flex flex-row flex-wrap items-center justify-between gap-3"
        >
            <CardTitle class="text-base">Account invite</CardTitle>
            <Badge :variant="getInvitationBadgeVariant(invitation.status)">
                {{ invitation.status_label }}
            </Badge>
        </CardHeader>
        <CardContent class="space-y-4">
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-muted-foreground">Sent</dt>
                    <dd class="mt-0.5">
                        {{ invitation.sent_at || 'Not sent yet' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Expires</dt>
                    <dd class="mt-0.5">{{ invitation.expires_at || '-' }}</dd>
                </div>
            </dl>

            <div
                v-if="invitation.delivery_error"
                role="alert"
                class="bg-destructive/10 text-destructive rounded-lg p-3 text-sm"
            >
                <p class="font-medium">The invite could not be delivered.</p>
                <p class="mt-0.5 text-xs">{{ invitation.delivery_error }}</p>
            </div>

            <div v-if="canManage" class="flex flex-wrap items-center gap-2">
                <Button
                    v-if="invitation.can_resend"
                    variant="outline"
                    size="sm"
                    @click="handleResendInvitation"
                >
                    <RotateCw class="size-3.5" />
                    Resend
                </Button>
                <Button
                    v-else
                    variant="outline"
                    size="sm"
                    disabled
                    title="Please wait before sending again, or try tomorrow."
                >
                    <RotateCw class="size-3.5" />
                    Resend later
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    @click="openCorrectEmailModal"
                >
                    <Mail class="size-3.5" />
                    Change email
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    class="text-destructive"
                    @click="openCancelModal"
                >
                    <XCircle class="size-3.5" />
                    Cancel invite
                </Button>
            </div>

            <MoreDetails>
                <dl class="text-muted-foreground grid gap-2 text-xs">
                    <div class="flex justify-between gap-4">
                        <dt>Delivery</dt>
                        <dd>{{ invitation.delivery_status_label }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt>Times sent</dt>
                        <dd>{{ invitation.generation }}</dd>
                    </div>
                    <div
                        v-if="invitation.opened_at"
                        class="flex justify-between gap-4"
                    >
                        <dt>Opened</dt>
                        <dd>{{ invitation.opened_at }}</dd>
                    </div>
                    <p>Times are Lagos time.</p>
                </dl>
            </MoreDetails>
        </CardContent>

        <Dialog
            :open="showCorrectEmailModal"
            @update:open="showCorrectEmailModal = $event"
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Change email</DialogTitle>
                    <DialogDescription>
                        We will send a new invite to {{ customerName }}. Old
                        invite links will stop working.
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="submitCorrectEmail">
                    <div class="space-y-1.5">
                        <Label for="correct-customer-email"
                            >New email
                            <span class="text-destructive">*</span></Label
                        >
                        <Input
                            id="correct-customer-email"
                            v-model="emailForm.email"
                            type="email"
                            required
                            placeholder="customer@example.ng"
                            :class="{
                                'border-destructive': emailForm.errors.email,
                            }"
                        />
                        <p
                            v-if="emailForm.errors.email"
                            class="text-destructive text-xs"
                        >
                            {{ emailForm.errors.email }}
                        </p>
                    </div>

                    <div class="space-y-1.5">
                        <Label for="correct-customer-email-reason"
                            >Reason
                            <span class="text-destructive">*</span></Label
                        >
                        <textarea
                            id="correct-customer-email-reason"
                            v-model="emailForm.reason"
                            rows="2"
                            required
                            placeholder="For example: typo in the first email"
                            :class="[
                                textareaClass,
                                {
                                    'border-destructive':
                                        emailForm.errors.reason,
                                },
                            ]"
                        />
                        <p
                            v-if="emailForm.errors.reason"
                            class="text-destructive text-xs"
                        >
                            {{ emailForm.errors.reason }}
                        </p>
                    </div>

                    <DialogFooter class="gap-2 sm:gap-0">
                        <Button
                            type="button"
                            variant="outline"
                            @click="showCorrectEmailModal = false"
                            >Cancel</Button
                        >
                        <Button type="submit" :disabled="emailForm.processing">
                            <Loader2
                                v-if="emailForm.processing"
                                class="size-4 animate-spin"
                            />
                            Save and resend
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog :open="showCancelModal" @update:open="showCancelModal = $event">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Cancel invite?</DialogTitle>
                    <DialogDescription>
                        {{ customerName }} will not be able to set up their
                        account with the current invite links.
                    </DialogDescription>
                </DialogHeader>
                <form
                    class="space-y-4"
                    @submit.prevent="submitCancelInvitation"
                >
                    <div class="space-y-1.5">
                        <Label for="cancel-customer-invitation-reason"
                            >Reason
                            <span class="text-destructive">*</span></Label
                        >
                        <textarea
                            id="cancel-customer-invitation-reason"
                            v-model="cancelForm.reason"
                            rows="2"
                            required
                            placeholder="For example: customer no longer wants to join"
                            :class="[
                                textareaClass,
                                {
                                    'border-destructive':
                                        cancelForm.errors.reason,
                                },
                            ]"
                        />
                        <p
                            v-if="cancelForm.errors.reason"
                            class="text-destructive text-xs"
                        >
                            {{ cancelForm.errors.reason }}
                        </p>
                    </div>

                    <DialogFooter class="gap-2 sm:gap-0">
                        <Button
                            type="button"
                            variant="outline"
                            @click="showCancelModal = false"
                            >Keep invite</Button
                        >
                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="cancelForm.processing"
                        >
                            <Loader2
                                v-if="cancelForm.processing"
                                class="size-4 animate-spin"
                            />
                            Cancel invite
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </Card>
</template>
