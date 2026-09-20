<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { LayoutDashboard, Palette, ShieldCheck, UserRound } from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutDashboard,
    },
];

const accountNavItems: NavItem[] = [
    {
        title: 'Profile',
        href: editProfile(),
        icon: UserRound,
    },
    {
        title: 'Security',
        href: editSecurity(),
        icon: ShieldCheck,
    },
    {
        title: 'Appearance',
        href: editAppearance(),
        icon: Palette,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="sidebar">
        <SidebarHeader
            class="border-sidebar-border h-20 justify-center border-b px-4"
        >
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent class="gap-4 py-5">
            <NavMain label="Overview" :items="mainNavItems" />
            <NavMain label="Account" :items="accountNavItems" />
        </SidebarContent>

        <SidebarFooter class="border-sidebar-border border-t p-4">
            <div
                class="bg-sidebar-accent/55 flex items-center gap-3 rounded-xl px-3 py-3 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:px-0"
            >
                <ShieldCheck
                    class="text-sidebar-accent-foreground size-4 shrink-0"
                />
                <div class="min-w-0 group-data-[collapsible=icon]:hidden">
                    <p
                        class="text-sidebar-accent-foreground truncate text-xs font-semibold"
                    >
                        Protected workspace
                    </p>
                    <p class="text-muted-foreground truncate text-[11px]">
                        Account safeguards enabled
                    </p>
                </div>
            </div>
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
