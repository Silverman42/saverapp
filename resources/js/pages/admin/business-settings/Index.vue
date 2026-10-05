<script setup lang="ts">
import { Head, Link, router, useForm, useHttp } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";
import type { FormDataConvertible } from "@inertiajs/core";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import { Badge } from "@/components/ui/badge";
import { useProtectedWorkspace } from "@/composables/useProtectedWorkspace";
import { dashboard } from "@/routes";
import { index } from "@/routes/admin/business-settings";
import * as drafts from "@/routes/admin/business-settings/drafts";
import * as versions from "@/routes/admin/business-settings/versions";
import * as operations from "@/routes/admin/business-settings/operations";
import * as logo from "@/routes/admin/business-settings/logo";
import { show as logoAsset } from "@/routes/business-logo";
import { show as freshAuthentication } from "@/actions/App/Http/Controllers/Auth/FreshAuthenticationController";
import type {
    SettingValue,
    SettingsWorkspace,
} from "@/types/business-settings";
const props = defineProps<{ settings: SettingsWorkspace; scope: string }>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: "Dashboard", href: dashboard() },
            { title: "Business settings", href: index() },
        ],
    },
});
const { visible, notice, refresh } = useProtectedWorkspace(() => props.scope);
const selectedCode = ref("display_name");
const selectedDraft = ref<number | null>(null);
const value = ref("");
const unknown = ref(false);
const operationId = ref<string | null>(null);
const mutationNotice = ref("");
const lookup = useHttp({});
const logoUpload = useHttp<{ logo: File | null }, { reference: string }>({
    logo: null,
});
const isLogoSetting = computed(() => selectedCode.value === "logo_reference");
const isBooleanSetting = computed(
    () => typeof definition.value.default === "boolean",
);
function isLogoReference(code: string, item: SettingValue): item is string {
    return code === "logo_reference" && typeof item === "string";
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
    method: "post" | "patch";
    data: Record<string, FormDataConvertible>;
} | null = null;
function remember(
    url: string,
    method: "post" | "patch",
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
    scheduled: "Scheduled",
    propagation_pending: "Activation pending",
    blocked: "Blocked — activation degraded",
    effective: "Effective",
    superseded: "Superseded",
    cancelled: "Cancelled",
};

function stateLabel(state: string | null): string {
    return state === null
        ? "Imported"
        : (stateLabels[state] ?? "Unknown state");
}

const groups = computed(() => [
    ...new Set(
        Object.values(props.settings.definitions).map((item) => item.group),
    ),
]);
const save = useForm({
    operation_id: "",
    base_version: props.settings.version,
    revision: 1,
    proposed_values: {} as Record<string, SettingValue>,
});
const preview = useForm({ revision: 1, effective_at: "" });
const publication = useForm({
    operation_id: "",
    revision: 1,
    preview_reference: "",
    reason: "",
    confirmation: false,
});
const lifecycle = useForm({
    operation_id: "",
    reason: "",
    confirmation: false,
    revision: 1,
});
watch(
    selectedCode,
    () => {
        value.value = String(props.settings.values[selectedCode.value] ?? "");
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
        value.value = "";
        publication.reset();
        save.reset();
    },
);
function begin(): string {
    unknown.value = false;
    mutationNotice.value = "";
    const id = crypto.randomUUID();
    operationId.value = id;
    return id;
}
const outcomes = {
    preserveScroll: true,
    onNetworkError: () => {
        unknown.value = true;
        mutationNotice.value =
            "The result is unknown. Resolve this operation before starting another change.";
    },
    onHttpException: () => {
        mutationNotice.value =
            "The server rejected the change. Refresh and review its current state.";
    },
    onSuccess: () => {
        unknown.value = false;
        mutationNotice.value =
            "The server confirmed the operation. Review its current state below.";
    },
};
function saveDraft(): void {
    if (unknown.value) return;
    const raw = isBooleanSetting.value
        ? value.value === "true"
        : typeof definition.value.default === "number"
            ? value.value.trim() === ""
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
        draft.value ? "patch" : "post",
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
    remember(drafts.publish.url(draft.value.id), "post", publication.data());
    publication.submit(drafts.publish(draft.value.id), outcomes);
}
function discardDraft(): void {
    if (!draft.value || unknown.value) return;
    const payload = { operation_id: begin(), revision: draft.value.revision };
    remember(drafts.discard.url(draft.value.id), "post", payload);
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
    remember(versions.cancel.url(id), "post", {
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
    remember(versions.rollback.url(id), "post", payload);
    router.post(versions.rollback.url(id), payload, outcomes);
}
function resolveOperation(): void {
    if (!operationId.value) return;
    lookup.get(operations.show.url(operationId.value), {
        onSuccess: () => {
            unknown.value = false;
            mutationNotice.value = "The original operation was confirmed.";
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
    <div class="space-y-8">
        <Head title="Business settings" />
        <div v-if="!visible" role="alert" class="rounded-xl border p-6">
            <p>{{ notice }}</p>
            <Button class="mt-4" @click="refresh">Refresh access</Button>
        </div>
        <p
            v-if="mutationNotice"
            role="status"
            aria-live="polite"
            class="rounded-xl border p-4"
        >
            {{ mutationNotice }}
            <Button v-if="unknown" variant="outline" @click="resolveOperation"
                >Look up operation result</Button
            ><Button
                v-if="unknown && pendingRequest"
                variant="outline"
                @click="retryOriginal"
                >Retry the same operation</Button
            >
        </p>
        <template v-if="visible">
            <header>
                <h1 class="text-[25px] font-medium tracking-tight">
                    Business settings
                </h1>
                <p class="text-muted-foreground mt-1.5 text-sm">
                    {{ settings.business_reference }} · Effective version
                    {{ settings.version }} ·
                    {{
                        settings.can_manage
                            ? "Configuration manager"
                            : "Read-only oversight"
                    }}
                </p>
            </header>
            <p
                v-if="!settings.initialized"
                role="status"
                class="rounded-xl border p-4"
            >
                Trusted configuration import is required before editing. Current
                values are legacy configuration with unverified readiness.
            </p>
            <section
                v-for="group in groups"
                :key="group"
                class="space-y-3"
                :aria-label="group"
            >
                <h2 class="text-lg font-medium">{{ group }}</h2>
                <dl class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <template
                        v-for="(item, code) in settings.definitions"
                        :key="code"
                    >
                        <div
                            v-if="
                                item.group === group && code in settings.values
                            "
                            class="rounded-xl border p-4"
                        >
                            <dt class="text-muted-foreground text-sm">
                                {{ item.label }}
                            </dt>
                            <dd
                                v-if="
                                    isLogoReference(code, settings.values[code])
                                "
                                class="mt-2"
                            >
                                <img
                                    :src="logoAsset.url(settings.values[code])"
                                    alt="Current business logo"
                                    class="size-16 rounded-md border object-contain"
                                />
                            </dd>
                            <dd v-else class="mt-2 font-medium break-words">
                                {{
                                    settings.values[code] === null
                                        ? "Not configured"
                                        : String(settings.values[code])
                                }}
                            </dd>
                            <p class="text-muted-foreground mt-2 text-xs">
                                {{ item.help }}
                            </p>
                        </div>
                    </template>
                </dl>
            </section>
            <section
                v-if="settings.can_manage && settings.initialized"
                class="space-y-4 rounded-xl border p-5"
                aria-labelledby="draft-title"
            >
                <h2 id="draft-title" class="text-lg font-medium">
                    Draft and impact preview
                </h2>
                <p class="text-muted-foreground text-sm">
                    Drafts do not change runtime values. To publish, confirm
                    your password and MFA again.
                </p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
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
                            <SelectTrigger id="draft-choice" class="mt-2 w-full"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__new">New draft</SelectItem>
                                <SelectItem
                                    v-for="item in settings.drafts"
                                    :key="item.id"
                                    :value="String(item.id)"
                                >
                                    Draft {{ item.id }} · revision
                                    {{ item.revision }} · base
                                    {{ item.base_version }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div>
                        <Label for="setting-code">Setting</Label
                        ><Select v-model="selectedCode">
                            <SelectTrigger id="setting-code" class="mt-2 w-full"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="[code, item] in editable"
                                    :key="code"
                                    :value="code"
                                >
                                    {{ item.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>
                <form class="space-y-3" @submit.prevent="saveDraft">
                    <Label for="setting-value"
                        >Proposed {{ definition.label }}</Label
                    >
                    <template v-if="isLogoSetting">
                        <Input
                            id="setting-value"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            aria-describedby="logo-help"
                            :disabled="unknown || logoUpload.processing"
                            @change="uploadLogo"
                        />
                        <p id="logo-help" class="text-muted-foreground text-sm">
                            JPEG, PNG or WebP, up to 2 MB, 128–2,048 pixels per
                            side. Metadata is removed. Leave empty and save to
                            remove the logo.
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
                            alt="Proposed business logo"
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
                            value === "true" ? "Enabled" : "Disabled"
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
                    <Button
                        :disabled="
                            save.processing || unknown || logoUpload.processing
                        "
                        >Save draft</Button
                    >
                </form>
                <template v-if="draft">
                    <dl class="space-y-2">
                        <div
                            v-for="(proposed, code) in draft.patch"
                            :key="code"
                        >
                            <dt class="text-sm">
                                {{ settings.definitions[code]?.label }}
                            </dt>
                            <dd class="font-medium">
                                {{ proposed ?? "Remove value" }}
                            </dd>
                        </div>
                    </dl>
                    <form class="space-y-3" @submit.prevent="previewDraft">
                        <Label for="effective-time"
                            >Future effective time (ISO timestamp with UTC
                            offset; blank for immediate)</Label
                        ><Input
                            id="effective-time"
                            v-model="preview.effective_at"
                            placeholder="2026-10-01T00:00:00+01:00"
                        />
                        <p class="text-muted-foreground text-sm">
                            Set collection limit changes to start at a future
                            business midnight.
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
                            >Preview impact</Button
                        ><Button
                            type="button"
                            class="ml-2"
                            variant="outline"
                            :disabled="unknown"
                            @click="discardDraft"
                            >Discard draft</Button
                        >
                    </form>
                    <div
                        v-if="draft.preview"
                        class="bg-muted/40 space-y-4 rounded-lg p-4"
                    >
                        <h3 class="font-medium">Confirmed impact preview</h3>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead>
                                    <tr>
                                        <th scope="col" class="p-2">Setting</th>
                                        <th scope="col" class="p-2">Current</th>
                                        <th scope="col" class="p-2">
                                            Proposed
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
                                            {{
                                                change.before ??
                                                "Not configured"
                                            }}
                                        </td>
                                        <td class="p-2">
                                            {{
                                                change.after ?? "Not configured"
                                            }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <ul class="list-disc space-y-1 pl-5 text-sm">
                            <li
                                v-for="effect in draft.preview.effects"
                                :key="effect"
                            >
                                {{ effect }}
                            </li>
                        </ul>
                        <p class="text-sm">
                            Requested effect:
                            {{
                                draft.preview.effective_at ??
                                "Immediate after acknowledgement"
                            }}
                        </p>
                        <Link
                            :href="freshAuthentication()"
                            class="text-primary underline"
                            >Confirm password and MFA</Link
                        >
                        <form class="space-y-3" @submit.prevent="publishDraft">
                            <Label for="publication-reason"
                                >Internal reason</Label
                            ><Input
                                id="publication-reason"
                                v-model="publication.reason"
                                maxlength="500"
                            /><label class="flex items-start gap-3 text-sm"
                                ><input
                                    v-model="publication.confirmation"
                                    type="checkbox"
                                    class="mt-1"
                                />I reviewed this prospective change and its
                                impact.</label
                            >
                            <p
                                v-for="(error, key) in publication.errors"
                                :key="key"
                                role="alert"
                                class="text-destructive text-sm"
                            >
                                {{ error }}
                            </p>
                            <Button
                                :disabled="
                                    publication.processing ||
                                    !publication.confirmation ||
                                    unknown
                                "
                                >Publish configuration</Button
                            >
                        </form>
                    </div>
                </template>
            </section>
            <section class="space-y-3" aria-labelledby="readiness-title">
                <h2 id="readiness-title" class="text-lg font-medium">
                    Features and owner readiness
                </h2>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div
                        v-for="(check, code) in settings.readiness"
                        :key="code"
                        class="space-y-2 rounded-xl border p-4"
                    >
                        <h3 class="font-medium">
                            {{
                                settings.definitions[code]?.label ??
                                code.replaceAll("_", " ")
                            }}
                        </h3>
                        <Badge variant="outline">{{ check.state }}</Badge>
                        <p class="text-muted-foreground text-sm">
                            {{ check.owner }} · contract {{ check.version }}
                        </p>
                        <p class="text-sm">
                            {{
                                check.blocker ||
                                "Supported prospective configuration consumer."
                            }}
                        </p>
                    </div>
                </div>
            </section>
            <section class="space-y-4" aria-labelledby="history-title">
                <h2 id="history-title" class="text-lg font-medium">
                    Scheduled changes and version history
                </h2>
                <div class="overflow-x-auto rounded-xl border">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr>
                                <th scope="col" class="p-3">Version</th>
                                <th scope="col" class="p-3">State</th>
                                <th scope="col" class="p-3">
                                    Requested / actual effect
                                </th>
                                <th scope="col" class="p-3">Actions</th>
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
                                    <p v-if="item.failure_code">
                                        {{ item.failure_code }}
                                    </p>
                                </td>
                                <td class="p-3">
                                    {{ item.requested_effective_at }}
                                    <p class="text-muted-foreground">
                                        {{
                                            item.effective_at ?? "Not effective"
                                        }}
                                    </p>
                                </td>
                                <td class="p-3">
                                    <template v-if="settings.can_manage"
                                        ><Button
                                            v-if="
                                                [
                                                    'scheduled',
                                                    'propagation_pending',
                                                    'blocked',
                                                ].includes(item.state)
                                            "
                                            variant="outline"
                                            :disabled="
                                                unknown ||
                                                !lifecycle.confirmation ||
                                                !lifecycle.reason
                                            "
                                            :aria-label="`Cancel pending version ${item.version}`"
                                            @click="cancelVersion(item.id)"
                                            >Cancel pending version</Button
                                        ><Button
                                            v-else-if="
                                                [
                                                    'effective',
                                                    'superseded',
                                                ].includes(item.state)
                                            "
                                            variant="outline"
                                            :disabled="unknown"
                                            :aria-label="`Draft rollback to version ${item.version}`"
                                            @click="rollbackVersion(item.id)"
                                            >Draft rollback</Button
                                        ></template
                                    >
                                </td>
                            </tr>
                            <tr v-if="!settings.history.length">
                                <td
                                    colspan="4"
                                    class="text-muted-foreground p-4"
                                >
                                    No imported or published versions.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="settings.can_manage" class="space-y-2">
                    <Label for="cancel-reason">Cancellation reason</Label
                    ><Input
                        id="cancel-reason"
                        v-model="lifecycle.reason"
                        maxlength="500"
                    /><label class="flex gap-3 text-sm"
                        ><input
                            v-model="lifecycle.confirmation"
                            type="checkbox"
                        />I confirm cancellation of a pending change; fresh
                        authentication is required.</label
                    >
                    <p
                        v-for="(error, key) in lifecycle.errors"
                        :key="key"
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        {{ error }}
                    </p>
                </div>
            </section>
        </template>
    </div>
</template>
