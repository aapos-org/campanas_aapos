<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { usePermissions } from '@/composables/usePermissions';
import { toUrl } from '@/lib/utils';
import type { NavItem } from '@/types';

const props = defineProps<{
    label: string;
    items: NavItem[];
}>();

const { isCurrentOrParentUrl } = useCurrentUrl();
const { can } = usePermissions();

const visibleItems = computed(() =>
    props.items.filter((item) => can(item.permission)),
);
</script>

<template>
    <SidebarGroup v-if="visibleItems.length" class="px-2 py-0">
        <SidebarGroupLabel>{{ label }}</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in visibleItems" :key="item.title">
                <SidebarMenuButton
                    as-child
                    :is-active="
                        !item.external && isCurrentOrParentUrl(item.href)
                    "
                    :tooltip="item.title"
                >
                    <a v-if="item.external" :href="toUrl(item.href)">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </a>
                    <Link v-else :href="item.href">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
