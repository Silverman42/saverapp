<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { RefreshCw, ScrollText, Search, SlidersHorizontal } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import FormSheet from '@/components/FormSheet.vue';
import MoreDetails from '@/components/MoreDetails.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { DatePicker } from '@/components/ui/date-picker';
import { useProtectedWorkspace } from '@/composables/useProtectedWorkspace';
import { dashboard } from '@/routes';
import { index, show } from '@/routes/admin/audit';
import type { AuditResult } from '@/types/audit';
const page = usePage();
const dateRangeError = computed(
    () => page.props.errors.from ?? page.props.errors.to,
);
const props = defineProps<{
    audit: AuditResult;
    filters: Record<string, string>;
    scope: string;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Activity log', href: index() },
        ],
    },
});
const { visible, notice, refresh, clear } = useProtectedWorkspace(
    () => props.scope,
    'audit.view',
);
const filters = ref<Record<string, string>>({
    per_page: '25',
    outcome: '',
    ...props.filters,
});
const mainFields: { key: string; label: string }[] = [
    { key: 'event_type', label: 'Event type' },
    { key: 'category', label: 'Category' },
    { key: 'severity', label: 'Severity' },
    { key: 'target_reference', label: 'Record reference' },
];
const moreFields: { key: string; label: string }[] = [
    { key: 'actor_id', label: 'Person ID' },
    { key: 'actor_type', label: 'Person type' },
    { key: 'target_type', label: 'Record type' },
    { key: 'source_module', label: 'App area' },
    { key: 'required_permission', label: 'Permission needed' },
    { key: 'correlation_reference', label: 'Linked reference' },
    { key: 'retention_class', label: 'Retention group' },
];
const sheetFields = [...mainFields, ...moreFields].map((field) => field.key);
const filtersOpen = ref(false);
const activeFilterCount = computed(
    () =>
        [...sheetFields, 'outcome'].filter((key) => filters.value[key]).length +
        (String(filters.value.per_page) !== '25' ? 1 : 0),
);
const reference = ref('');
const lookupOpen = ref(false);
const lookupError = ref('');
function search(cursor?: string): void {
    router.get(
        index.url({ query: { ...filters.value, cursor } }),
        {},
        { onHttpException: clear, onNetworkError: clear },
    );
}
function applyFromSheet(): void {
    filtersOpen.value = false;
    search();
}
function clearSheetFilters(): void {
    for (const key of sheetFields) {
        filters.value[key] = '';
    }
    filters.value.outcome = '';
    filters.value.per_page = '25';
    filtersOpen.value = false;
    search();
}
function lookup(): void {
    if (/^[0-9A-HJKMNP-TV-Z]{26}$/.test(reference.value.toUpperCase())) {
        lookupError.value = '';
        router.visit(show.url(reference.value.toUpperCase()));
        return;
    }
    lookupError.value =
        'That reference does not look right. It should be 26 letters and numbers.';
}
</script>
<template>
    <div class="space-y-6">
        <Head title="Activity log" />
        <PageHeader
            title="Activity log"
            description="See who did what, and when."
        >
            <template #actions>
                <Button
                    v-if="visible"
                    variant="outline"
                    @click="lookupOpen = true"
                >
                    <Search class="size-4" />
                    Find event
                </Button>
                <Button variant="outline" @click="refresh">
                    <RefreshCw class="size-4" />
                    Refresh
                </Button>
            </template>
        </PageHeader>
        <p v-if="notice" role="alert" class="bg-muted rounded-xl p-4 text-sm">
            {{ notice }}
        </p>
        <template v-if="visible">
            <p
                v-if="audit.health.status !== 'current'"
                class="bg-muted rounded-xl p-4 text-sm"
                role="status"
            >
                Some recent events may be missing while the log updates. If you
                have an event reference, use Find event.
            </p>
            <form
                class="flex flex-row flex-wrap items-end gap-4"
                aria-label="Activity filters"
                @submit.prevent="search()"
            >
                <div class="w-fit space-y-1.5">
                    <Label for="audit-from">From</Label
                    ><DatePicker
                        id="audit-from"
                        :error-message="dateRangeError"
                        v-model="filters.from"
                        aria-label="From date"
                    />
                </div>
                <div class="w-fit space-y-1.5">
                    <Label for="audit-to">To</Label
                    ><DatePicker
                        id="audit-to"
                        :error-message="dateRangeError"
                        v-model="filters.to"
                        aria-label="To date"
                    />
                </div>
                <Button type="submit">Show</Button>
                <Button
                    type="button"
                    variant="outline"
                    @click="filtersOpen = true"
                >
                    <SlidersHorizontal class="size-4" />
                    Filters
                    <span
                        v-if="activeFilterCount > 0"
                        class="bg-primary text-primary-foreground inline-flex size-5 items-center justify-center rounded-full text-[11px]"
                        >{{ activeFilterCount }}</span
                    >
                </Button>
                <InputError class="basis-full" :message="dateRangeError" />
            </form>

            <FormSheet
                v-model:open="filtersOpen"
                title="Filters"
                description="Narrow down the events you see."
            >
                <div class="grid gap-5">
                    <div class="grid gap-2">
                        <Label for="audit-outcome">Result</Label
                        ><Select
                            :model-value="filters.outcome || '__all'"
                            @update:model-value="
                                filters.outcome =
                                    $event === '__all' ? '' : String($event)
                            "
                        >
                            <SelectTrigger id="audit-outcome" class="w-full"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__all"
                                    >All results</SelectItem
                                >
                                <SelectItem
                                    v-for="value in [
                                        'Succeeded',
                                        'Denied',
                                        'Failed',
                                        'Conflict',
                                        'Expired',
                                    ]"
                                    :key="value"
                                    :value="value"
                                >
                                    {{ value }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div
                        v-for="field in mainFields"
                        :key="field.key"
                        class="grid gap-2"
                    >
                        <Label :for="'audit-' + field.key">{{
                            field.label
                        }}</Label
                        ><Input
                            :id="'audit-' + field.key"
                            v-model="filters[field.key]"
                        />
                    </div>
                    <MoreDetails label="More filters">
                        <div class="grid gap-5">
                            <div
                                v-for="field in moreFields"
                                :key="field.key"
                                class="grid gap-2"
                            >
                                <Label :for="'audit-' + field.key">{{
                                    field.label
                                }}</Label
                                ><Input
                                    :id="'audit-' + field.key"
                                    v-model="filters[field.key]"
                                />
                            </div>
                        </div>
                    </MoreDetails>
                    <div class="grid gap-2">
                        <Label for="audit-size">Rows per page</Label
                        ><Select
                            :model-value="String(filters.per_page)"
                            @update:model-value="
                                filters.per_page = String($event)
                            "
                        >
                            <SelectTrigger id="audit-size" class="w-full"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="size in [25, 50, 100]"
                                    :key="size"
                                    :value="String(size)"
                                >
                                    {{ size }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>
                <template #footer>
                    <Button
                        type="button"
                        variant="outline"
                        @click="clearSheetFilters"
                        >Clear</Button
                    >
                    <Button type="button" @click="applyFromSheet"
                        >Show results</Button
                    >
                </template>
            </FormSheet>

            <EmptyState
                v-if="!audit.rows.length"
                :icon="ScrollText"
                title="No events found"
                description="Try different dates or clear the filters. By default you see the last 24 hours."
            />
            <div v-else class="overflow-x-auto rounded-xl border">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">
                        Activity log results
                    </caption>
                    <thead class="text-muted-foreground text-xs">
                        <tr>
                            <th scope="col" class="p-3 font-medium">When</th>
                            <th scope="col" class="p-3 font-medium">Event</th>
                            <th scope="col" class="p-3 font-medium">Who</th>
                            <th scope="col" class="p-3 font-medium">Record</th>
                            <th scope="col" class="p-3 font-medium">Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="event in audit.rows"
                            :key="event.event_id"
                            class="border-t"
                        >
                            <td class="p-3 whitespace-nowrap">
                                {{ event.recorded_at }}
                            </td>
                            <th scope="row" class="p-3 font-normal">
                                <Link
                                    :href="show(event.event_id)"
                                    class="font-medium underline-offset-4 hover:underline"
                                    >{{ event.event_type }}</Link
                                >
                                <p class="text-muted-foreground mt-0.5 text-xs">
                                    {{ event.category }}
                                </p>
                                <Badge
                                    v-if="event.legacy_evidence"
                                    variant="secondary"
                                    class="mt-1"
                                    >Older record</Badge
                                >
                            </th>
                            <td class="p-3">
                                {{ event.actor_type }}
                                {{ event.actor_id ? '#' + event.actor_id : '' }}
                            </td>
                            <td class="p-3">
                                {{ event.target_reference || '-' }}
                            </td>
                            <td class="p-3">
                                <Badge variant="outline">{{
                                    event.outcome
                                }}</Badge>
                                <p class="text-muted-foreground mt-1 text-xs">
                                    {{ event.severity }}
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Button
                v-if="audit.next_cursor"
                variant="outline"
                @click="search(audit.next_cursor)"
                >More results</Button
            >
            <MoreDetails label="About this log">
                <dl
                    class="text-muted-foreground grid gap-3 text-xs sm:grid-cols-2"
                >
                    <div>
                        <dt class="text-foreground font-medium">Updated</dt>
                        <dd>{{ audit.health.as_of || 'Not yet' }}</dd>
                    </div>
                    <div>
                        <dt class="text-foreground font-medium">Status</dt>
                        <dd>
                            {{ audit.health.status }},
                            {{ audit.health.pending }} waiting
                        </dd>
                    </div>
                    <div>
                        <dt class="text-foreground font-medium">Integrity</dt>
                        <dd>{{ audit.health.integrity }}</dd>
                    </div>
                    <div>
                        <dt class="text-foreground font-medium">Retention</dt>
                        <dd>{{ audit.health.retention }}</dd>
                    </div>
                    <p class="sm:col-span-2">
                        Dates and times are in UTC. Private values are hidden.
                    </p>
                </dl>
            </MoreDetails>

            <Dialog v-model:open="lookupOpen">
                <DialogContent class="sm:max-w-md">
                    <form class="space-y-5" @submit.prevent="lookup">
                        <DialogHeader>
                            <DialogTitle>Find event</DialogTitle>
                            <DialogDescription>
                                Enter the event reference to open it.
                            </DialogDescription>
                        </DialogHeader>
                        <div class="grid gap-2">
                            <Label for="audit-reference">Event reference</Label
                            ><Input
                                id="audit-reference"
                                v-model="reference"
                                maxlength="26"
                                required
                                autocomplete="off"
                                :aria-invalid="lookupError ? true : undefined"
                            />
                            <p
                                v-if="lookupError"
                                role="alert"
                                class="text-destructive text-xs"
                            >
                                {{ lookupError }}
                            </p>
                        </div>
                        <DialogFooter class="gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                @click="lookupOpen = false"
                                >Cancel</Button
                            >
                            <Button type="submit">Open event</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </template>
    </div>
</template>
