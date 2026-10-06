<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Loader2, MailPlus } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { store as invitationsStore } from '@/routes/admin/access/invitations';

export type GrantablePermission = {
    code: string;
    name: string;
    description: string;
};

const props = defineProps<{
    attemptReference: string;
    permissions: GrantablePermission[];
}>();

const form = useForm({
    attempt_reference: props.attemptReference,
    name: '',
    email: '',
    permissions: [] as string[],
    confirmed: false,
});

const togglePermission = (
    code: string,
    checked: boolean | 'indeterminate',
): void => {
    form.permissions =
        checked === true
            ? [...form.permissions, code]
            : form.permissions.filter((permission) => permission !== code);
};

const submit = (): void => {
    form.post(invitationsStore.url(), { preserveScroll: true });
};
</script>

<template>
    <form class="space-y-8" @submit.prevent="submit">
        <section class="space-y-4">
            <div>
                <h2 class="text-base font-medium">Who are you inviting?</h2>
                <p class="text-muted-foreground mt-1 text-sm">
                    We will email them a link. It works for 24 hours.
                </p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="name">Full name</Label>
                    <Input
                        id="name"
                        v-model="form.name"
                        required
                        maxlength="150"
                        autocomplete="off"
                    />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="email">Email address</Label>
                    <Input
                        id="email"
                        v-model="form.email"
                        type="email"
                        required
                        maxlength="254"
                        autocomplete="off"
                    />
                    <InputError :message="form.errors.email" />
                </div>
            </div>
        </section>

        <section class="space-y-4">
            <div>
                <h2 class="text-base font-medium">Extra permissions</h2>
                <p class="text-muted-foreground mt-1 text-sm">
                    Every admin gets basic access. Tick anything else they need.
                </p>
            </div>
            <div class="divide-border divide-y rounded-xl border">
                <div
                    v-for="permission in permissions"
                    :key="permission.code"
                    class="flex items-start gap-3 p-3"
                >
                    <Checkbox
                        :id="`permission-${permission.code}`"
                        :model-value="
                            form.permissions.includes(permission.code)
                        "
                        class="mt-0.5"
                        @update:model-value="
                            togglePermission(permission.code, $event)
                        "
                    />
                    <Label
                        :for="`permission-${permission.code}`"
                        class="cursor-pointer flex-col items-start gap-0.5 font-normal"
                    >
                        <span class="text-sm font-medium">{{
                            permission.name
                        }}</span>
                        <span
                            class="text-muted-foreground line-clamp-2 text-xs"
                            :title="permission.description"
                            >{{ permission.description }}</span
                        >
                    </Label>
                </div>
            </div>
            <InputError :message="form.errors.permissions" />
        </section>

        <div class="space-y-4 border-t pt-6">
            <div class="flex items-start gap-3">
                <Checkbox
                    id="confirmed"
                    v-model="form.confirmed"
                    class="mt-0.5"
                />
                <Label
                    for="confirmed"
                    class="cursor-pointer text-sm leading-relaxed font-normal"
                >
                    This person should be an admin with the permissions above.
                </Label>
            </div>
            <InputError :message="form.errors.confirmed" />
            <Button
                type="submit"
                class="w-full sm:w-fit"
                :disabled="form.processing"
            >
                <Loader2 v-if="form.processing" class="size-4 animate-spin" />
                <MailPlus v-else class="size-4" />
                Send invite
            </Button>
        </div>
    </form>
</template>
