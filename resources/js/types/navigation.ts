import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
    /** Permission required to see the item. */
    permission?: string;
    /** Opens outside the SPA (e.g. Horizon). */
    external?: boolean;
};

export type NavGroup = {
    label: string;
    items: NavItem[];
};
