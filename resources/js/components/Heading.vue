<script setup lang="ts">
type Props = {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
};

withDefaults(defineProps<Props>(), {
    variant: 'default',
});
</script>

<template>
    <header :class="variant === 'small' ? '' : 'mb-8 space-y-1.5'">
        <h2
            :class="
                variant === 'small'
                    ? 'mb-0.5 text-base font-semibold'
                    : 'text-2xl font-semibold tracking-[-0.025em]'
            "
        >
            {{ title }}
        </h2>
        <p v-if="description" class="text-muted-foreground text-sm leading-6">
            {{ description }}
        </p>
    </header>
</template>
