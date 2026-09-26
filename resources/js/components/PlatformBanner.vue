<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';

const page = usePage();
</script>

<template>
    <div
        v-if="page.props.platform && page.props.platform.mode !== 'normal'"
        role="status"
        aria-live="polite"
        class="border-border bg-muted text-foreground mb-6 rounded-lg border px-4 py-3 text-sm"
    >
        {{ page.props.platform.message }}
    </div>
</template>
