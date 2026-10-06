<script setup lang="ts">
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';

defineProps<{
    title: string;
    description?: string;
}>();

const open = defineModel<boolean>('open', { default: false });
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent class="w-full gap-0 sm:max-w-lg">
            <SheetHeader class="border-b px-6 py-5">
                <SheetTitle class="text-lg">{{ title }}</SheetTitle>
                <SheetDescription v-if="description">
                    {{ description }}
                </SheetDescription>
            </SheetHeader>
            <div class="flex-1 overflow-y-auto px-6 py-5">
                <slot />
            </div>
            <SheetFooter
                v-if="$slots.footer"
                class="flex-row justify-end gap-2 border-t px-6 py-4"
            >
                <slot name="footer" />
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
