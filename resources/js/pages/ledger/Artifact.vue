<script setup lang="ts">
import { Head, Link, useForm, usePoll } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
usePoll(5000, { only: ['artifact'] });
function cancelDocument(): void {
    cancellation.post(cancel.url(props.artifact.artifact_reference));
}
</script>
<template>
    <Head title="Financial document" />
    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">
                {{
                    artifact.kind === 'statement'
                        ? 'Customer statement'
                        : 'Report export'
                }}
            </h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                {{ artifact.artifact_reference }} ·
                {{ artifact.format.toUpperCase() }}
            </p>
        </div>
        <Card
            ><CardContent class="grid gap-4 pt-6 text-sm">
                <p role="status">{{ artifact.status.replaceAll('_', ' ') }}</p>
                <p v-if="artifact.superseded_by">
                    Superseded by
                    <Link
                        class="underline"
                        :href="show(artifact.superseded_by)"
                        >{{ artifact.superseded_by }}</Link
                    >.
                </p>
                <p v-if="artifact.manifest.supersedes_reference">
                    Supersedes
                    <Link
                        class="underline"
                        :href="show(artifact.manifest.supersedes_reference)"
                        >{{ artifact.manifest.supersedes_reference }}</Link
                    >. The earlier issued document remains preserved.
                </p>
                <p>
                    Captured {{ artifact.manifest.captured_at }} for
                    {{ artifact.manifest.business_name }}
                </p>
                <p v-if="artifact.expires_at">
                    Download expires {{ artifact.expires_at }}.
                </p>
                <p v-if="artifact.failure_code">
                    Generation failed. The original snapshot and operation
                    identity are retained.
                </p>
                <a
                    v-if="artifact.download_url"
                    :href="artifact.download_url"
                    class="text-primary w-fit underline"
                    >Download {{ artifact.format.toUpperCase() }}</a
                >
                <form
                    v-if="artifact.status === 'queued' && artifact.can_cancel"
                    class="grid gap-3"
                    @submit.prevent="cancelDocument"
                >
                    <label class="flex gap-3"
                        ><input
                            v-model="cancellation.confirmed"
                            type="checkbox"
                        />Cancel this queued document.</label
                    ><Button
                        class="w-fit"
                        variant="outline"
                        :disabled="
                            cancellation.processing || !cancellation.confirmed
                        "
                        >Cancel generation</Button
                    >
                </form>
                <form
                    v-if="artifact.can_retry"
                    class="grid gap-3"
                    @submit.prevent="
                        retryForm.post(retry.url(artifact.artifact_reference))
                    "
                >
                    <label class="flex gap-3"
                        ><input
                            v-model="retryForm.confirmed"
                            type="checkbox"
                        />Retry generation from the same captured
                        snapshot.</label
                    >
                    <Button
                        class="w-fit"
                        :disabled="retryForm.processing || !retryForm.confirmed"
                        >Retry generation</Button
                    >
                    <p
                        v-for="(error, key) in retryForm.errors"
                        :key="key"
                        class="text-destructive"
                    >
                        {{ error }}
                    </p>
                </form>
                <p v-if="artifact.held">
                    A retention hold preserves this report file after its
                    download expiry.
                </p>
                <form
                    v-if="artifact.can_hold"
                    class="grid gap-3"
                    @submit.prevent="
                        retention.held = !artifact.held;
                        retention.post(hold.url(artifact.artifact_reference), {
                            onSuccess: () => {
                                retention.confirmed = false;
                            },
                        });
                    "
                >
                    <label class="grid gap-1"
                        >Retention reason<textarea
                            v-model="retention.reason"
                            maxlength="500"
                            required
                            class="rounded-md border p-2"
                        />
                    </label>
                    <label class="flex gap-3"
                        ><input
                            v-model="retention.confirmed"
                            type="checkbox"
                        />Confirm
                        {{ artifact.held ? 'release' : 'application' }} of the
                        retention hold.</label
                    >
                    <Button
                        class="w-fit"
                        variant="outline"
                        :disabled="retention.processing || !retention.confirmed"
                        >{{
                            artifact.held ? 'Release hold' : 'Apply hold'
                        }}</Button
                    >
                    <p
                        v-for="(error, key) in retention.errors"
                        :key="key"
                        class="text-destructive"
                    >
                        {{ error }}
                    </p>
                </form>
                <p
                    v-for="(error, key) in cancellation.errors"
                    :key="key"
                    class="text-destructive"
                >
                    {{ error }}
                </p>
            </CardContent></Card
        >
    </div>
</template>
