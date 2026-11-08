<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, watch } from 'vue';
import { SidebarProvider } from '@/components/ui/sidebar';
import {
    applyAmountMasking,
    useAmountVisibility,
} from '@/composables/useAmountVisibility';
import type { AppVariant } from '@/types';

type Props = {
    variant?: AppVariant;
};

withDefaults(defineProps<Props>(), {
    variant: 'sidebar',
});

const page = usePage();
const isOpen = page.props.sidebarOpen;

const { amountsHidden } = useAmountVisibility();
const shouldMaskAmounts = computed(
    () => amountsHidden.value && page.props.auth?.user?.user_type === 'agent',
);

onMounted(() => {
    applyAmountMasking(shouldMaskAmounts.value);
});

watch(shouldMaskAmounts, (value) => applyAmountMasking(value));

onUnmounted(() => {
    applyAmountMasking(false);
});
</script>

<template>
    <div v-if="variant === 'header'" class="flex min-h-screen w-full flex-col">
        <slot />
    </div>
    <SidebarProvider v-else :default-open="isOpen">
        <slot />
    </SidebarProvider>
</template>
