<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    Briefcase,
    LayoutDashboard,
    Palette,
    ShieldAlert,
    ShieldCheck,
    UserRound,
    Users,
} from '@lucide/vue';
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
    SidebarTrigger,
    useSidebar,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const page = usePage();
const { isMobile, setOpenMobile } = useSidebar();
const isAdmin = computed(() => page.props.auth?.user?.user_type === 'admin');
const isAgent = computed(() => page.props.auth?.user?.user_type === 'agent');
const canViewCustomers = computed(() => isAdmin.value || isAgent.value);
const hasSecurityOperationsManage = computed(() => {
    const permissions =
        (page.props.auth?.user as { permissions?: string[] } | undefined)
            ?.permissions ?? [];
    return permissions.includes('security.operations.manage');
});

const mainNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutDashboard,
        },
    ];

    if (canViewCustomers.value) {
        items.push({
            title: 'Customers',
            href: '/customers',
            icon: Users,
        });
    }

    return items;
});

const adminNavItems = computed<NavItem[]>(() => {
    if (!isAdmin.value) {
        return [];
    }

    const items: NavItem[] = [
        {
            title: 'Agents',
            href: '/agents',
            icon: Briefcase,
        },
        {
            title: 'Admin Access',
            href: '/admin/access',
            icon: Users,
        },
    ];

    if (hasSecurityOperationsManage.value) {
        items.push({
            title: 'Lockouts',
            href: '/admin/lockouts',
            icon: ShieldAlert,
        });
    }

    return items;
});

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

function closeMobileSidebar(): void {
    if (isMobile.value) {
        setOpenMobile(false);
    }
}
</script>

<template>
    <Sidebar collapsible="icon" variant="sidebar">
        <SidebarHeader
            class="border-sidebar-border h-20 justify-center border-b px-4 group-data-[collapsible=icon]:px-2"
        >
            <div
                class="flex w-full items-center justify-between group-data-[collapsible=icon]:justify-center"
            >
                <SidebarMenu
                    class="min-w-0 flex-1 group-data-[collapsible=icon]:hidden"
                >
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" as-child>
                            <Link
                                :href="dashboard()"
                                @click="closeMobileSidebar"
                            >
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                <SidebarTrigger class="hidden shrink-0 md:flex" />
            </div>
        </SidebarHeader>

        <SidebarContent
            class="gap-4 py-5 group-data-[collapsible=icon]:gap-0 group-data-[collapsible=icon]:py-2"
        >
            <NavMain label="Overview" :items="mainNavItems" />
            <NavMain
                v-if="adminNavItems.length > 0"
                label="Administration"
                :items="adminNavItems"
            />
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
