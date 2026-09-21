<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profile',
        href: editProfile(),
    },
    {
        title: 'Security',
        href: editSecurity(),
    },
    {
        title: 'Appearance',
        href: editAppearance(),
    },
];

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div>
        <Heading
            title="Settings"
            description="Manage your profile and account settings"
        />

        <div class="flex flex-col gap-8 lg:flex-row lg:gap-10">
            <aside class="w-full lg:w-56">
                <nav
                    class="border-border bg-card flex flex-col gap-1 rounded-2xl border p-2 shadow-[0_1px_2px_rgba(22,44,58,0.03)]"
                    aria-label="Settings"
                >
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        variant="ghost"
                        :class="[
                            'w-full justify-start',
                            {
                                'bg-accent text-accent-foreground':
                                    isCurrentOrParentUrl(item.href),
                            },
                        ]"
                        as-child
                    >
                        <Link :href="item.href">
                            <component :is="item.icon" class="h-4 w-4" />
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <Separator class="my-6 lg:hidden" />

            <div class="min-w-0 flex-1 md:max-w-3xl">
                <section
                    class="border-border bg-card max-w-2xl space-y-12 rounded-2xl border p-5 shadow-[0_1px_2px_rgba(22,44,58,0.03)] sm:p-7"
                >
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
