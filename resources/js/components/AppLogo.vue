<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

const page = usePage();
const name = computed(() =>
    page.props.name === 'Laravel' ? 'SaverApp' : page.props.name,
);
</script>

<template>
    <div
        class="bg-primary text-primary-foreground flex aspect-square size-9 items-center justify-center rounded-xl shadow-[0_8px_18px_-10px_color-mix(in_srgb,var(--primary)_80%,transparent)]"
    >
        <AppLogoIcon class="size-5" :stroke-width="2.2" />
    </div>
    <div class="ml-1.5 grid flex-1 text-left text-sm">
        <span
            class="truncate text-[15px] leading-tight font-bold tracking-[-0.02em]"
            >{{ name }}</span
        >
        <span
            class="text-muted-foreground truncate text-[10px] font-medium tracking-[0.12em] uppercase"
        >
            Savings operations
        </span>
    </div>
</template>
