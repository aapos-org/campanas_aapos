<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowDownRight,
    ArrowUpRight,
    BarChart3,
    CalendarClock,
    Filter,
    Info,
    Megaphone,
    MousePointerClick,
    Upload,
    UserMinus,
    UserPlus,
    Users,
    Workflow,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import CampaignStatusBadge from '@/components/CampaignStatusBadge.vue';
import DailyBarsChart from '@/components/reports/DailyBarsChart.vue';
import { useFormat } from '@/composables/useFormat';
import { dashboard, usage as usagePage } from '@/routes';
import { show as audienceShow } from '@/routes/audiences';
import { index as automationsIndex } from '@/routes/automations';
import {
    index as campaignsIndex,
    show as campaignShow,
} from '@/routes/campaigns';
import { index as importsIndex } from '@/routes/imports';
import { index as reportsIndex } from '@/routes/reports';
import { create as segmentCreate } from '@/routes/segments';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Panel', href: dashboard() }] },
});

type Meter = { used: number; limit: number | null };
type Kpi = { value: number | null; previous: number | null };

const props = defineProps<{
    greeting: string;
    today: string;
    series: { date: string; sent: number; opens: number; clicks: number }[];
    kpis: {
        subscribed: Kpi;
        unsubscribed: Kpi;
        open_rate: Kpi;
        click_rate: Kpi;
    };
    activity: {
        type: 'subscribed' | 'unsubscribed' | 'campaign';
        title: string;
        detail: string;
        at: string | null;
        link: string | null;
    }[];
    alerts: { level: 'warning' | 'info'; title: string; message: string }[];
    audiences: { id: number; name: string; subscribed: number }[];
    lastCampaign: {
        id: number;
        name: string;
        finished_at: string | null;
        sent: number;
        open_rate: number | null;
        click_rate: number | null;
    } | null;
    upcoming: {
        id: number;
        name: string;
        status: string;
        status_label: string;
        scheduled_at: string | null;
    }[];
    automations: number;
    usage: { contacts: Meter; monthly: Meter };
    stats: { users: number | null };
}>();

const page = usePage();
const { number, dateTime, date } = useFormat();
const can = (permission: string) =>
    (page.props.auth.permissions as string[] | undefined)?.includes(permission);
const firstName = computed(() => page.props.auth.user.name.split(' ')[0]);

const longDate = computed(() =>
    new Intl.DateTimeFormat('es-GT', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        timeZone: 'UTC',
    }).format(new Date(`${props.today}T12:00:00Z`)),
);

const metric = ref<'sent' | 'opens' | 'clicks'>('sent');
const metrics = [
    { key: 'sent', label: 'Envíos' },
    { key: 'opens', label: 'Aperturas' },
    { key: 'clicks', label: 'Clics' },
] as const;
const chartPoints = computed(() =>
    props.series.map((d) => ({ date: d.date, value: d[metric.value] })),
);
const periodTotal = computed(() =>
    chartPoints.value.reduce((a, p) => a + p.value, 0),
);
const todayValue = computed(
    () => chartPoints.value[chartPoints.value.length - 1]?.value ?? 0,
);

const actions = computed(() =>
    [
        {
            title: 'Nueva campaña',
            text: 'Diseña y envía',
            icon: Megaphone,
            href: campaignsIndex(),
            permission: 'campaigns.manage',
        },
        {
            title: 'Importar contactos',
            text: 'CSV o Excel',
            icon: Upload,
            href: importsIndex(),
            permission: 'contacts.manage',
        },
        {
            title: 'Crear segmento',
            text: 'Por etiquetas y actividad',
            icon: Filter,
            href: segmentCreate(),
            permission: 'contacts.manage',
        },
        {
            title: 'Automatizaciones',
            text: `${props.automations} activas`,
            icon: Workflow,
            href: automationsIndex(),
            permission: 'campaigns.view',
        },
        {
            title: 'Reportes',
            text: 'Crecimiento y campañas',
            icon: BarChart3,
            href: reportsIndex(),
            permission: 'reports.view',
        },
    ].filter((a) => can(a.permission)),
);

type KpiCard = {
    key: keyof typeof props.kpis;
    title: string;
    suffix: string;
    icon: unknown;
    // For unsubscribes, going down is good.
    lowerIsBetter?: boolean;
};
const kpiCards: KpiCard[] = [
    {
        key: 'subscribed',
        title: 'Nuevas suscripciones',
        suffix: '',
        icon: UserPlus,
    },
    {
        key: 'open_rate',
        title: 'Tasa de apertura',
        suffix: ' %',
        icon: Megaphone,
    },
    {
        key: 'click_rate',
        title: 'Tasa de clics',
        suffix: ' %',
        icon: MousePointerClick,
    },
    {
        key: 'unsubscribed',
        title: 'Bajas',
        suffix: '',
        icon: UserMinus,
        lowerIsBetter: true,
    },
];
const change = (k: Kpi) => {
    if (k.value === null || k.previous === null || k.previous === 0) {
        return null;
    }

    return Math.round(((k.value - k.previous) * 1000) / k.previous) / 10;
};

const activityIcon = {
    subscribed: UserPlus,
    unsubscribed: UserMinus,
    campaign: Megaphone,
};
const meterPct = (m: Meter) =>
    m.limit ? Math.min(100, Math.round((m.used * 100) / m.limit)) : null;
</script>

<template>
    <Head title="Panel" />

    <div class="flex flex-1 flex-col gap-4 p-4 md:p-6">
        <!-- Greeting -->
        <section
            class="flex animate-rise flex-col gap-4 lg:flex-row lg:items-end lg:justify-between"
            style="--rise-index: 0"
        >
            <div>
                <p
                    class="text-xs font-semibold tracking-wider text-primary uppercase"
                >
                    {{ longDate }}
                </p>
                <h1
                    class="mt-1 text-3xl font-semibold tracking-tight md:text-4xl"
                >
                    {{ greeting }},
                    <span class="text-primary">{{ firstName }}</span>
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    <strong class="text-foreground">{{
                        number(usage.contacts.used)
                    }}</strong>
                    contactos ·
                    <strong class="text-foreground">{{
                        number(usage.monthly.used)
                    }}</strong>
                    envíos este mes ·
                    <strong class="text-foreground">{{
                        upcoming.length
                    }}</strong>
                    envíos en curso o programados
                </p>
            </div>
            <Link
                v-if="can('reports.view')"
                :href="usagePage()"
                class="self-start glass-card px-4 py-2 text-sm font-medium transition-colors hover:text-primary lg:self-auto"
                >Ver uso de la plataforma</Link
            >
        </section>

        <!-- Alerts -->
        <section
            v-if="alerts.length"
            aria-label="Alertas"
            class="grid animate-rise gap-2 md:grid-cols-2"
            style="--rise-index: 1"
        >
            <div
                v-for="alert in alerts"
                :key="alert.title"
                role="status"
                class="flex gap-3 glass-card p-3 text-sm"
                :class="
                    alert.level === 'warning'
                        ? 'border-amber-400/50! bg-amber-400/10!'
                        : ''
                "
            >
                <AlertTriangle
                    v-if="alert.level === 'warning'"
                    class="mt-0.5 size-4 shrink-0 text-amber-500"
                    aria-hidden="true"
                />
                <Info
                    v-else
                    class="mt-0.5 size-4 shrink-0 text-primary"
                    aria-hidden="true"
                />
                <div>
                    <p class="font-medium">{{ alert.title }}</p>
                    <p class="text-muted-foreground">{{ alert.message }}</p>
                </div>
            </div>
        </section>

        <!-- Quick actions -->
        <nav
            v-if="actions.length"
            aria-label="Accesos rápidos"
            class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5"
        >
            <Link
                v-for="(a, i) in actions"
                :key="a.title"
                :href="a.href"
                class="group flex animate-rise items-center gap-3 glass-card p-3 transition-transform hover:-translate-y-0.5"
                :style="{ '--rise-index': i + 2 }"
            >
                <span
                    class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary/15 text-primary transition-colors group-hover:bg-primary group-hover:text-primary-foreground"
                >
                    <component :is="a.icon" class="size-4" aria-hidden="true" />
                </span>
                <span class="min-w-0">
                    <span class="block text-sm leading-tight font-medium">{{
                        a.title
                    }}</span>
                    <span
                        class="block truncate text-xs text-muted-foreground"
                        >{{ a.text }}</span
                    >
                </span>
            </Link>
        </nav>

        <!-- Bento grid -->
        <div class="grid gap-4 lg:grid-cols-12">
            <section
                class="animate-rise space-y-4 glass-card p-5 lg:col-span-8"
                style="--rise-index: 7"
                aria-labelledby="b-chart"
            >
                <div
                    class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div>
                        <h2
                            id="b-chart"
                            class="flex items-center gap-2 text-sm text-muted-foreground"
                        >
                            <span
                                class="size-2 rounded-full bg-primary"
                                aria-hidden="true"
                            />{{ metrics.find((m) => m.key === metric)?.label }}
                            · últimos 30 días
                        </h2>
                        <p
                            class="mt-1 text-4xl font-semibold tracking-tight tabular-nums"
                        >
                            {{ number(periodTotal) }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Hoy: {{ number(todayValue) }}
                        </p>
                    </div>
                    <div
                        class="inline-flex self-start rounded-xl bg-muted p-1"
                        role="radiogroup"
                        aria-label="Métrica del gráfico"
                    >
                        <button
                            v-for="m in metrics"
                            :key="m.key"
                            type="button"
                            role="radio"
                            :aria-checked="metric === m.key"
                            class="rounded-lg px-3 py-1 text-xs font-medium transition-colors"
                            :class="
                                metric === m.key
                                    ? 'bg-background text-foreground shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground'
                            "
                            @click="metric = m.key"
                        >
                            {{ m.label }}
                        </button>
                    </div>
                </div>
                <DailyBarsChart
                    :key="metric"
                    :points="chartPoints"
                    :value-label="
                        metrics.find((m) => m.key === metric)?.label ?? ''
                    "
                />
            </section>

            <section
                class="relative animate-rise overflow-hidden rounded-[calc(var(--radius)+6px)] border border-white/10 bg-gradient-to-b from-[oklch(0.3_0.12_262)] to-[oklch(0.18_0.07_265)] p-5 text-white shadow-[var(--glass-shadow)] lg:col-span-4"
                style="--rise-index: 8"
                aria-labelledby="b-activity"
            >
                <div
                    class="pointer-events-none absolute -top-16 -right-16 size-48 rounded-full bg-[oklch(0.7_0.15_225/0.35)] blur-3xl"
                    aria-hidden="true"
                />
                <h2
                    id="b-activity"
                    class="flex items-center gap-2 text-sm font-medium"
                >
                    <span class="relative flex size-2" aria-hidden="true">
                        <span
                            class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-75"
                        />
                        <span
                            class="relative inline-flex size-2 rounded-full bg-emerald-400"
                        />
                    </span>
                    Actividad reciente
                </h2>
                <ol v-if="activity.length" class="relative mt-4 space-y-2">
                    <li v-for="(item, i) in activity" :key="i">
                        <component
                            :is="item.link ? Link : 'div'"
                            :href="item.link ?? undefined"
                            class="flex items-start gap-3 rounded-xl px-2 py-2 transition-colors hover:bg-white/10"
                        >
                            <span
                                class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg bg-white/10"
                            >
                                <component
                                    :is="activityIcon[item.type]"
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-sm font-medium"
                                    >{{ item.title }}</span
                                >
                                <span
                                    class="block truncate text-xs text-white/65"
                                    >{{ item.detail }} ·
                                    {{ dateTime(item.at) }}</span
                                >
                            </span>
                        </component>
                    </li>
                </ol>
                <p v-else class="relative mt-4 text-sm text-white/70">
                    Aún no hay actividad reciente.
                </p>
            </section>

            <!-- KPIs -->
            <div
                v-for="(k, i) in kpiCards"
                :key="k.key"
                class="animate-rise glass-card p-4 lg:col-span-3"
                :style="{ '--rise-index': i + 9 }"
            >
                <div class="flex items-center justify-between gap-2">
                    <p class="text-xs text-muted-foreground">
                        {{ k.title }} · 30 días
                    </p>
                    <component
                        :is="k.icon"
                        class="size-4 text-primary"
                        aria-hidden="true"
                    />
                </div>
                <p class="mt-2 text-2xl font-semibold tabular-nums">
                    {{
                        kpis[k.key].value === null
                            ? '—'
                            : number(kpis[k.key].value as number) + k.suffix
                    }}
                </p>
                <p
                    v-if="change(kpis[k.key]) !== null"
                    class="mt-1 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="
                        change(kpis[k.key])! >= 0 !== !!k.lowerIsBetter
                            ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300'
                            : 'bg-rose-500/15 text-rose-700 dark:text-rose-300'
                    "
                >
                    <ArrowUpRight
                        v-if="change(kpis[k.key])! >= 0"
                        class="size-3"
                        aria-hidden="true"
                    />
                    <ArrowDownRight v-else class="size-3" aria-hidden="true" />
                    {{ Math.abs(change(kpis[k.key])!) }} % vs. 30 días antes
                </p>
                <p v-else class="mt-1 text-xs text-muted-foreground">
                    Sin datos para comparar
                </p>
            </div>

            <section
                class="animate-rise space-y-3 glass-card p-5 lg:col-span-4"
                style="--rise-index: 13"
                aria-labelledby="b-last"
            >
                <h2
                    id="b-last"
                    class="flex items-center gap-2 text-sm font-medium"
                >
                    <Megaphone
                        class="size-4 text-primary"
                        aria-hidden="true"
                    />Última campaña
                </h2>
                <template v-if="lastCampaign">
                    <Link
                        :href="campaignShow(lastCampaign.id)"
                        class="block font-medium underline-offset-2 hover:underline"
                        >{{ lastCampaign.name }}</Link
                    >
                    <p class="text-xs text-muted-foreground">
                        {{ date(lastCampaign.finished_at) }} ·
                        {{ number(lastCampaign.sent) }} enviados
                    </p>
                    <dl class="grid grid-cols-2 gap-2">
                        <div class="rounded-xl bg-muted p-3">
                            <dt class="text-xs text-muted-foreground">
                                Apertura
                            </dt>
                            <dd class="text-xl font-semibold tabular-nums">
                                {{ lastCampaign.open_rate ?? '—' }} %
                            </dd>
                        </div>
                        <div class="rounded-xl bg-muted p-3">
                            <dt class="text-xs text-muted-foreground">Clics</dt>
                            <dd class="text-xl font-semibold tabular-nums">
                                {{ lastCampaign.click_rate ?? '—' }} %
                            </dd>
                        </div>
                    </dl>
                </template>
                <p v-else class="text-sm text-muted-foreground">
                    Aún no se envía ninguna campaña desde la plataforma.
                </p>
            </section>

            <section
                class="animate-rise space-y-3 glass-card p-5 lg:col-span-4"
                style="--rise-index: 14"
                aria-labelledby="b-upcoming"
            >
                <h2
                    id="b-upcoming"
                    class="flex items-center gap-2 text-sm font-medium"
                >
                    <CalendarClock
                        class="size-4 text-primary"
                        aria-hidden="true"
                    />Envíos en curso y programados
                </h2>
                <ul v-if="upcoming.length" class="divide-y text-sm">
                    <li
                        v-for="c in upcoming"
                        :key="c.id"
                        class="flex items-center justify-between gap-2 py-2"
                    >
                        <Link
                            :href="campaignShow(c.id)"
                            class="truncate underline-offset-2 hover:underline"
                            >{{ c.name }}</Link
                        >
                        <CampaignStatusBadge
                            :status="c.status"
                            :label="c.status_label"
                        />
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">
                    No hay envíos programados.
                </p>
            </section>

            <section
                class="animate-rise space-y-3 glass-card p-5 lg:col-span-4"
                style="--rise-index: 15"
                aria-labelledby="b-usage"
            >
                <h2
                    id="b-usage"
                    class="flex items-center gap-2 text-sm font-medium"
                >
                    <BarChart3
                        class="size-4 text-primary"
                        aria-hidden="true"
                    />Uso del plan
                </h2>
                <div
                    v-for="m in [
                        { label: 'Contactos', meter: usage.contacts },
                        { label: 'Envíos del mes', meter: usage.monthly },
                    ]"
                    :key="m.label"
                    class="space-y-1.5"
                >
                    <div class="flex justify-between text-sm">
                        <span>{{ m.label }}</span>
                        <span class="text-muted-foreground tabular-nums"
                            >{{ number(m.meter.used)
                            }}{{
                                m.meter.limit
                                    ? ` de ${number(m.meter.limit)}`
                                    : ' · sin límite'
                            }}</span
                        >
                    </div>
                    <div
                        v-if="m.meter.limit"
                        class="h-2 overflow-hidden rounded-full bg-muted"
                        role="progressbar"
                        :aria-label="m.label"
                        :aria-valuenow="meterPct(m.meter) ?? 0"
                        aria-valuemin="0"
                        aria-valuemax="100"
                    >
                        <div
                            class="h-full rounded-full bg-gradient-to-r from-chart-2 to-primary"
                            :style="{ width: `${meterPct(m.meter)}%` }"
                        />
                    </div>
                </div>
            </section>

            <section
                class="animate-rise space-y-3 glass-card p-5 lg:col-span-12"
                style="--rise-index: 16"
                aria-labelledby="b-audiences"
            >
                <h2
                    id="b-audiences"
                    class="flex items-center gap-2 text-sm font-medium"
                >
                    <Users
                        class="size-4 text-primary"
                        aria-hidden="true"
                    />Audiencias
                </h2>
                <ul
                    v-if="audiences.length"
                    class="grid gap-2 text-sm sm:grid-cols-2 lg:grid-cols-3"
                >
                    <li v-for="a in audiences" :key="a.id">
                        <Link
                            :href="audienceShow(a.id)"
                            class="flex justify-between gap-2 rounded-xl bg-muted px-3 py-2 transition-colors hover:bg-accent"
                        >
                            <span class="truncate">{{ a.name }}</span>
                            <span class="shrink-0 tabular-nums"
                                >{{ number(a.subscribed) }}
                                <span class="text-muted-foreground"
                                    >suscritos</span
                                ></span
                            >
                        </Link>
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">
                    Aún no hay audiencias.
                </p>
            </section>
        </div>
    </div>
</template>
