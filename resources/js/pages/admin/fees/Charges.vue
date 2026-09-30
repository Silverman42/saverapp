<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { index, publish, assess } from '@/routes/admin/charges';

type Category = {
    id: number;
    category_key: string;
    version: number;
    kind: string;
    purpose: string;
    customer_description: string;
    amount_kobo: number;
};
const props = defineProps<{
    categories: Category[];
    can_fees: boolean;
    can_deductions: boolean;
    enabled: boolean;
    customer: { customer_id: string; name: string; version: number } | null;
    plans: { plan_id: string; version: number }[];
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Charges', href: index() },
        ],
    },
});
const customerSearch = ref(props.customer?.customer_id ?? '');
const catalogue = useForm({
    publication_reference: crypto.randomUUID(),
    category_key: '',
    kind: props.can_fees ? 'manual_fee' : 'deduction',
    purpose: '',
    customer_description: '',
    amount_ngn: '',
    confirmed: false,
});
const charge = useForm({
    operation_reference: crypto.randomUUID(),
    customer_id: props.customer?.customer_id ?? '',
    plan_id: '',
    category_id: '',
    customer_version: props.customer?.version ?? 0,
    plan_version: 0,
    reason: '',
    confirmed: false,
});
const selected = computed(() =>
    props.categories.find(
        (category) => String(category.id) === charge.category_id,
    ),
);
function publishCategory(): void {
    catalogue.post(publish.url(), {
        onSuccess: () => {
            catalogue.publication_reference = crypto.randomUUID();
            catalogue.confirmed = false;
        },
    });
}
function submitCharge(): void {
    charge.plan_version =
        props.plans.find((plan) => plan.plan_id === charge.plan_id)?.version ??
        0;
    charge.post(assess.url(), {
        onSuccess: () => {
            charge.operation_reference = crypto.randomUUID();
            charge.confirmed = false;
        },
    });
}
</script>
<template>
    <Head title="Controlled charges" />
    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">
                Controlled charges
            </h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                Publish fixed NGN terms, then confirm a charge for an eligible
                Customer cycle.
            </p>
        </div>
        <p v-if="!enabled" class="text-muted-foreground text-sm">
            Charge posting awaits integrated acceptance. Published categories
            remain available for review.
        </p>
        <Card
            ><CardHeader
                ><CardTitle>Publish a category version</CardTitle></CardHeader
            ><CardContent>
                <form
                    class="grid max-w-xl gap-3"
                    @submit.prevent="publishCategory"
                >
                    <Label for="category-kind">Charge kind</Label
                    ><select
                        id="category-kind"
                        v-model="catalogue.kind"
                        class="border-input rounded-md border p-2"
                    >
                        <option v-if="can_fees" value="manual_fee">
                            Manual fee obligation
                        </option>
                        <option v-if="can_deductions" value="deduction">
                            Savings deduction
                        </option>
                    </select>
                    <Label for="category-key">Category key</Label
                    ><Input
                        id="category-key"
                        v-model="catalogue.category_key"
                        required
                        maxlength="80"
                    />
                    <Label for="category-purpose">Approved purpose</Label
                    ><Input
                        id="category-purpose"
                        v-model="catalogue.purpose"
                        required
                        maxlength="500"
                    />
                    <Label for="category-description"
                        >Customer description</Label
                    ><Input
                        id="category-description"
                        v-model="catalogue.customer_description"
                        required
                        maxlength="500"
                    />
                    <Label for="category-amount">Fixed amount (NGN)</Label
                    ><Input
                        id="category-amount"
                        v-model="catalogue.amount_ngn"
                        required
                        inputmode="decimal"
                    />
                    <p class="text-muted-foreground text-sm">
                        Manual fees recognize income when settled. Deductions
                        use the configured deduction destination.
                    </p>
                    <p
                        v-for="(error, field) in catalogue.errors"
                        :key="field"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ error }}
                    </p>
                    <label class="flex items-center gap-2 text-sm"
                        ><input
                            v-model="catalogue.confirmed"
                            type="checkbox"
                        />Confirm publication of immutable terms</label
                    >
                    <Button
                        type="submit"
                        :disabled="catalogue.processing || !catalogue.confirmed"
                        >Publish version</Button
                    >
                </form>
            </CardContent></Card
        >
        <Card
            ><CardHeader><CardTitle>Assess a charge</CardTitle></CardHeader
            ><CardContent class="grid max-w-xl gap-3">
                <form
                    class="flex gap-2"
                    @submit.prevent="
                        router.get(index.url(), { customer: customerSearch })
                    "
                >
                    <Label for="charge-customer" class="sr-only"
                        >Customer ID</Label
                    ><Input
                        id="charge-customer"
                        v-model="customerSearch"
                        placeholder="Customer ID"
                        required
                    /><Button type="submit" variant="outline"
                        >Find Customer</Button
                    >
                </form>
                <form
                    v-if="customer"
                    class="grid gap-3"
                    @submit.prevent="submitCharge"
                >
                    <p>{{ customer.name }} · {{ customer.customer_id }}</p>
                    <Label for="charge-plan">Cycle</Label
                    ><select
                        id="charge-plan"
                        v-model="charge.plan_id"
                        class="border-input rounded-md border p-2"
                        required
                    >
                        <option value="">Choose cycle</option>
                        <option
                            v-for="plan in plans"
                            :key="plan.plan_id"
                            :value="plan.plan_id"
                        >
                            {{ plan.plan_id }}
                        </option>
                    </select>
                    <Label for="charge-category">Published category</Label
                    ><select
                        id="charge-category"
                        v-model="charge.category_id"
                        class="border-input rounded-md border p-2"
                        required
                    >
                        <option value="">Choose category</option>
                        <option
                            v-for="category in categories"
                            :key="category.id"
                            :value="String(category.id)"
                        >
                            {{ category.category_key }} · version
                            {{ category.version }} · NGN
                            {{ (category.amount_kobo / 100).toFixed(2) }}
                        </option>
                    </select>
                    <p v-if="selected" class="text-sm">
                        {{ selected.customer_description }} ·
                        {{
                            selected.kind === 'manual_fee'
                                ? 'Creates an unpaid obligation'
                                : 'Debits unreserved cycle savings'
                        }}
                    </p>
                    <Label for="charge-reason">Reason for this charge</Label
                    ><Input
                        id="charge-reason"
                        v-model="charge.reason"
                        required
                        maxlength="1000"
                    />
                    <p
                        v-for="(error, field) in charge.errors"
                        :key="field"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ error }}
                    </p>
                    <label class="flex items-center gap-2 text-sm"
                        ><input
                            v-model="charge.confirmed"
                            type="checkbox"
                        />Confirm this Customer, cycle and exact category
                        amount</label
                    >
                    <Button
                        type="submit"
                        :disabled="
                            !enabled || charge.processing || !charge.confirmed
                        "
                        >Confirm charge</Button
                    >
                </form>
            </CardContent></Card
        >
        <Link :href="dashboard()" class="text-primary w-fit text-sm underline"
            >Back to dashboard</Link
        >
    </div>
</template>
