<script setup lang="ts">
import MoreDetails from '@/components/MoreDetails.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

export type FeeHistoryEntry = {
    type: string;
    formatted_amount: string;
    description: string | null;
    recorded_at: string | null;
};

export type FeeSnapshot = {
    name: string;
    model: string;
    rule_version: number;
    amount_kobo: number;
    formatted_amount: string;
    currency: string;
    customer_description: string;
    is_zero: boolean;
    acknowledged_at: string | null;
    obligation: {
        status: 'available' | 'no_fee' | 'unavailable';
        assessed_amount_kobo?: number;
        formatted_assessed_amount?: string;
        formatted_settled_amount?: string;
        formatted_waived_amount?: string;
        formatted_outstanding_amount?: string;
        obligation_status?: string;
        obligation_status_label?: string;
        message?: string;
        history?: Array<FeeHistoryEntry & { amount_kobo: number }>;
    };
};

export type PlanFeeObligation = {
    id: number;
    kind?: string;
    name?: string;
    rule_version?: number;
    status: string;
    message?: string;
    status_label?: string;
    formatted_assessed_amount?: string;
    formatted_settled_amount?: string;
    formatted_waived_amount?: string;
    formatted_outstanding_amount?: string;
    history?: FeeHistoryEntry[];
};

/**
 * A compact summary of a customer's registration fee and plan fees.
 * Fee terms and history are tucked away under "More details".
 */
defineProps<{
    feeSnapshot?: FeeSnapshot | null;
    planFees: PlanFeeObligation[];
}>();

const entryLabel = (type: string): string => type.replaceAll('_', ' ');
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="text-base">Fees</CardTitle>
        </CardHeader>
        <CardContent>
            <ul class="divide-y">
                <li v-if="feeSnapshot" class="space-y-3 py-4 first:pt-0">
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div class="min-w-0">
                            <p class="font-medium">Registration fee</p>
                            <p class="text-muted-foreground text-sm">
                                {{ feeSnapshot.name }} ·
                                {{ feeSnapshot.formatted_amount }}
                            </p>
                        </div>
                        <div
                            v-if="
                                feeSnapshot.obligation.status !== 'unavailable'
                            "
                            class="text-right"
                        >
                            <p class="font-semibold">
                                {{
                                    feeSnapshot.obligation
                                        .formatted_outstanding_amount ?? '₦0.00'
                                }}
                                <span
                                    class="text-muted-foreground text-xs font-normal"
                                    >due</span
                                >
                            </p>
                            <Badge variant="outline" class="mt-1">
                                {{
                                    feeSnapshot.obligation
                                        .obligation_status_label ?? 'No fee due'
                                }}
                            </Badge>
                        </div>
                    </div>
                    <p
                        v-if="feeSnapshot.obligation.status === 'unavailable'"
                        class="text-destructive text-sm"
                    >
                        {{ feeSnapshot.obligation.message }}
                    </p>
                    <MoreDetails>
                        <div class="text-muted-foreground space-y-3 text-xs">
                            <p>{{ feeSnapshot.customer_description }}</p>
                            <p>
                                {{
                                    feeSnapshot.acknowledged_at
                                        ? `Customer agreed on ${feeSnapshot.acknowledged_at}.`
                                        : 'The customer will agree to this fee when they set up their account.'
                                }}
                            </p>
                            <p
                                v-if="
                                    feeSnapshot.obligation
                                        .formatted_settled_amount
                                "
                            >
                                Paid
                                {{
                                    feeSnapshot.obligation
                                        .formatted_settled_amount
                                }}<span
                                    v-if="
                                        feeSnapshot.obligation
                                            .formatted_waived_amount
                                    "
                                >
                                    · Waived
                                    {{
                                        feeSnapshot.obligation
                                            .formatted_waived_amount
                                    }}</span
                                >
                            </p>
                            <ol
                                v-if="feeSnapshot.obligation.history?.length"
                                class="space-y-2 border-t pt-3"
                            >
                                <li
                                    v-for="(entry, index) in feeSnapshot
                                        .obligation.history"
                                    :key="`${entry.type}-${index}`"
                                    class="flex items-start justify-between gap-4"
                                >
                                    <div>
                                        <p
                                            class="text-foreground font-medium capitalize"
                                        >
                                            {{ entryLabel(entry.type) }}
                                        </p>
                                        <p v-if="entry.description">
                                            {{ entry.description }}
                                        </p>
                                        <p>{{ entry.recorded_at }}</p>
                                    </div>
                                    <span class="text-foreground shrink-0">{{
                                        entry.formatted_amount
                                    }}</span>
                                </li>
                            </ol>
                        </div>
                    </MoreDetails>
                </li>

                <li
                    v-for="obligation in planFees"
                    :key="obligation.id"
                    class="space-y-3 py-4 first:pt-0 last:pb-0"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div class="min-w-0">
                            <p class="font-medium">
                                {{ obligation.name ?? 'Plan fee' }}
                            </p>
                            <p
                                v-if="obligation.status !== 'unavailable'"
                                class="text-muted-foreground text-sm"
                            >
                                Charged
                                {{ obligation.formatted_assessed_amount }} ·
                                Paid {{ obligation.formatted_settled_amount }}
                            </p>
                        </div>
                        <div class="text-right">
                            <p
                                v-if="obligation.status !== 'unavailable'"
                                class="font-semibold"
                            >
                                {{ obligation.formatted_outstanding_amount }}
                                <span
                                    class="text-muted-foreground text-xs font-normal"
                                    >due</span
                                >
                            </p>
                            <Badge variant="outline" class="mt-1">{{
                                obligation.status_label ?? 'Unavailable'
                            }}</Badge>
                        </div>
                    </div>
                    <p
                        v-if="obligation.status === 'unavailable'"
                        class="text-destructive text-sm"
                    >
                        {{ obligation.message }}
                    </p>
                    <MoreDetails
                        v-if="obligation.history?.length"
                        label="Fee history"
                    >
                        <ol class="text-muted-foreground space-y-2 text-xs">
                            <li
                                v-for="(entry, index) in obligation.history"
                                :key="`${entry.type}-${index}`"
                                class="flex justify-between gap-4"
                            >
                                <div>
                                    <p class="text-foreground capitalize">
                                        {{ entryLabel(entry.type) }}
                                    </p>
                                    <p v-if="entry.description">
                                        {{ entry.description }}
                                    </p>
                                    <p>{{ entry.recorded_at }}</p>
                                </div>
                                <span class="text-foreground">{{
                                    entry.formatted_amount
                                }}</span>
                            </li>
                        </ol>
                    </MoreDetails>
                </li>
            </ul>
        </CardContent>
    </Card>
</template>
