<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { RefreshCw, ShieldCheck } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useProtectedWorkspace } from '@/composables/useProtectedWorkspace';
import { dashboard } from '@/routes';
import { index, show } from '@/routes/admin/security';
import { index as lockouts } from '@/routes/admin/lockouts';
import type { SecurityCaseSummary } from '@/types/audit';
const props = defineProps<{
    cases: {
        data: SecurityCaseSummary[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    filters: Record<string, string>;
    scope: string;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Security cases', href: index() },
        ],
    },
});
const filters = ref<Record<string, string>>({
    per_page: '25',
    state: '',
    severity: '',
    ...props.filters,
});
const { visible, notice, refresh, clear } = useProtectedWorkspace(
    () => props.scope,
    'security.operations.manage',
);
function search(): void {
    router.get(
        index.url({ query: filters.value }),
        {},
        { onHttpException: clear, onNetworkError: clear },
    );
}
const ALL = '__all';
function setFilter(key: string, value: unknown): void {
    filters.value[key] = value === ALL ? '' : String(value ?? '');
    search();
}
function stateLabel(state: string): string {
    return state === 'ClosedNoAction' ? 'Closed, no action' : state;
}
</script>
<template>
    <div class="space-y-6">
        <Head title="Security cases" />
        <PageHeader
            title="Security cases"
            description="Look into unusual sign-in activity and track what was done."
        >
            <template #actions>
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
            <p class="text-muted-foreground text-sm">
                Closing a case does not unlock or change an account. To unlock
                someone, go to
                <Link
                    :href="lockouts()"
                    class="text-foreground font-medium underline underline-offset-4"
                    >Lockouts</Link
                >.
            </p>
            <div
                class="flex flex-row flex-wrap gap-4"
                role="group"
                aria-label="Case filters"
            >
                <div class="w-fit space-y-1.5">
                    <Label for="case-state">Status</Label>
                    <Select
                        :model-value="filters.state || ALL"
                        @update:model-value="
                            (value) => setFilter('state', value)
                        "
                    >
                        <SelectTrigger id="case-state"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL">All statuses</SelectItem>
                            <SelectItem
                                v-for="state in [
                                    'Open',
                                    'Investigating',
                                    'Resolved',
                                    'ClosedNoAction',
                                ]"
                                :key="state"
                                :value="state"
                            >
                                {{ stateLabel(state) }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div class="w-fit space-y-1.5">
                    <Label for="case-severity">Severity</Label>
                    <Select
                        :model-value="filters.severity || ALL"
                        @update:model-value="
                            (value) => setFilter('severity', value)
                        "
                    >
                        <SelectTrigger id="case-severity"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL">All levels</SelectItem>
                            <SelectItem
                                v-for="severity in [
                                    'Critical',
                                    'High',
                                    'Medium',
                                    'Low',
                                    'Informational',
                                ]"
                                :key="severity"
                                :value="severity"
                            >
                                {{ severity }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>
            <EmptyState
                v-if="!cases.data.length"
                :icon="ShieldCheck"
                title="No cases found"
                description="Nothing matches these filters right now."
            />
            <div v-else class="overflow-x-auto rounded-xl border">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">
                        Security cases, open and most serious first
                    </caption>
                    <thead class="text-muted-foreground text-xs">
                        <tr>
                            <th scope="col" class="p-3 font-medium">Account</th>
                            <th scope="col" class="p-3 font-medium">
                                Severity
                            </th>
                            <th scope="col" class="p-3 font-medium">Status</th>
                            <th scope="col" class="p-3 font-medium">
                                Assigned to
                            </th>
                            <th scope="col" class="p-3 font-medium">Opened</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="item in cases.data"
                            :key="item.case_reference"
                            class="border-t"
                        >
                            <th scope="row" class="p-3 font-normal">
                                <Link
                                    :href="show(item.case_reference)"
                                    class="font-medium underline-offset-4 hover:underline"
                                    >{{ item.affected_account }}</Link
                                >
                                <p
                                    class="text-muted-foreground mt-0.5 font-mono text-xs"
                                >
                                    {{ item.case_reference }}
                                </p>
                            </th>
                            <td class="p-3">
                                <Badge
                                    :variant="
                                        ['Critical', 'High'].includes(
                                            item.severity,
                                        )
                                            ? 'destructive'
                                            : 'secondary'
                                    "
                                    >{{ item.severity }}</Badge
                                >
                            </td>
                            <td class="p-3">{{ stateLabel(item.state) }}</td>
                            <td class="p-3">
                                {{
                                    item.owner_id
                                        ? 'Admin #' + item.owner_id
                                        : 'No one yet'
                                }}
                            </td>
                            <td class="p-3 whitespace-nowrap">
                                {{ item.created_at }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div
                    class="text-muted-foreground flex items-center gap-2 text-sm"
                >
                    Show
                    <Select
                        :model-value="filters.per_page"
                        @update:model-value="
                            (value) => setFilter('per_page', value)
                        "
                    >
                        <SelectTrigger
                            id="case-size"
                            class="h-9 w-20"
                            aria-label="Rows per page"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="size in ['25', '50', '100']"
                                :key="size"
                                :value="size"
                            >
                                {{ size }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    per page
                </div>
                <nav aria-label="Case pages" class="flex flex-wrap gap-2">
                    <template v-for="(link, i) in cases.links" :key="i"
                        ><Link
                            v-if="link.url"
                            :href="link.url"
                            class="rounded-md border px-3 py-1.5 text-sm"
                            :class="
                                link.active
                                    ? 'bg-accent text-accent-foreground'
                                    : 'hover:bg-muted'
                            "
                            :aria-current="link.active ? 'page' : undefined"
                            >{{ link.label.replace(/&[^;]+;/g, '') }}</Link
                        ></template
                    >
                </nav>
            </div>
        </template>
    </div>
</template>
