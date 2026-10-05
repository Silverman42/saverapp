<script setup lang="ts">
import type { SwitchRootEmits, SwitchRootProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { SwitchRoot, SwitchThumb, useForwardPropsEmits } from "reka-ui"
import { cn } from "@/lib/utils"

const props = defineProps<SwitchRootProps & { class?: HTMLAttributes["class"] }>()
const emits = defineEmits<SwitchRootEmits>()

const delegatedProps = reactiveOmit(props, "class")

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <SwitchRoot
    data-slot="switch"
    v-bind="forwarded"
    :class="
      cn('peer data-[state=checked]:bg-primary data-[state=unchecked]:bg-input focus-visible:border-ring focus-visible:ring-ring/20 inline-flex h-5 w-9 shrink-0 items-center rounded-full border border-transparent shadow-[0_1px_2px_rgba(30,55,70,0.04)] transition-colors outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50',
         props.class)"
  >
    <SwitchThumb
      data-slot="switch-thumb"
      class="bg-background pointer-events-none block size-4 rounded-full ring-0 shadow-sm transition-transform data-[state=checked]:translate-x-[calc(100%-2px)] data-[state=unchecked]:translate-x-0.5"
    />
  </SwitchRoot>
</template>
