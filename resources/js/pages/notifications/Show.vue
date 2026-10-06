<script setup lang="ts">
import { Head, Link, router, useHttp, usePage } from '@inertiajs/vue3';
import { onMounted, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import PageHeader from '@/components/PageHeader.vue';
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
                'This notification is no longer available, or your access changed. Go back to your notifications to refresh.';
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
            'This notification changed or is no longer available. Refresh to try again.';
    }
}
onMounted(() => {
    if (!props.notification.read_at) void mark(true);
});
const categoryLabels: Record<string, string> = {
    account: 'Account',
    financial: 'Money',
    plan: 'Plan',
};
function date(value: string): string {
    return new Intl.DateTimeFormat('en-NG', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: props.timezone,
    }).format(new Date(value));
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head title="Notification" />
        <PageHeader :title="visible?.title ?? 'Notification'">
            <template #actions>
                <Button
                    v-if="visible"
                    variant="outline"
                    :disabled="http.processing"
                    @click="mark(visible.read_at !== null ? false : true)"
                    >{{ visible.read_at ? 'Mark unread' : 'Mark read' }}</Button
                >
            </template>
        </PageHeader>
        <p v-if="message" role="status" class="bg-muted rounded-xl p-4 text-sm">
            {{ message }}
        </p>
        <Card v-if="visible" class="max-w-3xl">
            <CardContent class="space-y-5">
                <div class="flex flex-wrap items-center gap-2">
                    <Badge variant="secondary">{{
                        categoryLabels[visible.category] ?? visible.category
                    }}</Badge
                    ><Badge v-if="visible.action_required">Needs action</Badge
                    ><Badge
                        v-if="visible.visibility !== 'current'"
                        variant="outline"
                        >{{
                            visible.visibility === 'expired'
                                ? 'Action expired'
                                : 'Replaced by a newer one'
                        }}</Badge
                    >
                    <span class="sr-only">{{
                        visible.read_at ? 'Read' : 'Unread'
                    }}</span>
                </div>
                <p class="leading-relaxed">{{ visible.summary }}</p>
                <div
                    class="text-muted-foreground flex flex-wrap items-center gap-2 text-sm"
                >
                    <time
                        :datetime="visible.effective_at"
                        :title="`Time zone: ${timezone}`"
                        >{{ date(visible.effective_at) }}</time
                    >
                    <template v-if="visible.reference">
                        <span aria-hidden="true">·</span>
                        <span>{{ visible.reference }}</span>
                    </template>
                </div>
                <Button v-if="visible.has_destination" as-child
                    ><Link :href="open(visible.id)">Open record</Link></Button
                >
            </CardContent>
        </Card>
        <div class="flex gap-3">
            <Button as-child variant="ghost"
                ><Link :href="index()">Back to notifications</Link></Button
            ><Button v-if="!visible" variant="outline" @click="router.reload()"
                >Refresh</Button
            >
        </div>
    </div>
</template>
