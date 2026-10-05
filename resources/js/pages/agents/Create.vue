<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { ArrowLeft, Briefcase, Camera, Loader2 } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
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
            { title: 'Register Agent', href: agentsCreate() },
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

const submit = (): void => {
    form.post(agentsStore.url(), {
        forceFormData: true,
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Register Agent" />

    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-[25px] font-medium tracking-tight">
                    Register Agent
                </h1>
                <p class="text-muted-foreground mt-1.5 text-sm">
                    Create an Inactive Agent profile and send an activation
                    invitation. The agent sets their own password and sets up
                    MFA.
                </p>
            </div>
            <Link :href="agentsIndex().url">
                <Button variant="outline" size="sm">
                    <ArrowLeft class="mr-1.5 size-4" /> Back to Agents
                </Button>
            </Link>
        </div>

        <form @submit.prevent="submit" class="max-w-3xl space-y-6">
            <Card>
                <CardHeader>
                    <CardTitle>Personal & Contact Information</CardTitle>
                    <CardDescription
                        >Enter the agent's identity details. Full name, email,
                        and phone are required.</CardDescription
                    >
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-1.5">
                            <Label for="agent-name"
                                >Full name
                                <span class="text-destructive">*</span></Label
                            >
                            <Input
                                id="agent-name"
                                v-model="form.name"
                                placeholder="e.g. Chukwuma Adebayo"
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
                            <Label for="agent-email"
                                >Email address
                                <span class="text-destructive">*</span></Label
                            >
                            <Input
                                id="agent-email"
                                v-model="form.email"
                                type="email"
                                placeholder="e.g. agent@example.ng"
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
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-1.5">
                            <Label for="agent-phone"
                                >Phone number
                                <span class="text-destructive">*</span></Label
                            >
                            <Input
                                id="agent-phone"
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
                            <p class="text-muted-foreground text-[11px]">
                                Must be in international E.164 format (e.g.
                                +2348012345678).
                            </p>
                        </div>

                        <div class="space-y-1.5">
                            <Label for="agent-employment-date"
                                >Employment date (optional)</Label
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
                    </div>

                    <div class="space-y-1.5">
                        <Label for="agent-address"
                            >Physical address (optional)</Label
                        >
                        <Input
                            id="agent-address"
                            v-model="form.address"
                            placeholder="e.g. 14 Commercial Avenue, Yaba, Lagos"
                            :class="{
                                'border-destructive': form.errors.address,
                            }"
                        />
                        <p
                            v-if="form.errors.address"
                            class="text-destructive text-xs"
                        >
                            {{ form.errors.address }}
                        </p>
                    </div>

                    <div class="space-y-1.5">
                        <Label for="agent-photo"
                            >Profile photo (optional)</Label
                        >
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
                                @change="onPhotoChange"
                                class="h-10 text-sm"
                            />
                        </div>
                        <p
                            v-if="form.errors.photo"
                            class="text-destructive text-xs"
                        >
                            {{ form.errors.photo }}
                        </p>
                        <p class="text-muted-foreground text-[11px]">
                            JPEG, PNG, or WebP. Max 2MB, between 128x128 and
                            2048x2048 pixels.
                        </p>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Administrative Notes</CardTitle>
                    <CardDescription
                        >Internal notes for Administrators only. Agents cannot
                        see these notes.</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <div class="space-y-1.5">
                        <Label for="agent-notes">Notes (optional)</Label>
                        <textarea
                            id="agent-notes"
                            v-model="form.notes"
                            placeholder="Add any internal onboarding or engagement notes..."
                            rows="4"
                            class="border-input placeholder:text-muted-foreground bg-card focus-visible:border-ring focus-visible:ring-ring/20 disabled:bg-muted w-full rounded-xl border px-3.5 py-2.5 text-base transition-[border-color,background-color] outline-none focus-visible:ring-[3px] disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-60 md:text-sm"
                            :class="{ 'border-destructive': form.errors.notes }"
                        ></textarea>
                        <p
                            v-if="form.errors.notes"
                            class="text-destructive text-xs"
                        >
                            {{ form.errors.notes }}
                        </p>
                    </div>
                </CardContent>
                <CardFooter
                    class="flex items-center justify-between border-t pt-6"
                >
                    <Link :href="agentsIndex().url">
                        <Button type="button" variant="outline">Cancel</Button>
                    </Link>
                    <Button type="submit" :disabled="form.processing">
                        <Loader2
                            v-if="form.processing"
                            class="mr-1.5 size-4 animate-spin"
                        />
                        <Briefcase v-else class="mr-1.5 size-4" />
                        Register & Send Invitation
                    </Button>
                </CardFooter>
            </Card>
        </form>
    </div>
</template>
