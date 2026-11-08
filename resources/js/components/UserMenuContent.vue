<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Eye, EyeOff, LogOut, Settings } from '@lucide/vue';
import { computed } from 'vue';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { useAmountVisibility } from '@/composables/useAmountVisibility';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

type Props = {
    user: User;
};

const handleLogout = () => {
    router.flushAll();
};

const props = defineProps<Props>();

const isAgent = computed(() => props.user.user_type === 'agent');
const { amountsHidden, toggleAmounts } = useAmountVisibility();

const handleToggleAmounts = (event: Event) => {
    event.preventDefault();
    toggleAmounts();
};
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem
            v-if="isAgent"
            class="cursor-pointer"
            data-test="toggle-amounts"
            @select="handleToggleAmounts"
        >
            <Eye v-if="amountsHidden" class="mr-2 h-4 w-4" />
            <EyeOff v-else class="mr-2 h-4 w-4" />
            {{ amountsHidden ? 'Show amounts' : 'Hide amounts' }}
        </DropdownMenuItem>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full cursor-pointer" :href="edit()" prefetch>
                <Settings class="mr-2 h-4 w-4" />
                Settings
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link
            class="block w-full cursor-pointer"
            :href="logout()"
            @click="handleLogout"
            as="button"
            data-test="logout-button"
        >
            <LogOut class="mr-2 h-4 w-4" />
            Log out
        </Link>
    </DropdownMenuItem>
</template>
