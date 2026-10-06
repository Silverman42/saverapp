<script setup lang="ts">
import { router, useHttp, usePage } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { access, index } from '@/routes/customers';
import type { Auth } from '@/types';
const page = usePage<{
    auth: Auth;
    customer?: { id?: string; reference?: string; customer_id?: string };
    receipt?: { customer_id?: string };
    withdrawal?: { customer_id?: string };
    reversal?: { customer_id?: string };
}>();
const inaccessible = ref(false);
const scope = useHttp({ context: 'customer' });
let interval: ReturnType<typeof setInterval> | undefined;
async function recheckCustomerAccess(): Promise<void> {
    const reference =
        page.url.match(/^\/customers\/(CUS-[^/?]+)(?:[/?]|$)/)?.[1] ??
        page.props.customer?.reference ??
        page.props.customer?.customer_id ??
        page.props.customer?.id ??
        page.props.receipt?.customer_id ??
        page.props.withdrawal?.customer_id ??
        page.props.reversal?.customer_id;
    if (!reference || scope.processing) return;
    scope.context =
        page.component === 'customers/Reassign'
            ? 'reassignment'
            : page.component === 'customers/Recovery'
              ? 'recovery'
              : 'customer';
    try {
        const result = (await scope.get(access.url(reference), {
            headers: { 'X-Passive-Polling': 'true' },
        })) as { accessible?: boolean };
        if (result.accessible !== true) {
            inaccessible.value = true;
            router.visit(index());
        }
    } catch (error) {
        const status = (error as { response?: { status?: number } }).response
            ?.status;
        if (status && [401, 403, 404, 419].includes(status)) {
            inaccessible.value = true;
            router.visit(index());
        }
    }
}
watch(
    () => page.url,
    () => {
        inaccessible.value = false;
        void recheckCustomerAccess();
    },
);
onMounted(() => {
    interval = setInterval(() => {
        if (document.visibilityState === 'visible')
            void recheckCustomerAccess();
    }, 10000);
    window.addEventListener('focus', recheckCustomerAccess);
    void recheckCustomerAccess();
});
onUnmounted(() => {
    clearInterval(interval);
    window.removeEventListener('focus', recheckCustomerAccess);
});
import PlatformBanner from '@/components/PlatformBanner.vue';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent
            variant="sidebar"
            class="bg-sidebar min-w-0 overflow-x-clip"
        >
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <div
                class="mx-auto w-full max-w-[1350] px-4 py-6 sm:px-6 lg:px-8 lg:py-8"
            >
                <PlatformBanner />
                <slot v-if="!inaccessible" />
                <p v-else role="status">
                    You no longer have access to this customer.
                </p>
            </div>
        </AppContent>
        <Toaster />
    </AppShell>
</template>
