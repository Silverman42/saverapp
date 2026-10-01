<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{
    message: string;
    error_code: string;
    correlation_reference: string;
    operation_reference?: string;
}>();
</script>

<template>
    <main
        class="bg-background text-foreground flex min-h-screen items-center justify-center px-6 py-12"
    >
        <Head title="Service temporarily unavailable" />
        <section aria-labelledby="platform-title" class="w-full max-w-lg">
            <h1
                id="platform-title"
                class="text-[25px] font-medium tracking-tight"
            >
                Service temporarily unavailable
            </h1>
            <p role="status" class="text-muted-foreground mt-1.5 text-sm">
                {{ message }}
            </p>
            <p class="text-muted-foreground mt-6 text-sm">
                If you submitted a financial request, check its existing
                reference when service resumes before submitting another
                request.
            </p>
            <p
                v-if="operation_reference"
                class="text-muted-foreground mt-4 text-sm break-all"
            >
                Original operation reference: {{ operation_reference }}
            </p>
            <p class="text-muted-foreground mt-4 text-xs break-all">
                Support reference: {{ correlation_reference }}
            </p>
        </section>
    </main>
</template>
