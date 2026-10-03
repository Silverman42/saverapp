<script setup lang="ts">
import { HttpResponseError } from '@inertiajs/core';
import { Head, Link, router, useHttp } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    isOperationReference,
    newOperationReference,
} from '@/lib/operation-reference';
import { dashboard } from '@/routes';
import { manage, publicationResult, store } from '@/routes/collection-methods';

type Method = {
    id: number;
    method_key: string;
    version: number;
    label: string;
    custody_account_code: string;
    mapping_version: number;
    destination_key: string;
    attachment_required: boolean;
    effective_at: string;
};
const props = defineProps<{
    methods: {
        data: Method[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    latest_versions: Record<string, number>;
    accounts: Array<{
        code: string;
        version: number;
        mapping_status: string;
        display_name: string | null;
    }>;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Collection methods', href: manage() },
        ],
    },
});
const form = useHttp({
    publication_reference: newOperationReference(),
    method_key: 'transfer',
    version: 1,
    label: '',
    custody_account_code: 'business_bank_ngn',
    mapping_version: 1,
    destination_key: '',
    attachment_required: true,
    reason: '',
});
const lookup = useHttp<
    Record<string, never>,
    {
        method_version_id: number;
        method_key: string;
        version: number;
        label: string;
    }
>({});
const uncertain = ref(false);
const confirmed = ref(false);
const message = ref('');
const storageKey = 'collection-method-publication';
watch(
    () => [
        form.label,
        form.destination_key,
        form.attachment_required,
        form.reason,
    ],
    () => {
        confirmed.value = false;
    },
);
const options = computed(() =>
    props.accounts.filter(
        (account) =>
            form.method_key === 'other' ||
            account.code ===
                (form.method_key === 'transfer'
                    ? 'business_bank_ngn'
                    : 'payment_clearing_ngn'),
    ),
);
const mappingReady = computed(() =>
    options.value.some(
        (account) =>
            account.code === form.custody_account_code &&
            account.mapping_status === 'mapped',
    ),
);
function refreshTerms(): void {
    form.version = Number(props.latest_versions[form.method_key] ?? 0) + 1;
    if (
        !options.value.some(
            (account) => account.code === form.custody_account_code,
        )
    )
        form.custody_account_code = options.value[0]?.code ?? '';
    form.mapping_version =
        options.value.find(
            (account) => account.code === form.custody_account_code,
        )?.version ?? 0;
    confirmed.value = false;
}
watch(
    () => [
        form.method_key,
        form.custody_account_code,
        props.latest_versions,
        props.accounts,
    ],
    refreshTerms,
    { immediate: true },
);
onMounted(() => {
    try {
        const saved = sessionStorage.getItem(storageKey);
        if (isOperationReference(saved)) {
            form.publication_reference = saved;
            uncertain.value = true;
        }
    } catch {
        /* Tab storage is optional. */
    }
});
function clearAttempt(): void {
    try {
        sessionStorage.removeItem(storageKey);
    } catch {
        /* The result is durable. */
    }
}
async function check(): Promise<void> {
    message.value = '';
    try {
        const result = await lookup.get(
            publicationResult.url(form.publication_reference),
        );
        message.value = `${result.label}, version ${result.version}, was published.`;
        clearAttempt();
        uncertain.value = false;
        confirmed.value = false;
        form.publication_reference = newOperationReference();
        form.reason = '';
        router.reload();
    } catch (error) {
        if (
            error instanceof HttpResponseError &&
            error.response.status === 404
        ) {
            message.value =
                'No publication was found for this reference. Refresh the current details and review before retrying.';
            clearAttempt();
            uncertain.value = false;
            confirmed.value = false;
            router.reload();
        } else
            message.value =
                'The result could not be checked. Keep this reference and check again.';
    }
}
async function publish(): Promise<void> {
    if (
        !confirmed.value ||
        uncertain.value ||
        form.processing ||
        !mappingReady.value
    )
        return;
    try {
        sessionStorage.setItem(storageKey, form.publication_reference);
    } catch {
        /* The page retains the reference. */
    }
    try {
        await form.post(store.url());
        message.value = `${form.label}, version ${form.version}, was published.`;
        clearAttempt();
        form.publication_reference = newOperationReference();
        form.reason = '';
        confirmed.value = false;
        router.reload();
    } catch (error) {
        if (
            error instanceof HttpResponseError &&
            [403, 422, 429, 503].includes(error.response.status)
        ) {
            clearAttempt();
            message.value =
                'Publication was not accepted. Check the field errors and fresh Admin authentication.';
        } else {
            uncertain.value = true;
            message.value =
                'The outcome is uncertain or the configuration changed. Check this reference before retrying.';
        }
    }
}
</script>
<template>
    <Head title="Collection methods" />
    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">
                Collection methods
            </h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                Publish reviewed payment destinations and custody instructions.
                Existing receipts retain their agreed method version.
            </p>
        </div>
        <p v-if="message" role="status" class="text-sm">{{ message }}</p>
        <Card
            ><CardContent class="grid gap-4 pt-6">
                <h2 class="font-medium">Publish a method version</h2>
                <div v-if="uncertain" class="grid gap-3" role="status">
                    <p>
                        Check the previous publication before submitting
                        another.
                    </p>
                    <p class="text-sm break-all">
                        {{ form.publication_reference }}
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="lookup.processing"
                        @click="check"
                        >Check publication result</Button
                    >
                </div>
                <form class="grid gap-4" @submit.prevent="publish">
                    <fieldset
                        :disabled="uncertain || form.processing"
                        class="grid gap-4 sm:grid-cols-2"
                    >
                        <legend class="sr-only">
                            Collection method instructions
                        </legend>
                        <div class="grid gap-2">
                            <Label for="method-key">Payment method</Label
                            ><select
                                id="method-key"
                                v-model="form.method_key"
                                class="bg-background h-11 rounded-md border px-3"
                            >
                                <option value="transfer">Bank transfer</option>
                                <option value="pos">POS</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="grid gap-2">
                            <Label for="method-label"
                                >Customer-facing name</Label
                            ><Input
                                id="method-label"
                                v-model="form.label"
                                maxlength="100"
                                required
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="method-custody">Funds held in</Label
                            ><select
                                id="method-custody"
                                v-model="form.custody_account_code"
                                class="bg-background h-11 rounded-md border px-3"
                                required
                            >
                                <option
                                    v-for="account in options"
                                    :key="account.code"
                                    :value="account.code"
                                    :disabled="
                                        account.mapping_status !== 'mapped'
                                    "
                                >
                                    {{ account.display_name ?? account.code }} ·
                                    {{ account.mapping_status }}
                                </option>
                            </select>
                        </div>
                        <div class="grid gap-2">
                            <Label for="method-destination"
                                >Verified destination identifier</Label
                            ><Input
                                id="method-destination"
                                v-model="form.destination_key"
                                maxlength="100"
                                pattern="[A-Za-z0-9._-]+"
                                required
                            />
                        </div>
                        <p class="text-muted-foreground text-sm sm:col-span-2">
                            Publishing version {{ form.version }} against
                            custody mapping version {{ form.mapping_version }}.
                            Confirm the destination independently before
                            publication.
                        </p>
                        <p
                            v-if="!mappingReady"
                            role="status"
                            class="text-muted-foreground text-sm sm:col-span-2"
                        >
                            Publication is unavailable until the selected
                            custody account has a current approved mapping.
                        </p>
                        <label class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="form.attachment_required"
                                type="checkbox"
                            />
                            Require a protected supporting file</label
                        >
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="method-reason"
                                >Internal publication reason</Label
                            ><textarea
                                id="method-reason"
                                v-model="form.reason"
                                class="bg-background min-h-24 rounded-md border p-3 text-sm"
                                minlength="10"
                                maxlength="2000"
                                required
                            />
                        </div>
                        <label
                            class="flex items-start gap-2 text-sm sm:col-span-2"
                            ><input
                                v-model="confirmed"
                                type="checkbox"
                                class="mt-1"
                            />
                            I reviewed the destination, custody and evidence
                            requirements.</label
                        >
                    </fieldset>
                    <div
                        v-if="Object.keys(form.errors).length"
                        role="alert"
                        class="text-destructive grid gap-1 text-sm"
                    >
                        <p v-for="(error, field) in form.errors" :key="field">
                            {{ field }}: {{ error }}
                        </p>
                    </div>
                    <Button
                        type="submit"
                        class="w-fit"
                        :disabled="
                            !confirmed ||
                            uncertain ||
                            form.processing ||
                            !mappingReady
                        "
                        >Publish reviewed version</Button
                    >
                </form>
            </CardContent></Card
        >
        <Card
            ><CardContent class="grid gap-4 pt-6"
                ><h2 class="font-medium">Published history</h2>
                <p
                    v-if="!methods.data.length"
                    class="text-muted-foreground text-sm"
                >
                    No collection method has been published.
                </p>
                <div
                    v-for="method in methods.data"
                    :key="method.id"
                    class="grid gap-1 border-b pb-3 text-sm last:border-0"
                >
                    <p class="font-medium">
                        {{ method.label }} · {{ method.method_key }} · version
                        {{ method.version }}
                    </p>
                    <p>Destination {{ method.destination_key }}</p>
                    <p>
                        {{ method.custody_account_code }} · mapping
                        {{ method.mapping_version }} ·
                        {{
                            method.attachment_required
                                ? 'Protected file required'
                                : 'Supporting file optional'
                        }}
                    </p>
                    <p class="text-muted-foreground">
                        Published {{ method.effective_at }}
                    </p>
                </div></CardContent
            ></Card
        >
        <nav aria-label="Method history pages" class="flex gap-4">
            <Link
                v-if="methods.prev_page_url"
                :href="methods.prev_page_url"
                class="underline"
                >Previous</Link
            ><Link
                v-if="methods.next_page_url"
                :href="methods.next_page_url"
                class="underline"
                >Next</Link
            >
        </nav>
    </div>
</template>
