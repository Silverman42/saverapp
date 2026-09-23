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

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Profile"
            description="Review your account details and manage identity changes securely."
        />

        <section class="space-y-3 rounded-lg border p-5">
            <h2 class="text-base font-medium">Name and phone</h2>
            <p class="text-muted-foreground text-sm">
                Your current name is {{ user.name }}. Name and phone changes use
                your account profile and may require fresh authentication.
            </p>
            <Link v-if="profileEditUrl" :href="profileEditUrl">
                <Button variant="outline">Open profile</Button>
            </Link>
            <p v-else class="text-muted-foreground text-sm">
                Contact an authorized administrator to update your profile
                details.
            </p>
        </section>

        <form
            class="space-y-4 rounded-lg border p-5"
            @submit.prevent="submitEmailChange"
        >
            <div>
                <h2 class="text-base font-medium">Email address</h2>
                <p class="text-muted-foreground mt-1 text-sm">
                    The current email stays active until both the current and
                    new addresses confirm the change.
                </p>
            </div>
            <div class="grid gap-2">
                <Label for="email">New email address</Label>
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
            <p v-if="emailFormProfileError" class="text-destructive text-sm">
                {{ emailFormProfileError }}
            </p>
            <Button type="submit" :disabled="emailForm.processing">{{
                emailForm.processing
                    ? 'Sending confirmations…'
                    : 'Request email change'
            }}</Button>
        </form>

        <p
            v-if="props.status === 'email-change-complete'"
            class="text-sm font-medium text-green-700"
        >
            Your email address changed. Sign in with the new address.
        </p>

        <DeleteUser />
    </div>
</template>
