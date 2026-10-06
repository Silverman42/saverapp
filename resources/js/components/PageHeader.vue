<script setup lang="ts">
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[25px] font-medium tracking-tight">{{ title }}</h1>
            <p
                v-if="description"
                class="text-muted-foreground mt-1.5 max-w-2xl text-sm"
            >
                {{ description }}
            </p>
        </div>
        <div
            v-if="$slots.actions"
            class="flex shrink-0 flex-wrap items-center gap-2"
        >
            <slot name="actions" />
        </div>
    </div>
</template>
