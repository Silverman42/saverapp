<script lang="ts" setup>
import type { ToasterProps } from "vue-sonner"
import { Toaster as Sonner } from "vue-sonner"
import { cn } from "@/lib/utils"

import 'vue-sonner/style.css';

const props = withDefaults(defineProps<ToasterProps>(), {
    position: 'top-center',
    toastOptions: () => ({ unstyled: true, class: 'w-full' }),
});
</script>

<template>
  <Sonner
    :class="cn('toaster group', props.class)"
    :style="{ '--width': 'min(40rem, calc(100vw - 2rem))' }"
    v-bind="props"
  />
</template>
