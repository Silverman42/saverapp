<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Camera, Coins, Loader2, ShieldAlert, UserCheck } from '@lucide/vue';
import MoreDetails from '@/components/MoreDetails.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { dashboard } from '@/routes';
import {
    create as customersCreate,
    index as customersIndex,
    store as customersStore,
} from '@/routes/customers';

export type FeePreview = {
    available: boolean;
    rule_id?: string | number;
    version?: number;
    name?: string;
    model?: string;
    amount_kobo?: number;
    formatted_amount?: string;
    currency?: string;
    customer_description?: string;
    is_zero?: boolean;
    timing?: string;
    basis?: string;
    quote?: {
        rule_id: number;
        rule_version: number;
        amount_kobo: number;
        currency: string;
        basis_kobo: number;
        timing: string;
        basis: string;
        source_type: string;
        source_id: string;
    };
    message?: string;
};

const props = defineProps<{
    attempt_reference: string;
    fee_preview: FeePreview;
}>();

defineOptions({
    inheritAttrs: false,
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Customers', href: customersIndex() },
            { title: 'Add customer', href: customersCreate() },
        ],
    },
});

const photoPreview = ref<string | null>(null);
const photoInput = ref<HTMLInputElement | null>(null);

const form = useForm({
    attempt_reference: props.attempt_reference,
    fee_rule_version: props.fee_preview.version ?? 0,
    name: '',
    email: '',
    phone: '',
    address: '',
    gender: '' as string,
    occupation: '',
    internal_reference: '',
    notes: '',
    photo: null as File | null,
    next_of_kin: {
        full_name: '',
        relationship: '',
        phone: '',
        address: '',
    },
});

const hasMoreOptionErrors = computed(() =>
    (
        [
            'gender',
            'occupation',
            'internal_reference',
            'photo',
            'notes',
        ] as const
    ).some((field) => Boolean(form.errors[field])),
);

const onPhotoChange = (event: Event): void => {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0];
    if (file) {
        form.photo = file;
        const reader = new FileReader();
        reader.onload = (e) => {
            photoPreview.value = e.target?.result as string;
        };
        reader.readAsDataURL(file);
    } else {
        form.photo = null;
        photoPreview.value = null;
    }
};

const submit = (): void => {
    if (!props.fee_preview.available) {
        return;
    }

    form.post(customersStore.url(), {
        forceFormData: true,
        preserveScroll: true,
    });
};
</script>

<template>
    <div>
        <Head title="Register Customer" />

        <div class="mx-auto w-full max-w-3xl space-y-6">
            <PageHeader
                title="Add customer"
                description="We will email them an invite to set up their account."
            />

            <Alert v-if="!fee_preview.available" variant="destructive">
                <ShieldAlert class="size-4" />
                <AlertTitle>You can't add customers right now</AlertTitle>
                <AlertDescription>
                    {{
                        fee_preview.message ??
                        'An admin needs to set up the registration fee first.'
                    }}
                </AlertDescription>
            </Alert>

            <div v-else class="bg-muted/50 space-y-3 rounded-xl p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2 text-sm">
                        <Coins class="text-primary size-4" />
                        <span>Registration fee</span>
                    </div>
                    <p class="text-lg font-semibold">
                        {{ fee_preview.formatted_amount }}
                    </p>
                </div>
                <p class="text-muted-foreground text-sm">
                    {{
                        fee_preview.is_zero
                            ? 'This customer will not pay a fee.'
                            : 'The customer will owe this fee after you add them.'
                    }}
                </p>
                <MoreDetails label="What the customer sees">
                    <p class="text-muted-foreground text-xs">
                        {{ fee_preview.customer_description }}
                    </p>
                    <p class="text-muted-foreground mt-2 text-xs">
                        These fee terms are saved to the customer's account. Fee
                        version {{ fee_preview.version }}.
                    </p>
                </MoreDetails>
            </div>

            <form class="space-y-6" @submit.prevent="submit">
                <Card>
                    <CardHeader>
                        <CardTitle class="text-base"
                            >Customer details</CardTitle
                        >
                    </CardHeader>
                    <CardContent class="space-y-5">
                        <div class="grid gap-5 sm:grid-cols-2">
                            <div class="space-y-1.5">
                                <Label for="customer-name">
                                    Full name
                                    <span class="text-destructive">*</span>
                                </Label>
                                <Input
                                    id="customer-name"
                                    v-model="form.name"
                                    placeholder="e.g. Ngozi Amadi"
                                    required
                                    :class="{
                                        'border-destructive': form.errors.name,
                                    }"
                                />
                                <p
                                    v-if="form.errors.name"
                                    class="text-destructive text-xs"
                                >
                                    {{ form.errors.name }}
                                </p>
                            </div>

                            <div class="space-y-1.5">
                                <Label for="customer-email">
                                    Email
                                    <span class="text-destructive">*</span>
                                </Label>
                                <Input
                                    id="customer-email"
                                    v-model="form.email"
                                    type="email"
                                    placeholder="e.g. ngozi@example.ng"
                                    required
                                    :class="{
                                        'border-destructive': form.errors.email,
                                    }"
                                />
                                <p
                                    v-if="form.errors.email"
                                    class="text-destructive text-xs"
                                >
                                    {{ form.errors.email }}
                                </p>
                            </div>

                            <div class="space-y-1.5">
                                <Label for="customer-phone">
                                    Phone number
                                    <span class="text-destructive">*</span>
                                </Label>
                                <Input
                                    id="customer-phone"
                                    v-model="form.phone"
                                    placeholder="e.g. +2348012345678"
                                    required
                                    :class="{
                                        'border-destructive': form.errors.phone,
                                    }"
                                />
                                <p
                                    v-if="form.errors.phone"
                                    class="text-destructive text-xs"
                                >
                                    {{ form.errors.phone }}
                                </p>
                                <p class="text-muted-foreground text-xs">
                                    Start with +234.
                                </p>
                            </div>

                            <div class="space-y-1.5">
                                <Label for="customer-address">Address</Label>
                                <Input
                                    id="customer-address"
                                    v-model="form.address"
                                    placeholder="e.g. 12 Broad Street, Lagos"
                                    :class="{
                                        'border-destructive':
                                            form.errors.address,
                                    }"
                                />
                                <p
                                    v-if="form.errors.address"
                                    class="text-destructive text-xs"
                                >
                                    {{ form.errors.address }}
                                </p>
                            </div>
                        </div>

                        <MoreDetails
                            :key="hasMoreOptionErrors ? 'more-open' : 'more'"
                            label="More options"
                            :default-open="hasMoreOptionErrors"
                        >
                            <div class="space-y-5">
                                <div class="grid gap-5 sm:grid-cols-2">
                                    <div class="space-y-1.5">
                                        <Label for="customer-gender"
                                            >Gender</Label
                                        >
                                        <Select v-model="form.gender">
                                            <SelectTrigger
                                                id="customer-gender"
                                                class="w-full"
                                            >
                                                <SelectValue
                                                    placeholder="Select gender"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="female"
                                                    >Female</SelectItem
                                                >
                                                <SelectItem value="male"
                                                    >Male</SelectItem
                                                >
                                                <SelectItem value="other"
                                                    >Other</SelectItem
                                                >
                                                <SelectItem
                                                    value="prefer_not_to_say"
                                                    >Prefer not to
                                                    say</SelectItem
                                                >
                                            </SelectContent>
                                        </Select>
                                        <p
                                            v-if="form.errors.gender"
                                            class="text-destructive text-xs"
                                        >
                                            {{ form.errors.gender }}
                                        </p>
                                    </div>

                                    <div class="space-y-1.5">
                                        <Label for="customer-occupation"
                                            >Occupation</Label
                                        >
                                        <Input
                                            id="customer-occupation"
                                            v-model="form.occupation"
                                            placeholder="e.g. Trader"
                                            :class="{
                                                'border-destructive':
                                                    form.errors.occupation,
                                            }"
                                        />
                                        <p
                                            v-if="form.errors.occupation"
                                            class="text-destructive text-xs"
                                        >
                                            {{ form.errors.occupation }}
                                        </p>
                                    </div>

                                    <div class="space-y-1.5">
                                        <Label for="customer-internal-ref"
                                            >Reference</Label
                                        >
                                        <Input
                                            id="customer-internal-ref"
                                            v-model="form.internal_reference"
                                            placeholder="e.g. BR-1092"
                                            :class="{
                                                'border-destructive':
                                                    form.errors
                                                        .internal_reference,
                                            }"
                                        />
                                        <p
                                            v-if="
                                                form.errors.internal_reference
                                            "
                                            class="text-destructive text-xs"
                                        >
                                            {{ form.errors.internal_reference }}
                                        </p>
                                    </div>
                                </div>

                                <div class="space-y-1.5">
                                    <Label for="customer-photo">Photo</Label>
                                    <div class="flex items-center gap-4">
                                        <div
                                            class="border-border bg-muted/40 relative flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-dashed"
                                        >
                                            <img
                                                v-if="photoPreview"
                                                :src="photoPreview"
                                                alt="Photo preview"
                                                class="size-full object-cover"
                                            />
                                            <Camera
                                                v-else
                                                class="text-muted-foreground size-5"
                                            />
                                        </div>
                                        <div class="space-y-1">
                                            <input
                                                id="customer-photo"
                                                ref="photoInput"
                                                type="file"
                                                accept="image/jpeg,image/png,image/webp"
                                                class="hidden"
                                                @change="onPhotoChange"
                                            />
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                @click="photoInput?.click()"
                                            >
                                                Choose photo
                                            </Button>
                                            <p
                                                class="text-muted-foreground text-xs"
                                            >
                                                JPEG, PNG or WebP, up to 2 MB.
                                            </p>
                                            <p
                                                v-if="form.errors.photo"
                                                class="text-destructive text-xs"
                                            >
                                                {{ form.errors.photo }}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div class="space-y-1.5">
                                    <Label for="customer-notes"
                                        >Staff notes</Label
                                    >
                                    <textarea
                                        id="customer-notes"
                                        v-model="form.notes"
                                        rows="3"
                                        placeholder="Only staff and the customer's agent can see these."
                                        class="border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                                        :class="{
                                            'border-destructive':
                                                form.errors.notes,
                                        }"
                                    />
                                    <p
                                        v-if="form.errors.notes"
                                        class="text-destructive text-xs"
                                    >
                                        {{ form.errors.notes }}
                                    </p>
                                </div>
                            </div>
                        </MoreDetails>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base"
                            >Next of kin (optional)</CardTitle
                        >
                        <CardDescription
                            >Someone we can contact in an
                            emergency.</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="grid gap-5 sm:grid-cols-2">
                        <div class="space-y-1.5">
                            <Label for="nok-name">Full name</Label>
                            <Input
                                id="nok-name"
                                v-model="form.next_of_kin.full_name"
                                placeholder="e.g. Emeka Amadi"
                                :class="{
                                    'border-destructive':
                                        form.errors['next_of_kin.full_name'],
                                }"
                            />
                            <p
                                v-if="form.errors['next_of_kin.full_name']"
                                class="text-destructive text-xs"
                            >
                                {{ form.errors['next_of_kin.full_name'] }}
                            </p>
                        </div>

                        <div class="space-y-1.5">
                            <Label for="nok-relationship">Relationship</Label>
                            <Input
                                id="nok-relationship"
                                v-model="form.next_of_kin.relationship"
                                placeholder="e.g. Spouse, Sibling"
                                :class="{
                                    'border-destructive':
                                        form.errors['next_of_kin.relationship'],
                                }"
                            />
                            <p
                                v-if="form.errors['next_of_kin.relationship']"
                                class="text-destructive text-xs"
                            >
                                {{ form.errors['next_of_kin.relationship'] }}
                            </p>
                        </div>

                        <div class="space-y-1.5">
                            <Label for="nok-phone">Phone number</Label>
                            <Input
                                id="nok-phone"
                                v-model="form.next_of_kin.phone"
                                placeholder="e.g. +2348098765432"
                                :class="{
                                    'border-destructive':
                                        form.errors['next_of_kin.phone'],
                                }"
                            />
                            <p
                                v-if="form.errors['next_of_kin.phone']"
                                class="text-destructive text-xs"
                            >
                                {{ form.errors['next_of_kin.phone'] }}
                            </p>
                        </div>

                        <div class="space-y-1.5">
                            <Label for="nok-address">Address</Label>
                            <Input
                                id="nok-address"
                                v-model="form.next_of_kin.address"
                                placeholder="e.g. Same as customer"
                                :class="{
                                    'border-destructive':
                                        form.errors['next_of_kin.address'],
                                }"
                            />
                            <p
                                v-if="form.errors['next_of_kin.address']"
                                class="text-destructive text-xs"
                            >
                                {{ form.errors['next_of_kin.address'] }}
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <div
                    class="bg-background/95 sticky bottom-0 z-10 -mx-1 flex flex-wrap items-center justify-end gap-3 border-t px-1 py-4 backdrop-blur"
                >
                    <Button as-child type="button" variant="outline">
                        <Link :href="customersIndex().url">Cancel</Link>
                    </Button>
                    <Button
                        type="submit"
                        :disabled="form.processing || !fee_preview.available"
                    >
                        <Loader2
                            v-if="form.processing"
                            class="size-4 animate-spin"
                        />
                        <UserCheck v-else class="size-4" />
                        Add customer
                    </Button>
                </div>
            </form>
        </div>
    </div>
</template>
