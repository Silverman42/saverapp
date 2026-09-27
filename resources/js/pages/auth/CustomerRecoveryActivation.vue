<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { activate } from '@/routes/customer-recovery';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent } from '@/components/ui/card';

defineOptions({
    layout: {
        title: 'Activate account recovery',
        description: 'Verify your new email and choose your own password.',
    },
});
const props = defineProps<{ reference: string }>();
const form = useForm({ token: '', password: '', password_confirmation: '' });
function submit(): void {
    form.post(activate.url(props.reference), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>
<template>
    <div class="mx-auto w-full max-w-md">
        <Head title="Activate account recovery" /><Card
            ><CardContent class="pt-6"
                ><form class="space-y-5" @submit.prevent="submit">
                    <div>
                        <Label for="activation-token"
                            >Activation code from your email</Label
                        ><Input
                            id="activation-token"
                            v-model="form.token"
                            type="password"
                            autocomplete="off"
                            maxlength="64"
                            required
                            class="mt-2"
                        />
                        <p class="text-muted-foreground mt-2 text-sm">
                            Paste the single-use code sent to your proposed
                            email address. The code expires after 30 minutes.
                        </p>
                    </div>
                    <div>
                        <Label for="password">New password</Label
                        ><Input
                            id="password"
                            v-model="form.password"
                            type="password"
                            autocomplete="new-password"
                            minlength="15"
                            required
                            class="mt-2"
                        />
                        <p class="text-muted-foreground mt-2 text-sm">
                            Use at least 15 characters.
                        </p>
                    </div>
                    <div>
                        <Label for="password-confirmation"
                            >Confirm password</Label
                        ><Input
                            id="password-confirmation"
                            v-model="form.password_confirmation"
                            type="password"
                            autocomplete="new-password"
                            required
                            class="mt-2"
                        />
                    </div>
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
                        >Verify email and recover account</Button
                    >
                </form></CardContent
            ></Card
        >
    </div>
</template>
