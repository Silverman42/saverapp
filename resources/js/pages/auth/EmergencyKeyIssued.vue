<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { enrolment } from '@/routes/two-factor';

defineOptions({
    layout: {
        title: 'Your new emergency key',
        description:
            'Your previous key no longer works. This replacement is shown only once.',
    },
});

defineProps<{ recovery_key: string }>();
const stored = ref(false);
</script>

<template>
    <div class="mx-auto w-full max-w-md">
        <Head title="New emergency key" />
        <Card>
            <CardContent class="space-y-5 pt-6">
                <p
                    class="bg-muted rounded-lg p-4 text-center font-mono text-lg tracking-wider break-all select-all"
                >
                    {{ recovery_key }}
                </p>
                <p class="text-muted-foreground text-sm">
                    Store it offline, away from this device. Only its hash is
                    kept, so nobody can show it to you again.
                </p>
                <div class="flex items-start gap-3">
                    <Checkbox id="stored" v-model="stored" class="mt-1" />
                    <Label
                        for="stored"
                        class="cursor-pointer text-sm leading-relaxed font-normal"
                    >
                        I have stored this key offline.
                    </Label>
                </div>
                <Link v-if="stored" :href="enrolment()" class="block">
                    <Button class="w-full"
                        >Continue to authenticator setup</Button
                    >
                </Link>
            </CardContent>
        </Card>
    </div>
</template>
