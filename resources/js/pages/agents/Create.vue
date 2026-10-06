<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Camera, Loader2, Mail } from '@lucide/vue';
import MoreDetails from '@/components/MoreDetails.vue';
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
import { dashboard } from '@/routes';
import {
    create as agentsCreate,
    index as agentsIndex,
    store as agentsStore,
} from '@/routes/agents';

defineOptions({
    inheritAttrs: false,
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Agents', href: agentsIndex() },
            { title: 'Add agent', href: agentsCreate() },
        ],
    },
});

const props = defineProps<{
    attempt_reference: string;
}>();

const photoPreview = ref<string | null>(null);

const form = useForm({
    attempt_reference: props.attempt_reference,
    name: '',
    email: '',
    phone: '',
    address: '',
    employment_date: '',
    notes: '',
    photo: null as File | null,
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

const hasOptionalErrors = computed(() =>
    Boolean(
        form.errors.employment_date ||
        form.errors.address ||
        form.errors.photo ||
        form.errors.notes,
    ),
);

const submit = (): void => {
    form.post(agentsStore.url(), {
        forceFormData: true,
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Add agent" />

    <div class="space-y-6">
        <PageHeader
            title="Add agent"
            description="We'll email them an invite to set up their account."
        />

        <form class="max-w-3xl" @submit.prevent="submit">
            <Card>
                <CardHeader>
                    <CardTitle>Agent details</CardTitle>
                </CardHeader>
                <CardContent class="space-y-5">
                    <div class="space-y-1.5">
                        <Label for="agent-name">Full name</Label>
                        <Input
                            id="agent-name"
                            v-model="form.name"
                            placeholder="e.g. Chukwuma Adebayo"
                            required
                            :aria-invalid="!!form.errors.name"
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

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div class="space-y-1.5">
                            <Label for="agent-email">Email</Label>
                            <Input
                                id="agent-email"
                                v-model="form.email"
                                type="email"
                                placeholder="e.g. agent@example.ng"
                                required
                                :aria-invalid="!!form.errors.email"
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
                            <Label for="agent-phone">Phone number</Label>
                            <Input
                                id="agent-phone"
                                v-model="form.phone"
                                placeholder="e.g. +2348012345678"
                                required
                                :aria-invalid="!!form.errors.phone"
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
                            <p v-else class="text-muted-foreground text-xs">
                                Include the country code, e.g. +234.
                            </p>
                        </div>
                    </div>

                    <MoreDetails
                        :key="hasOptionalErrors ? 'open' : 'closed'"
                        label="More options"
                        :default-open="hasOptionalErrors"
                    >
                        <div class="space-y-5">
                            <div class="grid gap-5 sm:grid-cols-2">
                                <div class="space-y-1.5">
                                    <Label for="agent-employment-date"
                                        >Start date</Label
                                    >
                                    <DatePicker
                                        id="agent-employment-date"
                                        :model-value="form.employment_date"
                                        @update:model-value="
                                            form.employment_date = $event
                                        "
                                    />
                                    <p
                                        v-if="form.errors.employment_date"
                                        class="text-destructive text-xs"
                                    >
                                        {{ form.errors.employment_date }}
                                    </p>
                                </div>

                                <div class="space-y-1.5">
                                    <Label for="agent-address">Address</Label>
                                    <Input
                                        id="agent-address"
                                        v-model="form.address"
                                        placeholder="e.g. 14 Commercial Avenue, Yaba, Lagos"
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

                            <div class="space-y-1.5">
                                <Label for="agent-photo">Photo</Label>
                                <div class="flex items-center gap-4">
                                    <div
                                        class="border-muted bg-muted/40 relative flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-xl border"
                                    >
                                        <img
                                            v-if="photoPreview"
                                            :src="photoPreview"
                                            alt="Photo preview"
                                            class="size-full object-cover"
                                        />
                                        <Camera
                                            v-else
                                            class="text-muted-foreground size-6"
                                        />
                                    </div>
                                    <Input
                                        id="agent-photo"
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp"
                                        class="h-10 text-sm"
                                        @change="onPhotoChange"
                                    />
                                </div>
                                <p
                                    v-if="form.errors.photo"
                                    class="text-destructive text-xs"
                                >
                                    {{ form.errors.photo }}
                                </p>
                                <p class="text-muted-foreground text-xs">
                                    JPEG, PNG or WebP, up to 2 MB.
                                </p>
                            </div>

                            <div class="space-y-1.5">
                                <Label for="agent-notes">Notes</Label>
                                <textarea
                                    id="agent-notes"
                                    v-model="form.notes"
                                    placeholder="Anything other admins should know"
                                    rows="3"
                                    class="border-input placeholder:text-muted-foreground bg-card focus-visible:border-ring focus-visible:ring-ring/20 disabled:bg-muted w-full rounded-xl border px-3.5 py-2.5 text-base transition-[border-color,background-color] outline-none focus-visible:ring-[3px] disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-60 md:text-sm"
                                    :class="{
                                        'border-destructive': form.errors.notes,
                                    }"
                                ></textarea>
                                <p
                                    v-if="form.errors.notes"
                                    class="text-destructive text-xs"
                                >
                                    {{ form.errors.notes }}
                                </p>
                                <p v-else class="text-muted-foreground text-xs">
                                    Only admins can see these.
                                </p>
                            </div>
                        </div>
                    </MoreDetails>
                </CardContent>
                <CardFooter class="flex justify-end gap-2 border-t pt-6">
                    <Button as-child type="button" variant="outline">
                        <Link :href="agentsIndex().url">Cancel</Link>
                    </Button>
                    <Button type="submit" :disabled="form.processing">
                        <Loader2
                            v-if="form.processing"
                            class="size-4 animate-spin"
                        />
                        <Mail v-else class="size-4" />
                        Add and send invite
                    </Button>
                </CardFooter>
            </Card>
        </form>
    </div>
</template>
