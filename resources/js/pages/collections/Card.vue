<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import FormSheet from '@/components/FormSheet.vue';
import MoreDetails from '@/components/MoreDetails.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import InputError from '@/components/InputError.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dashboard } from '@/routes';
import { create as createCollection } from '@/routes/customers/collections';
import { index as plansIndex, show as showPlan } from '@/routes/plans';
import { store as storeAnnotation } from '@/routes/plans/card/annotations';
import { computed, nextTick, ref } from 'vue';

type Slot = {
    id: number;
    ordinal: number;
    due_date: string;
    target_kobo: number;
    funded_kobo: number;
    remaining_kobo: number;
    status: string;
    advance: boolean;
    annotation_reason: string | null;
    annotation_version: number;
};
type CardData = {
    plan_id: string;
    status: string;
    timezone: string;
    target_kobo: number;
    funded_kobo: number;
    paid_slots: number;
    slot_count: number;
    slots: Slot[];
    position: {
        liability_kobo: number;
        reservations_kobo: number;
        available_kobo: number;
    };
};
const props = defineProps<{
    card: CardData;
    can_record: boolean;
    customer_id: string;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Plans', href: plansIndex() },
            { title: 'Thrift card', href: '#' },
        ],
    },
});
const money = (kobo: number): string =>
    `₦${(kobo / 100).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const annotation = useForm({ version: 0, kind: 'missed', reason: '' });
const selectedSlot = ref<number | null>(null);
const annotationMessage = ref('');
const annotationNotice = ref<HTMLElement | null>(null);
const selectedDay = computed(() =>
    props.card.slots.find((slot) => slot.id === selectedSlot.value),
);
const noteSheetOpen = computed({
    get: () => selectedSlot.value !== null && props.can_record,
    set: (open: boolean) => {
        if (!open && !annotation.processing) {
            selectedSlot.value = null;
        }
    },
});
const fundedPercent = computed(() =>
    props.card.target_kobo > 0
        ? Math.min(
              100,
              Math.round(
                  (props.card.funded_kobo / props.card.target_kobo) * 100,
              ),
          )
        : 0,
);
function selectSlot(slot: Slot): void {
    if (annotation.processing) return;
    selectedSlot.value = slot.id;
    annotation.version = slot.annotation_version;
    annotation.reason = '';
    annotation.clearErrors();
    annotationMessage.value = '';
}
function saveAnnotation(): void {
    if (selectedSlot.value !== null && !annotation.processing) {
        annotationMessage.value = '';
        annotation.post(
            storeAnnotation.url([props.card.plan_id, selectedSlot.value]),
            {
                preserveScroll: true,
                onHttpException: (response) => {
                    annotationMessage.value =
                        response.status === 409
                            ? 'This note was not saved. Future or paid days cannot be marked missed or skipped. Refresh the page and try again.'
                            : 'This note was not saved. Refresh the page and try again.';
                    return false;
                },
                onNetworkError: () => {
                    annotationMessage.value =
                        'We could not confirm the note was saved. Refresh the page to check before trying again.';
                },
                onSuccess: () => {
                    annotationMessage.value = 'Note saved.';
                    selectedSlot.value = null;
                    annotation.resetAndClearErrors();
                },
                onFinish: () => {
                    void nextTick(() => annotationNotice.value?.focus());
                },
            },
        );
    }
}
</script>

<template>
    <Head :title="`Thrift card ${card.plan_id}`" />
    <div class="flex flex-col gap-6">
        <PageHeader title="Thrift card" :description="`Plan ${card.plan_id}`">
            <template #actions>
                <Button variant="outline" as-child
                    ><Link :href="showPlan(card.plan_id)"
                        >View plan</Link
                    ></Button
                >
                <Button
                    v-if="
                        can_record &&
                        card.status === 'active' &&
                        card.funded_kobo < card.target_kobo
                    "
                    as-child
                    ><Link :href="createCollection(customer_id).url"
                        >Record cash</Link
                    ></Button
                >
            </template>
        </PageHeader>

        <Card>
            <CardHeader
                class="flex flex-row flex-wrap items-center justify-between gap-3"
            >
                <CardTitle>Progress</CardTitle>
                <Badge variant="secondary" class="capitalize">{{
                    card.status
                }}</Badge>
            </CardHeader>
            <CardContent class="space-y-5">
                <div>
                    <p class="text-2xl font-semibold">
                        {{ money(card.funded_kobo) }}
                        <span
                            class="text-muted-foreground text-base font-normal"
                            >of {{ money(card.target_kobo) }}</span
                        >
                    </p>
                    <div
                        class="bg-muted mt-3 h-2 overflow-hidden rounded-full"
                        role="progressbar"
                        :aria-valuenow="fundedPercent"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        aria-label="Amount saved"
                    >
                        <div
                            class="bg-primary h-full rounded-full"
                            :style="{ width: `${fundedPercent}%` }"
                        />
                    </div>
                    <p class="text-muted-foreground mt-2 text-sm">
                        {{ card.paid_slots }} of {{ card.slot_count }} days paid
                    </p>
                </div>
                <dl class="grid gap-3 border-t pt-4 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-muted-foreground">Total saved</dt>
                        <dd class="mt-1 font-medium">
                            {{ money(card.position.liability_kobo) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Set aside</dt>
                        <dd class="mt-1 font-medium">
                            {{ money(card.position.reservations_kobo) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Available</dt>
                        <dd class="mt-1 font-medium">
                            {{ money(card.position.available_kobo) }}
                        </dd>
                    </div>
                </dl>
            </CardContent>
        </Card>

        <Card>
            <CardHeader><CardTitle>Days</CardTitle></CardHeader>
            <CardContent>
                <p
                    v-if="annotationMessage && !noteSheetOpen"
                    ref="annotationNotice"
                    role="alert"
                    tabindex="-1"
                    class="bg-muted mb-4 rounded-xl p-3 text-sm"
                >
                    {{ annotationMessage }}
                </p>
                <ol class="divide-y">
                    <li
                        v-for="slot in card.slots"
                        :key="slot.id"
                        class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm first:pt-0 last:pb-0"
                    >
                        <div class="min-w-0">
                            <p class="font-medium">
                                Day {{ slot.ordinal }}
                                <span class="text-muted-foreground font-normal"
                                    >· {{ slot.due_date }}</span
                                >
                            </p>
                            <p class="text-muted-foreground mt-0.5 text-xs">
                                {{ money(slot.funded_kobo) }} of
                                {{ money(slot.target_kobo) }}
                                <span v-if="slot.annotation_reason">
                                    · {{ slot.annotation_reason }}</span
                                >
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge v-if="slot.advance" variant="outline"
                                >Paid early</Badge
                            >
                            <Badge variant="secondary" class="capitalize">{{
                                slot.status
                            }}</Badge>
                            <Button
                                v-if="can_record && slot.remaining_kobo > 0"
                                type="button"
                                variant="ghost"
                                size="sm"
                                :disabled="annotation.processing"
                                @click="selectSlot(slot)"
                                >Add note</Button
                            >
                        </div>
                    </li>
                </ol>
            </CardContent>
        </Card>

        <MoreDetails>
            <p class="text-muted-foreground text-sm">
                Dates use the {{ card.timezone }} time zone.
            </p>
        </MoreDetails>

        <FormSheet
            v-model:open="noteSheetOpen"
            title="Add a note"
            :description="
                selectedDay
                    ? `Day ${selectedDay.ordinal}, ${selectedDay.due_date}`
                    : undefined
            "
        >
            <form
                id="annotation-form"
                class="grid gap-5"
                :aria-busy="annotation.processing"
                @submit.prevent="saveAnnotation"
            >
                <p
                    v-if="annotationMessage && noteSheetOpen"
                    ref="annotationNotice"
                    role="alert"
                    tabindex="-1"
                    class="bg-muted rounded-xl p-3 text-sm"
                >
                    {{ annotationMessage }}
                </p>
                <div class="grid gap-2">
                    <Label for="annotation-kind">What happened?</Label>
                    <Select
                        v-model="annotation.kind"
                        :disabled="annotation.processing"
                    >
                        <SelectTrigger
                            id="annotation-kind"
                            class="w-full"
                            :aria-invalid="Boolean(annotation.errors.kind)"
                            :aria-describedby="
                                annotation.errors.kind
                                    ? 'annotation-kind-error'
                                    : undefined
                            "
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="missed">Missed</SelectItem>
                            <SelectItem value="skipped">Skipped</SelectItem>
                            <SelectItem value="clear">Remove note</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError
                        id="annotation-kind-error"
                        :message="annotation.errors.kind"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="annotation-reason">Reason</Label
                    ><Input
                        id="annotation-reason"
                        v-model="annotation.reason"
                        :disabled="annotation.processing"
                        :maxlength="500"
                        :aria-invalid="Boolean(annotation.errors.reason)"
                        :aria-describedby="
                            annotation.errors.reason
                                ? 'annotation-reason-error'
                                : undefined
                        "
                    />
                    <InputError
                        id="annotation-reason-error"
                        :message="annotation.errors.reason"
                    />
                </div>
                <p
                    v-for="(error, key) in annotation.errors"
                    v-show="key !== 'kind' && key !== 'reason'"
                    :key="key"
                    class="text-destructive text-sm"
                    role="alert"
                >
                    {{ error }}
                </p>
            </form>
            <template #footer>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="annotation.processing"
                    @click="noteSheetOpen = false"
                    >Cancel</Button
                >
                <Button
                    type="submit"
                    form="annotation-form"
                    :disabled="
                        annotation.processing || !annotation.reason.trim()
                    "
                    >Save note</Button
                >
            </template>
        </FormSheet>
    </div>
</template>
