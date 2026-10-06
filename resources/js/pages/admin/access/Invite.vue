<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import InviteAdminForm from '@/components/InviteAdminForm.vue';
import type { GrantablePermission } from '@/components/InviteAdminForm.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { dashboard } from '@/routes';
import { index as adminAccessIndex } from '@/routes/admin/access';
import { create as invitationsCreate } from '@/routes/admin/access/invitations';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Admin team', href: adminAccessIndex() },
            { title: 'Invite admin', href: invitationsCreate() },
        ],
    },
});

defineProps<{
    attempt_reference: string;
    permissions: GrantablePermission[];
}>();
</script>

<template>
    <Head title="Invite admin" />

    <div class="space-y-6">
        <PageHeader
            title="Invite admin"
            description="They set their own password and sign-in code."
        >
            <template #actions>
                <Button variant="outline" as-child>
                    <Link :href="adminAccessIndex()">
                        <ArrowLeft class="size-4" />
                        Back
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <Card class="max-w-3xl">
            <CardContent>
                <InviteAdminForm
                    :attempt-reference="attempt_reference"
                    :permissions="permissions"
                />
            </CardContent>
        </Card>
    </div>
</template>
