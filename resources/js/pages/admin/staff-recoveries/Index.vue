<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import {
    decide,
    index as recoveriesIndex,
} from '@/routes/admin/staff-recoveries';

type Recovery = {
    reference: string;
    user: { id: number; name: string; type: string };
    requested_by: string;
    state: string;
    version: number;
    approvals: number;
    required_approvals: number;
    created_at: string | null;
    request_expires_at: string;
    activation_expires_at: string | null;
    can_decide: boolean;
    can_cancel: boolean;
    can_reissue: boolean;
};
type Action = 'approve' | 'reject' | 'cancel' | 'reissue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Account recoveries', href: recoveriesIndex() },
        ],
    },
});

defineProps<{ recoveries: Recovery[] }>();

const active = ref<{ reference: string; action: Action } | null>(null);
const form = useForm({ version: 0, reason: '' });

const open = (recovery: Recovery, action: Action): void => {
    active.value = { reference: recovery.reference, action };
    form.version = recovery.version;
    form.reason = '';
    form.clearErrors();
};

const submit = (): void => {
    if (!active.value) return;
    form.post(
        decide.url({
            recovery: active.value.reference,
            action: active.value.action,
        }),
        { preserveScroll: true, onSuccess: () => (active.value = null) },
    );
};

const label = (value: string): string => value.replaceAll('_', ' ');
const formatDate = (value: string | null): string =>
    value ? new Date(value).toLocaleString() : '—';
</script>

<template>
    <Head title="Account recoveries" />

    <div class="space-y-6">
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">
                Account recoveries
            </h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                Assisted recoveries for Agents and Administrators. The requester
                and the recovering user cannot approve. Admin recoveries need
                two approvers when two are available.
            </p>
        </div>

        <p v-if="recoveries.length === 0" class="text-muted-foreground text-sm">
            No recoveries to review.
        </p>

        <Card v-for="recovery in recoveries" :key="recovery.reference">
            <CardHeader class="pb-3">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <CardTitle class="text-base font-semibold">
                            {{ recovery.user.name }}
                            <span
                                class="text-muted-foreground font-normal capitalize"
                                >· {{ recovery.user.type }}</span
                            >
                        </CardTitle>
                        <CardDescription>
                            Requested by {{ recovery.requested_by }} on
                            {{ formatDate(recovery.created_at) }}
                        </CardDescription>
                    </div>
                    <Badge variant="outline" class="capitalize">{{
                        label(recovery.state)
                    }}</Badge>
                </div>
            </CardHeader>
            <CardContent class="space-y-4">
                <dl class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-muted-foreground text-xs font-medium">
                            Approvals
                        </dt>
                        <dd class="mt-1">
                            {{ recovery.approvals }} of
                            {{ recovery.required_approvals }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs font-medium">
                            Request expires
                        </dt>
                        <dd class="mt-1">
                            {{ formatDate(recovery.request_expires_at) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs font-medium">
                            Link expires
                        </dt>
                        <dd class="mt-1">
                            {{ formatDate(recovery.activation_expires_at) }}
                        </dd>
                    </div>
                </dl>

                <div class="flex flex-wrap gap-3 border-t pt-4">
                    <template v-if="recovery.can_decide">
                        <Button size="sm" @click="open(recovery, 'approve')"
                            >Approve</Button
                        >
                        <Button
                            size="sm"
                            variant="outline"
                            @click="open(recovery, 'reject')"
                            >Reject</Button
                        >
                    </template>
                    <Button
                        v-if="recovery.can_cancel"
                        size="sm"
                        variant="outline"
                        class="text-destructive"
                        @click="open(recovery, 'cancel')"
                    >
                        Cancel
                    </Button>
                    <Button
                        v-if="recovery.can_reissue"
                        size="sm"
                        variant="outline"
                        @click="open(recovery, 'reissue')"
                    >
                        Reissue link
                    </Button>
                </div>

                <form
                    v-if="active?.reference === recovery.reference"
                    class="space-y-3"
                    @submit.prevent="submit"
                >
                    <div class="space-y-1.5">
                        <Label
                            :for="`reason-${recovery.reference}`"
                            class="capitalize"
                            >Reason to {{ active.action }}</Label
                        >
                        <Input
                            :id="`reason-${recovery.reference}`"
                            v-model="form.reason"
                            required
                            maxlength="500"
                        />
                    </div>
                    <ul
                        v-if="form.hasErrors"
                        role="alert"
                        class="text-destructive text-xs"
                    >
                        <li v-for="(error, field) in form.errors" :key="field">
                            {{ error }}
                        </li>
                    </ul>
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="form.processing"
                        class="capitalize"
                    >
                        Confirm {{ active.action }}
                    </Button>
                </form>
            </CardContent>
        </Card>
    </div>
</template>
