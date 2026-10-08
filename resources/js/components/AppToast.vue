<script setup lang="ts">
import { BadgeAlert, BadgeCheck, BadgeInfo, BadgeX } from "@lucide/vue";
import { computed } from "vue";
import { toast } from "vue-sonner";
import type { FlashToast } from "@/types/ui";

const props = defineProps<FlashToast & { toastId: string }>();

const appearance = computed(
    () =>
        ({
            success: { icon: BadgeCheck, color: "text-primary" },
            info: { icon: BadgeInfo, color: "text-primary" },
            warning: { icon: BadgeAlert, color: "text-amber-400" },
            error: { icon: BadgeX, color: "text-destructive" },
        })[props.type],
);
</script>

<template>
    <div
        :role="type === 'error' ? 'alert' : 'status'"
        class="flex w-full items-stretch overflow-hidden rounded-2xl border border-(--toast-border) bg-(--toast-background) text-(--toast-foreground) shadow-[0_12px_32px_rgba(4,12,20,0.35)]"
    >
        <div class="flex min-w-0 flex-1 items-center gap-4 px-5 py-4 sm:px-7">
            <component
                :is="appearance.icon"
                :class="['size-10 shrink-0', appearance.color]"
                fill="currentColor"
                stroke="var(--toast-background)"
                :stroke-width="1.75"
                aria-hidden="true"
            />
            <div class="min-w-0">
                <p class="text-base leading-6 font-semibold">{{ title }}</p>
                <p
                    v-if="description"
                    class="text-sm leading-5 text-(--toast-muted-foreground)"
                >
                    {{ description }}
                </p>
            </div>
        </div>
        <div
            class="flex shrink-0 items-center border-l border-(--toast-border) px-4 sm:px-8"
        >
            <button
                type="button"
                class="rounded-lg bg-(--toast-button) px-4 py-2 text-sm font-medium transition-colors hover:bg-(--toast-button-hover) focus-visible:ring-2 focus-visible:ring-(--ring) focus-visible:outline-none"
                @click="toast.dismiss(toastId)"
            >
                Close
            </button>
        </div>
    </div>
</template>
