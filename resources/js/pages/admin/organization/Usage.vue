<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import BarChart from '@/components/reports/BarChart.vue';
import { useFormat } from '@/composables/useFormat';
import { usage } from '@/routes';

type Meter = { used: number; limit: number | null };

const props = defineProps<{
    contacts: Meter;
    monthly: Meter & { month: string };
    history: { month: string; sent: number }[];
    connections: {
        id: number;
        name: string;
        driver: string;
        status: string;
        used: number;
        limit: number | null;
    }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Uso', href: usage() }] },
});

const { number } = useFormat();

const percent = (m: Meter) =>
    m.limit ? Math.min(100, Math.round((m.used * 100) / m.limit)) : null;

// Status colors carry an icon-free text label too ("Al límite", "Cerca del límite").
const level = (m: Meter) => {
    const p = percent(m);

    return p === null ? 'none' : p >= 100 ? 'full' : p >= 80 ? 'near' : 'ok';
};
const barClass = {
    none: 'bg-primary',
    ok: 'bg-primary',
    near: 'bg-amber-500',
    full: 'bg-destructive',
};
const levelLabel = {
    none: 'Sin límite',
    ok: '',
    near: 'Cerca del límite',
    full: 'Al límite',
};

const monthLabel = (m: string) => {
    const [y, mo] = m.split('-').map(Number);

    return new Intl.DateTimeFormat('es-GT', {
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(Date.UTC(y, mo - 1, 1)));
};

const meters = computed(() => [
    { key: 'contacts', title: 'Contactos', meter: props.contacts },
    {
        key: 'monthly',
        title: `Envíos de ${monthLabel(props.monthly.month)}`,
        meter: props.monthly,
    },
]);
</script>

<template>
    <Head title="Uso" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Uso de la plataforma"
            description="Consumo frente a los límites configurados para la organización."
        />

        <div class="grid gap-4 md:grid-cols-2">
            <section
                v-for="m in meters"
                :key="m.key"
                class="space-y-3 rounded-xl border p-4"
                :aria-labelledby="`u-${m.key}`"
            >
                <div class="flex items-baseline justify-between gap-2">
                    <h2 :id="`u-${m.key}`" class="font-medium">
                        {{ m.title }}
                    </h2>
                    <span
                        v-if="levelLabel[level(m.meter)]"
                        class="text-xs font-medium"
                        :class="{
                            'text-destructive': level(m.meter) === 'full',
                            'text-amber-700 dark:text-amber-400':
                                level(m.meter) === 'near',
                            'text-muted-foreground': level(m.meter) === 'none',
                        }"
                        >{{ levelLabel[level(m.meter)] }}</span
                    >
                </div>
                <p class="text-2xl font-semibold tabular-nums">
                    {{ number(m.meter.used) }}
                    <span
                        v-if="m.meter.limit"
                        class="text-base font-normal text-muted-foreground"
                        >de {{ number(m.meter.limit) }}</span
                    >
                </p>
                <div
                    v-if="m.meter.limit"
                    class="h-2.5 overflow-hidden rounded-full bg-muted"
                    role="progressbar"
                    :aria-valuenow="percent(m.meter) ?? 0"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    :aria-label="m.title"
                >
                    <div
                        class="h-full rounded-full"
                        :class="barClass[level(m.meter)]"
                        :style="{ width: `${percent(m.meter)}%` }"
                    />
                </div>
            </section>
        </div>

        <section class="rounded-xl border p-4" aria-label="Envíos por mes">
            <BarChart
                title="Envíos por mes"
                :points="
                    history.map((h) => ({
                        label: monthLabel(h.month),
                        value: h.sent,
                    }))
                "
                value-label="envíos"
            />
        </section>

        <section class="space-y-2" aria-labelledby="u-conn">
            <h2 id="u-conn" class="font-medium">Conexiones de envío (hoy)</h2>
            <ul class="divide-y rounded-xl border">
                <li
                    v-for="c in connections"
                    :key="c.id"
                    class="flex flex-col gap-1 p-3 text-sm sm:flex-row sm:items-center sm:justify-between"
                >
                    <span
                        >{{ c.name }}
                        <span class="text-muted-foreground"
                            >· {{ c.driver }}</span
                        ></span
                    >
                    <span class="tabular-nums"
                        >{{ number(c.used) }}
                        <template v-if="c.limit"
                            >de {{ number(c.limit) }}</template
                        >
                        <template v-else> (sin límite diario)</template></span
                    >
                </li>
                <li
                    v-if="!connections.length"
                    class="p-3 text-sm text-muted-foreground"
                >
                    No hay conexiones de envío.
                </li>
            </ul>
        </section>
    </div>
</template>
