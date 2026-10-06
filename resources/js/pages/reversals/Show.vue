<script setup lang="ts">
import { Head, Link, useForm, useHttp } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { FileText } from '@lucide/vue';
import FormSheet from '@/components/FormSheet.vue';
import MoreDetails from '@/components/MoreDetails.vue';
import PageHeader from '@/components/PageHeader.vue';
import ReversalStatusBadge from '@/components/ReversalStatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { replacement } from '@/routes/reversals';
import {
    link as evidenceLink,
    store as storeEvidence,
} from '@/routes/reversals/evidence';
import { dashboard } from '@/routes';
import {
    index as reversalsIndex,
    cancel,
    reject,
    approve,
    reviewPreview,
} from '@/routes/reversals';

type Reversal = {
    id: string;
    customer_name: string | null;
    original_reference: string;
    original_amount_kobo: number;
    state: string;
    requested_at: string;
    reviewed_at: string | null;
    version: number;
    customer_explanation: string | null;
    internal_reason: string | null;
    evidence_text: string | null;
    dependency_snapshot: {
        summary: Record<string, unknown>;
        dependencies: Array<Record<string, unknown>>;
    } | null;
};

const props = defineProps<{
    reversal: Reversal;
    can_replace: boolean;
    can_review: boolean;
    can_cancel: boolean;
    can_approve: boolean;
    can_add_evidence: boolean;
    evidence_files: Array<{
        id: number;
        type: string;
        bytes: number;
        added_at: string;
    }>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Reversals', href: reversalsIndex() },
            { title: 'Request', href: '#' },
        ],
    },
});

const action = ref<'cancel' | 'reject' | 'approve' | null>(null);
const form = useForm({
    attempt_reference: crypto.randomUUID(),
    version: props.reversal.version,
    decision_reason: '',
    preview_fingerprint: '',
    confirmed: false,
});

const previewHttp = useHttp<
    Record<string, never>,
    {
        preview_fingerprint: string;
        gross_kobo: number;
        summary: Record<string, unknown>;
        dependencies: Record<string, unknown>[];
        request_version: number;
    }
>({});
const review = ref<{
    gross_kobo: number;
    summary: Record<string, unknown>;
    dependencies: Record<string, unknown>[];
} | null>(null);
const previewError = ref('');
async function reviewCompensation(): Promise<void> {
    previewError.value = '';
    try {
        const result = await previewHttp.get(
            reviewPreview.url(props.reversal.id),
        );
        choose('approve');
        form.preview_fingerprint = result.preview_fingerprint;
        form.version = result.request_version;
        review.value = result;
    } catch {
        review.value = null;
        previewError.value =
            'We could not check the full amount to correct. Sort out the linked records, then try again.';
    }
}
const evidenceOpen = ref(false);
const decisionOpen = computed({
    get: () => action.value !== null,
    set: (open: boolean) => {
        if (!open) action.value = null;
    },
});
const decisionCopy = {
    approve: {
        title: 'Approve this reversal?',
        description: 'The original payment will be corrected.',
        label: 'Reason for approving',
        button: 'Approve',
    },
    reject: {
        title: 'Reject this reversal?',
        description: 'The original payment stays as it is.',
        label: 'Reason for rejecting',
        button: 'Reject',
    },
    cancel: {
        title: 'Cancel this reversal?',
        description:
            'The request will be closed. The original payment stays as it is.',
        label: 'Reason for cancelling',
        button: 'Cancel request',
    },
} as const;
const evidenceForm = useForm({ files: [] as File[] });
const evidenceError = ref('');
function chooseEvidence(event: Event): void {
    const input = event.target as HTMLInputElement;
    evidenceForm.files = Array.from(input.files ?? []).slice(0, 3);
}
function addEvidence(): void {
    evidenceForm.post(storeEvidence.url(props.reversal.id), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            evidenceForm.reset();
            evidenceOpen.value = false;
        },
    });
}
async function openEvidence(file: number): Promise<void> {
    evidenceError.value = '';
    try {
        const response = await fetch(
            evidenceLink.url({ reversal: props.reversal.id, file }),
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            },
        );
        if (!response.ok) throw new Error('denied');
        window.location.assign((await response.json()).url);
    } catch {
        evidenceError.value = "This file isn't available to you.";
    }
}
const money = (kobo: number): string =>
    `₦${(kobo / 100).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

function choose(next: 'cancel' | 'reject' | 'approve'): void {
    action.value = next;
    form.reset();
    form.attempt_reference = crypto.randomUUID();
    form.version = props.reversal.version;
}

function submit(): void {
    if (!action.value || !form.confirmed) return;
    form.post(
        (action.value === 'cancel'
            ? cancel
            : action.value === 'approve'
              ? approve
              : reject
        ).url(props.reversal.id),
        {
            onSuccess: () => {
                action.value = null;
            },
        },
    );
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="`Reversal ${reversal.id}`" />
        <PageHeader
            :title="`Reversal ${reversal.id}`"
            :description="`${reversal.customer_name ?? 'Customer'} · for ${reversal.original_reference}`"
        >
            <template
                v-if="can_approve || can_review || can_cancel || can_replace"
                #actions
            >
                <Button
                    v-if="can_approve"
                    type="button"
                    :disabled="previewHttp.processing"
                    @click="reviewCompensation"
                    >Review and approve</Button
                >
                <Button
                    v-if="can_review"
                    type="button"
                    variant="outline"
                    @click="choose('reject')"
                    >Reject</Button
                >
                <Button
                    v-if="can_cancel"
                    type="button"
                    variant="outline"
                    @click="choose('cancel')"
                    >Cancel request</Button
                >
                <Button v-if="can_replace" variant="outline" as-child
                    ><Link :href="replacement.url(reversal.id)"
                        >Record replacement</Link
                    ></Button
                >
            </template>
        </PageHeader>
        <p
            v-if="!can_approve && can_review"
            class="text-muted-foreground -mt-2 text-sm"
        >
            You can approve once the full amount to correct has been checked.
        </p>
        <p v-if="previewError" role="alert" class="text-destructive text-sm">
            {{ previewError }}
        </p>

        <Card>
            <CardContent class="grid gap-5 text-sm">
                <div class="flex flex-wrap items-center gap-2">
                    <ReversalStatusBadge :state="reversal.state" />
                </div>
                <p
                    v-if="reversal.state === 'pending_review'"
                    class="text-muted-foreground -mt-2"
                >
                    Nothing has changed yet. The original payment still stands.
                </p>
                <p
                    v-if="reversal.state === 'approved_no_money'"
                    class="text-muted-foreground -mt-2"
                >
                    The full fee was already refunded, so no more money moved.
                    The earlier refund stays in place.
                </p>
                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-3">
                    <div>
                        <dt class="text-muted-foreground">Original amount</dt>
                        <dd class="text-lg font-semibold">
                            {{ money(reversal.original_amount_kobo) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Requested</dt>
                        <dd class="font-medium">{{ reversal.requested_at }}</dd>
                    </div>
                    <div v-if="reversal.reviewed_at">
                        <dt class="text-muted-foreground">Decided</dt>
                        <dd class="font-medium">{{ reversal.reviewed_at }}</dd>
                    </div>
                </dl>
                <p
                    v-if="reversal.customer_explanation"
                    class="bg-muted rounded-xl p-4"
                >
                    {{ reversal.customer_explanation }}
                </p>
            </CardContent>
        </Card>

        <Card
            v-if="
                reversal.internal_reason ||
                reversal.evidence_text ||
                evidence_files.length > 0 ||
                can_add_evidence
            "
        >
            <CardHeader
                class="flex flex-row flex-wrap items-center justify-between gap-3"
            >
                <CardTitle>Evidence</CardTitle>
                <Button
                    v-if="can_add_evidence"
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="evidenceOpen = true"
                    >Add files</Button
                >
            </CardHeader>
            <CardContent class="grid gap-4 text-sm">
                <div
                    v-if="reversal.internal_reason || reversal.evidence_text"
                    class="grid gap-2"
                >
                    <p v-if="reversal.internal_reason">
                        {{ reversal.internal_reason }}
                    </p>
                    <p v-if="reversal.evidence_text">
                        {{ reversal.evidence_text }}
                    </p>
                </div>
                <p
                    v-if="evidence_files.length === 0"
                    class="text-muted-foreground"
                >
                    No files attached.
                </p>
                <ul v-else class="divide-border divide-y">
                    <li
                        v-for="file in evidence_files"
                        :key="file.id"
                        class="flex flex-wrap items-center justify-between gap-3 py-2"
                    >
                        <span class="flex items-center gap-2">
                            <FileText class="text-muted-foreground size-4" />
                            File {{ file.id }}
                            <span class="text-muted-foreground"
                                >{{ file.type }} ·
                                {{ Math.ceil(file.bytes / 1024) }} KB</span
                            >
                        </span>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="openEvidence(file.id)"
                            >Open</Button
                        >
                    </li>
                </ul>
                <p v-if="evidenceError" role="alert" class="text-destructive">
                    {{ evidenceError }}
                </p>
            </CardContent>
        </Card>

        <FormSheet
            v-if="can_add_evidence"
            v-model:open="evidenceOpen"
            title="Add files"
            description="Add up to 3 files in total. Photos or PDFs."
        >
            <form
                id="reversal-evidence-form"
                class="grid gap-3"
                @submit.prevent="addEvidence"
            >
                <Label for="reversal-evidence-files">Files</Label>
                <input
                    id="reversal-evidence-files"
                    type="file"
                    multiple
                    accept="image/jpeg,image/png,image/webp,application/pdf"
                    class="border-input bg-background rounded-md border p-2 text-sm"
                    @change="chooseEvidence"
                />
                <div
                    v-if="Object.keys(evidenceForm.errors).length"
                    role="alert"
                    class="text-destructive grid gap-1 text-sm"
                >
                    <p v-for="(error, key) in evidenceForm.errors" :key="key">
                        {{ error }}
                    </p>
                </div>
            </form>
            <template #footer>
                <Button
                    type="button"
                    variant="outline"
                    @click="evidenceOpen = false"
                    >Cancel</Button
                >
                <Button
                    type="submit"
                    form="reversal-evidence-form"
                    :disabled="
                        evidenceForm.processing ||
                        evidenceForm.files.length === 0
                    "
                    >Add files</Button
                >
            </template>
        </FormSheet>

        <Dialog v-model:open="decisionOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-md">
                <form v-if="action" class="grid gap-5" @submit.prevent="submit">
                    <DialogHeader>
                        <DialogTitle>{{
                            decisionCopy[action].title
                        }}</DialogTitle>
                        <DialogDescription>{{
                            decisionCopy[action].description
                        }}</DialogDescription>
                    </DialogHeader>
                    <div
                        v-if="review && action === 'approve'"
                        class="grid gap-3 text-sm"
                    >
                        <div class="bg-muted/40 rounded-xl p-4">
                            <p class="text-muted-foreground">
                                Amount to correct
                            </p>
                            <p class="text-2xl font-semibold">
                                {{ money(review.gross_kobo) }}
                            </p>
                        </div>
                        <p v-if="review.summary.no_money === true">
                            The full fee was already refunded. Approving keeps
                            that refund and fixes the receipt history. No more
                            money moves.
                        </p>
                        <dl class="divide-border divide-y">
                            <div
                                v-if="
                                    typeof review.summary.controlled_kobo ===
                                    'number'
                                "
                                class="flex justify-between gap-3 py-2"
                            >
                                <dt class="text-muted-foreground">
                                    Available to replace
                                </dt>
                                <dd class="font-medium">
                                    {{ money(review.summary.controlled_kobo) }}
                                </dd>
                            </div>
                            <div
                                v-if="
                                    typeof review.summary
                                        .consumed_external_concession_kobo ===
                                        'number' &&
                                    review.summary
                                        .consumed_external_concession_kobo > 0
                                "
                                class="flex justify-between gap-3 py-2"
                            >
                                <dt class="text-muted-foreground">
                                    Fee refund kept
                                </dt>
                                <dd class="font-medium">
                                    {{
                                        money(
                                            review.summary
                                                .consumed_external_concession_kobo,
                                        )
                                    }}
                                </dd>
                            </div>
                        </dl>
                        <MoreDetails label="Linked records">
                            <pre
                                class="bg-muted/40 overflow-auto rounded-xl p-3 text-xs whitespace-pre-wrap"
                                >{{
                                    JSON.stringify(review.dependencies, null, 2)
                                }}</pre>
                        </MoreDetails>
                    </div>
                    <div class="grid gap-2">
                        <Label for="reversal-decision-reason">{{
                            decisionCopy[action].label
                        }}</Label>
                        <Input
                            id="reversal-decision-reason"
                            v-model="form.decision_reason"
                            required
                            maxlength="500"
                        />
                        <div
                            v-if="Object.keys(form.errors).length"
                            role="alert"
                            class="text-destructive grid gap-1 text-sm"
                        >
                            <p v-for="(error, key) in form.errors" :key="key">
                                {{ error }}
                            </p>
                        </div>
                    </div>
                    <label class="flex items-center gap-3 text-sm"
                        ><input v-model="form.confirmed" type="checkbox" /> I
                        confirm this decision.</label
                    >
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="decisionOpen = false"
                            >Go back</Button
                        >
                        <Button
                            type="submit"
                            :variant="
                                action === 'approve' ? 'default' : 'destructive'
                            "
                            :disabled="form.processing || !form.confirmed"
                            >{{ decisionCopy[action].button }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
