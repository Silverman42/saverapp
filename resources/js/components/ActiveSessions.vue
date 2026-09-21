<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import {
    Laptop,
    LogOut,
    Monitor,
    Shield,
    Smartphone,
    Trash2,
} from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

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
    <div class="space-y-6">
        <div
            class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
        >
            <Heading
                variant="small"
                title="Active Sessions & Devices"
                :description="`Manage devices that are currently signed in to your account. Your account allows up to ${props.maxDevices} concurrent active devices.`"
            />

            <div class="flex flex-wrap items-center gap-2">
                <Form
                    v-if="
                        props.sessions.filter((s) => !s.is_current_device)
                            .length > 0
                    "
                    action="/sessions/revoke-others"
                    method="post"
                    #default="{ processing }"
                >
                    <Button
                        variant="outline"
                        size="sm"
                        type="submit"
                        :disabled="processing"
                        class="gap-1.5"
                    >
                        <LogOut class="size-4" />
                        Sign Out Other Devices
                    </Button>
                </Form>

                <Form
                    action="/sessions/revoke-all"
                    method="post"
                    #default="{ processing }"
                >
                    <Button
                        variant="destructive"
                        size="sm"
                        type="submit"
                        :disabled="processing"
                        class="gap-1.5"
                    >
                        <Trash2 class="size-4" />
                        Sign Out Everywhere
                    </Button>
                </Form>
            </div>
        </div>

        <div
            class="border-border divide-border divide-y overflow-hidden rounded-xl border"
        >
            <div
                v-for="session in props.sessions"
                :key="session.id"
                class="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="flex items-start gap-3.5">
                    <div
                        class="bg-muted text-muted-foreground mt-0.5 rounded-lg p-2"
                    >
                        <component
                            :is="getDeviceIcon(session.device_name)"
                            class="size-5"
                        />
                    </div>

                    <div class="space-y-1">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-medium">
                                {{ session.device_name }}
                            </span>
                            <Badge
                                v-if="session.is_current_device"
                                variant="secondary"
                                class="text-xs"
                            >
                                This Device
                            </Badge>
                        </div>

                        <div
                            class="text-muted-foreground flex flex-wrap items-center gap-x-4 gap-y-1 text-xs"
                        >
                            <span
                                >Approx. network: {{ session.masked_ip }}</span
                            >
                            <span
                                >First sign-in:
                                {{ formatTime(session.first_sign_in_at) }}</span
                            >
                            <span
                                >Last active:
                                {{ formatTime(session.last_active_at) }}</span
                            >
                        </div>
                    </div>
                </div>

                <div v-if="!session.is_current_device" class="shrink-0">
                    <Form
                        :action="`/sessions/${session.id}`"
                        method="delete"
                        #default="{ processing }"
                    >
                        <Button
                            variant="ghost"
                            size="sm"
                            type="submit"
                            :disabled="processing"
                            class="text-destructive hover:bg-destructive/10 hover:text-destructive text-xs"
                        >
                            Sign Out
                        </Button>
                    </Form>
                </div>
            </div>

            <div
                v-if="props.sessions.length === 0"
                class="text-muted-foreground p-6 text-center text-sm"
            >
                No active sessions found.
            </div>
        </div>
    </div>
</template>
