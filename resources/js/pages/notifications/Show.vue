<script setup lang="ts">
import { Head, Link, router, useHttp, usePage } from '@inertiajs/vue3';
import { onMounted, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { notificationSyncState } from '@/composables/useNotificationSync';
import { dashboard } from '@/routes';
import { index, open, read } from '@/routes/notifications';
import type { InboxNotice, InboxSync } from '@/types/notifications';

const props = defineProps<{
    notification: InboxNotice;
    sync: InboxSync;
    timezone: string;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Notifications', href: index() },
            { title: 'Notice' },
        ],
    },
});
const page = usePage();
const visible = ref<InboxNotice | null>(props.notification);
const message = ref('');
const http = useHttp<
    { read: boolean; version: number },
    { id: string; read: boolean; version: number }
>({ read: true, version: props.notification.read_version });
watch(
    () => props.notification,
    (value) => {
        visible.value = value;
        message.value = '';
    },
);
watch(
    () => [
        notificationSyncState.status,
        notificationSyncState.scope,
        notificationSyncState.userId,
    ],
    () => {
        if (
            notificationSyncState.status === 'unavailable' ||
            notificationSyncState.userId !== page.props.auth.user.id ||
            (notificationSyncState.scope &&
                notificationSyncState.scope !== props.sync.scope)
        ) {
            visible.value = null;
            message.value =
                'This notice is unavailable or your access changed. Return to the inbox to refresh.';
        }
    },
);
async function mark(readState: boolean): Promise<void> {
    if (!visible.value) return;
    http.read = readState;
    http.version = visible.value.read_version;
    try {
        const result = await http.patch(read.url(visible.value.id));
        if (visible.value) {
            visible.value.read_version = result.version;
            visible.value.read_at = result.read
                ? new Date().toISOString()
                : null;
        }
    } catch {
        visible.value = null;
        message.value =
            'This notice changed or became unavailable. Refresh to retry.';
    }
}
onMounted(() => {
    if (!props.notification.read_at) void mark(true);
});
function date(value: string): string {
    return new Intl.DateTimeFormat('en-NG', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: props.timezone,
    }).format(new Date(value));
}
</script>

<template>
    <div class="space-y-6">
        <Head title="Notification" />
        <header>
            <h1 class="text-[25px] font-medium tracking-tight">Notification</h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                A notice records an outcome. Each action still needs your
                current permissions.
            </p>
        </header>
        <p v-if="message" role="status">{{ message }}</p>
        <Card v-if="visible"
            ><CardContent class="space-y-4 pt-6">
                <div class="flex flex-wrap gap-2">
                    <Badge variant="secondary">{{ visible.category }}</Badge
                    ><Badge v-if="visible.action_required" variant="outline"
                        >Action required</Badge
                    ><Badge
                        v-if="visible.visibility !== 'current'"
                        variant="outline"
                        >{{
                            visible.visibility === 'expired'
                                ? 'Expired action'
                                : 'Superseded'
                        }}</Badge
                    ><Badge variant="outline">{{
                        visible.read_at ? 'Read' : 'Unread'
                    }}</Badge>
                </div>
                <h2 class="text-xl font-medium">{{ visible.title }}</h2>
                <p>{{ visible.summary }}</p>
                <p
                    v-if="visible.reference"
                    class="text-muted-foreground text-sm"
                >
                    {{ visible.reference }}
                </p>
                <time
                    :datetime="visible.effective_at"
                    class="text-muted-foreground block text-sm"
                    >{{ date(visible.effective_at) }} ({{ timezone }})</time
                >
                <div class="flex flex-wrap gap-3">
                    <Button v-if="visible.has_destination" as-child
                        ><Link :href="open(visible.id)"
                            >Open current record</Link
                        ></Button
                    ><Button
                        variant="outline"
                        :disabled="http.processing"
                        @click="mark(visible.read_at !== null ? false : true)"
                        >{{
                            visible.read_at ? 'Mark unread' : 'Mark read'
                        }}</Button
                    >
                </div>
            </CardContent></Card
        >
        <div class="flex gap-3">
            <Button as-child variant="outline"
                ><Link :href="index()">Back to inbox</Link></Button
            ><Button v-if="!visible" variant="outline" @click="router.reload()"
                >Refresh</Button
            >
        </div>
    </div>
</template>
