<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { confirm as confirmEmailChange } from '@/routes/email-change';

const props = defineProps<{
    pending_id: number;
    confirmation_recorded: boolean;
}>();
const token = ref('');
const form = useForm({ token: '' });
const genericError = computed(
    () => (form.errors as unknown as { profile?: string }).profile,
);

onMounted(() => {
    const fragment = new URLSearchParams(window.location.hash.slice(1));
    token.value = fragment.get('token') ?? '';
    window.history.replaceState(null, '', window.location.pathname);
});

const submit = (): void => {
    if (token.value.length < 32) return;
    form.token = token.value;
    form.post(confirmEmailChange(props.pending_id).url, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Confirm email change" />
    <main class="bg-muted/40 flex min-h-screen items-center justify-center p-4">
        <Card class="w-full max-w-lg">
            <CardHeader>
                <CardTitle>Confirm this email address</CardTitle>
                <CardDescription
                    >Each address must confirm the change. Your current sign-in
                    email stays in place until both confirmations are
                    complete.</CardDescription
                >
            </CardHeader>
            <CardContent class="space-y-4">
                <p v-if="confirmation_recorded" class="text-sm">
                    This address is confirmed. The change will finish after the
                    other address is confirmed.
                </p>
                <p v-else-if="!token" class="text-muted-foreground text-sm">
                    This link is missing its confirmation token. Open the
                    original email link again.
                </p>
                <p v-else class="text-muted-foreground text-sm">
                    Confirm that this address belongs to you.
                </p>
                <p v-if="form.errors.token" class="text-destructive text-sm">
                    {{ form.errors.token }}
                </p>
                <p v-if="genericError" class="text-destructive text-sm">
                    {{ genericError }}
                </p>
                <Button
                    v-if="token"
                    :disabled="form.processing"
                    @click="submit"
                    >{{
                        form.processing
                            ? 'Confirming…'
                            : 'Confirm email address'
                    }}</Button
                >
            </CardContent>
        </Card>
    </main>
</template>
