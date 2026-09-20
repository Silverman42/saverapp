<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { BadgeCheck, ChartNoAxesCombined, ShieldCheck } from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="bg-background grid min-h-svh lg:grid-cols-[minmax(26rem,0.9fr)_minmax(32rem,1.1fr)]"
    >
        <aside
            class="border-border bg-card relative hidden overflow-hidden border-r p-10 lg:flex lg:flex-col xl:p-14"
        >
            <Link :href="home()" class="flex w-fit items-center gap-2.5">
                <AppLogo />
            </Link>

            <div class="my-auto max-w-lg py-14">
                <BadgeCheck class="text-primary mb-6 size-9" />
                <p
                    class="text-muted-foreground text-xs font-semibold tracking-[0.18em] uppercase"
                >
                    SaverApp operations
                </p>
                <h2
                    class="mt-4 text-4xl leading-[1.12] font-semibold tracking-[-0.035em] xl:text-5xl"
                >
                    Savings operations, kept clear and accountable.
                </h2>
                <p
                    class="text-muted-foreground mt-5 max-w-md text-base leading-7"
                >
                    A calm workspace for protected account access, reliable
                    operational summaries, and traceable savings activity.
                </p>

                <div class="mt-10 grid gap-3 sm:grid-cols-2">
                    <div
                        class="border-border bg-background rounded-2xl border p-4"
                    >
                        <ChartNoAxesCombined class="text-primary size-5" />
                        <p class="mt-4 text-sm font-semibold">
                            Operational clarity
                        </p>
                        <p class="text-muted-foreground mt-1 text-xs leading-5">
                            Role-aware summaries with explicit data states.
                        </p>
                    </div>
                    <div
                        class="border-border bg-background rounded-2xl border p-4"
                    >
                        <ShieldCheck class="text-primary size-5" />
                        <p class="mt-4 text-sm font-semibold">Secure access</p>
                        <p class="text-muted-foreground mt-1 text-xs leading-5">
                            One protected sign-in for every permitted role.
                        </p>
                    </div>
                </div>
            </div>

            <p class="text-muted-foreground text-xs">
                Built for dependable thrift and savings administration.
            </p>
        </aside>

        <main
            class="flex min-h-svh items-center justify-center px-5 py-10 sm:px-8 lg:px-12"
        >
            <div class="w-full max-w-md">
                <Link
                    :href="home()"
                    class="mb-10 flex w-fit items-center gap-2.5 lg:hidden"
                >
                    <div
                        class="bg-primary text-primary-foreground flex size-10 items-center justify-center rounded-xl"
                    >
                        <AppLogoIcon class="size-5" />
                    </div>
                    <span class="font-bold">SaverApp</span>
                </Link>

                <div
                    class="border-border bg-card rounded-3xl border p-6 shadow-[0_20px_70px_-50px_rgba(22,44,58,0.45)] sm:p-8"
                >
                    <div class="mb-8 space-y-2">
                        <p
                            class="text-primary text-xs font-bold tracking-[0.16em] uppercase"
                        >
                            Secure account access
                        </p>
                        <h1 class="text-2xl font-semibold tracking-[-0.025em]">
                            {{ title }}
                        </h1>
                        <p class="text-muted-foreground text-sm leading-6">
                            {{ description }}
                        </p>
                    </div>
                    <slot />
                </div>
            </div>
        </main>
    </div>
</template>
