<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

const props = defineProps<{
    requiresTwoFactor: boolean;
    userType?: string;
    email?: string;
}>();

defineOptions({
    layout: {
        title: 'Confirm identity',
        description:
            'This is a protected area. Please re-confirm your credentials to continue.',
    },
});

const form = useForm({
    password: '',
    code: '',
});

const submit = () => {
    form.post('/user/fresh-authentication', {
        onSuccess: () => {
            form.reset();
        },
        onError: () => {
            if (props.requiresTwoFactor) {
                form.reset('code');
            }
        },
    });
};
</script>

<template>
    <Head title="Fresh authentication" />

    <form @submit.prevent="submit" class="space-y-6">
        <div
            class="rounded-lg border border-amber-500/20 bg-amber-500/10 p-3 text-sm text-amber-600 dark:text-amber-400"
        >
            <p class="font-medium">High-security area</p>
            <p class="mt-1 text-xs opacity-90">
                Fresh verification is required for 10 minutes. Recovery codes
                and trusted-device bypasses do not apply.
            </p>
        </div>

        <div class="grid gap-2">
            <Label for="password">Current password</Label>
            <PasswordInput
                id="password"
                v-model="form.password"
                name="password"
                class="block w-full"
                required
                autocomplete="current-password"
                autofocus
            />
            <InputError :message="form.errors.password" />
        </div>

        <div v-if="requiresTwoFactor" class="grid gap-2">
            <Label for="otp">Two-factor authentication code</Label>
            <p class="text-muted-foreground text-xs">
                Enter the 6-digit code from your authenticator app.
            </p>
            <div class="flex items-center justify-center py-2">
                <InputOTP
                    id="otp"
                    v-model="form.code"
                    :maxlength="6"
                    :disabled="form.processing"
                >
                    <InputOTPGroup>
                        <InputOTPSlot
                            v-for="index in 6"
                            :key="index"
                            :index="index - 1"
                        />
                    </InputOTPGroup>
                </InputOTP>
            </div>
            <InputError :message="form.errors.code" />
        </div>

        <div class="flex items-center gap-3">
            <Button
                type="submit"
                class="w-full"
                :disabled="form.processing"
                data-test="confirm-fresh-auth-button"
            >
                <Spinner v-if="form.processing" />
                Confirm &amp; continue
            </Button>
        </div>
    </form>
</template>
