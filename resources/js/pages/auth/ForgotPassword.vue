<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { useVuelidate } from '@vuelidate/core';
import { email as emailValidator, required } from '@vuelidate/validators';
import { toast } from 'vue-sonner';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { email } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Forgot password',
        description: 'Enter your email to receive a password reset link',
    },
});

defineProps<{
    status?: string;
}>();

const formState = reactive({
    email: '',
});

const rules = {
    email: { required, email: emailValidator },
};

const v$ = useVuelidate(rules, formState);

const handleSuccess = (): void => {
    toast.success(
        "If an account exists for this email, we've sent password reset instructions.",
    );
};
</script>

<template>
    <Head title="Forgot password" />

    <div
        v-if="status"
        class="mb-4 text-center text-sm font-medium text-green-600"
    >
        {{ status }}
    </div>

    <div class="space-y-6">
        <Form
            v-bind="email.form()"
            :transform="() => ({ email: formState.email })"
            @success="handleSuccess"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    v-model="formState.email"
                    autocomplete="off"
                    autofocus
                    placeholder="email@example.com"
                    @blur="v$.email.$touch"
                />
                <InputError
                    :message="
                        errors.email ||
                        (v$.email.$error
                            ? 'A valid email address is required.'
                            : undefined)
                    "
                />
            </div>

            <div class="my-6 flex items-center justify-start">
                <Button
                    class="w-full"
                    :disabled="processing"
                    data-test="email-password-reset-link-button"
                >
                    <Spinner v-if="processing" />
                    Email password reset link
                </Button>
            </div>
        </Form>

        <div class="text-muted-foreground space-x-1 text-center text-sm">
            <span>Or, return to </span>
            <TextLink :href="login()">log in</TextLink>
        </div>
    </div>
</template>
