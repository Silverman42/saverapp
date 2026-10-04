<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { dashboard } from '@/routes';
import { show as showCustomer } from '@/routes/customers';
import {
    issue as issueStatement,
    preview as statementPreview,
} from '@/routes/customers/statements';
import { show as showArtifact } from '@/routes/financial-artifacts';
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
        <form
            v-if="preview.status === 'ready'"
            class="flex flex-wrap items-center gap-4"
            @submit.prevent="issue"
        >
            <label
                v-if="
                    issued_statements.some(
                        (statement) =>
                            statement.status === 'ready' &&
                            !statement.superseded,
                    )
                "
                class="grid gap-1 text-sm"
                >Supersede an issued statement for this period
                <select
                    v-model="issuance.supersedes_reference"
                    class="rounded-md border p-2"
                >
                    <option value="">Issue a new statement</option>
                    <option
                        v-for="statement in issued_statements.filter(
                            (statement) =>
                                statement.status === 'ready' &&
                                !statement.superseded,
                        )"
                        :key="statement.artifact_reference"
                        :value="statement.artifact_reference"
                    >
                        {{ statement.artifact_reference }}
                    </option>
                </select>
            </label>
            <label class="flex gap-3 text-sm"
                ><input v-model="issuance.confirmed" type="checkbox" />I confirm
                issuance of this period's statement at the verified
                cutoff.</label
            >
            <Button :disabled="issuance.processing || !issuance.confirmed"
                >Issue PDF statement</Button
            >
            <p
                v-for="(error, key) in issuance.errors"
                :key="key"
                class="text-destructive text-sm"
            >
                {{ error }}
            </p>
        </form>
        <div v-if="issued_statements.length" class="grid gap-2">
            <p class="font-medium">Statement history</p>
            <Link
                v-for="statement in issued_statements"
                :key="statement.artifact_reference"
                :href="showArtifact(statement.artifact_reference)"
                class="text-primary text-sm underline"
                >{{ statement.artifact_reference }} ·
                {{
                    statement.superseded ? 'superseded' : statement.status
                }}</Link
            >
        </div>
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
                <CardContent class="grid gap-4 pt-6 text-sm sm:grid-cols-3">
                    <div>
                        <p class="text-muted-foreground">
                            Current live withdrawal reservations
                        </p>
                        <p>
                            {{
                                preview.current_reserved_kobo == null
                                    ? 'Unavailable'
                                    : money(preview.current_reserved_kobo)
                            }}
                        </p>
                    </div>
                    <div>
                        <p class="text-muted-foreground">
                            Current available savings
                        </p>
                        <p>
                            {{
                                preview.current_available_kobo == null
                                    ? 'Unavailable'
                                    : money(preview.current_available_kobo)
                            }}
                        </p>
                    </div>
                    <div>
                        <p class="text-muted-foreground">
                            Unpaid fees (separate from savings)
                        </p>
                        <p>
                            {{
                                preview.unpaid_fees_kobo == null
                                    ? 'Unavailable'
                                    : money(preview.unpaid_fees_kobo)
                            }}
                        </p>
                    </div>
                </CardContent>
            </Card>
            <Card v-if="preview.type_totals?.length">
                <CardContent class="grid gap-2 pt-6 text-sm">
                    <p class="font-medium">Activity by type</p>
                    <p v-for="total in preview.type_totals" :key="total.type">
                        {{ total.type.replaceAll('_', ' ') }} ·
                        {{ total.count }} · savings effect
                        {{ money(total.savings_effect_kobo) }} · fees
                        {{ money(total.fee_amount_kobo) }}
                    </p>
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
