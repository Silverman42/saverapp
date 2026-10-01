<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { dashboard } from '@/routes';
import { index as feesIndex } from '@/routes/admin/fees';
import { index as registrationFeesIndex } from '@/routes/admin/fees/registration';
import { show as showCustomer } from '@/routes/customers';
import {
    correct as correctObligation,
    waive as waiveObligation,
} from '@/routes/admin/fees/obligations';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Obligation = {
    id: number;
    customer_id: string;
    customer_name: string;
    kind: string;
    rule_name: string;
    amount_kobo: number;
    formatted_amount: string;
    settled_amount_kobo: number;
    outstanding_amount_kobo: number;
    formatted_outstanding_amount: string;
    status: string;
    status_label: string;
    can_waive: boolean;
    can_correct: boolean;
};

type PageLink = { url: string | null; label: string; active: boolean };

const props = defineProps<{
    summary: {
        obligation_count: number;
        pending_count: number;
        formatted_outstanding_amount: string;
        as_of: string;
        earnings: {
            status: 'available' | 'unavailable';
            message?: string;
            formatted_lifetime_gross?: string;
            formatted_lifetime_refunds?: string;
            formatted_lifetime_net?: string;
            formatted_today_net?: string;
            formatted_month_net?: string;
            today_net_kobo?: number;
            month_net_kobo?: number;
        };
        refund_payable: {
            status: 'available' | 'unavailable';
            message?: string;
            formatted_amount?: string;
        };
    };
    obligations: {
        data: Obligation[];
        links: PageLink[];
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Fees and Deductions', href: feesIndex() },
        ],
    },
});

const selectedObligation = ref<Obligation | null>(null);
const action = ref<'waive' | 'correct'>('waive');
const showActionDialog = ref(false);

const form = useForm({
    amount_ngn: '',
    direction: 'reduce',
    reason: '',
    customer_description: '',
    attempt_reference: '',
});

const openAction = (
    selected: Obligation,
    selectedAction: 'waive' | 'correct',
): void => {
    selectedObligation.value = selected;
    action.value = selectedAction;
    form.reset();
    form.clearErrors();
    form.direction = 'reduce';
    form.attempt_reference = crypto.randomUUID();
    showActionDialog.value = true;
};

const submitAction = (): void => {
    if (!selectedObligation.value) return;

    const endpoint =
        action.value === 'waive'
            ? waiveObligation(selectedObligation.value.id)
            : correctObligation(selectedObligation.value.id);

    form.post(endpoint.url, {
        preserveScroll: true,
        onSuccess: () => {
            showActionDialog.value = false;
            form.reset();
        },
    });
};
</script>

<template>
    <div>
        <Head title="Fees and Deductions" />

        <div class="space-y-6">
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
            >
                <div>
                    <h1 class="text-[25px] font-medium tracking-tight">
                        Fees and Deductions
                    </h1>
                    <p class="text-muted-foreground mt-1.5 text-sm">
                        Outstanding fee obligations and recognized earnings from
                        committed ledger postings.
                    </p>
                </div>
                <Button as-child variant="outline">
                    <Link :href="registrationFeesIndex().url"
                        >Manage fee rules</Link
                    >
                </Button>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Card>
                    <CardHeader class="pb-2"
                        ><CardDescription
                            >Outstanding fees</CardDescription
                        ></CardHeader
                    >
                    <CardContent>
                        <p class="text-2xl font-semibold">
                            {{ summary.formatted_outstanding_amount }}
                        </p>
                        <p class="text-muted-foreground mt-1 text-xs">
                            {{ summary.pending_count }} obligations have an
                            unpaid balance
                        </p>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader class="pb-2"
                        ><CardDescription
                            >Fee obligations</CardDescription
                        ></CardHeader
                    >
                    <CardContent>
                        <p class="text-2xl font-semibold">
                            {{ summary.obligation_count }}
                        </p>
                        <p class="text-muted-foreground mt-1 text-xs">
                            All registered fee snapshots with an assessment
                        </p>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader class="pb-2"
                        ><CardDescription
                            >Lifetime net earnings</CardDescription
                        ></CardHeader
                    >
                    <CardContent v-if="summary.earnings.status === 'available'">
                        <p class="text-2xl font-semibold">
                            {{ summary.earnings.formatted_lifetime_net }}
                        </p>
                        <p class="text-muted-foreground mt-1 text-xs">
                            Gross
                            {{ summary.earnings.formatted_lifetime_gross }} ·
                            refunds
                            {{ summary.earnings.formatted_lifetime_refunds }}
                        </p>
                        <p class="text-muted-foreground mt-1 text-xs">
                            Today {{ summary.earnings.formatted_today_net }} ·
                            this month
                            {{ summary.earnings.formatted_month_net }}
                        </p>
                    </CardContent>
                    <CardContent v-else>
                        <Badge variant="secondary">Unavailable</Badge>
                        <p class="text-muted-foreground mt-2 text-xs">
                            {{ summary.earnings.message }}
                        </p>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader class="pb-2"
                        ><CardDescription
                            >Refund payable</CardDescription
                        ></CardHeader
                    >
                    <CardContent
                        v-if="summary.refund_payable.status === 'available'"
                    >
                        <p class="text-2xl font-semibold">
                            {{ summary.refund_payable.formatted_amount }}
                        </p>
                        <p class="text-muted-foreground mt-1 text-xs">
                            External refund entitlements not yet paid
                        </p>
                    </CardContent>
                    <CardContent v-else>
                        <Badge variant="secondary">Unavailable</Badge>
                        <p class="text-muted-foreground mt-2 text-xs">
                            {{ summary.refund_payable.message }}
                        </p>
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Fee obligation status</CardTitle>
                    <CardDescription
                        >Balances are derived from immutable assessments,
                        settlements, waivers, and corrections. Updated
                        {{ summary.as_of }}.</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <div
                        v-if="obligations.data.length === 0"
                        class="text-muted-foreground rounded-lg border border-dashed p-8 text-center text-sm"
                    >
                        No fee obligations are recorded.
                    </div>
                    <div v-else class="overflow-x-auto rounded-lg border">
                        <table class="w-full min-w-[850px] text-left text-sm">
                            <thead
                                class="bg-muted/40 text-muted-foreground text-xs uppercase"
                            >
                                <tr>
                                    <th class="px-4 py-3 font-medium">
                                        Customer
                                    </th>
                                    <th class="px-4 py-3 font-medium">Fee</th>
                                    <th
                                        class="px-4 py-3 text-right font-medium"
                                    >
                                        Assessed
                                    </th>
                                    <th
                                        class="px-4 py-3 text-right font-medium"
                                    >
                                        Outstanding
                                    </th>
                                    <th class="px-4 py-3 font-medium">
                                        Status
                                    </th>
                                    <th
                                        class="px-4 py-3 text-right font-medium"
                                    >
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr
                                    v-for="obligation in obligations.data"
                                    :key="obligation.id"
                                >
                                    <td class="px-4 py-3">
                                        <Link
                                            :href="
                                                showCustomer(
                                                    obligation.customer_id,
                                                ).url
                                            "
                                            class="font-medium hover:underline"
                                            >{{
                                                obligation.customer_name
                                            }}</Link
                                        >
                                        <p
                                            class="text-muted-foreground text-xs"
                                        >
                                            {{ obligation.customer_id }}
                                        </p>
                                    </td>
                                    <td class="px-4 py-3">
                                        <p>{{ obligation.rule_name }}</p>
                                        <p
                                            class="text-muted-foreground text-xs capitalize"
                                        >
                                            {{ obligation.kind }}
                                        </p>
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono">
                                        {{ obligation.formatted_amount }}
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono">
                                        {{
                                            obligation.formatted_outstanding_amount
                                        }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <Badge variant="outline">{{
                                            obligation.status_label
                                        }}</Badge>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex justify-end gap-2">
                                            <Button
                                                v-if="obligation.can_waive"
                                                size="sm"
                                                variant="outline"
                                                @click="
                                                    openAction(
                                                        obligation,
                                                        'waive',
                                                    )
                                                "
                                                >Waive</Button
                                            >
                                            <Button
                                                v-if="obligation.can_correct"
                                                size="sm"
                                                variant="ghost"
                                                @click="
                                                    openAction(
                                                        obligation,
                                                        'correct',
                                                    )
                                                "
                                                >Correct</Button
                                            >
                                            <span
                                                v-if="
                                                    !obligation.can_waive &&
                                                    !obligation.can_correct
                                                "
                                                class="text-muted-foreground text-xs"
                                                >—</span
                                            >
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <nav
                        v-if="obligations.links.length > 3"
                        class="mt-4 flex flex-wrap justify-end gap-1"
                        aria-label="Fee obligation pages"
                    >
                        <template
                            v-for="(link, index) in obligations.links"
                            :key="index"
                        >
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                preserve-scroll
                                class="rounded border px-3 py-1.5 text-xs"
                                :class="
                                    link.active
                                        ? 'bg-primary text-primary-foreground'
                                        : 'hover:bg-muted'
                                "
                                v-html="link.label"
                            />
                            <span
                                v-else
                                class="text-muted-foreground rounded border px-3 py-1.5 text-xs"
                                v-html="link.label"
                            />
                        </template>
                    </nav>
                </CardContent>
            </Card>

            <div
                class="rounded-lg border border-amber-500/30 bg-amber-500/5 p-4 text-sm"
            >
                <p class="font-medium">Posting and owner workflows are gated</p>
                <p class="text-muted-foreground mt-1">
                    Fee earnings and refund payable remain unavailable until
                    Module 10 maps the approved accounts. Plan assessment,
                    collection settlement, withdrawals, reversals, and external
                    refund payouts wait for their owning contracts.
                </p>
            </div>
        </div>

        <Dialog
            :open="showActionDialog"
            @update:open="showActionDialog = $event"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{
                        action === 'waive'
                            ? 'Waive fee balance'
                            : 'Correct unsettled assessment'
                    }}</DialogTitle>
                    <DialogDescription>
                        {{ selectedObligation?.customer_name }} ·
                        {{ selectedObligation?.formatted_outstanding_amount }}
                        currently outstanding. The action is audited and cannot
                        exceed this balance.
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="submitAction">
                    <div class="space-y-1.5">
                        <template v-if="action === 'correct'">
                            <Label for="fee-action-direction"
                                >Correction direction</Label
                            >
                            <select
                                id="fee-action-direction"
                                v-model="form.direction"
                                class="border-input bg-background focus-visible:border-ring flex h-10 w-full rounded-md border px-3 py-2 text-sm"
                            >
                                <option value="reduce">
                                    Reduce assessed fee
                                </option>
                                <option value="increase">
                                    Increase assessed fee
                                </option>
                            </select>
                            <p
                                v-if="form.errors.direction"
                                class="text-destructive text-xs"
                            >
                                {{ form.errors.direction }}
                            </p>
                        </template>
                        <Label for="fee-action-amount">Amount (NGN)</Label>
                        <Input
                            id="fee-action-amount"
                            v-model="form.amount_ngn"
                            type="number"
                            min="0.01"
                            step="0.01"
                            required
                        />
                        <p
                            v-if="form.errors.amount_ngn"
                            class="text-destructive text-xs"
                        >
                            {{ form.errors.amount_ngn }}
                        </p>
                    </div>
                    <div class="space-y-1.5">
                        <Label for="fee-action-description"
                            >Customer disclosure</Label
                        >
                        <Input
                            id="fee-action-description"
                            v-model="form.customer_description"
                            maxlength="500"
                            required
                        />
                        <p
                            v-if="form.errors.customer_description"
                            class="text-destructive text-xs"
                        >
                            {{ form.errors.customer_description }}
                        </p>
                    </div>
                    <div class="space-y-1.5">
                        <Label for="fee-action-reason">Internal reason</Label>
                        <textarea
                            id="fee-action-reason"
                            v-model="form.reason"
                            rows="3"
                            maxlength="500"
                            required
                            class="border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:outline-none"
                        />
                        <p
                            v-if="form.errors.reason"
                            class="text-destructive text-xs"
                        >
                            {{ form.errors.reason }}
                        </p>
                    </div>
                    <p
                        v-if="form.errors.attempt_reference"
                        class="text-destructive text-xs"
                    >
                        {{ form.errors.attempt_reference }}
                    </p>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="showActionDialog = false"
                            >Cancel</Button
                        >
                        <Button type="submit" :disabled="form.processing">{{
                            form.processing ? 'Saving…' : 'Confirm'
                        }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
