<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { accept, cancel, reject } from '@/routes/customers/name-corrections';

/**
 * Review, accept, reject or cancel a pending customer name correction.
 * Used on the name correction page and in a dialog on the customer profile.
 */
const props = defineProps<{
    customerId: string;
    correction: {
        id: number;
        proposed_name: string | null;
        expires_at: string;
        can_review: boolean;
        can_cancel: boolean;
    };
}>();

const emit = defineEmits<{ done: [] }>();

const processing = ref(false);

const submit = (action: 'accept' | 'reject' | 'cancel'): void => {
    const parameters = {
        customer: props.customerId,
        correction: props.correction.id,
    };
    const route =
        action === 'accept'
            ? accept(parameters)
            : action === 'reject'
              ? reject(parameters)
              : cancel(parameters);
    router.post(
        route.url,
        {},
        {
            preserveScroll: true,
            onStart: () => {
                processing.value = true;
            },
            onFinish: () => {
                processing.value = false;
            },
            onSuccess: () => emit('done'),
        },
    );
};
</script>

<template>
    <div class="space-y-5">
        <div v-if="correction.can_review && correction.proposed_name">
            <p class="text-muted-foreground text-sm">New name</p>
            <p class="mt-1 text-xl font-medium">
                {{ correction.proposed_name }}
            </p>
        </div>
        <p v-else class="text-muted-foreground text-sm">
            Waiting for the customer to accept or reject the new name.
        </p>
        <p class="text-muted-foreground text-xs">
            Expires {{ correction.expires_at }}
        </p>
        <div class="flex flex-wrap gap-2">
            <template v-if="correction.can_review">
                <Button :disabled="processing" @click="submit('accept')"
                    >Accept</Button
                >
                <Button
                    variant="outline"
                    :disabled="processing"
                    @click="submit('reject')"
                    >Reject</Button
                >
            </template>
            <Button
                v-if="correction.can_cancel"
                variant="outline"
                class="text-destructive"
                :disabled="processing"
                @click="submit('cancel')"
                >Cancel request</Button
            >
            <slot />
        </div>
    </div>
</template>
