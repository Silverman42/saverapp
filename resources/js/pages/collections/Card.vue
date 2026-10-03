<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
                            ? 'The attendance note was rejected. Future or funded days cannot be marked missed or skipped, and the slot may have changed. Reload the card and review the day before retrying.'
                            : 'The attendance note could not be saved. Reload the card to check your current access and the day before retrying.';
                    return false;
                },
                onNetworkError: () => {
                    annotationMessage.value =
                        'The outcome could not be confirmed. Reload the card to check whether the note was saved before retrying.';
                },
                onSuccess: () => {
                    annotationMessage.value = 'Attendance note saved.';
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
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-[25px] font-medium tracking-tight">
                    Thrift card
                </h1>
                <p class="text-muted-foreground mt-1.5 text-sm">
                    {{ card.plan_id }} · {{ card.status }} · dates in
                    {{ card.timezone }}
                </p>
            </div>
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
        </div>
        <Card
            ><CardHeader><CardTitle>Plan coverage</CardTitle></CardHeader
            ><CardContent class="grid gap-3 sm:grid-cols-3"
                ><div>
                    Scheduled target<br /><strong>{{
                        money(card.target_kobo)
                    }}</strong>
                </div>
                <div>
                    Net funded<br /><strong>{{
                        money(card.funded_kobo)
                    }}</strong>
                </div>
                <div>
                    Paid slots<br /><strong
                        >{{ card.paid_slots }} / {{ card.slot_count }}</strong
                    >
                </div></CardContent
            ></Card
        >
        <Card
            ><CardHeader><CardTitle>Dated slots</CardTitle></CardHeader
            ><CardContent
                ><ol class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <li
                        v-for="slot in card.slots"
                        :key="slot.id"
                        class="rounded-lg border p-3 text-sm"
                    >
                        <strong
                            >Day {{ slot.ordinal }} ·
                            {{ slot.due_date }}</strong
                        >
                        <p class="mt-1 capitalize">
                            {{ slot.status }}
                            <span v-if="slot.advance">· advance funded</span>
                        </p>
                        <p class="text-muted-foreground mt-1">
                            {{ money(slot.funded_kobo) }} of
                            {{ money(slot.target_kobo) }}
                        </p>
                        <p v-if="slot.annotation_reason" class="mt-1">
                            {{ slot.annotation_reason }}
                        </p>
                        <Button
                            v-if="can_record && slot.remaining_kobo > 0"
                            type="button"
                            variant="outline"
                            class="mt-2"
                            :disabled="annotation.processing"
                            @click="selectSlot(slot)"
                            >Annotate day</Button
                        >
                    </li>
                </ol>
                <p
                    v-if="annotationMessage"
                    ref="annotationNotice"
                    role="alert"
                    tabindex="-1"
                    class="mt-5 text-sm"
                >
                    {{ annotationMessage }}
                </p>
                <form
                    v-if="selectedSlot !== null && can_record"
                    class="mt-5 grid max-w-md gap-3"
                    :aria-busy="annotation.processing"
                    @submit.prevent="saveAnnotation"
                >
                    <p v-if="selectedDay" class="text-sm">
                        Day {{ selectedDay.ordinal }} ·
                        {{ selectedDay.due_date }}
                    </p>
                    <Label for="annotation-kind">Attendance note</Label
                    ><select
                        id="annotation-kind"
                        v-model="annotation.kind"
                        :disabled="annotation.processing"
                        :aria-invalid="Boolean(annotation.errors.kind)"
                        :aria-describedby="
                            annotation.errors.kind
                                ? 'annotation-kind-error'
                                : undefined
                        "
                        class="bg-background rounded-md border p-2 text-sm"
                    >
                        <option value="missed">Missed</option>
                        <option value="skipped">Skipped</option>
                        <option value="clear">Clear note</option></select
                    ><InputError
                        id="annotation-kind-error"
                        :message="annotation.errors.kind"
                    />
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
                    /><Button
                        type="submit"
                        class="w-fit"
                        :disabled="
                            annotation.processing || !annotation.reason.trim()
                        "
                        >Save note</Button
                    >
                    <InputError
                        id="annotation-reason-error"
                        :message="annotation.errors.reason"
                    />
                    <p
                        v-for="(error, key) in annotation.errors"
                        v-show="key !== 'kind' && key !== 'reason'"
                        :key="key"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ error }}
                    </p>
                </form></CardContent
            ></Card
        >
        <Card
            ><CardHeader><CardTitle>Lifetime savings</CardTitle></CardHeader
            ><CardContent class="grid gap-3 sm:grid-cols-3"
                ><div>
                    Liability<br /><strong>{{
                        money(card.position.liability_kobo)
                    }}</strong>
                </div>
                <div>
                    Reservations<br /><strong>{{
                        money(card.position.reservations_kobo)
                    }}</strong>
                </div>
                <div>
                    Available<br /><strong>{{
                        money(card.position.available_kobo)
                    }}</strong>
                </div></CardContent
            ></Card
        >
        <Link
            :href="showPlan(card.plan_id)"
            class="text-primary w-fit text-sm underline"
            >Back to plan</Link
        >
    </div>
</template>
