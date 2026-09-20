<script setup lang="ts">
defineProps<{
    message?: string;
}>();
</script>

<template>
    <div v-show="message">
        <p
            class="text-destructive flex items-center gap-1.5 text-xs font-medium"
        >
            {{ message }}
        </p>
    </div>
</template>
