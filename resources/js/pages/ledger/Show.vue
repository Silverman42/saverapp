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
import {
    index as transactionsIndex,
    show as showTransaction,
} from '@/routes/transactions';
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
        compensation_reference: string | null;
        original_reference: string | null;
        components: {
            gross_kobo: number;
            net_kobo: number;
            fee_kobo: number;
            deduction_kobo: number;
        } | null;
        timeline: Array<{ label: string; at?: string | null; on?: string }>;
        actors: Record<string, string | null>;
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

const statusLabel: Record<string, string> = {
    posted: 'Posted',
    reversed: 'Reversed',
    approved_no_money: 'Corrected, no money moved',
};
const reasonCategories: Array<[string, string]> = [
    ['duplicate_posting', 'Duplicate posting'],
    ['wrong_customer', 'Recorded for the wrong Customer'],
    ['wrong_amount_allocation', 'Wrong amount or allocation'],
    ['payment_not_received', 'Payment was never received'],
    ['incorrect_fee_deduction', 'Incorrect fee or deduction'],
    ['incorrect_payout_record', 'Incorrect payout record'],
    ['other', 'Other'],
];
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
    reason_category: '',
    internal_reason: '',
    customer_explanation: '',
    evidence_text: '',
    files: [] as File[],
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
    reversal.post(submitReversal.url(props.reversal_original), {
        forceFormData: true,
    });
}
function chooseFiles(event: Event): void {
    const input = event.target as HTMLInputElement;
    reversal.files = Array.from(input.files ?? []).slice(0, 3);
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
                {{ transaction.type.replaceAll('_', ' ') }} ·
                {{ statusLabel[transaction.status] ?? transaction.status }}
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
                    <Label for="correction-category">Reason category</Label>
                    <select
                        id="correction-category"
                        v-model="reversal.reason_category"
                        required
                        class="border-input bg-background h-11 rounded-md border px-3 text-sm"
                    >
                        <option value="" disabled>Choose a reason</option>
                        <option
                            v-for="[value, label] in reasonCategories"
                            :key="value"
                            :value="value"
                        >
                            {{ label }}
                        </option>
                    </select>
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
                    <Label for="correction-files"
                        >Evidence files (optional, up to 3)</Label
                    >
                    <input
                        id="correction-files"
                        type="file"
                        multiple
                        accept="image/jpeg,image/png,image/webp,application/pdf"
                        class="border-input bg-background rounded-md border p-2 text-sm"
                        @change="chooseFiles"
                    />
                    <p class="text-muted-foreground text-xs">
                        JPEG, PNG, WebP or PDF, 5 MB each. Files are scanned and
                        kept privately; the Customer never sees them.
                    </p>
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
        <Card
            v-if="
                transaction.compensation_reference ||
                transaction.original_reference
            "
        >
            <CardContent class="grid gap-1 pt-6 text-sm">
                <p v-if="transaction.compensation_reference">
                    Reversed by
                    <Link
                        :href="
                            showTransaction(transaction.compensation_reference)
                        "
                        class="underline"
                        >{{ transaction.compensation_reference }}</Link
                    >. The original entries are unchanged.
                </p>
                <p v-if="transaction.original_reference">
                    Corrects
                    <Link
                        :href="showTransaction(transaction.original_reference)"
                        class="underline"
                        >{{ transaction.original_reference }}</Link
                    >.
                </p>
            </CardContent>
        </Card>
        <Card v-if="transaction.components">
            <CardContent class="grid gap-3 pt-6 text-sm sm:grid-cols-4">
                <p>
                    Gross savings debit (G)<br /><strong>{{
                        money(transaction.components.gross_kobo)
                    }}</strong>
                </p>
                <p>
                    Fee (F)<br /><strong>{{
                        money(transaction.components.fee_kobo)
                    }}</strong>
                </p>
                <p>
                    Deduction (D)<br /><strong>{{
                        money(transaction.components.deduction_kobo)
                    }}</strong>
                </p>
                <p>
                    Net payout (P)<br /><strong>{{
                        money(transaction.components.net_kobo)
                    }}</strong>
                </p>
            </CardContent>
        </Card>
        <Card>
            <CardContent class="grid gap-2 pt-6 text-sm">
                <p class="font-medium">Timeline</p>
                <ul class="grid gap-1">
                    <li v-for="step in transaction.timeline" :key="step.label">
                        {{ step.label }}:
                        {{ step.at ?? step.on ?? 'Not yet' }}
                    </li>
                </ul>
                <p
                    v-for="(name, role) in transaction.actors"
                    :key="role"
                    class="text-muted-foreground"
                >
                    {{ String(role).replaceAll('_', ' ') }}:
                    {{ name ?? 'Not yet' }}
                </p>
            </CardContent>
        </Card>
        <Link
            :href="transactionsIndex()"
            class="text-primary w-fit text-sm underline"
            >Back to transactions</Link
        >
    </div>
</template>
