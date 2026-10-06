<script setup lang="ts">
import { Head, Link, useForm, usePoll } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Download } from '@lucide/vue';
import FormSheet from '@/components/FormSheet.vue';
import MoreDetails from '@/components/MoreDetails.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { cancel, show, retry, hold } from '@/routes/financial-artifacts';
const props = defineProps<{
    artifact: {
        artifact_reference: string;
        kind: string;
        format: string;
        status: string;
        issued_at: string | null;
        expires_at: string | null;
        failure_code: string | null;
        download_url: string | null;
        superseded_by: string | null;
        held: boolean;
        can_cancel: boolean;
        can_retry: boolean;
        can_hold: boolean;
        manifest: {
            captured_at: string;
            snapshot_hash: string;
            business_name: string;
            supersedes_reference: string | null;
        };
    };
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Financial document' },
        ],
    },
});
const cancellation = useForm({ confirmed: false });
const retryForm = useForm({ confirmed: false });
const retention = useForm({
    held: !props.artifact.held,
    reason: '',
    confirmed: false,
});
const cancelOpen = ref(false);
const retryOpen = ref(false);
const holdOpen = ref(false);
usePoll(5000, { only: ['artifact'] });

const statusLabels: Record<string, string> = {
    queued: 'Waiting to start',
    running: 'Being prepared',
    ready: 'Ready',
    failed: 'Failed',
    cancelled: 'Cancelled',
    expired: 'Expired',
};
const statusMessages: Record<string, string> = {
    queued: 'Your file is in line. This page updates on its own.',
    running: 'Your file is being prepared. This page updates on its own.',
    ready: 'Your file is ready to download.',
    failed: 'We could not create this file. Your request and data are kept.',
    cancelled: 'This file was cancelled.',
    expired: 'The download link has expired.',
};
const statusLabel = computed(
    () =>
        statusLabels[props.artifact.status] ??
        props.artifact.status.replaceAll('_', ' '),
);

function cancelDocument(): void {
    cancellation.confirmed = true;
    cancellation.post(cancel.url(props.artifact.artifact_reference), {
        onFinish: () => {
            cancelOpen.value = false;
        },
    });
}
function retryDocument(): void {
    retryForm.confirmed = true;
    retryForm.post(retry.url(props.artifact.artifact_reference), {
        onFinish: () => {
            retryOpen.value = false;
        },
    });
}
function submitHold(): void {
    retention.held = !props.artifact.held;
    retention.post(hold.url(props.artifact.artifact_reference), {
        onSuccess: () => {
            retention.confirmed = false;
            holdOpen.value = false;
        },
    });
}
</script>
<template>
    <Head title="Financial document" />
    <div class="flex flex-col gap-6">
        <PageHeader
            :title="
                artifact.kind === 'statement'
                    ? 'Customer statement'
                    : 'Report export'
            "
            :description="`${artifact.format.toUpperCase()} file for ${artifact.manifest.business_name}.`"
        >
            <template #actions>
                <Button v-if="artifact.download_url" as-child
                    ><a :href="artifact.download_url"
                        ><Download class="size-4" />Download
                        {{ artifact.format.toUpperCase() }}</a
                    ></Button
                >
                <Button v-if="artifact.can_retry" @click="retryOpen = true"
                    >Try again</Button
                >
                <Button
                    v-if="artifact.status === 'queued' && artifact.can_cancel"
                    variant="outline"
                    @click="cancelOpen = true"
                    >Cancel</Button
                >
            </template>
        </PageHeader>

        <Card>
            <CardContent class="space-y-5 text-sm">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p role="status">
                        {{
                            statusMessages[artifact.status] ??
                            `Status: ${statusLabel}`
                        }}
                    </p>
                    <Badge variant="secondary">{{ statusLabel }}</Badge>
                </div>

                <dl class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <dt class="text-muted-foreground">Data from</dt>
                        <dd class="mt-0.5 font-medium">
                            {{ artifact.manifest.captured_at }}
                        </dd>
                    </div>
                    <div v-if="artifact.expires_at">
                        <dt class="text-muted-foreground">Link expires</dt>
                        <dd class="mt-0.5 font-medium">
                            {{ artifact.expires_at }}
                        </dd>
                    </div>
                    <div v-if="artifact.held">
                        <dt class="text-muted-foreground">Kept</dt>
                        <dd class="mt-0.5 font-medium">
                            Yes, after the link expires
                        </dd>
                    </div>
                </dl>

                <div
                    v-if="
                        artifact.superseded_by ||
                        artifact.manifest.supersedes_reference
                    "
                    class="bg-muted/40 grid gap-1 rounded-xl p-4"
                >
                    <p v-if="artifact.superseded_by">
                        A newer version is available:
                        <Link
                            class="font-medium underline underline-offset-4"
                            :href="show(artifact.superseded_by)"
                            >{{ artifact.superseded_by }}</Link
                        >.
                    </p>
                    <p v-if="artifact.manifest.supersedes_reference">
                        This replaces
                        <Link
                            class="font-medium underline underline-offset-4"
                            :href="show(artifact.manifest.supersedes_reference)"
                            >{{ artifact.manifest.supersedes_reference }}</Link
                        >. The earlier file is still kept.
                    </p>
                </div>

                <div
                    v-for="(error, key) in {
                        ...cancellation.errors,
                        ...retryForm.errors,
                    }"
                    :key="key"
                    role="alert"
                    class="text-destructive"
                >
                    {{ error }}
                </div>

                <Button
                    v-if="artifact.can_hold"
                    variant="outline"
                    size="sm"
                    @click="holdOpen = true"
                    >{{
                        artifact.held ? 'Stop keeping file' : 'Keep file'
                    }}</Button
                >

                <MoreDetails>
                    <dl
                        class="text-muted-foreground grid gap-3 text-xs sm:grid-cols-2"
                    >
                        <div>
                            <dt class="text-foreground">Reference</dt>
                            <dd class="break-all">
                                {{ artifact.artifact_reference }}
                            </dd>
                        </div>
                        <div v-if="artifact.issued_at">
                            <dt class="text-foreground">Created</dt>
                            <dd>{{ artifact.issued_at }}</dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="text-foreground">Data fingerprint</dt>
                            <dd class="break-all">
                                {{ artifact.manifest.snapshot_hash }}
                            </dd>
                        </div>
                        <div v-if="artifact.failure_code">
                            <dt class="text-foreground">Error code</dt>
                            <dd>{{ artifact.failure_code }}</dd>
                        </div>
                    </dl>
                </MoreDetails>
            </CardContent>
        </Card>
    </div>

    <Dialog v-model:open="cancelOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Cancel this file?</DialogTitle>
                <DialogDescription
                    >It has not started yet. You can request a new one
                    later.</DialogDescription
                >
            </DialogHeader>
            <DialogFooter>
                <Button
                    variant="outline"
                    :disabled="cancellation.processing"
                    @click="cancelOpen = false"
                    >Keep it</Button
                >
                <Button
                    variant="destructive"
                    :disabled="cancellation.processing"
                    @click="cancelDocument"
                    >Cancel file</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="retryOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Try again?</DialogTitle>
                <DialogDescription
                    >We will create the file again using the same saved
                    data.</DialogDescription
                >
            </DialogHeader>
            <DialogFooter>
                <Button
                    variant="outline"
                    :disabled="retryForm.processing"
                    @click="retryOpen = false"
                    >Not now</Button
                >
                <Button :disabled="retryForm.processing" @click="retryDocument"
                    >Try again</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <FormSheet
        v-if="artifact.can_hold"
        v-model:open="holdOpen"
        :title="artifact.held ? 'Stop keeping this file' : 'Keep this file'"
        :description="
            artifact.held
                ? 'The file will be removed when its link expires.'
                : 'The file stays stored after its download link expires.'
        "
    >
        <form
            id="retention-form"
            class="grid gap-5"
            @submit.prevent="submitHold"
        >
            <div class="grid gap-2">
                <Label for="retention-reason">Reason</Label>
                <textarea
                    id="retention-reason"
                    v-model="retention.reason"
                    maxlength="500"
                    required
                    class="border-input bg-background focus-visible:ring-ring/30 min-h-24 w-full rounded-xl border px-3 py-2 text-sm shadow-sm outline-none focus-visible:ring-2"
                />
            </div>
            <div class="bg-muted/40 flex items-start gap-3 rounded-xl p-4">
                <Checkbox
                    id="retention-confirmed"
                    v-model="retention.confirmed"
                />
                <Label for="retention-confirmed" class="leading-5">{{
                    artifact.held
                        ? 'Yes, stop keeping this file'
                        : 'Yes, keep this file'
                }}</Label>
            </div>
            <p
                v-for="(error, key) in retention.errors"
                :key="key"
                role="alert"
                class="text-destructive text-sm"
            >
                {{ error }}
            </p>
        </form>
        <template #footer>
            <Button
                type="button"
                variant="outline"
                :disabled="retention.processing"
                @click="holdOpen = false"
                >Cancel</Button
            >
            <Button
                type="submit"
                form="retention-form"
                :disabled="retention.processing || !retention.confirmed"
                >{{ artifact.held ? 'Stop keeping' : 'Keep file' }}</Button
            >
        </template>
    </FormSheet>
</template>
