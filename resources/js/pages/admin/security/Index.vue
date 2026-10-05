<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
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
            { title: 'Security operations', href: index() },
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
}
function stateLabel(state: string): string {
    return state === 'ClosedNoAction' ? 'Closed — no action' : state;
}
</script>
<template>
    <div class="space-y-6">
        <Head title="Security operations" />
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-[25px] font-medium tracking-tight">
                    Security operations
                </h1>
                <p class="text-muted-foreground mt-1.5 text-sm">
                    Review classified signals and track investigations.
                </p>
            </div>
            <Button variant="outline" @click="refresh">Refresh</Button>
        </header>
        <p v-if="notice" role="alert">{{ notice }}</p>
        <template v-if="visible">
            <div class="bg-muted/40 rounded-xl border p-4 text-sm">
                Case resolution records the progress of an investigation.
                Account restrictions use a different procedure. Assisted
                recovery is not available until its owner workflow is ready.
                <Link :href="lockouts()" class="underline underline-offset-4"
                    >Review authentication locks</Link
                >
            </div>
            <form
                class="flex flex-row flex-wrap gap-4"
                @submit.prevent="search"
            >
                <div class="w-fit space-y-1.5">
                    <Label for="case-state">State</Label>
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
                            <SelectItem :value="ALL">All states</SelectItem>
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
                            <SelectItem :value="ALL">All severities</SelectItem>
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
                <div class="w-fit space-y-1.5">
                    <Label for="case-size">Rows</Label>
                    <Select
                        :model-value="filters.per_page"
                        @update:model-value="
                            (value) => setFilter('per_page', value)
                        "
                    >
                        <SelectTrigger id="case-size"
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
                </div>
                <Button type="submit" class="self-end">Filter</Button>
            </form>
            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">
                        Security investigation queue, ordered by open state,
                        severity and age
                    </caption>
                    <thead class="bg-muted/50">
                        <tr>
                            <th scope="col" class="p-3">Case</th>
                            <th scope="col" class="p-3">Severity</th>
                            <th scope="col" class="p-3">State</th>
                            <th scope="col" class="p-3">Account</th>
                            <th scope="col" class="p-3">Owner</th>
                            <th scope="col" class="p-3">Created</th>
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
                                    class="font-mono text-xs underline"
                                    >{{ item.case_reference }}</Link
                                >
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
                            <td class="p-3">{{ item.affected_account }}</td>
                            <td class="p-3">
                                {{
                                    item.owner_id
                                        ? 'Admin #' + item.owner_id
                                        : 'Unassigned'
                                }}
                            </td>
                            <td class="p-3 whitespace-nowrap">
                                {{ item.created_at }}
                            </td>
                        </tr>
                        <tr v-if="!cases.data.length">
                            <td colspan="6" class="text-muted-foreground p-6">
                                No matching security cases.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <nav aria-label="Case pages" class="flex flex-wrap gap-2">
                <template v-for="(link, i) in cases.links" :key="i"
                    ><Link
                        v-if="link.url"
                        :href="link.url"
                        class="rounded-md border px-3 py-2 text-sm"
                        :aria-current="link.active ? 'page' : undefined"
                        >{{ link.label.replace(/&[^;]+;/g, '') }}</Link
                    ></template
                >
            </nav>
        </template>
    </div>
</template>
