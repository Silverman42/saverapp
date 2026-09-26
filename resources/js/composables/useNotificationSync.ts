import { useHttp, usePage } from '@inertiajs/vue3';
import { onMounted, onUnmounted, reactive, watch } from 'vue';
import { sync } from '@/routes/notifications';
import type { InboxSync } from '@/types/notifications';

export const notificationSyncState = reactive({
    userId: null as number | null,
    scope: null as string | null,
    unreadCount: null as number | null,
    status: 'loading' as 'loading' | 'current' | 'unavailable',
});

export function useNotificationSync(): void {
    const page = usePage();
    const http = useHttp<Record<string, never>, InboxSync>({});
    let timer: ReturnType<typeof setTimeout> | undefined;
    let active = false;
    let generation = 0;

    function reset(): void {
        generation++;
        http.cancel();
        notificationSyncState.userId = page.props.auth.user?.id ?? null;
        notificationSyncState.scope = null;
        notificationSyncState.unreadCount = null;
        notificationSyncState.status = 'loading';
    }

    async function poll(): Promise<void> {
        const started = generation;
        if (
            page.props.features.notifications &&
            document.visibilityState === 'visible' &&
            !http.processing
        ) {
            try {
                const result = await http.get(sync.url());
                if (
                    result.status !== 'current' ||
                    typeof result.scope !== 'string' ||
                    !Number.isInteger(result.unread_count)
                ) {
                    throw new Error(
                        'Notification access could not be verified.',
                    );
                }
                if (active && started === generation) {
                    notificationSyncState.scope = result.scope;
                    notificationSyncState.unreadCount = result.unread_count;
                    notificationSyncState.status = 'current';
                }
            } catch {
                if (active && started === generation) {
                    notificationSyncState.scope = null;
                    notificationSyncState.unreadCount = null;
                    notificationSyncState.status = 'unavailable';
                }
            }
        }
        if (active) timer = setTimeout(poll, 5000);
    }
    watch(() => page.props.auth.user?.id, reset);
    onMounted(() => {
        active = true;
        reset();
        void poll();
    });
    onUnmounted(() => {
        active = false;
        generation++;
        clearTimeout(timer);
        http.cancel();
        notificationSyncState.scope = null;
        notificationSyncState.unreadCount = null;
    });
}
