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
                <h1 class="text-xl font-medium tracking-tight">{{ title }}</h1>
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
                class="border-border flex min-h-40 flex-col justify-between rounded-2xl border p-5"
            >
                <p
                    class="text-muted-foreground text-xs font-semibold tracking-[0.16em] uppercase"
                >
                    {{ metric.label }}
                </p>
                <p class="mt-4 text-4xl font-semibold tracking-tight">
                    {{ metric.value }}
                </p>
                <p class="text-muted-foreground mt-2 text-sm">
                    {{ metric.description }}
                </p>
            </div>
        </div>
    </section>
</template>
