<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Bell } from '@lucide/vue';
import { computed } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { getInitials } from '@/composables/useInitials';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <header
        class="border-sidebar-border bg-card/95 sticky top-0 z-20 flex h-20 shrink-0 items-center gap-3 border-b px-4 backdrop-blur transition-[width,height] ease-linear md:px-6"
    >
        <div class="flex min-w-0 items-center gap-2">
            <SidebarTrigger class="-ml-1 md:hidden" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumbs
                    :breadcrumbs="breadcrumbs"
                    class="hidden sm:block"
                />
            </template>
        </div>

        <div class="ml-auto flex items-center gap-2">
            <Button variant="ghost" size="icon" aria-label="Notifications">
                <Bell class="size-4.5" />
            </Button>

            <DropdownMenu v-if="user">
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="outline"
                        class="h-11 gap-2 rounded-full p-1.5 pr-2.5"
                        data-test="header-user-menu-button"
                    >
                        <Avatar class="size-8">
                            <AvatarImage
                                v-if="user.avatar"
                                :src="user.avatar"
                                :alt="user.name"
                            />
                            <AvatarFallback
                                class="bg-accent text-accent-foreground text-xs font-bold"
                            >
                                {{ getInitials(user.name) }}
                            </AvatarFallback>
                        </Avatar>
                        <span
                            class="hidden max-w-28 truncate text-sm sm:inline"
                        >
                            {{ user.name }}
                        </span>
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-64" :side-offset="8">
                    <UserMenuContent :user="user" />
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </header>
</template>
