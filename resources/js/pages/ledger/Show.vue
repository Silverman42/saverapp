<script setup lang="ts">
import { Head, Link, useForm, useHttp } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Undo2 } from '@lucide/vue';
import FormSheet from '@/components/FormSheet.vue';
import MoreDetails from '@/components/MoreDetails.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    preview as previewReversal,
    store as submitReversal,
} from '@/routes/reversals';
import { dashboard } from '@/routes';
import {
    index as transactionsIndex,
    show as showTransaction,
} from '@/routes/transactions';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

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
    posted: 'Completed',
    reversed: 'Reversed',
    approved_no_money: 'Corrected, no money moved',
};
const typeLabels: Record<string, string> = {
    contribution: 'Contribution',
    remittance: 'Remittance',
    withdrawal: 'Withdrawal',
    reversal: 'Correction',
    deduction: 'Deduction',
    fee_application: 'Fee taken from savings',
    fee_refund: 'Fee refund owed',
    external_refund_payment: 'Cash refund',
    earnings_draw: 'Earnings draw',
};
const title = computed(() => {
    const type = props.transaction.type;
    if (typeLabels[type]) return typeLabels[type];
    const words = type.replaceAll('_', ' ');
    return words.charAt(0).toUpperCase() + words.slice(1);
});
const correctionOpen = ref(false);
const reasonCategories: Array<[string, string]> = [
    ['duplicate_posting', 'Recorded twice'],
    ['wrong_customer', 'Recorded for the wrong customer'],
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
function openCorrection(): void {
    correctionOpen.value = true;
    if (!quote.value) void reviewCorrection();
}
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
            'This transaction cannot be corrected yet. Some linked records still need to be completed first.';
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
        <PageHeader
            :title="title"
            :description="`Reference ${transaction.reference}`"
        >
            <template v-if="reversal_original" #actions>
                <Button variant="outline" @click="openCorrection"
                    ><Undo2 class="size-4" />Request correction</Button
                >
            </template>
        </PageHeader>

        <Card>
            <CardContent class="space-y-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-muted-foreground text-sm">
                            {{
                                transaction.savings_effect_kobo < 0
                                    ? 'Taken from savings'
                                    : 'Added to savings'
                            }}
                        </p>
                        <p class="mt-1 text-3xl font-semibold">
                            {{ money(transaction.savings_effect_kobo) }}
                        </p>
                    </div>
                    <Badge variant="secondary">{{
                        statusLabel[transaction.status] ?? transaction.status
                    }}</Badge>
                </div>
                <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-muted-foreground">Customer</dt>
                        <dd class="mt-0.5 font-medium">
                            {{ transaction.customer_name ?? 'Business cash' }}
                            <span
                                v-if="transaction.customer_id"
                                class="text-muted-foreground block text-xs font-normal"
                                >{{ transaction.customer_id }}</span
                            >
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Date</dt>
                        <dd class="mt-0.5 font-medium">
                            {{ transaction.occurred_on }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Total amount</dt>
                        <dd class="mt-0.5 font-medium">
                            {{ money(transaction.gross_amount_kobo) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Fees</dt>
                        <dd class="mt-0.5 font-medium">
                            {{ money(transaction.fee_amount_kobo) }}
                        </dd>
                    </div>
                </dl>

                <div v-if="transaction.components" class="space-y-2">
                    <h2 class="text-sm font-medium">Breakdown</h2>
                    <dl class="divide-y rounded-xl border text-sm">
                        <div class="flex justify-between gap-4 p-3">
                            <dt class="text-muted-foreground">
                                Taken from savings
                            </dt>
                            <dd class="font-medium">
                                {{ money(transaction.components.gross_kobo) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4 p-3">
                            <dt class="text-muted-foreground">Fee</dt>
                            <dd class="font-medium">
                                {{ money(transaction.components.fee_kobo) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4 p-3">
                            <dt class="text-muted-foreground">Deduction</dt>
                            <dd class="font-medium">
                                {{
                                    money(transaction.components.deduction_kobo)
                                }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4 p-3">
                            <dt class="text-muted-foreground">Paid out</dt>
                            <dd class="font-medium">
                                {{ money(transaction.components.net_kobo) }}
                            </dd>
                        </div>
                    </dl>
                </div>

                <div
                    v-if="
                        transaction.compensation_reference ||
                        transaction.original_reference
                    "
                    class="bg-muted/40 grid gap-1 rounded-xl p-4 text-sm"
                >
                    <p v-if="transaction.compensation_reference">
                        Reversed by
                        <Link
                            :href="
                                showTransaction(
                                    transaction.compensation_reference,
                                )
                            "
                            class="font-medium underline underline-offset-4"
                            >{{ transaction.compensation_reference }}</Link
                        >. This record stays as it was.
                    </p>
                    <p v-if="transaction.original_reference">
                        Corrects
                        <Link
                            :href="
                                showTransaction(transaction.original_reference)
                            "
                            class="font-medium underline underline-offset-4"
                            >{{ transaction.original_reference }}</Link
                        >.
                    </p>
                </div>

                <MoreDetails>
                    <dl
                        class="text-muted-foreground grid gap-3 text-xs sm:grid-cols-2 lg:grid-cols-3"
                    >
                        <div>
                            <dt class="text-foreground">Saved at</dt>
                            <dd>{{ transaction.committed_at }} UTC</dd>
                        </div>
                        <div>
                            <dt class="text-foreground">Time zone</dt>
                            <dd>{{ transaction.timezone }}</dd>
                        </div>
                        <div>
                            <dt class="text-foreground">Currency</dt>
                            <dd>{{ transaction.currency }}</dd>
                        </div>
                        <div>
                            <dt class="text-foreground">Linked records</dt>
                            <dd>{{ transaction.posting_group_count }}</dd>
                        </div>
                        <div>
                            <dt class="text-foreground">Source</dt>
                            <dd>
                                {{
                                    transaction.source_type.replaceAll('_', ' ')
                                }}
                            </dd>
                        </div>
                    </dl>
                </MoreDetails>
            </CardContent>
        </Card>

        <Card>
            <CardHeader><CardTitle>History</CardTitle></CardHeader>
            <CardContent class="space-y-4 text-sm">
                <ol class="divide-y">
                    <li
                        v-for="step in transaction.timeline"
                        :key="step.label"
                        class="flex flex-wrap justify-between gap-2 py-2.5 first:pt-0 last:pb-0"
                    >
                        <span>{{ step.label }}</span>
                        <span class="text-muted-foreground">{{
                            step.at ?? step.on ?? 'Not yet'
                        }}</span>
                    </li>
                </ol>
                <dl
                    v-if="Object.keys(transaction.actors).length"
                    class="text-muted-foreground grid gap-2 border-t pt-4 sm:grid-cols-2"
                >
                    <div v-for="(name, role) in transaction.actors" :key="role">
                        <dt class="capitalize">
                            {{ String(role).replaceAll('_', ' ') }}
                        </dt>
                        <dd class="text-foreground">{{ name ?? 'Not yet' }}</dd>
                    </div>
                </dl>
            </CardContent>
        </Card>

        <Link
            :href="transactionsIndex()"
            class="text-primary w-fit text-sm underline underline-offset-4"
            >Back to transactions</Link
        >
    </div>

    <FormSheet
        v-if="reversal_original"
        v-model:open="correctionOpen"
        title="Request a correction"
        description="Ask an admin to reverse this transaction in full."
    >
        <p
            v-if="previewHttp.processing"
            role="status"
            class="text-muted-foreground text-sm"
        >
            Checking this transaction…
        </p>
        <div v-else-if="quoteError" class="grid gap-3">
            <p role="alert" class="text-destructive text-sm">
                {{ quoteError }}
            </p>
            <Button variant="outline" class="w-fit" @click="reviewCorrection"
                >Try again</Button
            >
        </div>
        <form
            v-else-if="quote"
            id="correction-form"
            class="grid gap-5"
            @submit.prevent="requestCorrection"
        >
            <div class="bg-muted/40 rounded-xl p-4 text-sm">
                <p>
                    Amount to reverse:
                    <span class="font-medium">{{
                        money(quote.gross_kobo)
                    }}</span>
                </p>
                <p class="text-muted-foreground mt-1 text-xs">
                    Who holds the cash stays the same unless the return rules
                    say otherwise.
                </p>
            </div>
            <div class="grid gap-2">
                <Label for="correction-category">What went wrong?</Label>
                <Select v-model="reversal.reason_category" required
                    ><SelectTrigger id="correction-category" class="h-11 w-full"
                        ><SelectValue
                            placeholder="Choose a reason" /></SelectTrigger
                    ><SelectContent>
                        <SelectItem
                            v-for="[value, label] in reasonCategories"
                            :key="value"
                            :value="value"
                        >
                            {{ label }}
                        </SelectItem>
                    </SelectContent></Select
                >
            </div>
            <div class="grid gap-2">
                <Label for="correction-reason"
                    >Details
                    <span class="text-muted-foreground font-normal"
                        >(staff only)</span
                    ></Label
                ><Input
                    id="correction-reason"
                    v-model="reversal.internal_reason"
                    required
                    maxlength="1000"
                />
            </div>
            <div class="grid gap-2">
                <Label for="correction-description"
                    >Message for the customer</Label
                ><Input
                    id="correction-description"
                    v-model="reversal.customer_explanation"
                    required
                    maxlength="500"
                />
            </div>
            <div class="grid gap-2">
                <Label for="correction-evidence">Proof</Label
                ><Input
                    id="correction-evidence"
                    v-model="reversal.evidence_text"
                    required
                    maxlength="1000"
                />
                <p class="text-muted-foreground text-xs">
                    Describe what shows the mistake, like a receipt or cash
                    count.
                </p>
            </div>
            <div class="grid gap-2">
                <Label for="correction-files"
                    >Files
                    <span class="text-muted-foreground font-normal"
                        >(optional, up to 3)</span
                    ></Label
                >
                <input
                    id="correction-files"
                    type="file"
                    multiple
                    accept="image/jpeg,image/png,image/webp,application/pdf"
                    class="border-input bg-background rounded-xl border p-2 text-sm"
                    @change="chooseFiles"
                />
                <p class="text-muted-foreground text-xs">
                    JPEG, PNG, WebP or PDF, up to 5 MB each. Files are private.
                    The customer can't see them.
                </p>
            </div>
            <p
                v-for="(error, key) in reversal.errors"
                :key="key"
                role="alert"
                class="text-destructive text-sm"
            >
                {{ error }}
            </p>
            <div class="bg-muted/40 flex items-start gap-3 rounded-xl p-4">
                <Checkbox
                    id="correction-confirmed"
                    v-model="reversal.confirmed"
                />
                <Label for="correction-confirmed" class="leading-5"
                    >Send this full correction to an admin for review</Label
                >
            </div>
        </form>
        <template v-if="quote && !quoteError" #footer>
            <Button
                type="button"
                variant="outline"
                :disabled="reversal.processing"
                @click="correctionOpen = false"
                >Cancel</Button
            >
            <Button
                type="submit"
                form="correction-form"
                :disabled="reversal.processing || !reversal.confirmed"
                >Send request</Button
            >
        </template>
    </FormSheet>
</template>
