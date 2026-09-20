<script setup lang="ts">
import type { LinkComponentBaseProps, Method } from '@inertiajs/core';
import { Link } from '@inertiajs/vue3';

type Props = {
    href: LinkComponentBaseProps['href'];
    tabindex?: number;
    method?: Method;
    as?: string;
};

defineProps<Props>();
</script>

<template>
    <Link
        :href="href"
        :tabindex="tabindex"
        :method="method"
        :as="as"
        class="text-primary decoration-primary/25 hover:text-primary/80 font-semibold underline underline-offset-4 transition-colors duration-200 ease-out hover:decoration-current!"
    >
        <slot />
    </Link>
</template>
