<script setup lang="ts">
import type { ComboboxItemEmits, ComboboxItemProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { Check } from "@lucide/vue"
import { reactiveOmit } from "@vueuse/core"
import { ComboboxItem, ComboboxItemIndicator, useForwardPropsEmits } from "reka-ui"
import { cn } from "@/lib/utils"

const props = defineProps<ComboboxItemProps & { class?: HTMLAttributes["class"] }>()
const emits = defineEmits<ComboboxItemEmits>()

const delegatedProps = reactiveOmit(props, "class")
const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <ComboboxItem
    data-slot="combobox-item"
    v-bind="forwarded"
    :class="cn(
      'data-[highlighted]:bg-accent data-[highlighted]:text-accent-foreground relative flex w-full cursor-default items-center gap-2 rounded-lg py-2 pr-8 pl-3 text-sm outline-hidden select-none data-[disabled]:pointer-events-none data-[disabled]:opacity-50',
      props.class,
    )"
  >
    <slot />
    <span class="absolute right-2 flex size-3.5 items-center justify-center">
      <ComboboxItemIndicator>
        <Check class="size-4" />
      </ComboboxItemIndicator>
    </span>
  </ComboboxItem>
</template>
