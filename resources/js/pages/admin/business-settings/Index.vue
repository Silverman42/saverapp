<script setup lang="ts">
import { Head, Link, router, useForm, useHttp } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import type { FormDataConvertible } from '@inertiajs/core';
import { Pencil } from '@lucide/vue';
import FormSheet from '@/components/FormSheet.vue';
import MoreDetails from '@/components/MoreDetails.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
import { Switch } from '@/components/ui/switch';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { useProtectedWorkspace } from '@/composables/useProtectedWorkspace';
import { dashboard } from '@/routes';
import { index } from '@/routes/admin/business-settings';
import * as drafts from '@/routes/admin/business-settings/drafts';
import * as versions from '@/routes/admin/business-settings/versions';
import * as operations from '@/routes/admin/business-settings/operations';
import * as logo from '@/routes/admin/business-settings/logo';
import { show as logoAsset } from '@/routes/business-logo';
import { show as freshAuthentication } from '@/actions/App/Http/Controllers/Auth/FreshAuthenticationController';
import type {
    SettingValue,
    SettingsWorkspace,
} from '@/types/business-settings';
const props = defineProps<{ settings: SettingsWorkspace; scope: string }>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Business settings', href: index() },
        ],
    },
});
const { visible, notice, refresh } = useProtectedWorkspace(() => props.scope);
const selectedCode = ref('display_name');
const selectedDraft = ref<number | null>(null);
const value = ref('');
const unknown = ref(false);
const operationId = ref<string | null>(null);
const mutationNotice = ref('');
const lookup = useHttp({});
const logoUpload = useHttp<{ logo: File | null }, { reference: string }>({
    logo: null,
});
const isLogoSetting = computed(() => selectedCode.value === 'logo_reference');
const isBooleanSetting = computed(
    () => typeof definition.value.default === 'boolean',
);
function isLogoReference(code: string, item: SettingValue): item is string {
    return code === 'logo_reference' && typeof item === 'string';
}
function uploadLogo(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    if (!file) return;
    logoUpload.logo = file;
    logoUpload.post(logo.store.url(), {
        onSuccess: (response) => {
            value.value = response.reference;
        },
    });
}
let pendingRequest: {
    url: string;
    method: 'post' | 'patch';
    data: Record<string, FormDataConvertible>;
} | null = null;
function remember(
    url: string,
    method: 'post' | 'patch',
    data: Record<string, FormDataConvertible>,
): void {
    pendingRequest = { url, method, data: JSON.parse(JSON.stringify(data)) };
}
const draft = computed(() =>
    props.settings.drafts.find((item) => item.id === selectedDraft.value),
);
const definition = computed(
    () => props.settings.definitions[selectedCode.value],
);
const editable = computed(() =>
    Object.entries(props.settings.definitions).filter(
        ([, item]) => item.editable,
    ),
);
const stateLabels: Record<string, string> = {
    scheduled: 'Scheduled',
    propagation_pending: 'Going live',
    blocked: 'Blocked',
    effective: 'Live',
    superseded: 'Replaced',
    cancelled: 'Cancelled',
};
const editSheetOpen = ref(false);
const reviewSheetOpen = ref(false);
const cancellingVersion = ref<{ id: number; version: number } | null>(null);
function openEdit(code: string): void {
    selectedCode.value = code;
    value.value = String(props.settings.values[code] ?? '');
    save.clearErrors();
    editSheetOpen.value = true;
}
function formatValue(item: SettingValue): string {
    if (item === null) return 'Not set';
    if (typeof item === 'boolean') return item ? 'On' : 'Off';
    return String(item);
}
function openCancel(id: number, version: number): void {
    lifecycle.reason = '';
    lifecycle.confirmation = false;
    lifecycle.clearErrors();
    cancellingVersion.value = { id, version };
}
function confirmCancel(): void {
    if (!cancellingVersion.value) return;
    cancelVersion(cancellingVersion.value.id);
}
const hasPendingVersions = computed(() =>
    props.settings.history.some((item) =>
        ['scheduled', 'propagation_pending', 'blocked'].includes(item.state),
    ),
);

function stateLabel(state: string | null): string {
    return state === null
        ? 'Imported'
        : (stateLabels[state] ?? 'Unknown state');
}

const groups = computed(() => [
    ...new Set(
        Object.values(props.settings.definitions).map((item) => item.group),
    ),
]);
const save = useForm({
    operation_id: '',
    base_version: props.settings.version,
    revision: 1,
    proposed_values: {} as Record<string, SettingValue>,
});
const preview = useForm({ revision: 1, effective_at: '' });
const publication = useForm({
    operation_id: '',
    revision: 1,
    preview_reference: '',
    reason: '',
    confirmation: false,
});
const lifecycle = useForm({
    operation_id: '',
    reason: '',
    confirmation: false,
    revision: 1,
});
watch(
    selectedCode,
    () => {
        value.value = String(props.settings.values[selectedCode.value] ?? '');
    },
    { immediate: true },
);
watch(
    () => props.settings.drafts,
    (items) => {
        if (selectedDraft.value === null && items.length)
            selectedDraft.value = items[0].id;
    },
);
watch(
    () => props.scope,
    () => {
        value.value = '';
        publication.reset();
        save.reset();
    },
);
function begin(): string {
    unknown.value = false;
    mutationNotice.value = '';
    const id = crypto.randomUUID();
    operationId.value = id;
    return id;
}
const outcomes = {
    preserveScroll: true,
    onNetworkError: () => {
        unknown.value = true;
        mutationNotice.value =
            'We could not confirm if your change was saved. Check the result before making another change.';
    },
    onHttpException: () => {
        mutationNotice.value =
            'The change was not accepted. Refresh the page and check the current settings.';
    },
    onSuccess: () => {
        unknown.value = false;
        mutationNotice.value = 'Saved.';
        editSheetOpen.value = false;
        cancellingVersion.value = null;
        reviewSheetOpen.value = false;
    },
};
function saveDraft(): void {
    if (unknown.value) return;
    const raw = isBooleanSetting.value
        ? value.value === 'true'
        : typeof definition.value.default === 'number'
          ? value.value.trim() === ''
              ? null
              : Number(value.value)
          : value.value || null;
    save.operation_id = begin();
    save.base_version = props.settings.version;
    save.proposed_values = {
        ...draft.value?.patch,
        [selectedCode.value]: raw,
    };
    save.revision = draft.value?.revision ?? 1;
    save.transform(({ proposed_values, ...data }) => ({
        ...data,
        patch: proposed_values,
    }));
    const { proposed_values, ...saveData } = save.data();
    remember(
        draft.value ? drafts.update.url(draft.value.id) : drafts.store.url(),
        draft.value ? 'patch' : 'post',
        { ...saveData, patch: proposed_values },
    );
    if (draft.value) save.submit(drafts.update(draft.value.id), outcomes);
    else save.submit(drafts.store(), outcomes);
}
function previewDraft(): void {
    if (!draft.value || unknown.value) return;
    preview.revision = draft.value.revision;
    preview.transform((data) => ({
        ...data,
        effective_at: data.effective_at || null,
    }));
    preview.submit(drafts.preview(draft.value.id), { preserveScroll: true });
}
function publishDraft(): void {
    if (!draft.value?.preview || unknown.value) return;
    publication.operation_id = begin();
    publication.revision = draft.value.revision;
    publication.preview_reference = draft.value.preview.reference;
    remember(drafts.publish.url(draft.value.id), 'post', publication.data());
    publication.submit(drafts.publish(draft.value.id), outcomes);
}
function discardDraft(): void {
    if (!draft.value || unknown.value) return;
    const payload = { operation_id: begin(), revision: draft.value.revision };
    remember(drafts.discard.url(draft.value.id), 'post', payload);
    router.post(drafts.discard.url(draft.value.id), payload, {
        ...outcomes,
        onSuccess: () => {
            selectedDraft.value = null;
            outcomes.onSuccess();
        },
    });
}
function cancelVersion(id: number): void {
    if (unknown.value) return;
    lifecycle.operation_id = begin();
    remember(versions.cancel.url(id), 'post', {
        operation_id: lifecycle.operation_id,
        reason: lifecycle.reason,
        confirmation: lifecycle.confirmation,
    });
    lifecycle
        .transform(({ revision: _revision, ...data }) => data)
        .submit(versions.cancel(id), outcomes);
}
function rollbackVersion(id: number): void {
    if (unknown.value) return;
    const payload = { operation_id: begin() };
    remember(versions.rollback.url(id), 'post', payload);
    router.post(versions.rollback.url(id), payload, outcomes);
}
function resolveOperation(): void {
    if (!operationId.value) return;
    lookup.get(operations.show.url(operationId.value), {
        onSuccess: () => {
            unknown.value = false;
            mutationNotice.value = 'Your last change was saved.';
            refresh();
        },
    });
}
function retryOriginal(): void {
    if (pendingRequest)
        router.visit(pendingRequest.url, {
            method: pendingRequest.method,
            data: pendingRequest.data,
            ...outcomes,
        });
}
</script>

<template>
    <div class="space-y-6">
        <Head title="Business settings" />
        <div v-if="!visible" role="alert" class="rounded-xl border p-6">
            <p>{{ notice }}</p>
            <Button class="mt-4" @click="refresh">Refresh</Button>
        </div>
        <div
            v-if="mutationNotice"
            role="status"
            aria-live="polite"
            class="bg-muted flex flex-wrap items-center justify-between gap-3 rounded-xl p-4 text-sm"
        >
            <p>{{ mutationNotice }}</p>
            <div v-if="unknown" class="flex flex-wrap gap-2">
                <Button size="sm" variant="outline" @click="resolveOperation"
                    >Check result</Button
                ><Button
                    v-if="pendingRequest"
                    size="sm"
                    variant="outline"
                    @click="retryOriginal"
                    >Try again</Button
                >
            </div>
        </div>
        <template v-if="visible">
            <PageHeader
                title="Business settings"
                description="Your business details and how the app works."
            >
                <template #actions>
                    <Badge v-if="!settings.can_manage" variant="secondary"
                        >View only</Badge
                    >
                </template>
            </PageHeader>
            <p
                v-if="!settings.initialized"
                role="status"
                class="bg-muted rounded-xl p-4 text-sm"
            >
                Settings cannot be edited yet. They need to be imported and
                checked first.
            </p>

            <Card
                v-if="
                    settings.can_manage &&
                    settings.initialized &&
                    settings.drafts.length
                "
                aria-labelledby="draft-title"
            >
                <CardHeader
                    class="flex flex-row flex-wrap items-start justify-between gap-3"
                >
                    <div class="min-w-0">
                        <CardTitle id="draft-title"
                            >Changes not live yet</CardTitle
                        >
                        <CardDescription class="mt-1.5">
                            Review your changes, then publish them.
                        </CardDescription>
                    </div>
                    <div v-if="draft" class="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="unknown"
                            @click="discardDraft"
                            >Discard</Button
                        >
                        <Button
                            type="button"
                            :disabled="unknown"
                            @click="reviewSheetOpen = true"
                            >Review and publish</Button
                        >
                    </div>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div
                        v-if="settings.drafts.length > 1 || !draft"
                        class="w-fit space-y-2"
                    >
                        <Label for="draft-choice">Draft</Label
                        ><Select
                            :model-value="
                                selectedDraft === null
                                    ? '__new'
                                    : String(selectedDraft)
                            "
                            @update:model-value="
                                selectedDraft =
                                    $event === '__new' ? null : Number($event)
                            "
                        >
                            <SelectTrigger id="draft-choice" class="w-56"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__new"
                                    >Start a new draft</SelectItem
                                >
                                <SelectItem
                                    v-for="item in settings.drafts"
                                    :key="item.id"
                                    :value="String(item.id)"
                                >
                                    Draft {{ item.id }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <dl v-if="draft" class="divide-border divide-y">
                        <div
                            v-for="(proposed, code) in draft.patch"
                            :key="code"
                            class="flex flex-wrap items-center justify-between gap-3 py-2.5 text-sm"
                        >
                            <dt class="text-muted-foreground">
                                {{ settings.definitions[code]?.label }}
                            </dt>
                            <dd class="font-medium break-words">
                                {{
                                    proposed === null
                                        ? 'Remove value'
                                        : formatValue(proposed)
                                }}
                            </dd>
                        </div>
                    </dl>
                </CardContent>
            </Card>

            <Card v-for="group in groups" :key="group" :aria-label="group">
                <CardHeader>
                    <CardTitle>{{ group }}</CardTitle>
                </CardHeader>
                <CardContent>
                    <dl class="divide-border divide-y">
                        <template
                            v-for="(item, code) in settings.definitions"
                            :key="code"
                        >
                            <div
                                v-if="
                                    item.group === group &&
                                    code in settings.values
                                "
                                class="flex flex-wrap items-center justify-between gap-3 py-3"
                            >
                                <div class="min-w-0 flex-1">
                                    <dt class="text-sm font-medium">
                                        {{ item.label }}
                                    </dt>
                                    <dd
                                        v-if="
                                            isLogoReference(
                                                code,
                                                settings.values[code],
                                            )
                                        "
                                        class="mt-2"
                                    >
                                        <img
                                            :src="
                                                logoAsset.url(
                                                    settings.values[code],
                                                )
                                            "
                                            alt="Current business logo"
                                            class="size-14 rounded-md border object-contain"
                                        />
                                    </dd>
                                    <dd
                                        v-else
                                        class="text-muted-foreground mt-0.5 text-sm break-words"
                                    >
                                        {{ formatValue(settings.values[code]) }}
                                    </dd>
                                </div>
                                <Button
                                    v-if="
                                        settings.can_manage &&
                                        settings.initialized &&
                                        item.editable
                                    "
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    :disabled="unknown"
                                    :aria-label="`Edit ${item.label}`"
                                    @click="openEdit(code)"
                                >
                                    <Pencil class="size-4" /> Edit
                                </Button>
                            </div>
                        </template>
                    </dl>
                </CardContent>
            </Card>

            <section class="space-y-3" aria-labelledby="history-title">
                <h2 id="history-title" class="text-base font-medium">
                    History
                </h2>
                <MoreDetails
                    label="Show past and scheduled changes"
                    :default-open="hasPendingVersions"
                >
                    <div class="overflow-x-auto rounded-xl border">
                        <table class="w-full min-w-[560px] text-left text-sm">
                            <thead
                                class="bg-muted/40 text-muted-foreground text-xs"
                            >
                                <tr>
                                    <th scope="col" class="p-3 font-medium">
                                        Version
                                    </th>
                                    <th scope="col" class="p-3 font-medium">
                                        Status
                                    </th>
                                    <th scope="col" class="p-3 font-medium">
                                        Starts
                                    </th>
                                    <th scope="col" class="p-3 font-medium">
                                        <span class="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="item in settings.history"
                                    :key="item.id"
                                    class="border-t"
                                >
                                    <th scope="row" class="p-3 font-normal">
                                        {{ item.version }}
                                    </th>
                                    <td class="p-3">
                                        <Badge variant="outline">{{
                                            stateLabel(item.state)
                                        }}</Badge>
                                        <p
                                            v-if="item.failure_code"
                                            class="text-muted-foreground mt-1 text-xs"
                                        >
                                            {{ item.failure_code }}
                                        </p>
                                    </td>
                                    <td class="p-3">
                                        {{ item.requested_effective_at }}
                                        <p
                                            class="text-muted-foreground text-xs"
                                        >
                                            {{
                                                item.effective_at
                                                    ? `Live since ${item.effective_at}`
                                                    : 'Not live'
                                            }}
                                        </p>
                                    </td>
                                    <td class="p-3 text-right">
                                        <template v-if="settings.can_manage"
                                            ><Button
                                                v-if="
                                                    [
                                                        'scheduled',
                                                        'propagation_pending',
                                                        'blocked',
                                                    ].includes(item.state)
                                                "
                                                size="sm"
                                                variant="outline"
                                                :disabled="unknown"
                                                :aria-label="`Cancel version ${item.version}`"
                                                @click="
                                                    openCancel(
                                                        item.id,
                                                        item.version,
                                                    )
                                                "
                                                >Cancel</Button
                                            ><Button
                                                v-else-if="
                                                    [
                                                        'effective',
                                                        'superseded',
                                                    ].includes(item.state)
                                                "
                                                size="sm"
                                                variant="ghost"
                                                :disabled="unknown"
                                                :aria-label="`Restore version ${item.version} as a draft`"
                                                @click="
                                                    rollbackVersion(item.id)
                                                "
                                                >Restore as draft</Button
                                            ></template
                                        >
                                    </td>
                                </tr>
                                <tr v-if="!settings.history.length">
                                    <td
                                        colspan="4"
                                        class="text-muted-foreground p-4"
                                    >
                                        No changes yet.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </MoreDetails>
            </section>

            <section class="space-y-3" aria-labelledby="readiness-title">
                <h2 id="readiness-title" class="text-base font-medium">
                    Feature status
                </h2>
                <MoreDetails label="Show feature status">
                    <div class="divide-border divide-y rounded-xl border">
                        <div
                            v-for="(check, code) in settings.readiness"
                            :key="code"
                            class="flex flex-wrap items-start justify-between gap-3 p-4"
                        >
                            <div class="min-w-0 space-y-1">
                                <h3 class="text-sm font-medium">
                                    {{
                                        settings.definitions[code]?.label ??
                                        code.replaceAll('_', ' ')
                                    }}
                                </h3>
                                <p
                                    v-if="check.blocker"
                                    class="text-muted-foreground text-sm"
                                >
                                    {{ check.blocker }}
                                </p>
                                <p class="text-muted-foreground text-xs">
                                    {{ check.owner }} · version
                                    {{ check.version }}
                                </p>
                            </div>
                            <Badge variant="outline">{{ check.state }}</Badge>
                        </div>
                    </div>
                </MoreDetails>
            </section>

            <MoreDetails label="About these settings">
                <dl class="text-muted-foreground grid gap-1 text-xs">
                    <div>
                        <dt class="inline">Business ID:</dt>
                        <dd class="inline">
                            {{ settings.business_reference }}
                        </dd>
                    </div>
                    <div>
                        <dt class="inline">Live version:</dt>
                        <dd class="inline">{{ settings.version }}</dd>
                    </div>
                    <div>
                        <dt class="inline">Your access:</dt>
                        <dd class="inline">
                            {{ settings.can_manage ? 'Can edit' : 'View only' }}
                        </dd>
                    </div>
                </dl>
            </MoreDetails>

            <FormSheet
                v-model:open="editSheetOpen"
                :title="`Edit ${definition.label.toLowerCase()}`"
                :description="definition.help"
            >
                <form
                    id="setting-form"
                    class="space-y-3"
                    @submit.prevent="saveDraft"
                >
                    <Label for="setting-value">{{ definition.label }}</Label>
                    <template v-if="isLogoSetting">
                        <Input
                            id="setting-value"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            aria-describedby="logo-help"
                            :disabled="unknown || logoUpload.processing"
                            @change="uploadLogo"
                        />
                        <p id="logo-help" class="text-muted-foreground text-xs">
                            JPEG, PNG or WebP, up to 2 MB, 128 to 2,048 pixels
                            per side. Save with no file to remove the logo.
                        </p>
                        <p
                            v-if="logoUpload.errors.logo"
                            role="alert"
                            class="text-destructive text-sm"
                        >
                            {{ logoUpload.errors.logo }}
                        </p>
                        <img
                            v-if="value"
                            :src="logoAsset.url(value)"
                            alt="New business logo"
                            class="size-16 rounded-md border object-contain"
                        />
                    </template>
                    <div
                        v-else-if="isBooleanSetting"
                        class="flex items-center gap-3"
                    >
                        <Switch
                            id="setting-value"
                            :model-value="value === 'true'"
                            :disabled="unknown"
                            @update:model-value="
                                (checked: boolean) =>
                                    (value = checked ? 'true' : 'false')
                            "
                        />
                        <span class="text-muted-foreground text-sm">{{
                            value === 'true' ? 'On' : 'Off'
                        }}</span>
                    </div>
                    <Input
                        v-else
                        id="setting-value"
                        v-model="value"
                        :disabled="unknown"
                    />
                    <ul
                        v-if="save.hasErrors"
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        <li v-for="(error, key) in save.errors" :key="key">
                            {{ error }}
                        </li>
                    </ul>
                    <p class="text-muted-foreground text-xs">
                        {{
                            draft
                                ? `This is added to draft ${draft.id}. Nothing changes until you publish.`
                                : 'This starts a new draft. Nothing changes until you publish.'
                        }}
                    </p>
                </form>
                <template #footer>
                    <Button
                        type="button"
                        variant="outline"
                        @click="editSheetOpen = false"
                        >Cancel</Button
                    >
                    <Button
                        type="submit"
                        form="setting-form"
                        :disabled="
                            save.processing || unknown || logoUpload.processing
                        "
                        >Save draft</Button
                    >
                </template>
            </FormSheet>

            <FormSheet
                v-if="draft"
                v-model:open="reviewSheetOpen"
                title="Review and publish"
                description="Check what will change before it goes live."
            >
                <div class="space-y-6">
                    <form class="space-y-3" @submit.prevent="previewDraft">
                        <Label for="effective-time">Start time (optional)</Label
                        ><Input
                            id="effective-time"
                            v-model="preview.effective_at"
                            placeholder="2026-10-01T00:00:00+01:00"
                            aria-describedby="effective-time-help"
                        />
                        <p
                            id="effective-time-help"
                            class="text-muted-foreground text-xs"
                        >
                            Leave blank to apply now. Collection limit changes
                            should start at midnight.
                        </p>
                        <p
                            v-for="(error, key) in preview.errors"
                            :key="key"
                            role="alert"
                            class="text-destructive text-sm"
                        >
                            {{ error }}
                        </p>
                        <Button
                            :disabled="preview.processing || unknown"
                            variant="outline"
                            >Check impact</Button
                        >
                    </form>
                    <div v-if="draft.preview" class="space-y-4">
                        <div class="overflow-x-auto rounded-xl border">
                            <table class="w-full text-left text-sm">
                                <thead
                                    class="bg-muted/40 text-muted-foreground text-xs"
                                >
                                    <tr>
                                        <th scope="col" class="p-2 font-medium">
                                            Setting
                                        </th>
                                        <th scope="col" class="p-2 font-medium">
                                            Now
                                        </th>
                                        <th scope="col" class="p-2 font-medium">
                                            New
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="(change, code) in draft.preview
                                            .diff"
                                        :key="code"
                                        class="border-t"
                                    >
                                        <th scope="row" class="p-2 font-normal">
                                            {{
                                                settings.definitions[code]
                                                    ?.label
                                            }}
                                        </th>
                                        <td class="p-2">
                                            {{ formatValue(change.before) }}
                                        </td>
                                        <td class="p-2">
                                            {{ formatValue(change.after) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <ul
                            v-if="draft.preview.effects.length"
                            class="list-disc space-y-1 pl-5 text-sm"
                        >
                            <li
                                v-for="effect in draft.preview.effects"
                                :key="effect"
                            >
                                {{ effect }}
                            </li>
                        </ul>
                        <p class="text-sm">
                            Starts:
                            {{ draft.preview.effective_at ?? 'Right away' }}
                        </p>
                        <form
                            id="publish-form"
                            class="space-y-3"
                            @submit.prevent="publishDraft"
                        >
                            <div class="space-y-2">
                                <Label for="publication-reason"
                                    >Reason (staff only)</Label
                                ><Input
                                    id="publication-reason"
                                    v-model="publication.reason"
                                    maxlength="500"
                                />
                            </div>
                            <label class="flex items-start gap-3 text-sm"
                                ><input
                                    v-model="publication.confirmation"
                                    type="checkbox"
                                    class="mt-1"
                                />I have checked these changes.</label
                            >
                            <p
                                v-for="(error, key) in publication.errors"
                                :key="key"
                                role="alert"
                                class="text-destructive text-sm"
                            >
                                {{ error }}
                            </p>
                            <p class="text-muted-foreground text-xs">
                                You need to
                                <Link
                                    :href="freshAuthentication()"
                                    class="text-primary underline"
                                    >confirm it's you</Link
                                >
                                with your password and authenticator code before
                                publishing.
                            </p>
                        </form>
                    </div>
                </div>
                <template #footer>
                    <Button
                        type="button"
                        variant="outline"
                        @click="reviewSheetOpen = false"
                        >Close</Button
                    >
                    <Button
                        v-if="draft.preview"
                        type="submit"
                        form="publish-form"
                        :disabled="
                            publication.processing ||
                            !publication.confirmation ||
                            unknown
                        "
                        >Publish</Button
                    >
                </template>
            </FormSheet>

            <Dialog
                :open="cancellingVersion !== null"
                @update:open="if (!$event) cancellingVersion = null;"
            >
                <DialogContent class="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle
                            >Cancel version
                            {{ cancellingVersion?.version }}?</DialogTitle
                        >
                        <DialogDescription
                            >This scheduled change will not go
                            live.</DialogDescription
                        >
                    </DialogHeader>
                    <form class="space-y-3" @submit.prevent="confirmCancel">
                        <div class="space-y-2">
                            <Label for="cancel-reason">Reason</Label
                            ><Input
                                id="cancel-reason"
                                v-model="lifecycle.reason"
                                maxlength="500"
                                required
                            />
                        </div>
                        <label class="flex gap-3 text-sm"
                            ><input
                                v-model="lifecycle.confirmation"
                                type="checkbox"
                            />Yes, cancel this change.</label
                        >
                        <p class="text-muted-foreground text-xs">
                            You may be asked for your password and authenticator
                            code.
                        </p>
                        <p
                            v-for="(error, key) in lifecycle.errors"
                            :key="key"
                            role="alert"
                            class="text-destructive text-sm"
                        >
                            {{ error }}
                        </p>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                @click="cancellingVersion = null"
                                >Keep it</Button
                            >
                            <Button
                                type="submit"
                                variant="destructive"
                                :disabled="
                                    lifecycle.processing ||
                                    unknown ||
                                    !lifecycle.confirmation ||
                                    !lifecycle.reason
                                "
                                >Cancel change</Button
                            >
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </template>
    </div>
</template>
