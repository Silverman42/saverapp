<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import {
    AlertCircle,
    CheckCircle2,
    Coins,
    KeyRound,
    Loader2,
    ShieldAlert,
    ShieldCheck,
    UserCheck,
} from '@lucide/vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type FeeSnapshot = {
    name: string;
    amount_kobo: number;
    formatted_amount: string;
    currency: string;
    description: string | null;
    is_zero: boolean;
};

const props = defineProps<{
    status: 'ready' | 'expired' | 'cancelled' | 'already_activated' | 'invalid';
    token?: string;
    name?: string;
    email?: string;
    business_name: string;
    fee_snapshot?: FeeSnapshot | null;
}>();

const form = useForm({
    fee_acknowledged: false,
    password: '',
    password_confirmation: '',
});

const submit = (): void => {
    if (!props.token) return;

    form.post(`/invitations/customer/${props.token}`, {
        preserveScroll: true,
    });
};
</script>

<template>
    <div>
        <Head title="Activate Customer Account" />

        <div
            class="bg-muted/40 flex min-h-screen items-center justify-center p-4 sm:p-6 lg:p-8"
        >
            <div class="w-full max-w-lg space-y-6">
                <!-- Branding Header -->
                <div class="text-center">
                    <div
                        class="bg-primary text-primary-foreground mx-auto flex size-12 items-center justify-center rounded-2xl shadow-sm"
                    >
                        <UserCheck class="size-6" />
                    </div>
                    <h1 class="mt-4 text-2xl font-bold tracking-tight">
                        {{ business_name }}
                    </h1>
                    <p class="text-muted-foreground mt-1 text-sm">
                        Customer Account Activation
                    </p>
                </div>

                <!-- Ready State: Fee Terms & Password Form -->
                <Card v-if="status === 'ready'">
                    <CardHeader>
                        <CardTitle>Activate Your Account</CardTitle>
                        <CardDescription>
                            Hello
                            <span class="text-foreground font-medium">{{
                                name
                            }}</span
                            >, please review your registration terms and set
                            your account password for
                            <span class="text-foreground font-medium">{{
                                email
                            }}</span
                            >.
                        </CardDescription>
                    </CardHeader>
                    <form @submit.prevent="submit">
                        <CardContent class="space-y-5">
                            <!-- Registration Fee Terms Disclosure Card -->
                            <div
                                class="bg-muted/30 space-y-3 rounded-lg border p-4"
                            >
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <Coins class="text-primary size-4" />
                                        <span
                                            class="text-foreground text-sm font-semibold"
                                        >
                                            Registration Fee Terms
                                        </span>
                                    </div>
                                    <span
                                        class="text-foreground font-mono text-sm font-bold"
                                    >
                                        {{
                                            fee_snapshot
                                                ? fee_snapshot.formatted_amount
                                                : 'Free'
                                        }}
                                    </span>
                                </div>

                                <p
                                    v-if="fee_snapshot?.description"
                                    class="text-muted-foreground text-xs"
                                >
                                    {{ fee_snapshot.description }}
                                </p>
                                <p v-else class="text-muted-foreground text-xs">
                                    {{
                                        fee_snapshot?.is_zero
                                            ? 'No registration charge is required for this account.'
                                            : 'Standard registration charge.'
                                    }}
                                </p>

                                <div
                                    class="border-border/60 flex items-start gap-3 border-t pt-2"
                                >
                                    <Checkbox
                                        id="fee-ack"
                                        :checked="form.fee_acknowledged"
                                        @update:checked="
                                            form.fee_acknowledged =
                                                Boolean($event)
                                        "
                                        class="mt-0.5"
                                    />
                                    <Label
                                        for="fee-ack"
                                        class="cursor-pointer text-xs leading-normal font-normal"
                                    >
                                        I acknowledge and accept the
                                        registration terms and fee of
                                        <span
                                            class="text-foreground font-semibold"
                                        >
                                            {{
                                                fee_snapshot
                                                    ? fee_snapshot.formatted_amount
                                                    : 'NGN 0.00'
                                            }}
                                        </span>
                                        associated with opening this account.
                                    </Label>
                                </div>
                                <p
                                    v-if="form.errors.fee_acknowledged"
                                    class="text-destructive text-xs"
                                >
                                    {{ form.errors.fee_acknowledged }}
                                </p>
                            </div>

                            <!-- Password Fields -->
                            <div class="space-y-4">
                                <div class="space-y-1.5">
                                    <Label for="password"
                                        >Create Password</Label
                                    >
                                    <Input
                                        id="password"
                                        v-model="form.password"
                                        type="password"
                                        required
                                        autocomplete="new-password"
                                        placeholder="•••••••••••••••"
                                        :class="{
                                            'border-destructive':
                                                form.errors.password,
                                        }"
                                    />
                                    <p
                                        v-if="form.errors.password"
                                        class="text-destructive text-xs"
                                    >
                                        {{ form.errors.password }}
                                    </p>
                                    <p
                                        class="text-muted-foreground text-[11px]"
                                    >
                                        Use at least 15 characters. Do not use a
                                        password that is known from a public
                                        data breach.
                                    </p>
                                </div>

                                <div class="space-y-1.5">
                                    <Label for="password-confirmation"
                                        >Confirm Password</Label
                                    >
                                    <Input
                                        id="password-confirmation"
                                        v-model="form.password_confirmation"
                                        type="password"
                                        required
                                        autocomplete="new-password"
                                        placeholder="•••••••••••••••"
                                        :class="{
                                            'border-destructive':
                                                form.errors
                                                    .password_confirmation,
                                        }"
                                    />
                                    <p
                                        v-if="form.errors.password_confirmation"
                                        class="text-destructive text-xs"
                                    >
                                        {{ form.errors.password_confirmation }}
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                        <CardFooter>
                            <Button
                                type="submit"
                                class="w-full"
                                :disabled="
                                    form.processing || !form.fee_acknowledged
                                "
                            >
                                <Loader2
                                    v-if="form.processing"
                                    class="mr-2 size-4 animate-spin"
                                />
                                <KeyRound v-else class="mr-2 size-4" />
                                Activate Account & Log In
                            </Button>
                        </CardFooter>
                    </form>
                </Card>

                <!-- Expired State -->
                <Card v-else-if="status === 'expired'">
                    <CardHeader class="text-center">
                        <div
                            class="bg-destructive/10 text-destructive mx-auto flex size-12 items-center justify-center rounded-full"
                        >
                            <AlertCircle class="size-6" />
                        </div>
                        <CardTitle class="mt-2"
                            >Invitation Link Expired</CardTitle
                        >
                        <CardDescription>
                            This activation link has expired. For your security,
                            invitation links work for 7 days after we send them.
                        </CardDescription>
                    </CardHeader>
                    <CardContent
                        class="text-muted-foreground text-center text-sm"
                    >
                        Ask your agent or account administrator for a new
                        invitation link.
                    </CardContent>
                </Card>

                <!-- Cancelled State -->
                <Card v-else-if="status === 'cancelled'">
                    <CardHeader class="text-center">
                        <div
                            class="bg-destructive/10 text-destructive mx-auto flex size-12 items-center justify-center rounded-full"
                        >
                            <AlertCircle class="size-6" />
                        </div>
                        <CardTitle class="mt-2">Invitation Cancelled</CardTitle>
                        <CardDescription>
                            An agent or administrator cancelled this activation
                            invitation.
                        </CardDescription>
                    </CardHeader>
                    <CardContent
                        class="text-muted-foreground text-center text-sm"
                    >
                        If you think that this is an error, contact your account
                        manager.
                    </CardContent>
                </Card>

                <!-- Already Activated State -->
                <Card v-else-if="status === 'already_activated'">
                    <CardHeader class="text-center">
                        <div
                            class="bg-primary/10 text-primary mx-auto flex size-12 items-center justify-center rounded-full"
                        >
                            <CheckCircle2 class="size-6" />
                        </div>
                        <CardTitle class="mt-2"
                            >Account Already Activated</CardTitle
                        >
                        <CardDescription>
                            This customer account has already been successfully
                            activated.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="text-center">
                        <Link href="/login">
                            <Button class="w-full"
                                >Sign In to Your Account</Button
                            >
                        </Link>
                    </CardContent>
                </Card>

                <!-- Invalid / Fallback State -->
                <Card v-else>
                    <CardHeader class="text-center">
                        <div
                            class="bg-destructive/10 text-destructive mx-auto flex size-12 items-center justify-center rounded-full"
                        >
                            <ShieldAlert class="size-6" />
                        </div>
                        <CardTitle class="mt-2"
                            >Invalid Invitation Link</CardTitle
                        >
                        <CardDescription>
                            This invitation link is not valid, or it was
                            cancelled.
                        </CardDescription>
                    </CardHeader>
                    <CardContent
                        class="text-muted-foreground text-center text-sm"
                    >
                        Please check the link received in your email or contact
                        support.
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
