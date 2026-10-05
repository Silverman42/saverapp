<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
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
const props = defineProps<{
    audit: AuditResult;
    filters: Record<string, string>;
    scope: string;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Audit trail', href: index() },
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
const reference = ref('');
function search(cursor?: string): void {
    router.get(
        index.url({ query: { ...filters.value, cursor } }),
        {},
        { onHttpException: clear, onNetworkError: clear },
    );
}
function lookup(): void {
    if (/^[0-9A-HJKMNP-TV-Z]{26}$/.test(reference.value.toUpperCase())) {
        router.visit(show.url(reference.value.toUpperCase()));
    }
}
</script>
<template>
    <div class="space-y-6">
        <Head title="Audit trail" />
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-[25px] font-medium tracking-tight">
                    Audit trail
                </h1>
                <p class="text-muted-foreground mt-1.5 text-sm">
                    Search permitted events and review masked evidence.
                </p>
            </div>
            <Button variant="outline" @click="refresh">Refresh</Button>
        </header>
        <p v-if="notice" role="alert" class="rounded-lg border p-4 text-sm">
            {{ notice }}
        </p>
        <template v-if="visible">
            <div
                class="bg-muted/40 flex flex-wrap gap-4 rounded-xl border p-4 text-sm"
                role="status"
            >
                <span>Index: {{ audit.health.status }}</span
                ><span>{{ audit.health.pending }} pending</span
                ><span>Integrity: {{ audit.health.integrity }}</span
                ><span>Retention: {{ audit.health.retention }}</span
                ><span
                    >As of: {{ audit.health.as_of || 'Not yet indexed' }}</span
                >
            </div>
            <p
                v-if="audit.health.status !== 'current'"
                class="text-sm"
                role="status"
            >
                Results are incomplete while indexing catches up. Use an exact
                event reference to open canonical evidence.
            </p>
            <form
                class="flex flex-row flex-wrap gap-4"
                @submit.prevent="search()"
            >
                <div class="w-fit space-y-1.5">
                    <Label for="audit-from">From (UTC date)</Label
                    ><DatePicker
                        id="audit-from"
                        v-model="filters.from"
                        aria-label="From date (UTC)"
                    />
                </div>
                <div class="w-fit space-y-1.5">
                    <Label for="audit-to">To (UTC date)</Label
                    ><DatePicker
                        id="audit-to"
                        v-model="filters.to"
                        aria-label="To date (UTC)"
                    />
                </div>
                <div
                    v-for="field in [
                        'event_type',
                        'category',
                        'actor_id',
                        'actor_type',
                        'target_type',
                        'target_reference',
                        'source_module',
                        'required_permission',
                        'correlation_reference',
                        'severity',
                        'retention_class',
                    ]"
                    :key="field"
                    class="w-fit space-y-1.5"
                >
                    <Label :for="'audit-' + field">{{
                        field.replaceAll('_', ' ')
                    }}</Label
                    ><Input :id="'audit-' + field" v-model="filters[field]" />
                </div>
                <div class="w-fit space-y-1.5">
                    <Label for="audit-outcome">Outcome</Label
                    ><Select
                        :model-value="filters.outcome || '__all'"
                        @update:model-value="
                            filters.outcome =
                                $event === '__all' ? '' : String($event)
                        "
                    >
                        <SelectTrigger id="audit-outcome"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__all">All outcomes</SelectItem>
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
                <div class="w-fit space-y-1.5">
                    <Label for="audit-size">Rows</Label
                    ><Select
                        :model-value="String(filters.per_page)"
                        @update:model-value="filters.per_page = String($event)"
                    >
                        <SelectTrigger id="audit-size"
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
                <Button type="submit" class="self-end">Search</Button>
            </form>
            <form
                class="flex flex-wrap items-end gap-3"
                @submit.prevent="lookup"
            >
                <div class="space-y-1.5">
                    <Label for="audit-reference">Exact event reference</Label
                    ><Input
                        id="audit-reference"
                        v-model="reference"
                        maxlength="26"
                        required
                        placeholder="Event reference"
                    />
                </div>
                <Button variant="outline" type="submit">Open event</Button>
            </form>
            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">
                        Masked audit search results
                    </caption>
                    <thead class="bg-muted/50">
                        <tr>
                            <th scope="col" class="p-3">Recorded (UTC)</th>
                            <th scope="col" class="p-3">Event</th>
                            <th scope="col" class="p-3">Actor</th>
                            <th scope="col" class="p-3">Target</th>
                            <th scope="col" class="p-3">Result</th>
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
                                    class="font-medium underline underline-offset-4"
                                    >{{ event.event_type }}</Link
                                >
                                <div
                                    class="text-muted-foreground mt-1 text-xs break-all"
                                >
                                    {{ event.event_id }}
                                </div>
                                <Badge
                                    v-if="event.legacy_evidence"
                                    variant="secondary"
                                    class="mt-1"
                                    >Legacy evidence</Badge
                                >
                            </th>
                            <td class="p-3">
                                {{ event.actor_type }}
                                {{ event.actor_id ? '#' + event.actor_id : '' }}
                            </td>
                            <td class="p-3">
                                {{
                                    event.target_reference ||
                                    'Reference unavailable'
                                }}
                            </td>
                            <td class="p-3">
                                <Badge variant="outline">{{
                                    event.outcome
                                }}</Badge>
                                <p class="mt-1 text-xs">{{ event.severity }}</p>
                            </td>
                        </tr>
                        <tr v-if="!audit.rows.length">
                            <td colspan="5" class="text-muted-foreground p-6">
                                No indexed events match this range. The default
                                search covers the last 24 hours.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Button
                v-if="audit.next_cursor"
                variant="outline"
                @click="search(audit.next_cursor)"
                >Next results</Button
            >
        </template>
    </div>
</template>
