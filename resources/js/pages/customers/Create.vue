<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import {
    AlertCircle,
    ArrowLeft,
    Camera,
    Coins,
    HeartHandshake,
    Loader2,
    ShieldAlert,
    UserCheck,
    UserPlus,
} from '@lucide/vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
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
            { title: 'Register Customer', href: customersCreate() },
        ],
    },
});

const photoPreview = ref<string | null>(null);

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

        <div class="space-y-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-[25px] font-medium tracking-tight">
                        Register Customer
                    </h1>
                    <p class="text-muted-foreground mt-1.5 text-sm">
                        Register a new customer into your portfolio. An activation invitation will be dispatched to the customer's email.
                    </p>
                </div>
                <Link :href="customersIndex().url">
                    <Button variant="outline" size="sm">
                        <ArrowLeft class="mr-1.5 size-4" /> Back to Customers
                    </Button>
                </Link>
            </div>

            <!-- Fee Rule Check -->
            <div v-if="!fee_preview.available">
                <Alert variant="destructive">
                    <ShieldAlert class="size-4" />
                    <AlertTitle>Registration Temporarily Unavailable</AlertTitle>
                    <AlertDescription>
                        {{ fee_preview.message ?? 'No active registration fee rule is published. An administrator must publish fee terms before customers can be onboarded.' }}
                    </AlertDescription>
                </Alert>
            </div>

            <!-- Authoritative Fee Terms Preview -->
            <Card v-else class="border-primary/20 bg-primary/5">
                <CardHeader class="pb-3">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <Coins class="size-4 text-primary" />
                            <CardTitle class="text-base">Applicable Registration Fee Terms</CardTitle>
                        </div>
                        <Badge variant="outline">Rule v{{ fee_preview.version }}</Badge>
                    </div>
                    <CardDescription>
                        These terms will be permanently snapshotted to this customer's account at commit.
                    </CardDescription>
                </CardHeader>
                <CardContent class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <p class="text-xs font-medium text-muted-foreground uppercase">Snapshotted Charge</p>
                        <p class="mt-1 text-2xl font-bold tracking-tight text-foreground">
                            {{ fee_preview.formatted_amount }}
                        </p>
                        <p class="text-[11px] text-muted-foreground">
                            {{ fee_preview.is_zero ? 'No payable obligation will be generated.' : 'Creates a pending fee obligation upon registration.' }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-muted-foreground uppercase">Disclosure to Customer</p>
                        <p class="mt-1 text-xs text-foreground">{{ fee_preview.customer_description }}</p>
                    </div>
                </CardContent>
            </Card>

            <form @submit.prevent="submit" class="max-w-3xl space-y-6">
                <!-- Personal & Contact Information -->
                <Card>
                    <CardHeader>
                        <CardTitle>Personal & Contact Information</CardTitle>
                        <CardDescription>
                            Enter the customer's core identity details. Full name, email, and phone number are required.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-1.5">
                                <Label for="customer-name">
                                    Full name <span class="text-destructive">*</span>
                                </Label>
                                <Input
                                    id="customer-name"
                                    v-model="form.name"
                                    placeholder="e.g. Ngozi Amadi"
                                    required
                                    :class="{ 'border-destructive': form.errors.name }"
                                />
                                <p v-if="form.errors.name" class="text-destructive text-xs">
                                    {{ form.errors.name }}
                                </p>
                            </div>

                            <div class="space-y-1.5">
                                <Label for="customer-email">
                                    Email address <span class="text-destructive">*</span>
                                </Label>
                                <Input
                                    id="customer-email"
                                    v-model="form.email"
                                    type="email"
                                    placeholder="e.g. ngozi@example.ng"
                                    required
                                    :class="{ 'border-destructive': form.errors.email }"
                                />
                                <p v-if="form.errors.email" class="text-destructive text-xs">
                                    {{ form.errors.email }}
                                </p>
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-1.5">
                                <Label for="customer-phone">
                                    Phone number <span class="text-destructive">*</span>
                                </Label>
                                <Input
                                    id="customer-phone"
                                    v-model="form.phone"
                                    placeholder="e.g. +2348012345678"
                                    required
                                    :class="{ 'border-destructive': form.errors.phone }"
                                />
                                <p v-if="form.errors.phone" class="text-destructive text-xs">
                                    {{ form.errors.phone }}
                                </p>
                                <p class="text-muted-foreground text-[11px]">
                                    Must be in international E.164 format (e.g. +2348012345678).
                                </p>
                            </div>

                            <div class="space-y-1.5">
                                <Label for="customer-gender">Gender (optional)</Label>
                                <Select v-model="form.gender">
                                    <SelectTrigger id="customer-gender">
                                        <SelectValue placeholder="Select gender" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="female">Female</SelectItem>
                                        <SelectItem value="male">Male</SelectItem>
                                        <SelectItem value="other">Other</SelectItem>
                                        <SelectItem value="prefer_not_to_say">Prefer not to say</SelectItem>
                                    </SelectContent>
                                </Select>
                                <p v-if="form.errors.gender" class="text-destructive text-xs">
                                    {{ form.errors.gender }}
                                </p>
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-1.5">
                                <Label for="customer-occupation">Occupation (optional)</Label>
                                <Input
                                    id="customer-occupation"
                                    v-model="form.occupation"
                                    placeholder="e.g. Trader, Teacher, Engineer"
                                    :class="{ 'border-destructive': form.errors.occupation }"
                                />
                                <p v-if="form.errors.occupation" class="text-destructive text-xs">
                                    {{ form.errors.occupation }}
                                </p>
                            </div>

                            <div class="space-y-1.5">
                                <Label for="customer-internal-ref">Internal Reference (optional)</Label>
                                <Input
                                    id="customer-internal-ref"
                                    v-model="form.internal_reference"
                                    placeholder="e.g. BR-1092"
                                    :class="{ 'border-destructive': form.errors.internal_reference }"
                                />
                                <p v-if="form.errors.internal_reference" class="text-destructive text-xs">
                                    {{ form.errors.internal_reference }}
                                </p>
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <Label for="customer-address">Residential / Business address (optional)</Label>
                            <Input
                                id="customer-address"
                                v-model="form.address"
                                placeholder="e.g. 12 Broad Street, Lagos Island, Lagos"
                                :class="{ 'border-destructive': form.errors.address }"
                            />
                            <p v-if="form.errors.address" class="text-destructive text-xs">
                                {{ form.errors.address }}
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <!-- Next of Kin Information -->
                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-2">
                            <HeartHandshake class="size-4 text-muted-foreground" />
                            <CardTitle>Next of Kin (Optional)</CardTitle>
                        </div>
                        <CardDescription>
                            Emergency contact and next of kin information.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-1.5">
                                <Label for="nok-name">Full name</Label>
                                <Input
                                    id="nok-name"
                                    v-model="form.next_of_kin.full_name"
                                    placeholder="e.g. Emeka Amadi"
                                    :class="{ 'border-destructive': form.errors['next_of_kin.full_name'] }"
                                />
                                <p v-if="form.errors['next_of_kin.full_name']" class="text-destructive text-xs">
                                    {{ form.errors['next_of_kin.full_name'] }}
                                </p>
                            </div>

                            <div class="space-y-1.5">
                                <Label for="nok-relationship">Relationship</Label>
                                <Input
                                    id="nok-relationship"
                                    v-model="form.next_of_kin.relationship"
                                    placeholder="e.g. Spouse, Sibling, Child"
                                    :class="{ 'border-destructive': form.errors['next_of_kin.relationship'] }"
                                />
                                <p v-if="form.errors['next_of_kin.relationship']" class="text-destructive text-xs">
                                    {{ form.errors['next_of_kin.relationship'] }}
                                </p>
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-1.5">
                                <Label for="nok-phone">Phone number</Label>
                                <Input
                                    id="nok-phone"
                                    v-model="form.next_of_kin.phone"
                                    placeholder="e.g. +2348098765432"
                                    :class="{ 'border-destructive': form.errors['next_of_kin.phone'] }"
                                />
                                <p v-if="form.errors['next_of_kin.phone']" class="text-destructive text-xs">
                                    {{ form.errors['next_of_kin.phone'] }}
                                </p>
                            </div>

                            <div class="space-y-1.5">
                                <Label for="nok-address">Physical address</Label>
                                <Input
                                    id="nok-address"
                                    v-model="form.next_of_kin.address"
                                    placeholder="e.g. Same as customer"
                                    :class="{ 'border-destructive': form.errors['next_of_kin.address'] }"
                                />
                                <p v-if="form.errors['next_of_kin.address']" class="text-destructive text-xs">
                                    {{ form.errors['next_of_kin.address'] }}
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- Profile Photo & Operational Notes -->
                <Card>
                    <CardHeader>
                        <CardTitle>Profile Photo & Internal Notes</CardTitle>
                        <CardDescription>
                            Upload an identity photo and record initial onboarding notes.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="space-y-1.5">
                            <Label>Customer Photo (optional)</Label>
                            <div class="flex items-center gap-4">
                                <div
                                    class="relative flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-dashed border-border bg-muted/40"
                                >
                                    <img
                                        v-if="photoPreview"
                                        :src="photoPreview"
                                        alt="Photo preview"
                                        class="size-full object-cover"
                                    />
                                    <Camera v-else class="size-6 text-muted-foreground" />
                                </div>
                                <div class="space-y-1">
                                    <input
                                        id="customer-photo"
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp"
                                        class="hidden"
                                        @change="onPhotoChange"
                                    />
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        @click="() => $el.querySelector('#customer-photo')?.click()"
                                    >
                                        Choose Image
                                    </Button>
                                    <p class="text-muted-foreground text-[11px]">
                                        JPEG, PNG, or WebP. Max 2MB.
                                    </p>
                                    <p v-if="form.errors.photo" class="text-destructive text-xs">
                                        {{ form.errors.photo }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <Label for="customer-notes">Internal Operational Notes (optional)</Label>
                            <textarea
                                id="customer-notes"
                                v-model="form.notes"
                                rows="3"
                                placeholder="Internal portfolio notes. Only visible to staff and assigned agents."
                                class="border-input placeholder:text-muted-foreground focus-visible:border-ring flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                                :class="{ 'border-destructive': form.errors.notes }"
                            />
                            <p v-if="form.errors.notes" class="text-destructive text-xs">
                                {{ form.errors.notes }}
                            </p>
                        </div>
                    </CardContent>
                    <CardFooter class="flex justify-between border-t pt-4">
                        <Link :href="customersIndex().url">
                            <Button type="button" variant="outline">Cancel</Button>
                        </Link>
                        <Button
                            type="submit"
                            :disabled="form.processing || !fee_preview.available"
                        >
                            <Loader2 v-if="form.processing" class="mr-2 size-4 animate-spin" />
                            <UserCheck v-else class="mr-2 size-4" />
                            Register Customer & Send Invitation
                        </Button>
                    </CardFooter>
                </Card>
            </form>
        </div>
    </div>
</template>
