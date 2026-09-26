<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
            { title: 'Security operations', href: index() },
            { title: 'Case detail' },
        ],
    },
});
const { visible, notice, refresh, clear } = useProtectedWorkspace(
    () => props.scope,
    'security.operations.manage',
);
const evidence = ref('');
const form = useForm({
    expected_version: props.case.version,
    action: 'note',
    owner_id: '',
    state: 'Investigating',
    note: '',
    evidence_references: [] as string[],
});
watch(
    () => props.case.version,
    (version) => {
        form.expected_version = version;
    },
);
const currentCase = computed(() => props.case);
watch(visible, (value) => {
    if (!value) {
        form.reset();
        evidence.value = '';
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
watch(
    states,
    (values) => {
        form.state = values[0] ?? '';
    },
    { immediate: true },
);
function submit(): void {
    form.evidence_references = evidence.value
        .split(',')
        .map((value) => value.trim())
        .filter(Boolean);
    form.patch(update.url(props.case.case_reference), {
        preserveScroll: true,
        onSuccess: () => form.reset('note'),
        onHttpException: clear,
        onNetworkError: clear,
    });
}
function label(state: string): string {
    return state === 'ClosedNoAction' ? 'Closed — no action' : state;
}
</script>
<template>
    <div class="space-y-6">
        <Head title="Security case" />
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-[25px] font-medium tracking-tight">
                    Security case
                </h1>
                <p class="text-muted-foreground mt-1.5 text-sm">
                    Review facts, retain investigation history and coordinate
                    the next step.
                </p>
            </div>
            <Button variant="outline" @click="refresh">Refresh</Button>
        </header>
        <p v-if="notice" role="alert">{{ notice }}</p>
        <template v-if="visible">
            <section class="space-y-3 rounded-xl border p-5">
                <div class="flex flex-wrap gap-2">
                    <Badge variant="secondary">{{ currentCase.severity }}</Badge
                    ><Badge variant="outline">{{
                        label(currentCase.state)
                    }}</Badge>
                </div>
                <h2 class="font-mono text-sm break-all">
                    {{ currentCase.case_reference }}
                </h2>
                <p class="text-sm">
                    {{ currentCase.affected_account }} · Episode
                    {{ currentCase.episode }} · Version
                    {{ currentCase.version }}
                </p>
                <p class="text-muted-foreground text-sm">
                    {{
                        currentCase.owner_id
                            ? 'Owned by Admin #' + currentCase.owner_id
                            : 'Unassigned — eligible queue'
                    }}
                </p>
                <Link :href="lockouts()" class="text-sm underline"
                    >Review Authentication-owned restrictions</Link
                >
                <p class="text-muted-foreground text-sm">
                    Closing this case does not unlock, activate or suspend an
                    account.
                </p>
            </section>
            <section class="space-y-3 rounded-xl border p-5">
                <h2 class="font-medium">Classified source signal</h2>
                <p class="text-sm">
                    {{ source.event_type }} · {{ source.occurred_at }}
                </p>
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div v-for="(value, field) in source.facts" :key="field">
                        <dt class="text-muted-foreground text-xs">
                            {{ field.replaceAll('_', ' ') }}
                        </dt>
                        <dd class="mt-1 text-sm">{{ value }}</dd>
                    </div>
                </dl>
            </section>
            <form
                class="space-y-4 rounded-xl border p-5"
                @submit.prevent="submit"
            >
                <h2 class="font-medium">Update investigation</h2>
                <div class="flex flex-row flex-wrap gap-4">
                    <div class="w-fit space-y-1.5">
                        <Label for="case-action">Action</Label
                        ><select
                            id="case-action"
                            v-model="form.action"
                            class="bg-background h-11 rounded-md border px-3"
                        >
                            <option value="note">Add protected note</option>
                            <option value="assign">Assign owner</option>
                            <option v-if="states.length" value="state">
                                Change state
                            </option>
                            <option
                                v-if="
                                    ['Resolved', 'ClosedNoAction'].includes(
                                        currentCase.state,
                                    )
                                "
                                value="reopen"
                            >
                                Reopen investigation
                            </option>
                        </select>
                    </div>
                    <div
                        v-if="form.action === 'assign'"
                        class="w-fit space-y-1.5"
                    >
                        <Label for="case-owner">Eligible owner</Label
                        ><select
                            id="case-owner"
                            v-model="form.owner_id"
                            class="bg-background h-11 rounded-md border px-3"
                        >
                            <option value="">Return to queue</option>
                            <option
                                v-for="owner in owners"
                                :key="owner.id"
                                :value="owner.id"
                            >
                                {{ owner.label }}
                            </option>
                        </select>
                    </div>
                    <div
                        v-if="form.action === 'state'"
                        class="w-fit space-y-1.5"
                    >
                        <Label for="case-next-state">Next state</Label
                        ><select
                            id="case-next-state"
                            v-model="form.state"
                            class="bg-background h-11 rounded-md border px-3"
                        >
                            <option
                                v-for="state in states"
                                :key="state"
                                :value="state"
                            >
                                {{ label(state) }}
                            </option>
                        </select>
                    </div>
                </div>
                <div class="space-y-1.5">
                    <Label for="case-note"
                        >Protected note / closure reason</Label
                    ><textarea
                        class="bg-background focus-visible:ring-ring min-h-28 w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2"
                        id="case-note"
                        v-model="form.note"
                        maxlength="2000"
                        :required="form.action !== 'assign'"
                        aria-describedby="case-note-help"
                    />
                    <p
                        id="case-note-help"
                        class="text-muted-foreground text-xs"
                    >
                        For authorized investigation only. Do not enter
                        credentials, recovery codes or tokens.
                    </p>
                </div>
                <div class="space-y-1.5">
                    <Label for="case-evidence"
                        >Evidence references (optional)</Label
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
                        Separate references with commas. Audit references also
                        require audit access.
                    </p>
                </div>
                <p
                    v-for="(error, field) in form.errors"
                    :key="field"
                    role="alert"
                    class="text-destructive text-sm"
                >
                    {{ error }}
                </p>
                <Button type="submit" :disabled="form.processing"
                    >Save case update</Button
                >
            </form>
            <section class="space-y-4 rounded-xl border p-5">
                <h2 class="font-medium">Investigation history</h2>
                <ol class="space-y-5">
                    <li
                        v-for="entry in history"
                        :key="entry.version"
                        class="border-l-2 pl-4"
                    >
                        <h3 class="text-sm font-medium">
                            {{
                                entry.event_type
                                    .replace('security.', '')
                                    .replaceAll('_', ' ')
                            }}
                        </h3>
                        <p class="text-muted-foreground mt-1 text-xs">
                            {{ entry.created_at }} · Version
                            {{ entry.version }} ·
                            {{
                                entry.actor_id
                                    ? 'Admin #' + entry.actor_id
                                    : 'Trusted service'
                            }}
                            · {{ label(entry.facts.state) }}
                        </p>
                        <p
                            v-if="entry.note"
                            class="mt-2 text-sm whitespace-pre-wrap"
                        >
                            {{ entry.note }}
                        </p>
                        <p
                            v-if="entry.evidence_references.length"
                            class="mt-2 text-xs"
                        >
                            Evidence references:
                            {{ entry.evidence_references.join(', ') }}
                        </p>
                    </li>
                </ol>
            </section>
        </template>
    </div>
</template>
