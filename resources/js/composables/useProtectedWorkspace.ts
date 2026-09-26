import { router, usePage, usePoll } from '@inertiajs/vue3';
import { onUnmounted, ref, watch } from 'vue';
import type { AdminPermission } from '@/types/auth';
export function useProtectedWorkspace(
    scope: () => string,
    permission?: AdminPermission,
) {
    const page = usePage();
    const visible = ref(true);
    const notice = ref('');
    function clear(): void {
        visible.value = false;
        notice.value =
            'Access or data could not be verified. Refresh to continue.';
        router.clearHistory();
    }
    watch(scope, (value, previous) => {
        if (value !== previous) {
            clear();
        }
    });
    watch(
        () => page.props.auth,
        (auth) => {
            if (
                !auth?.user ||
                auth.user.user_type !== 'admin' ||
                (permission !== undefined &&
                    !auth.permissions.includes(permission))
            ) {
                clear();
            }
        },
        { deep: true, immediate: true },
    );
    const remove = router.on('start', (event) => {
        if (/\/(logout|login)(?:$|\?)/.test(event.detail.visit.url.pathname)) {
            clear();
        }
    });
    const removeHttp = router.on('httpException', clear);
    const removeNetwork = router.on('networkError', clear);
    onUnmounted(() => {
        remove();
        removeHttp();
        removeNetwork();
    });
    usePoll(5000, {
        only: ['scope'],
        onHttpException: clear,
        onNetworkError: clear,
    });
    function refresh(): void {
        router.reload({
            onSuccess: () => {
                visible.value = true;
                notice.value = '';
            },
            onHttpException: clear,
            onNetworkError: clear,
        });
    }
    return { visible, notice, refresh, clear };
}
