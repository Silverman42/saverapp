<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import {
    AlertCircle,
    CheckCircle2,
    KeyRound,
    Loader2,
    ShieldCheck,
} from '@lucide/vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    status: 'ready' | 'expired' | 'cancelled' | 'already_activated' | 'invalid';
    token?: string;
    name?: string;
    email?: string;
    business_name: string;
}>();

const form = useForm({
    identity_confirmed: false,
    access_accepted: false,
    password: '',
    password_confirmation: '',
});

const submit = (): void => {
    if (!props.token) return;

    form.post(`/invitations/admin/${props.token}`, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Activate Administrator Account" />

    <div
        class="bg-muted/40 flex min-h-screen items-center justify-center p-4 sm:p-6 lg:p-8"
    >
        <div class="w-full max-w-md space-y-6">
            <div class="text-center">
                <div
                    class="bg-primary text-primary-foreground mx-auto flex size-12 items-center justify-center rounded-2xl shadow-sm"
                >
                    <ShieldCheck class="size-6" />
                </div>
                <h1 class="mt-4 text-2xl font-bold tracking-tight">
                    {{ business_name }}
                </h1>
                <p class="text-muted-foreground mt-1 text-sm">
                    Administrator Account Activation
                </p>
            </div>

            <!-- Ready State: Form -->
            <Card v-if="status === 'ready'">
                <CardHeader>
                    <CardTitle>Activate Your Administrator Access</CardTitle>
                    <CardDescription>
                        Hello
                        <span class="text-foreground font-medium">{{
                            name
                        }}</span
                        >, confirm your details, accept access and choose your
                        own password for
                        <span class="text-foreground font-medium">{{
                            email
                        }}</span
                        >.
                    </CardDescription>
                </CardHeader>
                <form @submit.prevent="submit">
                    <CardContent class="space-y-4">
                        <div class="space-y-1.5">
                            <div class="flex items-start gap-3">
                                <Checkbox
                                    id="identity-confirmed"
                                    v-model="form.identity_confirmed"
                                    class="mt-1"
                                />
                                <Label
                                    for="identity-confirmed"
                                    class="cursor-pointer text-sm leading-relaxed font-normal"
                                >
                                    I confirm that {{ name }} ({{ email }}) is
                                    me.
                                </Label>
                            </div>
                            <p
                                v-if="form.errors.identity_confirmed"
                                class="text-destructive text-xs"
                            >
                                {{ form.errors.identity_confirmed }}
                            </p>
                        </div>

                        <div class="space-y-1.5">
                            <Label for="password">Password</Label>
                            <Input
                                id="password"
                                v-model="form.password"
                                type="password"
                                required
                                autocomplete="new-password"
                                placeholder="••••••••"
                                :class="{
                                    'border-destructive': form.errors.password,
                                }"
                            />
                            <p
                                v-if="form.errors.password"
                                class="text-destructive text-xs"
                            >
                                {{ form.errors.password }}
                            </p>
                            <p class="text-muted-foreground text-[11px]">
                                Minimum 8 characters. Must not be compromised.
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
                                placeholder="••••••••"
                                :class="{
                                    'border-destructive':
                                        form.errors.password_confirmation,
                                }"
                            />
                            <p
                                v-if="form.errors.password_confirmation"
                                class="text-destructive text-xs"
                            >
                                {{ form.errors.password_confirmation }}
                            </p>
                        </div>

                        <div
                            class="bg-muted/60 text-muted-foreground rounded-lg p-3 text-xs"
                        >
                            <p class="text-foreground font-medium">
                                Next Step: Two-Factor Authentication
                            </p>
                            <p class="mt-1">
                                Administrators must use an authenticator app
                                (TOTP) and save ten recovery codes. Your role
                                and permissions were set by the inviting
                                Administrator and cannot be changed here.
                            </p>
                        </div>

                        <div class="space-y-1.5">
                            <div class="flex items-start gap-3">
                                <Checkbox
                                    id="access-accepted"
                                    v-model="form.access_accepted"
                                    class="mt-1"
                                />
                                <Label
                                    for="access-accepted"
                                    class="cursor-pointer text-sm leading-relaxed font-normal"
                                >
                                    I accept Administrator access to the
                                    {{ business_name }} dashboard and its
                                    security responsibilities.
                                </Label>
                            </div>
                            <p
                                v-if="form.errors.access_accepted"
                                class="text-destructive text-xs"
                            >
                                {{ form.errors.access_accepted }}
                            </p>
                        </div>
                    </CardContent>
                    <CardFooter>
                        <Button
                            type="submit"
                            class="w-full"
                            :disabled="form.processing"
                        >
                            <Loader2
                                v-if="form.processing"
                                class="mr-2 size-4 animate-spin"
                            />
                            <KeyRound v-else class="mr-2 size-4" />
                            Set Password & Continue to MFA
                        </Button>
                    </CardFooter>
                </form>
            </Card>

            <!-- Expired State -->
            <Card v-else-if="status === 'expired'">
                <CardHeader>
                    <div class="text-destructive flex items-center gap-2">
                        <AlertCircle class="size-5" />
                        <CardTitle>Invitation Expired</CardTitle>
                    </div>
                    <CardDescription>
                        This invitation link was valid for 24 hours and has now
                        expired.
                    </CardDescription>
                </CardHeader>
                <CardContent class="text-muted-foreground space-y-3 text-sm">
                    <p>
                        Your Administrator account remains invited, but you
                        cannot activate access with this expired link.
                    </p>
                    <p>
                        Please contact an Administrator to resend an activation
                        invitation to your email.
                    </p>
                </CardContent>
                <CardFooter>
                    <Link href="/login" class="w-full">
                        <Button variant="outline" class="w-full"
                            >Return to Sign In</Button
                        >
                    </Link>
                </CardFooter>
            </Card>

            <!-- Cancelled State -->
            <Card v-else-if="status === 'cancelled'">
                <CardHeader>
                    <div class="text-destructive flex items-center gap-2">
                        <AlertCircle class="size-5" />
                        <CardTitle>Invitation Cancelled</CardTitle>
                    </div>
                    <CardDescription>
                        This invitation has been cancelled by an administrator.
                    </CardDescription>
                </CardHeader>
                <CardContent class="text-muted-foreground space-y-3 text-sm">
                    <p>
                        If you believe this was done in error, please contact
                        your platform administrator.
                    </p>
                </CardContent>
                <CardFooter>
                    <Link href="/login" class="w-full">
                        <Button variant="outline" class="w-full"
                            >Return to Sign In</Button
                        >
                    </Link>
                </CardFooter>
            </Card>

            <!-- Already Activated State -->
            <Card v-else-if="status === 'already_activated'">
                <CardHeader>
                    <div class="text-primary flex items-center gap-2">
                        <CheckCircle2 class="size-5" />
                        <CardTitle>Account Already Activated</CardTitle>
                    </div>
                    <CardDescription>
                        This Administrator account has already been activated.
                    </CardDescription>
                </CardHeader>
                <CardContent class="text-muted-foreground space-y-3 text-sm">
                    <p>
                        You can sign in directly using your email, password, and
                        authenticator app.
                    </p>
                </CardContent>
                <CardFooter>
                    <Link href="/login" class="w-full">
                        <Button class="w-full">Sign In</Button>
                    </Link>
                </CardFooter>
            </Card>

            <!-- Invalid State -->
            <Card v-else>
                <CardHeader>
                    <div class="text-destructive flex items-center gap-2">
                        <AlertCircle class="size-5" />
                        <CardTitle>Invalid Link</CardTitle>
                    </div>
                    <CardDescription>
                        This invitation link is not recognized or may have been
                        used previously.
                    </CardDescription>
                </CardHeader>
                <CardContent class="text-muted-foreground space-y-3 text-sm">
                    <p>
                        Please ensure you opened the link from your invitation
                        email, or ask an administrator to issue a new
                        invitation.
                    </p>
                </CardContent>
                <CardFooter>
                    <Link href="/login" class="w-full">
                        <Button variant="outline" class="w-full"
                            >Return to Sign In</Button
                        >
                    </Link>
                </CardFooter>
            </Card>
        </div>
    </div>
</template>
