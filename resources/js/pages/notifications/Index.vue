<script setup lang="ts">
import { Head, Link, router, useHttp, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { Bell, RefreshCw, Search, SlidersHorizontal } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import FormSheet from '@/components/FormSheet.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { DatePicker } from '@/components/ui/date-picker';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { notificationSyncState } from '@/composables/useNotificationSync';
import { dashboard } from '@/routes';
import { index, show, read, pageRead } from '@/routes/notifications';
import type {
    InboxFilters,
    InboxNotice,
    InboxResult,
} from '@/types/notifications';

const props = defineProps<{
    inbox: InboxResult;
    filters: InboxFilters;
    timezone: string;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Notifications', href: index() },
        ],
    },
});
const page = usePage();
const visible = ref<InboxResult | null>(props.inbox);
const filters = ref<InboxFilters>({ ...props.filters });
const message = ref('');
const loading = ref(false);
const marking = useHttp({ read: true, version: 1 });
const bulk = useHttp({ page_token: '' });
const errors = computed(() => Object.values(page.props.errors));
watch(
    () => props.inbox,
    (value) => {
        visible.value = value;
        message.value = '';
    },
);
watch(
    () => props.filters,
    (value) => {
        filters.value = { ...value };
    },
);
watch(
    () => [
        notificationSyncState.status,
        notificationSyncState.scope,
        notificationSyncState.userId,
    ],
    () => {
        if (
            notificationSyncState.status === 'unavailable' ||
            notificationSyncState.userId !== page.props.auth.user.id
        ) {
            visible.value = null;
            message.value =
                "We couldn't check your access to notifications. Refresh the page to try again.";
        } else if (
            notificationSyncState.scope &&
            notificationSyncState.scope !== props.inbox.scope
        ) {
            visible.value = null;
            message.value =
                'Your access changed. Loading your notifications again.';
            visit();
        }
    },
);
function visit(cursor?: string): void {
    if (loading.value) return;
    visible.value = null;
    loading.value = true;
    const query = Object.fromEntries(
        Object.entries({ ...filters.value, cursor }).filter(
            ([, value]) => value !== '' && value !== undefined,
        ),
    );
    router.get(index.url(), query, {
        preserveScroll: true,
        preserveState: true,
        onHttpException: () => {
            message.value =
                "Notifications can't load right now. Refresh to try again.";
        },
        onNetworkError: () => {
            message.value =
                "We couldn't load notifications. Check your connection and refresh.";
        },
        onFinish: () => {
            loading.value = false;
        },
    });
}
async function mark(notice: InboxNotice): Promise<void> {
    marking.read = notice.read_at === null;
    marking.version = notice.read_version;
    try {
        await marking.patch(read.url(notice.id));
        visit();
    } catch {
        visible.value = null;
        message.value =
            'This notification changed or is no longer available. Refresh to try again.';
    }
}
async function markCurrentPage(): Promise<void> {
    if (!visible.value) return;
    bulk.page_token = visible.value.page_token;
    try {
        await bulk.post(pageRead.url());
        visit();
    } catch {
        visible.value = null;
        message.value = 'New notifications arrived. Refresh, then try again.';
    }
}
const filtersOpen = ref(false);
const activeFilterCount = computed(
    () =>
        [
            filters.value.category,
            filters.value.action_required,
            filters.value.from,
            filters.value.to,
        ].filter(Boolean).length +
        (filters.value.status && filters.value.status !== 'current' ? 1 : 0) +
        (filters.value.page_size && filters.value.page_size !== 25 ? 1 : 0),
);
const hasFilters = computed(
    () =>
        Boolean(filters.value.search) ||
        filters.value.read === 'unread' ||
        Boolean(
            filters.value.category ||
            filters.value.action_required ||
            filters.value.from ||
            filters.value.to,
        ) ||
        Boolean(filters.value.status && filters.value.status !== 'current'),
);
const categoryLabels: Record<string, string> = {
    account: 'Account',
    financial: 'Money',
    plan: 'Plan',
};
function applyFromSheet(): void {
    filtersOpen.value = false;
    visit();
}
function resetFilters(): void {
    filters.value = {
        ...filters.value,
        category: '',
        action_required: '',
        status: 'current',
        from: '',
        to: '',
        page_size: 25,
    };
    applyFromSheet();
}
function date(value: string): string {
    return new Intl.DateTimeFormat('en-NG', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: props.timezone,
    }).format(new Date(value));
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head title="Notifications" />
        <PageHeader
            title="Notifications"
            description="Updates about your account, savings and business."
        >
            <template #actions>
                <Button
                    variant="outline"
                    :disabled="
                        !visible?.items.length || bulk.processing || loading
                    "
                    @click="markCurrentPage"
                    >Mark page as read</Button
                >
                <Button
                    variant="outline"
                    :disabled="loading"
                    aria-label="Refresh notifications"
                    @click="visit()"
                    ><RefreshCw
                        class="size-4"
                        :class="loading ? 'animate-spin' : ''"
                    />
                    Refresh</Button
                >
            </template>
        </PageHeader>
        <form
            class="flex flex-row flex-wrap items-end gap-4"
            aria-label="Notification filters"
            @submit.prevent="visit()"
        >
            <div class="w-fit space-y-2">
                <Label for="notice-search">Search</Label
                ><Input
                    id="notice-search"
                    v-model="filters.search"
                    placeholder="Title, message or reference"
                    maxlength="160"
                />
            </div>
            <div class="w-fit space-y-2">
                <Label for="notice-read">Show</Label
                ><Select v-model="filters.read" @update:model-value="visit()"
                    ><SelectTrigger id="notice-read" class="h-11 w-fit"
                        ><SelectValue /></SelectTrigger
                    ><SelectContent
                        ><SelectItem value="all">All</SelectItem
                        ><SelectItem value="unread"
                            >Unread only</SelectItem
                        ></SelectContent
                    ></Select
                >
            </div>
            <Button type="submit" variant="outline" :disabled="loading"
                ><Search class="size-4" /> Search</Button
            >
            <Button type="button" variant="outline" @click="filtersOpen = true">
                <SlidersHorizontal class="size-4" />
                Filters
                <span
                    v-if="activeFilterCount > 0"
                    class="bg-primary text-primary-foreground inline-flex size-5 items-center justify-center rounded-full text-[11px]"
                    >{{ activeFilterCount }}</span
                >
            </Button>
        </form>

        <FormSheet
            v-model:open="filtersOpen"
            title="Filters"
            description="Narrow down which notifications you see."
        >
            <div class="grid gap-5">
                <div class="grid gap-2">
                    <Label for="notice-category">Type</Label
                    ><Select
                        :model-value="filters.category || '__all'"
                        @update:model-value="
                            filters.category =
                                $event === '__all' ? '' : String($event ?? '')
                        "
                        ><SelectTrigger id="notice-category" class="h-11 w-full"
                            ><SelectValue /></SelectTrigger
                        ><SelectContent
                            ><SelectItem value="__all">All types</SelectItem
                            ><SelectItem value="account">Account</SelectItem
                            ><SelectItem value="financial">Money</SelectItem
                            ><SelectItem value="plan"
                                >Plan</SelectItem
                            ></SelectContent
                        ></Select
                    >
                </div>
                <div class="grid gap-2">
                    <Label for="notice-action">Needs action</Label
                    ><Select
                        :model-value="filters.action_required || '__all'"
                        @update:model-value="
                            filters.action_required =
                                $event === '__all' ? '' : String($event ?? '')
                        "
                        ><SelectTrigger id="notice-action" class="h-11 w-full"
                            ><SelectValue /></SelectTrigger
                        ><SelectContent
                            ><SelectItem value="__all">All</SelectItem
                            ><SelectItem value="1">Needs action</SelectItem
                            ><SelectItem value="0"
                                >For your information</SelectItem
                            ></SelectContent
                        ></Select
                    >
                </div>
                <div class="grid gap-2">
                    <Label for="notice-status">Status</Label
                    ><Select v-model="filters.status"
                        ><SelectTrigger id="notice-status" class="h-11 w-full"
                            ><SelectValue /></SelectTrigger
                        ><SelectContent
                            ><SelectItem value="current">Current</SelectItem
                            ><SelectItem value="expired"
                                >Action expired</SelectItem
                            ><SelectItem value="superseded"
                                >Replaced by a newer one</SelectItem
                            ></SelectContent
                        ></Select
                    >
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-2">
                        <Label for="notice-from">From</Label
                        ><DatePicker id="notice-from" v-model="filters.from" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="notice-to">To</Label
                        ><DatePicker id="notice-to" v-model="filters.to" />
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="notice-size">Per page</Label
                    ><Select
                        :model-value="String(filters.page_size ?? 25)"
                        @update:model-value="filters.page_size = Number($event)"
                        ><SelectTrigger id="notice-size" class="h-11 w-full"
                            ><SelectValue /></SelectTrigger
                        ><SelectContent
                            ><SelectItem value="25">25</SelectItem
                            ><SelectItem value="50">50</SelectItem
                            ><SelectItem value="100"
                                >100</SelectItem
                            ></SelectContent
                        ></Select
                    >
                </div>
            </div>
            <template #footer>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="loading"
                    @click="resetFilters"
                    >Clear</Button
                >
                <Button
                    type="button"
                    :disabled="loading"
                    @click="applyFromSheet"
                    >Show results</Button
                >
            </template>
        </FormSheet>

        <p
            v-for="error in errors"
            :key="String(error)"
            class="text-destructive text-sm"
            role="alert"
        >
            {{ error }}
        </p>
        <p v-if="message" role="status" class="bg-muted rounded-xl p-4 text-sm">
            {{ message }}
        </p>
        <p
            v-else-if="visible"
            role="status"
            aria-live="polite"
            class="text-muted-foreground -mt-2 text-sm"
        >
            {{ visible.unread_count }} unread
        </p>
        <div
            v-if="loading"
            role="status"
            aria-label="Loading notifications"
            class="bg-muted h-32 animate-pulse rounded-2xl motion-reduce:animate-none"
        />
        <template v-else-if="visible">
            <EmptyState
                v-if="!visible.items.length"
                :icon="Bell"
                :title="
                    hasFilters
                        ? 'No notifications match'
                        : 'No notifications yet'
                "
                :description="
                    hasFilters
                        ? 'Try a different search or clear your filters.'
                        : 'You will see updates here when something happens.'
                "
            />
            <Card v-else class="py-2">
                <CardContent>
                    <ul
                        class="divide-border divide-y"
                        aria-label="Notifications"
                    >
                        <li
                            v-for="notice in visible.items"
                            :key="notice.id"
                            class="flex flex-col gap-3 py-4 sm:flex-row sm:items-start sm:justify-between"
                        >
                            <div class="flex min-w-0 gap-3">
                                <span
                                    class="mt-2 size-2 shrink-0 rounded-full"
                                    :class="
                                        notice.read_at
                                            ? 'bg-transparent'
                                            : 'bg-primary'
                                    "
                                    aria-hidden="true"
                                />
                                <div class="min-w-0 space-y-1">
                                    <h2
                                        :class="
                                            notice.read_at
                                                ? 'font-normal'
                                                : 'font-medium'
                                        "
                                    >
                                        <Link
                                            :href="show(notice.id)"
                                            class="rounded underline-offset-4 hover:underline focus-visible:outline-2"
                                            >{{ notice.title }}</Link
                                        ><span
                                            v-if="!notice.read_at"
                                            class="sr-only"
                                        >
                                            (unread)</span
                                        >
                                    </h2>
                                    <p class="text-muted-foreground text-sm">
                                        {{ notice.summary }}
                                    </p>
                                    <div
                                        class="text-muted-foreground flex flex-wrap items-center gap-2 pt-1 text-xs"
                                    >
                                        <span>{{
                                            categoryLabels[notice.category] ??
                                            notice.category
                                        }}</span>
                                        <span aria-hidden="true">·</span>
                                        <time :datetime="notice.effective_at">{{
                                            date(notice.effective_at)
                                        }}</time>
                                        <template v-if="notice.reference">
                                            <span aria-hidden="true">·</span>
                                            <span>{{ notice.reference }}</span>
                                        </template>
                                        <Badge
                                            v-if="notice.action_required"
                                            variant="default"
                                            >Needs action</Badge
                                        >
                                        <Badge
                                            v-if="
                                                notice.visibility !== 'current'
                                            "
                                            variant="outline"
                                            >{{
                                                notice.visibility === 'expired'
                                                    ? 'Action expired'
                                                    : 'Replaced'
                                            }}</Badge
                                        >
                                    </div>
                                </div>
                            </div>
                            <Button
                                variant="ghost"
                                size="sm"
                                class="self-start"
                                :disabled="marking.processing"
                                :aria-label="`${notice.read_at ? 'Mark unread' : 'Mark read'}: ${notice.title}`"
                                @click="mark(notice)"
                                >{{
                                    notice.read_at ? 'Mark unread' : 'Mark read'
                                }}</Button
                            >
                        </li>
                    </ul>
                </CardContent>
            </Card>
            <Button
                v-if="visible.next_cursor"
                variant="outline"
                class="w-fit"
                :disabled="loading"
                @click="visit(visible.next_cursor ?? undefined)"
                >Next page</Button
            >
        </template>
    </div>
</template>
