<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Loader2, WifiOff } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { enable as enableOnlineAccess } from '@/routes/customers/online-access';

/**
 * Explains that a customer saves offline only and lets staff give them app access.
 */
const props = defineProps<{
    customerId: string;
    customerName: string;
    canEnable: boolean;
}>();

const open = ref(false);

const form = useForm({
    email: '',
});

const submit = (): void => {
    form.post(enableOnlineAccess(props.customerId).url, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
    });
};
</script>

<template>
    <div>
        <Card>
            <CardHeader>
                <CardTitle class="flex items-center gap-2 text-base">
                    <WifiOff class="text-muted-foreground size-4" />
                    Offline only
                </CardTitle>
                <CardDescription>
                    {{ customerName }} saves offline only. They have no email
                    and cannot sign in to the app.
                </CardDescription>
            </CardHeader>
            <CardContent v-if="canEnable">
                <Button variant="outline" size="sm" @click="open = true">
                    Give app access
                </Button>
            </CardContent>
        </Card>

        <Dialog :open="open" @update:open="open = $event">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Give app access</DialogTitle>
                    <DialogDescription>
                        Add {{ customerName }}'s email. We will send them an
                        invite to set up their account.
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="submit">
                    <div class="space-y-1.5">
                        <Label for="online-access-email">
                            Email
                            <span class="text-destructive">*</span>
                        </Label>
                        <Input
                            id="online-access-email"
                            v-model="form.email"
                            type="email"
                            placeholder="e.g. ngozi@example.ng"
                            required
                        />
                        <p
                            v-if="form.errors.email"
                            class="text-destructive text-xs"
                        >
                            {{ form.errors.email }}
                        </p>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="open = false"
                        >
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="form.processing">
                            <Loader2
                                v-if="form.processing"
                                class="size-4 animate-spin"
                            />
                            Send invite
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
