<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import NameCorrectionReview from '@/components/NameCorrectionReview.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { dashboard } from '@/routes';
import {
    index as customersIndex,
    show as customerShow,
} from '@/routes/customers';

defineProps<{
    customer: { id: string };
    correction: {
        id: number;
        proposed_name: string;
        expires_at: string;
        can_review: boolean;
        can_cancel: boolean;
    };
}>();

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
        <PageHeader
            title="Name correction"
            description="Check the new name before you confirm it."
        />
        <Card>
            <CardContent>
                <NameCorrectionReview
                    :customer-id="customer.id"
                    :correction="correction"
                >
                    <Button as-child variant="ghost">
                        <Link :href="customerShow(customer.id)"
                            >Back to profile</Link
                        >
                    </Button>
                </NameCorrectionReview>
            </CardContent>
        </Card>
    </div>
</template>
