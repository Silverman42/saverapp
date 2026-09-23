<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { dashboard } from '@/routes';
import {
    index as customersIndex,
    show as customerShow,
} from '@/routes/customers';
import { accept, cancel, reject } from '@/routes/customers/name-corrections';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

const props = defineProps<{
    customer: { id: string };
    correction: {
        id: number;
        proposed_name: string;
        expires_at: string;
        can_review: boolean;
        can_cancel: boolean;
    };
}>();

const submit = (action: 'accept' | 'reject' | 'cancel'): void => {
    const route =
        action === 'accept'
            ? accept({
                  customer: props.customer.id,
                  correction: props.correction.id,
              })
            : action === 'reject'
              ? reject({
                    customer: props.customer.id,
                    correction: props.correction.id,
                })
              : cancel({
                    customer: props.customer.id,
                    correction: props.correction.id,
                });
    router.post(route.url, {}, { preserveScroll: true });
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Customer profile', href: customersIndex() },
            { title: 'Name correction', href: '#' },
        ],
    },
});
</script>

<template>
    <Head title="Review name correction" />
    <div class="mx-auto w-full max-w-2xl space-y-6">
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">
                Name correction
            </h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                This proposal expires
                {{ correction.expires_at }} (Africa/Lagos).
            </p>
        </div>
        <Card>
            <CardHeader>
                <CardTitle>Proposed Customer name</CardTitle>
                <CardDescription
                    >Review the proposed change before confirming
                    it.</CardDescription
                >
            </CardHeader>
            <CardContent class="space-y-5">
                <p class="text-xl font-medium">
                    {{ correction.proposed_name }}
                </p>
                <div class="flex flex-wrap gap-3">
                    <template v-if="correction.can_review">
                        <Button @click="submit('accept')"
                            >Accept correction</Button
                        >
                        <Button variant="outline" @click="submit('reject')"
                            >Reject</Button
                        >
                    </template>
                    <Button
                        v-if="correction.can_cancel"
                        variant="destructive"
                        @click="submit('cancel')"
                        >Cancel proposal</Button
                    >
                    <Link :href="customerShow(customer.id)"
                        ><Button variant="ghost"
                            >Return to profile</Button
                        ></Link
                    >
                </div>
            </CardContent>
        </Card>
    </div>
</template>
