<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { dashboard } from '@/routes';
import { index as transactionsIndex } from '@/routes/transactions';
import { Card, CardContent } from '@/components/ui/card';

defineProps<{
    transaction: {
        reference: string;
        type: string;
        status: string;
        customer_id: string | null;
        customer_name: string | null;
        occurred_on: string;
        committed_at: string;
        timezone: string;
        currency: string;
        gross_amount_kobo: number;
        savings_effect_kobo: number;
        fee_amount_kobo: number;
        posting_group_count: number;
        source_type: string;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Transactions', href: transactionsIndex() },
        ],
    },
});

function money(kobo: number): string {
    return `₦${(Math.abs(kobo) / 100).toLocaleString('en-NG', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;
}
</script>

<template>
    <Head :title="transaction.reference" />
    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">
                {{ transaction.reference }}
            </h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                Posted {{ transaction.type }} · {{ transaction.status }}
            </p>
        </div>
        <Card>
            <CardContent class="grid gap-4 pt-6 text-sm sm:grid-cols-2">
                <div>
                    <p class="text-muted-foreground">Customer</p>
                    <p>
                        {{ transaction.customer_name ?? 'Business custody' }}
                        <span v-if="transaction.customer_id"
                            >({{ transaction.customer_id }})</span
                        >
                    </p>
                </div>
                <div>
                    <p class="text-muted-foreground">Gross amount</p>
                    <p>{{ money(transaction.gross_amount_kobo) }}</p>
                </div>
                <div>
                    <p class="text-muted-foreground">Savings effect</p>
                    <p>
                        {{
                            transaction.savings_effect_kobo < 0
                                ? 'Debit'
                                : 'Credit'
                        }}
                        {{ money(transaction.savings_effect_kobo) }}
                    </p>
                </div>
                <div>
                    <p class="text-muted-foreground">Recognized fees</p>
                    <p>{{ money(transaction.fee_amount_kobo) }}</p>
                </div>
                <div>
                    <p class="text-muted-foreground">Occurred</p>
                    <p>
                        {{ transaction.occurred_on }} ({{
                            transaction.timezone
                        }})
                    </p>
                </div>
                <div>
                    <p class="text-muted-foreground">Committed</p>
                    <p>{{ transaction.committed_at }} UTC</p>
                </div>
                <div>
                    <p class="text-muted-foreground">Currency</p>
                    <p>{{ transaction.currency }}</p>
                </div>
                <div>
                    <p class="text-muted-foreground">Linked posting groups</p>
                    <p>{{ transaction.posting_group_count }}</p>
                </div>
            </CardContent>
        </Card>
        <Link
            :href="transactionsIndex()"
            class="text-primary w-fit text-sm underline"
            >Back to transactions</Link
        >
    </div>
</template>
