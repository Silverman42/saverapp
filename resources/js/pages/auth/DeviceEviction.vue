<script setup lang="ts">
import { Form, Head, setLayoutProps } from '@inertiajs/vue3';
import { useVuelidate } from '@vuelidate/core';
import { helpers, required } from '@vuelidate/validators';
import {
    Laptop,
    LogOut,
    Monitor,
    ShieldAlert,
    Smartphone,
    XCircle,
} from '@lucide/vue';
import { computed, reactive, watchEffect } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type SessionItem = {
    id: string;
    device_name: string;
    masked_ip: string;
    first_sign_in_at: string;
    last_active_at: string;
    last_active_timestamp: number;
    is_current_device: boolean;
};

type Props = {
    sessions: SessionItem[];
    userType: string;
    maxDevices: number;
};

const props = defineProps<Props>();

const formState = reactive({
    session_id: props.sessions.length > 0 ? props.sessions[0].id : '',
});

const rules = {
    session_id: {
        required: helpers.withMessage(
            'Please select a device session to revoke.',
            required,
        ),
    },
};

const v$ = useVuelidate(rules, formState);

const isAdmin = computed(() => props.userType === 'admin');

const pageTitle = computed(() =>
    isAdmin.value ? 'Active Session Found' : 'Device Limit Reached',
);

const pageDescription = computed(() =>
    isAdmin.value
        ? 'Administrator accounts are strictly restricted to 1 active session. Confirm revoking your existing session to sign in here.'
        : `Your account allows a maximum of ${props.maxDevices} concurrent devices. Select an existing session to sign out before continuing.`,
);

watchEffect(() => {
    setLayoutProps({
        title: pageTitle.value,
        description: pageDescription.value,
    });
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
        <Head :title="pageTitle" />

        <!-- Advisory Banner -->
        <div
            class="flex items-start gap-3 rounded-lg border border-amber-500/20 bg-amber-500/10 p-4 text-sm text-amber-950 dark:text-amber-200"
        >
            <ShieldAlert
                class="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-400"
            />
            <div class="space-y-1">
                <p class="font-medium">
                    {{
                        isAdmin
                            ? 'Single Device Policy'
                            : 'Concurrent Device Limit'
                    }}
                </p>
                <p class="text-muted-foreground text-xs leading-relaxed">
                    {{ pageDescription }}
                </p>
            </div>
        </div>

        <!-- Eviction Form -->
        <Form
            action="/device-eviction/confirm"
            method="post"
            class="space-y-4"
            @submit="v$.$touch()"
            #default="{ processing, errors }"
        >
            <input
                type="hidden"
                name="session_id"
                :value="formState.session_id"
            />

            <div class="space-y-3">
                <Label
                    class="text-muted-foreground text-xs font-semibold tracking-wider uppercase"
                >
                    Select active session to sign out:
                </Label>

                <div class="space-y-2">
                    <div
                        v-for="session in props.sessions"
                        :key="session.id"
                        @click="formState.session_id = session.id"
                        class="border-border flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition-colors"
                        :class="{
                            'border-primary bg-primary/5 ring-primary ring-1':
                                formState.session_id === session.id,
                            'hover:bg-muted/50':
                                formState.session_id !== session.id,
                        }"
                    >
                        <div class="mt-0.5">
                            <input
                                type="radio"
                                name="selected_session"
                                :value="session.id"
                                :checked="formState.session_id === session.id"
                                class="text-primary size-4"
                                @change="formState.session_id = session.id"
                            />
                        </div>

                        <div class="flex-1 space-y-1">
                            <div class="flex items-center gap-2">
                                <component
                                    :is="getDeviceIcon(session.device_name)"
                                    class="text-muted-foreground size-4"
                                />
                                <span class="text-sm font-medium">
                                    {{ session.device_name }}
                                </span>
                            </div>

                            <div
                                class="text-muted-foreground grid grid-cols-1 gap-1 text-xs sm:grid-cols-2"
                            >
                                <div>Network: {{ session.masked_ip }}</div>
                                <div>
                                    Last active:
                                    {{ formatTime(session.last_active_at) }}
                                </div>
                                <div class="col-span-full">
                                    Signed in:
                                    {{ formatTime(session.first_sign_in_at) }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <InputError
                    :message="
                        errors?.session_id ||
                        (v$.session_id.$error
                            ? String(v$.session_id.$errors[0]?.$message)
                            : undefined)
                    "
                />
            </div>

            <div class="space-y-2 pt-2">
                <Button
                    type="submit"
                    class="w-full"
                    :disabled="processing || !formState.session_id"
                >
                    <LogOut class="mr-2 size-4" />
                    Revoke Selected Device and Continue
                </Button>
            </div>
        </Form>

        <!-- Cancel Form -->
        <Form
            action="/device-eviction/cancel"
            method="post"
            class="text-center"
        >
            <Button
                variant="ghost"
                size="sm"
                type="submit"
                class="text-muted-foreground hover:text-foreground gap-1.5"
            >
                <XCircle class="size-4" />
                Cancel Sign In
            </Button>
        </Form>
    </div>
</template>
