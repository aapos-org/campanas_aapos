<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Activity, LayoutGrid, ScrollText, Users } from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
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
import { index as auditIndex } from '@/routes/admin/audit';
import { index as usersIndex } from '@/routes/admin/users';
import { index as horizonIndex } from '@/routes/horizon';
import type { NavGroup } from '@/types';

// Each module adds its entries here as it is built.
const navGroups: NavGroup[] = [
    {
        label: 'General',
        items: [
            {
                title: 'Panel',
                href: dashboard(),
                icon: LayoutGrid,
            },
        ],
    },
    {
        label: 'Administración',
        items: [
            {
                title: 'Usuarios',
                href: usersIndex(),
                icon: Users,
                permission: 'users.manage',
            },
            {
                title: 'Auditoría',
                href: auditIndex(),
                icon: ScrollText,
                permission: 'audit.view',
            },
            {
                title: 'Colas (Horizon)',
                href: horizonIndex(),
                icon: Activity,
                permission: 'system.monitor',
                external: true,
            },
        ],
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
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

        <SidebarContent>
            <NavMain
                v-for="group in navGroups"
                :key="group.label"
                :label="group.label"
                :items="group.items"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
