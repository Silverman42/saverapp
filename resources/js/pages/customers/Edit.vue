<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { dashboard } from '@/routes';
import {
    index as customersIndex,
    show as customerShow,
    update as updateCustomer,
} from '@/routes/customers';
import { update as changeOwnName } from '@/routes/customers/name';
import { store as proposeCustomerName } from '@/routes/customers/name-corrections';
import { self as changeOwnCustomerPhone } from '@/routes/customers/phone';
import { store as correctCustomerPhone } from '@/routes/customers/phone-corrections';
import FormSheet from '@/components/FormSheet.vue';
import PageHeader from '@/components/PageHeader.vue';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type NextOfKin = {
    full_name: string | null;
    relationship: string | null;
    phone: string | null;
    address: string | null;
};

type CustomerProfile = {
    id: string;
    name: string;
    phone: string;
    address: string | null;
    gender: string | null;
    occupation: string | null;
    next_of_kin: NextOfKin | null;
    notes: string | null;
    internal_reference: string | null;
    photo_url: string | null;
    version: number;
    account_state: string | null;
    can_change_phone: boolean;
};

const props = defineProps<{ customer: CustomerProfile; viewer_type: string }>();
const isCustomer = computed(() => props.viewer_type === 'customer');
const form = useForm({
    address: props.customer.address ?? '',
    gender: props.customer.gender ?? '',
    occupation: props.customer.occupation ?? '',
    next_of_kin: {
        full_name: props.customer.next_of_kin?.full_name ?? '',
        relationship: props.customer.next_of_kin?.relationship ?? '',
        phone: props.customer.next_of_kin?.phone ?? '',
        address: props.customer.next_of_kin?.address ?? '',
    },
    ...(!isCustomer.value
        ? {
              notes: props.customer.notes ?? '',
              internal_reference: props.customer.internal_reference ?? '',
              reason: '',
          }
        : {}),
    photo: null as File | null,
    remove_photo: false,
    version: props.customer.version,
});
const genderSelection = computed({
    get: () => form.gender || '__none',
    set: (value: string) => {
        form.gender = value === '__none' ? '' : value;
    },
});
const nameForm = useForm({
    name: props.customer.name,
    reason: '',
    version: props.customer.version,
});
const phoneForm = useForm({
    phone: props.customer.phone,
    reason: '',
    version: props.customer.version,
});
const nameFormProfileError = computed(
    () => (nameForm.errors as unknown as { profile?: string }).profile,
);
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
    form.patch(updateCustomer(props.customer.id).url, {
        forceFormData: true,
        preserveScroll: true,
    });
};

const nameSheetOpen = ref(false);
const phoneSheetOpen = ref(false);

const textareaClass =
    'border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring flex min-h-20 w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';

const submitName = (): void => {
    const url = isCustomer.value
        ? changeOwnName(props.customer.id).url
        : proposeCustomerName(props.customer.id).url;
    nameForm.post(url, {
        preserveScroll: true,
        onSuccess: () => {
            nameSheetOpen.value = false;
        },
    });
};

const submitPhone = (): void => {
    const url = isCustomer.value
        ? changeOwnCustomerPhone(props.customer.id).url
        : correctCustomerPhone(props.customer.id).url;
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
            { title: 'Customers', href: customersIndex() },
            { title: 'Edit profile', href: '#' },
        ],
    },
});
</script>

<template>
    <Head :title="`Edit ${customer.name}`" />

    <div class="mx-auto w-full max-w-3xl space-y-6">
        <PageHeader title="Edit profile" :description="customer.name" />

        <Card>
            <CardContent>
                <dl class="divide-y text-sm">
                    <div
                        class="flex flex-wrap items-center justify-between gap-3 pb-3"
                    >
                        <div>
                            <dt class="text-muted-foreground">Name</dt>
                            <dd class="mt-0.5 font-medium">
                                {{ customer.name }}
                            </dd>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="nameSheetOpen = true"
                            >Change</Button
                        >
                    </div>
                    <div
                        class="flex flex-wrap items-center justify-between gap-3 pt-3"
                    >
                        <div>
                            <dt class="text-muted-foreground">Phone</dt>
                            <dd class="mt-0.5 font-medium">
                                {{ customer.phone }}
                            </dd>
                        </div>
                        <Button
                            v-if="customer.can_change_phone"
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="phoneSheetOpen = true"
                            >Change</Button
                        >
                    </div>
                </dl>
            </CardContent>
        </Card>

        <form class="space-y-6" @submit.prevent="submit">
            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Personal details</CardTitle>
                </CardHeader>
                <CardContent class="grid gap-5 sm:grid-cols-2">
                    <div class="flex items-center gap-4 sm:col-span-2">
                        <img
                            v-if="customer.photo_url && !form.remove_photo"
                            :src="customer.photo_url"
                            :alt="customer.name"
                            class="size-16 shrink-0 rounded-full object-cover"
                        />
                        <div class="grid min-w-0 flex-1 gap-2">
                            <Label for="customer-photo">Photo</Label>
                            <Input
                                id="customer-photo"
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                @change="onPhotoChange"
                            />
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
                                v-if="customer.photo_url"
                                class="flex items-center gap-2 text-sm"
                                ><input
                                    v-model="form.remove_photo"
                                    type="checkbox"
                                    @change="form.photo = null"
                                />
                                Remove current photo</label
                            >
                        </div>
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="address">Address</Label>
                        <textarea
                            id="address"
                            v-model="form.address"
                            rows="2"
                            maxlength="500"
                            :class="textareaClass"
                        />
                        <p
                            v-if="form.errors.address"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.address }}
                        </p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="gender">Gender</Label>
                        <Select v-model="genderSelection"
                            ><SelectTrigger id="gender" class="h-11 w-full"
                                ><SelectValue /></SelectTrigger
                            ><SelectContent>
                                <SelectItem value="__none"
                                    >Not specified</SelectItem
                                >
                                <SelectItem value="female">Female</SelectItem>
                                <SelectItem value="male">Male</SelectItem>
                                <SelectItem value="other">Other</SelectItem>
                                <SelectItem value="prefer_not_to_say"
                                    >Prefer not to say</SelectItem
                                >
                            </SelectContent></Select
                        >
                        <p
                            v-if="form.errors.gender"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.gender }}
                        </p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="occupation">Occupation</Label>
                        <Input
                            id="occupation"
                            v-model="form.occupation"
                            maxlength="100"
                        />
                        <p
                            v-if="form.errors.occupation"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.occupation }}
                        </p>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Next of kin</CardTitle>
                    <CardDescription
                        >Fill in all fields, or clear them all to
                        remove.</CardDescription
                    >
                </CardHeader>
                <CardContent class="grid gap-5 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="nok-name">Full name</Label
                        ><Input
                            id="nok-name"
                            v-model="form.next_of_kin.full_name"
                            maxlength="150"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="nok-relationship">Relationship</Label
                        ><Input
                            id="nok-relationship"
                            v-model="form.next_of_kin.relationship"
                            maxlength="50"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="nok-phone">Phone</Label
                        ><Input
                            id="nok-phone"
                            v-model="form.next_of_kin.phone"
                            type="tel"
                            maxlength="50"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="nok-address">Address</Label
                        ><Input
                            id="nok-address"
                            v-model="form.next_of_kin.address"
                            maxlength="500"
                        />
                    </div>
                    <p
                        v-if="form.errors.next_of_kin"
                        class="text-destructive text-sm sm:col-span-2"
                    >
                        {{ form.errors.next_of_kin }}
                    </p>
                </CardContent>
            </Card>

            <Card v-if="!isCustomer">
                <CardHeader
                    ><CardTitle class="text-base">Staff only</CardTitle
                    ><CardDescription
                        >The customer can't see these.</CardDescription
                    ></CardHeader
                >
                <CardContent class="grid gap-5">
                    <div class="grid gap-2">
                        <Label for="notes">Notes</Label
                        ><textarea
                            id="notes"
                            v-model="form.notes"
                            rows="3"
                            maxlength="2000"
                            :class="textareaClass"
                        />
                        <p
                            v-if="form.errors.notes"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.notes }}
                        </p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="internal-reference">Reference</Label
                        ><Input
                            id="internal-reference"
                            v-model="form.internal_reference"
                            maxlength="50"
                        />
                        <p
                            v-if="form.errors.internal_reference"
                            class="text-destructive text-sm"
                        >
                            {{ form.errors.internal_reference }}
                        </p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="reason">Reason for these changes</Label
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

            <div
                class="bg-background/95 sticky bottom-0 z-10 -mx-1 flex flex-wrap items-center gap-3 border-t px-1 py-4 backdrop-blur"
            >
                <Button type="submit" :disabled="form.processing">{{
                    form.processing ? 'Saving…' : 'Save'
                }}</Button>
                <Button as-child type="button" variant="outline">
                    <Link :href="customerShow(customer.id)">Cancel</Link>
                </Button>
                <p
                    v-if="editFormProfileError || form.errors.version"
                    role="alert"
                    class="text-destructive text-sm"
                >
                    {{ editFormProfileError || form.errors.version }}
                </p>
            </div>
        </form>

        <FormSheet
            v-model:open="nameSheetOpen"
            title="Change name"
            :description="
                isCustomer
                    ? 'You will be asked to confirm it is you.'
                    : 'For an active customer, they will be asked to confirm the new name.'
            "
        >
            <form
                id="name-form"
                class="grid gap-5"
                @submit.prevent="submitName"
            >
                <div class="grid gap-2">
                    <Label for="customer-name">Full name</Label
                    ><Input
                        id="customer-name"
                        v-model="nameForm.name"
                        maxlength="150"
                        required
                    />
                    <p
                        v-if="nameForm.errors.name"
                        class="text-destructive text-sm"
                    >
                        {{ nameForm.errors.name }}
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="name-reason">Reason</Label
                    ><Input
                        id="name-reason"
                        v-model="nameForm.reason"
                        maxlength="500"
                        required
                    />
                    <p
                        v-if="nameForm.errors.reason"
                        class="text-destructive text-sm"
                    >
                        {{ nameForm.errors.reason }}
                    </p>
                </div>
                <p
                    v-if="nameFormProfileError"
                    role="alert"
                    class="text-destructive text-sm"
                >
                    {{ nameFormProfileError }}
                </p>
            </form>
            <template #footer>
                <Button
                    type="button"
                    variant="outline"
                    @click="nameSheetOpen = false"
                    >Cancel</Button
                >
                <Button
                    type="submit"
                    form="name-form"
                    :disabled="nameForm.processing"
                    >{{ isCustomer ? 'Save' : 'Send for approval' }}</Button
                >
            </template>
        </FormSheet>

        <FormSheet
            v-if="customer.can_change_phone"
            v-model:open="phoneSheetOpen"
            title="Change phone"
            :description="
                isCustomer
                    ? 'You will be asked to confirm it is you.'
                    : 'You can only change this before the customer sets up their account.'
            "
        >
            <form
                id="phone-form"
                class="grid gap-5"
                @submit.prevent="submitPhone"
            >
                <div class="grid gap-2">
                    <Label for="customer-phone">Phone number</Label
                    ><Input
                        id="customer-phone"
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
                <div v-if="!isCustomer" class="grid gap-2">
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
