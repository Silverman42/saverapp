<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, LifeBuoy, ShieldAlert, UserCheck } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { login } from '@/routes';
</script>

<template>
    <Head title="Assisted Account Recovery" />

    <div class="space-y-6">
        <div
            class="flex items-start gap-3 rounded-lg border border-amber-500/20 bg-amber-500/10 p-4 text-sm text-amber-950 dark:text-amber-200"
        >
            <ShieldAlert
                class="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-400"
            />
            <div class="space-y-1">
                <p class="font-medium">High-Security Verification Required</p>
                <p class="text-muted-foreground text-xs leading-relaxed">
                    To protect accounts from unauthorized takeover,
                    administrative and agent accounts cannot bypass multi-factor
                    authentication without verified identity confirmation.
                </p>
            </div>
        </div>

        <div class="space-y-4">
            <Card>
                <CardHeader class="pb-3">
                    <CardTitle
                        class="flex items-center gap-2 text-base font-semibold"
                    >
                        <UserCheck class="text-primary size-4" />
                        Agents
                    </CardTitle>
                    <CardDescription class="text-xs">
                        Contact an authorized Administrator to initiate assisted
                        recovery.
                    </CardDescription>
                </CardHeader>
                <CardContent
                    class="text-muted-foreground space-y-2 text-xs leading-relaxed"
                >
                    <p>
                        1. An Administrator will verify your identity against
                        company records.
                    </p>
                    <p>
                        2. Upon approval, your previous authenticator and
                        recovery codes will be revoked.
                    </p>
                    <p>
                        3. You will receive a single-use activation link to
                        configure a new authenticator.
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="pb-3">
                    <CardTitle
                        class="flex items-center gap-2 text-base font-semibold"
                    >
                        <LifeBuoy class="text-primary size-4" />
                        Administrators
                    </CardTitle>
                    <CardDescription class="text-xs">
                        Contact another Administrator with Admin-management
                        permissions.
                    </CardDescription>
                </CardHeader>
                <CardContent
                    class="text-muted-foreground space-y-2 text-xs leading-relaxed"
                >
                    <p>
                        1. Two distinct Administrators are required to approve
                        administrative recovery where eligible.
                    </p>
                    <p>
                        2. Once approved, all existing sessions and credentials
                        are reset.
                    </p>
                    <p>
                        3. If you are the final active Administrator, use the
                        offline single-use business emergency recovery key.
                    </p>
                </CardContent>
            </Card>
        </div>

        <div class="flex justify-center pt-2">
            <Button variant="outline" as-child class="w-full">
                <Link :href="login()">
                    <ArrowLeft class="mr-2 size-4" />
                    Return to Sign In
                </Link>
            </Button>
        </div>
    </div>
</template>
