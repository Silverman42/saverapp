<script setup lang="ts">
import { PiggyBank } from '@lucide/vue';
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <PiggyBank :class="className" v-bind="$attrs" />
</template>
