<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import type { Paginated } from '@/types';

defineProps<{
    page: Paginated<unknown>;
}>();
</script>

<template>
    <nav
        v-if="page.last_page > 1 || page.total > 0"
        class="flex flex-col items-center justify-between gap-3 text-sm sm:flex-row"
        aria-label="Paginación"
    >
        <p class="text-muted-foreground">
            <template v-if="page.total > 0">
                Mostrando {{ page.from }}–{{ page.to }} de {{ page.total }}
            </template>
        </p>
        <div v-if="page.last_page > 1" class="flex items-center gap-2">
            <Button
                variant="outline"
                size="sm"
                :disabled="!page.prev_page_url"
                :as="page.prev_page_url ? Link : 'button'"
                :href="page.prev_page_url ?? undefined"
                preserve-scroll
            >
                <ChevronLeft class="size-4" aria-hidden="true" />
                Anterior
            </Button>
            <span class="tabular-nums">
                {{ page.current_page }} / {{ page.last_page }}
            </span>
            <Button
                variant="outline"
                size="sm"
                :disabled="!page.next_page_url"
                :as="page.next_page_url ? Link : 'button'"
                :href="page.next_page_url ?? undefined"
                preserve-scroll
            >
                Siguiente
                <ChevronRight class="size-4" aria-hidden="true" />
            </Button>
        </div>
    </nav>
</template>
