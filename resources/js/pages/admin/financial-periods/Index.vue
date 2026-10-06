<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import { CalendarDays, Plus } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import FormSheet from '@/components/FormSheet.vue';
import MoreDetails from '@/components/MoreDetails.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
            { title: 'Booking months', href: index() },
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
const openSheetOpen = ref(false);
const transitionDialogOpen = computed(
    () => selected.value !== null && !requiresReload.value,
);

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
    openSheetOpen.value = false;
    requiresReload.value = true;
    unknownOutcome.value = status !== 403 && status !== 404 && status !== 409;
    notice.value =
        status === 409
            ? 'This month has changed, or this action is not allowed right now. Reload to see the latest before trying again.'
            : status === 403 || status === 404
              ? 'This was not allowed. The month may be gone or your access has changed. Reload to check.'
              : 'We could not confirm if this worked. Reload to check the month before trying again.';
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
            openSheetOpen.value = false;
            notice.value = 'Month opened.';
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
                | { data?: Period[] }
                | undefined;
            if (
                page.component !== 'admin/financial-periods/Index' ||
                !Array.isArray(currentPeriods?.data) ||
                page.props.timezone !== failedTimezone.value
            ) {
                notice.value =
                    'We could not load the latest months. Please reload again.';
                return;
            }
            const current = currentPeriods.data.find(
                (period) =>
                    period.month === failedMonth.value &&
                    period.timezone === failedTimezone.value,
            );
            if (unknownOutcome.value && !current) {
                notice.value =
                    'We still cannot see that month in the list, so we do not know if it worked. Please reload again.';
                return;
            }
            requiresReload.value = false;
            unknownOutcome.value = false;
            opening.clearErrors();
            transition.clearErrors();
            selected.value = null;
            notice.value = current
                ? `Updated. ${current.month} is ${current.status}.`
                : 'Updated. Check the months below before you continue.';
        },
        onError: () => {
            notice.value =
                'We could not load the latest months. Please reload again.';
        },
        onHttpException: (response) => {
            notice.value =
                response.status === 403 || response.status === 404
                    ? 'This page is not available, or your access has changed.'
                    : 'We could not load the latest months. Please reload again.';
            return false;
        },
        onNetworkError: () => {
            notice.value =
                'Connection failed. Reload again when you are back online.';
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
                ? 'This month changed while you were looking at it. Reload to see the latest.'
                : '';
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
    <Head title="Booking months" />
    <div class="flex flex-col gap-6">
        <PageHeader
            title="Booking months"
            description="Cash can only be recorded in an open month."
        >
            <template #actions>
                <Button :disabled="locked" @click="openSheetOpen = true">
                    <Plus class="size-4" /> Open month
                </Button>
            </template>
        </PageHeader>
        <div
            v-if="notice"
            ref="periodNotice"
            role="alert"
            tabindex="-1"
            aria-live="assertive"
            aria-atomic="true"
            class="bg-muted flex flex-wrap items-center justify-between gap-3 rounded-xl p-4 text-sm"
        >
            <p>{{ notice }}</p>
            <Button
                v-if="requiresReload"
                type="button"
                variant="outline"
                size="sm"
                :disabled="busy"
                @click="reloadPeriods"
                >{{ reloading ? 'Reloading…' : 'Reload' }}</Button
            >
        </div>
        <p
            v-if="busy"
            role="status"
            aria-live="polite"
            class="text-muted-foreground text-sm"
        >
            {{ reloading ? 'Checking…' : 'Saving…' }}
        </p>

        <Card>
            <CardContent>
                <EmptyState
                    v-if="periods.data.length === 0"
                    :icon="CalendarDays"
                    title="No months yet"
                    description="Open a month so agents can record cash in it."
                >
                    <Button
                        variant="outline"
                        :disabled="locked"
                        @click="openSheetOpen = true"
                        >Open month</Button
                    >
                </EmptyState>
                <ul v-else class="divide-y" aria-live="polite">
                    <li
                        v-for="period in periods.data"
                        :key="`${period.timezone}-${period.month}`"
                        class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
                    >
                        <div class="flex items-center gap-3">
                            <span class="font-medium">{{ period.month }}</span>
                            <Badge
                                :variant="
                                    period.status === 'open'
                                        ? 'default'
                                        : 'secondary'
                                "
                                class="capitalize"
                                >{{ period.status }}</Badge
                            >
                        </div>
                        <Button
                            :id="`period-action-${period.timezone}-${period.month}`"
                            type="button"
                            size="sm"
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
                <div
                    v-if="
                        (periods.prev_page_url || periods.next_page_url) &&
                        !locked
                    "
                    class="mt-4 flex gap-4 text-sm"
                >
                    <Link
                        v-if="periods.prev_page_url"
                        :href="periods.prev_page_url"
                        class="underline"
                        >Previous</Link
                    ><Link
                        v-if="periods.next_page_url"
                        :href="periods.next_page_url"
                        class="underline"
                        >Next</Link
                    >
                </div>
            </CardContent>
        </Card>

        <MoreDetails>
            <p class="text-muted-foreground text-xs leading-5">
                Months follow the {{ timezone }} time zone. A month can only be
                closed after it ends, when all cash batches are matched and all
                issues are fixed. Reopening a month does not give more time to
                record old receipts.
            </p>
        </MoreDetails>

        <FormSheet
            v-model:open="openSheetOpen"
            title="Open a month"
            description="Agents can record cash once the month is open."
        >
            <form
                id="open-month-form"
                class="grid gap-4"
                @submit.prevent="submitOpening"
            >
                <div class="grid gap-2">
                    <Label for="open-month">Month</Label
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
            </form>
            <template #footer>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="busy"
                    @click="openSheetOpen = false"
                    >Cancel</Button
                >
                <Button
                    type="submit"
                    form="open-month-form"
                    :disabled="
                        locked || !opening.month || !opening.reason.trim()
                    "
                    >Open month</Button
                >
            </template>
        </FormSheet>

        <Dialog
            :open="transitionDialogOpen"
            @update:open="if (!$event) cancelReview();"
        >
            <DialogContent
                class="sm:max-w-md"
                :show-close-button="!locked"
                @escape-key-down="locked && $event.preventDefault()"
                @interact-outside="locked && $event.preventDefault()"
            >
                <DialogHeader>
                    <DialogTitle
                        >{{ selected?.action === 'close' ? 'Close' : 'Reopen' }}
                        {{ selected?.month }}?</DialogTitle
                    >
                    <DialogDescription>
                        {{
                            selected?.action === 'close'
                                ? 'Agents will not be able to record cash in this month.'
                                : 'Agents will be able to record cash in this month again.'
                        }}
                    </DialogDescription>
                </DialogHeader>
                <form class="grid gap-4" @submit.prevent="submitTransition">
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
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="locked"
                            @click="cancelReview"
                            >Cancel</Button
                        ><Button
                            type="submit"
                            :disabled="locked || !transition.reason.trim()"
                            :aria-describedby="
                                transition.errors.version
                                    ? 'transition-version-error'
                                    : undefined
                            "
                            >{{
                                selected?.action === 'close'
                                    ? 'Close month'
                                    : 'Reopen month'
                            }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
