<script setup lang="ts">
import { HttpResponseError } from '@inertiajs/core';
import { Link, router, useHttp, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    isOperationReference,
    newOperationReference,
} from '@/lib/operation-reference';
import { freshAuthentication } from '@/routes';
import {
    prepare as prepareAttempt,
    cancel as cancelAttempt,
    status as attemptStatus,
} from '@/routes/admin/fees/obligations/attempts';
import {
    applySavings,
    savingsPreview,
    savingsSources,
    savingsStatus,
} from '@/routes/admin/fees/obligations';

type Selection = { id: number; customer_name: string; rule_name: string };
type Instructions = {
    plan_id: string;
    reason: string;
    customer_description: string;
};
type Review = {
    plan_id: string;
    occurred_on: string;
    business_timezone: string;
    quote_expires_at: string;
    preview_fingerprint: string;
    display: {
        liability: string;
        reservations: string;
        available: string;
        fee: string;
        remaining: string;
        available_after: string;
    };
};
type Commit = Instructions & {
    attempt_reference: string;
    confirmed: boolean;
    preview_fingerprint: string;
    quote_expires_at: string;
};
type Outcome = { status: 'posted'; posting_reference: string };
type AttemptResult = {
    status: 'prepared' | 'cancelled' | 'recorded';
    operation: 'apply_savings';
    attempt_reference: string;
    obligation_id: number;
    posting_reference?: string;
};
type AttemptInstructions = {
    operation: 'apply_savings';
    attempt_reference: string;
    payload?: Commit;
};
type Attempt = { obligation: number; reference: string };
type SavedAttempt = Attempt & {
    schema_version: 1;
    actor_id: number;
    commit: Commit;
};

const props = defineProps<{ obligation: Selection | null }>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ pending: [value: boolean] }>();
const page = usePage();
const actorId = page.props.auth.user.id;
const storageKey = `fee-savings-attempt-${actorId}`;
const sources = ref<{ plan_id: string; name: string; status: string }[]>([]);
const activeSelection = ref<Selection | null>(null);
const attempt = ref<Attempt | null>(null);
const submitted = ref<Readonly<Commit> | null>(null);
const review = ref<Review | null>(null);
const confirmed = ref(false);
const message = ref('');
const outcome = ref<Outcome | null>(null);
const needsFreshAuthentication = ref(false);
const storageBlocked = ref(false);
let sequence = 0;
const sourceRequest = useHttp<
    Record<string, never>,
    { sources: typeof sources.value }
>({});
const previewRequest = useHttp<Instructions, Review>({
    plan_id: '',
    reason: '',
    customer_description: '',
});
const commitRequest = useHttp<Commit, Outcome>({
    plan_id: '',
    reason: '',
    customer_description: '',
    attempt_reference: '',
    confirmed: false,
    preview_fingerprint: '',
    quote_expires_at: '',
});
const statusRequest = useHttp<Record<string, never>, AttemptResult>({});
const legacyStatusRequest = useHttp<Record<string, never>, Outcome>({});
const prepareRequest = useHttp<AttemptInstructions, AttemptResult>({
    operation: 'apply_savings',
    attempt_reference: '',
    payload: undefined,
});
const cancelRequest = useHttp<AttemptInstructions, AttemptResult>({
    operation: 'apply_savings',
    attempt_reference: '',
    payload: undefined,
});
const busy = computed(
    () =>
        sourceRequest.processing ||
        previewRequest.processing ||
        commitRequest.processing ||
        statusRequest.processing ||
        legacyStatusRequest.processing ||
        prepareRequest.processing ||
        cancelRequest.processing,
);
const canPost = computed(
    () =>
        page.props.auth.user.id === actorId &&
        ['normal', 'degraded'].includes(page.props.platform.mode),
);
const selectedCycle = computed(() =>
    sources.value.find((source) => source.plan_id === previewRequest.plan_id),
);

watch(
    () => attempt.value !== null || storageBlocked.value,
    (value) => emit('pending', value),
    { immediate: true },
);
watch(
    () => [
        previewRequest.plan_id,
        previewRequest.reason,
        previewRequest.customer_description,
    ],
    () => {
        sequence++;
        review.value = null;
        confirmed.value = false;
        previewRequest.clearErrors();
    },
    { flush: 'sync' },
);

function errorMessage(error: unknown, fallback: string): string {
    if (error instanceof HttpResponseError) {
        try {
            const data: unknown = JSON.parse(error.response.data);
            if (
                data &&
                typeof data === 'object' &&
                'message' in data &&
                typeof data.message === 'string'
            ) {
                return data.message;
            }
        } catch {
            return fallback;
        }
    }
    return fallback;
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function hasExactKeys(value: Record<string, unknown>, keys: string[]): boolean {
    return (
        Object.keys(value).length === keys.length &&
        keys.every((key) => Object.hasOwn(value, key))
    );
}

function boundedText(value: unknown, maximum: number): value is string {
    return (
        typeof value === 'string' &&
        value.trim().length > 0 &&
        value.length <= maximum &&
        !value.includes('\0')
    );
}

function validIdentity(value: unknown): value is Attempt {
    return (
        isRecord(value) &&
        Number.isSafeInteger(value.obligation) &&
        Number(value.obligation) > 0 &&
        typeof value.reference === 'string' &&
        isOperationReference(value.reference)
    );
}

function validCommit(value: unknown, reference: string): value is Commit {
    return (
        isRecord(value) &&
        hasExactKeys(value, [
            'plan_id',
            'reason',
            'customer_description',
            'attempt_reference',
            'confirmed',
            'preview_fingerprint',
            'quote_expires_at',
        ]) &&
        boundedText(value.plan_id, 100) &&
        boundedText(value.reason, 500) &&
        boundedText(value.customer_description, 500) &&
        value.attempt_reference === reference &&
        value.confirmed === true &&
        typeof value.preview_fingerprint === 'string' &&
        /^[a-f0-9]{64}$/.test(value.preview_fingerprint) &&
        typeof value.quote_expires_at === 'string' &&
        value.quote_expires_at.length <= 64 &&
        /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/.test(
            value.quote_expires_at,
        ) &&
        Number.isFinite(Date.parse(value.quote_expires_at))
    );
}

function readSavedAttempt(): Attempt | SavedAttempt | null {
    const stored = sessionStorage.getItem(storageKey);
    if (stored === null) return null;
    if (stored.length > 4096) throw new Error('Saved attempt is too large');
    const value: unknown = JSON.parse(stored);
    if (!validIdentity(value)) throw new Error('Invalid saved attempt');
    const record = value as unknown as Record<string, unknown>;
    if (
        hasExactKeys(value as unknown as Record<string, unknown>, [
            'obligation',
            'reference',
        ])
    )
        return value;
    if (
        !isRecord(value) ||
        !hasExactKeys(value, [
            'schema_version',
            'actor_id',
            'obligation',
            'reference',
            'commit',
        ]) ||
        record.schema_version !== 1 ||
        record.actor_id !== actorId ||
        !validCommit(record.commit, value.reference)
    ) {
        throw new Error('Invalid saved instructions');
    }
    return value as SavedAttempt;
}

function retainedAttemptMatches(pending: Attempt): boolean {
    if (
        page.props.auth.user.id !== actorId ||
        attempt.value?.obligation !== pending.obligation ||
        attempt.value.reference !== pending.reference
    )
        return false;
    const retained = readSavedAttempt();
    return (
        retained?.obligation === pending.obligation &&
        retained.reference === pending.reference &&
        (submitted.value === null
            ? !('commit' in retained)
            : 'commit' in retained &&
              JSON.stringify(retained.commit) ===
                  JSON.stringify(submitted.value))
    );
}

function clearAttempt(pending: Attempt): boolean {
    try {
        if (!retainedAttemptMatches(pending))
            throw new Error('Saved attempt changed');
        sessionStorage.removeItem(storageKey);
        if (sessionStorage.getItem(storageKey) !== null)
            throw new Error('Saved attempt remains');
        attempt.value = null;
        submitted.value = null;
        return true;
    } catch {
        storageBlocked.value = true;
        message.value =
            'The saved attempt could not be verified and cleared. No new application can be submitted safely.';
        return false;
    }
}

function recordOutcome(saved: Outcome, pending: Attempt): void {
    if (
        !isRecord(saved) ||
        saved.status !== 'posted' ||
        !boundedText(saved.posting_reference, 100)
    ) {
        message.value =
            'The application outcome is still unknown. Check again before another application.';
        return;
    }
    outcome.value = saved;
    if (clearAttempt(pending)) {
        message.value =
            'Fee applied successfully. The full unpaid balance is settled.';
    }
    router.reload({ only: ['summary', 'obligations'] });
}

function resolveAttempt(result: AttemptResult, pending: Attempt): boolean {
    if (
        result.operation !== 'apply_savings' ||
        result.attempt_reference !== pending.reference ||
        result.obligation_id !== pending.obligation
    )
        throw new Error('Outcome does not match the saved attempt');
    if (result.status === 'prepared') return false;
    if (result.status === 'recorded') {
        if (!boundedText(result.posting_reference, 100))
            throw new Error('Posted reference is unavailable');
        recordOutcome(
            { status: 'posted', posting_reference: result.posting_reference },
            pending,
        );
        return true;
    }
    if (result.status !== 'cancelled')
        throw new Error('Unknown attempt outcome');
    if (clearAttempt(pending)) {
        outcome.value = null;
        review.value = null;
        confirmed.value = false;
        needsFreshAuthentication.value = false;
        open.value = false;
        message.value =
            'The original application attempt is cancelled. Delayed requests cannot post it. You may review current savings again.';
        router.reload({ only: ['summary', 'obligations'] });
    }
    return true;
}

function attemptError(error: unknown): void {
    needsFreshAuthentication.value =
        error instanceof HttpResponseError && error.response.status === 423;
    message.value = errorMessage(
        error,
        'The application outcome remains unknown. Keep this reference; check its outcome or stop it safely before a new review.',
    );
}

async function checkOutcome(): Promise<void> {
    if (!attempt.value || busy.value || page.props.auth.user.id !== actorId)
        return;
    const pending = { ...attempt.value };
    message.value = '';
    try {
        if (!retainedAttemptMatches(pending))
            throw new Error('Saved attempt changed');
        const result = await statusRequest.get(
            attemptStatus.url({
                obligation: pending.obligation,
                attemptReference: pending.reference,
            }),
        );
        if (!resolveAttempt(result, pending))
            message.value =
                'The application is prepared with no recorded outcome. Retry its original instructions or stop it safely before reviewing again.';
    } catch (error) {
        if (
            error instanceof HttpResponseError &&
            error.response.status === 404
        ) {
            try {
                recordOutcome(
                    await legacyStatusRequest.get(
                        savingsStatus.url({
                            obligation: pending.obligation,
                            attemptReference: pending.reference,
                        }),
                    ),
                    pending,
                );
            } catch (legacyError) {
                attemptError(legacyError);
                if (
                    legacyError instanceof HttpResponseError &&
                    legacyError.response.status === 404
                )
                    message.value =
                        'No recorded outcome is available. Keep the original attempt and retry its saved instructions, or stop it safely before a new review.';
            }
        } else attemptError(error);
    }
}

async function stopApplication(): Promise<void> {
    if (
        !attempt.value ||
        busy.value ||
        storageBlocked.value ||
        page.props.auth.user.id !== actorId
    )
        return;
    const pending = { ...attempt.value };
    try {
        if (!retainedAttemptMatches(pending))
            throw new Error('Saved attempt changed');
        Object.assign(cancelRequest, {
            operation: 'apply_savings',
            attempt_reference: pending.reference,
            payload: submitted.value ? { ...submitted.value } : undefined,
        });
        const result = await cancelRequest.post(
            cancelAttempt.url(pending.obligation),
        );
        if (!result) {
            message.value = `${Object.values(cancelRequest.errors).flat().join(' ')} The original attempt remains retained. Check its outcome or stop it safely before a new review.`;
            return;
        }
        if (!resolveAttempt(result, pending))
            throw new Error('Cancellation is not terminal');
    } catch (error) {
        attemptError(error);
    }
}

async function loadSources(): Promise<void> {
    if (!activeSelection.value || attempt.value) return;
    const id = activeSelection.value.id;
    const generation = ++sequence;
    try {
        const response = await sourceRequest.get(savingsSources.url(id));
        if (generation === sequence && activeSelection.value.id === id)
            sources.value = response.sources;
    } catch (error) {
        if (generation === sequence)
            message.value = errorMessage(
                error,
                'Savings sources are unavailable. Try loading them again.',
            );
    }
}

watch(open, async (visible) => {
    sequence++;
    if (!visible) {
        sourceRequest.cancel();
        previewRequest.cancel();
        return;
    }
    if (storageBlocked.value) return;
    if (attempt.value) {
        await checkOutcome();
        return;
    }
    activeSelection.value = props.obligation;
    sources.value = [];
    review.value = null;
    outcome.value = null;
    confirmed.value = false;
    message.value = '';
    needsFreshAuthentication.value = false;
    previewRequest.resetAndClearErrors();
    commitRequest.clearErrors();
    await loadSources();
});

onMounted(() => {
    try {
        const pending = readSavedAttempt();
        if (pending === null) return;
        attempt.value = {
            obligation: pending.obligation,
            reference: pending.reference,
        };
        if ('commit' in pending) {
            submitted.value = Object.freeze({ ...pending.commit });
        }
        message.value = submitted.value
            ? 'A submitted fee application needs an outcome check. Only its original saved instructions can be retried.'
            : 'This older saved attempt retains its reference only. Check its outcome or stop it safely; retrying its original instructions is unavailable.';
    } catch {
        storageBlocked.value = true;
        message.value =
            'Saved fee application recovery is unavailable. No new application can be submitted safely. Ask an Admin to verify its history.';
    }
});

async function requestReview(): Promise<void> {
    if (
        !activeSelection.value ||
        busy.value ||
        attempt.value ||
        outcome.value ||
        storageBlocked.value
    )
        return;
    const id = activeSelection.value.id;
    const generation = ++sequence;
    message.value = '';
    confirmed.value = false;
    review.value = null;
    try {
        const response = await previewRequest.post(savingsPreview.url(id));
        if (
            generation === sequence &&
            activeSelection.value.id === id &&
            open.value
        )
            review.value = response;
    } catch (error) {
        if (generation === sequence)
            message.value = errorMessage(
                error,
                'The fee review is unavailable. Check the selected savings source.',
            );
    }
}

async function sendApplication(): Promise<void> {
    if (
        !attempt.value ||
        !submitted.value ||
        busy.value ||
        !canPost.value ||
        storageBlocked.value
    )
        return;
    const pending = { ...attempt.value };
    try {
        if (!retainedAttemptMatches(pending))
            throw new Error('Saved instructions changed');
    } catch {
        storageBlocked.value = true;
        message.value =
            'The original saved instructions could not be verified. Check the outcome; no application can be resubmitted safely.';
        return;
    }
    try {
        Object.assign(prepareRequest, {
            operation: 'apply_savings',
            attempt_reference: pending.reference,
            payload: { ...submitted.value },
        });
        needsFreshAuthentication.value = false;
        const prepared = await prepareRequest.post(
            prepareAttempt.url(pending.obligation),
        );
        if (!prepared) {
            message.value = `${Object.values(prepareRequest.errors).flat().join(' ')} The original attempt remains retained. Check its outcome or stop it safely before a new review.`;
            return;
        }
        if (resolveAttempt(prepared, pending)) return;
        if (!retainedAttemptMatches(pending))
            throw new Error('Saved instructions changed');
    } catch (error) {
        attemptError(error);
        return;
    }
    Object.assign(commitRequest, submitted.value);
    message.value = '';
    needsFreshAuthentication.value = false;
    try {
        recordOutcome(
            await commitRequest.post(applySavings.url(pending.obligation)),
            pending,
        );
    } catch (error) {
        needsFreshAuthentication.value =
            error instanceof HttpResponseError && error.response.status === 423;
        message.value = errorMessage(
            error,
            'The response was interrupted. Check the saved outcome before another application.',
        );
    }
}

async function confirmApplication(): Promise<void> {
    if (
        !activeSelection.value ||
        !review.value ||
        !confirmed.value ||
        attempt.value ||
        storageBlocked.value ||
        busy.value ||
        !canPost.value
    )
        return;
    if (
        !Number.isFinite(Date.parse(review.value.quote_expires_at)) ||
        Date.parse(review.value.quote_expires_at) <= Date.now()
    ) {
        review.value = null;
        confirmed.value = false;
        message.value = 'This review expired. Review current savings again.';
        return;
    }
    const reference = newOperationReference();
    const commit: Commit = {
        ...previewRequest.data(),
        attempt_reference: reference,
        confirmed: true,
        preview_fingerprint: review.value.preview_fingerprint,
        quote_expires_at: review.value.quote_expires_at,
    };
    const pending: SavedAttempt = {
        schema_version: 1,
        actor_id: actorId,
        obligation: activeSelection.value.id,
        reference,
        commit,
    };
    if (!validIdentity(pending) || !validCommit(commit, reference)) {
        review.value = null;
        confirmed.value = false;
        message.value =
            'The reviewed instructions are incomplete. Review current savings again.';
        return;
    }
    attempt.value = { obligation: pending.obligation, reference };
    submitted.value = Object.freeze({ ...commit });
    try {
        if (sessionStorage.getItem(storageKey) !== null)
            throw new Error('An earlier saved attempt remains');
        const retained = JSON.stringify(pending);
        sessionStorage.setItem(storageKey, retained);
        if (sessionStorage.getItem(storageKey) !== retained)
            throw new Error('Saved attempt could not be retained');
    } catch {
        storageBlocked.value = true;
        message.value =
            'Your browser could not retain the original instructions. Submission is blocked until saved attempt recovery is available.';
        return;
    }
    await sendApplication();
}
</script>

<template>
    <div>
        <div
            v-if="attempt || storageBlocked"
            class="mb-4 rounded-lg border p-4"
            role="status"
        >
            <p class="text-sm">
                {{
                    storageBlocked
                        ? message
                        : 'A submitted fee application needs an outcome check.'
                }}
            </p>
            <Button
                class="mt-3"
                variant="outline"
                :disabled="busy"
                @click="open = true"
                >Check fee application</Button
            >
        </div>
        <Dialog :open="open" @update:open="!busy && (open = $event)">
            <DialogContent
                class="max-h-[90dvh] overflow-y-auto sm:max-w-xl"
                :show-close-button="!busy"
                @escape-key-down="busy && $event.preventDefault()"
                @interact-outside="busy && $event.preventDefault()"
            >
                <DialogHeader>
                    <DialogTitle>Apply fee from savings</DialogTitle>
                    <DialogDescription>
                        <template v-if="activeSelection"
                            >{{ activeSelection.customer_name }} ·
                            {{ activeSelection.rule_name }}.</template
                        >
                        Select the agreed savings cycle and review the full
                        unpaid fee before confirming.
                    </DialogDescription>
                </DialogHeader>
                <p
                    v-if="message"
                    class="text-sm"
                    role="status"
                    aria-live="polite"
                >
                    {{ message }}
                </p>
                <div v-if="outcome" class="space-y-2 rounded-lg border p-4">
                    <p class="font-medium">Fee application posted</p>
                    <p class="text-sm break-all">
                        Reference: {{ outcome.posting_reference }}
                    </p>
                </div>
                <div v-else-if="attempt" class="space-y-3">
                    <p class="text-muted-foreground text-sm">
                        Check the saved outcome before changing instructions or
                        submitting another application.
                    </p>
                    <p class="text-xs break-all">
                        Attempt: {{ attempt.reference }}
                    </p>
                    <p
                        v-for="(error, field) in commitRequest.errors"
                        :key="field"
                        class="text-destructive text-sm"
                    >
                        {{ error }}
                    </p>
                    <p class="text-muted-foreground text-sm">
                        Stopping cannot undo a recorded application. The server
                        verifies any winning commit or prevents delayed requests
                        before permitting a new review.
                    </p>
                    <Button
                        variant="outline"
                        :disabled="busy || storageBlocked"
                        @click="stopApplication"
                        >Stop pending application</Button
                    >
                    <Button :disabled="busy" @click="checkOutcome">{{
                        statusRequest.processing
                            ? 'Checking…'
                            : 'Check saved outcome'
                    }}</Button>
                    <Button
                        v-if="submitted"
                        variant="outline"
                        :disabled="busy || !canPost || storageBlocked"
                        @click="sendApplication"
                        >Retry the same application</Button
                    >
                    <Button
                        v-if="needsFreshAuthentication"
                        as-child
                        variant="outline"
                        ><Link :href="freshAuthentication()"
                            >Confirm password and authenticator</Link
                        ></Button
                    >
                </div>
                <form
                    v-else-if="activeSelection && !storageBlocked"
                    class="space-y-4"
                    @submit.prevent="requestReview"
                >
                    <fieldset :disabled="busy" class="space-y-4">
                        <div class="space-y-1.5">
                            <Label for="fee-savings-cycle">Savings cycle</Label>
                            <Select v-model="previewRequest.plan_id">
                                <SelectTrigger
                                    id="fee-savings-cycle"
                                    aria-describedby="fee-savings-cycle-error"
                                    ><SelectValue
                                        placeholder="Select a savings cycle"
                                /></SelectTrigger>
                                <SelectContent
                                    ><SelectItem
                                        v-for="source in sources"
                                        :key="source.plan_id"
                                        :value="source.plan_id"
                                        >{{ source.name }} ·
                                        {{ source.status }} ·
                                        {{ source.plan_id }}</SelectItem
                                    ></SelectContent
                                >
                            </Select>
                            <p
                                id="fee-savings-cycle-error"
                                class="text-destructive text-xs"
                            >
                                {{ previewRequest.errors.plan_id }}
                            </p>
                            <p
                                v-if="sourceRequest.processing"
                                class="text-muted-foreground animate-pulse text-sm"
                            >
                                Loading savings cycles…
                            </p>
                            <Button
                                v-if="
                                    !sources.length && !sourceRequest.processing
                                "
                                type="button"
                                variant="outline"
                                @click="loadSources"
                                >Reload savings cycles</Button
                            >
                        </div>
                        <div class="space-y-1.5">
                            <Label for="fee-savings-reason"
                                >Internal reason</Label
                            >
                            <Input
                                id="fee-savings-reason"
                                v-model="previewRequest.reason"
                                required
                                maxlength="500"
                                aria-describedby="fee-savings-reason-error"
                            />
                            <p
                                id="fee-savings-reason-error"
                                class="text-destructive text-xs"
                            >
                                {{ previewRequest.errors.reason }}
                            </p>
                        </div>
                        <div class="space-y-1.5">
                            <Label for="fee-savings-description"
                                >Customer explanation</Label
                            >
                            <Input
                                id="fee-savings-description"
                                v-model="previewRequest.customer_description"
                                required
                                maxlength="500"
                                aria-describedby="fee-savings-description-error"
                            />
                            <p
                                id="fee-savings-description-error"
                                class="text-destructive text-xs"
                            >
                                {{ previewRequest.errors.customer_description }}
                            </p>
                        </div>
                        <Button
                            type="submit"
                            variant="outline"
                            :disabled="!previewRequest.plan_id"
                            >{{
                                previewRequest.processing
                                    ? 'Reviewing…'
                                    : 'Review fee application'
                            }}</Button
                        >
                    </fieldset>
                    <div v-if="review" class="space-y-4 rounded-lg border p-4">
                        <p class="font-medium">
                            {{ selectedCycle?.name }} · {{ review.plan_id }}
                        </p>
                        <dl class="grid grid-cols-2 gap-3 text-sm">
                            <dt>Posted cycle savings</dt>
                            <dd class="text-right font-mono">
                                {{ review.display.liability }}
                            </dd>
                            <dt>Live reservations</dt>
                            <dd class="text-right font-mono">
                                {{ review.display.reservations }}
                            </dd>
                            <dt>Available cycle savings</dt>
                            <dd class="text-right font-mono">
                                {{ review.display.available }}
                            </dd>
                            <dt>Full fee to apply</dt>
                            <dd class="text-right font-mono">
                                {{ review.display.fee }}
                            </dd>
                            <dt>Remaining savings</dt>
                            <dd class="text-right font-mono">
                                {{ review.display.remaining }}
                            </dd>
                            <dt>Available cycle savings after application</dt>
                            <dd class="text-right font-mono">
                                {{ review.display.available_after }}
                            </dd>
                            <dt>Remaining unpaid fee</dt>
                            <dd class="text-right font-mono">₦0.00</dd>
                        </dl>
                        <p class="text-muted-foreground text-xs">
                            Posting date {{ review.occurred_on }} ·
                            {{ review.business_timezone }}. Review expires
                            {{
                                new Date(
                                    review.quote_expires_at,
                                ).toLocaleTimeString()
                            }}.
                        </p>
                        <div class="flex items-start gap-3">
                            <Checkbox
                                id="fee-savings-confirm"
                                :model-value="confirmed"
                                :disabled="busy || !canPost"
                                @update:model-value="
                                    confirmed = $event === true
                                "
                            />
                            <Label
                                for="fee-savings-confirm"
                                class="leading-relaxed"
                                >I confirm the Customer's agreed source and
                                explanation and the full fee shown above.</Label
                            >
                        </div>
                        <p
                            v-if="!canPost"
                            class="text-muted-foreground text-sm"
                        >
                            {{ page.props.platform.message }}
                        </p>
                        <Button
                            type="button"
                            :disabled="busy || !confirmed || !canPost"
                            @click="confirmApplication"
                            >Confirm fee application</Button
                        >
                    </div>
                </form>
                <DialogFooter>
                    <Button
                        variant="outline"
                        :disabled="busy"
                        @click="open = false"
                        >{{
                            attempt || storageBlocked ? 'Hide' : 'Close'
                        }}</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
