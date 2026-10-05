<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { close, index, open, reopen } from '@/routes/admin/financial-periods';

type Period = {
    month: string;
    timezone: string;
    status: string;
    version: number;
};
const props = defineProps<{
    timezone: string;
    current_month: string;
    periods: {
        data: Period[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Cash receipt months', href: index() },
        ],
    },
});

const opening = useForm({ month: props.current_month, reason: '' });
const transition = useForm({ version: 0, reason: '' });
const selected = ref<{
    month: string;
    timezone: string;
    action: 'close' | 'reopen';
} | null>(null);
const reviewReason = ref<InstanceType<typeof Input> | null>(null);
const periodNotice = ref<HTMLElement | null>(null);
const notice = ref('');
const requiresReload = ref(false);
const reloading = ref(false);
const failedMonth = ref('');
const failedTimezone = ref('');
const unknownOutcome = ref(false);
const busy = computed(
    () => opening.processing || transition.processing || reloading.value,
);
const locked = computed(() => busy.value || requiresReload.value);

function focusNotice(): void {
    if (notice.value) void nextTick(() => periodNotice.value?.focus());
}

function focusMonth(month: string, timezone: string): void {
    void nextTick(() =>
        document.getElementById(`period-action-${timezone}-${month}`)?.focus(),
    );
}

function cancelReview(): void {
    if (locked.value || !selected.value) return;
    const { month, timezone } = selected.value;
    selected.value = null;
    transition.clearErrors();
    focusMonth(month, timezone);
}

function reportFailure(status?: number): void {
    requiresReload.value = true;
    unknownOutcome.value = status !== 403 && status !== 404 && status !== 409;
    notice.value =
        status === 409
            ? 'This month changed or the action is not currently allowed. Reload current periods and review the month before confirming another action.'
            : status === 403 || status === 404
              ? 'This action was rejected because the period is unavailable or your access has changed. Reload to check current access before continuing.'
              : 'The action outcome could not be confirmed. Your submitted instructions are retained. Reload current periods to check the month before confirming another action.';
}

function submitOpening(): void {
    if (locked.value) return;
    notice.value = '';
    failedMonth.value = opening.month;
    failedTimezone.value = props.timezone;
    opening.post(open.url(), {
        preserveScroll: true,
        onSuccess: () => {
            opening.reason = '';
            notice.value = 'The month was opened.';
        },
        onError: () => {
            notice.value = 'The month was not opened. Review the field errors.';
        },
        onHttpException: (response) => {
            reportFailure(response.status);
            return false;
        },
        onNetworkError: () => {
            reportFailure();
            return false;
        },
        onFinish: focusNotice,
    });
}

function reloadPeriods(): void {
    if (busy.value || !requiresReload.value) return;
    reloading.value = true;
    router.reload({
        only: ['periods', 'timezone', 'current_month'],
        onSuccess: (page) => {
            const currentPeriods = page.props.periods as
                { data?: Period[] } | undefined;
            if (
                page.component !== 'admin/financial-periods/Index' ||
                !Array.isArray(currentPeriods?.data) ||
                page.props.timezone !== failedTimezone.value
            ) {
                notice.value =
                    'The current period could not be verified. The previous action remains unverified. Reload again before continuing.';
                return;
            }
            const current = currentPeriods.data.find(
                (period) =>
                    period.month === failedMonth.value &&
                    period.timezone === failedTimezone.value,
            );
            if (unknownOutcome.value && !current) {
                notice.value =
                    'The submitted month is absent from the reloaded list, so its outcome remains unconfirmed. The submitted instructions are retained and another action is blocked.';
                return;
            }
            requiresReload.value = false;
            unknownOutcome.value = false;
            opening.clearErrors();
            transition.clearErrors();
            selected.value = null;
            notice.value = current
                ? `Current periods reloaded. ${current.month} is ${current.status}, version ${current.version}. Review its recorded status before choosing another action.`
                : 'Current periods reloaded. Review the recorded months before choosing another action.';
        },
        onError: () => {
            notice.value =
                'Current periods could not be verified. Reload again before continuing.';
        },
        onHttpException: (response) => {
            notice.value =
                response.status === 403 || response.status === 404
                    ? 'Current periods are unavailable or your access has changed. The previous action remains unverified.'
                    : 'Current periods could not be verified. Reload again before continuing.';
            return false;
        },
        onNetworkError: () => {
            notice.value =
                'Current periods could not be verified because the connection failed. Reload again before continuing.';
            return false;
        },
        onFinish: () => {
            reloading.value = false;
            focusNotice();
        },
    });
}

function select(period: Period): void {
    if (locked.value) return;
    notice.value = '';
    selected.value = {
        month: period.month,
        timezone: period.timezone,
        action: period.status === 'open' ? 'close' : 'reopen',
    };
    transition.version = period.version;
    transition.reason = '';
    transition.clearErrors();
    void nextTick(() => reviewReason.value?.$el.focus());
}

function submitTransition(): void {
    if (locked.value || !selected.value) return;
    notice.value = '';
    failedMonth.value = selected.value.month;
    failedTimezone.value = props.timezone;
    const target =
        selected.value.action === 'close'
            ? close.url(selected.value.month)
            : reopen.url(selected.value.month);
    transition.post(target, {
        preserveScroll: true,
        onSuccess: () => {
            const reviewed = selected.value;
            selected.value = null;
            transition.reset();
            if (reviewed) focusMonth(reviewed.month, reviewed.timezone);
        },
        onError: () => {
            if (transition.errors.version) requiresReload.value = true;
            notice.value = transition.errors.version
                ? 'The reviewed period version is invalid. Reload current periods before choosing another action.'
                : 'The month action was not saved. Review the field errors.';
        },
        onHttpException: (response) => {
            reportFailure(response.status);
            return false;
        },
        onNetworkError: () => {
            reportFailure();
            return false;
        },
        onFinish: focusNotice,
    });
}
</script>

<template>
    <Head title="Cash receipt months" />
    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">
                Cash receipt months
            </h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                Booking periods in {{ timezone }}. You cannot record cash
                receipts for a missing or closed month.
            </p>
        </div>
        <div
            v-if="notice"
            ref="periodNotice"
            role="alert"
            tabindex="-1"
            aria-live="assertive"
            aria-atomic="true"
            class="grid gap-3 rounded-md border p-4 text-sm"
        >
            <p>{{ notice }}</p>
            <Button
                v-if="requiresReload"
                type="button"
                variant="outline"
                class="w-fit"
                :disabled="busy"
                @click="reloadPeriods"
                >{{
                    reloading
                        ? 'Reloading current periods…'
                        : 'Reload current periods'
                }}</Button
            >
        </div>
        <p v-if="busy" role="status" aria-live="polite">
            {{
                reloading
                    ? 'Checking current periods. Wait for the result.'
                    : 'Saving the period action. Wait for the result before continuing.'
            }}
        </p>
        <Card>
            <CardHeader><CardTitle>Open a month</CardTitle></CardHeader>
            <CardContent
                ><form
                    class="grid max-w-lg gap-4"
                    @submit.prevent="submitOpening"
                >
                    <div class="grid gap-2">
                        <Label for="open-month">Calendar month</Label
                        ><Input
                            id="open-month"
                            v-model="opening.month"
                            type="month"
                            :aria-invalid="!!opening.errors.month"
                            :aria-describedby="
                                opening.errors.month
                                    ? 'open-month-error'
                                    : undefined
                            "
                            :disabled="locked"
                        />
                        <p
                            v-if="opening.errors.month"
                            id="open-month-error"
                            role="alert"
                            class="text-destructive text-sm"
                        >
                            {{ opening.errors.month }}
                        </p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="open-reason">Reason</Label
                        ><Input
                            id="open-reason"
                            v-model="opening.reason"
                            maxlength="500"
                            :aria-invalid="!!opening.errors.reason"
                            :aria-describedby="
                                opening.errors.reason
                                    ? 'open-reason-error'
                                    : undefined
                            "
                            :disabled="locked"
                        />
                        <p
                            v-if="opening.errors.reason"
                            id="open-reason-error"
                            role="alert"
                            class="text-destructive text-sm"
                        >
                            {{ opening.errors.reason }}
                        </p>
                    </div>
                    <Button
                        type="submit"
                        class="w-fit"
                        :disabled="
                            locked || !opening.month || !opening.reason.trim()
                        "
                        >Open month</Button
                    >
                </form></CardContent
            >
        </Card>
        <Card>
            <CardHeader><CardTitle>Recorded months</CardTitle></CardHeader>
            <CardContent class="grid gap-4">
                <p
                    v-if="periods.data.length === 0"
                    class="text-muted-foreground text-sm"
                >
                    No month has been opened.
                </p>
                <ul v-else class="divide-y" aria-live="polite">
                    <li
                        v-for="period in periods.data"
                        :key="`${period.timezone}-${period.month}`"
                        class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
                    >
                        <span
                            >{{ period.month }} · {{ period.timezone }} ·
                            <strong class="capitalize">{{
                                period.status
                            }}</strong></span
                        >
                        <Button
                            :id="`period-action-${period.timezone}-${period.month}`"
                            type="button"
                            variant="outline"
                            :disabled="locked"
                            :aria-label="`${period.status === 'open' ? 'Close' : 'Reopen'} ${period.month}`"
                            @click="select(period)"
                            >{{
                                period.status === 'open' ? 'Close' : 'Reopen'
                            }}</Button
                        >
                    </li>
                </ul>
                <div class="flex gap-4 text-sm">
                    <Link
                        v-if="periods.prev_page_url && !locked"
                        :href="periods.prev_page_url"
                        class="underline"
                        >Previous</Link
                    ><Link
                        v-if="periods.next_page_url && !locked"
                        :href="periods.next_page_url"
                        class="underline"
                        >Next</Link
                    >
                </div>
            </CardContent>
        </Card>
        <Card v-if="selected">
            <CardHeader
                ><CardTitle
                    >{{ selected.action === 'close' ? 'Close' : 'Reopen' }}
                    {{ selected.month }}</CardTitle
                ></CardHeader
            >
            <CardContent
                ><form
                    class="grid max-w-lg gap-4"
                    @submit.prevent="submitTransition"
                >
                    <p class="text-muted-foreground text-sm">
                        You can close only a month that has ended. All cash
                        batches must be reconciled and all exceptions resolved.
                        Reopening does not extend the receipt lookback period.
                    </p>
                    <div class="grid gap-2">
                        <Label for="transition-reason">Reason</Label
                        ><Input
                            id="transition-reason"
                            ref="reviewReason"
                            v-model="transition.reason"
                            maxlength="500"
                            :aria-invalid="!!transition.errors.reason"
                            :aria-describedby="
                                transition.errors.reason
                                    ? 'transition-reason-error'
                                    : undefined
                            "
                            :disabled="locked"
                        />
                        <p
                            v-if="transition.errors.reason"
                            id="transition-reason-error"
                            role="alert"
                            class="text-destructive text-sm"
                        >
                            {{ transition.errors.reason }}
                        </p>
                    </div>
                    <p
                        v-if="transition.errors.version"
                        id="transition-version-error"
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        {{ transition.errors.version }}
                    </p>
                    <div class="flex flex-wrap gap-3">
                        <Button
                            type="submit"
                            :disabled="locked || !transition.reason.trim()"
                            :aria-describedby="
                                transition.errors.version
                                    ? 'transition-version-error'
                                    : undefined
                            "
                            >Confirm {{ selected.action }}</Button
                        ><Button
                            type="button"
                            variant="outline"
                            :disabled="locked"
                            @click="cancelReview"
                            >Cancel</Button
                        >
                    </div>
                </form></CardContent
            >
        </Card>
    </div>
</template>
