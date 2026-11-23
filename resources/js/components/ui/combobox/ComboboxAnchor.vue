<script setup lang="ts">
import type { ComboboxAnchorProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { ComboboxAnchor, useForwardProps } from "reka-ui"
import { cn } from "@/lib/utils"

const props = defineProps<ComboboxAnchorProps & { class?: HTMLAttributes["class"] }>()

const delegatedProps = reactiveOmit(props, "class")
const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
  <ComboboxAnchor
    data-slot="combobox-anchor"
    v-bind="forwardedProps"
    :class="cn(
      'border-input focus-within:border-ring focus-within:ring-ring/20 aria-invalid:border-destructive flex h-11 w-full items-center gap-2 rounded-xl border bg-card px-3.5 text-sm transition-[border-color] focus-within:ring-[3px] [&_svg]:shrink-0 [&_svg:not([class*=\'size-\'])]:size-4 [&_svg:not([class*=\'text-\'])]:text-muted-foreground',
      props.class,
    )"
  >
    <slot />
  </ComboboxAnchor>
</template>
