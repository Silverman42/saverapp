<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <h3
    data-slot="card-title"
    :class="cn('text-base leading-tight font-semibold tracking-[-0.01em]', props.class)"
  >
    <slot />
  </h3>
</template>
