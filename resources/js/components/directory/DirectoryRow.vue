<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <article
        :class="cn('border-border bg-card rounded-2xl border p-4 transition-colors hover:border-primary hover:bg-accent/35 focus-within:border-primary focus-within:bg-accent/35 sm:p-5', props.class)"
    >
        <slot />
    </article>
</template>
