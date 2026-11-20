<script setup lang="ts">
import { Head, Link, useForm } from "@inertiajs/vue3";
import { ArrowLeft } from "@lucide/vue";
import PageHeader from "@/components/PageHeader.vue";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { dashboard } from "@/routes";
import { show as showPlan } from "@/routes/plans";
import {
    index as reversalsIndex,
    show as showReversal,
} from "@/routes/reversals";
import { store } from "@/routes/reversals/wallet-credit";

const props = defineProps<{
    customer: { id: string; name: string };
    preview: {
        reversal_id: string;
        plan_id: string | null;
        plan_status: string | null;
        amount_kobo: number;
        preview_fingerprint: string;
    };
    attempt_reference: string;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: "Dashboard", href: dashboard() },
            { title: "Reversals", href: reversalsIndex() },
            { title: "Add to wallet", href: "#" },
        ],
    },
});
const form = useForm({
    attempt_reference: props.attempt_reference,
    preview_fingerprint: props.preview.preview_fingerprint,
    confirmed: false,
});
const amount = `₦${(props.preview.amount_kobo / 100).toLocaleString("en-NG", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
})}`;
function submit(): void {
    form.post(store.url(props.preview.reversal_id));
}
</script>

<template>
    <div class="mx-auto w-full max-w-3xl space-y-6">
        <Head title="Add held payment to wallet" />
        <PageHeader
            title="Add held payment to wallet"
            description="The plan this payment was meant for is no longer active, so it can't be re-recorded there."
        />

        <Card>
            <CardHeader><CardTitle>What happens</CardTitle></CardHeader>
            <CardContent>
                <dl class="grid gap-4 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-muted-foreground">Customer</dt>
                        <dd class="mt-0.5 font-medium">{{ customer.name }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Amount</dt>
                        <dd class="mt-0.5 font-medium">{{ amount }}</dd>
                    </div>
                    <div v-if="preview.plan_id">
                        <dt class="text-muted-foreground">Original plan</dt>
                        <dd class="mt-0.5 font-medium">
                            <Link
                                :href="showPlan.url(preview.plan_id)"
                                class="underline-offset-4 hover:underline"
                                >{{ preview.plan_id }}</Link
                            >
                            <span class="text-muted-foreground capitalize">
                                · {{ preview.plan_status }}</span
                            >
                        </dd>
                    </div>
                </dl>
                <p class="text-muted-foreground mt-5 text-sm">
                    The {{ amount }} held from the corrected payment is added to
                    the customer's wallet. No new cash is recorded; the cash
                    stays in its original collection batch. Once nothing else is
                    blocking it, the original plan closes.
                </p>
            </CardContent>
        </Card>

        <Card>
            <CardHeader><CardTitle>Confirm</CardTitle></CardHeader>
            <CardContent>
                <form class="grid gap-5" @submit.prevent="submit">
                    <div
                        class="bg-muted/40 flex items-start gap-3 rounded-xl p-4"
                    >
                        <Checkbox
                            id="wallet-credit-confirmed"
                            v-model="form.confirmed"
                        />
                        <Label for="wallet-credit-confirmed" class="leading-5"
                            >I have checked the amount and want to add it to the
                            customer's wallet.</Label
                        >
                    </div>
                    <p
                        v-for="(error, key) in form.errors"
                        :key="key"
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        {{ error }}
                    </p>
                    <div
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <Button as-child variant="ghost"
                            ><Link :href="showReversal.url(preview.reversal_id)"
                                ><ArrowLeft class="size-4" />Back to
                                correction</Link
                            ></Button
                        >
                        <Button
                            type="submit"
                            :disabled="form.processing || !form.confirmed"
                            >{{
                                form.processing ? "Saving…" : "Add to wallet"
                            }}</Button
                        >
                    </div>
                </form>
            </CardContent>
        </Card>
    </div>
</template>
