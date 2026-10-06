<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { ref } from 'vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';

const props = withDefaults(
    defineProps<{ label?: string; defaultOpen?: boolean }>(),
    {
        label: 'More details',
        defaultOpen: false,
    },
);

const open = ref(props.defaultOpen);
</script>

<template>
    <Collapsible v-model:open="open">
        <CollapsibleTrigger
            class="text-muted-foreground hover:text-foreground inline-flex items-center gap-1.5 text-sm font-medium transition-colors"
        >
            {{ label }}
            <ChevronDown
                class="size-4 transition-transform"
                :class="open ? 'rotate-180' : ''"
            />
        </CollapsibleTrigger>
        <CollapsibleContent class="mt-3">
            <slot />
        </CollapsibleContent>
    </Collapsible>
</template>
