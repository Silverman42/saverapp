<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { FileText, ReceiptText } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import FormSheet from '@/components/FormSheet.vue';
import MoreDetails from '@/components/MoreDetails.vue';
import PageHeader from '@/components/PageHeader.vue';
import { dashboard } from '@/routes';
import { show as showCustomer } from '@/routes/customers';
import {
    issue as issueStatement,
    preview as statementPreview,
} from '@/routes/customers/statements';
import { show as showArtifact } from '@/routes/financial-artifacts';
import { show as showTransaction } from '@/routes/transactions';
import { DatePicker } from '@/components/ui/date-picker';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

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
        preview_fingerprint?: string;
        message?: string;
        from?: string;
        to?: string;
        timezone?: string;
        cutoff_at?: string;
        ledger_watermark?: number;
        opening_kobo?: number;
        activity_kobo?: number;
        closing_kobo?: number;
        current_reserved_kobo?: number | null;
        current_available_kobo?: number | null;
        unpaid_fees_kobo?: number | null;
        type_totals?: Array<{
            type: string;
            count: number;
            savings_effect_kobo: number;
            fee_amount_kobo: number;
        }>;
        lines?: StatementLine[];
    };
    issued_statements: Array<{
        artifact_reference: string;
        status: string;
        issued_at: string | null;
        superseded: boolean;
    }>;
    from: string;
    to: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});

const issuance = useForm({
    operation_reference: crypto.randomUUID(),
    preview_fingerprint: props.preview.preview_fingerprint ?? '',
    supersedes_reference: '',
    from: props.from,
    to: props.to,
    confirmed: false,
});
function issue(): void {
    issuance.from = from.value;
    issuance.to = to.value;
    issuance.preview_fingerprint = props.preview.preview_fingerprint ?? '';
    issuance.post(issueStatement.url(props.customer.id));
}
const from = ref(props.from);
const to = ref(props.to);
const issueOpen = ref(false);
const replaceableStatements = computed(() =>
    props.issued_statements.filter(
        (statement) => statement.status === 'ready' && !statement.superseded,
    ),
);
function optionalMoney(kobo: number | null | undefined): string {
    return kobo == null ? 'Not available' : money(kobo);
}
function typeLabel(value: string): string {
    const words = value.replaceAll('_', ' ');
    return words.charAt(0).toUpperCase() + words.slice(1);
}

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
        <PageHeader
            title="Statement"
            :description="`Savings activity for ${customer.name}.`"
        >
            <template v-if="preview.status === 'ready'" #actions>
                <Button @click="issueOpen = true"
                    ><FileText class="size-4" />Create PDF</Button
                >
            </template>
        </PageHeader>

        <form
            class="flex flex-row flex-wrap items-end gap-4"
            aria-label="Statement period"
            @submit.prevent="refresh"
        >
            <div class="grid w-fit gap-2">
                <Label for="statement-from">From</Label>
                <DatePicker
                    id="statement-from"
                    v-model="from"
                    aria-label="From"
                    class="w-fit"
                />
            </div>
            <div class="grid w-fit gap-2">
                <Label for="statement-to">To</Label>
                <DatePicker
                    id="statement-to"
                    v-model="to"
                    aria-label="To"
                    class="w-fit"
                />
            </div>
            <Button type="submit" variant="outline">Show</Button>
        </form>

        <EmptyState
            v-if="preview.status === 'unavailable'"
            :icon="ReceiptText"
            title="Statement not available right now"
            :description="
                preview.message ??
                'We are still checking the records. Please try again soon.'
            "
        />
        <template v-else>
            <Card>
                <CardHeader>
                    <CardTitle
                        >{{ preview.from }} to {{ preview.to }}</CardTitle
                    >
                </CardHeader>
                <CardContent class="space-y-5">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="bg-muted/40 rounded-xl p-4">
                            <p class="text-muted-foreground text-sm">
                                Starting balance
                            </p>
                            <p class="mt-1 text-xl font-semibold">
                                {{ money(preview.opening_kobo ?? 0) }}
                            </p>
                        </div>
                        <div class="bg-muted/40 rounded-xl p-4">
                            <p class="text-muted-foreground text-sm">
                                Change in period
                            </p>
                            <p class="mt-1 text-xl font-semibold">
                                {{ money(preview.activity_kobo ?? 0) }}
                            </p>
                        </div>
                        <div class="bg-muted/40 rounded-xl p-4">
                            <p class="text-muted-foreground text-sm">
                                Ending balance
                            </p>
                            <p class="mt-1 text-xl font-semibold">
                                {{ money(preview.closing_kobo ?? 0) }}
                            </p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <h3 class="text-sm font-medium">Right now</h3>
                        <dl class="grid gap-4 text-sm sm:grid-cols-3">
                            <div>
                                <dt class="text-muted-foreground">Available</dt>
                                <dd class="font-medium">
                                    {{
                                        optionalMoney(
                                            preview.current_available_kobo,
                                        )
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">
                                    Set aside for withdrawals
                                </dt>
                                <dd class="font-medium">
                                    {{
                                        optionalMoney(
                                            preview.current_reserved_kobo,
                                        )
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">
                                    Unpaid fees
                                </dt>
                                <dd class="font-medium">
                                    {{
                                        optionalMoney(preview.unpaid_fees_kobo)
                                    }}
                                    <span
                                        class="text-muted-foreground block text-xs font-normal"
                                        >Not taken from savings</span
                                    >
                                </dd>
                            </div>
                        </dl>
                    </div>
                    <MoreDetails
                        v-if="preview.type_totals?.length"
                        label="Totals by type"
                    >
                        <table class="w-full text-left text-sm">
                            <thead class="text-muted-foreground text-xs">
                                <tr>
                                    <th scope="col" class="py-2 font-medium">
                                        Type
                                    </th>
                                    <th
                                        scope="col"
                                        class="py-2 text-right font-medium"
                                    >
                                        Count
                                    </th>
                                    <th
                                        scope="col"
                                        class="py-2 text-right font-medium"
                                    >
                                        Savings
                                    </th>
                                    <th
                                        scope="col"
                                        class="py-2 text-right font-medium"
                                    >
                                        Fees
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr
                                    v-for="total in preview.type_totals"
                                    :key="total.type"
                                >
                                    <td class="py-2">
                                        {{ typeLabel(total.type) }}
                                    </td>
                                    <td class="py-2 text-right">
                                        {{ total.count }}
                                    </td>
                                    <td class="py-2 text-right">
                                        {{ money(total.savings_effect_kobo) }}
                                    </td>
                                    <td class="py-2 text-right">
                                        {{ money(total.fee_amount_kobo) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </MoreDetails>
                </CardContent>
            </Card>

            <Card>
                <CardHeader><CardTitle>Activity</CardTitle></CardHeader>
                <CardContent class="space-y-4">
                    <p
                        v-if="!preview.lines?.length"
                        class="text-muted-foreground text-sm"
                    >
                        No savings activity in this period.
                    </p>
                    <ul v-else class="divide-y">
                        <li
                            v-for="line in preview.lines"
                            :key="line.reference"
                            class="flex flex-wrap items-center justify-between gap-4 py-3 first:pt-0 last:pb-0"
                        >
                            <div class="grid gap-0.5 text-sm">
                                <span class="font-medium">{{
                                    typeLabel(line.type)
                                }}</span>
                                <span class="text-muted-foreground text-xs"
                                    >{{ line.occurred_on }} ·
                                    <Link
                                        :href="showTransaction(line.reference)"
                                        class="underline underline-offset-4"
                                        >{{ line.reference }}</Link
                                    ></span
                                >
                            </div>
                            <span class="text-sm font-medium">{{
                                money(line.savings_effect_kobo)
                            }}</span>
                        </li>
                    </ul>
                    <MoreDetails label="About this statement">
                        <p class="text-muted-foreground text-xs">
                            This is a preview, not an official statement. Dates
                            use {{ preview.timezone }}. Includes records up to
                            {{ preview.cutoff_at }} (position
                            {{ preview.ledger_watermark }}).
                        </p>
                    </MoreDetails>
                </CardContent>
            </Card>
        </template>

        <Card v-if="issued_statements.length">
            <CardHeader><CardTitle>Past statements</CardTitle></CardHeader>
            <CardContent>
                <ul class="divide-y">
                    <li
                        v-for="statement in issued_statements"
                        :key="statement.artifact_reference"
                        class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
                    >
                        <div class="grid gap-0.5 text-sm">
                            <Link
                                :href="
                                    showArtifact(statement.artifact_reference)
                                "
                                class="font-medium underline-offset-4 hover:underline"
                                >{{ statement.artifact_reference }}</Link
                            >
                            <span
                                v-if="statement.issued_at"
                                class="text-muted-foreground text-xs"
                                >{{ statement.issued_at }}</span
                            >
                        </div>
                        <Badge variant="secondary" class="capitalize">{{
                            statement.superseded ? 'Replaced' : statement.status
                        }}</Badge>
                    </li>
                </ul>
            </CardContent>
        </Card>

        <Link
            :href="showCustomer(customer.id)"
            class="text-primary w-fit text-sm underline underline-offset-4"
            >Back to customer</Link
        >
    </div>

    <FormSheet
        v-if="preview.status === 'ready'"
        v-model:open="issueOpen"
        title="Create PDF statement"
        :description="`For ${preview.from} to ${preview.to}.`"
    >
        <form
            id="issue-statement-form"
            class="grid gap-5"
            @submit.prevent="issue"
        >
            <div v-if="replaceableStatements.length" class="grid gap-2">
                <Label for="statement-supersedes"
                    >Replace an earlier PDF?</Label
                >
                <Select
                    :model-value="issuance.supersedes_reference || '__new'"
                    @update:model-value="
                        issuance.supersedes_reference =
                            $event === '__new' ? '' : String($event ?? '')
                    "
                    ><SelectTrigger id="statement-supersedes" class="w-full"
                        ><SelectValue /></SelectTrigger
                    ><SelectContent
                        ><SelectItem value="__new"
                            >No, create a new one</SelectItem
                        ><SelectItem
                            v-for="statement in replaceableStatements"
                            :key="statement.artifact_reference"
                            :value="statement.artifact_reference"
                            >{{ statement.artifact_reference }}</SelectItem
                        ></SelectContent
                    ></Select
                >
            </div>
            <div class="bg-muted/40 flex items-start gap-3 rounded-xl p-4">
                <Checkbox
                    id="statement-confirmed"
                    v-model="issuance.confirmed"
                />
                <Label for="statement-confirmed" class="leading-5"
                    >Create the official statement for this period</Label
                >
            </div>
            <p
                v-for="(error, key) in issuance.errors"
                :key="key"
                role="alert"
                class="text-destructive text-sm"
            >
                {{ error }}
            </p>
        </form>
        <template #footer>
            <Button
                type="button"
                variant="outline"
                :disabled="issuance.processing"
                @click="issueOpen = false"
                >Cancel</Button
            >
            <Button
                type="submit"
                form="issue-statement-form"
                :disabled="issuance.processing || !issuance.confirmed"
                >Create PDF</Button
            >
        </template>
    </FormSheet>
</template>
