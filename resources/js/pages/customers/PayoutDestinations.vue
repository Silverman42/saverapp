<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Wallet, Plus } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import FormSheet from '@/components/FormSheet.vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import {
    index as customersIndex,
    show as customerShow,
} from '@/routes/customers';
import { store as storeDestination } from '@/routes/customers/payout-destinations';
import { reject, revoke, verify } from '@/routes/payout-destinations';

type Destination = {
    reference: string;
    version: number;
    status: string;
    bank_name: string;
    account_mask: string;
    name_match: string;
    payee_name: string | null;
    verified_at: string | null;
};
const props = defineProps<{
    customer: { id: string; name: string };
    destinations: Destination[];
    can_register: boolean;
    can_review: boolean;
    bank_available: boolean;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Customers', href: customersIndex() },
            { title: 'Bank destinations', href: '#' },
        ],
    },
});
const registration = useForm({
    registration_reference: crypto.randomUUID(),
    bank_code: '',
    account_number: '',
    attestation: '',
});
const registrationOpen = ref(false);
const decision = useForm({ note: '', confirmed: false });
const acting = ref<{
    reference: string;
    kind: 'verify' | 'reject' | 'revoke';
} | null>(null);

function register(): void {
    registration.post(storeDestination.url(props.customer.id), {
        preserveScroll: true,
        onSuccess: () => {
            registrationOpen.value = false;
            registration.reset('account_number', 'attestation', 'bank_code');
            registration.registration_reference = crypto.randomUUID();
        },
        onError: () => registration.reset('account_number'),
    });
}
function choose(reference: string, kind: 'verify' | 'reject' | 'revoke'): void {
    acting.value = { reference, kind };
    decision.reset();
}
function commit(): void {
    if (!acting.value || !decision.confirmed) return;
    const route = { verify, reject, revoke }[acting.value.kind];
    decision.post(route.url(acting.value.reference), {
        preserveScroll: true,
        onSuccess: () => (acting.value = null),
    });
}
const matchText: Record<string, string> = {
    exact: 'Name matches the customer',
    partial: 'Name partly matches the customer',
    mismatch: 'Name does not match the customer',
};
const decisionText: Record<
    'verify' | 'reject' | 'revoke',
    { title: string; description: string; button: string }
> = {
    verify: {
        title: 'Approve bank account?',
        description: 'Payouts can then be sent to this account.',
        button: 'Approve',
    },
    reject: {
        title: 'Reject bank account?',
        description: 'This account will not be used for payouts.',
        button: 'Reject',
    },
    revoke: {
        title: 'Remove bank account?',
        description: 'No more payouts will go to this account.',
        button: 'Remove',
    },
};
</script>

<template>
    <Head title="Bank destinations" />
    <div class="flex flex-col gap-6">
        <PageHeader
            title="Bank accounts"
            :description="`Bank accounts ${customer.name} can be paid into.`"
        >
            <template #actions>
                <Button
                    v-if="can_register && bank_available"
                    @click="registrationOpen = true"
                    ><Plus class="size-4" /> Add bank account</Button
                >
                <Button as-child variant="outline">
                    <Link :href="customerShow(customer.id)"
                        >Back to customer</Link
                    >
                </Button>
            </template>
        </PageHeader>

        <p
            v-if="can_register && !bank_available"
            role="status"
            class="bg-muted rounded-xl p-4 text-sm"
        >
            Bank transfers are turned off, so you can't add a bank account yet.
        </p>

        <EmptyState
            v-if="destinations.length === 0"
            :icon="Wallet"
            title="No bank accounts yet"
            :description="
                can_register && bank_available
                    ? 'Add a bank account so this customer can be paid by transfer.'
                    : 'Bank accounts added for this customer will show here.'
            "
        />
        <Card v-else>
            <CardContent>
                <ul class="divide-y">
                    <li
                        v-for="item in destinations"
                        :key="item.reference"
                        class="flex flex-wrap items-start justify-between gap-3 py-4 text-sm first:pt-0 last:pb-0"
                    >
                        <div class="min-w-0 space-y-1">
                            <p class="font-medium">
                                {{ item.bank_name }} · {{ item.account_mask }}
                            </p>
                            <p
                                v-if="item.payee_name"
                                class="text-muted-foreground"
                            >
                                Account name: {{ item.payee_name }}
                            </p>
                            <p
                                v-if="item.payee_name"
                                :class="
                                    item.name_match === 'mismatch'
                                        ? 'text-destructive'
                                        : 'text-muted-foreground'
                                "
                            >
                                {{ matchText[item.name_match] }}
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge
                                :variant="
                                    item.status === 'verified'
                                        ? 'default'
                                        : 'secondary'
                                "
                                class="capitalize"
                                >{{ item.status.replaceAll('_', ' ') }}</Badge
                            >
                            <template v-if="can_review">
                                <Button
                                    v-if="
                                        item.status ===
                                            'pending_verification' &&
                                        item.name_match !== 'mismatch'
                                    "
                                    type="button"
                                    size="sm"
                                    @click="choose(item.reference, 'verify')"
                                    >Approve</Button
                                >
                                <Button
                                    v-if="
                                        item.status === 'pending_verification'
                                    "
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    @click="choose(item.reference, 'reject')"
                                    >Reject</Button
                                >
                                <Button
                                    v-if="item.status === 'verified'"
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    @click="choose(item.reference, 'revoke')"
                                    >Remove</Button
                                >
                            </template>
                        </div>
                    </li>
                </ul>
            </CardContent>
        </Card>

        <Dialog
            :open="acting !== null"
            @update:open="(open: boolean) => (open ? null : (acting = null))"
        >
            <DialogContent v-if="acting" class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{
                        decisionText[acting.kind].title
                    }}</DialogTitle>
                    <DialogDescription>{{
                        decisionText[acting.kind].description
                    }}</DialogDescription>
                </DialogHeader>
                <form class="grid gap-4" @submit.prevent="commit">
                    <div class="grid gap-2">
                        <Label :for="`note-${acting.reference}`">Reason</Label>
                        <Input
                            :id="`note-${acting.reference}`"
                            v-model="decision.note"
                            maxlength="500"
                            required
                        />
                    </div>
                    <label class="flex gap-3 text-sm"
                        ><input v-model="decision.confirmed" type="checkbox" />
                        I'm sure.</label
                    >
                    <p
                        v-for="(error, key) in decision.errors"
                        :key="key"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ error }}
                    </p>
                    <DialogFooter class="gap-2 sm:gap-0">
                        <Button
                            type="button"
                            variant="outline"
                            @click="acting = null"
                            >Cancel</Button
                        >
                        <Button
                            :variant="
                                acting.kind === 'verify'
                                    ? 'default'
                                    : 'destructive'
                            "
                            :disabled="
                                decision.processing || !decision.confirmed
                            "
                            >{{ decisionText[acting.kind].button }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <FormSheet
            v-if="can_register && bank_available"
            v-model:open="registrationOpen"
            title="Add bank account"
            description="An admin checks the account before it can be used."
        >
            <form
                id="register-destination"
                class="grid gap-5"
                @submit.prevent="register"
            >
                <div class="grid gap-2">
                    <Label for="bank-code">Bank code</Label>
                    <Input
                        id="bank-code"
                        v-model="registration.bank_code"
                        inputmode="numeric"
                        maxlength="3"
                        autocomplete="off"
                    />
                    <p class="text-muted-foreground text-xs">3 digits.</p>
                </div>
                <div class="grid gap-2">
                    <Label for="account-number">Account number</Label>
                    <Input
                        id="account-number"
                        v-model="registration.account_number"
                        inputmode="numeric"
                        maxlength="10"
                        autocomplete="off"
                    />
                    <p class="text-muted-foreground text-xs">
                        10 digits. We only keep the last few digits.
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="attestation">Customer's request</Label>
                    <Input
                        id="attestation"
                        v-model="registration.attestation"
                        maxlength="500"
                        placeholder="How the customer asked for this account"
                    />
                </div>
                <p
                    v-for="(error, key) in registration.errors"
                    :key="key"
                    class="text-destructive text-sm"
                    role="alert"
                >
                    {{ error }}
                </p>
            </form>
            <template #footer>
                <Button
                    type="button"
                    variant="outline"
                    @click="registrationOpen = false"
                    >Cancel</Button
                >
                <Button
                    type="submit"
                    form="register-destination"
                    :disabled="registration.processing"
                    >Send for review</Button
                >
            </template>
        </FormSheet>
    </div>
</template>
