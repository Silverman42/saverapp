<script setup lang="ts">
import { Head, Link, setLayoutProps, useForm } from '@inertiajs/vue3';
import { Check, Circle } from '@lucide/vue';
import { computed, watchEffect } from 'vue';
import { activate } from '@/actions/App/Http/Controllers/Auth/CustomerActivationController';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';

export type FeeSnapshot = {
    name: string;
    amount_kobo: number;
    formatted_amount: string;
    currency: string;
    description: string | null;
    is_zero: boolean;
};

type ActivationStatus =
    'ready' | 'expired' | 'cancelled' | 'already_activated' | 'invalid';

const props = defineProps<{
    status: ActivationStatus;
    token?: string;
    name?: string;
    email?: string;
    business_name: string;
    fee_snapshot?: FeeSnapshot | null;
}>();

const headings: Record<
    ActivationStatus,
    { title: string; description: string }
> = {
    ready: {
        title: 'Activate your account',
        description: props.name
            ? `Hi ${props.name}, set a password to start saving with ${props.business_name}.`
            : `Set a password to start saving with ${props.business_name}.`,
    },
    expired: {
        title: 'This link has expired',
        description:
            'Activation links work for 7 days. Ask your agent to send you a new one.',
    },
    cancelled: {
        title: 'This invitation was cancelled',
        description: 'If you think this is a mistake, contact your agent.',
    },
    already_activated: {
        title: 'Your account is already active',
        description: 'Log in with the email and password you set up.',
    },
    invalid: {
        title: 'This link is not valid',
        description:
            'Check the link in your email, or ask your agent to send a new one.',
    },
};

watchEffect(() => {
    setLayoutProps(headings[props.status]);
});

const form = useForm({
    fee_acknowledged: false,
    password: '',
    password_confirmation: '',
});

const passwordRequirements = computed(() => [
    { label: 'At least 8 characters', met: form.password.length >= 8 },
    { label: 'An uppercase letter', met: /\p{Lu}/u.test(form.password) },
    { label: 'A lowercase letter', met: /\p{Ll}/u.test(form.password) },
    { label: 'A number', met: /\p{N}/u.test(form.password) },
    {
        label: 'A special character',
        met: /[^\p{L}\p{N}]/u.test(form.password),
    },
]);

const isPasswordValid = computed(() =>
    passwordRequirements.value.every((requirement) => requirement.met),
);

const doPasswordsMatch = computed(
    () =>
        form.password_confirmation.length > 0 &&
        form.password === form.password_confirmation,
);

const confirmationError = computed(() =>
    form.password_confirmation.length >= form.password.length &&
    form.password_confirmation.length > 0 &&
    !doPasswordsMatch.value
        ? 'Passwords do not match.'
        : undefined,
);

const canSubmit = computed(
    () =>
        form.fee_acknowledged &&
        isPasswordValid.value &&
        doPasswordsMatch.value &&
        !form.processing,
);

const feeAmount = computed(() =>
    props.fee_snapshot && !props.fee_snapshot.is_zero
        ? props.fee_snapshot.formatted_amount
        : 'Free',
);

const submit = (): void => {
    if (!props.token) {
        return;
    }

    form.post(activate.url(props.token), {
        preserveScroll: true,
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <div>
        <Head title="Activate account" />

        <form
            v-if="status === 'ready'"
            class="flex flex-col gap-6"
            @submit.prevent="submit"
        >
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="email">Email address</Label>
                    <Input
                        id="email"
                        type="email"
                        :model-value="email"
                        autocomplete="username"
                        readonly
                        class="bg-muted/50 text-muted-foreground"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="password">Password</Label>
                    <PasswordInput
                        id="password"
                        v-model="form.password"
                        required
                        autofocus
                        autocomplete="new-password"
                        placeholder="Create a password"
                        aria-describedby="password-requirements"
                    />
                    <ul
                        id="password-requirements"
                        class="grid grid-cols-1 gap-x-4 gap-y-1.5 pt-1 sm:grid-cols-2"
                    >
                        <li
                            v-for="requirement in passwordRequirements"
                            :key="requirement.label"
                            class="flex items-center gap-2 text-xs transition-colors"
                            :class="
                                requirement.met
                                    ? 'text-foreground'
                                    : 'text-muted-foreground'
                            "
                        >
                            <Check
                                v-if="requirement.met"
                                class="text-primary size-3.5 shrink-0"
                            />
                            <Circle v-else class="size-3.5 shrink-0" />
                            <span>
                                {{ requirement.label }}
                                <span class="sr-only">{{
                                    requirement.met ? '(done)' : '(missing)'
                                }}</span>
                            </span>
                        </li>
                    </ul>
                    <InputError :message="form.errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">Confirm password</Label>
                    <PasswordInput
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        required
                        autocomplete="new-password"
                        placeholder="Enter it again"
                    />
                    <InputError
                        :message="
                            form.errors.password_confirmation ??
                            confirmationError
                        "
                    />
                </div>

                <div class="grid gap-3 rounded-xl border p-4">
                    <div class="flex items-baseline justify-between gap-4">
                        <span class="text-sm font-medium"
                            >Registration fee</span
                        >
                        <span class="text-sm font-semibold tabular-nums">
                            {{ feeAmount }}
                        </span>
                    </div>
                    <p
                        v-if="fee_snapshot?.description"
                        class="text-muted-foreground text-xs leading-5"
                    >
                        {{ fee_snapshot.description }}
                    </p>
                    <Label
                        for="fee_acknowledged"
                        class="flex items-start gap-3 text-sm leading-5 font-normal"
                    >
                        <Checkbox
                            id="fee_acknowledged"
                            v-model="form.fee_acknowledged"
                            class="mt-0.5"
                        />
                        <span>
                            I accept the registration terms and the
                            {{
                                feeAmount === 'Free'
                                    ? 'free registration'
                                    : `${feeAmount} fee`
                            }}.
                        </span>
                    </Label>
                    <InputError :message="form.errors.fee_acknowledged" />
                </div>

                <Button
                    type="submit"
                    class="mt-2 w-full"
                    :disabled="!canSubmit"
                    data-test="activate-button"
                >
                    <Spinner v-if="form.processing" />
                    Activate account
                </Button>
            </div>
        </form>

        <Button
            v-else-if="status === 'already_activated'"
            as-child
            class="w-full"
        >
            <Link :href="login()">Log in</Link>
        </Button>

        <Button v-else variant="outline" as-child class="w-full">
            <Link :href="login()">Back to log in</Link>
        </Button>
    </div>
</template>
