<script setup lang="ts">
import type { ComboboxContentEmits, ComboboxContentProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { ComboboxContent, ComboboxPortal, ComboboxViewport, useForwardPropsEmits } from "reka-ui"
import { cn } from "@/lib/utils"

defineOptions({
  inheritAttrs: false,
})

const props = withDefaults(
  defineProps<ComboboxContentProps & { class?: HTMLAttributes["class"] }>(),
  {
    position: "popper",
    align: "start",
    sideOffset: 4,
  },
)
const emits = defineEmits<ComboboxContentEmits>()

const delegatedProps = reactiveOmit(props, "class")
const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <ComboboxPortal>
    <ComboboxContent
      data-slot="combobox-list"
      v-bind="{ ...$attrs, ...forwarded }"
      :class="cn(
        'bg-popover text-popover-foreground data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 relative z-50 max-h-72 w-(--reka-combobox-trigger-width) min-w-[12rem] overflow-hidden rounded-xl border border-border shadow-[0_18px_45px_-20px_rgba(22,44,58,0.35)]',
        props.class,
      )"
    >
      <ComboboxViewport class="max-h-72 p-1.5">
        <slot />
      </ComboboxViewport>
    </ComboboxContent>
  </ComboboxPortal>
</template>
