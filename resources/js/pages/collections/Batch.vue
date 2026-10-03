<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { DatePicker } from '@/components/ui/date-picker';
import CollectionSettlementPanel from '@/components/CollectionSettlementPanel.vue';
import { dashboard } from '@/routes';
import {
    index as batchesIndex,
    review as reviewBatch,
} from '@/routes/collection-batches';
import { store as storeRemittance } from '@/routes/collection-batches/remittances';
import { watch } from 'vue';
import {
    resolve as resolveException,
    reopen as reopenException,
    store as storeException,
    progress as progressException,
} from '@/routes/collection-batches/exceptions';

type Page<T> = {
    data: T[];
    prev_page_url: string | null;
    next_page_url: string | null;
};
type Receipt = { id: string; tender_kobo: number };
type Remittance = { reference: string; amount_kobo: number };
type Exception = {
    id: number;
    kind: string;
    status: string;
    amount_kobo: number;
    reason: string | null;
};
type Batch = {
    id: number;
    date: string;
    revision: number;
    status: string;
    version: number;
    expected_kobo: number;
    savings_kobo: number;
    fees_kobo: number;
    remitted_kobo: number;
    outstanding_kobo: number;
    receipt_count: number;
    method_label: string;
    cash_handoff_allowed: boolean;
    settlement_pending: boolean;
    custody_account_code: string;
    timezone: string;
};
const props = defineProps<{
    batch: Batch;
    receipts: Page<Receipt> | null;
    remittances: Page<Remittance>;
    exceptions: Page<Exception>;
    resolution_records: Page<{
        id: number;
        exception_id: number;
        kind: string;
        receipt_reference: string | null;
        reversal_id: string | null;
        outstanding_kobo: number;
        cause: string;
        recorded_at: string | null;
    }> | null;
    can_manage: boolean;
    settlement_banks: {
        id: number;
        label: string;
        version: number;
        destination_key: string;
    }[];
    settlements: Page<{
        reference: string;
        amount_kobo: number;
        date: string;
        bank_reference: string;
        files: { id: number }[];
    }> | null;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Collection batches', href: batchesIndex() },
            { title: 'Batch', href: '#' },
        ],
    },
});
const money = (kobo: number): string =>
    `₦${(kobo / 100).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const remittance = useForm({
    handoff_reference: '',
    amount_ngn: '',
    handoff_date: props.batch.date,
    receiving_location: '',
    source_attestation: '',
    batch_version: props.batch.version,
    confirmed: false,
});
const review = useForm({
    batch_version: props.batch.version,
    reason: '',
    confirmed: false,
});
const resolution = useForm({
    batch_version: props.batch.version,
    reason: '',
    confirmed: false,
    resolution_kind: 'remittance',
    receipt_reference: '',
    reversal_id: '',
});
const reopening = useForm({
    batch_version: props.batch.version,
    reason: '',
    confirmed: false,
});
const progress = useForm({
    batch_version: props.batch.version,
    status: 'investigating',
    reason: '',
    confirmed: false,
});
const investigation = useForm({
    batch_version: props.batch.version,
    kind: props.batch.cash_handoff_allowed ? 'overage' : 'missing_transfer',
    amount_ngn: '',
    reason: '',
    confirmed: false,
});
watch(
    () => props.batch.version,
    (version) => {
        remittance.batch_version = version;
        review.batch_version = version;
        resolution.batch_version = version;
        reopening.batch_version = version;
        investigation.batch_version = version;
        progress.batch_version = version;
        progress.reason = '';
        progress.confirmed = false;
    },
);
function confirmRemittance(): void {
    remittance.post(storeRemittance.url(props.batch.id));
}
function submitReview(): void {
    review.post(reviewBatch.url(props.batch.id));
}
function resolve(exceptionId: number): void {
    resolution.resolution_kind = 'remittance';
    resolution.receipt_reference = '';
    resolution.reversal_id = '';
    resolution.post(resolveException.url([props.batch.id, exceptionId]));
}
function resolveEvidence(exceptionId: number): void {
    if (
        !resolution.confirmed ||
        resolution.processing ||
        resolution.resolution_kind === 'remittance'
    )
        return;
    resolution.post(resolveException.url([props.batch.id, exceptionId]));
}
function advanceException(exceptionId: number, status: string): void {
    progress.status = status;
    progress.post(progressException.url([props.batch.id, exceptionId]));
}
function reopen(exceptionId: number): void {
    reopening.post(reopenException.url([props.batch.id, exceptionId]));
}
function report(): void {
    investigation.post(storeException.url(props.batch.id));
}
</script>

<template>
    <Head :title="`Collection batch ${batch.date}`" />
    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">
                Collection batch {{ batch.date }}
            </h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                {{ batch.method_label }} · Revision {{ batch.revision }} ·
                {{ batch.status.replaceAll('_', ' ') }}
            </p>
        </div>
        <p
            v-if="batch.settlement_pending"
            class="text-muted-foreground text-sm"
            role="status"
        >
            Awaiting verified bank settlement before reconciliation.
        </p>
        <Card
            v-if="
                can_manage &&
                batch.custody_account_code === 'payment_clearing_ngn'
            "
        >
            <CardContent class="grid gap-4 pt-6">
                <CollectionSettlementPanel
                    :batch-id="batch.id"
                    :batch-version="batch.version"
                    :date="batch.date"
                    :timezone="batch.timezone"
                    :outstanding-kobo="batch.outstanding_kobo"
                    :can-record="
                        batch.status !== 'open' &&
                        batch.status !== 'reconciled' &&
                        batch.outstanding_kobo > 0
                    "
                    :banks="settlement_banks"
                    :settlements="settlements?.data ?? []"
                />
                <div class="flex gap-3 text-sm">
                    <Link
                        v-if="settlements?.prev_page_url"
                        :href="settlements.prev_page_url"
                        >Previous settlements</Link
                    >
                    <Link
                        v-if="settlements?.next_page_url"
                        :href="settlements.next_page_url"
                        >Next settlements</Link
                    >
                </div>
            </CardContent>
        </Card>
        <Card
            ><CardHeader><CardTitle>Custody position</CardTitle></CardHeader
            ><CardContent class="grid gap-3 sm:grid-cols-3"
                ><div>
                    Money recorded<br /><strong>{{
                        money(batch.expected_kobo)
                    }}</strong>
                    <p class="text-muted-foreground text-sm">
                        Savings {{ money(batch.savings_kobo) }} · fees
                        {{ money(batch.fees_kobo) }}
                    </p>
                </div>
                <div>
                    {{
                        batch.cash_handoff_allowed
                            ? 'Confirmed handoffs'
                            : 'Verified bank receipts'
                    }}<br /><strong>{{ money(batch.remitted_kobo) }}</strong>
                </div>
                <div>
                    {{
                        batch.cash_handoff_allowed
                            ? 'Outstanding Agent receivable'
                            : 'Awaiting bank settlement'
                    }}<br /><strong>{{ money(batch.outstanding_kobo) }}</strong>
                </div></CardContent
            ></Card
        >
        <Card v-if="can_manage && receipts"
            ><CardHeader
                ><CardTitle
                    >Posted receipts ({{ batch.receipt_count }})</CardTitle
                ></CardHeader
            ><CardContent
                ><p
                    v-if="receipts.data.length === 0"
                    class="text-muted-foreground text-sm"
                >
                    No receipts.
                </p>
                <ul v-else class="grid gap-2 text-sm">
                    <li v-for="receipt in receipts.data" :key="receipt.id">
                        {{ receipt.id }} · {{ money(receipt.tender_kobo) }}
                    </li>
                </ul>
                <div class="mt-4 flex gap-4 text-sm">
                    <Link
                        v-if="receipts.prev_page_url"
                        :href="receipts.prev_page_url"
                        class="underline"
                        >Previous receipts</Link
                    ><Link
                        v-if="receipts.next_page_url"
                        :href="receipts.next_page_url"
                        class="underline"
                        >Next receipts</Link
                    >
                </div></CardContent
            ></Card
        >
        <Card v-if="batch.cash_handoff_allowed"
            ><CardHeader><CardTitle>Confirmed handoffs</CardTitle></CardHeader
            ><CardContent
                ><p
                    v-if="remittances.data.length === 0"
                    class="text-muted-foreground text-sm"
                >
                    No confirmed cash handoffs.
                </p>
                <ul v-else class="grid gap-2 text-sm">
                    <li v-for="item in remittances.data" :key="item.reference">
                        {{ item.reference }} · {{ money(item.amount_kobo) }}
                    </li>
                </ul>
                <div class="mt-4 flex gap-4 text-sm">
                    <Link
                        v-if="remittances.prev_page_url"
                        :href="remittances.prev_page_url"
                        class="underline"
                        >Previous handoffs</Link
                    ><Link
                        v-if="remittances.next_page_url"
                        :href="remittances.next_page_url"
                        class="underline"
                        >Next handoffs</Link
                    >
                </div></CardContent
            ></Card
        >
        <Card
            v-if="
                can_manage &&
                batch.cash_handoff_allowed &&
                batch.status !== 'open' &&
                batch.status !== 'reconciled'
            "
            ><CardHeader><CardTitle>Confirm counted cash</CardTitle></CardHeader
            ><CardContent class="grid gap-4 sm:grid-cols-2"
                ><div class="grid gap-2">
                    <Label for="handoff-reference"
                        >Unique handoff reference</Label
                    ><Input
                        id="handoff-reference"
                        v-model="remittance.handoff_reference"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="handoff-amount">Amount counted (NGN)</Label
                    ><Input
                        id="handoff-amount"
                        v-model="remittance.amount_ngn"
                        inputmode="decimal"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="handoff-date">Handoff date</Label
                    ><DatePicker
                        id="handoff-date"
                        aria-label="Handoff date"
                        v-model="remittance.handoff_date"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="handoff-location">Receiving location</Label
                    ><Input
                        id="handoff-location"
                        v-model="remittance.receiving_location"
                    />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="handoff-attestation"
                        >Count and source attestation</Label
                    ><Input
                        id="handoff-attestation"
                        v-model="remittance.source_attestation"
                    />
                </div>
                <label class="flex gap-2 text-sm sm:col-span-2"
                    ><input v-model="remittance.confirmed" type="checkbox" /> I
                    counted and received this cash.</label
                ><Button
                    type="button"
                    :disabled="remittance.processing || !remittance.confirmed"
                    class="w-fit"
                    @click="confirmRemittance"
                    >Confirm handoff</Button
                >
                <p
                    v-for="(error, key) in remittance.errors"
                    :key="key"
                    class="text-destructive text-sm"
                >
                    {{ error }}
                </p></CardContent
            ></Card
        >
        <Card
            v-if="
                can_manage &&
                !batch.settlement_pending &&
                batch.status !== 'open' &&
                batch.status !== 'reconciled'
            "
            ><CardHeader><CardTitle>Review batch</CardTitle></CardHeader
            ><CardContent class="grid gap-4"
                ><div class="grid gap-2">
                    <Label for="review-reason"
                        >Review reason and evidence summary</Label
                    ><Input id="review-reason" v-model="review.reason" />
                </div>
                <label class="flex gap-2 text-sm"
                    ><input v-model="review.confirmed" type="checkbox" />
                    Confirm this review. A shortage opens an exception and
                    retains Customer credit.</label
                ><Button
                    type="button"
                    :disabled="review.processing || !review.confirmed"
                    class="w-fit"
                    @click="submitReview"
                    >Record review</Button
                >
                <p
                    v-for="(error, key) in review.errors"
                    :key="key"
                    class="text-destructive text-sm"
                >
                    {{ error }}
                </p></CardContent
            ></Card
        >
        <Card v-if="can_manage && batch.status !== 'open'"
            ><CardHeader
                ><CardTitle>Report an investigation</CardTitle></CardHeader
            ><CardContent class="grid max-w-md gap-3"
                ><Label for="exception-kind">Issue</Label
                ><select
                    id="exception-kind"
                    v-model="investigation.kind"
                    class="bg-background rounded-md border p-2 text-sm"
                >
                    <option v-if="batch.cash_handoff_allowed" value="overage">
                        Counted cash overage
                    </option>
                    <option value="missing_transfer">
                        Missing transfer evidence
                    </option></select
                ><Label for="exception-amount">Amount (NGN)</Label
                ><Input
                    id="exception-amount"
                    v-model="investigation.amount_ngn"
                    inputmode="decimal"
                /><Label for="exception-reason">Evidence and reason</Label
                ><Input
                    id="exception-reason"
                    v-model="investigation.reason"
                /><label class="flex gap-2 text-sm"
                    ><input v-model="investigation.confirmed" type="checkbox" />
                    Open an investigation without changing Customer savings or
                    assigning this amount.</label
                ><Button
                    type="button"
                    class="w-fit"
                    :disabled="
                        investigation.processing || !investigation.confirmed
                    "
                    @click="report"
                    >Report issue</Button
                >
                <p
                    v-for="(error, key) in investigation.errors"
                    :key="key"
                    class="text-destructive text-sm"
                >
                    {{ error }}
                </p></CardContent
            ></Card
        >
        <Card
            v-if="
                can_manage &&
                resolution_records &&
                resolution_records.data.length
            "
            ><CardHeader
                ><CardTitle>Verified resolution history</CardTitle></CardHeader
            ><CardContent class="grid gap-4">
                <div
                    v-for="record in resolution_records.data"
                    :key="record.id"
                    class="grid gap-1 border-b pb-3 text-sm last:border-0"
                >
                    <p class="font-medium">
                        Exception {{ record.exception_id }} ·
                        {{ record.kind.replaceAll('_', ' ') }}
                    </p>
                    <p>
                        {{
                            record.receipt_reference ??
                            'Receipt reference unavailable'
                        }}<span v-if="record.reversal_id">
                            · {{ record.reversal_id }}</span
                        >
                    </p>
                    <p>{{ record.cause }}</p>
                    <p>
                        Outstanding custody at resolution:
                        {{ money(record.outstanding_kobo) }}
                    </p>
                    <p class="text-muted-foreground">
                        {{ record.recorded_at }}
                    </p>
                </div>
                <nav aria-label="Resolution history pages" class="flex gap-4">
                    <Link
                        v-if="resolution_records.prev_page_url"
                        :href="resolution_records.prev_page_url"
                        class="underline"
                        >Previous resolutions</Link
                    ><Link
                        v-if="resolution_records.next_page_url"
                        :href="resolution_records.next_page_url"
                        class="underline"
                        >Next resolutions</Link
                    >
                </nav>
            </CardContent></Card
        >
        <Card v-if="exceptions.data.length"
            ><CardHeader><CardTitle>Exceptions</CardTitle></CardHeader
            ><CardContent class="grid gap-4"
                ><div
                    v-for="item in exceptions.data"
                    :key="item.id"
                    class="rounded-md border p-3 text-sm"
                >
                    <strong
                        >{{ item.kind.replaceAll('_', ' ') }} ·
                        {{ item.status }}</strong
                    >
                    <p>
                        {{ money(item.amount_kobo) }}
                        <span v-if="item.reason">· {{ item.reason }}</span>
                    </p>
                    <div
                        v-if="
                            can_manage &&
                            item.kind === 'cash_shortage' &&
                            item.status !== 'resolved' &&
                            batch.outstanding_kobo === 0
                        "
                        class="mt-3 grid gap-2"
                    >
                        <Label :for="`resolution-${item.id}`"
                            >Resolution reason</Label
                        ><Input
                            :id="`resolution-${item.id}`"
                            v-model="resolution.reason"
                        /><label class="flex gap-2"
                            ><input
                                v-model="resolution.confirmed"
                                type="checkbox"
                            />
                            Confirm this exception can close.</label
                        ><Button
                            type="button"
                            class="w-fit"
                            :disabled="
                                resolution.processing || !resolution.confirmed
                            "
                            @click="resolve(item.id)"
                            >Resolve exception</Button
                        >
                    </div>
                    <div
                        v-if="can_manage && item.status !== 'resolved'"
                        class="mt-3 grid gap-2"
                    >
                        <Label :for="`progress-${item.id}`"
                            >Investigation findings or required next
                            action</Label
                        >
                        <Input
                            :id="`progress-${item.id}`"
                            v-model="progress.reason"
                            maxlength="500"
                        />
                        <label class="flex items-start gap-2"
                            ><input
                                v-model="progress.confirmed"
                                type="checkbox"
                            />
                            Confirm this reasoned investigation update.</label
                        >
                        <Button
                            type="button"
                            class="w-fit"
                            :disabled="
                                progress.processing ||
                                !progress.confirmed ||
                                !progress.reason.trim()
                            "
                            @click="
                                advanceException(
                                    item.id,
                                    item.status === 'investigating'
                                        ? 'awaiting_action'
                                        : 'investigating',
                                )
                            "
                            >{{
                                item.status === 'investigating'
                                    ? 'Await verified action'
                                    : 'Investigate exception'
                            }}</Button
                        >
                        <p class="text-muted-foreground">
                            Investigation status preserves the original custody
                            and Customer credit. Outstanding funds still require
                            verified settlement.
                        </p>
                        <p
                            v-for="(error, field) in progress.errors"
                            :key="field"
                            role="alert"
                            class="text-destructive"
                        >
                            {{ error }}
                        </p>
                    </div>
                    <div
                        v-if="
                            can_manage &&
                            ['cash_shortage', 'missing_transfer'].includes(
                                item.kind,
                            ) &&
                            item.status !== 'resolved'
                        "
                        class="mt-3 grid gap-2"
                    >
                        <Label :for="`resolution-kind-${item.id}`"
                            >Evidence-backed resolution</Label
                        >
                        <select
                            :id="`resolution-kind-${item.id}`"
                            v-model="resolution.resolution_kind"
                            class="bg-background h-11 rounded-md border px-3"
                        >
                            <option value="remittance">
                                Choose resolution evidence
                            </option>
                            <option
                                v-if="item.kind === 'missing_transfer'"
                                value="verified_match"
                            >
                                Match an independently verified noncash receipt
                            </option>
                            <option value="approved_correction">
                                Link a separately approved receipt correction
                            </option>
                        </select>
                        <Label :for="`matched-receipt-${item.id}`"
                            >Original receipt reference in this batch</Label
                        >
                        <Input
                            :id="`matched-receipt-${item.id}`"
                            v-model="resolution.receipt_reference"
                            maxlength="80"
                        />
                        <template
                            v-if="
                                resolution.resolution_kind ===
                                'approved_correction'
                            "
                            ><Label :for="`matched-reversal-${item.id}`"
                                >Approved correction reference</Label
                            ><Input
                                :id="`matched-reversal-${item.id}`"
                                v-model="resolution.reversal_id"
                                maxlength="80"
                        /></template>
                        <Label :for="`verified-resolution-${item.id}`"
                            >Verified cause and resolution findings</Label
                        ><Input
                            :id="`verified-resolution-${item.id}`"
                            v-model="resolution.reason"
                            maxlength="500"
                        />
                        <label class="flex items-start gap-2"
                            ><input
                                v-model="resolution.confirmed"
                                type="checkbox"
                            />
                            I verified these existing records and this
                            resolution cause.</label
                        >
                        <p class="text-muted-foreground">
                            This closes the investigation only. Original custody
                            still requires verified handoff or settlement; it
                            does not change Customer credit or pay a refund.
                        </p>
                        <Button
                            type="button"
                            class="w-fit"
                            :disabled="
                                resolution.processing ||
                                !resolution.confirmed ||
                                !resolution.reason.trim() ||
                                !resolution.receipt_reference ||
                                resolution.resolution_kind === 'remittance'
                            "
                            @click="resolveEvidence(item.id)"
                            >Resolve with verified records</Button
                        >
                    </div>
                    <div
                        v-if="can_manage && item.status === 'resolved'"
                        class="mt-3 grid gap-2"
                    >
                        <Label :for="`reopen-${item.id}`"
                            >New evidence or reopening reason</Label
                        ><Input
                            :id="`reopen-${item.id}`"
                            v-model="reopening.reason"
                        /><label class="flex gap-2"
                            ><input
                                v-model="reopening.confirmed"
                                type="checkbox"
                            />
                            Confirm this case must reopen.</label
                        ><Button
                            type="button"
                            variant="outline"
                            class="w-fit"
                            :disabled="
                                reopening.processing || !reopening.confirmed
                            "
                            @click="reopen(item.id)"
                            >Reopen exception</Button
                        >
                    </div>
                </div>
                <div class="flex gap-4 text-sm">
                    <Link
                        v-if="exceptions.prev_page_url"
                        :href="exceptions.prev_page_url"
                        class="underline"
                        >Previous exceptions</Link
                    ><Link
                        v-if="exceptions.next_page_url"
                        :href="exceptions.next_page_url"
                        class="underline"
                        >Next exceptions</Link
                    >
                </div>
                <p
                    v-for="(error, key) in resolution.errors"
                    :key="key"
                    class="text-destructive text-sm"
                >
                    {{ error }}
                </p>
                <p
                    v-for="(error, key) in reopening.errors"
                    :key="key"
                    class="text-destructive text-sm"
                >
                    {{ error }}
                </p></CardContent
            ></Card
        >
        <Link
            :href="batchesIndex()"
            class="text-primary w-fit text-sm underline"
            >Back to collection batches</Link
        >
    </div>
</template>
