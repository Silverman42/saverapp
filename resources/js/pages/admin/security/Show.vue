<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch, ref } from 'vue';
import {
    MessageSquarePlus,
    MoreHorizontal,
    RefreshCw,
    RotateCcw,
    UserPlus,
    CircleCheck,
} from '@lucide/vue';
import FormSheet from '@/components/FormSheet.vue';
import MoreDetails from '@/components/MoreDetails.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
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
import { index, update } from '@/routes/admin/security';
import { index as lockouts } from '@/routes/admin/lockouts';
import type {
    SecurityCaseSummary,
    SecurityCaseTransition,
} from '@/types/audit';
const props = defineProps<{
    case: SecurityCaseSummary;
    history: SecurityCaseTransition[];
    owners: { id: number; label: string }[];
    source: {
        event_type: string;
        occurred_at: string;
        facts: Record<string, string | number>;
    };
    scope: string;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Security cases', href: index() },
            { title: 'Case details' },
        ],
    },
});
const { visible, notice, refresh, clear } = useProtectedWorkspace(
    () => props.scope,
    'security.operations.manage',
);
const evidence = ref('');
const QUEUE = '__queue';
type CaseAction = 'note' | 'assign' | 'state' | 'reopen';
const form = useForm({
    expected_version: props.case.version,
    action: 'note' as CaseAction,
    owner_id: '' as number | '',
    state: 'Investigating',
    note: '',
    evidence_references: [] as string[],
});
function setOwner(value: unknown): void {
    form.owner_id = value === QUEUE ? '' : Number(value);
}
watch(
    () => props.case.version,
    (version) => {
        form.expected_version = version;
    },
);
const currentCase = computed(() => props.case);
const sheetOpen = ref(false);
watch(visible, (value) => {
    if (!value) {
        form.reset();
        evidence.value = '';
        sheetOpen.value = false;
    } else {
        form.expected_version = props.case.version;
    }
});
const states = computed(() =>
    props.case.state === 'Open'
        ? ['Investigating', 'ClosedNoAction']
        : props.case.state === 'Investigating'
          ? ['Resolved', 'ClosedNoAction']
          : [],
);
const canReopen = computed(() =>
    ['Resolved', 'ClosedNoAction'].includes(props.case.state),
);
watch(
    states,
    (values) => {
        form.state = values[0] ?? '';
    },
    { immediate: true },
);
const sheetTitles: Record<CaseAction, string> = {
    note: 'Add a note',
    assign: 'Assign case',
    state: 'Change status',
    reopen: 'Reopen case',
};
const noteLabels: Record<CaseAction, string> = {
    note: 'Note',
    assign: 'Note (optional)',
    state: 'Reason',
    reopen: 'Reason',
};
function openSheet(action: CaseAction): void {
    form.action = action;
    form.clearErrors();
    sheetOpen.value = true;
}
function submit(): void {
    form.evidence_references = evidence.value
        .split(',')
        .map((value) => value.trim())
        .filter(Boolean);
    form.patch(update.url(props.case.case_reference), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('note');
            evidence.value = '';
            sheetOpen.value = false;
        },
        onHttpException: clear,
        onNetworkError: clear,
    });
}
function label(state: string): string {
    return state === 'ClosedNoAction' ? 'Closed, no action' : state;
}
</script>
<template>
    <div class="space-y-6">
        <Head title="Security case" />
        <PageHeader
            title="Security case"
            description="Check what happened and record what you did."
        >
            <template #actions>
                <template v-if="visible">
                    <Button @click="openSheet('note')">
                        <MessageSquarePlus class="size-4" />
                        Add note
                    </Button>
                    <DropdownMenu :modal="false">
                        <DropdownMenuTrigger as-child>
                            <Button variant="outline">
                                <MoreHorizontal class="size-4" />
                                More
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem @select="openSheet('assign')">
                                <UserPlus class="size-4" />
                                Assign case
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="states.length"
                                @select="openSheet('state')"
                            >
                                <CircleCheck class="size-4" />
                                Change status
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="canReopen"
                                @select="openSheet('reopen')"
                            >
                                <RotateCcw class="size-4" />
                                Reopen case
                            </DropdownMenuItem>
                            <DropdownMenuItem @select="refresh">
                                <RefreshCw class="size-4" />
                                Refresh
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </template>
                <Button v-else variant="outline" @click="refresh">
                    <RefreshCw class="size-4" />
                    Refresh
                </Button>
            </template>
        </PageHeader>
        <p v-if="notice" role="alert" class="bg-muted rounded-xl p-4 text-sm">
            {{ notice }}
        </p>
        <template v-if="visible">
            <Card>
                <CardHeader class="space-y-2">
                    <div class="flex flex-wrap gap-2">
                        <Badge variant="secondary">{{
                            currentCase.severity
                        }}</Badge
                        ><Badge variant="outline">{{
                            label(currentCase.state)
                        }}</Badge>
                    </div>
                    <CardTitle class="text-lg break-all">{{
                        currentCase.affected_account
                    }}</CardTitle>
                    <p class="text-muted-foreground text-sm">
                        {{
                            currentCase.owner_id
                                ? 'Assigned to Admin #' + currentCase.owner_id
                                : 'Not assigned yet'
                        }}
                    </p>
                </CardHeader>
                <CardContent class="space-y-4">
                    <p class="text-muted-foreground text-sm">
                        Closing this case does not unlock or change the account.
                        To unlock it, go to
                        <Link
                            :href="lockouts()"
                            class="text-foreground font-medium underline underline-offset-4"
                            >Lockouts</Link
                        >.
                    </p>
                    <MoreDetails>
                        <dl class="grid gap-4 text-sm sm:grid-cols-3">
                            <div>
                                <dt class="text-muted-foreground text-xs">
                                    Case reference
                                </dt>
                                <dd class="mt-1 font-mono break-all">
                                    {{ currentCase.case_reference }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground text-xs">
                                    Times opened
                                </dt>
                                <dd class="mt-1">{{ currentCase.episode }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground text-xs">
                                    Version
                                </dt>
                                <dd class="mt-1">{{ currentCase.version }}</dd>
                            </div>
                        </dl>
                    </MoreDetails>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>What started this case</CardTitle>
                    <p class="text-muted-foreground text-sm">
                        {{ source.event_type }} · {{ source.occurred_at }}
                    </p>
                </CardHeader>
                <CardContent>
                    <dl class="grid gap-4 sm:grid-cols-2">
                        <div
                            v-for="(value, field) in source.facts"
                            :key="field"
                        >
                            <dt class="text-muted-foreground text-xs">
                                {{ String(field).replaceAll('_', ' ') }}
                            </dt>
                            <dd class="mt-1 text-sm break-words">
                                {{ value }}
                            </dd>
                        </div>
                    </dl>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>History</CardTitle>
                </CardHeader>
                <CardContent>
                    <ol class="divide-border divide-y">
                        <li
                            v-for="entry in history"
                            :key="entry.version"
                            class="py-3"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <h3 class="text-sm font-medium capitalize">
                                    {{
                                        entry.event_type
                                            .replace('security.', '')
                                            .replaceAll('_', ' ')
                                    }}
                                </h3>
                                <Badge variant="outline">{{
                                    label(entry.facts.state)
                                }}</Badge>
                            </div>
                            <p class="text-muted-foreground mt-0.5 text-xs">
                                {{
                                    entry.actor_id
                                        ? 'Admin #' + entry.actor_id
                                        : 'System'
                                }}
                                · {{ entry.created_at }}
                            </p>
                            <p
                                v-if="entry.note"
                                class="mt-2 text-sm whitespace-pre-wrap"
                            >
                                {{ entry.note }}
                            </p>
                            <p
                                v-if="entry.evidence_references.length"
                                class="text-muted-foreground mt-2 text-xs break-all"
                            >
                                Linked records:
                                {{ entry.evidence_references.join(', ') }}
                            </p>
                        </li>
                    </ol>
                </CardContent>
            </Card>

            <FormSheet
                v-model:open="sheetOpen"
                :title="sheetTitles[form.action]"
                description="This is saved to the case history."
            >
                <form
                    id="case-update-form"
                    class="grid gap-5"
                    @submit.prevent="submit"
                >
                    <div v-if="form.action === 'assign'" class="grid gap-2">
                        <Label for="case-owner">Assign to</Label>
                        <Select
                            :model-value="
                                form.owner_id === ''
                                    ? QUEUE
                                    : String(form.owner_id)
                            "
                            @update:model-value="setOwner"
                        >
                            <SelectTrigger id="case-owner" class="w-full"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="QUEUE"
                                    >No one (back to the list)</SelectItem
                                >
                                <SelectItem
                                    v-for="owner in owners"
                                    :key="owner.id"
                                    :value="String(owner.id)"
                                >
                                    {{ owner.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div v-if="form.action === 'state'" class="grid gap-2">
                        <Label for="case-next-state">New status</Label>
                        <Select v-model="form.state">
                            <SelectTrigger id="case-next-state" class="w-full"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="state in states"
                                    :key="state"
                                    :value="state"
                                >
                                    {{ label(state) }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="case-note">{{
                            noteLabels[form.action]
                        }}</Label
                        ><textarea
                            id="case-note"
                            v-model="form.note"
                            class="bg-background focus-visible:ring-ring min-h-28 w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                            maxlength="2000"
                            :required="form.action !== 'assign'"
                            aria-describedby="case-note-help"
                        />
                        <p
                            id="case-note-help"
                            class="text-muted-foreground text-xs"
                        >
                            Never write passwords, codes or other secrets here.
                        </p>
                    </div>
                    <MoreDetails label="More options">
                        <div class="grid gap-2">
                            <Label for="case-evidence">Linked records</Label
                            ><Input
                                id="case-evidence"
                                v-model="evidence"
                                placeholder="lock:123"
                                aria-describedby="case-evidence-help"
                            />
                            <p
                                id="case-evidence-help"
                                class="text-muted-foreground text-xs"
                            >
                                Separate with commas. Activity log references
                                need log access.
                            </p>
                        </div>
                    </MoreDetails>
                    <div v-if="form.hasErrors" role="alert" class="space-y-1">
                        <p
                            v-for="(error, field) in form.errors"
                            :key="field"
                            class="text-destructive text-sm"
                        >
                            {{ error }}
                        </p>
                    </div>
                </form>
                <template #footer>
                    <Button
                        type="button"
                        variant="outline"
                        @click="sheetOpen = false"
                        >Cancel</Button
                    >
                    <Button
                        type="submit"
                        form="case-update-form"
                        :disabled="form.processing"
                        >Save</Button
                    >
                </template>
            </FormSheet>
        </template>
    </div>
</template>
