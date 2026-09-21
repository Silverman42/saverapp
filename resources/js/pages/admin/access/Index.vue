<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { dashboard } from '@/routes';
import {
    ChevronRight,
    Shield,
    ShieldAlert,
    ShieldCheck,
    User as UserIcon,
} from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

export type AdminItem = {
    id: number;
    name: string;
    email: string;
    account_state: string;
    permission_version: number;
    is_self: boolean;
    can_manage: boolean;
    summary: string[];
    created_at?: string | null;
};

export type PaginatedAdmins = {
    data: AdminItem[];
    current_page: number;
    last_page: number;
    total: number;
    next_page_url: string | null;
    prev_page_url: string | null;
};

const props = defineProps<{
    admins: PaginatedAdmins;
    canManage: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
            {
                title: 'Admin access',
                href: '/admin/access',
            },
        ],
    },
});

const getBadgeVariant = (state: string) => {
    switch (state) {
        case 'active':
            return 'default';
        case 'suspended':
        case 'deactivated':
            return 'destructive';
        default:
            return 'secondary';
    }
};
</script>

<template>
    <Head title="Administrator Access" />

    <div class="space-y-6">
        <div
            class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
        >
            <div>
                <h1 class="text-3xl font-semibold tracking-tight">
                    Administrator Access
                </h1>
                <p class="text-muted-foreground mt-1.5 text-sm">
                    Directory of registered system administrators, permission
                    versions, and active responsibilities.
                </p>
            </div>
        </div>

        <Card>
            <CardHeader class="pb-3">
                <div class="flex items-center justify-between">
                    <div>
                        <CardTitle>Administrators</CardTitle>
                        <CardDescription>
                            Total of {{ admins.total }} administrator{{
                                admins.total === 1 ? '' : 's'
                            }}
                            registered.
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead
                            class="bg-muted/40 text-muted-foreground border-b text-xs uppercase"
                        >
                            <tr>
                                <th class="px-4 py-3">Administrator</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Version</th>
                                <th class="px-4 py-3">
                                    Assigned Responsibilities
                                </th>
                                <th class="px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="admin in admins.data"
                                :key="admin.id"
                                class="hover:bg-muted/50 transition-colors"
                            >
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="bg-primary/10 text-primary flex h-9 w-9 items-center justify-center rounded-full"
                                        >
                                            <UserIcon class="h-4 w-4" />
                                        </div>
                                        <div>
                                            <div
                                                class="flex items-center gap-2"
                                            >
                                                <span
                                                    class="text-foreground font-medium"
                                                    >{{ admin.name }}</span
                                                >
                                                <Badge
                                                    v-if="admin.is_self"
                                                    variant="outline"
                                                    class="px-1.5 py-0 text-[10px]"
                                                >
                                                    You
                                                </Badge>
                                            </div>
                                            <div
                                                class="text-muted-foreground text-xs"
                                            >
                                                {{ admin.email }}
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <Badge
                                        :variant="
                                            getBadgeVariant(admin.account_state)
                                        "
                                        class="text-xs capitalize"
                                    >
                                        {{
                                            admin.account_state.replace(
                                                '_',
                                                ' ',
                                            )
                                        }}
                                    </Badge>
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="bg-secondary text-secondary-foreground inline-flex items-center rounded-md px-2 py-0.5 font-mono text-xs font-medium"
                                    >
                                        v{{ admin.permission_version }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex max-w-md flex-wrap gap-1">
                                        <template
                                            v-if="admin.summary.length > 0"
                                        >
                                            <span
                                                v-for="(
                                                    item, idx
                                                ) in admin.summary.slice(0, 3)"
                                                :key="idx"
                                                class="bg-muted text-muted-foreground inline-flex items-center rounded-sm px-1.5 py-0.5 text-[11px]"
                                            >
                                                {{ item }}
                                            </span>
                                            <span
                                                v-if="admin.summary.length > 3"
                                                class="bg-muted text-muted-foreground inline-flex items-center rounded-sm px-1.5 py-0.5 text-[11px]"
                                            >
                                                +{{ admin.summary.length - 3 }}
                                                more
                                            </span>
                                        </template>
                                        <span
                                            v-else
                                            class="text-muted-foreground text-xs italic"
                                        >
                                            None
                                        </span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div
                                        class="flex items-center justify-end gap-2"
                                    >
                                        <Link
                                            :href="`/admin/access/${admin.id}`"
                                        >
                                            <Button variant="outline" size="sm">
                                                <span>{{
                                                    admin.can_manage
                                                        ? 'Manage'
                                                        : 'View'
                                                }}</span>
                                                <ChevronRight
                                                    class="ml-1 h-3.5 w-3.5"
                                                />
                                            </Button>
                                        </Link>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div
                    v-if="admins.last_page > 1"
                    class="text-muted-foreground mt-4 flex items-center justify-between border-t pt-4 text-xs"
                >
                    <div>
                        Showing page {{ admins.current_page }} of
                        {{ admins.last_page }}
                    </div>
                    <div class="flex items-center gap-2">
                        <Link
                            v-if="admins.prev_page_url"
                            :href="admins.prev_page_url"
                        >
                            <Button variant="outline" size="sm"
                                >Previous</Button
                            >
                        </Link>
                        <Link
                            v-if="admins.next_page_url"
                            :href="admins.next_page_url"
                        >
                            <Button variant="outline" size="sm">Next</Button>
                        </Link>
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
