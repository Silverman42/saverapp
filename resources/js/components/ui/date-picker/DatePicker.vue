<script setup lang="ts">
import { VueDatePicker } from "@vuepic/vue-datepicker"
import { computed } from "vue"
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = withDefaults(
  defineProps<{
    id: string
    modelValue?: string
    placeholder?: string
    ariaLabel?: string
    errorMessage?: string
    disabled?: boolean
    class?: HTMLAttributes["class"]
  }>(),
  {
    modelValue: "",
    placeholder: "Select date",
    ariaLabel: "Datepicker input",
    errorMessage: "",
    disabled: false,
  },
)

const emits = defineEmits<{
  (e: "update:modelValue", value: string): void
}>()

const pickerValue = computed(() => props.modelValue || null)
const inputAriaLabel = computed(() =>
  props.errorMessage ? `${props.ariaLabel}. ${props.errorMessage}` : props.ariaLabel,
)

const ui = {
  input:
    "h-11 min-w-0 rounded-xl border-input bg-card px-3.5 py-2 text-sm text-foreground shadow-[0_1px_2px_rgba(30,55,70,0.03)] focus:border-ring focus:ring-ring/20 focus:ring-[3px]",
  menu: "rounded-xl border-border bg-popover text-popover-foreground shadow-lg",
}

function updateModelValue(value: unknown): void {
  emits("update:modelValue", typeof value === "string" ? value : "")
}
</script>

<template>
  <VueDatePicker
    :model-value="pickerValue"
    :placeholder
    :disabled
    :input-attrs="{
      id,
      autocomplete: 'off',
      state: props.errorMessage ? false : undefined,
    }"
    :aria-labels="{ input: inputAriaLabel }"
    :ui
    :class="cn('saver-date-picker w-fit', props.class)"
    auto-apply
    clearable
    :formats="{ input: 'dd/MM/yyyy' }"
    :time-config="{ enableTimePicker: false }"
    model-type="yyyy-MM-dd"
    @update:model-value="updateModelValue"
  />
</template>
