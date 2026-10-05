<script setup lang="ts">
import { Head, Link, router, useHttp, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
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
                'The system cannot verify notification access. Refresh the page to try again.';
        } else if (
            notificationSyncState.scope &&
            notificationSyncState.scope !== props.inbox.scope
        ) {
            visible.value = null;
            message.value = 'Your access changed. Refreshing notifications.';
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
            message.value = 'Notifications are unavailable. Refresh to retry.';
        },
        onNetworkError: () => {
            message.value =
                'Notifications could not be synchronized. Refresh to retry.';
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
            'This notice changed or became unavailable. Refresh to retry.';
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
        message.value = 'This page changed. Refresh before marking it read.';
    }
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
    <div class="space-y-6">
        <Head title="Notifications" />
        <header>
            <h1 class="text-[25px] font-medium tracking-tight">
                Notifications
            </h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                This page shows your authorized account and business notices.
                Times use the {{ timezone }} time zone.
            </p>
        </header>
        <form class="flex flex-row flex-wrap gap-4" @submit.prevent="visit()">
            <div class="w-fit space-y-1.5">
                <Label for="notice-search">Search</Label
                ><Input
                    id="notice-search"
                    v-model="filters.search"
                    placeholder="Title, summary or reference"
                    maxlength="160"
                />
            </div>
            <div class="w-fit space-y-1.5">
                <Label for="notice-read">Read state</Label
                ><Select v-model="filters.read"
                    ><SelectTrigger id="notice-read" class="h-11 w-fit"
                        ><SelectValue /></SelectTrigger
                    ><SelectContent
                        ><SelectItem value="all">All</SelectItem
                        ><SelectItem value="unread"
                            >Unread</SelectItem
                        ></SelectContent
                    ></Select
                >
            </div>
            <div class="w-fit space-y-1.5">
                <Label for="notice-category">Category</Label
                ><Select
                    :model-value="filters.category || '__all'"
                    @update:model-value="
                        filters.category =
                            $event === '__all' ? '' : String($event ?? '')
                    "
                    ><SelectTrigger id="notice-category" class="h-11 w-fit"
                        ><SelectValue /></SelectTrigger
                    ><SelectContent
                        ><SelectItem value="__all">All categories</SelectItem
                        ><SelectItem value="account"
                            >Account and lifecycle</SelectItem
                        ><SelectItem value="financial">Financial</SelectItem
                        ><SelectItem value="plan"
                            >Plan</SelectItem
                        ></SelectContent
                    ></Select
                >
            </div>
            <div class="w-fit space-y-1.5">
                <Label for="notice-action">Action</Label
                ><Select
                    :model-value="filters.action_required || '__all'"
                    @update:model-value="
                        filters.action_required =
                            $event === '__all' ? '' : String($event ?? '')
                    "
                    ><SelectTrigger id="notice-action" class="h-11 w-fit"
                        ><SelectValue /></SelectTrigger
                    ><SelectContent
                        ><SelectItem value="__all">All notices</SelectItem
                        ><SelectItem value="1">Action required</SelectItem
                        ><SelectItem value="0"
                            >Informational</SelectItem
                        ></SelectContent
                    ></Select
                >
            </div>
            <div class="w-fit space-y-1.5">
                <Label for="notice-status">Status</Label
                ><Select v-model="filters.status"
                    ><SelectTrigger id="notice-status" class="h-11 w-fit"
                        ><SelectValue /></SelectTrigger
                    ><SelectContent
                        ><SelectItem value="current">Current</SelectItem
                        ><SelectItem value="expired">Expired action</SelectItem
                        ><SelectItem value="superseded"
                            >Superseded</SelectItem
                        ></SelectContent
                    ></Select
                >
            </div>
            <div class="w-fit space-y-1.5">
                <Label for="notice-from">From</Label
                ><DatePicker id="notice-from" v-model="filters.from" />
            </div>
            <div class="w-fit space-y-1.5">
                <Label for="notice-to">To</Label
                ><DatePicker id="notice-to" v-model="filters.to" />
            </div>
            <div class="w-fit space-y-1.5">
                <Label for="notice-size">Rows</Label
                ><Select
                    :model-value="String(filters.page_size ?? 25)"
                    @update:model-value="filters.page_size = Number($event)"
                    ><SelectTrigger id="notice-size" class="h-11 w-fit"
                        ><SelectValue /></SelectTrigger
                    ><SelectContent
                        ><SelectItem value="25">25</SelectItem
                        ><SelectItem value="50">50</SelectItem
                        ><SelectItem value="100">100</SelectItem></SelectContent
                    ></Select
                >
            </div>
            <Button type="submit" class="self-end" :disabled="loading"
                >Apply filters</Button
            >
        </form>
        <p
            v-for="error in errors"
            :key="String(error)"
            class="text-destructive text-sm"
            role="alert"
        >
            {{ error }}
        </p>
        <div class="flex flex-wrap items-center gap-3">
            <Button variant="outline" :disabled="loading" @click="visit()"
                >Refresh</Button
            >
            <Button
                variant="outline"
                :disabled="!visible?.items.length || bulk.processing || loading"
                @click="markCurrentPage"
                >Mark current page read</Button
            >
            <span v-if="visible" class="text-muted-foreground text-sm"
                >{{ visible.unread_count }} unread at this view’s cutoff</span
            >
        </div>
        <p v-if="message" role="status" class="text-muted-foreground">
            {{ message }}
        </p>
        <div
            v-if="loading"
            role="status"
            aria-label="Loading notifications"
            class="bg-muted h-32 animate-pulse rounded-xl"
        />
        <template v-else-if="visible">
            <p
                v-if="!visible.items.length"
                class="text-muted-foreground"
                role="status"
            >
                {{
                    Object.entries(filters).some(
                        ([key, value]) =>
                            !['page_size', 'read', 'status'].includes(key) &&
                            value,
                    ) ||
                    filters.read === 'unread' ||
                    (filters.status && filters.status !== 'current')
                        ? 'No notifications match these filters.'
                        : 'No notifications.'
                }}
            </p>
            <ul v-else class="space-y-3" aria-label="Notifications">
                <li v-for="notice in visible.items" :key="notice.id">
                    <Card
                        ><CardContent
                            class="flex flex-col gap-3 pt-5 sm:flex-row sm:items-start sm:justify-between"
                        >
                            <div class="min-w-0 space-y-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <Badge variant="secondary">{{
                                        notice.category
                                    }}</Badge
                                    ><Badge v-if="!notice.read_at">Unread</Badge
                                    ><Badge
                                        v-if="notice.action_required"
                                        variant="outline"
                                        >Action required</Badge
                                    ><Badge
                                        v-if="notice.visibility !== 'current'"
                                        variant="outline"
                                        >{{
                                            notice.visibility === 'expired'
                                                ? 'Expired action'
                                                : 'Superseded'
                                        }}</Badge
                                    >
                                </div>
                                <h2 class="font-medium">
                                    <Link
                                        :href="show(notice.id)"
                                        class="rounded underline-offset-4 hover:underline focus-visible:outline-2"
                                        >{{ notice.title }}</Link
                                    >
                                </h2>
                                <p class="text-muted-foreground text-sm">
                                    {{ notice.summary }}
                                </p>
                                <p v-if="notice.reference" class="text-sm">
                                    {{ notice.reference }}
                                </p>
                                <time
                                    :datetime="notice.effective_at"
                                    class="text-muted-foreground text-xs"
                                    >{{ date(notice.effective_at) }}</time
                                >
                            </div>
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="marking.processing"
                                :aria-label="`${notice.read_at ? 'Mark unread' : 'Mark read'}: ${notice.title}`"
                                @click="mark(notice)"
                                >{{
                                    notice.read_at ? 'Mark unread' : 'Mark read'
                                }}</Button
                            >
                        </CardContent></Card
                    >
                </li>
            </ul>
            <Button
                v-if="visible.next_cursor"
                variant="outline"
                :disabled="loading"
                @click="visit(visible.next_cursor ?? undefined)"
                >Next page</Button
            >
        </template>
    </div>
</template>
