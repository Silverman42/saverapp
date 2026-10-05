<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Loader2, MailPlus } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { index as adminAccessIndex } from '@/routes/admin/access';
import {
    create as invitationsCreate,
    store as invitationsStore,
} from '@/routes/admin/access/invitations';

type GrantablePermission = { code: string; name: string; description: string };

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Admin access', href: adminAccessIndex() },
            { title: 'Invite Administrator', href: invitationsCreate() },
        ],
    },
});

const props = defineProps<{
    attempt_reference: string;
    permissions: GrantablePermission[];
}>();

const form = useForm({
    attempt_reference: props.attempt_reference,
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
    <Head title="Invite Administrator" />

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-[25px] font-medium tracking-tight">
                    Invite Administrator
                </h1>
                <p class="text-muted-foreground mt-1.5 text-sm">
                    The new Administrator activates with a single-use link,
                    creates their own password and sets up two-factor
                    authentication. You never choose or see their password.
                </p>
            </div>
            <Link :href="adminAccessIndex()">
                <Button variant="outline">
                    <ArrowLeft class="mr-2 size-4" />
                    Back to directory
                </Button>
            </Link>
        </div>

        <form class="max-w-3xl space-y-6" @submit.prevent="submit">
            <Card>
                <CardHeader>
                    <CardTitle>Identity</CardTitle>
                    <CardDescription>
                        The invitation is sent to this address and expires after
                        24 hours.
                    </CardDescription>
                </CardHeader>
                <CardContent class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <Label for="name">Full name</Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            required
                            maxlength="150"
                            autocomplete="off"
                        />
                        <p
                            v-if="form.errors.name"
                            class="text-destructive text-xs"
                        >
                            {{ form.errors.name }}
                        </p>
                    </div>
                    <div class="space-y-1.5">
                        <Label for="email">Email address</Label>
                        <Input
                            id="email"
                            v-model="form.email"
                            type="email"
                            required
                            maxlength="254"
                            autocomplete="off"
                        />
                        <p
                            v-if="form.errors.email"
                            class="text-destructive text-xs"
                        >
                            {{ form.errors.email }}
                        </p>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Initial permissions</CardTitle>
                    <CardDescription>
                        Every Administrator has baseline access. Select any
                        additional permissions; the invited Administrator cannot
                        change them during activation.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-3">
                    <div
                        v-for="permission in permissions"
                        :key="permission.code"
                        class="flex items-start gap-3"
                    >
                        <Checkbox
                            :id="`permission-${permission.code}`"
                            :model-value="
                                form.permissions.includes(permission.code)
                            "
                            class="mt-1"
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
                            <span class="text-muted-foreground text-xs">{{
                                permission.description
                            }}</span>
                        </Label>
                    </div>
                    <p
                        v-if="form.errors.permissions"
                        class="text-destructive text-xs"
                    >
                        {{ form.errors.permissions }}
                    </p>
                </CardContent>
                <CardFooter
                    class="flex flex-col items-stretch gap-4 border-t pt-6"
                >
                    <div class="flex items-start gap-3">
                        <Checkbox
                            id="confirmed"
                            v-model="form.confirmed"
                            class="mt-1"
                        />
                        <Label
                            for="confirmed"
                            class="cursor-pointer text-sm leading-relaxed font-normal"
                        >
                            I confirm this person should receive Administrator
                            access with the permissions selected above.
                        </Label>
                    </div>
                    <p
                        v-if="form.errors.confirmed"
                        class="text-destructive text-xs"
                    >
                        {{ form.errors.confirmed }}
                    </p>
                    <Button
                        type="submit"
                        class="w-full sm:w-fit"
                        :disabled="form.processing"
                    >
                        <Loader2
                            v-if="form.processing"
                            class="mr-2 size-4 animate-spin"
                        />
                        <MailPlus v-else class="mr-2 size-4" />
                        Send invitation
                    </Button>
                </CardFooter>
            </Card>
        </form>
    </div>
</template>
