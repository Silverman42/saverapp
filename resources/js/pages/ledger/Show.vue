<script setup lang="ts">
import { Head, Link, useForm, useHttp } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    preview as previewReversal,
    store as submitReversal,
} from '@/routes/reversals';
import { dashboard } from '@/routes';
import { index as transactionsIndex } from '@/routes/transactions';
import { Card, CardContent } from '@/components/ui/card';

const props = defineProps<{
    reversal_original: string | null;
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

const quote = ref<{
    preview_fingerprint: string;
    customer_version: number;
    assignment_version: number;
    gross_kobo: number;
} | null>(null);
const quoteError = ref('');
const previewHttp = useHttp<
    Record<string, never>,
    {
        preview_fingerprint: string;
        customer_version: number;
        assignment_version: number;
        gross_kobo: number;
    }
>({});
const reversal = useForm({
    attempt_reference: crypto.randomUUID(),
    preview_fingerprint: '',
    customer_version: 0,
    assignment_version: 0,
    reason_category: 'wrong_amount_allocation',
    internal_reason: '',
    customer_explanation: '',
    evidence_text: '',
    confirmed: false,
});
async function reviewCorrection(): Promise<void> {
    if (!props.reversal_original) return;
    quoteError.value = '';
    try {
        quote.value = await previewHttp.post(
            previewReversal.url(props.reversal_original),
        );
    } catch {
        quote.value = null;
        quoteError.value =
            'This original cannot be fully corrected until its dependencies and custody proof are complete.';
    }
}
function requestCorrection(): void {
    if (!props.reversal_original || !quote.value) return;
    reversal.preview_fingerprint = quote.value.preview_fingerprint;
    reversal.customer_version = quote.value.customer_version;
    reversal.assignment_version = quote.value.assignment_version;
    reversal.post(submitReversal.url(props.reversal_original));
}
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
        <Card v-if="reversal_original"
            ><CardContent class="grid gap-3 pt-6">
                <Button
                    class="w-fit"
                    :disabled="previewHttp.processing"
                    @click="reviewCorrection"
                    >Review full correction request</Button
                >
                <p
                    v-if="quoteError"
                    role="alert"
                    class="text-destructive text-sm"
                >
                    {{ quoteError }}
                </p>
                <form
                    v-if="quote"
                    class="grid max-w-xl gap-3"
                    @submit.prevent="requestCorrection"
                >
                    <p class="text-sm">
                        Full original amount {{ money(quote.gross_kobo) }}.
                        Original custody is preserved unless the owning
                        full-return contract proves otherwise.
                    </p>
                    <Label for="correction-reason">Internal reason</Label
                    ><Input
                        id="correction-reason"
                        v-model="reversal.internal_reason"
                        required
                        maxlength="1000"
                    />
                    <Label for="correction-description"
                        >Customer explanation</Label
                    ><Input
                        id="correction-description"
                        v-model="reversal.customer_explanation"
                        required
                        maxlength="500"
                    />
                    <Label for="correction-evidence"
                        >Custody and transaction evidence</Label
                    ><Input
                        id="correction-evidence"
                        v-model="reversal.evidence_text"
                        required
                        maxlength="1000"
                    />
                    <p
                        v-for="(error, key) in reversal.errors"
                        :key="key"
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        {{ error }}
                    </p>
                    <label class="flex items-center gap-2 text-sm"
                        ><input
                            v-model="reversal.confirmed"
                            type="checkbox"
                        />Confirm full correction for Admin review</label
                    >
                    <Button
                        class="w-fit"
                        :disabled="reversal.processing || !reversal.confirmed"
                        >Request correction</Button
                    >
                </form>
            </CardContent></Card
        >
        <Link
            :href="transactionsIndex()"
            class="text-primary w-fit text-sm underline"
            >Back to transactions</Link
        >
    </div>
</template>
