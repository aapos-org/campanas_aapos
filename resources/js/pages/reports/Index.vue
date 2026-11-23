<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import BarChart from '@/components/reports/BarChart.vue';
import { Badge } from '@/components/ui/badge';
import { useFormat } from '@/composables/useFormat';
import { show as campaignShow } from '@/routes/campaigns';
import { index } from '@/routes/reports';

const props = defineProps<{
    growth: { month: string; subscribed: number; unsubscribed: number }[];
    totals: Record<string, number>;
    statusLabels: Record<string, string>;
    campaigns: {
        id: number;
        name: string;
        is_historic: boolean;
        finished_at: string | null;
        sent: number;
        open_rate: number | null;
        click_rate: number | null;
        unsubscribes: number;
        bounces: number;
    }[];
    audiences: { id: number; name: string }[];
    filters: { audience: number | null };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Reportes', href: index() }] },
});

const { date, number } = useFormat();
const audience = ref(
    props.filters.audience ? String(props.filters.audience) : '',
);

watch(audience, (value) =>
    router.get(
        index.url(),
        { audience: value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    ),
);

const monthLabel = (m: string) => {
    const [y, mo] = m.split('-').map(Number);

    return new Intl.DateTimeFormat('es-GT', {
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(Date.UTC(y, mo - 1, 1)));
};

const series = (key: 'subscribed' | 'unsubscribed') =>
    props.growth.map((g) => ({ label: monthLabel(g.month), value: g[key] }));

const percent = (v: number | null) =>
    v === null ? '—' : `${v.toLocaleString('es-GT')} %`;

const selectClass =
    'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none dark:bg-input/30';
</script>

<template>
    <Head title="Reportes" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"
        >
            <Heading
                title="Reportes"
                description="Crecimiento de las audiencias, bajas por mes y comparación de campañas."
            />
            <div class="grid gap-1.5">
                <label for="audience" class="text-sm font-medium"
                    >Audiencia</label
                >
                <select id="audience" v-model="audience" :class="selectClass">
                    <option value="">Todas</option>
                    <option
                        v-for="a in audiences"
                        :key="a.id"
                        :value="String(a.id)"
                    >
                        {{ a.name }}
                    </option>
                </select>
            </div>
        </div>

        <dl class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            <div
                v-for="(label, key) in statusLabels"
                :key="key"
                class="rounded-xl border p-3"
            >
                <dt class="text-xs text-muted-foreground">{{ label }}</dt>
                <dd class="text-xl font-semibold tabular-nums">
                    {{ number(totals[key] ?? 0) }}
                </dd>
            </div>
        </dl>

        <section
            class="grid gap-6 rounded-xl border p-4 md:grid-cols-2"
            aria-label="Crecimiento por mes"
        >
            <BarChart
                title="Nuevas suscripciones por mes"
                :points="series('subscribed')"
                value-label="suscripciones"
            />
            <BarChart
                title="Bajas por mes"
                :points="series('unsubscribed')"
                value-label="bajas"
            />
        </section>

        <section class="space-y-2" aria-labelledby="s-campaigns">
            <h2 id="s-campaigns" class="font-medium">
                Comparación de campañas enviadas
            </h2>
            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 font-medium">Campaña</th>
                            <th class="px-3 py-2 font-medium">Fecha</th>
                            <th class="px-3 py-2 text-right font-medium">
                                Enviados
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Apertura
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Clics
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Bajas
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Rebotes
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!campaigns.length">
                            <td
                                colspan="7"
                                class="px-3 py-6 text-center text-muted-foreground"
                            >
                                Aún no hay campañas enviadas.
                            </td>
                        </tr>
                        <tr v-for="c in campaigns" :key="c.id" class="border-t">
                            <td class="px-3 py-2">
                                <Link
                                    :href="campaignShow(c.id)"
                                    class="underline-offset-2 hover:underline"
                                    >{{ c.name }}</Link
                                >
                                <Badge
                                    v-if="c.is_historic"
                                    variant="outline"
                                    class="ml-2"
                                    >Mailchimp</Badge
                                >
                            </td>
                            <td
                                class="px-3 py-2 whitespace-nowrap text-muted-foreground"
                            >
                                {{ date(c.finished_at) }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ number(c.sent) }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ percent(c.open_rate) }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ percent(c.click_rate) }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ number(c.unsubscribes) }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ number(c.bounces) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-muted-foreground">
                Las tasas se calculan sobre los correos enviados. Las aperturas
                son aproximadas (algunos clientes cargan las imágenes
                automáticamente).
            </p>
        </section>
    </div>
</template>
