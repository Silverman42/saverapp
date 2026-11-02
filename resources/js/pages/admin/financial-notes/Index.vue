<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { NotebookPen, Plus } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import FormSheet from '@/components/FormSheet.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { DatePicker } from '@/components/ui/date-picker';
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
import { dashboard } from '@/routes';
import {
    archive,
    index,
    restore,
    store,
    update,
} from '@/routes/admin/financial-notes';

type NoteType = 'credit' | 'debit';
type Note = {
    reference: string;
    type: NoteType;
    type_label: string;
    title: string;
    amount_ngn: string;
    amount_display: string;
    note_date: string;
    category: string | null;
    body: string;
    status: 'active' | 'archived';
    version: number;
    updated_by: string | null;
    updated_at: string | null;
};
type Filters = {
    search?: string | null;
    type?: string | null;
    category?: string | null;
    from?: string | null;
    to?: string | null;
    status: 'active' | 'archived';
};

const props = defineProps<{
    notes: {
        data: Note[];
        prev_page_url: string | null;
        next_page_url: string | null;
        total: number;
    };
    filters: Filters;
    categories: string[];
    summary: {
        credit: string;
        debit: string;
        net: string;
        net_is_negative: boolean;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Financial notes', href: index() },
        ],
    },
});

const ALL = 'all';
const filterForm = reactive({
    search: props.filters.search ?? '',
    type: props.filters.type ?? ALL,
    category: props.filters.category ?? ALL,
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    status: props.filters.status,
});

function applyFilters(): void {
    const query: Record<string, string> = {};
    if (filterForm.search.trim()) query.search = filterForm.search.trim();
    if (filterForm.type !== ALL) query.type = filterForm.type;
    if (filterForm.category !== ALL) query.category = filterForm.category;
    if (filterForm.from) query.from = filterForm.from;
    if (filterForm.to) query.to = filterForm.to;
    if (filterForm.status !== 'active') query.status = filterForm.status;
    router.get(
        index.url({ query }),
        {},
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

let searchTimer: ReturnType<typeof setTimeout> | undefined;
watch(
    () => filterForm.search,
    () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(applyFilters, 350);
    },
);
watch(
    () => [
        filterForm.type,
        filterForm.category,
        filterForm.from,
        filterForm.to,
        filterForm.status,
    ],
    applyFilters,
);

const hasFilters = computed(
    () =>
        filterForm.search !== '' ||
        filterForm.type !== ALL ||
        filterForm.category !== ALL ||
        filterForm.from !== '' ||
        filterForm.to !== '',
);
const viewingArchived = computed(() => filterForm.status === 'archived');

function resetFilters(): void {
    Object.assign(filterForm, {
        search: '',
        type: ALL,
        category: ALL,
        from: '',
        to: '',
    });
}

function today(): string {
    const now = new Date();
    const offset = now.getTimezoneOffset() * 60_000;
    return new Date(now.getTime() - offset).toISOString().slice(0, 10);
}

const sheetOpen = ref(false);
const editing = ref<Note | null>(null);
const form = useForm({
    type: 'debit' as NoteType,
    title: '',
    amount_ngn: '',
    note_date: today(),
    category: '',
    body: '',
    version: 0,
});

function openCreate(): void {
    editing.value = null;
    form.defaults({
        type: 'debit',
        title: '',
        amount_ngn: '',
        note_date: today(),
        category: '',
        body: '',
        version: 0,
    });
    form.reset();
    form.clearErrors();
    sheetOpen.value = true;
}

function openEdit(note: Note): void {
    editing.value = note;
    form.defaults({
        type: note.type,
        title: note.title,
        amount_ngn: note.amount_ngn,
        note_date: note.note_date,
        category: note.category ?? '',
        body: note.body,
        version: note.version,
    });
    form.reset();
    form.clearErrors();
    sheetOpen.value = true;
}

function submitNote(): void {
    if (form.processing) return;
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            sheetOpen.value = false;
        },
    };
    if (editing.value) {
        form.put(update.url(editing.value.reference), options);
    } else {
        form.transform(({ version: _version, ...data }) => data).post(
            store.url(),
            options,
        );
    }
}

const confirming = ref<Note | null>(null);
const statusForm = useForm({ version: 0 });

function askStatusChange(note: Note): void {
    confirming.value = note;
    statusForm.version = note.version;
}

function submitStatusChange(): void {
    const note = confirming.value;
    if (!note || statusForm.processing) return;
    const target =
        note.status === 'active'
            ? archive.url(note.reference)
            : restore.url(note.reference);
    statusForm.post(target, {
        preserveScroll: true,
        onFinish: () => {
            confirming.value = null;
        },
    });
}

function formatDate(value: string): string {
    return new Date(`${value}T00:00:00`).toLocaleDateString('en-NG', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

const textareaClass =
    'border-input bg-card placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/20 aria-invalid:border-destructive min-h-32 w-full rounded-xl border px-3.5 py-2.5 text-base outline-none focus-visible:ring-[3px] md:text-sm';
</script>

<template>
    <Head title="Financial notes" />
    <div class="flex flex-col gap-6">
        <PageHeader
            title="Financial notes"
            description="Private notes about money the business spends and receives. They do not change any balance or report."
        >
            <template #actions>
                <Button @click="openCreate">
                    <Plus class="size-4" /> Add note
                </Button>
            </template>
        </PageHeader>

        <div class="grid gap-4 sm:grid-cols-3">
            <Card>
                <CardContent>
                    <p class="text-muted-foreground text-sm">Credits</p>
                    <p class="mt-1 text-xl font-medium tabular-nums">
                        {{ summary.credit }}
                    </p>
                </CardContent>
            </Card>
            <Card>
                <CardContent>
                    <p class="text-muted-foreground text-sm">Debits</p>
                    <p class="mt-1 text-xl font-medium tabular-nums">
                        {{ summary.debit }}
                    </p>
                </CardContent>
            </Card>
            <Card>
                <CardContent>
                    <p class="text-muted-foreground text-sm">Net</p>
                    <p
                        class="mt-1 text-xl font-medium tabular-nums"
                        :class="
                            summary.net_is_negative ? 'text-destructive' : ''
                        "
                    >
                        {{ summary.net }}
                    </p>
                </CardContent>
            </Card>
        </div>
        <p class="text-muted-foreground -mt-3 text-xs">
            Totals cover the {{ viewingArchived ? 'archived' : 'active' }}
            notes that match your filters.
        </p>

        <div class="flex flex-row flex-wrap gap-4">
            <div class="grid w-fit gap-1.5">
                <Label for="note-search">Search</Label>
                <Input
                    id="note-search"
                    v-model="filterForm.search"
                    type="search"
                    placeholder="Title or category"
                    maxlength="100"
                />
            </div>
            <div class="grid w-fit gap-1.5">
                <Label for="note-type-filter">Type</Label>
                <Select v-model="filterForm.type">
                    <SelectTrigger id="note-type-filter" class="h-11 w-36"
                        ><SelectValue
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL">All types</SelectItem>
                        <SelectItem value="credit">Credit</SelectItem>
                        <SelectItem value="debit">Debit</SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <div class="grid w-fit gap-1.5">
                <Label for="note-category-filter">Category</Label>
                <Select v-model="filterForm.category">
                    <SelectTrigger id="note-category-filter" class="h-11 w-44"
                        ><SelectValue
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL">All categories</SelectItem>
                        <SelectItem
                            v-for="category in categories"
                            :key="category"
                            :value="category"
                            >{{ category }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>
            <div class="grid w-fit gap-1.5">
                <Label for="note-from">From</Label>
                <DatePicker
                    id="note-from"
                    v-model="filterForm.from"
                    aria-label="From date"
                />
            </div>
            <div class="grid w-fit gap-1.5">
                <Label for="note-to">To</Label>
                <DatePicker
                    id="note-to"
                    v-model="filterForm.to"
                    aria-label="To date"
                />
            </div>
            <div class="grid w-fit gap-1.5">
                <Label for="note-status-filter">Show</Label>
                <Select v-model="filterForm.status">
                    <SelectTrigger id="note-status-filter" class="h-11 w-36"
                        ><SelectValue
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="active">Active</SelectItem>
                        <SelectItem value="archived">Archived</SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <div v-if="hasFilters" class="flex w-fit items-end">
                <Button variant="ghost" class="h-11" @click="resetFilters"
                    >Clear filters</Button
                >
            </div>
        </div>

        <Card>
            <CardContent>
                <EmptyState
                    v-if="notes.data.length === 0"
                    :icon="NotebookPen"
                    :title="
                        hasFilters
                            ? 'No notes match these filters'
                            : viewingArchived
                              ? 'No archived notes'
                              : 'No notes yet'
                    "
                    :description="
                        hasFilters
                            ? 'Try different filters.'
                            : viewingArchived
                              ? 'Notes you archive show up here.'
                              : 'Add a note to keep track of an expense or income.'
                    "
                >
                    <Button
                        v-if="!hasFilters && !viewingArchived"
                        variant="outline"
                        @click="openCreate"
                        >Add note</Button
                    >
                </EmptyState>
                <ul v-else class="divide-y">
                    <li
                        v-for="note in notes.data"
                        :key="note.reference"
                        class="flex flex-col gap-3 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium break-words">{{
                                    note.title
                                }}</span>
                                <Badge
                                    :variant="
                                        note.type === 'credit'
                                            ? 'default'
                                            : 'secondary'
                                    "
                                    >{{ note.type_label }}</Badge
                                >
                                <Badge v-if="note.category" variant="outline">{{
                                    note.category
                                }}</Badge>
                            </div>
                            <p
                                class="text-muted-foreground mt-1 line-clamp-2 text-sm break-words whitespace-pre-line"
                            >
                                {{ note.body }}
                            </p>
                            <p class="text-muted-foreground mt-1 text-xs">
                                {{ formatDate(note.note_date) }}
                                <span v-if="note.updated_by">
                                    · Last changed by {{ note.updated_by }}</span
                                >
                            </p>
                        </div>
                        <div
                            class="flex items-center justify-between gap-3 sm:flex-col sm:items-end"
                        >
                            <span
                                class="font-medium tabular-nums"
                                :class="
                                    note.type === 'debit'
                                        ? 'text-destructive'
                                        : ''
                                "
                                >{{ note.type === 'debit' ? '-' : '+'
                                }}{{ note.amount_display }}</span
                            >
                            <div class="flex gap-2">
                                <Button
                                    v-if="note.status === 'active'"
                                    size="sm"
                                    variant="outline"
                                    :aria-label="`Edit ${note.title}`"
                                    @click="openEdit(note)"
                                    >Edit</Button
                                >
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    :aria-label="`${note.status === 'active' ? 'Archive' : 'Restore'} ${note.title}`"
                                    @click="askStatusChange(note)"
                                    >{{
                                        note.status === 'active'
                                            ? 'Archive'
                                            : 'Restore'
                                    }}</Button
                                >
                            </div>
                        </div>
                    </li>
                </ul>
                <div
                    v-if="notes.prev_page_url || notes.next_page_url"
                    class="mt-4 flex gap-4 text-sm"
                >
                    <Link
                        v-if="notes.prev_page_url"
                        :href="notes.prev_page_url"
                        preserve-scroll
                        class="underline"
                        >Previous</Link
                    ><Link
                        v-if="notes.next_page_url"
                        :href="notes.next_page_url"
                        preserve-scroll
                        class="underline"
                        >Next</Link
                    >
                </div>
            </CardContent>
        </Card>

        <FormSheet
            v-model:open="sheetOpen"
            :title="editing ? 'Edit note' : 'Add note'"
            description="Notes are for your records only. They do not post money or change any balance."
        >
            <form
                id="financial-note-form"
                class="grid gap-4"
                @submit.prevent="submitNote"
            >
                <div class="grid gap-2">
                    <Label for="note-type">Type</Label>
                    <Select v-model="form.type">
                        <SelectTrigger id="note-type" class="h-11 w-full"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="debit"
                                >Debit (money out)</SelectItem
                            >
                            <SelectItem value="credit"
                                >Credit (money in)</SelectItem
                            >
                        </SelectContent>
                    </Select>
                    <p
                        v-if="form.errors.type"
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        {{ form.errors.type }}
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="note-title">Title</Label>
                    <Input
                        id="note-title"
                        v-model="form.title"
                        maxlength="150"
                        :aria-invalid="!!form.errors.title"
                        aria-describedby="note-title-error"
                    />
                    <p
                        v-if="form.errors.title"
                        id="note-title-error"
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        {{ form.errors.title }}
                    </p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="note-amount">Amount (₦)</Label>
                        <Input
                            id="note-amount"
                            v-model="form.amount_ngn"
                            inputmode="decimal"
                            placeholder="0.00"
                            :aria-invalid="!!form.errors.amount_ngn"
                            aria-describedby="note-amount-error"
                        />
                        <p
                            v-if="form.errors.amount_ngn"
                            id="note-amount-error"
                            role="alert"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.amount_ngn }}
                        </p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="note-date">Date</Label>
                        <DatePicker
                            id="note-date"
                            v-model="form.note_date"
                            aria-label="Note date"
                            :error-message="form.errors.note_date"
                        />
                        <p
                            v-if="form.errors.note_date"
                            role="alert"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.note_date }}
                        </p>
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="note-category"
                        >Category
                        <span class="text-muted-foreground font-normal"
                            >(optional)</span
                        ></Label
                    >
                    <Input
                        id="note-category"
                        v-model="form.category"
                        maxlength="80"
                        list="note-category-options"
                        placeholder="For example, Rent"
                        :aria-invalid="!!form.errors.category"
                    />
                    <datalist id="note-category-options">
                        <option
                            v-for="category in categories"
                            :key="category"
                            :value="category"
                        />
                    </datalist>
                    <p
                        v-if="form.errors.category"
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        {{ form.errors.category }}
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="note-body">Note</Label>
                    <textarea
                        id="note-body"
                        v-model="form.body"
                        maxlength="5000"
                        :class="textareaClass"
                        :aria-invalid="!!form.errors.body"
                        aria-describedby="note-body-error"
                    />
                    <p
                        v-if="form.errors.body"
                        id="note-body-error"
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        {{ form.errors.body }}
                    </p>
                </div>
            </form>
            <template #footer>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="form.processing"
                    @click="sheetOpen = false"
                    >Cancel</Button
                >
                <Button
                    type="submit"
                    form="financial-note-form"
                    :disabled="form.processing"
                    >{{
                        form.processing
                            ? 'Saving…'
                            : editing
                              ? 'Save changes'
                              : 'Add note'
                    }}</Button
                >
            </template>
        </FormSheet>

        <Dialog
            :open="confirming !== null"
            @update:open="if (!$event && !statusForm.processing) confirming = null;"
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle
                        >{{
                            confirming?.status === 'active'
                                ? 'Archive'
                                : 'Restore'
                        }}
                        this note?</DialogTitle
                    >
                    <DialogDescription>
                        {{
                            confirming?.status === 'active'
                                ? 'It will be hidden from the list and totals. You can restore it later.'
                                : 'It will appear in the list and totals again.'
                        }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="statusForm.processing"
                        @click="confirming = null"
                        >Cancel</Button
                    >
                    <Button
                        type="button"
                        :variant="
                            confirming?.status === 'active'
                                ? 'destructive'
                                : 'default'
                        "
                        :disabled="statusForm.processing"
                        @click="submitStatusChange"
                        >{{
                            confirming?.status === 'active'
                                ? 'Archive note'
                                : 'Restore note'
                        }}</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
