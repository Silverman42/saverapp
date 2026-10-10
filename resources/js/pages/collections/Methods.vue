<script setup lang="ts">
import { HttpResponseError } from '@inertiajs/core';
import { Head, Link, router, useHttp } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import FormSheet from '@/components/FormSheet.vue';
import MoreDetails from '@/components/MoreDetails.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { dashboard, freshAuthentication } from '@/routes';
import { manage, publicationResult, store } from '@/routes/collection-methods';
import { showToast } from '@/lib/flashToast';

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
const sheetOpen = ref(false);
const methodNames: Record<string, string> = {
    transfer: 'Bank transfer',
    pos: 'POS',
    other: 'Other',
};
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
        message.value = `${result.label} was published.`;
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
                'It was not published. Check the details and try again.';
            clearAttempt();
            uncertain.value = false;
            confirmed.value = false;
            router.reload();
        } else
            message.value =
                'We could not check right now. Try again in a moment.';
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
        message.value = `${form.label} was published.`;
        showToast({
            type: 'success',
            title: 'Method published',
            description: message.value,
        });
        sheetOpen.value = false;
        clearAttempt();
        form.publication_reference = newOperationReference();
        form.reason = '';
        confirmed.value = false;
        router.reload();
    } catch (error) {
        if (
            error instanceof HttpResponseError &&
            error.response.status === 423
        ) {
            clearAttempt();
            message.value = 'Confirm it is you, then publish again.';
            router.visit(freshAuthentication());
        } else if (
            error instanceof HttpResponseError &&
            [403, 409, 422, 429, 503].includes(error.response.status)
        ) {
            clearAttempt();
            message.value =
                'This was not published. Fix the errors below, or sign in again if asked.';
        } else {
            uncertain.value = true;
            message.value =
                'We are not sure this was published. Check the result before trying again.';
        }
    }
}
</script>
<template>
    <Head title="Collection methods" />
    <div class="flex flex-col gap-6">
        <PageHeader
            title="Collection methods"
            description="Ways customers can pay, like bank transfer or POS."
        >
            <template #actions>
                <Button type="button" @click="sheetOpen = true"
                    >Add method</Button
                >
            </template>
        </PageHeader>
        <p
            v-if="message && !sheetOpen"
            role="status"
            class="bg-muted rounded-xl p-4 text-sm"
        >
            {{ message }}
        </p>
        <div
            v-if="uncertain && !sheetOpen"
            class="bg-muted flex flex-wrap items-center justify-between gap-3 rounded-xl p-4 text-sm"
            role="status"
        >
            <p>Check your last change before adding another.</p>
            <Button
                type="button"
                variant="outline"
                :disabled="lookup.processing"
                @click="check"
                >Check result</Button
            >
        </div>

        <EmptyState
            v-if="!methods.data.length"
            title="No collection methods yet"
            description="Add a method so agents can record transfers and POS payments."
        >
            <Button type="button" @click="sheetOpen = true">Add method</Button>
        </EmptyState>
        <Card v-else>
            <CardHeader><CardTitle>Published methods</CardTitle></CardHeader>
            <CardContent>
                <ul class="divide-y">
                    <li
                        v-for="method in methods.data"
                        :key="method.id"
                        class="grid gap-2 py-4 text-sm first:pt-0 last:pb-0"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-2"
                        >
                            <p class="font-medium">{{ method.label }}</p>
                            <Badge variant="secondary">{{
                                methodNames[method.method_key] ??
                                method.method_key
                            }}</Badge>
                        </div>
                        <p class="text-muted-foreground text-xs">
                            Since {{ method.effective_at }} ·
                            {{
                                method.attachment_required
                                    ? 'Proof required'
                                    : 'Proof optional'
                            }}
                        </p>
                        <MoreDetails>
                            <dl
                                class="text-muted-foreground grid gap-1 text-xs break-all"
                            >
                                <div>
                                    <dt class="text-foreground inline">
                                        Account ID:
                                    </dt>
                                    <dd class="inline">
                                        {{ method.destination_key }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-foreground inline">
                                        Money held in:
                                    </dt>
                                    <dd class="inline">
                                        {{ method.custody_account_code }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-foreground inline">
                                        Version:
                                    </dt>
                                    <dd class="inline">
                                        {{ method.version }} (account setup
                                        {{ method.mapping_version }})
                                    </dd>
                                </div>
                            </dl>
                        </MoreDetails>
                    </li>
                </ul>
            </CardContent>
        </Card>
        <nav
            v-if="methods.prev_page_url || methods.next_page_url"
            aria-label="Method history pages"
            class="flex gap-4 text-sm"
        >
            <Link
                v-if="methods.prev_page_url"
                :href="methods.prev_page_url"
                class="underline-offset-4 hover:underline"
                >Previous</Link
            ><Link
                v-if="methods.next_page_url"
                :href="methods.next_page_url"
                class="underline-offset-4 hover:underline"
                >Next</Link
            >
        </nav>

        <FormSheet
            v-model:open="sheetOpen"
            title="Add collection method"
            description="Existing payments keep the method they were recorded with."
        >
            <div class="grid gap-5">
                <p
                    v-if="message"
                    role="status"
                    class="bg-muted rounded-xl p-3 text-sm"
                >
                    {{ message }}
                </p>
                <div
                    v-if="uncertain"
                    class="bg-muted grid gap-3 rounded-xl p-3 text-sm"
                    role="status"
                >
                    <p>Check your last change before adding another.</p>
                    <p class="text-muted-foreground text-xs break-all">
                        Reference: {{ form.publication_reference }}
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        class="w-fit"
                        :disabled="lookup.processing"
                        @click="check"
                        >Check result</Button
                    >
                </div>
                <form
                    id="method-form"
                    class="grid gap-5"
                    @submit.prevent="publish"
                >
                    <fieldset
                        :disabled="uncertain || form.processing"
                        class="grid gap-5"
                    >
                        <legend class="sr-only">
                            Collection method details
                        </legend>
                        <div class="grid gap-2">
                            <Label for="method-key">Payment type</Label
                            ><Select v-model="form.method_key"
                                ><SelectTrigger
                                    id="method-key"
                                    class="h-11 w-full"
                                    ><SelectValue /></SelectTrigger
                                ><SelectContent>
                                    <SelectItem value="transfer"
                                        >Bank transfer</SelectItem
                                    >
                                    <SelectItem value="pos">POS</SelectItem>
                                    <SelectItem value="other">Other</SelectItem>
                                </SelectContent></Select
                            >
                        </div>
                        <div class="grid gap-2">
                            <Label for="method-label">Name customers see</Label
                            ><Input
                                id="method-label"
                                v-model="form.label"
                                maxlength="100"
                                placeholder="For example: GTBank transfer"
                                required
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="method-custody">Money goes to</Label
                            ><Select
                                v-model="form.custody_account_code"
                                required
                                ><SelectTrigger
                                    id="method-custody"
                                    class="h-11 w-full"
                                    ><SelectValue /></SelectTrigger
                                ><SelectContent>
                                    <SelectItem
                                        v-for="account in options"
                                        :key="account.code"
                                        :value="account.code"
                                        :disabled="
                                            account.mapping_status !== 'mapped'
                                        "
                                    >
                                        {{ account.display_name ?? account.code
                                        }}{{
                                            account.mapping_status !== 'mapped'
                                                ? ' (not ready)'
                                                : ''
                                        }}
                                    </SelectItem>
                                </SelectContent></Select
                            >
                            <p
                                v-if="!mappingReady"
                                role="status"
                                class="text-muted-foreground text-xs"
                            >
                                This account is not ready yet. Its accounting
                                setup needs approval first.
                            </p>
                        </div>
                        <div class="grid gap-2">
                            <Label for="method-destination">Account ID</Label
                            ><Input
                                id="method-destination"
                                v-model="form.destination_key"
                                maxlength="100"
                                pattern="[A-Za-z0-9._-]+"
                                required
                            />
                            <p class="text-muted-foreground text-xs">
                                Letters, numbers, dots, dashes and underscores.
                                Double-check it before you publish.
                            </p>
                        </div>
                        <label class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="form.attachment_required"
                                type="checkbox"
                            />
                            Ask for proof of payment</label
                        >
                        <div class="grid gap-2">
                            <Label for="method-reason"
                                >Reason (staff only)</Label
                            ><textarea
                                id="method-reason"
                                v-model="form.reason"
                                class="bg-background min-h-24 rounded-md border p-3 text-sm"
                                minlength="10"
                                maxlength="2000"
                                required
                            />
                        </div>
                        <label class="flex items-start gap-2 text-sm"
                            ><input
                                v-model="confirmed"
                                type="checkbox"
                                class="mt-1"
                            />
                            I checked the account details and proof
                            setting.</label
                        >
                    </fieldset>
                    <div
                        v-if="Object.keys(form.errors).length"
                        role="alert"
                        class="text-destructive grid gap-1 text-sm"
                    >
                        <p v-for="(error, field) in form.errors" :key="field">
                            {{ error }}
                        </p>
                    </div>
                    <MoreDetails>
                        <p class="text-muted-foreground text-xs">
                            This will be version {{ form.version }}, using
                            account setup version {{ form.mapping_version }}.
                        </p>
                    </MoreDetails>
                </form>
            </div>
            <template #footer>
                <Button
                    type="button"
                    variant="outline"
                    @click="sheetOpen = false"
                    >Cancel</Button
                >
                <Button
                    type="submit"
                    form="method-form"
                    :disabled="
                        !confirmed ||
                        uncertain ||
                        form.processing ||
                        !mappingReady
                    "
                    >Publish</Button
                >
            </template>
        </FormSheet>
    </div>
</template>
