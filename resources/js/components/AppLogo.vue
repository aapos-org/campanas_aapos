<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';

/**
 * Sidebar brand: the white AAPOS seal (made for dark backgrounds; the sidebar is dark
 * glass in both themes) with the platform name and the association's name, styled like
 * the institutional title (bold name + spaced capitals).
 */
const name = usePage().props.name;
</script>

<template>
    <img
        src="/images/logo-aapos-blanco.webp"
        alt="Asociación Amigos Pro Obras Sociales"
        width="40"
        height="40"
        class="size-10 shrink-0 rounded-full drop-shadow-[0_0_10px_oklch(0.85_0.12_85/0.35)] transition-[width,height] group-data-[collapsible=icon]:size-8"
    />
    <div class="ml-1 grid flex-1 text-left leading-tight">
        <span class="truncate text-[15px] font-semibold tracking-tight">{{
            name
        }}</span>
        <span
            class="truncate text-[9px] font-medium tracking-[0.22em] text-sidebar-foreground/65 uppercase"
            >Pro Obras Sociales</span
        >
    </div>
</template>
