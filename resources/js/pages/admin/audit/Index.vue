<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ScrollText } from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { reactive } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFormat } from '@/composables/useFormat';
import { index } from '@/routes/admin/audit';
import type { Paginated } from '@/types';

type LogRow = {
    id: number;
    event: string | null;
    description: string;
    causer: { id: number; name: string } | null;
    subject_type: string | null;
    subject_id: number | null;
    properties: Record<string, unknown> | null;
    changes: Record<string, unknown> | null;
    ip_address: string | null;
    created_at: string | null;
};

const props = defineProps<{
    logs: Paginated<LogRow>;
    filters: { event: string; user: number | null; from: string; to: string };
    users: { id: number; name: string }[];
    events: string[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Auditoría', href: index() }],
    },
});

const { dateTime } = useFormat();

const form = reactive({
    event: props.filters.event,
    user: props.filters.user ? String(props.filters.user) : '',
    from: props.filters.from,
    to: props.filters.to,
});

watchDebounced(
    form,
    () => {
        router.get(
            index.url(),
            {
                event: form.event || undefined,
                user: form.user || undefined,
                from: form.from || undefined,
                to: form.to || undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    },
    { debounce: 300 },
);

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none dark:bg-input/30';

const details = (log: LogRow): string | null => {
    const data = { ...log.properties, ...log.changes };

    return Object.keys(data).length ? JSON.stringify(data, null, 2) : null;
};
</script>

<template>
    <Head title="Auditoría" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Registro de auditoría"
            description="Acciones importantes realizadas en el sistema: accesos, cambios de usuarios, envíos e importaciones."
        />

        <form class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent>
            <div class="grid gap-1.5">
                <Label for="event">Evento</Label>
                <select id="event" v-model="form.event" :class="selectClass">
                    <option value="">Todos</option>
                    <option v-for="event in events" :key="event" :value="event">
                        {{ event }}
                    </option>
                </select>
            </div>
            <div class="grid gap-1.5">
                <Label for="user">Usuario</Label>
                <select id="user" v-model="form.user" :class="selectClass">
                    <option value="">Todos</option>
                    <option
                        v-for="u in users"
                        :key="u.id"
                        :value="String(u.id)"
                    >
                        {{ u.name }}
                    </option>
                </select>
            </div>
            <div class="grid gap-1.5">
                <Label for="from">Desde</Label>
                <Input id="from" v-model="form.from" type="date" />
            </div>
            <div class="grid gap-1.5">
                <Label for="to">Hasta</Label>
                <Input id="to" v-model="form.to" type="date" />
            </div>
        </form>

        <EmptyState
            v-if="logs.data.length === 0"
            :icon="ScrollText"
            title="No hay registros"
            description="Cambia los filtros para ver otros eventos."
        />

        <ol v-else class="grid gap-3">
            <li
                v-for="log in logs.data"
                :key="log.id"
                class="rounded-xl border p-4"
            >
                <div
                    class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div class="min-w-0 space-y-1">
                        <p class="font-medium">{{ log.description }}</p>
                        <p class="text-sm text-muted-foreground">
                            {{ log.causer?.name ?? 'Sistema / anónimo' }}
                            <template v-if="log.subject_type">
                                · {{ log.subject_type }} #{{ log.subject_id }}
                            </template>
                            <template v-if="log.ip_address">
                                · IP {{ log.ip_address }}
                            </template>
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <Badge v-if="log.event" variant="outline">{{
                            log.event
                        }}</Badge>
                        <time
                            :datetime="log.created_at ?? undefined"
                            class="text-sm whitespace-nowrap text-muted-foreground"
                        >
                            {{ dateTime(log.created_at) }}
                        </time>
                    </div>
                </div>
                <details v-if="details(log)" class="mt-2">
                    <summary
                        class="cursor-pointer text-sm text-muted-foreground"
                    >
                        Ver detalle
                    </summary>
                    <pre
                        class="mt-2 overflow-x-auto rounded-md bg-muted p-3 text-xs"
                        >{{ details(log) }}</pre>
                </details>
            </li>
        </ol>

        <Pagination :page="logs" />
    </div>
</template>
