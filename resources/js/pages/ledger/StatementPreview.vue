<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { dashboard } from '@/routes';
import { show as showCustomer } from '@/routes/customers';
import { preview as statementPreview } from '@/routes/customers/statements';
import { show as showTransaction } from '@/routes/transactions';
import { DatePicker } from '@/components/ui/date-picker';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';

type StatementLine = {
    reference: string;
    type: string;
    occurred_on: string;
    committed_at: string;
    gross_amount_kobo: number;
    savings_effect_kobo: number;
    fee_amount_kobo: number;
};

const props = defineProps<{
    customer: { id: string; name: string };
    preview: {
        status: 'ready' | 'unavailable';
        message?: string;
        from?: string;
        to?: string;
        timezone?: string;
        cutoff_at?: string;
        ledger_watermark?: number;
        opening_kobo?: number;
        activity_kobo?: number;
        closing_kobo?: number;
        current_reserved_kobo?: number;
        current_available_kobo?: number;
        lines?: StatementLine[];
    };
    from: string;
    to: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});

const from = ref(props.from);
const to = ref(props.to);

function refresh(): void {
    router.get(
        statementPreview.url(props.customer.id, {
            query: { from: from.value, to: to.value },
        }),
    );
}

function money(kobo: number): string {
    return `${kobo < 0 ? '−' : ''}₦${(Math.abs(kobo) / 100).toLocaleString(
        'en-NG',
        {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        },
    )}`;
}
</script>

<template>
    <Head :title="`Statement preview · ${customer.name}`" />
    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">
                Statement preview
            </h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                Posted savings activity for {{ customer.name }}. This preview is
                not an issued statement.
            </p>
        </div>
        <form
            class="flex flex-row flex-wrap items-end gap-4"
            @submit.prevent="refresh"
        >
            <DatePicker id="statement-from" v-model="from" class="w-fit" />
            <DatePicker id="statement-to" v-model="to" class="w-fit" />
            <Button type="submit">Preview period</Button>
        </form>
        <Card v-if="preview.status === 'unavailable'">
            <CardContent class="pt-6">
                <p class="font-medium">Statement preview unavailable</p>
                <p class="text-muted-foreground mt-1 text-sm">
                    {{
                        preview.message ??
                        'The ledger needs verification before statement totals can be shown.'
                    }}
                </p>
            </CardContent>
        </Card>
        <template v-else>
            <p class="text-muted-foreground text-sm">
                {{ preview.from }} through {{ preview.to }} ·
                {{ preview.timezone }} · ledger cutoff {{ preview.cutoff_at }}
            </p>
            <Card>
                <CardContent class="grid gap-4 pt-6 text-sm sm:grid-cols-3">
                    <div>
                        <p class="text-muted-foreground">Opening savings</p>
                        <p class="font-medium">
                            {{ money(preview.opening_kobo ?? 0) }}
                        </p>
                    </div>
                    <div>
                        <p class="text-muted-foreground">Net period activity</p>
                        <p class="font-medium">
                            {{ money(preview.activity_kobo ?? 0) }}
                        </p>
                    </div>
                    <div>
                        <p class="text-muted-foreground">Closing savings</p>
                        <p class="font-medium">
                            {{ money(preview.closing_kobo ?? 0) }}
                        </p>
                    </div>
                </CardContent>
            </Card>
            <Card>
                <CardContent class="grid gap-4 pt-6 text-sm sm:grid-cols-2">
                    <div>
                        <p class="text-muted-foreground">
                            Current live withdrawal reservations
                        </p>
                        <p>{{ money(preview.current_reserved_kobo ?? 0) }}</p>
                    </div>
                    <div>
                        <p class="text-muted-foreground">
                            Current available savings
                        </p>
                        <p>{{ money(preview.current_available_kobo ?? 0) }}</p>
                    </div>
                </CardContent>
            </Card>
            <Card>
                <CardContent class="pt-6">
                    <p
                        v-if="!preview.lines?.length"
                        class="text-muted-foreground text-sm"
                    >
                        No posted savings activity in this period.
                    </p>
                    <ul v-else class="divide-y">
                        <li
                            v-for="line in preview.lines"
                            :key="line.reference"
                            class="flex flex-wrap items-start justify-between gap-4 py-4 first:pt-0 last:pb-0"
                        >
                            <div class="grid gap-1 text-sm">
                                <Link
                                    :href="showTransaction(line.reference)"
                                    class="font-medium underline"
                                    >{{ line.reference }}</Link
                                >
                                <span class="text-muted-foreground"
                                    >{{ line.type }} · occurred
                                    {{ line.occurred_on }} · posted
                                    {{ line.committed_at }} UTC</span
                                >
                            </div>
                            <span class="text-sm">{{
                                money(line.savings_effect_kobo)
                            }}</span>
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </template>
        <Link
            :href="showCustomer(customer.id)"
            class="text-primary w-fit text-sm underline"
            >Back to Customer</Link
        >
    </div>
</template>
