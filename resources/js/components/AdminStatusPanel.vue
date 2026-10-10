<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Ban, PauseCircle, PlayCircle } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { update as updateStatus } from '@/routes/admin/access/status';

export type AdminStatusAction = 'suspend' | 'reactivate' | 'deactivate';

export type AdminStatusSummary = {
    version: number;
    actions: AdminStatusAction[];
};

const props = defineProps<{
    adminId: number;
    adminName: string;
    status: AdminStatusSummary;
}>();

const copy: Record<
    AdminStatusAction,
    { label: string; title: string; description: string; confirm: string }
> = {
    suspend: {
        label: 'Suspend',
        title: 'Suspend this admin',
        description:
            'They are signed out everywhere and cannot sign in until another admin restores access. Their permissions and history stay as they are.',
        confirm: 'I understand they will lose access straight away.',
    },
    reactivate: {
        label: 'Restore access',
        title: 'Restore access',
        description:
            'They can sign in again. If their authenticator or recovery codes are not set up, they finish that first.',
        confirm: 'I have checked that this admin should have access again.',
    },
    deactivate: {
        label: 'Deactivate',
        title: 'Deactivate this admin',
        description:
            'Deactivation is permanent. They are signed out everywhere and the account cannot be used again. Their history is kept.',
        confirm: 'I understand this cannot be undone.',
    },
};

const icons = {
    suspend: PauseCircle,
    reactivate: PlayCircle,
    deactivate: Ban,
};

const openAction = ref<AdminStatusAction | null>(null);
const form = useForm({
    action: '' as AdminStatusAction | '',
    reason: '',
    expected_version: props.status.version,
    confirmed: false,
});

const dialogOpen = computed({
    get: () => openAction.value !== null,
    set: (open: boolean) => {
        if (!open) {
            openAction.value = null;
        }
    },
});

const start = (action: AdminStatusAction): void => {
    form.reset();
    form.clearErrors();
    form.action = action;
    form.expected_version = props.status.version;
    openAction.value = action;
};

const submit = (): void => {
    form.patch(updateStatus(props.adminId).url, {
        preserveScroll: true,
        onSuccess: () => {
            openAction.value = null;
            form.reset();
        },
    });
};
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="text-base">Account access</CardTitle>
            <CardDescription>
                Suspend {{ adminName }}, restore their access, or close the
                account for good.
            </CardDescription>
        </CardHeader>
        <CardContent class="flex flex-row flex-wrap gap-2">
            <Button
                v-for="action in status.actions"
                :key="action"
                size="sm"
                :variant="action === 'reactivate' ? 'default' : 'outline'"
                :class="{ 'text-destructive': action === 'deactivate' }"
                @click="start(action)"
            >
                <component :is="icons[action]" class="size-4" />
                {{ copy[action].label }}
            </Button>
        </CardContent>

        <Dialog v-model:open="dialogOpen">
            <DialogContent v-if="openAction" class="sm:max-w-md">
                <form class="space-y-5" @submit.prevent="submit">
                    <DialogHeader>
                        <DialogTitle>{{ copy[openAction].title }}</DialogTitle>
                        <DialogDescription>
                            {{ copy[openAction].description }}
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-2">
                        <Label for="admin-status-reason">Reason</Label>
                        <Input
                            id="admin-status-reason"
                            v-model="form.reason"
                            required
                            maxlength="500"
                        />
                        <InputError :message="form.errors.reason" />
                    </div>
                    <div class="flex items-start gap-2">
                        <Checkbox
                            id="admin-status-confirm"
                            :model-value="form.confirmed"
                            @update:model-value="
                                (val) => (form.confirmed = val === true)
                            "
                        />
                        <Label
                            for="admin-status-confirm"
                            class="text-sm leading-snug font-normal"
                        >
                            {{ copy[openAction].confirm }}
                        </Label>
                    </div>
                    <InputError
                        :message="
                            form.errors.action ??
                            form.errors.confirmed ??
                            form.errors.expected_version
                        "
                    />
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="openAction = null"
                        >
                            Keep as is
                        </Button>
                        <Button
                            type="submit"
                            :variant="
                                openAction === 'reactivate'
                                    ? 'default'
                                    : 'destructive'
                            "
                            :disabled="
                                form.processing ||
                                !form.confirmed ||
                                form.reason.trim() === ''
                            "
                        >
                            {{ copy[openAction].label }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </Card>
</template>
