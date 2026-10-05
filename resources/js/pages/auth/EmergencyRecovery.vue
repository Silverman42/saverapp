<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { store } from '@/routes/emergency-recovery';

defineOptions({
    layout: {
        title: 'Final Administrator emergency recovery',
        description:
            'Only for the last Administrator who has lost their email access, authenticator and recovery codes.',
    },
});

const form = useForm({ email: '', key: '' });

function submit(): void {
    form.post(store.url(), { onFinish: () => form.reset('key') });
}
</script>

<template>
    <div class="mx-auto w-full max-w-md">
        <Head title="Emergency recovery" />
        <Card>
            <CardContent class="pt-6">
                <form class="space-y-5" @submit.prevent="submit">
                    <div>
                        <Label for="email">Seeded Administrator email</Label>
                        <Input
                            id="email"
                            v-model="form.email"
                            type="email"
                            autocomplete="username"
                            required
                            class="mt-2"
                        />
                    </div>
                    <div>
                        <Label for="key">Offline emergency key</Label>
                        <Input
                            id="key"
                            v-model="form.key"
                            type="password"
                            autocomplete="off"
                            maxlength="64"
                            required
                            class="mt-2"
                        />
                    </div>
                    <p class="text-muted-foreground text-sm">
                        If both match, we send a single-use recovery link to the
                        seeded email. The key then stops working. If other
                        Administrators are active, ask them for assisted
                        recovery.
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
                    <Button class="w-full" :disabled="form.processing"
                        >Send recovery link</Button
                    >
                </form>
            </CardContent>
        </Card>
    </div>
</template>
