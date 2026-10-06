<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { ArrowRight, Copy, RefreshCw } from '@lucide/vue';
import MoreDetails from '@/components/MoreDetails.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useProtectedWorkspace } from '@/composables/useProtectedWorkspace';
import { dashboard } from '@/routes';
import { index, show } from '@/routes/admin/audit';
import type { AuditDetail } from '@/types/audit';
const props = defineProps<{ event: AuditDetail; scope: string }>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Activity log', href: index() },
            { title: 'Event details' },
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
        copied.value = 'Reference copied.';
    } catch {
        copied.value =
            'Could not copy. Select the reference under More details.';
    }
}
function format(value: unknown): string {
    return value === null
        ? 'Unavailable'
        : typeof value === 'object'
          ? JSON.stringify(value)
          : String(value);
}
const mainFacts = computed(() => ({
    'Done by':
        props.event.content.actor_type +
        ' #' +
        (props.event.content.actor_id || 'unknown'),
    'Approved by': props.event.content.approver_id || '-',
    'Carried out by': props.event.content.executor,
    'Happened (UTC)': props.event.content.occurred_at,
    'Saved (UTC)': props.event.content.recorded_at,
}));
const technicalFacts = computed(() => ({
    'Event reference': props.event.summary.event_id,
    'Permission needed': props.event.summary.legacy_evidence
        ? 'Unavailable (older record)'
        : props.event.content.authority.required_permission || 'Owner action',
    'Permission version':
        props.event.content.authority.permission_version ?? 'Unavailable',
    'Access check': props.event.content.authority.evidence,
    'Recent sign-in check':
        props.event.content.authority.fresh_authentication === null
            ? 'Unavailable'
            : props.event.content.authority.fresh_authentication
              ? 'Confirmed'
              : 'Not confirmed',
    'App area': props.event.content.source_module,
    'Source version': props.event.content.source_version,
    'Retention group': props.event.content.retention_class,
}));
</script>
<template>
    <div class="space-y-6">
        <Head title="Event details" />
        <PageHeader
            title="Event details"
            description="What happened, who did it, and what changed."
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
            <Card>
                <CardHeader
                    class="flex flex-row flex-wrap items-start justify-between gap-3"
                >
                    <div class="space-y-2">
                        <CardTitle class="text-lg">{{
                            event.summary.event_type
                        }}</CardTitle>
                        <div class="flex flex-wrap gap-2">
                            <Badge variant="outline">{{
                                event.summary.outcome
                            }}</Badge
                            ><Badge variant="secondary">{{
                                event.summary.severity
                            }}</Badge
                            ><Badge
                                v-if="event.summary.legacy_evidence"
                                variant="secondary"
                                >Older record</Badge
                            >
                        </div>
                    </div>
                    <Button variant="outline" size="sm" @click="copyReference">
                        <Copy class="size-4" />
                        Copy reference
                    </Button>
                </CardHeader>
                <CardContent class="space-y-5">
                    <p
                        v-if="copied"
                        role="status"
                        class="text-muted-foreground text-sm"
                    >
                        {{ copied }}
                    </p>
                    <dl class="grid gap-5 sm:grid-cols-2">
                        <div v-for="(value, label) in mainFacts" :key="label">
                            <dt class="text-muted-foreground text-xs">
                                {{ label }}
                            </dt>
                            <dd class="mt-1 text-sm break-words">
                                {{ value }}
                            </dd>
                        </div>
                    </dl>
                    <Link
                        v-if="event.owner_link"
                        :href="event.owner_link"
                        class="inline-flex items-center gap-1 text-sm font-medium underline-offset-4 hover:underline"
                        >Open the record <ArrowRight class="size-4"
                    /></Link>
                    <MoreDetails>
                        <div class="space-y-4">
                            <dl class="grid gap-4 sm:grid-cols-2">
                                <div
                                    v-for="(value, label) in technicalFacts"
                                    :key="label"
                                >
                                    <dt class="text-muted-foreground text-xs">
                                        {{ label }}
                                    </dt>
                                    <dd
                                        class="mt-1 text-sm break-words"
                                        :class="
                                            label === 'Event reference'
                                                ? 'font-mono'
                                                : ''
                                        "
                                    >
                                        {{ value }}
                                    </dd>
                                </div>
                            </dl>
                            <p class="text-muted-foreground text-xs">
                                {{ event.content_check }}
                            </p>
                        </div>
                    </MoreDetails>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>What changed</CardTitle>
                </CardHeader>
                <CardContent class="space-y-4">
                    <dl
                        v-if="Object.keys(event.content.safe_changes).length"
                        class="divide-border divide-y"
                    >
                        <div
                            v-for="(value, field) in event.content.safe_changes"
                            :key="field"
                            class="grid gap-1 py-2 sm:grid-cols-[12rem_1fr] sm:gap-4"
                        >
                            <dt class="text-muted-foreground text-xs sm:pt-0.5">
                                {{ String(field).replaceAll('_', ' ') }}
                            </dt>
                            <dd class="text-sm break-words">
                                {{ format(value) }}
                            </dd>
                        </div>
                    </dl>
                    <p v-else class="text-muted-foreground text-sm">
                        No changes to show.
                    </p>
                    <p
                        v-if="event.content.protected_fields.length"
                        class="text-muted-foreground text-xs"
                    >
                        Hidden for privacy:
                        {{ event.content.protected_fields.join(', ') }}.
                    </p>
                </CardContent>
            </Card>

            <Card v-if="event.related.length">
                <CardHeader>
                    <CardTitle>Related events</CardTitle>
                </CardHeader>
                <CardContent>
                    <ol class="divide-border divide-y">
                        <li
                            v-for="related in event.related"
                            :key="related.event_id"
                            class="flex flex-wrap items-center justify-between gap-2 py-2"
                        >
                            <Link
                                :href="show(related.event_id)"
                                class="text-sm font-medium underline-offset-4 hover:underline"
                                >{{ related.event_type }}</Link
                            ><span class="text-muted-foreground text-xs">{{
                                related.recorded_at
                            }}</span>
                        </li>
                    </ol>
                </CardContent>
            </Card>
        </template>
    </div>
</template>
