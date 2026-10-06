<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { dashboard } from '@/routes';
import {
    index as agentsIndex,
    show as agentShow,
    update as updateAgent,
} from '@/routes/agents';
import { self as changeOwnAgentPhone } from '@/routes/agents/phone';
import { store as correctAgentPhone } from '@/routes/agents/phone-corrections';
import FormSheet from '@/components/FormSheet.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { DatePicker } from '@/components/ui/date-picker';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type AgentProfile = {
    id: string;
    name: string;
    phone: string;
    address: string | null;
    employment_date: string | null;
    notes: string | null;
    photo_url: string | null;
    version: number;
    account_state: string | null;
    can_change_phone: boolean;
};

const props = defineProps<{ agent: AgentProfile; viewer_type: string }>();
const isAgent = props.viewer_type === 'agent';
const form = useForm({
    address: props.agent.address ?? '',
    ...(!isAgent
        ? {
              name: props.agent.name,
              employment_date: props.agent.employment_date ?? '',
              notes: props.agent.notes ?? '',
              reason: '',
          }
        : {}),
    photo: null as File | null,
    remove_photo: false,
    version: props.agent.version,
});
const phoneForm = useForm({
    phone: props.agent.phone,
    reason: '',
    version: props.agent.version,
});
const phoneFormProfileError = computed(
    () => (phoneForm.errors as unknown as { profile?: string }).profile,
);
const editFormProfileError = computed(
    () => (form.errors as unknown as { profile?: string }).profile,
);

const onPhotoChange = (event: Event): void => {
    const target = event.target as HTMLInputElement;
    form.photo = target.files?.[0] ?? null;
    if (form.photo) form.remove_photo = false;
};

const submit = (): void => {
    form.patch(updateAgent(props.agent.id).url, {
        forceFormData: true,
        preserveScroll: true,
    });
};

const phoneSheetOpen = ref(false);

const submitPhone = (): void => {
    const url = isAgent
        ? changeOwnAgentPhone(props.agent.id).url
        : correctAgentPhone(props.agent.id).url;
    phoneForm.post(url, {
        preserveScroll: true,
        onSuccess: () => {
            phoneSheetOpen.value = false;
        },
    });
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Agents', href: agentsIndex() },
            { title: 'Edit profile', href: '#' },
        ],
    },
});
</script>

<template>
    <Head :title="`Edit ${agent.name}`" />

    <div class="space-y-6">
        <PageHeader title="Edit profile" :description="agent.name" />

        <form class="max-w-3xl" @submit.prevent="submit">
            <Card>
                <CardHeader>
                    <CardTitle>Profile</CardTitle>
                </CardHeader>
                <CardContent class="grid gap-5 sm:grid-cols-2">
                    <div
                        v-if="agent.can_change_phone"
                        class="bg-muted/40 flex flex-wrap items-center justify-between gap-3 rounded-xl p-4 sm:col-span-2"
                    >
                        <div>
                            <p class="text-muted-foreground text-xs">
                                Phone number
                            </p>
                            <p class="text-sm font-medium">{{ agent.phone }}</p>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="phoneSheetOpen = true"
                            >Change</Button
                        >
                    </div>
                    <div v-if="!isAgent" class="grid gap-2">
                        <Label for="name">Full name</Label
                        ><Input id="name" v-model="form.name" maxlength="150" />
                        <p
                            v-if="form.errors.name"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.name }}
                        </p>
                    </div>
                    <div v-if="!isAgent" class="grid gap-2">
                        <Label for="employment-date">Start date</Label
                        ><DatePicker
                            id="employment-date"
                            v-model="form.employment_date"
                            :error-message="form.errors.employment_date"
                        />
                        <p
                            v-if="form.errors.employment_date"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.employment_date }}
                        </p>
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="address">Address</Label
                        ><textarea
                            id="address"
                            v-model="form.address"
                            rows="2"
                            maxlength="500"
                            class="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring flex min-h-16 w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                        />
                        <p
                            v-if="form.errors.address"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.address }}
                        </p>
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="agent-photo">Photo</Label>
                        <div class="flex items-center gap-4">
                            <img
                                v-if="agent.photo_url && !form.remove_photo"
                                :src="agent.photo_url"
                                :alt="agent.name"
                                class="size-14 shrink-0 rounded-full object-cover"
                            />
                            <Input
                                id="agent-photo"
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                @change="onPhotoChange"
                            />
                        </div>
                        <p class="text-muted-foreground text-xs">
                            JPEG, PNG or WebP, up to 5 MB.
                        </p>
                        <p
                            v-if="form.errors.photo"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.photo }}
                        </p>
                        <label
                            v-if="agent.photo_url"
                            class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="form.remove_photo"
                                type="checkbox"
                                @change="form.photo = null"
                            />
                            Remove current photo</label
                        >
                    </div>
                    <div v-if="!isAgent" class="grid gap-2 sm:col-span-2">
                        <Label for="notes">Notes</Label
                        ><textarea
                            id="notes"
                            v-model="form.notes"
                            rows="3"
                            maxlength="2000"
                            class="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring flex min-h-20 w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                        />
                        <p class="text-muted-foreground text-xs">
                            Only admins can see these.
                        </p>
                        <p
                            v-if="form.errors.notes"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.notes }}
                        </p>
                    </div>
                    <div v-if="!isAgent" class="grid gap-2 sm:col-span-2">
                        <Label for="reason">Reason for change</Label
                        ><Input
                            id="reason"
                            v-model="form.reason"
                            maxlength="500"
                        />
                        <p class="text-muted-foreground text-xs">
                            Needed if you change the name, start date or notes.
                        </p>
                        <p
                            v-if="form.errors.reason"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.reason }}
                        </p>
                    </div>
                </CardContent>
                <CardFooter
                    class="flex flex-col items-stretch gap-3 border-t pt-6"
                >
                    <p
                        v-if="editFormProfileError || form.errors.version"
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        {{ editFormProfileError || form.errors.version }}
                    </p>
                    <div class="flex justify-end gap-2">
                        <Button as-child type="button" variant="outline">
                            <Link :href="agentShow(agent.id)">Cancel</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">{{
                            form.processing ? 'Saving…' : 'Save changes'
                        }}</Button>
                    </div>
                </CardFooter>
            </Card>
        </form>

        <FormSheet
            v-if="agent.can_change_phone"
            v-model:open="phoneSheetOpen"
            title="Change phone number"
            :description="
                isAgent
                    ? 'You will need to enter your password and authenticator code again.'
                    : 'You can only fix a phone number before the agent activates their account.'
            "
        >
            <form
                id="phone-form"
                class="grid gap-5"
                @submit.prevent="submitPhone"
            >
                <div class="grid gap-2">
                    <Label for="agent-phone">Phone number</Label
                    ><Input
                        id="agent-phone"
                        v-model="phoneForm.phone"
                        type="tel"
                        maxlength="50"
                        required
                    />
                    <p
                        v-if="phoneForm.errors.phone"
                        class="text-destructive text-sm"
                    >
                        {{ phoneForm.errors.phone }}
                    </p>
                </div>
                <div v-if="!isAgent" class="grid gap-2">
                    <Label for="phone-reason">Reason</Label
                    ><Input
                        id="phone-reason"
                        v-model="phoneForm.reason"
                        maxlength="500"
                        required
                    />
                    <p
                        v-if="phoneForm.errors.reason"
                        class="text-destructive text-sm"
                    >
                        {{ phoneForm.errors.reason }}
                    </p>
                </div>
                <p
                    v-if="phoneFormProfileError || phoneForm.errors.version"
                    role="alert"
                    class="text-destructive text-sm"
                >
                    {{ phoneFormProfileError || phoneForm.errors.version }}
                </p>
            </form>
            <template #footer>
                <Button
                    type="button"
                    variant="outline"
                    @click="phoneSheetOpen = false"
                    >Cancel</Button
                >
                <Button
                    type="submit"
                    form="phone-form"
                    :disabled="phoneForm.processing"
                    >{{ phoneForm.processing ? 'Saving…' : 'Save' }}</Button
                >
            </template>
        </FormSheet>
    </div>
</template>
