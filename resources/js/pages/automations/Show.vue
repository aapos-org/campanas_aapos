<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Users } from '@lucide/vue';
import { ref, watch } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useFormat } from '@/composables/useFormat';
import { edit, index, show } from '@/routes/automations';
import { show as campaignShow } from '@/routes/campaigns';
import { show as contactShow } from '@/routes/contacts';
import type { Paginated } from '@/types';

type Run = {
    id: number;
    contact_id: number;
    email: string | null;
    status: string;
    status_label: string;
    next_step: string | null;
    next_run_at: string | null;
    entered_at: string;
    exit_reason: string | null;
    logs: { message: string; at: string | null }[];
};

const props = defineProps<{
    automation: {
        id: number;
        name: string;
        audience: string | null;
        trigger: string;
        status: 'draft' | 'active' | 'paused';
    };
    counts: Record<string, number>;
    steps: {
        id: number;
        position: number;
        label: string;
        campaign_id: number | null;
        stats: {
            sent: number;
            unique_opens: number;
            unique_clicks: number;
            unsubscribes: number;
        } | null;
        waiting: number;
    }[];
    runs: Paginated<Run>;
    filters: { status: string | null };
    statusLabels: Record<string, string>;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Automatizaciones', href: index() }] },
});

const { dateTime, number } = useFormat();
const status = ref(props.filters.status ?? '');
watch(status, (value) =>
    router.get(
        show.url(props.automation.id),
        { status: value || undefined },
        { only: ['runs', 'filters'], preserveState: true, replace: true },
    ),
);
</script>

<template>
    <Head :title="automation.name" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                :title="automation.name"
                :description="`${automation.audience} · ${automation.trigger}`"
            />
            <Button variant="secondary" as-child
                ><Link :href="edit(automation.id)">Editar</Link></Button
            >
        </div>

        <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div
                v-for="(label, key) in statusLabels"
                :key="key"
                class="rounded-xl border p-3"
            >
                <dt class="text-xs text-muted-foreground">{{ label }}</dt>
                <dd class="text-xl font-semibold tabular-nums">
                    {{ number(counts[key] ?? 0) }}
                </dd>
            </div>
        </dl>

        <section class="space-y-2" aria-labelledby="s-steps">
            <h2 id="s-steps" class="font-medium">Pasos</h2>
            <ol class="divide-y rounded-xl border">
                <li
                    v-for="s in steps"
                    :key="s.id"
                    class="flex flex-col gap-1 p-3 text-sm sm:flex-row sm:items-center sm:justify-between"
                >
                    <span class="min-w-0">
                        <Link
                            v-if="s.campaign_id"
                            :href="campaignShow(s.campaign_id)"
                            class="underline-offset-2 hover:underline"
                            >{{ s.label }}</Link
                        >
                        <template v-else>{{ s.label }}</template>
                    </span>
                    <span class="flex shrink-0 gap-3 text-muted-foreground">
                        <span v-if="s.waiting"
                            >{{ number(s.waiting) }} esperando aquí</span
                        >
                        <template v-if="s.stats">
                            <span>{{ number(s.stats.sent) }} enviados</span>
                            <span
                                >{{
                                    number(s.stats.unique_opens)
                                }}
                                aperturas</span
                            >
                            <span
                                >{{ number(s.stats.unique_clicks) }} clics</span
                            >
                        </template>
                    </span>
                </li>
            </ol>
        </section>

        <section class="space-y-3" aria-labelledby="s-runs">
            <div
                class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between"
            >
                <h2 id="s-runs" class="font-medium">Contactos en el flujo</h2>
                <div class="grid gap-1.5 sm:w-48">
                    <Label for="status">Estado</Label>
                    <NativeSelect id="status" v-model="status">
                        <option value="">Todos</option>
                        <option
                            v-for="(label, key) in statusLabels"
                            :key="key"
                            :value="key"
                        >
                            {{ label }}
                        </option>
                    </NativeSelect>
                </div>
            </div>

            <EmptyState
                v-if="!runs.data.length"
                :icon="Users"
                title="Nadie ha entrado todavía"
                description="Los contactos aparecerán aquí cuando ocurra el disparador con la automatización activa."
            />
            <ul v-else class="grid gap-3">
                <li
                    v-for="r in runs.data"
                    :key="r.id"
                    class="space-y-2 rounded-xl border p-4"
                >
                    <div
                        class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <Link
                            :href="contactShow(r.contact_id)"
                            class="font-medium underline-offset-2 hover:underline"
                            >{{ r.email }}</Link
                        >
                        <span class="flex items-center gap-2 text-sm">
                            <Badge
                                :variant="
                                    r.status === 'active'
                                        ? 'default'
                                        : r.status === 'failed'
                                          ? 'destructive'
                                          : 'outline'
                                "
                                >{{ r.status_label }}</Badge
                            >
                            <span class="text-muted-foreground"
                                >Entró el {{ dateTime(r.entered_at) }}</span
                            >
                        </span>
                    </div>
                    <p
                        v-if="r.status === 'active' && r.next_step"
                        class="text-sm text-muted-foreground"
                    >
                        Siguiente: {{ r.next_step
                        }}<template v-if="r.next_run_at">
                            · {{ dateTime(r.next_run_at) }}</template
                        >
                    </p>
                    <p
                        v-if="r.exit_reason"
                        class="text-sm text-muted-foreground"
                    >
                        Motivo de salida: {{ r.exit_reason }}
                    </p>
                    <details v-if="r.logs.length">
                        <summary
                            class="cursor-pointer text-sm text-muted-foreground"
                        >
                            Registro
                        </summary>
                        <ol class="mt-2 space-y-1 border-l-2 pl-3 text-sm">
                            <li v-for="(l, i) in r.logs" :key="i">
                                {{ l.message }}
                                <span class="text-xs text-muted-foreground">{{
                                    dateTime(l.at)
                                }}</span>
                            </li>
                        </ol>
                    </details>
                </li>
            </ul>
            <Pagination :page="runs" />
        </section>
    </div>
</template>
