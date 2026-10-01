<script setup lang="ts">
import PlatformBanner from '@/components/PlatformBanner.vue';
import AuthLayout from '@/layouts/auth/AuthSimpleLayout.vue';

const { title = '', description = '' } = defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <AuthLayout :title="title" :description="description">
        <PlatformBanner />
        <slot />
    </AuthLayout>
</template>
