<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { dashboard } from '@/routes';
import {
    index as agentsIndex,
    show as agentShow,
    update as updateAgent,
} from '@/routes/agents';
import { self as changeOwnAgentPhone } from '@/routes/agents/phone';
import { store as correctAgentPhone } from '@/routes/agents/phone-corrections';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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

const submitPhone = (): void => {
    const url = isAgent
        ? changeOwnAgentPhone(props.agent.id).url
        : correctAgentPhone(props.agent.id).url;
    phoneForm.post(url, { preserveScroll: true });
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
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">
                Edit Agent profile
            </h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                Update permitted profile details for {{ agent.name }}.
            </p>
        </div>

        <Card v-if="agent.can_change_phone">
            <CardHeader
                ><CardTitle>Phone number</CardTitle
                ><CardDescription>{{
                    isAgent
                        ? 'Fresh password and authenticator verification are required.'
                        : 'Staff phone corrections are available before account activation and require a reason.'
                }}</CardDescription></CardHeader
            >
            <form @submit.prevent="submitPhone">
                <CardContent class="grid gap-4">
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
                        class="text-destructive text-sm"
                    >
                        {{ phoneFormProfileError || phoneForm.errors.version }}
                    </p>
                    <Button type="submit" :disabled="phoneForm.processing">{{
                        phoneForm.processing ? 'Saving…' : 'Update phone number'
                    }}</Button>
                </CardContent>
            </form>
        </Card>

        <form class="space-y-6" @submit.prevent="submit">
            <Card>
                <CardHeader
                    ><CardTitle>Profile details</CardTitle
                    ><CardDescription
                        >Phone and email use dedicated security
                        workflows.</CardDescription
                    ></CardHeader
                >
                <CardContent class="grid gap-5 sm:grid-cols-2">
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
                    <div class="grid gap-2">
                        <Label for="address">Address</Label
                        ><textarea
                            id="address"
                            v-model="form.address"
                            rows="3"
                            maxlength="500"
                            class="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring flex min-h-20 w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                        />
                        <p
                            v-if="form.errors.address"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.address }}
                        </p>
                    </div>
                    <div v-if="!isAgent" class="grid gap-2">
                        <Label for="employment-date">Engagement date</Label
                        ><Input
                            id="employment-date"
                            v-model="form.employment_date"
                            type="date"
                        />
                        <p
                            v-if="form.errors.employment_date"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.employment_date }}
                        </p>
                    </div>
                    <div v-if="!isAgent" class="grid gap-2 sm:col-span-2">
                        <Label for="notes">Internal notes</Label
                        ><textarea
                            id="notes"
                            v-model="form.notes"
                            rows="4"
                            maxlength="2000"
                            class="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring flex min-h-24 w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                        />
                        <p
                            v-if="form.errors.notes"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.notes }}
                        </p>
                    </div>
                    <div v-if="!isAgent" class="grid gap-2 sm:col-span-2">
                        <Label for="reason">Reason for staff-only changes</Label
                        ><Input
                            id="reason"
                            v-model="form.reason"
                            maxlength="500"
                        />
                        <p
                            v-if="form.errors.reason"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.reason }}
                        </p>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader
                    ><CardTitle>Profile photo</CardTitle
                    ><CardDescription
                        >JPEG, PNG, or WebP. Maximum 5 MB and 4096 × 4096
                        pixels.</CardDescription
                    ></CardHeader
                >
                <CardContent class="grid gap-3">
                    <img
                        v-if="agent.photo_url && !form.remove_photo"
                        :src="agent.photo_url"
                        :alt="agent.name"
                        class="h-20 w-20 rounded-full object-cover"
                    />
                    <Input
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        @change="onPhotoChange"
                    />
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
                </CardContent>
            </Card>

            <p
                v-if="editFormProfileError || form.errors.version"
                class="text-destructive text-sm"
            >
                {{ editFormProfileError || form.errors.version }}
            </p>
            <div class="flex flex-wrap gap-3">
                <Button type="submit" :disabled="form.processing">{{
                    form.processing ? 'Saving…' : 'Save changes'
                }}</Button>
                <Link :href="agentShow(agent.id)"
                    ><Button type="button" variant="outline"
                        >Cancel</Button
                    ></Link
                >
            </div>
        </form>
    </div>
</template>
