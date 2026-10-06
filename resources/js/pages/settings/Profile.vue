<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { edit } from '@/routes/profile';
import { store as requestEmailChange } from '@/routes/email-change';

const props = defineProps<{
    mustVerifyEmail: boolean;
    status?: string | null;
    profileEditUrl: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Profile settings', href: edit() },
        ],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
const emailForm = useForm({ email: user.value.email });
const emailFormProfileError = computed(
    () => (emailForm.errors as unknown as { profile?: string }).profile,
);

const submitEmailChange = (): void => {
    emailForm.post(requestEmailChange().url, { preserveScroll: true });
};
</script>

<template>
    <Head title="Profile settings" />

    <h1 class="sr-only">Profile settings</h1>

    <div class="flex flex-col space-y-10">
        <section class="space-y-4">
            <Heading
                variant="small"
                title="Name and phone"
                :description="`You are signed in as ${user.name}.`"
            />
            <Button v-if="profileEditUrl" variant="outline" as-child>
                <Link :href="profileEditUrl">Edit profile</Link>
            </Button>
            <p v-else class="text-muted-foreground text-sm">
                To change these, ask an admin.
            </p>
        </section>

        <form class="space-y-4" @submit.prevent="submitEmailChange">
            <Heading
                variant="small"
                title="Email address"
                description="We will email both addresses to confirm. Your current email works until then."
            />
            <div class="grid gap-2">
                <Label for="email">New email</Label>
                <Input
                    id="email"
                    v-model="emailForm.email"
                    type="email"
                    required
                    maxlength="255"
                    autocomplete="email"
                />
                <InputError :message="emailForm.errors.email" />
            </div>
            <p
                v-if="emailFormProfileError"
                role="alert"
                class="text-destructive text-sm"
            >
                {{ emailFormProfileError }}
            </p>
            <Button type="submit" :disabled="emailForm.processing">{{
                emailForm.processing ? 'Sending…' : 'Change email'
            }}</Button>
        </form>

        <p
            v-if="props.status === 'email-change-complete'"
            role="status"
            class="text-sm font-medium text-green-700"
        >
            Your email has changed. Use the new one next time you sign in.
        </p>

        <DeleteUser />
    </div>
</template>
