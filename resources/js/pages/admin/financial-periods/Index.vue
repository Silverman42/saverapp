<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { close, index, open, reopen } from '@/routes/admin/financial-periods';

type Period = { month: string; timezone: string; status: string; version: number };
const props = defineProps<{
    timezone: string;
    current_month: string;
    periods: { data: Period[]; prev_page_url: string | null; next_page_url: string | null };
}>();
defineOptions({ layout: { breadcrumbs: [
    { title: 'Dashboard', href: dashboard() },
    { title: 'Cash receipt months', href: index() },
] } });

const opening = useForm({ month: props.current_month, reason: '' });
const transition = useForm({ version: 0, reason: '' });
const selected = ref<{ month: string; action: 'close' | 'reopen' } | null>(null);

function select(period: Period): void {
    selected.value = { month: period.month, action: period.status === 'open' ? 'close' : 'reopen' };
    transition.version = period.version;
    transition.reason = '';
    transition.clearErrors();
}

function submitTransition(): void {
    if (!selected.value) return;
    const target = selected.value.action === 'close' ? close.url(selected.value.month) : reopen.url(selected.value.month);
    transition.post(target, { onSuccess: () => { selected.value = null; transition.reset(); } });
}
</script>

<template>
    <Head title="Cash receipt months" />
    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">Cash receipt months</h1>
            <p class="text-muted-foreground mt-1.5 text-sm">Explicit booking periods in {{ timezone }}. A missing or closed month blocks cash receipts.</p>
        </div>
        <Card>
            <CardHeader><CardTitle>Open a month</CardTitle></CardHeader>
            <CardContent><form class="grid max-w-lg gap-4" @submit.prevent="opening.post(open.url(), { onSuccess: () => { opening.reason = ''; } })">
                <div class="grid gap-2"><Label for="open-month">Calendar month</Label><Input id="open-month" v-model="opening.month" type="month" :aria-invalid="!!opening.errors.month" /><p v-if="opening.errors.month" role="alert" class="text-destructive text-sm">{{ opening.errors.month }}</p></div>
                <div class="grid gap-2"><Label for="open-reason">Reason</Label><Input id="open-reason" v-model="opening.reason" maxlength="500" :aria-invalid="!!opening.errors.reason" /><p v-if="opening.errors.reason" role="alert" class="text-destructive text-sm">{{ opening.errors.reason }}</p></div>
                <Button type="submit" class="w-fit" :disabled="opening.processing || !opening.month || !opening.reason.trim()">Open month</Button>
            </form></CardContent>
        </Card>
        <Card>
            <CardHeader><CardTitle>Recorded months</CardTitle></CardHeader>
            <CardContent class="grid gap-4">
                <p v-if="periods.data.length === 0" class="text-muted-foreground text-sm">No month has been opened.</p>
                <ul v-else class="divide-y" aria-live="polite">
                    <li v-for="period in periods.data" :key="`${period.timezone}-${period.month}`" class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                        <span>{{ period.month }} · {{ period.timezone }} · <strong class="capitalize">{{ period.status }}</strong></span>
                        <Button type="button" variant="outline" :disabled="transition.processing" @click="select(period)">{{ period.status === 'open' ? 'Close' : 'Reopen' }}</Button>
                    </li>
                </ul>
                <div class="flex gap-4 text-sm"><Link v-if="periods.prev_page_url" :href="periods.prev_page_url" class="underline">Previous</Link><Link v-if="periods.next_page_url" :href="periods.next_page_url" class="underline">Next</Link></div>
            </CardContent>
        </Card>
        <Card v-if="selected">
            <CardHeader><CardTitle>{{ selected.action === 'close' ? 'Close' : 'Reopen' }} {{ selected.month }}</CardTitle></CardHeader>
            <CardContent><form class="grid max-w-lg gap-4" @submit.prevent="submitTransition">
                <p class="text-muted-foreground text-sm">Closing requires an ended month with every cash batch reconciled and no unresolved exception. Reopening does not extend the receipt lookback.</p>
                <div class="grid gap-2"><Label for="transition-reason">Reason</Label><Input id="transition-reason" v-model="transition.reason" maxlength="500" :aria-invalid="!!transition.errors.reason" /><p v-if="transition.errors.reason" role="alert" class="text-destructive text-sm">{{ transition.errors.reason }}</p></div>
                <div class="flex flex-wrap gap-3"><Button type="submit" :disabled="transition.processing || !transition.reason.trim()">Confirm {{ selected.action }}</Button><Button type="button" variant="outline" :disabled="transition.processing" @click="selected = null">Cancel</Button></div>
            </form></CardContent>
        </Card>
    </div>
</template>
