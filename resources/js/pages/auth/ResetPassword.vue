<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { useVuelidate } from '@vuelidate/core';
import {
    email as emailValidator,
    minLength,
    required,
    sameAs,
} from '@vuelidate/validators';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { request, update } from '@/routes/password';
import { showToast } from '@/lib/flashToast';

defineOptions({
    layout: {
        title: 'Reset password',
        description: 'Please enter your new password below',
    },
});

const props = withDefaults(
    defineProps<{
        token: string;
        email: string;
        passwordRules: string;
        isValidToken?: boolean;
        requiresTwoFactor?: boolean;
        userType?: string;
    }>(),
    {
        isValidToken: true,
        requiresTwoFactor: false,
        userType: 'customer',
    },
);

const inputEmail = ref(props.email);
const showRecoveryInput = ref(false);

const formState = reactive({
    password: '',
    password_confirmation: '',
    code: '',
    recovery_code: '',
});

const minPassLength = computed(() => {
    return props.userType === 'agent' || props.userType === 'admin' ? 8 : 15;
});

const rules = computed(() => ({
    password: {
        required,
        minLength: minLength(minPassLength.value),
    },
    password_confirmation: {
        required,
        sameAsPassword: sameAs(computed(() => formState.password)),
    },
    code:
        props.requiresTwoFactor && !showRecoveryInput.value
            ? { required, minLength: minLength(6) }
            : {},
    recovery_code:
        props.requiresTwoFactor && showRecoveryInput.value ? { required } : {},
}));

const v$ = useVuelidate(rules, formState);

const toggleRecoveryMode = (): void => {
    showRecoveryInput.value = !showRecoveryInput.value;
    formState.code = '';
    formState.recovery_code = '';
    v$.value.$reset();
};

const handleSuccess = (): void => {
    showToast({
        type: 'success',
        title: 'Password reset',
        description: 'Your password has been successfully reset.',
    });
};
</script>

<template>
    <Head title="Reset password" />

    <!-- Invalid or expired token view -->
    <div v-if="!isValidToken" class="space-y-6 text-center">
        <div
            class="border-destructive/20 bg-destructive/10 text-destructive rounded-xl border p-4 text-sm"
        >
            This password reset link is invalid or has expired.
        </div>

        <div class="text-sm">
            <TextLink :href="request()">
                Request a new password reset link
            </TextLink>
        </div>

        <div class="text-muted-foreground text-center text-sm">
            <span>Or, return to </span>
            <TextLink :href="login()">log in</TextLink>
        </div>
    </div>

    <!-- Valid token view -->
    <Form
        v-else
        v-bind="update.form()"
        :transform="
            () => ({
                token,
                email: inputEmail,
                password: formState.password,
                password_confirmation: formState.password_confirmation,
                ...(requiresTwoFactor
                    ? showRecoveryInput
                        ? { recovery_code: formState.recovery_code }
                        : { code: formState.code }
                    : {}),
            })
        "
        :reset-on-success="['password', 'password_confirmation']"
        @success="handleSuccess"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-6">
            <!-- Readonly Email -->
            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="email"
                    v-model="inputEmail"
                    class="mt-1 block w-full opacity-70"
                    readonly
                />
                <InputError :message="errors.email" class="mt-2" />
            </div>

            <!-- Mandatory Two Factor for Agent/Admin -->
            <div
                v-if="requiresTwoFactor"
                class="border-border bg-card/60 space-y-4 rounded-2xl border p-4"
            >
                <div class="flex items-center justify-between">
                    <p
                        class="text-muted-foreground text-xs font-semibold tracking-wider uppercase"
                    >
                        Two-factor authentication
                    </p>
                    <button
                        type="button"
                        class="text-primary hover:text-primary/80 text-xs underline underline-offset-4"
                        @click="toggleRecoveryMode"
                    >
                        {{
                            showRecoveryInput
                                ? 'Use authenticator code'
                                : 'Use recovery code'
                        }}
                    </button>
                </div>

                <!-- OTP code input -->
                <div
                    v-if="!showRecoveryInput"
                    class="flex flex-col items-center space-y-3 text-center"
                >
                    <Label for="otp" class="text-sm"
                        >Enter 6-digit authenticator code</Label
                    >
                    <InputOTP
                        id="otp"
                        v-model="formState.code"
                        :maxlength="6"
                        :disabled="processing"
                        autofocus
                    >
                        <InputOTPGroup>
                            <InputOTPSlot
                                v-for="index in 6"
                                :key="index"
                                :index="index - 1"
                            />
                        </InputOTPGroup>
                    </InputOTP>
                    <InputError
                        :message="
                            errors.code ||
                            (v$.code.$error
                                ? 'Valid 6-digit code is required.'
                                : undefined)
                        "
                    />
                </div>

                <!-- Recovery code input -->
                <div v-else class="space-y-2">
                    <Label for="recovery_code" class="text-sm"
                        >Emergency recovery code</Label
                    >
                    <Input
                        id="recovery_code"
                        name="recovery_code"
                        type="text"
                        v-model="formState.recovery_code"
                        placeholder="Enter unused recovery code"
                        :disabled="processing"
                        autofocus
                    />
                    <InputError
                        :message="
                            errors.recovery_code ||
                            (v$.recovery_code.$error
                                ? 'Recovery code is required.'
                                : undefined)
                        "
                    />
                </div>
            </div>

            <!-- New Password -->
            <div class="grid gap-2">
                <Label for="password">New password</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    v-model="formState.password"
                    autocomplete="new-password"
                    class="mt-1 block w-full"
                    :autofocus="!requiresTwoFactor"
                    placeholder="Enter new password"
                    :passwordrules="passwordRules"
                    @blur="v$.password.$touch"
                />
                <InputError
                    :message="
                        errors.password ||
                        (v$.password.$error
                            ? `Password must be at least ${minPassLength} characters.`
                            : undefined)
                    "
                />
            </div>

            <!-- Confirm Password -->
            <div class="grid gap-2">
                <Label for="password_confirmation">Confirm new password</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    v-model="formState.password_confirmation"
                    autocomplete="new-password"
                    class="mt-1 block w-full"
                    placeholder="Confirm new password"
                    :passwordrules="passwordRules"
                    @blur="v$.password_confirmation.$touch"
                />
                <InputError
                    :message="
                        errors.password_confirmation ||
                        (v$.password_confirmation.$error
                            ? 'Passwords must match.'
                            : undefined)
                    "
                />
            </div>

            <Button
                type="submit"
                class="mt-4 w-full"
                :disabled="processing"
                data-test="reset-password-button"
            >
                <Spinner v-if="processing" />
                Reset password
            </Button>

            <div class="text-muted-foreground text-center text-sm">
                <span>Or, return to </span>
                <TextLink :href="login()">log in</TextLink>
            </div>
        </div>
    </Form>
</template>
