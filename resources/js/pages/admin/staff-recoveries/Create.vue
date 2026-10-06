<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Loader2 } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
            { title: 'New request', href: '#' },
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

const approvalText = computed(() =>
    props.required_approvals === 2
        ? 'two other admins approve'
        : 'another admin approves',
);

const submit = (): void => {
    form.post(recoveriesStore.url(props.target.id), { preserveScroll: true });
};
</script>

<template>
    <Head title="Account recovery request" />

    <div class="space-y-6">
        <PageHeader
            :title="`Help ${target.name} get back in`"
            :description="`Nothing changes on the account until ${approvalText}.`"
        />

        <Card class="max-w-3xl">
            <CardContent>
                <form class="space-y-8" @submit.prevent="submit">
                    <section class="space-y-4">
                        <div>
                            <h2 class="text-base font-medium">
                                Check who they are
                            </h2>
                            <p class="text-muted-foreground mt-1 text-sm">
                                Confirm their identity outside the app first.
                                You will not see their new password.
                            </p>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2 sm:col-span-2">
                                <Label for="email"
                                    >Email for the recovery link</Label
                                >
                                <Input
                                    id="email"
                                    v-model="form.email"
                                    type="email"
                                    required
                                    maxlength="254"
                                />
                                <InputError :message="form.errors.email" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="procedure">Check reference</Label>
                                <Input
                                    id="procedure"
                                    v-model="form.procedure_reference"
                                    required
                                    maxlength="150"
                                    aria-describedby="procedure-help"
                                />
                                <p
                                    id="procedure-help"
                                    class="text-muted-foreground text-xs"
                                >
                                    The reference from your ID check.
                                </p>
                                <InputError
                                    :message="form.errors.procedure_reference"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="verified-at">Checked on</Label>
                                <DatePicker
                                    id="verified-at"
                                    v-model="form.verified_at"
                                    with-time
                                    required
                                />
                                <InputError
                                    :message="form.errors.verified_at"
                                />
                            </div>
                            <div class="grid gap-2 sm:col-span-2">
                                <Label for="notes">What did you check?</Label>
                                <textarea
                                    id="notes"
                                    v-model="form.notes"
                                    required
                                    maxlength="2000"
                                    rows="4"
                                    class="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                />
                                <InputError :message="form.errors.notes" />
                            </div>
                        </div>
                    </section>

                    <div class="space-y-4 border-t pt-6">
                        <div class="flex items-start gap-3">
                            <Checkbox
                                id="identity-verified"
                                v-model="form.identity_verified"
                                class="mt-0.5"
                            />
                            <Label
                                for="identity-verified"
                                class="cursor-pointer text-sm leading-relaxed font-normal"
                            >
                                I checked this person's identity myself, outside
                                their account.
                            </Label>
                        </div>
                        <InputError :message="form.errors.identity_verified" />
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
                                class="size-4 animate-spin"
                            />
                            Send for approval
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    </div>
</template>
