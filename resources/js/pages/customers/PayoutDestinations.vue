<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
const decision = useForm({ note: '', confirmed: false });
const acting = ref<{
    reference: string;
    kind: 'verify' | 'reject' | 'revoke';
} | null>(null);

function register(): void {
    registration.post(storeDestination.url(props.customer.id), {
        preserveScroll: true,
        onSuccess: () => {
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
    exact: 'Provider name matches the Customer',
    partial: 'Provider name partly matches the Customer',
    mismatch: 'Provider name does not match the Customer',
};
</script>

<template>
    <Head title="Bank destinations" />
    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">
                Bank destinations
            </h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                Verified bank accounts owned by {{ customer.name }}. Full
                account numbers are never stored or shown.
            </p>
        </div>
        <Card>
            <CardHeader
                ><CardTitle>Registered destinations</CardTitle></CardHeader
            >
            <CardContent>
                <p
                    v-if="destinations.length === 0"
                    class="text-muted-foreground text-sm"
                >
                    No bank destination has been registered for this Customer.
                </p>
                <ul v-else class="divide-y">
                    <li
                        v-for="item in destinations"
                        :key="item.reference"
                        class="grid gap-2 py-4 text-sm first:pt-0 last:pb-0"
                    >
                        <p>
                            <strong>{{ item.bank_name }}</strong> ·
                            {{ item.account_mask }} · version
                            {{ item.version }} ·
                            {{ item.status.replaceAll('_', ' ') }}
                        </p>
                        <p v-if="item.payee_name">
                            Provider payee name: {{ item.payee_name }} ·
                            {{ matchText[item.name_match] }}
                        </p>
                        <div v-if="can_review" class="flex flex-wrap gap-3">
                            <Button
                                v-if="
                                    item.status === 'pending_verification' &&
                                    item.name_match !== 'mismatch'
                                "
                                type="button"
                                @click="choose(item.reference, 'verify')"
                                >Verify destination</Button
                            >
                            <Button
                                v-if="item.status === 'pending_verification'"
                                type="button"
                                variant="outline"
                                @click="choose(item.reference, 'reject')"
                                >Reject destination</Button
                            >
                            <Button
                                v-if="item.status === 'verified'"
                                type="button"
                                variant="outline"
                                @click="choose(item.reference, 'revoke')"
                                >Revoke destination</Button
                            >
                        </div>
                        <form
                            v-if="acting && acting.reference === item.reference"
                            class="grid gap-3 rounded-md border p-4"
                            @submit.prevent="commit"
                        >
                            <Label :for="`note-${item.reference}`"
                                >Reason for {{ acting.kind }}</Label
                            >
                            <Input
                                :id="`note-${item.reference}`"
                                v-model="decision.note"
                                maxlength="500"
                                required
                            />
                            <label class="flex gap-3"
                                ><input
                                    v-model="decision.confirmed"
                                    type="checkbox"
                                />
                                I confirm this decision.</label
                            >
                            <Button
                                class="w-fit"
                                :disabled="
                                    decision.processing || !decision.confirmed
                                "
                                >Commit decision</Button
                            >
                            <p
                                v-for="(error, key) in decision.errors"
                                :key="key"
                                class="text-destructive"
                                role="alert"
                            >
                                {{ error }}
                            </p>
                        </form>
                    </li>
                </ul>
            </CardContent>
        </Card>
        <Card v-if="can_register && bank_available">
            <CardHeader
                ><CardTitle>Register a destination</CardTitle></CardHeader
            >
            <CardContent>
                <form
                    class="grid gap-4 sm:grid-cols-2"
                    @submit.prevent="register"
                >
                    <div class="grid gap-2">
                        <Label for="bank-code">Bank code (3 digits)</Label>
                        <Input
                            id="bank-code"
                            v-model="registration.bank_code"
                            inputmode="numeric"
                            maxlength="3"
                            autocomplete="off"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="account-number"
                            >Account number (10 digits)</Label
                        >
                        <Input
                            id="account-number"
                            v-model="registration.account_number"
                            inputmode="numeric"
                            maxlength="10"
                            autocomplete="off"
                        />
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="attestation"
                            >Attestation of the Customer's instruction</Label
                        >
                        <Input
                            id="attestation"
                            v-model="registration.attestation"
                            maxlength="500"
                        />
                    </div>
                    <p class="text-muted-foreground text-sm sm:col-span-2">
                        The provider confirms the account holder's name. An
                        Admin must verify the destination before use. We delete
                        the number after the provider checks it.
                    </p>
                    <Button class="w-fit" :disabled="registration.processing"
                        >Register for review</Button
                    >
                    <p
                        v-for="(error, key) in registration.errors"
                        :key="key"
                        class="text-destructive text-sm sm:col-span-2"
                        role="alert"
                    >
                        {{ error }}
                    </p>
                </form>
            </CardContent>
        </Card>
        <p v-else-if="can_register" class="text-muted-foreground text-sm">
            Bank transfers are not enabled, so destinations cannot be registered
            yet.
        </p>
        <Link
            :href="customerShow(customer.id)"
            class="text-primary w-fit text-sm underline"
            >Back to Customer</Link
        >
    </div>
</template>
