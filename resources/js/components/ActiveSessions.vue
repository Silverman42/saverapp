<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Laptop, LogOut, Monitor, Smartphone } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

export type SessionItem = {
    id: string;
    device_name: string;
    masked_ip: string;
    first_sign_in_at: string;
    last_active_at: string;
    last_active_timestamp: number;
    is_current_device: boolean;
};

export type Props = {
    sessions?: SessionItem[];
    maxDevices?: number;
};

const props = withDefaults(defineProps<Props>(), {
    sessions: () => [],
    maxDevices: 1,
});

const signOutEverywhereOpen = ref(false);
const otherSessionCount = computed(
    () => props.sessions.filter((s) => !s.is_current_device).length,
);

const getDeviceIcon = (deviceName: string) => {
    const lower = deviceName.toLowerCase();
    if (
        lower.includes('ios') ||
        lower.includes('iphone') ||
        lower.includes('android')
    ) {
        return Smartphone;
    }
    if (
        lower.includes('mac') ||
        lower.includes('windows') ||
        lower.includes('linux')
    ) {
        return Laptop;
    }
    return Monitor;
};

const formatTime = (isoString: string): string => {
    try {
        const date = new Date(isoString);
        return date.toLocaleString(undefined, {
            dateStyle: 'medium',
            timeStyle: 'short',
        });
    } catch {
        return isoString;
    }
};
</script>

<template>
    <div class="space-y-4">
        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                variant="small"
                title="Signed-in devices"
                :description="`You can be signed in on up to ${props.maxDevices} device${props.maxDevices === 1 ? '' : 's'} at once.`"
            />

            <Form
                v-if="otherSessionCount > 0"
                action="/sessions/revoke-others"
                method="post"
                #default="{ processing }"
            >
                <Button
                    variant="outline"
                    size="sm"
                    type="submit"
                    :disabled="processing"
                >
                    <LogOut class="size-4" />
                    Sign out others
                </Button>
            </Form>
        </div>

        <div
            class="border-border divide-border divide-y overflow-hidden rounded-xl border"
        >
            <div
                v-for="session in props.sessions"
                :key="session.id"
                class="flex items-center justify-between gap-4 p-4"
                :title="`First signed in ${formatTime(session.first_sign_in_at)}`"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="bg-muted text-muted-foreground shrink-0 rounded-lg p-2"
                    >
                        <component
                            :is="getDeviceIcon(session.device_name)"
                            class="size-5"
                        />
                    </div>

                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="truncate text-sm font-medium">
                                {{ session.device_name }}
                            </span>
                            <Badge
                                v-if="session.is_current_device"
                                variant="secondary"
                                class="text-xs"
                            >
                                This device
                            </Badge>
                        </div>
                        <p class="text-muted-foreground mt-0.5 text-xs">
                            Last active
                            {{ formatTime(session.last_active_at) }} ·
                            {{ session.masked_ip }}
                        </p>
                    </div>
                </div>

                <Form
                    v-if="!session.is_current_device"
                    :action="`/sessions/${session.id}`"
                    method="delete"
                    #default="{ processing }"
                    class="shrink-0"
                >
                    <Button
                        variant="ghost"
                        size="sm"
                        type="submit"
                        :disabled="processing"
                        class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                    >
                        Sign out
                    </Button>
                </Form>
            </div>

            <div
                v-if="props.sessions.length === 0"
                class="text-muted-foreground p-6 text-center text-sm"
            >
                No signed-in devices found.
            </div>
        </div>

        <Button
            variant="ghost"
            size="sm"
            class="text-destructive hover:bg-destructive/10 hover:text-destructive"
            @click="signOutEverywhereOpen = true"
        >
            Sign out everywhere
        </Button>

        <Dialog v-model:open="signOutEverywhereOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Sign out everywhere?</DialogTitle>
                    <DialogDescription>
                        You will be signed out on all devices, including this
                        one. Each device will ask for your code again.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <Button
                        variant="outline"
                        @click="signOutEverywhereOpen = false"
                        >Cancel</Button
                    >
                    <Form
                        action="/sessions/revoke-all"
                        method="post"
                        #default="{ processing }"
                    >
                        <Button
                            variant="destructive"
                            type="submit"
                            class="w-full"
                            :disabled="processing"
                        >
                            Sign out everywhere
                        </Button>
                    </Form>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
