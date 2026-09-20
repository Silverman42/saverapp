<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <div
    data-slot="card"
    :class="
      cn(
        'bg-card text-card-foreground flex flex-col gap-5 rounded-2xl border border-border py-6 shadow-[0_1px_2px_rgba(22,44,58,0.03),0_10px_30px_-24px_rgba(22,44,58,0.22)]',
        props.class,
      )
    "
  >
    <slot />
  </div>
</template>
