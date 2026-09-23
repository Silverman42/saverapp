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

        <div class="flex min-h-screen items-center justify-center bg-muted/40 p-4 sm:p-6 lg:p-8">
            <div class="w-full max-w-lg space-y-6">
                <!-- Branding Header -->
                <div class="text-center">
                    <div class="mx-auto flex size-12 items-center justify-center rounded-2xl bg-primary text-primary-foreground shadow-sm">
                        <UserCheck class="size-6" />
                    </div>
                    <h1 class="mt-4 text-2xl font-bold tracking-tight">{{ business_name }}</h1>
                    <p class="text-muted-foreground mt-1 text-sm">Customer Account Activation</p>
                </div>

                <!-- Ready State: Fee Terms & Password Form -->
                <Card v-if="status === 'ready'">
                    <CardHeader>
                        <CardTitle>Activate Your Account</CardTitle>
                        <CardDescription>
                            Hello <span class="font-medium text-foreground">{{ name }}</span>, please review your registration terms and set your account password for <span class="font-medium text-foreground">{{ email }}</span>.
                        </CardDescription>
                    </CardHeader>
                    <form @submit.prevent="submit">
                        <CardContent class="space-y-5">
                            <!-- Registration Fee Terms Disclosure Card -->
                            <div class="rounded-lg border bg-muted/30 p-4 space-y-3">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <Coins class="size-4 text-primary" />
                                        <span class="text-sm font-semibold text-foreground">
                                            Registration Fee Terms
                                        </span>
                                    </div>
                                    <span class="font-mono text-sm font-bold text-foreground">
                                        {{ fee_snapshot ? fee_snapshot.formatted_amount : 'Free' }}
                                    </span>
                                </div>

                                <p v-if="fee_snapshot?.description" class="text-xs text-muted-foreground">
                                    {{ fee_snapshot.description }}
                                </p>
                                <p v-else class="text-xs text-muted-foreground">
                                    {{ fee_snapshot?.is_zero ? 'No registration charge is required for this account.' : 'Standard onboarding registration charge.' }}
                                </p>

                                <div class="flex items-start gap-3 pt-2 border-t border-border/60">
                                    <Checkbox
                                        id="fee-ack"
                                        :checked="form.fee_acknowledged"
                                        @update:checked="form.fee_acknowledged = Boolean($event)"
                                        class="mt-0.5"
                                    />
                                    <Label for="fee-ack" class="text-xs leading-normal cursor-pointer font-normal">
                                        I acknowledge and accept the registration terms and fee of
                                        <span class="font-semibold text-foreground">
                                            {{ fee_snapshot ? fee_snapshot.formatted_amount : 'NGN 0.00' }}
                                        </span>
                                        associated with opening this account.
                                    </Label>
                                </div>
                                <p v-if="form.errors.fee_acknowledged" class="text-destructive text-xs">
                                    {{ form.errors.fee_acknowledged }}
                                </p>
                            </div>

                            <!-- Password Fields -->
                            <div class="space-y-4">
                                <div class="space-y-1.5">
                                    <Label for="password">Create Password</Label>
                                    <Input
                                        id="password"
                                        v-model="form.password"
                                        type="password"
                                        required
                                        autocomplete="new-password"
                                        placeholder="•••••••••••••••"
                                        :class="{ 'border-destructive': form.errors.password }"
                                    />
                                    <p v-if="form.errors.password" class="text-destructive text-xs">
                                        {{ form.errors.password }}
                                    </p>
                                    <p class="text-muted-foreground text-[11px]">
                                        Must be at least 15 characters long and not compromised in public data breaches.
                                    </p>
                                </div>

                                <div class="space-y-1.5">
                                    <Label for="password-confirmation">Confirm Password</Label>
                                    <Input
                                        id="password-confirmation"
                                        v-model="form.password_confirmation"
                                        type="password"
                                        required
                                        autocomplete="new-password"
                                        placeholder="•••••••••••••••"
                                        :class="{ 'border-destructive': form.errors.password_confirmation }"
                                    />
                                    <p v-if="form.errors.password_confirmation" class="text-destructive text-xs">
                                        {{ form.errors.password_confirmation }}
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                        <CardFooter>
                            <Button
                                type="submit"
                                class="w-full"
                                :disabled="form.processing || !form.fee_acknowledged"
                            >
                                <Loader2 v-if="form.processing" class="mr-2 size-4 animate-spin" />
                                <KeyRound v-else class="mr-2 size-4" />
                                Activate Account & Log In
                            </Button>
                        </CardFooter>
                    </form>
                </Card>

                <!-- Expired State -->
                <Card v-else-if="status === 'expired'">
                    <CardHeader class="text-center">
                        <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-destructive/10 text-destructive">
                            <AlertCircle class="size-6" />
                        </div>
                        <CardTitle class="mt-2">Invitation Link Expired</CardTitle>
                        <CardDescription>
                            This activation link has expired. Invitation links remain valid for 7 days from dispatch for your security.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="text-center text-sm text-muted-foreground">
                        Please contact your assigned agent or account administrator to receive a fresh invitation link.
                    </CardContent>
                </Card>

                <!-- Cancelled State -->
                <Card v-else-if="status === 'cancelled'">
                    <CardHeader class="text-center">
                        <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-destructive/10 text-destructive">
                            <AlertCircle class="size-6" />
                        </div>
                        <CardTitle class="mt-2">Invitation Cancelled</CardTitle>
                        <CardDescription>
                            This customer activation invitation was cancelled by an authorized agent or administrator.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="text-center text-sm text-muted-foreground">
                        If you believe this was done in error, please contact your account manager.
                    </CardContent>
                </Card>

                <!-- Already Activated State -->
                <Card v-else-if="status === 'already_activated'">
                    <CardHeader class="text-center">
                        <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-primary/10 text-primary">
                            <CheckCircle2 class="size-6" />
                        </div>
                        <CardTitle class="mt-2">Account Already Activated</CardTitle>
                        <CardDescription>
                            This customer account has already been successfully activated.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="text-center">
                        <Link href="/login">
                            <Button class="w-full">Sign In to Your Account</Button>
                        </Link>
                    </CardContent>
                </Card>

                <!-- Invalid / Fallback State -->
                <Card v-else>
                    <CardHeader class="text-center">
                        <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-destructive/10 text-destructive">
                            <ShieldAlert class="size-6" />
                        </div>
                        <CardTitle class="mt-2">Invalid Invitation Link</CardTitle>
                        <CardDescription>
                            This invitation link is unrecognized, malformed, or has been revoked.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="text-center text-sm text-muted-foreground">
                        Please check the link received in your email or contact support.
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
