<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { useProtectedWorkspace } from '@/composables/useProtectedWorkspace';
import { dashboard } from '@/routes';
import { index, show } from '@/routes/admin/audit';
import type { AuditDetail } from '@/types/audit';
const props = defineProps<{ event: AuditDetail; scope: string }>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Audit trail', href: index() },
            { title: 'Event detail' },
        ],
    },
});
const { visible, notice, refresh } = useProtectedWorkspace(
    () => props.scope,
    'audit.view',
);
const copied = ref('');
async function copyReference(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.event.summary.event_id);
        copied.value = 'Event reference copied.';
    } catch {
        copied.value = 'Copy is unavailable. Select the reference below.';
    }
}
function format(value: unknown): string {
    return value === null
        ? 'Unavailable'
        : typeof value === 'object'
          ? JSON.stringify(value)
          : String(value);
}
</script>
<template>
    <div class="space-y-6">
        <Head title="Audit event" />
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-[25px] font-medium tracking-tight">
                    Audit event
                </h1>
                <p class="text-muted-foreground mt-1.5 text-sm">
                    Masked canonical evidence and related references.
                </p>
            </div>
            <Button variant="outline" @click="refresh">Refresh</Button>
        </header>
        <p v-if="notice" role="alert">{{ notice }}</p>
        <template v-if="visible">
            <section class="space-y-4 rounded-xl border p-5">
                <div class="flex flex-wrap gap-2">
                    <Badge variant="outline">{{ event.summary.outcome }}</Badge
                    ><Badge variant="secondary">{{
                        event.summary.severity
                    }}</Badge
                    ><Badge
                        v-if="event.summary.legacy_evidence"
                        variant="secondary"
                        >Legacy evidence</Badge
                    >
                </div>
                <h2 class="text-lg font-medium">
                    {{ event.summary.event_type }}
                </h2>
                <p class="font-mono text-sm break-all">
                    {{ event.summary.event_id }}
                </p>
                <Button variant="outline" @click="copyReference"
                    >Copy event reference</Button
                >
                <p role="status" class="text-sm">{{ copied }}</p>
                <p class="text-muted-foreground text-sm">
                    {{ event.content_check }}
                </p>
            </section>
            <dl class="grid gap-5 rounded-xl border p-5 sm:grid-cols-2">
                <div
                    v-for="(value, label) in {
                        Actor:
                            event.content.actor_type +
                            ' #' +
                            (event.content.actor_id || 'unknown'),
                        Approver: event.content.approver_id || 'Unavailable',
                        Executor: event.content.executor,
                        'Occurred (UTC)': event.content.occurred_at,
                        'Recorded (UTC)': event.content.recorded_at,
                        Permission: event.summary.legacy_evidence
                            ? 'Unavailable (legacy evidence)'
                            : event.content.authority.required_permission ||
                              'Owner workflow',
                        'Permission version':
                            event.content.authority.permission_version ??
                            'Unavailable',
                        'Authority evidence': event.content.authority.evidence,
                        'Fresh authentication':
                            event.content.authority.fresh_authentication ===
                            null
                                ? 'Unavailable'
                                : event.content.authority.fresh_authentication
                                  ? 'Confirmed'
                                  : 'Not confirmed',
                        'Source version': event.content.source_version,
                        'Retention class': event.content.retention_class,
                    }"
                    :key="label"
                >
                    <dt class="text-muted-foreground text-xs">{{ label }}</dt>
                    <dd class="mt-1 text-sm break-words">{{ value }}</dd>
                </div>
            </dl>
            <section class="space-y-4 rounded-xl border p-5">
                <h2 class="font-medium">Safe changes</h2>
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div
                        v-for="(value, field) in event.content.safe_changes"
                        :key="field"
                    >
                        <dt class="text-muted-foreground text-xs">
                            {{ field }}
                        </dt>
                        <dd class="mt-1 text-sm break-words">
                            {{ format(value) }}
                        </dd>
                    </div>
                </dl>
                <p
                    v-if="!Object.keys(event.content.safe_changes).length"
                    class="text-muted-foreground text-sm"
                >
                    No safe changes recorded.
                </p>
                <p
                    v-if="event.content.protected_fields.length"
                    class="text-muted-foreground text-sm"
                >
                    Protected values are masked:
                    {{ event.content.protected_fields.join(', ') }}. Reveal is
                    unavailable.
                </p>
                <Link
                    v-if="event.owner_link"
                    :href="event.owner_link"
                    class="text-sm underline underline-offset-4"
                    >Open current owner record</Link
                >
            </section>
            <section
                v-if="event.related.length"
                class="space-y-3 rounded-xl border p-5"
            >
                <h2 class="font-medium">Related events</h2>
                <ol class="space-y-3">
                    <li
                        v-for="related in event.related"
                        :key="related.event_id"
                    >
                        <Link
                            :href="show(related.event_id)"
                            class="text-sm underline"
                            >{{ related.event_type }}</Link
                        ><span class="text-muted-foreground ml-2 text-xs">{{
                            related.recorded_at
                        }}</span>
                    </li>
                </ol>
            </section>
        </template>
    </div>
</template>
