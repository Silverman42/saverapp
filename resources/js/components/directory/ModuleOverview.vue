<script setup lang="ts">
defineProps<{
    title: string;
    description?: string;
    metrics: Array<{
        label: string;
        value: number | string;
        description: string;
    }>;
}>();
</script>

<template>
    <section class="bg-card border-border rounded-3xl border p-5 sm:p-8">
        <div
            class="mb-5 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <div>
                <h2 class="text-xl font-medium tracking-tight">{{ title }}</h2>
                <p
                    v-if="description"
                    class="text-muted-foreground mt-1 text-sm"
                >
                    {{ description }}
                </p>
            </div>
            <div v-if="$slots.actions" class="shrink-0">
                <slot name="actions" />
            </div>
        </div>
        <div class="grid gap-4 md:grid-cols-3">
            <div
                v-for="metric in metrics"
                :key="metric.label"
                class="bg-muted/40 flex flex-col gap-2 rounded-2xl p-5"
            >
                <p class="text-muted-foreground text-sm">
                    {{ metric.label }}
                </p>
                <p class="text-3xl font-semibold tracking-tight">
                    {{ metric.value }}
                </p>
                <p
                    v-if="metric.description"
                    class="text-muted-foreground text-xs"
                >
                    {{ metric.description }}
                </p>
            </div>
        </div>
    </section>
</template>
