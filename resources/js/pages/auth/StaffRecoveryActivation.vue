<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { onMounted } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { activate } from '@/routes/staff-recovery';

defineOptions({
    layout: {
        title: 'Recover your account',
        description:
            'Verify this email, choose your own password, then set up a new authenticator app.',
    },
});

const props = defineProps<{ reference: string; business_name: string }>();
const form = useForm({ token: '', password: '', password_confirmation: '' });

onMounted(() => {
    const fragment = new URLSearchParams(window.location.hash.slice(1));
    form.token = fragment.get('token') ?? '';
    window.history.replaceState(null, '', window.location.pathname);
});

function submit(): void {
    form.post(activate.url(props.reference), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <div class="mx-auto w-full max-w-md">
        <Head title="Recover your account" />
        <Card>
            <CardContent class="pt-6">
                <form class="space-y-5" @submit.prevent="submit">
                    <p
                        v-if="!form.token"
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        Open this page from the recovery link in your email. The
                        link works once and expires after 60 minutes.
                    </p>
                    <div>
                        <Label for="password">New password</Label>
                        <Input
                            id="password"
                            v-model="form.password"
                            type="password"
                            autocomplete="new-password"
                            required
                            class="mt-2"
                        />
                    </div>
                    <div>
                        <Label for="password-confirmation"
                            >Confirm password</Label
                        >
                        <Input
                            id="password-confirmation"
                            v-model="form.password_confirmation"
                            type="password"
                            autocomplete="new-password"
                            required
                            class="mt-2"
                        />
                    </div>
                    <p class="text-muted-foreground text-sm">
                        After this step you will set up a new authenticator app
                        and save new recovery codes before you can use
                        {{ business_name }}.
                    </p>
                    <ul
                        v-if="form.hasErrors"
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        <li v-for="(error, field) in form.errors" :key="field">
                            {{ error }}
                        </li>
                    </ul>
                    <Button
                        class="w-full"
                        :disabled="form.processing || !form.token"
                        >Verify email and continue</Button
                    >
                </form>
            </CardContent>
        </Card>
    </div>
</template>
