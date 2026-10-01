<script setup lang="ts">
import { Search, SlidersHorizontal, X } from '@lucide/vue';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';

withDefaults(
    defineProps<{
        title: string;
        description?: string;
        searchValue?: string;
        searchPlaceholder?: string;
        filtersOpen?: boolean;
        activeFilterCount?: number;
    }>(),
    {
        description: undefined,
        searchValue: '',
        searchPlaceholder: 'Search records',
        filtersOpen: false,
        activeFilterCount: 0,
    },
);

const emit = defineEmits<{
    'update:searchValue': [value: string];
    toggleFilters: [];
    resetFilters: [];
    submitSearch: [];
}>();
</script>

<template>
    <section class="bg-card border-border rounded-3xl border p-5 sm:p-8">
        <div
            class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"
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

            <div class="flex w-full flex-col gap-3 sm:flex-row lg:w-auto">
                <div class="relative min-w-0 sm:w-80">
                    <Search
                        class="text-muted-foreground pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2"
                    />
                    <Input
                        :model-value="searchValue"
                        :placeholder="searchPlaceholder"
                        class="pl-10"
                        @update:model-value="
                            emit('update:searchValue', String($event))
                        "
                        @keyup.enter="emit('submitSearch')"
                    />
                </div>
                <Button
                    type="button"
                    variant="outline"
                    :aria-expanded="filtersOpen"
                    aria-controls="directory-filters"
                    @click="emit('toggleFilters')"
                >
                    <SlidersHorizontal class="size-4" />
                    <span class="hidden sm:inline">Filter</span>
                    <span class="sr-only sm:hidden">Filter records</span>
                    <span
                        v-if="activeFilterCount > 0"
                        class="bg-primary text-primary-foreground inline-flex size-5 items-center justify-center rounded-full text-[11px]"
                    >
                        {{ activeFilterCount }}
                    </span>
                    <X
                        v-if="filtersOpen"
                        class="text-muted-foreground size-4"
                    />
                </Button>
            </div>
        </div>

        <div
            v-if="filtersOpen"
            id="directory-filters"
            class="border-border mt-6 border-y py-5"
        >
            <div class="flex flex-row flex-wrap gap-4">
                <slot name="filters" />
            </div>
            <div
                class="border-border mt-5 flex items-center justify-between border-t pt-4"
            >
                <slot name="filter-summary" />
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    @click="emit('resetFilters')"
                >
                    Reset
                </Button>
            </div>
        </div>

        <div class="mt-6">
            <slot />
        </div>

        <footer v-if="$slots.footer" class="border-border mt-5 border-t pt-4">
            <slot name="footer" />
        </footer>
    </section>
</template>
