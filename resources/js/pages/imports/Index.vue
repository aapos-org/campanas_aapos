<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Upload } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { useFormat } from '@/composables/useFormat';
import { index, show } from '@/routes/imports';
import type { ImportSummary, Paginated } from '@/types';

defineProps<{ imports: Paginated<ImportSummary> }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Importaciones', href: index() }] },
});

const { dateTime, number } = useFormat();
</script>

<template>
    <Head title="Importaciones" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Importaciones"
            description="Historial de archivos importados. Para importar, entra a una audiencia y pulsa «Importar»."
        />

        <EmptyState
            v-if="imports.data.length === 0"
            :icon="Upload"
            title="Aún no hay importaciones"
        />

        <ul v-else class="grid gap-2">
            <li v-for="item in imports.data" :key="item.id">
                <Link
                    :href="show(item.id)"
                    class="flex flex-col gap-1 glass-card p-3 hover:bg-muted/40 sm:flex-row sm:items-center sm:gap-4"
                >
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium">{{ item.file }}</p>
                        <p class="text-sm text-muted-foreground">
                            {{ item.audience?.name }} · {{ item.user }} ·
                            {{ dateTime(item.created_at) }}
                        </p>
                    </div>
                    <p class="text-sm text-muted-foreground tabular-nums">
                        {{ number(item.created_count) }} nuevos ·
                        {{ number(item.failed_count) }} errores
                    </p>
                    <Badge variant="outline">{{ item.status_label }}</Badge>
                </Link>
            </li>
        </ul>

        <Pagination :page="imports" />
    </div>
</template>
