<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Loader2, ShieldAlert } from '@lucide/vue';
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
import { DatePicker } from '@/components/ui/date-picker';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import {
    index as recoveriesIndex,
    store as recoveriesStore,
} from '@/routes/admin/staff-recoveries';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Account recoveries', href: recoveriesIndex() },
            { title: 'Request recovery', href: '#' },
        ],
    },
});

const props = defineProps<{
    target: { id: number; name: string; email: string; type: string };
    required_approvals: number;
}>();

const form = useForm({
    email: props.target.email,
    procedure_reference: '',
    notes: '',
    verified_at: new Date().toISOString().slice(0, 16),
    identity_verified: false,
});

const recoveryError = computed(
    () => (form.errors as Record<string, string | undefined>).recovery,
);

const submit = (): void => {
    form.post(recoveriesStore.url(props.target.id), { preserveScroll: true });
};
</script>

<template>
    <Head title="Request account recovery" />

    <div class="space-y-6">
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">
                Request account recovery
            </h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                {{ target.name }} ({{ target.type }}) cannot use their normal
                password or authenticator recovery. Nothing changes on the
                account until
                {{
                    required_approvals === 2
                        ? 'two other Administrators approve'
                        : 'another Administrator approves'
                }}.
            </p>
        </div>

        <form class="max-w-3xl" @submit.prevent="submit">
            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <ShieldAlert class="size-4" /> Identity verification
                    </CardTitle>
                    <CardDescription>
                        Use the approved business procedure to verify the person
                        outside this account. You do not set or see their new
                        password or authenticator.
                    </CardDescription>
                </CardHeader>
                <CardContent class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1.5 sm:col-span-2">
                        <Label for="email"
                            >Verified email for the recovery link</Label
                        >
                        <Input
                            id="email"
                            v-model="form.email"
                            type="email"
                            required
                            maxlength="254"
                        />
                        <p
                            v-if="form.errors.email"
                            class="text-destructive text-xs"
                        >
                            {{ form.errors.email }}
                        </p>
                    </div>
                    <div class="space-y-1.5">
                        <Label for="procedure"
                            >Verification procedure reference</Label
                        >
                        <Input
                            id="procedure"
                            v-model="form.procedure_reference"
                            required
                            maxlength="150"
                        />
                        <p
                            v-if="form.errors.procedure_reference"
                            class="text-destructive text-xs"
                        >
                            {{ form.errors.procedure_reference }}
                        </p>
                    </div>
                    <div class="space-y-1.5">
                        <Label for="verified-at">Verified at</Label>
                        <DatePicker
                            id="verified-at"
                            v-model="form.verified_at"
                            with-time
                            required
                        />
                        <p
                            v-if="form.errors.verified_at"
                            class="text-destructive text-xs"
                        >
                            {{ form.errors.verified_at }}
                        </p>
                    </div>
                    <div class="space-y-1.5 sm:col-span-2">
                        <Label for="notes">Verification notes</Label>
                        <textarea
                            id="notes"
                            v-model="form.notes"
                            required
                            maxlength="2000"
                            rows="4"
                            class="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                        />
                        <p
                            v-if="form.errors.notes"
                            class="text-destructive text-xs"
                        >
                            {{ form.errors.notes }}
                        </p>
                    </div>
                </CardContent>
                <CardFooter
                    class="flex flex-col items-stretch gap-4 border-t pt-6"
                >
                    <div class="flex items-start gap-3">
                        <Checkbox
                            id="identity-verified"
                            v-model="form.identity_verified"
                            class="mt-1"
                        />
                        <Label
                            for="identity-verified"
                            class="cursor-pointer text-sm leading-relaxed font-normal"
                        >
                            I verified this person's identity outside the
                            affected account.
                        </Label>
                    </div>
                    <p
                        v-if="form.errors.identity_verified"
                        class="text-destructive text-xs"
                    >
                        {{ form.errors.identity_verified }}
                    </p>
                    <p
                        v-if="recoveryError"
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        {{ recoveryError }}
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
                        Request recovery
                    </Button>
                </CardFooter>
            </Card>
        </form>
    </div>
</template>
