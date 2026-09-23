<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
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

const submitName = (): void => {
    const url = isCustomer.value
        ? changeOwnName(props.customer.id).url
        : proposeCustomerName(props.customer.id).url;
    nameForm.post(url, { preserveScroll: true });
};

const submitPhone = (): void => {
    const url = isCustomer.value
        ? changeOwnCustomerPhone(props.customer.id).url
        : correctCustomerPhone(props.customer.id).url;
    phoneForm.post(url, { preserveScroll: true });
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

    <div class="space-y-6">
        <div>
            <h1 class="text-[25px] font-medium tracking-tight">
                Edit Customer profile
            </h1>
            <p class="text-muted-foreground mt-1.5 text-sm">
                Update permitted profile details for {{ customer.name }}.
            </p>
        </div>

        <Card>
            <CardHeader
                ><CardTitle>Name</CardTitle
                ><CardDescription>{{
                    isCustomer
                        ? 'Confirm your name change with fresh authentication.'
                        : 'For an active Customer, the new name is sent for Customer confirmation.'
                }}</CardDescription></CardHeader
            >
            <form @submit.prevent="submitName">
                <CardContent class="grid gap-4">
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
                        class="text-destructive text-sm"
                    >
                        {{ nameFormProfileError }}
                    </p>
                    <Button type="submit" :disabled="nameForm.processing">{{
                        isCustomer ? 'Update my name' : 'Submit name correction'
                    }}</Button>
                </CardContent>
            </form>
        </Card>

        <Card v-if="customer.can_change_phone">
            <CardHeader
                ><CardTitle>Phone number</CardTitle
                ><CardDescription>{{
                    isCustomer
                        ? 'Fresh authentication is required to update your phone number.'
                        : 'Staff phone corrections are available before account activation and require a reason.'
                }}</CardDescription></CardHeader
            >
            <form @submit.prevent="submitPhone">
                <CardContent class="grid gap-4">
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
                <CardHeader>
                    <CardTitle>Personal details</CardTitle>
                    <CardDescription
                        >Name, phone, and email use their dedicated confirmation
                        workflows.</CardDescription
                    >
                </CardHeader>
                <CardContent class="grid gap-5 sm:grid-cols-2">
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="address">Address</Label>
                        <textarea
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
                    <div class="grid gap-2">
                        <Label for="gender">Gender</Label>
                        <select
                            id="gender"
                            v-model="form.gender"
                            class="border-input bg-background h-11 rounded-md border px-3 text-sm"
                        >
                            <option value="">Not specified</option>
                            <option value="female">Female</option>
                            <option value="male">Male</option>
                            <option value="other">Other</option>
                            <option value="prefer_not_to_say">
                                Prefer not to say
                            </option>
                        </select>
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
                    <CardTitle>Next of kin</CardTitle>
                    <CardDescription
                        >Provide all required contact details, or leave every
                        field blank to remove the contact.</CardDescription
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
                    ><CardTitle>Staff-only details</CardTitle
                    ><CardDescription
                        >These fields are visible to authorized staff
                        only.</CardDescription
                    ></CardHeader
                >
                <CardContent class="grid gap-5">
                    <div class="grid gap-2">
                        <Label for="internal-reference"
                            >Internal reference</Label
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
                    <div class="grid gap-2">
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
                        v-if="customer.photo_url && !form.remove_photo"
                        :src="customer.photo_url"
                        :alt="customer.name"
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
                        v-if="customer.photo_url"
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
                <Link :href="customerShow(customer.id)"
                    ><Button type="button" variant="outline"
                        >Cancel</Button
                    ></Link
                >
            </div>
        </form>
    </div>
</template>
