<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Activity,
    BarChart3,
    BookUser,
    FileText,
    Filter,
    LayoutTemplate,
    Megaphone,
    LayoutGrid,
    ListPlus,
    Plug,
    ScrollText,
    ShieldBan,
    Tag,
    Upload,
    Users,
    Webhook,
    Workflow,
} from '@lucide/vue';
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
import { index as automationsIndex } from '@/routes/automations';
import { index as campaignsIndex } from '@/routes/campaigns';
import { index as templatesIndex } from '@/routes/templates';
import { index as audiencesIndex } from '@/routes/audiences';
import { index as fieldsIndex } from '@/routes/fields';
import { index as formsIndex } from '@/routes/forms';
import { index as importsIndex } from '@/routes/imports';
import { index as suppressionsIndex } from '@/routes/suppressions';
import { index as segmentsIndex } from '@/routes/segments';
import { index as tagsIndex } from '@/routes/tags';
import { index as connectionsIndex } from '@/routes/connections';
import { index as usersIndex } from '@/routes/admin/users';
import { index as horizonIndex } from '@/routes/horizon';
import { index as reportsIndex } from '@/routes/reports';
import { index as webhooksIndex } from '@/routes/webhooks';
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
        label: 'Campañas',
        items: [
            {
                title: 'Campañas',
                href: campaignsIndex(),
                icon: Megaphone,
                permission: 'campaigns.view',
            },
            {
                title: 'Plantillas',
                href: templatesIndex(),
                icon: LayoutTemplate,
                permission: 'campaigns.manage',
            },
            {
                title: 'Automatizaciones',
                href: automationsIndex(),
                icon: Workflow,
                permission: 'campaigns.view',
            },
            {
                title: 'Reportes',
                href: reportsIndex(),
                icon: BarChart3,
                permission: 'reports.view',
            },
        ],
    },
    {
        label: 'Contactos',
        items: [
            {
                title: 'Audiencias',
                href: audiencesIndex(),
                icon: BookUser,
                permission: 'contacts.view',
            },
            {
                title: 'Formularios',
                href: formsIndex(),
                icon: FileText,
                permission: 'contacts.manage',
            },
            {
                title: 'Segmentos',
                href: segmentsIndex(),
                icon: Filter,
                permission: 'contacts.view',
            },
            {
                title: 'Etiquetas',
                href: tagsIndex(),
                icon: Tag,
                permission: 'contacts.manage',
            },
            {
                title: 'Campos personalizados',
                href: fieldsIndex(),
                icon: ListPlus,
                permission: 'contacts.manage',
            },
            {
                title: 'Importaciones',
                href: importsIndex(),
                icon: Upload,
                permission: 'contacts.manage',
            },
            {
                title: 'Lista de supresión',
                href: suppressionsIndex(),
                icon: ShieldBan,
                permission: 'contacts.manage',
            },
        ],
    },
    {
        label: 'Administración',
        items: [
            {
                title: 'Conexiones de envío',
                href: connectionsIndex(),
                icon: Plug,
                permission: 'connections.manage',
            },
            {
                title: 'Webhooks',
                href: webhooksIndex(),
                icon: Webhook,
                permission: 'connections.manage',
            },
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
