<script setup lang="ts">
import type { Component } from 'vue';

defineProps<{
    title: string;
    description?: string;
    icon?: Component;
}>();
</script>

<template>
    <div
        class="flex flex-col items-center rounded-2xl border border-dashed px-6 py-10 text-center"
    >
        <div
            v-if="icon"
            class="bg-muted text-muted-foreground mb-3 flex size-10 items-center justify-center rounded-full"
        >
            <component :is="icon" class="size-5" />
        </div>
        <p class="font-medium">{{ title }}</p>
        <p
            v-if="description"
            class="text-muted-foreground mt-1 max-w-sm text-sm"
        >
            {{ description }}
        </p>
        <div v-if="$slots.default" class="mt-4">
            <slot />
        </div>
    </div>
</template>
